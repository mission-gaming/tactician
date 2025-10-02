<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\LegStrategies;

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Scheduling\SchedulingContext;
use Override;

/**
 * Leg strategy that mirrors participant order for home/away effect.
 *
 * In the first leg, participants appear as [Home, Away]. In subsequent legs,
 * the order is reversed to [Away, Home], simulating home and away fixtures
 * common in sports tournaments.
 */
readonly class MirroredLegStrategy implements LegStrategy
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

        // For mirrored strategy, we simply reverse the participant order in each leg
        $legPlans = [];
        for ($leg = 1; $leg <= $totalLegs; ++$leg) {
            $legPlans[$leg] = [
                'strategy' => 'mirrored',
                'reverse_order' => $leg > 1, // Reverse order for legs 2+
            ];
        }

        return new GenerationPlan(
            $totalEvents,
            $eventsPerLeg,
            count($participants) - 1, // rounds per leg
            false, // No randomization required
            ['leg_plans' => $legPlans], // Strategy data
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
            return null; // Mirrored strategy only works with 2 participants
        }

        $roundObject = new Round($round);

        if ($leg === 1) {
            // First leg: use original order
            return new Event($participants, $roundObject);
        } else {
            // Subsequent legs: reverse the order for home/away effect
            return new Event([
                $participants[1], // Away becomes Home
                $participants[0],  // Home becomes Away
            ], $roundObject);
        }
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
        // Mirrored strategy is compatible with most constraints
        // The main consideration is that it creates exactly the same number of events per leg
        $canSatisfy = true;
        $reasons = [];

        if ($participantsPerEvent !== 2) {
            $canSatisfy = false;
            $reasons[] = 'Mirrored strategy only supports 2 participants per event';
        }

        if (count($participants) < 2) {
            $canSatisfy = false;
            $reasons[] = 'Mirrored strategy requires at least 2 participants';
        }

        return new ConstraintSatisfiabilityReport(
            $canSatisfy,
            $reasons,
            [] // No suggested modifications
        );
    }
}
