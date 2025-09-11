<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\DTO;

/**
 * Represents a single event/match in a tournament schedule.
 *
 * An Event contains a group of participants who will compete against each other,
 * along with optional round information and custom metadata. Events are immutable
 * and must contain at least 2 participants.
 */
readonly class Event
{
    /**
     * Create a new Event with the specified participants.
     *
     * @param array<Participant> $participants The participants competing in this event (minimum 2)
     * @param Round|null $round The round this event belongs to (optional)
     * @param array<string, mixed> $metadata Additional custom data for this event
     *
     * @throws \InvalidArgumentException When fewer than 2 participants are provided
     */
    public function __construct(
        private array $participants,
        private ?Round $round = null,
        private array $metadata = []
    ) {
        if (count($participants) < 2) {
            throw new \InvalidArgumentException('An event must have at least 2 participants');
        }
    }

    /**
     * Get all participants in this event.
     *
     * @return array<Participant> The participants competing in this event
     */
    public function getParticipants(): array
    {
        return $this->participants;
    }

    /**
     * Check if a specific participant is competing in this event.
     *
     * @param Participant $participant The participant to check for
     * @return bool True if the participant is in this event, false otherwise
     */
    public function hasParticipant(Participant $participant): bool
    {
        foreach ($this->participants as $eventParticipant) {
            if ($eventParticipant->getId() === $participant->getId()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the round this event belongs to.
     *
     * @return Round|null The round, or null if not assigned to a round
     */
    public function getRound(): ?Round
    {
        return $this->round;
    }

    /**
     * Get all metadata associated with this event.
     *
     * @return array<string, mixed> All metadata key-value pairs
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Check if a specific metadata key exists for this event.
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

    /**
     * Get the number of participants in this event.
     *
     * @return int The total number of participants
     */
    public function getParticipantCount(): int
    {
        return count($this->participants);
    }
}
