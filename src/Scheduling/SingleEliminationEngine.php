<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Stage\BracketTie;
use MissionGaming\Tactician\Stage\EliminationPlan;
use MissionGaming\Tactician\Stage\EngineFingerprint;
use MissionGaming\Tactician\Stage\FingerprintedEngine;
use MissionGaming\Tactician\Stage\PairKey;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Stage\StageEngineInterface;
use MissionGaming\Tactician\Stage\StageOutcome;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Standings\StandingsCalculator;
use MissionGaming\Tactician\Standings\WinDrawLossRanking;
use Override;

/**
 * Single elimination preset: a canned composition of single-round
 * knockout stages behind the standard stage driver loop.
 *
 * Entry pairing folds by list position (in an eight-slot bracket:
 * 1 vs 8, 4 vs 5, 2 vs 7, 3 vs 6), placing positions 1 and 2 in opposite
 * halves so the top entrants can only meet in the latest possible round;
 * fields that are not a power of two give byes to the top positions.
 * Position is authoritative - feed the entrant list in seeding order
 * (progression selectors already do).
 *
 * Two path behaviours (EliminationOptions):
 * - Fixed bracket path (default): survivors keep their bracket slots, so
 *   the draw made at entry decides every route to the final.
 * - Re-seeded (reseedEachRound): survivors are re-ranked by standings
 *   after every round and re-folded, so the strongest remaining records
 *   meet as late as possible. A bye counts in that ranking as the tie it
 *   stands for, won in every leg, and survivors who are level are in the
 *   order of the participant list: in a field of five, positions 1 and 2
 *   are in opposite halves of the semifinals.
 *
 * Ties are played over one event or two mirrored legs (legsPerTie). A tie
 * that finishes level - a drawn single event, or two legs that do not
 * decide - is the application's to decide and is recorded as a tie
 * decision on the result (see TieDecision); a level tie without one is
 * refused by every call.
 *
 * A third-place tie (thirdPlace, off by default) makes the bracket decide
 * places 3 and 4: the last round then holds two ties, the final and a tie
 * between the two entrants who went out in the round before it. It is a
 * tie like every other: the same number of legs, the same reading of a
 * level result, and the round is complete when both ties are decided.
 * Both are in the round labelled 'final', the final's events first, and
 * each event of that round says which tie it belongs to (see BracketTie).
 * On a fixed path the entrant who went out of the tie that fed the final's
 * first-named side is first-named; a re-seeded bracket puts the two in the
 * order of the table it folds the final from. A field of 3 has one entrant
 * who goes out before the final, so no third-place tie is played and that
 * entrant is third; a field of 2 is refused with the option, because it
 * has no third place.
 *
 * There is deliberately no champion accessor. The participant who
 * advances from the final is rank 1 of the outcome's standings, which are
 * in bracket placement order (see getOutcome()), and is the first
 * participant MatchOutcomeSelector::winners() returns for the outcome's
 * final round.
 *
 * @experimental
 */
final readonly class SingleEliminationEngine implements StageEngineInterface, FingerprintedEngine
{
    use EliminationBracketSupport;

    /**
     * Every option is accepted; grandFinalReset is not read.
     *
     * @param StandingsCalculator $standingsCalculator Computes the records of the outcome's standings
     *                                                 and orders the entrants who went out in the
     *                                                 same round; in a re-seeded bracket it also
     *                                                 orders the survivors before every round after
     *                                                 the first. On a fixed path it pairs nothing
     */
    public function __construct(
        private EliminationOptions $options = new EliminationOptions(),
        private StandingsCalculator $standingsCalculator = new StandingsCalculator()
    ) {}

    /**
     * What this engine stamps a stage state with, and requires of a state
     * that carries a stamp (see FingerprintedEngine for the contract).
     *
     * With default options it names the format and nothing else. Part of
     * it when not at the default: legsPerTie (default 1), reseedEachRound
     * (default false) and thirdPlace (default false), so a state stamped
     * by an engine without a third-place tie is refused by one with it,
     * and the other way round. A re-seeded bracket is paired from
     * the table after every round, so there the rules of the standings
     * calculator are part of it as well, when they are not the default:
     * the ranking scale (the three values of a WinDrawLossRanking, default
     * 3/1/0; any other RankingStrategy is stated as custom and not told
     * apart from another of your own) and the tiebreakers by name and in
     * order, or, in their place, that the calculator is a subclass of
     * StandingsCalculator (stated as custom; nothing is read from it, and
     * two subclasses are not told apart).
     *
     * Not part of it: grandFinalReset, which this engine does not read,
     * and the standings calculator of a bracket on a fixed path, which
     * orders the outcome and pairs nothing.
     */
    #[Override]
    public function getFingerprint(): string
    {
        $fingerprint = EngineFingerprint::of('single-elimination')
            ->with('legs-per-tie', $this->options->legsPerTie, 1)
            ->with('reseed-each-round', $this->options->reseedEachRound, false)
            ->with('third-place', $this->options->thirdPlace, false);

        if ($this->options->reseedEachRound) {
            $fingerprint = $fingerprint->withStandingsRules($this->standingsCalculator);
        }

        return $fingerprint->toString();
    }

    /**
     * The shape of the bracket for every participant the state has seen:
     * the bracket size, the number of rounds and the events to expect, the
     * events of a third-place tie included where the bracket has one.
     *
     * @throws InvalidConfigurationException When fewer than 2 participants have been seen, or
     *                                       fewer than 3 with the third-place option, or the
     *                                       state is stamped with another engine's fingerprint
     */
    #[Override]
    public function getPlan(StageState $state): EliminationPlan
    {
        $state->requireEngineFingerprint($this->getFingerprint());

        return new EliminationPlan(
            $state->getAllSeenParticipants(),
            'single-elimination',
            $this->options->legsPerTie,
            $this->options->thirdPlace
        );
    }

    /**
     * Pair the next unresolved round of the bracket.
     *
     * The bracket is replayed from the state's participant list and its
     * results on every call, so the same state gives the same pairing, and
     * a state that has the round's pairing recorded but none of its results
     * gives that round again. The call does not record anything: pass the
     * pairing and its results to StageState::withRoundPlayed().
     *
     * Round numbers are 1-based, and the round carries its label ('round
     * of 16', 'quarterfinal', 'semifinal', 'final'). The events are in
     * bracket order, top of the bracket first; a two-legged tie is two
     * consecutive events with the roles reversed, marked 'tie_leg' 1 and 2
     * in their metadata. The byes are the participants who advance without
     * playing, which happens in the first round only.
     *
     * With the third-place option the last round, labelled 'final', lists
     * the events of the final and then those of the third-place tie, and
     * each of them carries the tie it belongs to under
     * BracketTie::METADATA_KEY in its metadata ('final' or 'third-place');
     * read it with BracketTie::of().
     *
     * @throws InvalidConfigurationException When the state has fewer than 2 participants, or
     *                                       fewer than 3 with the third-place option
     *                                       (`TooFewParticipants`), or is
     *                                       stamped with another engine's fingerprint, a result
     *                                       has no round number, is not of a two-participant
     *                                       event or is recorded twice, a round is partially
     *                                       resolved (record the missing results via
     *                                       StageState::withAdditionalResults()), a completed tie
     *                                       is level without a usable tie decision, a recorded
     *                                       round pairs other participants than the bracket has
     *                                       in it (reason `RecordedRoundMismatch`), a re-seeded
     *                                       bracket has a bye and a ranking strategy that is not
     *                                       a WinDrawLossRanking (`IncompatibleOptions`), or the
     *                                       bracket is complete
     */
    #[Override]
    public function pairNextRound(StageState $state): RoundPairing
    {
        $resolution = $this->resolveBracket($state);

        if ($resolution['pending'] === null) {
            throw new InvalidConfigurationException(
                'Bracket is complete; no further rounds exist',
                [],
                reason: InvalidConfigurationReason::BracketComplete
            );
        }

        return $resolution['pending'];
    }

    /**
     * Whether the final has been decided: every round of the bracket has a
     * result for each of its ties. A bracket with a third-place tie is
     * complete when that tie is decided as well; with one of the two ties
     * of the last round decided and the other not, the state is refused as
     * partially resolved, as it is in every round.
     *
     * @throws InvalidConfigurationException When the recorded state is malformed: every case
     *                                       pairNextRound() names but the complete bracket
     */
    #[Override]
    public function isComplete(StageState $state): bool
    {
        return $this->resolveBracket($state)['pending'] === null;
    }

    /**
     * The uniform completion product; null while the bracket is unfinished.
     *
     * The standings are in bracket placement order, whatever the field
     * size, the byes and the legs per tie: the entrant who advanced from
     * the final is rank 1, the other finalist rank 2, then the two who
     * went out in the semifinals, then the four of the quarter-finals, and
     * so on back to the first round. The bracket decides those tiers
     * (Standings::getTiers()), and no record moves an entrant from one to
     * another: a finalist who had a bye, or who advanced from a two-legged
     * final on a tie decision, is still above the entrant it beat.
     *
     * A third-place tie decides two places more: the entrant who advanced
     * from it is rank 3 and the other rank 4, each alone in its tier, and
     * the tiers of the earlier rounds follow. Its results count in the
     * records as those of every tie do. The outcome's final round then
     * holds both ties, so MatchOutcomeSelector reads one participant from
     * each, the final's first: winners() gives ranks 1 and 3, losers()
     * ranks 2 and 4. A field of 3 with the option has the table it has
     * without it.
     *
     * Entrants who went out in the same round are ordered by the standings
     * calculator from their records (ranking value, tiebreakers, score
     * difference, scores-for), and where that leaves them level, by their
     * position in the participant list. Standings::getTiedSets() reports
     * those. The records are win/draw/loss records over the bracket's
     * results: a bye is no win in them, the legs of a two-legged tie count
     * as recorded, and a single-leg event that finished level and was
     * decided by its tie decision counts as a win for the participant who
     * advanced. The outcome's results are the results as recorded, the
     * draw included.
     *
     * @throws InvalidConfigurationException When the recorded state is malformed (see isComplete())
     */
    #[Override]
    public function getOutcome(StageState $state): ?StageOutcome
    {
        $resolution = $this->resolveBracket($state);
        if ($resolution['pending'] !== null) {
            return null;
        }

        $entrants = $state->getAllSeenParticipants();

        return new StageOutcome(
            $this->placementStandings(
                $this->standingsCalculator,
                $entrants,
                $this->resultsForStandings($state->getResults(), $resolution['levelAdvancers']),
                $resolution['placement']
            ),
            $state->getResults(),
            $state->getByeCounts(),
            $state->getLastRound(),
            [],
            $entrants
        );
    }

    /**
     * Replay the bracket from the entry fold through the recorded results.
     *
     * @return array{pending: RoundPairing|null, levelAdvancers: array<string, Participant>, placement: array<int|string, int>}
     *                                                                                                                         The placement is the tier of every
     *                                                                                                                         entrant of a finished bracket, 0 for
     *                                                                                                                         the one who advanced from the final
     *
     * @throws InvalidConfigurationException When a round is partially resolved, a tie is broken, a
     *                                       recorded round is not the round the bracket has there,
     *                                       a bye cannot be credited, or the state is stamped with
     *                                       another engine's fingerprint
     */
    private function resolveBracket(StageState $state): array
    {
        $state->requireEngineFingerprint($this->getFingerprint());

        $participants = array_values($state->getParticipants());
        $this->validateParticipants($participants);
        $this->requireByesToBeCreditable(count($participants));
        $this->requireAThirdPlaceToDecide(count($participants));

        $resultIndex = $this->indexResults($state->getResults());
        $totalRounds = (int) log($this->bracketSize(count($participants)), 2);

        $slots = $this->buildInitialSlots($participants);
        $levelAdvancers = [];
        $byes = [];
        $wentOutIn = [];
        $wentOutInPreviousRound = [];
        $thirdPlaceTie = null;
        $thirdPlaceAdvancer = null;

        for ($round = 1; $round <= $totalRounds; ++$round) {
            // Re-seeded path: after the entry round, survivors are
            // re-ranked by standings and re-folded each round. Only
            // results from earlier rounds may inform the ranking - the
            // replay must reproduce the same pairing before and after the
            // round's own results are recorded.
            if ($this->options->reseedEachRound && $round > 1) {
                $survivors = array_values(array_filter($slots, fn(?Participant $slot) => $slot !== null));
                $slots = $this->buildInitialSlots($this->rankByStandings($survivors, $state, $round, $levelAdvancers, $byes));
            }

            $pairs = array_chunk($slots, 2);
            $playable = [];
            $resolved = 0;

            foreach ($pairs as $pair) {
                if ($pair[0] !== null && ($pair[1] ?? null) !== null) {
                    $playable[] = $pair;
                    if ($this->lookupAdvancer($resultIndex, $round, $pair[0], $pair[1], $this->options->legsPerTie) !== null) {
                        ++$resolved;
                    }
                }
            }

            // The third-place tie is a tie of the last round like the
            // final: the round is paired with both, is partially resolved
            // with one of them decided, and is complete with both.
            if ($round === $totalRounds) {
                $thirdPlaceTie = $this->thirdPlaceTie($wentOutInPreviousRound, $state, $round, $levelAdvancers, $byes);
            }
            if ($thirdPlaceTie !== null) {
                $playable[] = $thirdPlaceTie;
                $thirdPlaceAdvancer = $this->lookupAdvancer($resultIndex, $round, $thirdPlaceTie[0], $thirdPlaceTie[1], $this->options->legsPerTie);
                $resolved += $thirdPlaceAdvancer instanceof Participant ? 1 : 0;
            }

            $this->requireRecordedRoundToMatch($state, $round, $playable);

            if ($resolved < count($playable)) {
                if ($resolved > 0) {
                    throw new InvalidConfigurationException(
                        "Round {$round} is partially resolved: {$resolved} of " . count($playable)
                            . ' ties have complete results. Record the remaining results before pairing the next round.',
                        ['round' => $round, 'resolved' => $resolved, 'playable' => count($playable)],
                        reason: InvalidConfigurationReason::RoundPartiallyResolved
                    );
                }

                return [
                    'pending' => $this->buildRoundPairing($round, $totalRounds, $pairs, $thirdPlaceTie),
                    'levelAdvancers' => $levelAdvancers,
                    'placement' => [],
                ];
            }

            $nextSlots = [];
            $wentOutInPreviousRound = [];
            foreach ($pairs as $pair) {
                [$first, $second] = [$pair[0], $pair[1] ?? null];
                if ($first !== null && $second !== null) {
                    $advancer = $this->lookupAdvancer($resultIndex, $round, $first, $second, $this->options->legsPerTie);
                    $this->noteLevelAdvancer($levelAdvancers, $resultIndex, $round, $first, $second, $this->options->legsPerTie, $advancer);
                    $nextSlots[] = $advancer;
                    $wentOut = $advancer?->getId() === $first->getId() ? $second : $first;
                    $wentOutIn[$wentOut->getId()] = $round;
                    $wentOutInPreviousRound[] = $wentOut;
                } else {
                    $sitsOut = $first ?? $second;
                    $nextSlots[] = $sitsOut;
                    if ($sitsOut !== null) {
                        $byes[$sitsOut->getId()] = ($byes[$sitsOut->getId()] ?? 0) + 1;
                    }
                }
            }

            if ($thirdPlaceTie !== null) {
                $this->noteLevelAdvancer($levelAdvancers, $resultIndex, $round, $thirdPlaceTie[0], $thirdPlaceTie[1], $this->options->legsPerTie, $thirdPlaceAdvancer);
            }

            $slots = $nextSlots;
        }

        // The entrant who advanced from the final is in the first tier,
        // and everyone else in the tier of the round they went out in:
        // the final's is the second, the semifinals' the third. A
        // third-place tie splits the tier of the semifinals in two, its
        // winner above the other, and every earlier round is a tier lower.
        $placement = [];
        foreach ($wentOutIn as $id => $round) {
            $tier = $totalRounds - $round + 1;
            if ($thirdPlaceAdvancer instanceof Participant && $round < $totalRounds) {
                $tier += $round < $totalRounds - 1 || (string) $id !== $thirdPlaceAdvancer->getId() ? 1 : 0;
            }
            $placement[$id] = $tier;
        }
        foreach ($slots as $slot) {
            if ($slot !== null) {
                $placement[$slot->getId()] = 0;
            }
        }

        return ['pending' => null, 'levelAdvancers' => $levelAdvancers, 'placement' => $placement];
    }

    /**
     * The third-place tie of the last round, or null when the bracket has
     * none: the option is off, or fewer than two entrants went out in the
     * round before the last (a field of 3, where the top entrant has a bye
     * in that round).
     *
     * The first-named entrant is the one who went out of the tie that fed
     * the final's first-named side, so on a fixed path the tie mirrors the
     * final. A re-seeded bracket folds the final from the table of the
     * earlier rounds, and the two entrants of this tie are in the order of
     * the same table.
     *
     * @param list<Participant> $wentOutInPreviousRound In the order of that round's ties
     * @param array<string, Participant> $levelAdvancers
     * @param array<int|string, int> $byes Byes of the earlier rounds, by participant ID
     * @return array{Participant, Participant}|null
     *
     * @throws InvalidConfigurationException When the bracket is re-seeded, one of the two had a
     *                                       bye and the ranking strategy is not a
     *                                       WinDrawLossRanking
     */
    private function thirdPlaceTie(
        array $wentOutInPreviousRound,
        StageState $state,
        int $round,
        array $levelAdvancers,
        array $byes
    ): ?array {
        if (!$this->options->thirdPlace || count($wentOutInPreviousRound) !== 2) {
            return null;
        }

        $entrants = $this->options->reseedEachRound
            ? $this->rankByStandings($wentOutInPreviousRound, $state, $round, $levelAdvancers, $byes)
            : $wentOutInPreviousRound;

        return [$entrants[0], $entrants[1]];
    }

    /**
     * Refuse the third-place option for a field of 2: its one tie decides
     * the two places there are. The entrant list says so before a round is
     * paired, so the bracket is refused then, on every call.
     *
     * A field of 3 is accepted: it has no third-place tie, and the one
     * entrant who goes out before the final is third.
     *
     * @throws InvalidConfigurationException When the option is on and the field has fewer than 3
     *                                       entrants
     */
    private function requireAThirdPlaceToDecide(int $participantCount): void
    {
        if (!$this->options->thirdPlace || $participantCount >= EliminationPlan::THIRD_PLACE_MINIMUM) {
            return;
        }

        throw new InvalidConfigurationException(
            'A bracket with the third-place option requires at least ' . EliminationPlan::THIRD_PLACE_MINIMUM
                . ' participants: with 2 there is no third place to decide',
            ['participant_count' => $participantCount, 'minimum_required' => EliminationPlan::THIRD_PLACE_MINIMUM],
            reason: InvalidConfigurationReason::TooFewParticipants
        );
    }

    /**
     * Refuse a re-seeded bracket that will have a bye it cannot credit.
     *
     * A field that is not a power of two has byes in its first round, and
     * the ranking before the second round credits each as a tie won, which
     * needs the value of a win (StandingsCalculator::withByesCredited()).
     * The entrant list says all of that before a round is paired, so the
     * bracket is refused then, on every call, and not after its first
     * round has been played.
     *
     * @throws InvalidConfigurationException When the bracket is re-seeded, the field is not a
     *                                       power of two and the ranking strategy is not a
     *                                       WinDrawLossRanking
     */
    private function requireByesToBeCreditable(int $participantCount): void
    {
        if (!$this->options->reseedEachRound || $participantCount === $this->bracketSize($participantCount)) {
            return;
        }

        $rankingStrategy = $this->standingsCalculator->getRankingStrategy();
        if ($rankingStrategy instanceof WinDrawLossRanking) {
            return;
        }

        throw new InvalidConfigurationException(
            "A re-seeded bracket of {$participantCount} entrants has byes, and crediting a bye requires a win/draw/loss"
                . ' ranking strategy: counting a bye as a tie won is undefined under other ranking scales.'
                . ' Use a WinDrawLossRanking, a field that is a power of two, or a bracket on a fixed path',
            ['ranking_strategy' => $rankingStrategy::class, 'participant_count' => $participantCount],
            reason: InvalidConfigurationReason::IncompatibleOptions
        );
    }

    /**
     * Refuse a state whose recorded round is not the round the bracket has
     * at that number: the recorded results would be read against ties that
     * were never played. A state is in that condition when its rounds were
     * paired under other rules than this engine's (another option, or the
     * re-seeding order of an earlier release) or when its participant list
     * changed after a round was recorded.
     *
     * @param list<array<Participant|null>> $playable The ties the bracket has in the round
     *
     * @throws InvalidConfigurationException When the recorded round pairs other participants
     */
    private function requireRecordedRoundToMatch(StageState $state, int $round, array $playable): void
    {
        $expected = [];
        foreach ($playable as $pair) {
            if ($pair[0] !== null && ($pair[1] ?? null) !== null) {
                $expected[PairKey::of($pair[0]->getId(), $pair[1]->getId())] = true;
            }
        }

        foreach ($state->getRoundsPlayed() as $recorded) {
            if ($recorded->getRoundNumber() !== $round) {
                continue;
            }

            $recordedPairs = [];
            foreach ($recorded->getEvents() as $event) {
                $recordedPairs[PairKey::of(...array_map(
                    static fn(Participant $participant): string => $participant->getId(),
                    array_values($event->getParticipants())
                ))] = true;
            }

            // A recorded round may hold more than the bracket's ties (an
            // event the application added is read by nothing); it may not
            // lack one of them.
            if (array_diff_key($expected, $recordedPairs) !== []) {
                throw new InvalidConfigurationException(
                    "The recorded round {$round} is not the round {$round} this bracket has: it pairs other participants."
                        . ' The state was paired under other rules than this engine applies (other options, or the'
                        . ' re-seeding order of an earlier release), or its participant list changed after the round'
                        . ' was recorded. Rebuild the state from the rounds that stand.',
                    ['round' => $round, 'recorded' => array_keys($recordedPairs), 'expected' => array_keys($expected)],
                    reason: InvalidConfigurationReason::RecordedRoundMismatch
                );
            }
        }
    }

    /**
     * The events of a round: the ties of the bracket in bracket order,
     * then the third-place tie where the round has one.
     *
     * With the third-place option, every event of the last round says
     * which tie it belongs to (see BracketTie), also in a field of 3,
     * whose last round holds the final alone. Without the option no event
     * carries that mark.
     *
     * @param array<array<Participant|null>> $pairs
     * @param array{Participant, Participant}|null $thirdPlaceTie
     */
    private function buildRoundPairing(int $roundNumber, int $totalRounds, array $pairs, ?array $thirdPlaceTie): RoundPairing
    {
        $label = $this->roundLabel($roundNumber, $totalRounds);
        $round = new Round($roundNumber, ['label' => $label]);
        $marked = $this->options->thirdPlace && $roundNumber === $totalRounds;

        $events = [];
        $byes = [];
        foreach ($pairs as $pair) {
            [$first, $second] = [$pair[0], $pair[1] ?? null];
            if ($first !== null && $second !== null) {
                $events = [...$events, ...$this->buildTieEvents(
                    $first,
                    $second,
                    $round,
                    $this->options->legsPerTie,
                    $marked ? [BracketTie::METADATA_KEY => BracketTie::Final->value] : []
                )];
                continue;
            }

            $advancer = $first ?? $second;
            if ($advancer !== null) {
                $byes[] = $advancer;
            }
        }

        if ($thirdPlaceTie !== null) {
            $events = [...$events, ...$this->buildTieEvents(
                $thirdPlaceTie[0],
                $thirdPlaceTie[1],
                $round,
                $this->options->legsPerTie,
                [BracketTie::METADATA_KEY => BracketTie::ThirdPlace->value]
            )];
        }

        return new RoundPairing($roundNumber, $label, $events, $byes);
    }

    /**
     * Order survivors by their standings rank as of the given round:
     * only results from earlier rounds count, so the ranking is stable
     * across replays regardless of what has been recorded since. A level
     * event of an earlier round counts as a win for whoever advanced from
     * it (see resultsForStandings()).
     *
     * A bye is an advance like a tie won, so it is credited as one: a win
     * for each leg of a tie. Without that an entrant who sat out the first
     * round would rank below everyone who played it, and the top of the
     * entrant list would be folded as the bottom of the round. Survivors
     * the table leaves level are in the order of the participant list:
     * no seed attribute, label or ID is read.
     *
     * @param array<Participant> $survivors
     * @param array<string, Participant> $levelAdvancers
     * @param array<int|string, int> $byes Byes of the earlier rounds, by participant ID
     * @return array<Participant>
     *
     * @throws InvalidConfigurationException When a survivor had a bye and the ranking strategy is
     *                                       not a WinDrawLossRanking
     */
    private function rankByStandings(
        array $survivors,
        StageState $state,
        int $beforeRound,
        array $levelAdvancers,
        array $byes
    ): array {
        $priorResults = array_values(array_filter(
            $state->getResults(),
            fn($result) => ($result->getEvent()->getRound()?->getNumber() ?? 0) < $beforeRound
        ));

        $entrants = $state->getAllSeenParticipants();
        $standings = $this->standingsCalculator
            ->withByesCredited(
                $this->standingsCalculator->calculate($entrants, $this->resultsForStandings($priorResults, $levelAdvancers)),
                $byes,
                $this->options->legsPerTie
            )
            ->withTiedSetsOrderedBy($entrants);

        $survivorIds = [];
        foreach ($survivors as $survivor) {
            $survivorIds[$survivor->getId()] = true;
        }

        $ranked = [];
        foreach ($standings->getEntries() as $entry) {
            if (isset($survivorIds[$entry->getParticipant()->getId()])) {
                $ranked[] = $entry->getParticipant();
            }
        }

        return $ranked;
    }

    private function roundLabel(int $roundNumber, int $totalRounds): string
    {
        return match ($totalRounds - $roundNumber) {
            0 => 'final',
            1 => 'semifinal',
            2 => 'quarterfinal',
            default => 'round of ' . 2 ** ($totalRounds - $roundNumber + 1),
        };
    }
}
