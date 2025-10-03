<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Positioning;

use MissionGaming\Tactician\DTO\Participant;

/**
 * Represents a pairing between two positions in a tournament.
 *
 * A positional pairing defines that two positions will compete against
 * each other, without specifying which actual participants occupy those
 * positions. This separation allows tournament structures to be defined
 * independently of participant assignment.
 *
 * Examples:
 * - "Seed 1 vs Seed 8" (round-robin)
 * - "Standing 1 vs Standing 2" (dynamic Swiss)
 * - "Winner of Group A vs Runner-up of Group B" (knockout)
 */
readonly class PositionalPairing
{
    public function __construct(
        private Position $position1,
        private Position $position2
    ) {
    }

    public function getPosition1(): Position
    {
        return $this->position1;
    }

    public function getPosition2(): Position
    {
        return $this->position2;
    }

    /**
     * Resolve this positional pairing to actual participants.
     *
     * @param PositionResolver $resolver The resolver to use for position lookup
     * @return array<Participant>|null Array of two participants, or null if positions cannot be resolved
     */
    public function resolve(PositionResolver $resolver): ?array
    {
        $participant1 = $resolver->resolve($this->position1);
        $participant2 = $resolver->resolve($this->position2);

        if ($participant1 === null || $participant2 === null) {
            return null;
        }

        return [$participant1, $participant2];
    }

    /**
     * Check if this pairing can be resolved with the given resolver.
     */
    public function canResolve(PositionResolver $resolver): bool
    {
        return $resolver->canResolve($this->position1)
            && $resolver->canResolve($this->position2);
    }

    /**
     * Get a human-readable string representation of this pairing.
     */
    public function __toString(): string
    {
        return "{$this->position1} vs {$this->position2}";
    }
}
