<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Stage\BracketFold;
use MissionGaming\Tactician\Stage\BracketTie;
use MissionGaming\Tactician\Stage\PairKey;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Stage\TieResolution;
use MissionGaming\Tactician\Standings\Adjustment;
use MissionGaming\Tactician\Standings\EntryOrder;
use MissionGaming\Tactician\Standings\StandingEntry;
use MissionGaming\Tactician\Standings\Standings;
use MissionGaming\Tactician\Standings\StandingsCalculator;

/**
 * Shared bracket mechanics for the elimination presets: positional fold
 * seeding, tie-aware result indexing, and tie-event emission.
 *
 * List position is authoritative for seeding (position 1 is the top seed,
 * per the stage entry contract) — carried seed attributes are display
 * facts, not pairing inputs, so consumer-derived entrant lists and
 * selector outputs behave identically by construction.
 *
 * @internal Not public API: implementation the two elimination engines share.
 */
trait EliminationBracketSupport
{
    /**
     * @param array<Participant> $participants
     *
     * @throws InvalidConfigurationException
     */
    private function validateParticipants(array $participants): void
    {
        if (count($participants) < 2) {
            throw new InvalidConfigurationException(
                'Elimination brackets require at least 2 participants',
                ['participant_count' => count($participants), 'minimum_required' => 2],
                reason: InvalidConfigurationReason::TooFewParticipants
            );
        }
    }

    /**
     * Place participants into bracket slots using standard fold seeding
     * over list position.
     *
     * @param array<Participant> $participants In seeding order, best first
     * @return array<Participant|null> Bracket slots in order; null slots are byes
     */
    private function buildInitialSlots(array $participants): array
    {
        $ordered = array_values($participants);
        $size = $this->bracketSize(count($ordered));

        $slots = [];
        foreach (BracketFold::positions($size) as $position) {
            $slots[] = $ordered[$position - 1] ?? null;
        }

        return $slots;
    }

    private function bracketSize(int $participantCount): int
    {
        return BracketFold::size($participantCount);
    }

    /**
     * Emit the events for one tie: a single event, or two mirrored legs
     * annotated with 'tie_leg' metadata so leg results stay distinguishable.
     *
     * @param array<string, mixed> $metadata What every event of the tie carries beside its leg;
     *                                       none by default
     * @return array<Event>
     */
    private function buildTieEvents(
        Participant $first,
        Participant $second,
        Round $round,
        int $legsPerTie,
        array $metadata = []
    ): array {
        if ($legsPerTie === 1) {
            return [new Event([$first, $second], $round, $metadata)];
        }

        return [
            new Event([$first, $second], $round, ['tie_leg' => 1] + $metadata),
            new Event([$second, $first], $round, ['tie_leg' => 2] + $metadata),
        ];
    }

    /**
     * Index results by round, normalized pairing, and tie leg.
     *
     * @param array<Result> $results
     * @return array<string, Result>
     *
     * @throws InvalidConfigurationException When a result lacks a round number, does not
     *                                       reference a two-participant event, or duplicates
     *                                       another result for the same leg
     */
    private function indexResults(array $results): array
    {
        $index = [];
        foreach ($results as $result) {
            $event = $result->getEvent();
            $eventParticipants = $event->getParticipants();
            $round = $event->getRound()?->getNumber();

            if ($round === null) {
                throw new InvalidConfigurationException(
                    'Elimination results must reference events with a round number; record results against the events produced by the engine',
                    ['participants' => array_map(fn(Participant $p) => $p->getId(), $eventParticipants)],
                    reason: InvalidConfigurationReason::EventWithoutRoundNumber
                );
            }

            if (count($eventParticipants) !== 2) {
                throw new InvalidConfigurationException(
                    'Elimination results must reference two-participant events',
                    ['round' => $round, 'participant_count' => count($eventParticipants)],
                    reason: InvalidConfigurationReason::InvalidResult
                );
            }

            $key = $this->legKey($round, $eventParticipants[0], $eventParticipants[1], $event->getMetadataValue('tie_leg'));

            if (isset($index[$key])) {
                $ids = PairKey::order([$eventParticipants[0]->getId(), $eventParticipants[1]->getId()]);
                throw new InvalidConfigurationException(
                    "Two results reference the same elimination match ({$ids[0]} vs {$ids[1]}, round {$round})",
                    ['round' => $round, 'participants' => $ids],
                    reason: InvalidConfigurationReason::DuplicateResult
                );
            }

            $index[$key] = $result;
        }

        return $index;
    }

    /**
     * Resolve who advances from the tie between two slots, or null while
     * legs are missing results.
     *
     * A level tie with no decision is reported with its round beside its
     * participants: two entrants can meet more than once in a bracket (the
     * grand final and its reset), so the pair alone does not say which tie.
     *
     * The deprecation of the 'tie_winner' metadata key is raised here, the
     * first time a replay takes an advancer from it and never again in the
     * same replay: the caller owns the flag and starts it at false for each
     * call of the engine.
     *
     * @param array<string, Result> $resultIndex
     * @param bool $tieWinnerDeprecationRaised Whether this replay has raised the deprecation
     *
     * @throws InvalidConfigurationException When a completed tie is level and carries no decider
     *                                       (`UndecidedTie`, which settleTie() reads as a tie
     *                                       that awaits its decision), or a result or a decider
     *                                       is one TieDecision refuses (`InvalidResult`)
     */
    private function lookupAdvancer(
        array $resultIndex,
        int $round,
        Participant $first,
        Participant $second,
        int $legsPerTie,
        bool &$tieWinnerDeprecationRaised
    ): ?Participant {
        $legResults = [];
        for ($leg = 1; $leg <= $legsPerTie; ++$leg) {
            $result = $resultIndex[$this->legKey($round, $first, $second, $legsPerTie === 1 ? null : $leg)] ?? null;
            if ($result !== null) {
                $legResults[] = $result;
            }
        }

        try {
            $resolution = TieResolution::of($legResults, $first, $second, $legsPerTie);
        } catch (InvalidConfigurationException $exception) {
            if ($exception->getReason() !== InvalidConfigurationReason::UndecidedTie) {
                throw $exception;
            }

            throw new InvalidConfigurationException(
                $exception->getConfigurationIssue(),
                ['round' => $round] + $exception->getContext(),
                reason: InvalidConfigurationReason::UndecidedTie
            );
        }

        if ($resolution->readTieWinnerMetadata && !$tieWinnerDeprecationRaised) {
            $tieWinnerDeprecationRaised = true;
            TieResolution::raiseTieWinnerDeprecation();
        }

        return $resolution->advancer;
    }

    /**
     * The results a single-leg bracket's standings are computed from: a
     * level event the bracket resolved through its tie decision counts as
     * a win for the participant who advanced, so the table places the
     * bracket as it does when every event has a winner (the participant
     * who went on from the final is not level with the one who did not).
     *
     * Only the standings read these. The recorded results are not changed,
     * and a result the bracket did not resolve, or one of a two-legged tie
     * (whose legs are ordinary results), is passed through as recorded.
     *
     * @param array<Result> $results
     * @param array<string, Participant> $levelAdvancers Who advanced from each level single-leg
     *                                                   event, by leg key
     * @return array<Result>
     */
    private function resultsForStandings(array $results, array $levelAdvancers): array
    {
        if ($levelAdvancers === []) {
            return $results;
        }

        $decided = [];
        foreach ($results as $result) {
            $event = $result->getEvent();
            $eventParticipants = $event->getParticipants();
            $round = $event->getRound()?->getNumber();

            $advancer = $round === null || count($eventParticipants) !== 2 || $result->getWinner() !== null
                ? null
                : $levelAdvancers[$this->legKey($round, $eventParticipants[0], $eventParticipants[1], $event->getMetadataValue('tie_leg'))] ?? null;

            $decided[] = $advancer === null
                ? $result
                : new Result($event, $advancer, $result->getScores(), $result->getMetadata(), $result->getAward(), $result->getDecider());
        }

        return $decided;
    }

    /**
     * Note who advanced from a single-leg event that finished level, for
     * resultsForStandings().
     *
     * @param array<string, Participant> $levelAdvancers
     * @param array<string, Result> $resultIndex
     */
    private function noteLevelAdvancer(
        array &$levelAdvancers,
        array $resultIndex,
        int $round,
        Participant $first,
        Participant $second,
        int $legsPerTie,
        ?Participant $advancer
    ): void {
        if ($legsPerTie !== 1 || $advancer === null) {
            return;
        }

        $key = $this->legKey($round, $first, $second, null);
        if (isset($resultIndex[$key]) && $resultIndex[$key]->getWinner() === null) {
            $levelAdvancers[$key] = $advancer;
        }
    }

    /**
     * The table of a finished bracket, in placement order.
     *
     * The bracket decides the tiers: the entrant who advanced from the
     * last round is alone in the first, the one it beat there alone in the
     * second, and the others follow by the round they went out in, later
     * first. Entrants who went out in the same round share a tier, and the
     * calculator's order of their records (ranking value, tiebreakers,
     * score difference, scores-for) is their order inside it. Where that
     * leaves two of them level, the position in the entrant list decides.
     * Participants the bracket did not place share a last tier.
     *
     * The adjustments of the stage are in the ranking values, and each
     * entry shows its own as a figure. Like every record, an adjustment
     * orders entrants inside a tier and moves nobody from one tier to
     * another: who advanced is decided by the ties.
     *
     * @param array<Participant> $entrants Every participant of the stage, in list order
     * @param array<Result> $results The results the records are computed from
     * @param array<int|string, int> $placement The tier of each placed participant by ID, 0 best
     * @param array<Adjustment> $adjustments The adjustments recorded on the stage state
     */
    private function placementStandings(
        StandingsCalculator $calculator,
        array $entrants,
        array $results,
        array $placement,
        array $adjustments = []
    ): Standings {
        $table = $calculator
            ->withAdjustments($calculator->calculate($entrants, $results), $adjustments)
            ->withTiedSetsOrderedBy($entrants);

        $unplaced = $placement === [] ? 0 : max($placement) + 1;
        $rows = [];
        foreach (array_values($table->getEntries()) as $index => $entry) {
            $rows[] = [$placement[$entry->getParticipant()->getId()] ?? $unplaced, $index, $entry];
        }
        usort($rows, static fn(array $first, array $second): int => [$first[0], $first[1]] <=> [$second[0], $second[1]]);

        return new Standings(
            array_map(static fn(array $row): StandingEntry => $row[2], $rows),
            array_map(static fn(array $row): int => $row[0], $rows)
        );
    }

    /**
     * A new walk through the bracket of a state: its recorded rounds by
     * number, its results by leg, and the participants who have withdrawn,
     * which is everyone the state has seen and no longer lists as active.
     *
     * @throws InvalidConfigurationException When a result cannot be indexed (see indexResults())
     */
    private function beginReading(StageState $state): BracketReading
    {
        $recorded = [];
        $lastNamedIn = [];
        foreach ($state->getRoundsPlayed() as $pairing) {
            $number = $pairing->getRoundNumber();
            $recorded[$number] = $pairing;

            foreach ($pairing->getEvents() as $event) {
                foreach ($event->getParticipants() as $participant) {
                    $lastNamedIn[$participant->getId()] = max($lastNamedIn[$participant->getId()] ?? 0, $number);
                }
            }
            foreach ($pairing->getByes() as $bye) {
                $lastNamedIn[$bye->getId()] = max($lastNamedIn[$bye->getId()] ?? 0, $number);
            }
        }

        // Everyone the state has seen who is no longer active has withdrawn,
        // whether or not the state knows the place it had: a state stored
        // before the entrant list was kept names a withdrawn participant in
        // its rounds only, and it is not to be paired again either.
        $active = [];
        foreach ($state->getParticipants() as $participant) {
            $active[$participant->getId()] = true;
        }
        $withdrawn = [];
        foreach ($state->getAllSeenParticipants() as $participant) {
            if (!isset($active[$participant->getId()])) {
                $withdrawn[$participant->getId()] = true;
            }
        }

        return new BracketReading($recorded, $this->indexResults($state->getResults()), $withdrawn, $lastNamedIn);
    }

    /**
     * The entrant list a bracket's tables are ordered by where records are
     * level: the entrant list of the state, in which an entrant who has
     * withdrawn from the live bracket keeps its place, then every other
     * participant the state has seen, in the order of their IDs (the
     * total order the standings calculator falls back on), so that the
     * list is the same whatever order the recorded events are in.
     *
     * @return list<Participant>
     */
    private function entrantsOf(StageState $state): array
    {
        $entrants = $state->getEntrants();
        $listed = [];
        foreach ($entrants as $entrant) {
            $listed[$entrant->getId()] = true;
        }

        $others = array_values(array_filter(
            $state->getAllSeenParticipants(),
            static fn(Participant $participant): bool => !isset($listed[$participant->getId()])
        ));
        usort(
            $others,
            static fn(Participant $first, Participant $second): int => EntryOrder::compareIds($first->getId(), $second->getId())
        );

        return [...$entrants, ...$others];
    }

    /**
     * The ties a recorded round holds: its two-participant events, put
     * together by the two they are between, in the order the round first
     * names each. The two of a tie are in the order of its first leg,
     * whichever leg the round names first: a history may supply the events
     * in any order. An event of any other size is no tie of a bracket and
     * is read by nothing.
     *
     * @return array<string, array{participants: array{Participant, Participant}, events: list<Event>, thirdPlace: bool}>
     */
    private function recordedTies(?RoundPairing $recorded): array
    {
        $ties = [];
        $namedByLeg = [];
        foreach ($recorded?->getEvents() ?? [] as $event) {
            $participants = array_values($event->getParticipants());
            if (count($participants) !== 2) {
                continue;
            }

            $key = 'tie ' . PairKey::of($participants[0]->getId(), $participants[1]->getId());
            $leg = is_int($event->getMetadataValue('tie_leg')) ? $event->getMetadataValue('tie_leg') : 1;
            $ties[$key] ??= ['participants' => [$participants[0], $participants[1]], 'events' => [], 'thirdPlace' => false];
            if ($leg < ($namedByLeg[$key] ??= $leg)) {
                $namedByLeg[$key] = $leg;
                $ties[$key]['participants'] = [$participants[0], $participants[1]];
            }
            $ties[$key]['events'][] = $event;
            $ties[$key]['thirdPlace'] = $ties[$key]['thirdPlace'] || BracketTie::of($event) === BracketTie::ThirdPlace;
        }

        return $ties;
    }

    /**
     * Whether a recorded round names both entrants of one of the given
     * ties, in one event or in two, or among its byes.
     *
     * This is how a recorded round is told from the round after it where
     * the bracket has no tie left to play in a round, because an entrant of
     * each has withdrawn. Such a round takes no number unless it was
     * recorded before they withdrew, and then both entrants of a tie were
     * still in the bracket and the round names them: as the tie itself, or,
     * in a round that was paired by hand, each against somebody else. The
     * round after it cannot name both: one of the two had withdrawn before
     * it was paired, which is why the tie was not played.
     *
     * @param list<array{Participant, Participant}> $ties
     */
    private function namesBothEntrantsOfOneOf(RoundPairing $recorded, array $ties): bool
    {
        $named = [];
        foreach ($recorded->getEvents() as $event) {
            foreach ($event->getParticipants() as $participant) {
                $named[$participant->getId()] = true;
            }
        }
        foreach ($recorded->getByes() as $bye) {
            $named[$bye->getId()] = true;
        }

        foreach ($ties as [$first, $second]) {
            if (isset($named[$first->getId()], $named[$second->getId()])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Settle one tie from what is recorded: who goes on from it, who goes
     * out, and whether it is still open.
     *
     * The results decide where they are complete. Where they are not, a
     * tie an entrant has withdrawn from is uncontested: the other entrant
     * goes on without it being played. A tie that neither decides is open.
     *
     * A tie that has every result, is level and carries no decider is open
     * too: it awaits the application's decision as another tie awaits a
     * result, and is marked as undecided. What the refusal says is given
     * back as `refusal`, for readRound() to keep the one pairNextRound()
     * throws. Where an entrant of such a tie has withdrawn, the withdrawal
     * gives the tie away as it does any tie the results do not decide.
     *
     * Where both have withdrawn, one of them may have gone on before it
     * left: the state does not say when an entrant withdrew, and the
     * recorded rounds do. The one that a later recorded round names went
     * on from the tie; where a later round names neither, or both, nobody
     * did.
     *
     * @param array<string, Participant> $levelAdvancers
     * @return array{winner: Participant|null, losers: list<Participant>, open: bool, uncontested: bool, undecided: bool, refusal: array{issue: string, context: array<string, mixed>}|null}
     *
     * @throws InvalidConfigurationException When a result of the tie names a winner outside it, or
     *                                       its decider is one TieDecision refuses (`InvalidResult`)
     */
    private function settleTie(
        BracketReading $reading,
        int $round,
        Participant $first,
        Participant $second,
        int $legsPerTie,
        array &$levelAdvancers
    ): array {
        $undecided = null;
        try {
            $advancer = $this->lookupAdvancer(
                $reading->resultIndex,
                $round,
                $first,
                $second,
                $legsPerTie,
                $reading->tieWinnerDeprecationRaised
            );
        } catch (InvalidConfigurationException $exception) {
            if ($exception->getReason() !== InvalidConfigurationReason::UndecidedTie) {
                throw $exception;
            }

            $advancer = null;
            $undecided = $exception;
        }

        if ($advancer instanceof Participant) {
            $this->noteLevelAdvancer($levelAdvancers, $reading->resultIndex, $round, $first, $second, $legsPerTie, $advancer);

            return [
                'winner' => $advancer,
                'losers' => [$advancer->getId() === $first->getId() ? $second : $first],
                'open' => false,
                'uncontested' => false,
                'undecided' => false,
                'refusal' => null,
            ];
        }

        $firstOut = isset($reading->withdrawn[$first->getId()]);
        $secondOut = isset($reading->withdrawn[$second->getId()]);
        if (!$firstOut && !$secondOut) {
            return [
                'winner' => null,
                'losers' => [],
                'open' => true,
                'uncontested' => false,
                'undecided' => $undecided instanceof InvalidConfigurationException,
                'refusal' => $undecided instanceof InvalidConfigurationException
                    ? ['issue' => $undecided->getConfigurationIssue(), 'context' => $undecided->getContext()]
                    : null,
            ];
        }

        if ($firstOut && $secondOut) {
            $firstWentOn = ($reading->lastNamedIn[$first->getId()] ?? 0) > $reading->number;
            $secondWentOn = ($reading->lastNamedIn[$second->getId()] ?? 0) > $reading->number;
            if ($firstWentOn === $secondWentOn) {
                return ['winner' => null, 'losers' => [$first, $second], 'open' => false, 'uncontested' => true, 'undecided' => false, 'refusal' => null];
            }

            $firstOut = !$firstWentOn;
        }

        return [
            'winner' => $firstOut ? $second : $first,
            'losers' => [$firstOut ? $first : $second],
            'open' => false,
            'uncontested' => true,
            'undecided' => false,
            'refusal' => null,
        ];
    }

    /**
     * Read one round of a bracket: the recorded round where there is one,
     * and what follows from the slots alone where there is none.
     *
     * A recorded round is the round that was played, whatever the slots
     * would have paired. Each of its ties is settled from its results, and
     * the entrants of the round go on in units, one for each tie and one
     * for each entrant that stood in the slots and is in none of the
     * round's ties. The units are in the order of the slots: a tie stands
     * where the earlier of its two entrants stood. So a round that pairs
     * the slots as the bracket does gives the units the bracket has, and a
     * round that pairs them otherwise gives the next round from what was
     * played. A pair of slots the round has no tie for is settled without
     * one where an entrant of it has withdrawn; an entrant who stands
     * alone goes on, withdrawn or not.
     *
     * @param list<Participant|null> $slots The slots the round is entered with, in bracket order
     * @param RoundPairing|null $recorded The recorded round, or null where the round is not recorded
     * @param int $round The number the round's results carry
     * @param array{Participant, Participant}|null $extraTie A tie of the round that stands in no slot
     *                                                       (the third-place tie)
     * @return array{manual: bool, units: list<array{winner: Participant|null, losers: list<Participant>}>, extra: array{winner: Participant|null, losers: list<Participant>}|null, unplayed: list<Participant>, unresolved: list<Event>, uncontested: list<Event>, undecided: list<Event>}
     *                                                                                                                                                                                                                                                                              `unplayed` lists who went on without a tie being played;
     *                                                                                                                                                                                                                                                                              `unresolved` the events that await a result, and
     *                                                                                                                                                                                                                                                                              `undecided` the events of the ties that have every
     *                                                                                                                                                                                                                                                                              result, are level and await a decider
     *
     * @throws InvalidConfigurationException When a result names a winner outside its tie, or a decider is one TieDecision refuses
     */
    private function readRound(
        BracketReading $reading,
        array $slots,
        ?RoundPairing $recorded,
        int $round,
        int $legsPerTie,
        ?array $extraTie = null
    ): array {
        $position = [];
        foreach ($slots as $index => $occupant) {
            if ($occupant instanceof Participant) {
                $position[$occupant->getId()] = $index;
            }
        }

        $pairs = array_chunk($slots, 2);
        $derived = [];
        foreach ($pairs as $pair) {
            $first = $pair[0] ?? null;
            $second = $pair[1] ?? null;
            if ($first instanceof Participant && $second instanceof Participant) {
                $derived['tie ' . PairKey::of($first->getId(), $second->getId())] = [$first, $second];
            }
        }
        $extraKey = $extraTie === null ? null : 'tie ' . PairKey::of($extraTie[0]->getId(), $extraTie[1]->getId());
        if ($extraTie !== null) {
            $derived[(string) $extraKey] = $extraTie;
        }

        $ties = $this->recordedTies($recorded);
        $recordedByes = [];
        foreach ($recorded?->getByes() ?? [] as $bye) {
            $recordedByes[$bye->getId()] = true;
        }
        $rows = [];
        $extra = null;
        $inTie = [];
        $unplayed = [];
        $unresolved = [];
        $uncontested = [];
        $undecided = [];
        $refusals = [];
        $sequence = 0;

        // The ties the bracket has in the round are read first, so that an
        // event the application added beside them never takes their place.
        $inOrder = [];
        foreach ($ties as $key => $tie) {
            if (isset($derived[$key])) {
                $inOrder[$key] = $tie;
            }
        }
        $inOrder += $ties;

        foreach ($inOrder as $key => $tie) {
            [$first, $second] = $tie['participants'];

            // The two are read in the order of their slots, whichever the
            // round names first: where the order shows (both have withdrawn
            // and neither went on), it is the bracket's and not that of the
            // events, which a history may supply in any order.
            if (($position[$second->getId()] ?? PHP_INT_MAX) < ($position[$first->getId()] ?? PHP_INT_MAX)) {
                [$first, $second] = [$second, $first];
            }

            // An event is no tie of the bracket, and is read by nothing,
            // where the application added it: between two entrants neither
            // of whom is in the round, or with an entrant who is in another
            // tie of the round already.
            if (
                $key !== $extraKey
                && !$tie['thirdPlace']
                && (
                    (!isset($position[$first->getId()]) && !isset($position[$second->getId()]))
                    || isset($inTie[$first->getId()])
                    || isset($inTie[$second->getId()])
                )
            ) {
                unset($ties[$key]);
                continue;
            }

            $settled = $this->settleTie($reading, $round, $first, $second, $legsPerTie, $reading->levelAdvancers);
            if ($settled['refusal'] !== null) {
                // The tie pairNextRound() names is the first of the bracket
                // and not the first of the recorded events, which a history
                // may supply in any order: the tie that stands in no slot
                // (the third-place tie) comes after those that do.
                $refusals[] = [
                    $key === $extraKey || $tie['thirdPlace'] ? 1 : 0,
                    min($position[$first->getId()] ?? PHP_INT_MAX, $position[$second->getId()] ?? PHP_INT_MAX),
                    count($refusals),
                    $settled['refusal'],
                ];
            }

            foreach ($tie['events'] as $event) {
                if (isset($reading->resultIndex[$this->legKey($round, $first, $second, $event->getMetadataValue('tie_leg'))])) {
                    if ($settled['undecided']) {
                        $undecided[] = $event;
                    }
                    continue;
                }
                if ($settled['open']) {
                    $unresolved[] = $event;
                } elseif ($settled['uncontested']) {
                    $uncontested[] = $event;
                }
            }
            if ($settled['uncontested'] && $settled['winner'] instanceof Participant) {
                $unplayed[] = $settled['winner'];
            }

            if ($key === $extraKey || $tie['thirdPlace']) {
                $extra = $settled;
                continue;
            }

            $inTie[$first->getId()] = true;
            $inTie[$second->getId()] = true;
            $rows[] = [
                min($position[$first->getId()] ?? PHP_INT_MAX, $position[$second->getId()] ?? PHP_INT_MAX),
                $sequence++,
                $settled,
            ];
        }

        foreach ($pairs as $index => $pair) {
            $first = $pair[0] ?? null;
            $second = $pair[1] ?? null;
            $firstFree = $first instanceof Participant && !isset($inTie[$first->getId()]);
            $secondFree = $second instanceof Participant && !isset($inTie[$second->getId()]);

            if ($first instanceof Participant && $second instanceof Participant && $firstFree && $secondFree) {
                $settled = $this->settleTie($reading, $round, $first, $second, $legsPerTie, $reading->levelAdvancers);
                if (!$settled['open']) {
                    $rows[] = [2 * $index, $sequence++, $settled];
                    if ($settled['winner'] instanceof Participant) {
                        $unplayed[] = $settled['winner'];
                    }
                    continue;
                }
                // The round holds no tie between the two and neither has
                // withdrawn: both go on.
            }

            // The place of a pair is held by a tie one of its entrants is in,
            // or by the entrants who go on alone; an empty slot goes on where
            // neither holds it, so that the halves of the bracket stay apart.
            $held = ($first instanceof Participant && !$firstFree) || ($second instanceof Participant && !$secondFree);
            foreach ([[$first, $firstFree, 2 * $index], [$second, $secondFree, 2 * $index + 1]] as [$occupant, $free, $at]) {
                if (!$occupant instanceof Participant || !$free) {
                    continue;
                }

                // An entrant who has withdrawn goes on like any other: when
                // it left is not known, and the tie it stands in next is
                // settled for its opponent there. It went on without playing
                // where the round says so by listing it among its byes: that
                // is recorded, and its leaving later does not take it back.
                $rows[] = [$at, $sequence++, ['winner' => $occupant, 'losers' => []]];
                if (!isset($reading->withdrawn[$occupant->getId()]) || isset($recordedByes[$occupant->getId()])) {
                    $unplayed[] = $occupant;
                }
                $held = true;
            }
            if (!$held) {
                $rows[] = [2 * $index, $sequence++, ['winner' => null, 'losers' => []]];
            }
        }

        if ($extraTie !== null && $extra === null) {
            // The tie that stands in no slot is not in the round: it is
            // settled where an entrant of it has withdrawn, and is left
            // undecided otherwise.
            $settled = $this->settleTie($reading, $round, $extraTie[0], $extraTie[1], $legsPerTie, $reading->levelAdvancers);
            $extra = $settled['open'] ? null : $settled;
        }

        if ($refusals !== []) {
            usort($refusals, static fn(array $one, array $other): int => [$one[0], $one[1], $one[2]] <=> [$other[0], $other[1], $other[2]]);
            $reading->undecidedTie ??= $refusals[0][3];
        }

        $manual = false;
        if ($recorded instanceof RoundPairing) {
            foreach (array_keys($ties) as $key) {
                $manual = $manual || !isset($derived[$key]);
            }
            foreach ($derived as $key => [$first, $second]) {
                $manual = $manual || (
                    !isset($ties[$key])
                    && !isset($reading->withdrawn[$first->getId()])
                    && !isset($reading->withdrawn[$second->getId()])
                );
            }
        }

        usort($rows, static fn(array $one, array $other): int => [$one[0], $one[1]] <=> [$other[0], $other[1]]);

        return [
            'manual' => $manual,
            'units' => array_map(
                static fn(array $row): array => ['winner' => $row[2]['winner'], 'losers' => $row[2]['losers']],
                $rows
            ),
            'extra' => $extra === null ? null : ['winner' => $extra['winner'], 'losers' => $extra['losers']],
            'unplayed' => $unplayed,
            'unresolved' => $unresolved,
            'uncontested' => $uncontested,
            'undecided' => $undecided,
        ];
    }

    private function legKey(int $round, Participant $first, Participant $second, mixed $tieLeg): string
    {
        $leg = is_int($tieLeg) ? $tieLeg : 1;

        return $round . ':' . PairKey::of($first->getId(), $second->getId()) . ':' . $leg;
    }
}
