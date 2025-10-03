<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Positioning;

use MissionGaming\Tactician\DTO\Participant;

/**
 * Resolves position references to actual participants.
 *
 * Position resolvers convert abstract position references (like "Seed 1" or
 * "Standing 3") into concrete Participant objects based on tournament context.
 *
 * Different resolvers handle different position types:
 * - SeedBasedPositionResolver: Resolves SEED positions
 * - StandingsBasedPositionResolver: Resolves STANDING positions
 */
interface PositionResolver
{
    /**
     * Resolve a position to an actual participant.
     *
     * @param Position $position The position to resolve
     * @return Participant|null The resolved participant, or null if position cannot be resolved
     */
    public function resolve(Position $position): ?Participant;

    /**
     * Check if this resolver can resolve the given position type.
     *
     * @param Position $position The position to check
     * @return bool True if this resolver can resolve the position
     */
    public function canResolve(Position $position): bool;
}
