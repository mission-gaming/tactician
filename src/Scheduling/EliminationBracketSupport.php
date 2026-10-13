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
use MissionGaming\Tactician\Stage\PairKey;
use MissionGaming\Tactician\Stage\TieDecision;
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
     * @return array<Event>
     */
    private function buildTieEvents(Participant $first, Participant $second, Round $round, int $legsPerTie): array
    {
        if ($legsPerTie === 1) {
            return [new Event([$first, $second], $round)];
        }

        return [
            new Event([$first, $second], $round, ['tie_leg' => 1]),
            new Event([$second, $first], $round, ['tie_leg' => 2]),
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
     * @param array<string, Result> $resultIndex
     *
     * @throws InvalidConfigurationException When a completed tie is level and carries no
     *                                       usable tie decision (see TieDecision)
     */
    private function lookupAdvancer(
        array $resultIndex,
        int $round,
        Participant $first,
        Participant $second,
        int $legsPerTie
    ): ?Participant {
        $legResults = [];
        for ($leg = 1; $leg <= $legsPerTie; ++$leg) {
            $result = $resultIndex[$this->legKey($round, $first, $second, $legsPerTie === 1 ? null : $leg)] ?? null;
            if ($result !== null) {
                $legResults[] = $result;
            }
        }

        return TieDecision::advancer($legResults, $first, $second, $legsPerTie);
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
                : new Result($event, $advancer, $result->getScores(), $result->getMetadata());
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
     * @param array<Participant> $entrants Every participant of the stage, in list order
     * @param array<Result> $results The results the records are computed from
     * @param array<int|string, int> $placement The tier of each placed participant by ID, 0 best
     */
    private function placementStandings(
        StandingsCalculator $calculator,
        array $entrants,
        array $results,
        array $placement
    ): Standings {
        $table = $calculator->calculate($entrants, $results)->withTiedSetsOrderedBy($entrants);

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

    private function legKey(int $round, Participant $first, Participant $second, mixed $tieLeg): string
    {
        $leg = is_int($tieLeg) ? $tieLeg : 1;

        return $round . ':' . PairKey::of($first->getId(), $second->getId()) . ':' . $leg;
    }
}
