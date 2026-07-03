<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;

/**
 * The pairings produced for a single Swiss round.
 */
readonly class SwissRoundPairing
{
    /**
     * @param array<Event> $events The paired events for this round
     * @param Participant|null $bye The participant sitting out, if any
     */
    public function __construct(
        private int $roundNumber,
        private array $events,
        private ?Participant $bye = null
    ) {
    }

    public function getRoundNumber(): int
    {
        return $this->roundNumber;
    }

    /**
     * @return array<Event>
     */
    public function getEvents(): array
    {
        return $this->events;
    }

    public function getBye(): ?Participant
    {
        return $this->bye;
    }

    public function hasBye(): bool
    {
        return $this->bye !== null;
    }
}
