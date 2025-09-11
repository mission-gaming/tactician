<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\LegStrategies;

use Override;

/**
 * Strategy that shuffles participant pairings for each leg.
 *
 * This strategy randomizes the order of participants in each pairing
 * for every leg, creating varied encounters across legs while maintaining
 * the same participant combinations.
 */
final class ShuffledLegStrategy implements LegStrategyInterface
{
    #[Override]
    public function generateLegPairings(
        array $basePairings,
        int $legNumber,
        int $totalLegs,
        ?\Random\Randomizer $randomizer = null
    ): array {
        // For the first leg, return base pairings unchanged
        if ($legNumber === 1) {
            return $basePairings;
        }

        // For subsequent legs, shuffle each pairing
        $randomizer ??= new \Random\Randomizer();

        return array_map(
            fn (array $pairing): array => $randomizer->getInt(0, 1) === 1 ?
                [$pairing[1], $pairing[0]] : $pairing,
            $basePairings
        );
    }
}
