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
    /**
     * Plan the generation strategy for a multi-leg tournament.
     */
    #[Override]
    public function planGeneration(
        array $participants,
        int $totalLegs,
        int $participantsPerEvent,
        ConstraintSet $constraints
    ): GenerationPlan {
        $eventsPerLeg = (int) (count($participants) * (count($participants) - 1) / 2);
        $totalEvents = $eventsPerLeg * $totalLegs;

        return new GenerationPlan(
            $totalEvents,
            $eventsPerLeg,
            count($participants) - 1, // rounds per leg
            false, // No randomization required
            ['strategy' => 'repeated'], // Strategy data
            [] // No warnings
        );
    }

    /**
     * Generate a specific event for a given leg and round.
     */
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

        $roundObject = new Round($round);

        // For repeated strategy, always use the same order regardless of leg
        return new Event($participants, $roundObject);
    }

    /**
     * Check if the strategy can satisfy the given constraints.
     */
    #[Override]
    public function canSatisfyConstraints(
        array $participants,
        int $legs,
        int $participantsPerEvent,
        ConstraintSet $constraints
    ): ConstraintSatisfiabilityReport {
        $canSatisfy = true;
        $reasons = [];

        if ($participantsPerEvent !== 2) {
            $canSatisfy = false;
            $reasons[] = 'Repeated strategy only supports 2 participants per event';
        }

        if (count($participants) < 2) {
            $canSatisfy = false;
            $reasons[] = 'Repeated strategy requires at least 2 participants';
        }

        return new ConstraintSatisfiabilityReport(
            $canSatisfy,
            $reasons,
            [] // No suggested modifications
        );
    }
}
