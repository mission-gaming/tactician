<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Stage\StagePlan;

/**
 * Context for tournament scheduling across algorithms.
 *
 * This class provides generated tournament state plus the stage plan —
 * the algorithm's declaration of the stage's shape. Constraints and
 * schedulers reason about rounds, legs, and expected size by reading the
 * plan; the context never infers shape facts itself.
 */
readonly class SchedulingContext
{
    /**
     * @param array<Participant> $allParticipants All participants in the tournament
     * @param StagePlan $plan The shape declaration for the stage being generated
     * @param array<Event> $allEvents Events generated so far
     * @param int $currentLeg The current leg being generated (1-based), for algorithms with legs
     * @param int $participantsPerEvent Number of participants per event (usually 2)
     */
    public function __construct(
        private array $allParticipants,
        private StagePlan $plan,
        private array $allEvents = [],
        private int $currentLeg = 1,
        private int $participantsPerEvent = 2
    ) {
    }

    /**
     * Get the stage plan: the algorithm's declaration of rounds, legs, and
     * expected event counts for this stage.
     */
    public function getPlan(): StagePlan
    {
        return $this->plan;
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
     * Get the current leg being generated (1-based).
     */
    public function getCurrentLeg(): int
    {
        return $this->currentLeg;
    }

    /**
     * Get the total number of legs in the tournament.
     *
     * Generation always runs leg by leg, so formats without a legs concept
     * (where the plan reports null) run as a single generation leg.
     */
    public function getTotalLegs(): int
    {
        return $this->plan->getLegs() ?? 1;
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
        return $this->getTotalLegs() > 1;
    }

    /**
     * Get events from a specific leg.
     *
     * Algorithms without legs use the default single leg and return all events for leg 1.
     *
     * @return array<Event>
     */
    public function getEventsForLeg(int $leg): array
    {
        $totalLegs = $this->getTotalLegs();

        if ($leg < 1 || $leg > $totalLegs) {
            return [];
        }

        if ($totalLegs === 1) {
            return $leg === 1 ? $this->allEvents : [];
        }

        $roundsPerLeg = $this->plan->getRoundsPerLeg() ?? 0;
        if ($roundsPerLeg === 0) {
            return [];
        }

        $firstRound = (($leg - 1) * $roundsPerLeg) + 1;
        $lastRound = $leg * $roundsPerLeg;

        return array_filter(
            $this->allEvents,
            function (Event $event) use ($firstRound, $lastRound) {
                $round = $event->getRound();
                if ($round === null) {
                    return false;
                }

                $roundNumber = $round->getNumber();

                return $roundNumber >= $firstRound && $roundNumber <= $lastRound;
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
     * Create a new context with additional events.
     * @param array<Event> $newEvents
     */
    public function withEvents(array $newEvents): self
    {
        return new self(
            $this->allParticipants,
            $this->plan,
            [...$this->allEvents, ...$newEvents],
            $this->currentLeg,
            $this->participantsPerEvent
        );
    }

    /**
     * Create a new context for the next leg.
     */
    public function withNextLeg(): self
    {
        return new self(
            $this->allParticipants,
            $this->plan,
            $this->allEvents,
            $this->currentLeg + 1,
            $this->participantsPerEvent
        );
    }
}
