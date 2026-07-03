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
 */
readonly class NoRepeatPairings implements ConstraintInterface
{
    public function __construct(private bool $acrossLegs = false)
    {
    }

    #[Override]
    public function isSatisfied(Event $event, SchedulingContext $context): bool
    {
        $participants = $event->getParticipants();
        $legEvents = $this->acrossLegs
            ? $context->getExistingEvents()
            : $context->getEventsForLeg($context->getCurrentLeg());

        // For events with more than 2 participants, check all pairs
        for ($i = 0; $i < count($participants) - 1; ++$i) {
            for ($j = $i + 1; $j < count($participants); ++$j) {
                if ($this->havePlayedWithin($legEvents, $participants[$i], $participants[$j])) {
                    return false;
                }
            }
        }

        return true;
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

    #[Override]
    public function getName(): string
    {
        return 'No Repeat Pairings';
    }
}
