<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\DTO;

use InvalidArgumentException;

/**
 * Represents a single round in a tournament schedule.
 *
 * A Round encapsulates the logical concept of a tournament round with
 * custom metadata. Rounds are immutable value objects that can be
 * compared and provide utility methods for schedule management.
 */
readonly class Round
{
    /**
     * Create a new Round with the specified parameters.
     *
     * @param int $number The round number (must be positive)
     * @param array<string, mixed> $metadata Additional custom data for this round
     *
     * @throws InvalidArgumentException When round number is not positive
     */
    public function __construct(
        private int $number,
        private array $metadata = []
    ) {
        if ($number <= 0) {
            throw new InvalidArgumentException('Round number must be positive');
        }
    }

    /**
     * Get the round number.
     *
     * @return int The round number (always positive)
     */
    public function getNumber(): int
    {
        return $this->number;
    }

    /**
     * Get all metadata associated with this round.
     *
     * @return array<string, mixed> All metadata key-value pairs
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Check if a specific metadata key exists for this round.
     *
     * @param string $key The metadata key to check for
     * @return bool True if the key exists, false otherwise
     */
    public function hasMetadata(string $key): bool
    {
        return array_key_exists($key, $this->metadata);
    }

    /**
     * Get the value for a specific metadata key.
     *
     * @param string $key The metadata key to retrieve
     * @param mixed $default The default value to return if the key doesn't exist
     * @return mixed The metadata value or the default if key not found
     */
    public function getMetadataValue(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->metadata) ? $this->metadata[$key] : $default;
    }

    /**
     * Check if this round is equal to another round (based on round number).
     *
     * @param Round $other The round to compare with
     * @return bool True if the round numbers match, false otherwise
     */
    public function equals(Round $other): bool
    {
        return $this->number === $other->number;
    }

    /**
     * Check if this round comes before another round.
     *
     * @param Round $other The round to compare with
     * @return bool True if this round's number is less than the other's
     */
    public function isBefore(Round $other): bool
    {
        return $this->number < $other->number;
    }

    /**
     * Check if this round comes after another round.
     *
     * @param Round $other The round to compare with
     * @return bool True if this round's number is greater than the other's
     */
    public function isAfter(Round $other): bool
    {
        return $this->number > $other->number;
    }

    /**
     * Get a string representation of this round.
     *
     * @return string Human-readable representation of the round
     */
    #[\Override]
    public function __toString(): string
    {
        return "Round {$this->number}";
    }
}
