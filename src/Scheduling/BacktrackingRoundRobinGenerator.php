<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Stage\PairKey;
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
 *
 * @internal Not public API: the search `RoundRobinScheduler` runs for
 *           `RoundRobinOptions(backtracking: true)`. Turn it on with that option.
 */
final class BacktrackingRoundRobinGenerator
{
    /**
     * Pairing attempts allowed before the search gives up. Generous for
     * every realistic field size, and a bound on the exponential worst
     * case.
     *
     * The bound is on attempts, not on time: an attempt costs one event
     * and one evaluation of the constraints. Spending the whole budget
     * took 0.5 to 0.8 seconds with a constraint that answers in constant
     * time (fields of 8 and of 24), and 1.8 seconds with a role balance
     * limit on a field of 20, which reads each participant's history
     * (PHP 8.4 without OPcache, one core; `composer bench` measures the
     * machine at hand).
     */
    public const STEP_BUDGET = 200_000;

    private int $stepsRemaining;

    private bool $budgetExhausted = false;

    /** @var array<int, string> Participant IDs receiving a bye, keyed by round number */
    private array $roundByes = [];

    /** @var array<string, true> Pair keys on the current search path */
    private array $usedPairs = [];

    /** @var list<Event> The events on the current search path, in round order */
    private array $placedEvents = [];

    /**
     * @param int $stepBudget Pairing attempts allowed before giving up;
     *                        the default suits every realistic field, and
     *                        overriding is primarily a test seam
     */
    public function __construct(
        private readonly ?ConstraintSet $constraints = null,
        private readonly int $stepBudget = self::STEP_BUDGET
    ) {
        $this->stepsRemaining = $stepBudget;
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
        $this->stepsRemaining = $this->stepBudget;
        $this->budgetExhausted = false;
        $this->roundByes = [];
        $this->usedPairs = [];
        $this->placedEvents = [];

        $participants = array_values($participants);
        $seats = $participants;
        if (count($seats) % 2 === 1) {
            $seats[] = null; // the bye seat: its partner sits the round out
        }

        $roundsPerLeg = $plan->getRoundsPerLeg();

        // Constraints see the events placed so far through a context. The
        // search keeps one per node of the path and extends it by the one
        // event a placement adds, where it used to build a context, and a
        // copy of every placed event, for each pairing attempt.
        $context = $this->constraints === null ? null : new SchedulingContext($participants, $plan, [], 1);

        $found = $this->searchRound(1, $roundsPerLeg, $seats, $context);
        $events = $this->placedEvents;
        $this->usedPairs = [];
        $this->placedEvents = [];

        return $found ? $events : null;
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
     * @param array<Participant|null> $seats The field plus the bye seat for odd counts
     * @param SchedulingContext|null $context The context of the events placed so far; null without constraints
     * @return bool Whether the rounds from this one on were completed, leaving their events in $placedEvents
     */
    private function searchRound(int $round, int $totalRounds, array $seats, ?SchedulingContext $context): bool
    {
        if ($round > $totalRounds) {
            return true;
        }

        return $this->searchMatching($seats, $round, $totalRounds, $seats, $context);
    }

    /**
     * Extend the current round's partial matching, recursing into the next
     * round when it completes.
     *
     * The path's state ($usedPairs, $placedEvents, $roundByes) is changed
     * in place and restored when a branch fails, so a step costs the
     * pairing it tries and not a copy of the path. The order of seats,
     * opponents and orientations, and what counts as a step, are what they
     * were when each call carried its own copies: the search visits the
     * same nodes in the same order and stops at the same step.
     *
     * @param array<Participant|null> $remaining Seats not yet matched this round
     * @param array<Participant|null> $seats The full seat list
     * @param SchedulingContext|null $context The context of the events placed so far; null without constraints
     * @return bool Whether the leg was completed from here, leaving its events in $placedEvents
     */
    private function searchMatching(
        array $remaining,
        int $round,
        int $totalRounds,
        array $seats,
        ?SchedulingContext $context
    ): bool {
        if ($remaining === []) {
            return $this->searchRound($round + 1, $totalRounds, $seats, $context);
        }

        // The bye seat is appended last and pairs are removed two at a
        // time, so the pivot is always a real participant.
        $pivot = array_shift($remaining);
        assert($pivot instanceof Participant);

        foreach ($remaining as $index => $candidate) {
            $pairKey = $this->pairKey($pivot, $candidate);
            if (isset($this->usedPairs[$pairKey])) {
                continue;
            }

            $rest = $remaining;
            unset($rest[$index]);
            $rest = array_values($rest);

            if ($candidate === null) {
                // Pairing with the bye seat: the pivot sits this round out.
                $this->usedPairs[$pairKey] = true;
                $this->roundByes[$round] = $pivot->getId();
                if ($this->searchMatching($rest, $round, $totalRounds, $seats, $context)) {
                    return true;
                }
                unset($this->roundByes[$round], $this->usedPairs[$pairKey]);
                continue;
            }

            foreach ($this->orientations($pivot, $candidate, $round) as $pair) {
                if ($this->stepsRemaining-- <= 0) {
                    $this->budgetExhausted = true;

                    return false;
                }

                $event = new Event($pair, new Round($round));
                if ($context !== null && $this->constraints?->isSatisfied($event, $context) === false) {
                    continue;
                }

                $this->usedPairs[$pairKey] = true;
                $this->placedEvents[] = $event;
                if ($this->searchMatching($rest, $round, $totalRounds, $seats, $context?->withEvents([$event]))) {
                    return true;
                }
                array_pop($this->placedEvents);
                unset($this->usedPairs[$pairKey]);
            }
        }

        return false;
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
     * The key of a pairing, or of a participant's bye when the other seat
     * is the bye seat.
     *
     * A bye is keyed by the one id behind a NUL byte. A key of one id
     * holds no separator and a pairing's key holds exactly one, so a bye
     * never shares a key with a pairing, whatever the ids are. The NUL
     * keeps the key a string: PHP would turn the bare id `'7'` into the
     * integer key 7; a string key keeps one type for every id.
     */
    private function pairKey(Participant $a, ?Participant $b): string
    {
        return $b === null ? "\0" . PairKey::of($a->getId()) : PairKey::of($a->getId(), $b->getId());
    }
}
