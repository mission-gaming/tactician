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
 *
 * The lookups by participant, by pairing, by round and by leg read an index
 * of the event list, built on first use, so each costs the events it
 * returns and not a scan of the whole schedule. They return exactly what a
 * scan returns: the same events, under the keys they have in
 * getExistingEvents(), in that order.
 */
readonly class SchedulingContext
{
    /**
     * The lookup maps over $allEvents. A mutable object on purpose: the
     * maps fill in as they are asked for, while the event list they
     * describe never changes.
     */
    private EventIndex $index;

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
        $this->index = new EventIndex($allEvents);
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

        return $this->index->inRoundRange($firstRound, $lastRound);
    }

    /**
     * Get events from all legs for a specific participant.
     * @return array<Event>
     */
    public function getEventsForParticipant(Participant $participant): array
    {
        return $this->index->forParticipant($participant->getId());
    }

    /**
     * Get the events in which both participants take part, across all legs.
     *
     * The events keep the keys they have in getExistingEvents() and come in
     * that order, as getEventsForParticipant() returns them. Participants
     * are matched by id, as Event::hasParticipant() matches them, and the
     * order of the two does not matter. Given the same participant twice,
     * the result is that participant's events.
     *
     * This is the lookup for a constraint about a pairing's history (has
     * this pair met, when did it last meet): it reads the pair's own events
     * and not the whole schedule.
     *
     * @return array<Event>
     */
    public function getEventsBetween(Participant $participant1, Participant $participant2): array
    {
        return $this->index->between($participant1->getId(), $participant2->getId());
    }

    /**
     * Get events in a specific round across all legs.
     * @return array<Event>
     */
    public function getEventsInRound(int $round): array
    {
        return $this->index->inRound($round);
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

        return $this->index->between($participant1->getId(), $participant2->getId()) !== [];
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

        // An event that holds every given participant holds the first one,
        // so only that participant's events can match. With no participant
        // given (a context of zero participants per event) every event does.
        $firstParticipant = array_values($participants)[0] ?? null;
        $candidates = $firstParticipant === null
            ? $this->allEvents
            : $this->index->forParticipant($firstParticipant->getId());

        foreach ($candidates as $event) {
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
        $next = new self(
            $this->allParticipants,
            $this->plan,
            [...$this->allEvents, ...$newEvents],
            $this->currentLeg,
            $this->participantsPerEvent
        );
        // The new list is this one with events appended, so the maps built
        // here stay valid for it and only the new events are added.
        $next->index->adopt($this->index);

        return $next;
    }

    /**
     * Create a new context for the next leg.
     */
    public function withNextLeg(): self
    {
        $next = new self(
            $this->allParticipants,
            $this->plan,
            $this->allEvents,
            $this->currentLeg + 1,
            $this->participantsPerEvent
        );
        $next->index->adopt($this->index);

        return $next;
    }
}
