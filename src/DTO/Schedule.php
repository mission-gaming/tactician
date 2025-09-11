<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\DTO;

use Countable;
use Iterator;
use Override;

/**
 * Represents a complete tournament schedule containing multiple events.
 *
 * A Schedule is a collection of events that can be iterated over and counted.
 * It supports organizing events by rounds, adding metadata, and provides
 * utility methods for schedule analysis. The Schedule implements Iterator
 * and Countable for convenient traversal and counting operations.
 *
 * @implements Iterator<int, Event>
 */
class Schedule implements Iterator, Countable
{
    /** @var array<Event> The events contained in this schedule */
    private readonly array $events;

    /** @var int Current position for Iterator implementation */
    private int $position;

    /**
     * Create a new Schedule with the specified events and metadata.
     *
     * @param array<Event> $events The events to include in this schedule
     * @param array<string, mixed> $metadata Additional custom data for this schedule
     */
    public function __construct(array $events = [], private array $metadata = [])
    {
        $this->events = array_values($events);
        $this->position = 0;
    }

    /**
     * Get all events in this schedule.
     *
     * @return array<Event> All events contained in this schedule
     */
    public function getEvents(): array
    {
        return $this->events;
    }

    /**
     * Create a new Schedule with an additional event added.
     *
     * Since schedules are immutable, this returns a new Schedule instance
     * with the original events plus the new event.
     *
     * @param Event $event The event to add to the schedule
     * @return self A new Schedule instance containing the additional event
     */
    public function addEvent(Event $event): self
    {
        $newEvents = [...$this->events, $event];

        return new self($newEvents, $this->metadata);
    }

    /**
     * Get all metadata associated with this schedule.
     *
     * @return array<string, mixed> All metadata key-value pairs
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Check if a specific metadata key exists for this schedule.
     *
     * @param string $key The metadata key to check for
     * @return bool True if the key exists, false otherwise
     */
    public function hasMetadata(string $key): bool
    {
        return array_key_exists($key, $this->metadata);
    }

    /**
     * Get the value for a specific metadata key.
     *
     * @param string $key The metadata key to retrieve
     * @param mixed $default The default value to return if the key doesn't exist
     * @return mixed The metadata value or the default if key not found
     */
    public function getMetadataValue(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * Get the total number of events in this schedule.
     *
     * Implementation of Countable interface.
     *
     * @return int The number of events in this schedule
     */
    #[Override]
    public function count(): int
    {
        return count($this->events);
    }

    /**
     * Get the current event during iteration.
     *
     * Implementation of Iterator interface.
     *
     * @return Event The current event
     */
    #[Override]
    public function current(): Event
    {
        return $this->events[$this->position];
    }

    /**
     * Get the current position/key during iteration.
     *
     * Implementation of Iterator interface.
     *
     * @return int The current position
     */
    #[Override]
    public function key(): int
    {
        return $this->position;
    }

    /**
     * Move to the next event during iteration.
     *
     * Implementation of Iterator interface.
     *
     * @return void
     */
    #[Override]
    public function next(): void
    {
        ++$this->position;
    }

    /**
     * Reset iteration to the first event.
     *
     * Implementation of Iterator interface.
     *
     * @return void
     */
    #[Override]
    public function rewind(): void
    {
        $this->position = 0;
    }

    /**
     * Check if the current iteration position is valid.
     *
     * Implementation of Iterator interface.
     *
     * @return bool True if the current position contains an event, false otherwise
     */
    #[Override]
    public function valid(): bool
    {
        return isset($this->events[$this->position]);
    }

    /**
     * Check if this schedule contains no events.
     *
     * @return bool True if the schedule is empty, false otherwise
     */
    public function isEmpty(): bool
    {
        return count($this->events) === 0;
    }

    /**
     * Get all events that belong to a specific round.
     *
     * @param int $round The round number to filter by
     * @return array<Event> Events belonging to the specified round
     */
    public function getEventsForRound(int $round): array
    {
        return array_values(array_filter(
            $this->events,
            fn (Event $event) => $event->getRound() === $round
        ));
    }

    /**
     * Get the highest round number found in this schedule.
     *
     * Useful for determining how many rounds the tournament contains.
     *
     * @return int|null The maximum round number, or null if no events have round numbers
     */
    public function getMaxRound(): ?int
    {
        if (empty($this->events)) {
            return null;
        }

        $rounds = array_map(
            fn (Event $event) => $event->getRound(),
            $this->events
        );

        $nonNullRounds = array_filter($rounds, fn ($round) => $round !== null);

        return empty($nonNullRounds) ? null : max($nonNullRounds);
    }
}
