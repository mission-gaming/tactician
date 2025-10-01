<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;

/**
 * Context for tournament scheduling with inherent multi-leg awareness.
 *
 * This class provides complete tournament state including multi-leg support,
 * allowing constraints and schedulers to have full visibility into the
 * tournament structure during generation.
 */
readonly class SchedulingContext
{
    /**
     * @param array<Participant> $allParticipants All participants in the tournament
     * @param array<Event> $allEvents Events from all legs generated so far
     * @param int $currentLeg The current leg being generated (1-based)
     * @param int $totalLegs The total number of legs in the tournament
     * @param int $participantsPerEvent Number of participants per event (usually 2)
     * @param array<string, mixed> $metadata Additional context metadata
     */
    public function __construct(
        private array $allParticipants,
        private array $allEvents = [],
        private int $currentLeg = 1,
        private int $totalLegs = 1,
        private int $participantsPerEvent = 2,
        private array $metadata = []
    ) {
    }

    /**
     * @return array<Participant>
     */
    public function getParticipants(): array
    {
        return $this->allParticipants;
    }

    /**
     * @return array<Event>
     */
    public function getExistingEvents(): array
    {
        return $this->allEvents;
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
     * Get the current leg being generated (1-based).
     */
    public function getCurrentLeg(): int
    {
        return $this->currentLeg;
    }

    /**
     * Get the total number of legs in the tournament.
     */
    public function getTotalLegs(): int
    {
        return $this->totalLegs;
    }

    /**
     * Get the number of participants per event.
     */
    public function getParticipantsPerEvent(): int
    {
        return $this->participantsPerEvent;
    }

    /**
     * Check if this is a multi-leg tournament.
     */
    public function isMultiLeg(): bool
    {
        return $this->totalLegs > 1;
    }

    /**
     * Get events from a specific leg.
     * @return array<Event>
     * @throws \DivisionByZeroError
     */
    public function getEventsForLeg(int $leg): array
    {
        if ($leg < 1 || $leg > $this->totalLegs) {
            return [];
        }

        return array_filter(
            $this->allEvents,
            function (Event $event) use ($leg) {
                $round = $event->getRound();
                if ($round === null) {
                    return false;
                }

                // Calculate which leg this round belongs to
                $roundsPerLeg = $this->calculateRoundsPerLeg();
                if ($roundsPerLeg === 0) {
                    return false;
                }

                $eventLeg = (int) ceil($round->getNumber() / $roundsPerLeg);

                return $eventLeg === $leg;
            }
        );
    }

    /**
     * Get events from all legs for a specific participant.
     * @return array<Event>
     */
    public function getEventsForParticipant(Participant $participant): array
    {
        return array_filter(
            $this->allEvents,
            fn (Event $event) => $event->hasParticipant($participant)
        );
    }

    /**
     * Get events in a specific round across all legs.
     * @return array<Event>
     */
    public function getEventsInRound(int $round): array
    {
        return array_filter(
            $this->allEvents,
            function (Event $event) use ($round) {
                $eventRound = $event->getRound();

                return $eventRound !== null && $eventRound->getNumber() === $round;
            }
        );
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

        foreach ($this->allEvents as $event) {
            if ($event->hasParticipant($participant1) && $event->hasParticipant($participant2)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if there is an event between the specified participants.
     *
     * @param array<Participant> $participants
     */
    public function hasEventBetween(array $participants): bool
    {
        if (count($participants) !== $this->participantsPerEvent) {
            return false;
        }

        foreach ($this->allEvents as $event) {
            $eventParticipants = $event->getParticipants();

            // Check if all participants match (order doesn't matter)
            $match = true;
            foreach ($participants as $participant) {
                $found = false;
                foreach ($eventParticipants as $eventParticipant) {
                    if ($participant->getId() === $eventParticipant->getId()) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $match = false;
                    break;
                }
            }

            if ($match) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the current number of events.
     */
    public function getEventCount(): int
    {
        return count($this->allEvents);
    }

    /**
     * Get the expected total number of events for the complete tournament.
     */
    public function getExpectedEventCount(): int
    {
        $participantCount = count($this->allParticipants);

        if ($participantCount < $this->participantsPerEvent) {
            return 0;
        }

        // Round-robin formula: n * (n-1) / 2 for each leg, multiplied by number of legs
        $eventsPerLeg = (int) ($participantCount * ($participantCount - 1) / 2);

        return $eventsPerLeg * $this->totalLegs;
    }

    /**
     * Create a new context with additional events.
     * @param array<Event> $newEvents
     */
    public function withEvents(array $newEvents): self
    {
        return new self(
            $this->allParticipants,
            [...$this->allEvents, ...$newEvents],
            $this->currentLeg,
            $this->totalLegs,
            $this->participantsPerEvent,
            $this->metadata
        );
    }

    /**
     * Create a new context for the next leg.
     */
    public function withNextLeg(): self
    {
        return new self(
            $this->allParticipants,
            $this->allEvents,
            $this->currentLeg + 1,
            $this->totalLegs,
            $this->participantsPerEvent,
            $this->metadata
        );
    }

    /**
     * Calculate rounds per leg based on current events.
     *
     * @throws \DivisionByZeroError
     */
    private function calculateRoundsPerLeg(): int
    {
        if (empty($this->allEvents)) {
            return 0;
        }

        $roundNumbers = array_map(
            fn (Event $event) => $event->getRound()?->getNumber() ?? 0,
            $this->allEvents
        );

        $maxRound = max($roundNumbers);

        return (int) ($maxRound / $this->totalLegs);
    }
}
