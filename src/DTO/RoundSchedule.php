<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\DTO;

/**
 * Represents the events scheduled for a single round.
 *
 * This class is used when generating schedules round-by-round
 * (e.g., in dynamic Swiss tournaments where pairings depend on
 * current standings).
 */
readonly class RoundSchedule
{
    /**
     * @param int $roundNumber The round number (1-based)
     * @param array<Event> $events The events scheduled for this round
     */
    public function __construct(
        private int $roundNumber,
        private array $events
    ) {
        if ($roundNumber < 1) {
            throw new \InvalidArgumentException('Round number must be at least 1');
        }
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

    /**
     * Get the number of events in this round.
     */
    public function getEventCount(): int
    {
        return count($this->events);
    }

    /**
     * Get all participants involved in this round.
     *
     * @return array<Participant>
     */
    public function getParticipants(): array
    {
        $participants = [];

        foreach ($this->events as $event) {
            foreach ($event->getParticipants() as $participant) {
                $id = $participant->getId();
                if (!isset($participants[$id])) {
                    $participants[$id] = $participant;
                }
            }
        }

        return array_values($participants);
    }

    /**
     * Check if a specific participant is involved in this round.
     */
    public function hasParticipant(Participant $participant): bool
    {
        foreach ($this->events as $event) {
            if ($event->hasParticipant($participant)) {
                return true;
            }
        }

        return false;
    }
}
