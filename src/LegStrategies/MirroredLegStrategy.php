<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\LegStrategies;

use MissionGaming\Tactician\DTO\Participant;
use Override;
use Random\Randomizer;

/**
 * Leg strategy that mirrors participant order for home/away effect.
 *
 * In the first leg, participants appear as [Home, Away]. In subsequent legs,
 * the order is reversed to [Away, Home], simulating home and away fixtures
 * common in sports tournaments.
 */
readonly class MirroredLegStrategy implements LegStrategyInterface
{
    /**
     * Generate mirrored pairings where participant order is reversed.
     *
     * @param array<array{0: Participant, 1: Participant}> $basePairings
     * @param int $legNumber
     * @param int $totalLegs
     * @param Randomizer|null $randomizer
     *
     * @return array<array{0: Participant, 1: Participant}>
     */
    #[Override]
    public function generateLegPairings(
        array $basePairings,
        int $legNumber,
        int $totalLegs,
        ?Randomizer $randomizer = null
    ): array {
        return array_map(
            fn (array $pairing) => [$pairing[1], $pairing[0]],
            $basePairings
        );
    }
}
