<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Ordering;

use Override;

/**
 * Maintains the original array order of participants.
 *
 * This is the default orderer that preserves backward compatibility.
 * The first participant in the array will always be "home" (or first position),
 * the second will always be "away" (or second position).
 *
 * This orderer makes no changes to participant order, providing deterministic
 * results based purely on the input array order.
 */
readonly class StaticParticipantOrderer implements ParticipantOrderer
{
    /**
     * Return participants in their original order.
     */
    #[Override]
    public function order(array $participants, EventOrderingContext $context): array
    {
        return array_values($participants);
    }
}
