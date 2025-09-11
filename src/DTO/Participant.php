<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\DTO;

/**
 * Represents a participant in a tournament or competition.
 *
 * A Participant contains identifying information, display labels, optional seeding
 * for ranking/bracket purposes, and custom metadata. Participants are immutable
 * and identified by their unique ID.
 */
readonly class Participant
{
    /**
     * Create a new Participant with the specified details.
     *
     * @param string $id Unique identifier for this participant
     * @param string $label Display name or label for this participant
     * @param int|null $seed Optional seeding/ranking number for tournament brackets
     * @param array<string, mixed> $metadata Additional custom data for this participant
     */
    public function __construct(
        private string $id,
        private string $label,
        private ?int $seed = null,
        private array $metadata = []
    ) {
    }

    /**
     * Get the unique identifier for this participant.
     *
     * @return string The participant's unique ID
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Get the display label for this participant.
     *
     * @return string The participant's display name or label
     */
    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * Get the seeding/ranking number for this participant.
     *
     * @return int|null The participant's seed number, or null if not seeded
     */
    public function getSeed(): ?int
    {
        return $this->seed;
    }

    /**
     * Get all metadata associated with this participant.
     *
     * @return array<string, mixed> All metadata key-value pairs
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Check if a specific metadata key exists for this participant.
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
        return $this->metadata[$key] ?? $default;
    }
}
