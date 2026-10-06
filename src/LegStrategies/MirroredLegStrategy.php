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
 * In the first leg, participants appear in their given positional order.
 * In subsequent legs, the order is reversed — read as home/away in
 * football, red/blue corner in combat sports; the core concept is the
 * position within the event.
 *
 * Every leg after the first is reversed, not every second one: a pair that
 * meets over three legs has one meeting one way and two the other way. Two
 * legs are the balanced case.
 *
 * What is reversed is the pairing as the scheduler lays the leg out. In a
 * schedule from a scheduler without a randomizer that is the first leg's
 * pairing, in the same round of the leg. A scheduler with a randomizer
 * shuffles the participant order for the first leg only, so its later legs
 * are not the first leg reversed pairing by pairing.
 *
 * @api
 */
readonly class MirroredLegStrategy implements LegStrategyInterface
{
    /**
     * States that roles mirror across legs and that no randomizer is
     * needed. No configuration is unsatisfiable for this strategy, and it
     * reads none of its arguments.
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
     * The pairing in the given round: in the given order for leg 1,
     * reversed for every later leg. Deterministic, and the context is not
     * read.
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

        if ($leg === 1) {
            // First leg: use original order
            return new Event($participants, $roundObject);
        }

        // Subsequent legs: reverse the positional roles
        return new Event([
            $participants[1],
            $participants[0],
        ], $roundObject);
    }
}
