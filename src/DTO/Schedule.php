<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\DTO;

use Iterator;
use Countable;

readonly class Schedule implements Iterator, Countable
{
    private array $events;
    private int $position;

    /**
     * @param array<Event> $events
     */
    public function __construct(array $events = [], private array $metadata = [])
    {
        $this->events = array_values($events);
        $this->position = 0;
    }

    /**
     * @return array<Event>
     */
    public function getEvents(): array
    {
        return $this->events;
    }

    public function addEvent(Event $event): self
    {
        $newEvents = [...$this->events, $event];
        return new self($newEvents, $this->metadata);
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function hasMetadata(string $key): bool
    {
        return array_key_exists($key, $this->metadata);
    }

    public function getMetadataValue(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    public function count(): int
    {
        return count($this->events);
    }

    public function current(): Event
    {
        return $this->events[$this->position];
    }

    public function key(): int
    {
        return $this->position;
    }

    public function next(): void
    {
        $this->position++;
    }

    public function rewind(): void
    {
        $this->position = 0;
    }

    public function valid(): bool
    {
        return isset($this->events[$this->position]);
    }

    public function isEmpty(): bool
    {
        return count($this->events) === 0;
    }

    /**
     * Get events for a specific round
     * @return array<Event>
     */
    public function getEventsForRound(int $round): array
    {
        return array_filter(
            $this->events,
            fn(Event $event) => $event->getRound() === $round
        );
    }

    /**
     * Get the maximum round number in the schedule
     */
    public function getMaxRound(): ?int
    {
        if (empty($this->events)) {
            return null;
        }

        $rounds = array_map(
            fn(Event $event) => $event->getRound(),
            $this->events
        );

        $nonNullRounds = array_filter($rounds, fn($round) => $round !== null);
        
        return empty($nonNullRounds) ? null : max($nonNullRounds);
    }
}
