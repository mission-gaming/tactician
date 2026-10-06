<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Repack;

use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Exceptions\PinConflictException;

/**
 * Everything a repack needs: the movable events, the pinned events, the
 * grid, and the options.
 *
 * Validation here distinguishes broken input from an unsatisfiable
 * instance. An instance that cannot be packed comes back as an outcome
 * with violations; input that contradicts itself — duplicate event ids,
 * pins off the grid, pins overflowing a slot's capacity, a participant
 * pinned twice at one position — throws, because no honest outcome can be
 * built over corrupt history.
 *
 * Each of those throws an InvalidConfigurationException whose reason
 * (`getReason()`) says which it was. The message is the same sentence for
 * every instance of a mistake and names no event; the ids are in the
 * exception's context and its diagnostic report. A participant pinned twice
 * at one position throws the PinConflictException subclass, whose
 * `getEventIds()` returns the two pinned events that collide.
 *
 * The request is also where the objective weights meet the grid. The
 * repacker scores a move as an integer no larger in magnitude than
 * earlyFillWeight x (sessions - 1) + 2 x consolidationWeight. Weights for
 * which that exceeds PHP_INT_MAX on this grid are rejected (reason
 * IncompatibleOptions): PHP would compute the score as a float, where one
 * weight can swallow the other, and the result would no longer be the
 * trade the weights state.
 *
 * @api
 */
final readonly class RepackRequest
{
    /** @var array<MovableEvent> */
    private array $movableEvents;

    /** @var array<PinnedEvent> */
    private array $pinnedEvents;

    /**
     * Either list may be empty. Participants are told apart by ID across
     * both lists. Two events may have the same two participants; only
     * their ids must differ.
     *
     * @param array<MovableEvent> $movableEvents The events to place; order carries no meaning
     * @param array<PinnedEvent> $pinnedEvents The events that must not move; order carries no meaning
     *
     * @throws PinConflictException When one participant is pinned in two events at one position
     * @throws InvalidConfigurationException When an entry is not of its list's class, an event
     *                                       id is used twice across the two lists, a pin is
     *                                       not on the grid, the pins at one position exceed
     *                                       the grid's capacity, or the objective weights are
     *                                       too large for the grid's number of sessions
     */
    public function __construct(
        array $movableEvents,
        array $pinnedEvents,
        private SessionGrid $grid,
        private RepackOptions $options = new RepackOptions()
    ) {
        $spread = $grid->getSessionCount() - 1;
        $headroom = PHP_INT_MAX - 2 * $options->consolidationWeight;
        if ($spread > 0 && $options->earlyFillWeight > intdiv($headroom, $spread)) {
            throw new InvalidConfigurationException(
                'The objective weights are too large for a grid of this many sessions',
                [
                    'consolidation_weight' => $options->consolidationWeight,
                    'early_fill_weight' => $options->earlyFillWeight,
                    'sessions' => $grid->getSessionCount(),
                ],
                reason: InvalidConfigurationReason::IncompatibleOptions
            );
        }

        $seenIds = [];
        foreach (array_values($movableEvents) as $index => $event) {
            if (!$event instanceof MovableEvent) {
                throw new InvalidConfigurationException(
                    'Every movable event must be a MovableEvent',
                    ['index' => $index, 'given' => get_debug_type($event)],
                    reason: InvalidConfigurationReason::WrongValueType
                );
            }

            if (isset($seenIds[$event->getId()])) {
                throw new InvalidConfigurationException(
                    'Event ids must be unique across the request',
                    ['event_id' => $event->getId()],
                    reason: InvalidConfigurationReason::DuplicateEventId
                );
            }
            $seenIds[$event->getId()] = true;
        }

        $pinnedPerPosition = [];
        $participantPositions = [];
        foreach (array_values($pinnedEvents) as $index => $event) {
            if (!$event instanceof PinnedEvent) {
                throw new InvalidConfigurationException(
                    'Every pinned event must be a PinnedEvent',
                    ['index' => $index, 'given' => get_debug_type($event)],
                    reason: InvalidConfigurationReason::WrongValueType
                );
            }

            if (isset($seenIds[$event->getId()])) {
                throw new InvalidConfigurationException(
                    'Event ids must be unique across the request',
                    ['event_id' => $event->getId()],
                    reason: InvalidConfigurationReason::DuplicateEventId
                );
            }
            $seenIds[$event->getId()] = true;

            if (!$grid->hasPosition($event->getSession(), $event->getSlot())) {
                throw new InvalidConfigurationException(
                    'A pinned event must sit on a grid position',
                    [
                        'event_id' => $event->getId(),
                        'session' => $event->getSession(),
                        'slot' => $event->getSlot(),
                        'sessions' => $grid->getSessionCount(),
                    ],
                    reason: InvalidConfigurationReason::PinOffGrid
                );
            }

            $position = $event->getSession() . ':' . $event->getSlot();
            $pinnedPerPosition[$position] = ($pinnedPerPosition[$position] ?? 0) + 1;
            $capacityLimit = $grid->getCapacityLimit();
            if ($capacityLimit !== null && $pinnedPerPosition[$position] > $capacityLimit) {
                throw new InvalidConfigurationException(
                    'Pinned events overflow a slot\'s declared capacity',
                    [
                        'session' => $event->getSession(),
                        'slot' => $event->getSlot(),
                        'capacity_per_slot' => $capacityLimit,
                    ],
                    reason: InvalidConfigurationReason::PinCapacityExceeded
                );
            }

            foreach ($event->getParticipants() as $participant) {
                $key = $position . ':' . $participant->getId();
                if (isset($participantPositions[$key])) {
                    throw new PinConflictException(
                        $participant->getId(),
                        $event->getSession(),
                        $event->getSlot(),
                        $participantPositions[$key],
                        $event->getId()
                    );
                }
                $participantPositions[$key] = $event->getId();
            }
        }

        $this->movableEvents = array_values($movableEvents);
        $this->pinnedEvents = array_values($pinnedEvents);
    }

    /**
     * The movable events, in the order given and keyed 0, 1, 2 and so on.
     *
     * @return array<MovableEvent>
     */
    public function getMovableEvents(): array
    {
        return $this->movableEvents;
    }

    /**
     * The pinned events, in the order given and keyed 0, 1, 2 and so on.
     *
     * @return array<PinnedEvent>
     */
    public function getPinnedEvents(): array
    {
        return $this->pinnedEvents;
    }

    /**
     * The grid the events are placed on.
     */
    public function getGrid(): SessionGrid
    {
        return $this->grid;
    }

    /**
     * The options of the repack; the defaults when none were given.
     */
    public function getOptions(): RepackOptions
    {
        return $this->options;
    }
}
