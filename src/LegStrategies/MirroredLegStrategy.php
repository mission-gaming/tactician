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
 */
readonly class MirroredLegStrategy implements LegStrategyInterface
{
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
