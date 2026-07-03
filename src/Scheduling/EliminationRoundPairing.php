<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;

/**
 * The pairings produced for a single elimination round.
 */
readonly class EliminationRoundPairing
{
    /**
     * @param string $stage Human-readable stage name (e.g. 'quarterfinal', 'semifinal', 'final')
     * @param array<Event> $events The playable pairs for this round, in bracket order
     * @param array<Participant> $byes Participants advancing without playing
     */
    public function __construct(
        private int $roundNumber,
        private string $stage,
        private array $events,
        private array $byes = []
    ) {
    }

    public function getRoundNumber(): int
    {
        return $this->roundNumber;
    }

    public function getStage(): string
    {
        return $this->stage;
    }

    /**
     * @return array<Event>
     */
    public function getEvents(): array
    {
        return $this->events;
    }

    /**
     * @return array<Participant>
     */
    public function getByes(): array
    {
        return $this->byes;
    }

    public function hasByes(): bool
    {
        return $this->byes !== [];
    }
}
