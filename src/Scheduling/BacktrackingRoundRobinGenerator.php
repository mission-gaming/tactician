<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Stage\RoundRobinPlan;

/**
 * Backtracking search over round-robin round decompositions.
 *
 * The circle method fixes which pairings share a round purely by list
 * order, so the greedy generator only ever sees n decompositions (one per
 * rotation). This search treats leg construction as what it is — a
 * constraint-satisfaction problem over perfect matchings: rounds are
 * built in order, each round picks the first unmatched seat and tries
 * every unused opponent in both orientations under the configured
 * constraints, and dead ends backtrack within the round and then into
 * earlier rounds.
 *
 * The search is deterministic (seat, opponent, and orientation order are
 * fixed; orientation prefers the greedy generator's round-parity role
 * balance) and bounded by a fixed step budget so genuinely unsatisfiable
 * configurations fail loudly instead of running away. See
 * docs/design/backtracking-generation.md.
 */
final class BacktrackingRoundRobinGenerator
{
    /**
     * Pairing attempts allowed before the search gives up. Generous for
     * every realistic field size while bounding the exponential worst
     * case to well under a second.
     */
    public const STEP_BUDGET = 200_000;

    private int $stepsRemaining = self::STEP_BUDGET;

    private bool $budgetExhausted = false;

    /** @var array<int, string> Participant IDs receiving a bye, keyed by round number */
    private array $roundByes = [];

    public function __construct(
        private readonly ?ConstraintSet $constraints = null
    ) {
    }

    /**
     * Search for a complete first leg satisfying the constraints.
     *
     * @param array<Participant> $participants The field, in the order seeding the search
     * @return array<Event>|null The leg's events in round order, or null when
     *                           no complete leg exists within the step budget
     */
    public function generateFirstLeg(array $participants, RoundRobinPlan $plan): ?array
    {
        $this->stepsRemaining = self::STEP_BUDGET;
        $this->budgetExhausted = false;
        $this->roundByes = [];

        $participants = array_values($participants);
        $seats = $participants;
        if (count($seats) % 2 === 1) {
            $seats[] = null; // the bye seat: its partner sits the round out
        }

        $roundsPerLeg = $plan->getRoundsPerLeg();

        return $this->searchRound(1, $roundsPerLeg, $participants, $seats, [], [], $plan);
    }

    /**
     * Whether the last search stopped because the step budget ran out —
     * distinct from exhausting the search space, which proves the
     * configuration unsatisfiable.
     */
    public function wasBudgetExhausted(): bool
    {
        return $this->budgetExhausted;
    }

    /**
     * @return array<int, string> Participant IDs receiving a bye, keyed by round number
     */
    public function getRoundByes(): array
    {
        return $this->roundByes;
    }

    /**
     * @param array<Participant> $participants The full field
     * @param array<Participant|null> $seats The field plus the bye seat for odd counts
     * @param array<string, true> $usedPairs Pair keys already scheduled this leg
     * @param array<Event> $events All events of completed rounds
     * @return array<Event>|null
     */
    private function searchRound(
        int $round,
        int $totalRounds,
        array $participants,
        array $seats,
        array $usedPairs,
        array $events,
        RoundRobinPlan $plan
    ): ?array {
        if ($round > $totalRounds) {
            return $events;
        }

        return $this->searchMatching($seats, [], $round, $totalRounds, $participants, $seats, $usedPairs, $events, $plan);
    }

    /**
     * Extend the current round's partial matching, recursing into the next
     * round when it completes.
     *
     * @param array<Participant|null> $remaining Seats not yet matched this round
     * @param array<Event> $roundEvents The round's events so far
     * @param array<Participant> $participants The full field
     * @param array<Participant|null> $seats The full seat list
     * @param array<string, true> $usedPairs
     * @param array<Event> $events
     * @return array<Event>|null
     */
    private function searchMatching(
        array $remaining,
        array $roundEvents,
        int $round,
        int $totalRounds,
        array $participants,
        array $seats,
        array $usedPairs,
        array $events,
        RoundRobinPlan $plan
    ): ?array {
        if ($this->budgetExhausted) {
            return null;
        }

        if ($remaining === []) {
            return $this->searchRound(
                $round + 1,
                $totalRounds,
                $participants,
                $seats,
                $usedPairs,
                [...$events, ...$roundEvents],
                $plan
            );
        }

        // The bye seat is appended last and pairs are removed two at a
        // time, so the pivot is always a real participant.
        $pivot = array_shift($remaining);
        assert($pivot instanceof Participant);

        foreach ($remaining as $index => $candidate) {
            $pairKey = $this->pairKey($pivot, $candidate);
            if (isset($usedPairs[$pairKey])) {
                continue;
            }

            $rest = $remaining;
            unset($rest[$index]);
            $rest = array_values($rest);
            $nextUsedPairs = [...$usedPairs, $pairKey => true];

            if ($candidate === null) {
                // Pairing with the bye seat: the pivot sits this round out.
                $this->roundByes[$round] = $pivot->getId();
                $result = $this->searchMatching($rest, $roundEvents, $round, $totalRounds, $participants, $seats, $nextUsedPairs, $events, $plan);
                if ($result !== null) {
                    return $result;
                }
                unset($this->roundByes[$round]);
                continue;
            }

            foreach ($this->orientations($pivot, $candidate, $round) as $pair) {
                if ($this->stepsRemaining-- <= 0) {
                    $this->budgetExhausted = true;

                    return null;
                }

                $event = new Event($pair, new Round($round));
                if (!$this->satisfiesConstraints($event, $participants, [...$events, ...$roundEvents], $plan)) {
                    continue;
                }

                $result = $this->searchMatching($rest, [...$roundEvents, $event], $round, $totalRounds, $participants, $seats, $nextUsedPairs, $events, $plan);
                if ($result !== null) {
                    return $result;
                }
            }
        }

        return null;
    }

    /**
     * Both orientations of a pairing, round-parity-preferred first so an
     * unconstrained search reproduces the greedy generator's role balance.
     *
     * @return array<array{Participant, Participant}>
     */
    private function orientations(Participant $pivot, Participant $candidate, int $round): array
    {
        $preferred = $round % 2 === 1 ? [$pivot, $candidate] : [$candidate, $pivot];

        return [$preferred, array_reverse($preferred)];
    }

    /**
     * @param array<Participant> $participants
     * @param array<Event> $priorEvents
     */
    private function satisfiesConstraints(Event $event, array $participants, array $priorEvents, RoundRobinPlan $plan): bool
    {
        if ($this->constraints === null) {
            return true;
        }

        $context = new SchedulingContext($participants, $plan, $priorEvents, 1);

        return $this->constraints->isSatisfied($event, $context);
    }

    private function pairKey(Participant $a, ?Participant $b): string
    {
        $ids = [$a->getId(), $b?->getId() ?? "\0bye"];
        sort($ids);

        return implode('|', $ids);
    }
}
