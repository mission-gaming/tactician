<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\LegStrategies;

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Scheduling\SchedulingContext;
use Override;

/**
 * Leg strategy that mirrors participant roles between legs.
 *
 * In an odd-numbered leg (1, 3, 5, ...) the two participants of a pairing
 * appear in the order given. In an even-numbered leg (2, 4, 6, ...) the
 * order is reversed — read as home/away in football, red/blue corner in
 * combat sports; the core concept is the position within the event.
 *
 * The legs alternate, so a pair's meetings are split as evenly as their
 * number allows: the same number each way over an even number of legs, and
 * one more in the order of the first leg over an odd number. An even
 * number of legs therefore ends with every participant first-named as
 * often as second-named, and an odd number ends as a single leg does.
 *
 * Up to 0.2.x every leg after the first was reversed, so three legs or
 * more split a pair 1:2, 1:3 and so on. One and two legs are as they were.
 *
 * What is reversed is the first leg's pairing, in the same round of the
 * leg. That holds for a scheduler with a randomizer too, whose first leg is
 * laid out from the shuffled order (up to 0.2.x its later legs were laid
 * out from the order as given, and were not the first leg reversed).
 *
 * The roles are a rule: an event of a later leg in the other role would
 * not be mirrored, so the scheduler never reverses one to satisfy a
 * constraint. What a constraint on roles can change is the first leg,
 * which every later leg follows: reversed in the even-numbered legs, as
 * it is in the odd-numbered ones. Its roles are the role assignment's. The
 * scheduler searches them within the rule the role assignment states
 * (the balanced role assignment of the default states one: the first leg
 * stays balanced), and freely with `RoundRobinOptions(backtracking: true)`;
 * under a role assignment that states none, and without backtracking,
 * roles that break a constraint in a later leg fail the schedule.
 *
 * @api
 */
readonly class MirroredLegStrategy implements LegStrategyInterface
{
    /**
     * States that roles mirror across legs (reversed in the even-numbered
     * legs) and that no randomizer is needed. No configuration is
     * unsatisfiable for this strategy, and it reads none of its arguments.
     */
    #[Override]
    public function planLegs(
        array $participants,
        int $legs,
        ConstraintSet $constraints
    ): LegPlanContribution {
        return new LegPlanContribution(
            rolesMirrorAcrossLegs: true,
            requiresRandomization: false
        );
    }

    /**
     * The pairing in the given round: in the given order for an
     * odd-numbered leg, reversed for an even-numbered one. Deterministic,
     * and the context is not read.
     *
     * @return Event|null Null when not given exactly two participants
     */
    #[Override]
    public function generateEventForLeg(
        array $participants,
        int $leg,
        int $round,
        SchedulingContext $context
    ): ?Event {
        if (count($participants) !== 2) {
            return null; // Mirrored strategy only works with 2 participants
        }

        $roundObject = new Round($round);

        if ($leg % 2 === 1) {
            // Odd-numbered legs: the order as given
            return new Event($participants, $roundObject);
        }

        // Even-numbered legs: reverse the positional roles
        return new Event([
            $participants[1],
            $participants[0],
        ], $roundObject);
    }
}
