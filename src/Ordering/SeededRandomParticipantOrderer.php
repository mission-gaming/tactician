<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Ordering;

use Override;

/**
 * Provides deterministic randomization of participant order per event.
 *
 * This orderer uses deterministic hashing to randomly determine participant order
 * based on the event context:
 * - Round number
 * - Event index within round
 * - Leg number (if applicable)
 *
 * The same inputs will always produce the same ordering, making this suitable
 * for reproducible tournament generation with varied participant positions.
 *
 * Each event gets its own random decision (via CRC32 hash), creating natural
 * variation while maintaining full reproducibility.
 */
readonly class SeededRandomParticipantOrderer implements ParticipantOrderer
{
    /**
     * Create a new seeded random participant orderer.
     */
    public function __construct()
    {
    }

    /**
     * Randomly order participants based on event-specific seed.
     */
    #[Override]
    public function order(array $participants, EventOrderingContext $context): array
    {
        $participants = array_values($participants);

        // Create deterministic seed from event context
        $seed = $this->createSeed($context);

        // Use seed to make deterministic random decision
        if ($this->shouldReverse($seed)) {
            return array_reverse($participants);
        }

        return $participants;
    }

    /**
     * Create a deterministic seed from event context.
     */
    private function createSeed(EventOrderingContext $context): int
    {
        // Combine round, event index, and leg into unique seed
        $legComponent = $context->leg ?? 0;

        return ($context->roundNumber * 10000)
            + ($context->eventIndexInRound * 100)
            + $legComponent;
    }

    /**
     * Make deterministic random decision based on seed.
     */
    private function shouldReverse(int $seed): bool
    {
        // Use a simple hash of the seed to get consistent random decision
        // This ensures the same seed always produces the same result
        $hash = crc32((string) $seed);

        return ($hash % 2) === 1;
    }
}
