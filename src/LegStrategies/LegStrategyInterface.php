<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\LegStrategies;

use Random\Randomizer;

/**
 * Interface for strategies that determine how additional legs are generated
 * in multi-leg tournaments.
 *
 * Leg strategies receive participant pairings from the base schedule and
 * transform them for subsequent legs (e.g., reversing for home/away effect,
 * reshuffling for variety, or repeating identically).
 */
interface LegStrategyInterface
{
    /**
     * Generate participant pairings for a specific leg.
     *
     * @param array<array{0: \MissionGaming\Tactician\DTO\Participant, 1: \MissionGaming\Tactician\DTO\Participant}> $basePairings
     *        The participant pairings from the first leg
     * @param int $legNumber The leg number being generated (2, 3, etc.)
     * @param int $totalLegs The total number of legs in the tournament
     * @param Randomizer|null $randomizer Optional randomizer for strategies that need randomization
     *
     * @return array<array{0: \MissionGaming\Tactician\DTO\Participant, 1: \MissionGaming\Tactician\DTO\Participant}>
     *         The transformed pairings for this leg
     */
    public function generateLegPairings(
        array $basePairings,
        int $legNumber,
        int $totalLegs,
        ?Randomizer $randomizer = null
    ): array;
}
