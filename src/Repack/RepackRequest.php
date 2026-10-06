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
 */
final readonly class RepackRequest
{
    /** @var array<MovableEvent> */
    private array $movableEvents;

    /** @var array<PinnedEvent> */
    private array $pinnedEvents;

    /**
     * @param array<MovableEvent> $movableEvents The events to place; order carries no meaning
     * @param array<PinnedEvent> $pinnedEvents The events that must not move; order carries no meaning
     *
     * @throws PinConflictException When one participant is pinned in two events at one position
     * @throws InvalidConfigurationException When the input contradicts itself in any other way
     */
    public function __construct(
        array $movableEvents,
        array $pinnedEvents,
        private SessionGrid $grid,
        private RepackOptions $options = new RepackOptions()
    ) {
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
            if ($pinnedPerPosition[$position] > $grid->getCapacityPerSlot()) {
                throw new InvalidConfigurationException(
                    'Pinned events overflow a slot\'s declared capacity',
                    [
                        'session' => $event->getSession(),
                        'slot' => $event->getSlot(),
                        'capacity_per_slot' => $grid->getCapacityPerSlot(),
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
     * @return array<MovableEvent>
     */
    public function getMovableEvents(): array
    {
        return $this->movableEvents;
    }

    /**
     * @return array<PinnedEvent>
     */
    public function getPinnedEvents(): array
    {
        return $this->pinnedEvents;
    }

    public function getGrid(): SessionGrid
    {
        return $this->grid;
    }

    public function getOptions(): RepackOptions
    {
        return $this->options;
    }
}
