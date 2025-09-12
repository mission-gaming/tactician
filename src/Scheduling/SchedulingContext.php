<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;

readonly class SchedulingContext
{
    /**
     * @param array<Participant> $participants
     * @param array<Event> $existingEvents
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private array $participants,
        private array $existingEvents = [],
        private array $metadata = []
    ) {
    }

    /**
     * @return array<Participant>
     */
    public function getParticipants(): array
    {
        return $this->participants;
    }

    /**
     * @return array<Event>
     */
    public function getExistingEvents(): array
    {
        return $this->existingEvents;
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
     * Check if two participants have already played against each other.
     */
    public function haveParticipantsPlayed(Participant $participant1, Participant $participant2): bool
    {
        // A participant cannot play against themselves
        if ($participant1->getId() === $participant2->getId()) {
            return false;
        }

        foreach ($this->existingEvents as $event) {
            if ($event->hasParticipant($participant1) && $event->hasParticipant($participant2)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get all events involving a specific participant.
     * @return array<Event>
     */
    public function getEventsForParticipant(Participant $participant): array
    {
        return array_filter(
            $this->existingEvents,
            fn (Event $event) => $event->hasParticipant($participant)
        );
    }

    /**
     * Create a new context with additional events.
     * @param array<Event> $newEvents
     */
    public function withEvents(array $newEvents): self
    {
        return new self(
            $this->participants,
            [...$this->existingEvents, ...$newEvents],
            $this->metadata
        );
    }
}
