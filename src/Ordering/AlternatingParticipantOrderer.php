<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Ordering;

use Override;

/**
 * Alternates participant order based on event index within the round.
 *
 * This orderer provides simple home/away alternation within a round:
 * - Even event indices (0, 2, 4...): original order (first participant is "home")
 * - Odd event indices (1, 3, 5...): reversed order (second participant is "home")
 *
 * This creates a balanced distribution where participants alternate home/away
 * positions in a deterministic pattern based on their event sequence.
 */
readonly class AlternatingParticipantOrderer implements ParticipantOrderer
{
    /**
     * Alternate participant order based on event index.
     */
    #[Override]
    public function order(array $participants, EventOrderingContext $context): array
    {
        $participants = array_values($participants);

        // Odd event indices: reverse the order
        if ($context->eventIndexInRound % 2 === 1) {
            return array_reverse($participants);
        }

        // Even event indices: keep original order
        return $participants;
    }
}
