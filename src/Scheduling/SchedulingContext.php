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
 * plan. The context adds one reading of its own: a plan without legs (a
 * Swiss stage, a bracket) is treated as a single leg that holds every
 * event.
 *
 * The lookups by participant, by pairing, by round and by leg read an index
 * of the event list, built on first use, so each costs the events it
 * returns and not a scan of the whole schedule. They return exactly what a
 * scan returns: the same events, under the keys they have in
 * getExistingEvents(), in that order.
 *
 * @experimental
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
     * Nothing is validated: the context holds what it is given. The events
     * are not checked against the participants or the plan, and the current
     * leg is not checked against the plan's legs.
     *
     * @param array<Participant> $allParticipants All participants in the tournament
     * @param StagePlan $plan The shape declaration for the stage being generated
     * @param array<Event> $allEvents Events generated so far
     * @param int $currentLeg The current leg being generated (1-based), for algorithms with legs
     * @param int $participantsPerEvent Number of participants per event (usually 2); of the
     *                                  lookups here, only hasEventBetween() reads it
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
     * Every participant of the stage, as given to the constructor: the
     * whole field, not only those who have an event so far.
     *
     * @return array<Participant>
     */
    public function getParticipants(): array
    {
        return $this->allParticipants;
    }

    /**
     * The events generated so far, in the order they were generated. The
     * candidate event a constraint is being asked about is not among them.
     *
     * @return array<Event>
     */
    public function getExistingEvents(): array
    {
        return $this->allEvents;
    }

    /**
     * Get the current leg being generated (1-based). It is 1 throughout for
     * a format without legs.
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
     *
     * @deprecated since 0.2.2, removed in 1.0.0. It answers 1 for a format
     *             that has no legs (Swiss, elimination), which is not a
     *             fact about the stage. Read `getPlan()->getLegs()` instead:
     *             it is null where the concept does not apply.
     */
    #[\Deprecated(message: 'it answers 1 for a format without legs; read getPlan()->getLegs() instead, which is null there', since: '0.2.2')]
    public function getTotalLegs(): int
    {
        return $this->generationLegs();
    }

    /**
     * Get the number of participants per event the context was built for
     * (2 unless the constructor was told otherwise). It is not derived from
     * the events.
     */
    public function getParticipantsPerEvent(): int
    {
        return $this->participantsPerEvent;
    }

    /**
     * Whether the plan has more than one leg. False for a format without
     * legs (Swiss, elimination), whose plan reports null.
     */
    public function isMultiLeg(): bool
    {
        return $this->generationLegs() > 1;
    }

    /**
     * Get the events of one leg (1-based), under the keys they have in
     * getExistingEvents() and in that order.
     *
     * With more than one leg, an event belongs to the leg its round number
     * falls in (leg 1 is rounds 1 to the plan's rounds per leg, and so on),
     * and an event without a round belongs to none. A plan with one leg, or
     * a format without legs, returns every event for leg 1, whatever its
     * round. A leg the plan does not have returns an empty array.
     *
     * @return array<Event>
     */
    public function getEventsForLeg(int $leg): array
    {
        $totalLegs = $this->generationLegs();

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
     * Get the events the participant takes part in, across all legs, under
     * the keys they have in getExistingEvents() and in that order. The
     * participant is matched by ID.
     *
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
     * Get the events whose round has this number, under the keys they have
     * in getExistingEvents() and in that order. Round numbers are 1-based
     * and continuous across legs, so a round belongs to one leg; an event
     * without a round is in no round.
     *
     * @return array<Event>
     */
    public function getEventsInRound(int $round): array
    {
        return $this->index->inRound($round);
    }

    /**
     * Whether an event among those generated so far holds both
     * participants, matched by ID, in either order and in any leg. False
     * for a participant and itself.
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
     * Whether an event among those generated so far holds every one of the
     * given participants, matched by ID, in any order.
     *
     * False when the number of participants given is not the context's
     * participants per event, whatever the events hold. The same
     * participant given twice counts as given: with two per event, a
     * participant and itself is true as soon as it has an event, where
     * haveParticipantsPlayed() says false.
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
     * How many events have been generated so far, in all legs.
     */
    public function getEventCount(): int
    {
        return count($this->allEvents);
    }

    /**
     * A new context whose events are this one's followed by the given
     * ones, with the same participants, plan and current leg. This context
     * is not changed.
     *
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
     * A new context with the same participants, plan and events and the
     * current leg one higher. This context is not changed. The leg is not
     * checked against the plan: the result can name a leg the plan does not
     * have.
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

    /**
     * The number of legs generation runs through: the plan's, or one for a
     * format without legs, whose events all belong to that single pass.
     */
    private function generationLegs(): int
    {
        return $this->plan->getLegs() ?? 1;
    }
}
