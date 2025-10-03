<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Positioning;

use MissionGaming\Tactician\DTO\Participant;

/**
 * Resolves positions based on static seeding order.
 *
 * This resolver maps SEED positions to participants based on their
 * order in the participants array. The first participant is Seed 1,
 * the second is Seed 2, etc.
 *
 * Used for:
 * - Round-robin tournaments
 * - Pre-determined Swiss tournaments (UEFA CL style)
 * - Any tournament where pairings are based on initial seeding
 */
readonly class SeedBasedPositionResolver implements PositionResolver
{
    /**
     * @param array<Participant> $participants Participants in seed order (Seed 1 = index 0)
     */
    public function __construct(private array $participants)
    {
    }

    public function resolve(Position $position): ?Participant
    {
        if (!$this->canResolve($position)) {
            return null;
        }

        // Convert 1-based position to 0-based array index
        $index = $position->getValue() - 1;

        return $this->participants[$index] ?? null;
    }

    public function canResolve(Position $position): bool
    {
        return $position->getType() === PositionType::SEED;
    }
}
