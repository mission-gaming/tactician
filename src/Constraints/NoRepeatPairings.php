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
final readonly class NoRepeatPairings implements ConstraintInterface
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

        // The context answers "which events hold both" from its index, so
        // the check reads the pair's own events. For events with more than
        // 2 participants, check all pairs.
        for ($i = 0; $i < count($participants) - 1; ++$i) {
            for ($j = $i + 1; $j < count($participants); ++$j) {
                if ($this->havePlayedWithin($context, $legEvents, $participants[$i], $participants[$j])) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Whether the two have met in the events of the context itself, or of
     * one of its legs: the pair's events come from the context's index, and
     * one of them is among $events when its key is, because both are the
     * context's events under the keys of its event list.
     *
     * @param array<Event> $events The context's events, or those of one leg
     */
    private function havePlayedWithin(
        SchedulingContext $context,
        array $events,
        Participant $first,
        Participant $second
    ): bool {
        if ($first->getId() === $second->getId()) {
            return false;
        }

        return array_any($context->getEventsBetween($first, $second), fn($event, $key) => isset($events[$key]));
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
