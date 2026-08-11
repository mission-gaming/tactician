<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Repack;

use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;

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
     * @throws InvalidConfigurationException When the input contradicts itself
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
                    ['index' => $index, 'given' => get_debug_type($event)]
                );
            }

            if (isset($seenIds[$event->getId()])) {
                throw new InvalidConfigurationException(
                    'Event ids must be unique across the request',
                    ['event_id' => $event->getId()]
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
                    ['index' => $index, 'given' => get_debug_type($event)]
                );
            }

            if (isset($seenIds[$event->getId()])) {
                throw new InvalidConfigurationException(
                    'Event ids must be unique across the request',
                    ['event_id' => $event->getId()]
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
                    ]
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
                    ]
                );
            }

            foreach ($event->getParticipants() as $participant) {
                $key = $position . ':' . $participant->getId();
                if (isset($participantPositions[$key])) {
                    throw new InvalidConfigurationException(
                        'A participant is pinned twice at one position',
                        [
                            'participant' => $participant->getId(),
                            'session' => $event->getSession(),
                            'slot' => $event->getSlot(),
                            'event_ids' => [$participantPositions[$key], $event->getId()],
                        ]
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
