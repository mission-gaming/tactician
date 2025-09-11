<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\LegStrategies;

use Override;

/**
 * Strategy that repeats the exact same pairings for each leg.
 *
 * This strategy maintains identical schedules across all legs,
 * suitable for tournaments where the same encounters should
 * happen multiple times without variation.
 */
final class RepeatedLegStrategy implements LegStrategyInterface
{
    #[Override]
    public function generateLegPairings(
        array $basePairings,
        int $legNumber,
        int $totalLegs,
        ?\Random\Randomizer $randomizer = null
    ): array {
        // For repeated strategy, just return the base pairings unchanged
        // regardless of leg number - each leg will have identical pairings
        return $basePairings;
    }
}
