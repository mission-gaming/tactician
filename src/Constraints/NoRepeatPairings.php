<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Constraints;

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\SchedulingContext;
use Override;

/**
 * Prevents the same pairing from occurring more than once within a leg.
 *
 * Multi-leg tournaments intentionally repeat every pairing once per leg, so
 * by default only the current leg's events are checked; single-leg schedules
 * are checked in full. Pass acrossLegs: true to forbid repeats anywhere in
 * the tournament (which makes complete multi-leg round robins impossible by
 * design).
 *
 * @experimental
 */
readonly class NoRepeatPairings implements ConstraintInterface
{
    /**
     * @param bool $acrossLegs False (the default) forbids a pairing twice within one leg; true
     *                         forbids it twice anywhere in the context
     */
    public function __construct(private bool $acrossLegs = false) {}

    /**
     * False when two participants of the event already share an event of
     * the context: one of the current leg by default, any one with
     * acrossLegs. Participants are matched by ID, and the roles of the two
     * do not matter.
     *
     * A stage whose plan has no legs (Swiss) is one leg, so all its events
     * are read either way. An event of more than two participants is
     * rejected when any pair of them has met.
     */
    #[Override]
    public function isSatisfied(Event $event, SchedulingContext $context): bool
    {
        $participants = $event->getParticipants();
        $legEvents = $this->acrossLegs
            ? $context->getExistingEvents()
            : $context->getEventsForLeg($context->getCurrentLeg());

        // A context of exactly this class answers "which events hold both"
        // from its index, so the check reads the pair's own events. A
        // subclass may answer getExistingEvents() or getEventsForLeg() its
        // own way, so for one the events it returned are scanned as before.
        $indexed = $context::class === SchedulingContext::class;

        // For events with more than 2 participants, check all pairs
        for ($i = 0; $i < count($participants) - 1; ++$i) {
            for ($j = $i + 1; $j < count($participants); ++$j) {
                $played = $indexed
                    ? $this->havePlayedWithinIndexed($context, $legEvents, $participants[$i], $participants[$j])
                    : $this->havePlayedWithin($legEvents, $participants[$i], $participants[$j]);
                if ($played) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * havePlayedWithin() for the events of the context itself, or of one of
     * its legs: the pair's events come from the context's index, and one of
     * them is among $events when its key is, because both are the context's
     * events under the keys of its event list.
     *
     * @param array<Event> $events The context's events, or those of one leg
     */
    private function havePlayedWithinIndexed(
        SchedulingContext $context,
        array $events,
        Participant $first,
        Participant $second
    ): bool {
        if ($first->getId() === $second->getId()) {
            return false;
        }

        foreach ($context->getEventsBetween($first, $second) as $key => $event) {
            if (isset($events[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<Event> $events
     */
    private function havePlayedWithin(array $events, Participant $first, Participant $second): bool
    {
        if ($first->getId() === $second->getId()) {
            return false;
        }

        foreach ($events as $event) {
            if ($event->hasParticipant($first) && $event->hasParticipant($second)) {
                return true;
            }
        }

        return false;
    }

    /**
     * `No Repeat Pairings`, whatever the acrossLegs setting.
     */
    #[Override]
    public function getName(): string
    {
        return 'No Repeat Pairings';
    }
}
