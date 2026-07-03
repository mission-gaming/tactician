<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\DTO;

use Countable;
use InvalidArgumentException;
use Iterator;
use JsonSerializable;
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
class Schedule implements Iterator, Countable, JsonSerializable
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
     * @param Round $round The round to filter by
     * @return array<Event> Events belonging to the specified round
     */
    public function getEventsForRound(Round $round): array
    {
        return array_values(array_filter(
            $this->events,
            fn (Event $event) => $event->getRound()?->equals($round) ?? false
        ));
    }

    /**
     * Get the events grouped by round number, in ascending round order.
     *
     * This is the natural shape for consumers that process a schedule round
     * by round (assigning one date per round, rendering matchday views, and
     * so on). Events without an assigned round are excluded; use getEvents()
     * for the full flat list.
     *
     * @return array<int, array<Event>> Events keyed by round number, ascending
     */
    public function getEventsByRound(): array
    {
        $grouped = [];
        foreach ($this->events as $event) {
            $roundNumber = $event->getRound()?->getNumber();
            if ($roundNumber !== null) {
                $grouped[$roundNumber][] = $event;
            }
        }
        ksort($grouped);

        return $grouped;
    }

    /**
     * Get the highest round found in this schedule.
     *
     * Useful for determining how many rounds the tournament contains.
     *
     * @return Round|null The maximum round, or null if no events have rounds assigned
     */
    public function getMaxRound(): ?Round
    {
        if (empty($this->events)) {
            return null;
        }

        $rounds = array_map(
            fn (Event $event) => $event->getRound(),
            $this->events
        );

        $nonNullRounds = array_filter($rounds, fn ($round) => $round !== null);

        if (empty($nonNullRounds)) {
            return null;
        }

        return array_reduce(
            $nonNullRounds,
            fn (?Round $max, Round $current) => $max === null || $current->isAfter($max) ? $current : $max
        );
    }

    /**
     * Convert this schedule to a serializable array.
     *
     * Participants are listed once and referenced by ID from events. Metadata
     * values must be serializable by the consumer (e.g. JSON-safe when using
     * toJson()).
     *
     * @return array{participants: array<int, array{id: string, label: string, seed: int|null, metadata: array<string, mixed>}>, events: array<int, array{participants: array<string>, round: array{number: int, metadata: array<string, mixed>}|null, metadata: array<string, mixed>}>, metadata: array<string, mixed>}
     */
    public function toArray(): array
    {
        /** @var array<string, Participant> $participantsById */
        $participantsById = [];
        foreach ($this->events as $event) {
            foreach ($event->getParticipants() as $participant) {
                $participantsById[$participant->getId()] ??= $participant;
            }
        }

        return [
            'participants' => array_values(array_map(
                fn (Participant $participant) => $participant->toArray(),
                $participantsById
            )),
            'events' => array_map(fn (Event $event) => $event->toArray(), $this->events),
            'metadata' => $this->metadata,
        ];
    }

    /**
     * @return array{participants: array<int, array{id: string, label: string, seed: int|null, metadata: array<string, mixed>}>, events: array<int, array{participants: array<string>, round: array{number: int, metadata: array<string, mixed>}|null, metadata: array<string, mixed>}>, metadata: array<string, mixed>}
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Serialize this schedule to a JSON string.
     *
     * @throws \JsonException When the schedule contains values JSON cannot represent
     */
    public function toJson(): string
    {
        return json_encode($this, JSON_THROW_ON_ERROR);
    }

    /**
     * Recreate a schedule from its array representation.
     *
     * @param array<string, mixed> $data
     *
     * @throws InvalidArgumentException When the data is malformed
     */
    public static function fromArray(array $data): self
    {
        $participantsData = $data['participants'] ?? [];
        if (!is_array($participantsData)) {
            throw new InvalidArgumentException('Schedule participants must be an array');
        }

        /** @var array<string, Participant> $participantsById */
        $participantsById = [];
        foreach ($participantsData as $participantData) {
            if (!is_array($participantData)) {
                throw new InvalidArgumentException('Each schedule participant must be an array');
            }
            /** @var array<string, mixed> $participantData */
            $participant = Participant::fromArray($participantData);
            $participantsById[$participant->getId()] = $participant;
        }

        $eventsData = $data['events'] ?? [];
        if (!is_array($eventsData)) {
            throw new InvalidArgumentException('Schedule events must be an array');
        }

        $events = [];
        foreach ($eventsData as $eventData) {
            if (!is_array($eventData)) {
                throw new InvalidArgumentException('Each schedule event must be an array');
            }
            /** @var array<string, mixed> $eventData */
            $events[] = Event::fromArray($eventData, $participantsById);
        }

        $rawMetadata = $data['metadata'] ?? [];
        if (!is_array($rawMetadata)) {
            throw new InvalidArgumentException('Schedule metadata must be an array');
        }
        $metadata = [];
        foreach ($rawMetadata as $key => $value) {
            $metadata[(string) $key] = $value;
        }

        return new self($events, $metadata);
    }

    /**
     * Recreate a schedule from a JSON string produced by toJson().
     *
     * @throws \JsonException When the JSON is invalid
     * @throws InvalidArgumentException When the decoded data is malformed
     */
    public static function fromJson(string $json): self
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Schedule JSON must decode to an object');
        }

        /** @var array<string, mixed> $decoded */
        return self::fromArray($decoded);
    }
}
