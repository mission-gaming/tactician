<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\DTO;

readonly class Event
{
    /**
     * @param array<Participant> $participants
     */
    public function __construct(
        private array $participants,
        private ?int $round = null,
        private array $metadata = []
    ) {
        if (count($participants) < 2) {
            throw new \InvalidArgumentException('An event must have at least 2 participants');
        }
    }

    /**
     * @return array<Participant>
     */
    public function getParticipants(): array
    {
        return $this->participants;
    }

    public function hasParticipant(Participant $participant): bool
    {
        foreach ($this->participants as $eventParticipant) {
            if ($eventParticipant->getId() === $participant->getId()) {
                return true;
            }
        }

        return false;
    }

    public function getRound(): ?int
    {
        return $this->round;
    }

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

    public function getParticipantCount(): int
    {
        return count($this->participants);
    }
}
