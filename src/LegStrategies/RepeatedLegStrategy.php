<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\LegStrategies;

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Scheduling\SchedulingContext;
use Override;

/**
 * Strategy that repeats the exact same pairings for each leg.
 *
 * This strategy maintains identical schedules across all legs,
 * suitable for tournaments where the same encounters should
 * happen multiple times without variation.
 */
readonly class RepeatedLegStrategy implements LegStrategyInterface
{
    #[Override]
    public function planLegs(
        array $participants,
        int $legs,
        ConstraintSet $constraints
    ): LegPlanContribution {
        return new LegPlanContribution(
            rolesMirrorAcrossLegs: false,
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
            return null; // Repeated strategy only works with 2 participants
        }

        // For repeated strategy, always use the same order regardless of leg
        return new Event($participants, new Round($round));
    }
}
