<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Positioning;

use MissionGaming\Tactician\DTO\Schedule;

/**
 * Represents the complete structural "blueprint" of a tournament.
 *
 * A positional schedule defines the tournament structure in terms of
 * positions rather than actual participants. This enables:
 *
 * 1. Tournament projection - inspect structure before play begins
 * 2. Dynamic scheduling - resolve positions as standings change
 * 3. Unified API - same structure concept across all algorithms
 *
 * Examples:
 * - Round-robin: "Round 1: Seed 1 vs Seed 2, Seed 3 vs Seed 4..."
 * - Swiss: "Round 2: Standing 1 vs Standing 2, Standing 3 vs Standing 4..."
 * - Knockout: "Semi-final 1: Winner QF1 vs Winner QF2..."
 */
readonly class PositionalSchedule
{
    /**
     * @param array<PositionalRound> $rounds The rounds in this tournament
     * @param array<string, mixed> $metadata Additional tournament metadata
     */
    public function __construct(
        private array $rounds,
        private array $metadata = []
    ) {
    }

    /**
     * @return array<PositionalRound>
     */
    public function getRounds(): array
    {
        return $this->rounds;
    }

    /**
     * Get a specific round by number.
     */
    public function getRound(int $roundNumber): ?PositionalRound
    {
        foreach ($this->rounds as $round) {
            if ($round->getRoundNumber() === $roundNumber) {
                return $round;
            }
        }

        return null;
    }

    /**
     * Get the total number of rounds in this tournament.
     */
    public function getRoundCount(): int
    {
        return count($this->rounds);
    }

    /**
     * Get the total number of pairings across all rounds.
     */
    public function getTotalPairingCount(): int
    {
        $total = 0;
        foreach ($this->rounds as $round) {
            $total += $round->getPairingCount();
        }

        return $total;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function hasMetadata(string $key): bool
    {
        return array_key_exists($key, $this->metadata);
    }

    public function getMetadataValue(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * Resolve all positional pairings to actual participants, creating a complete schedule.
     *
     * @param PositionResolver $resolver The resolver to use for position lookup
     * @return Schedule The fully resolved schedule with actual participants
     */
    public function resolve(PositionResolver $resolver): Schedule
    {
        $allEvents = [];

        foreach ($this->rounds as $positionalRound) {
            $resolvedEvents = $positionalRound->resolve($resolver);
            $allEvents = [...$allEvents, ...$resolvedEvents];
        }

        return new Schedule($allEvents, [
            ...$this->metadata,
            'fully_resolved' => true,
            'positional_structure' => $this,
        ]);
    }

    /**
     * Check if this entire schedule can be fully resolved with the given resolver.
     */
    public function canFullyResolve(PositionResolver $resolver): bool
    {
        foreach ($this->rounds as $round) {
            if (!$round->canFullyResolve($resolver)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if all positions in this schedule are statically resolvable (seed-based).
     * Returns true for round-robin and pre-determined Swiss.
     * Returns false for standings-based Swiss.
     */
    public function isFullyPredetermined(): bool
    {
        foreach ($this->rounds as $round) {
            foreach ($round->getPairings() as $pairing) {
                if (!$pairing->getPosition1()->isStaticallyResolvable()
                    || !$pairing->getPosition2()->isStaticallyResolvable()) {
                    return false;
                }
            }
        }

        return true;
    }
}
