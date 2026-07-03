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
 * Strategy that shuffles participant roles for each leg.
 *
 * This strategy randomizes the positional order of participants in each
 * pairing for every leg after the first, creating varied encounters
 * across legs while maintaining the same participant combinations.
 */
readonly class ShuffledLegStrategy implements LegStrategyInterface
{
    public function __construct(
        private ?Randomizer $randomizer = null
    ) {
    }

    #[Override]
    public function planLegs(
        array $participants,
        int $legs,
        ConstraintSet $constraints
    ): LegPlanContribution {
        return new LegPlanContribution(
            rolesMirrorAcrossLegs: false,
            requiresRandomization: true
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
            return null; // Shuffled strategy only works with 2 participants
        }

        $roundObject = new Round($round);

        if ($leg === 1) {
            // First leg: use original order
            return new Event($participants, $roundObject);
        }

        // Subsequent legs: randomly shuffle the pairing order
        $randomizer = $this->randomizer ?? new Randomizer();

        if ($randomizer->getInt(0, 1) === 1) {
            return new Event([
                $participants[1],
                $participants[0],
            ], $roundObject);
        }

        return new Event($participants, $roundObject);
    }
}
