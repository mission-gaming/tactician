<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\LegStrategies;

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Scheduling\SchedulingContext;
use Override;
use Random\Randomizer;

/**
 * Strategy that shuffles participant pairings for each leg.
 *
 * This strategy randomizes the order of participants in each pairing
 * for every leg, creating varied encounters across legs while maintaining
 * the same participant combinations.
 */
readonly class ShuffledLegStrategy implements LegStrategyInterface
{
    public function __construct(
        private ?Randomizer $randomizer = null
    ) {
    }

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
            true, // Requires randomization
            ['strategy' => 'shuffled'], // Strategy data
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
            return null; // Shuffled strategy only works with 2 participants
        }

        $roundObject = new Round($round);

        if ($leg === 1) {
            // First leg: use original order
            return new Event($participants, $roundObject);
        } else {
            // Subsequent legs: randomly shuffle the pairing order
            $randomizer = $this->randomizer ?? new Randomizer();

            if ($randomizer->getInt(0, 1) === 1) {
                // Reverse the order
                return new Event([
                    $participants[1],
                    $participants[0],
                ], $roundObject);
            } else {
                // Keep original order
                return new Event($participants, $roundObject);
            }
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
        $canSatisfy = true;
        $reasons = [];

        if ($participantsPerEvent !== 2) {
            $canSatisfy = false;
            $reasons[] = 'Shuffled strategy only supports 2 participants per event';
        }

        if (count($participants) < 2) {
            $canSatisfy = false;
            $reasons[] = 'Shuffled strategy requires at least 2 participants';
        }

        return new ConstraintSatisfiabilityReport(
            $canSatisfy,
            $reasons,
            [] // No suggested modifications
        );
    }
}
