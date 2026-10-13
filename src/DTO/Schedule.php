<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\DTO;

use Countable;
use Iterator;
use JsonSerializable;
use MissionGaming\Tactician\Exceptions\InvalidInputException;
use MissionGaming\Tactician\Exceptions\JsonConversionException;
use MissionGaming\Tactician\Serialization\PlainData;
use Override;

/**
 * An ordered list of events with free-form metadata: what a whole-schedule
 * generator returns.
 *
 * The events and the metadata never change: addEvent() returns a new
 * schedule. The object is not readonly, though, because it is its own
 * iterator and keeps the position of the iteration. One schedule therefore
 * supports one `foreach` at a time: a second loop over the same object
 * inside the first (a quality metric measuring it, for instance) moves the
 * position and ends the outer loop early. Loop over getEvents() where
 * loops may nest.
 *
 * A schedule round-trips through toArray()/fromArray() and
 * toJson()/fromJson().
 *
 * @implements Iterator<int, Event>
 *
 * @api
 */
class Schedule implements Iterator, Countable, JsonSerializable
{
    /** @var array<Event> The events contained in this schedule */
    private readonly array $events;

    /** @var int Current position for Iterator implementation */
    private int $position;

    /**
     * The entrants, in their order.
     *
     * @var list<Participant>
     */
    private readonly array $entrants;

    /**
     * The events are kept in the order given and re-indexed from 0. Nothing
     * about them is checked but who is in them: not that an event has a
     * round, nor that a pairing appears once.
     *
     * The entrants are the participants the schedule is of, in the order
     * the schedule was generated from, which is the seeding where position
     * decides (see getEntrants()). The generators pass the list they were
     * given. Left out, the list is read from the events: every participant
     * once, in the order of first appearance, which is all a list of events
     * can say. Given, it is kept as it is, and it has to hold every
     * participant of every event; an entrant in no event is kept too.
     *
     * @param array<Event> $events The events, in schedule order
     * @param array<string, mixed> $metadata Free-form data; the library reads none of it
     * @param array<Participant>|null $entrants The entrants in their order, or null to read them
     *                                          from the events
     *
     * @throws InvalidInputException When two entrants share an ID, or an event has a
     *                               participant who is not among the entrants given
     */
    public function __construct(array $events = [], private array $metadata = [], ?array $entrants = null)
    {
        $this->events = array_values($events);
        $this->position = 0;
        $this->entrants = self::entrantsOf($this->events, $entrants);
    }

    /**
     * Every event, in schedule order, as a list indexed from 0.
     *
     * @return array<Event>
     */
    public function getEvents(): array
    {
        return $this->events;
    }

    /**
     * The entrants: every participant the schedule is of, once, as a list.
     *
     * For a schedule a generator returned, this is the list the generator
     * was given, in that order. Where position decides (a round robin lays
     * out its rounds from it, a pot draw cuts its pots from it), it is the
     * seeding. An entrant who has no event is in it all the same.
     * For a schedule built from events alone it is the participants in the
     * order they first appear. The list survives toArray()/fromArray() and
     * JSON in its order, as the `participants` of the array.
     *
     * Every participant of every event is in the list, by ID. Where an
     * event holds another object with an entrant's ID, the object in the
     * list is the entrant's.
     *
     * @return list<Participant>
     */
    public function getEntrants(): array
    {
        return $this->entrants;
    }

    /**
     * A new schedule with the event appended after the existing ones and the
     * same metadata. This schedule is not changed, and the new one starts
     * its iteration at the first event. The entrants stay as they are, and
     * a participant of the new event who is not among them is added after
     * them.
     */
    public function addEvent(Event $event): self
    {
        $newEvents = [...$this->events, $event];

        return new self($newEvents, $this->metadata, self::entrantsOf([$event], $this->entrants, true));
    }

    /**
     * The entrant list of a schedule: the entrants given, in their order,
     * or the participants of the events in the order they first appear.
     *
     * @param array<Event> $events
     * @param array<Participant>|null $entrants
     * @param bool $extend Add a participant the entrants given do not hold after them,
     *                     where the list is otherwise refused
     *
     * @return list<Participant>
     *
     * @throws InvalidInputException When two entrants share an ID, or an event has a
     *                               participant who is not among the entrants given
     */
    private static function entrantsOf(array $events, ?array $entrants, bool $extend = false): array
    {
        /** @var array<string, Participant> $listed */
        $listed = [];
        foreach ($entrants ?? [] as $entrant) {
            if (isset($listed[$entrant->getId()])) {
                throw new InvalidInputException("A schedule cannot list entrant {$entrant->getId()} twice");
            }
            $listed[$entrant->getId()] = $entrant;
        }

        foreach ($events as $event) {
            foreach ($event->getParticipants() as $participant) {
                if (isset($listed[$participant->getId()])) {
                    continue;
                }
                if ($entrants !== null && !$extend) {
                    throw new InvalidInputException(
                        "An event of the schedule has participant {$participant->getId()}, who is not among its entrants"
                    );
                }
                $listed[$participant->getId()] = $participant;
            }
        }

        return array_values($listed);
    }

    /**
     * The metadata as given to the constructor.
     *
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Whether the metadata has the key, including a key whose value is null.
     */
    public function hasMetadata(string $key): bool
    {
        return array_key_exists($key, $this->metadata);
    }

    /**
     * The metadata value under the key, or the default.
     *
     * The default is also returned for a key that exists with the value
     * null; use hasMetadata() to tell the two apart.
     */
    public function getMetadataValue(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * The number of events, with or without a round.
     *
     * @return int<0, max>
     */
    #[Override]
    public function count(): int
    {
        return count($this->events);
    }

    /**
     * The event at the current position of the iteration.
     *
     * Call it only while valid() is true: past the last event, and on an
     * empty schedule, there is no event to return and PHP raises a
     * TypeError.
     */
    #[Override]
    public function current(): Event
    {
        return $this->events[$this->position];
    }

    /**
     * The 0-based position of the iteration.
     */
    #[Override]
    public function key(): int
    {
        return $this->position;
    }

    /**
     * Move the iteration to the next event.
     */
    #[Override]
    public function next(): void
    {
        ++$this->position;
    }

    /**
     * Move the iteration back to the first event. `foreach` calls this when
     * it starts, which is why a nested loop over the same schedule disturbs
     * the outer one.
     */
    #[Override]
    public function rewind(): void
    {
        $this->position = 0;
    }

    /**
     * Whether the iteration is at an event, and not past the last one.
     */
    #[Override]
    public function valid(): bool
    {
        return isset($this->events[$this->position]);
    }

    /**
     * Whether the schedule has no events.
     */
    public function isEmpty(): bool
    {
        return count($this->events) === 0;
    }

    /**
     * The events whose round has the same number as the given one, in
     * schedule order, as a list. Only the number is compared; an event
     * without a round matches nothing.
     *
     * @return array<Event>
     */
    public function getEventsForRound(Round $round): array
    {
        return array_values(array_filter(
            $this->events,
            fn(Event $event) => $event->getRound()?->equals($round) ?? false
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
     * @return array<int, array<Event>> Events keyed by round number, ascending; within a
     *                                  round, in schedule order
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
     * The round with the highest number among the events.
     *
     * Its number is the number of rounds only where rounds are numbered
     * from 1 without a gap, as the generators number them. When several
     * events are in that round, the Round object is that of the first of
     * them in schedule order.
     *
     * @return Round|null Null when the schedule is empty or no event has a round
     */
    public function getMaxRound(): ?Round
    {
        if ($this->events === []) {
            return null;
        }

        $rounds = array_map(
            fn(Event $event) => $event->getRound(),
            $this->events
        );

        $nonNullRounds = array_filter($rounds, fn($round) => $round !== null);

        if ($nonNullRounds === []) {
            return null;
        }

        return array_reduce(
            $nonNullRounds,
            fn(?Round $max, Round $current) => $max === null || $current->isAfter($max) ? $current : $max
        );
    }

    /**
     * The plain-data form fromArray() accepts.
     *
     * The `participants` are the entrants (getEntrants()): each once, in
     * the entrants' order, and referenced by ID from the events. An entrant
     * who is in no event is listed too. Metadata values must be
     * serializable by the consumer (e.g. JSON-safe when using toJson()).
     *
     * The first key is `format_version`, the version of this form (1). It
     * is written once, here: the participants and the events inside carry
     * none. fromArray() reads an array without the key as version 1, the
     * form the releases before 0.3.0 wrote.
     *
     * @return array{format_version: int, participants: array<int, array{id: string, label: string, seed: int|null, metadata: array<string, mixed>}>, events: array<int, array{participants: array<string>, round: array{number: int, metadata: array<string, mixed>}|null, metadata: array<string, mixed>}>, metadata: array<string, mixed>}
     */
    public function toArray(): array
    {
        $participants = [];
        foreach ($this->entrants as $participant) {
            $data = $participant->toArray();
            unset($data[PlainData::VERSION_KEY]);
            $participants[] = $data;
        }

        $events = [];
        foreach ($this->events as $event) {
            $data = $event->toArray();
            unset($data[PlainData::VERSION_KEY]);
            $events[] = $data;
        }

        return [
            PlainData::VERSION_KEY => PlainData::VERSION,
            'participants' => $participants,
            'events' => $events,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * What json_encode() encodes: the array form of toArray().
     *
     * @return array{format_version: int, participants: array<int, array{id: string, label: string, seed: int|null, metadata: array<string, mixed>}>, events: array<int, array{participants: array<string>, round: array{number: int, metadata: array<string, mixed>}|null, metadata: array<string, mixed>}>, metadata: array<string, mixed>}
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * The schedule as a JSON string that fromJson() reads back.
     *
     * The events, their order, rounds and participants come back equal,
     * and so do the entrants in their order.
     * Metadata comes back as JSON carries it: a float with no fractional
     * part (2.0) returns as an integer, and an object returns as an array.
     *
     * @throws JsonConversionException When an ID, a label or a metadata value cannot be
     *                                 represented in JSON (INF, NAN, malformed UTF-8)
     */
    public function toJson(): string
    {
        try {
            return json_encode($this, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw JsonConversionException::from($exception);
        }
    }

    /**
     * Recreate a schedule from the array form toArray() produces.
     *
     * Every key is optional: missing 'participants', 'events' or 'metadata'
     * are empty. Any other key is refused: data of your own belongs in the
     * metadata. Events keep the order of the data. An event may only
     * reference a participant the data lists.
     *
     * The `participants` of the data are the entrants of the schedule, in
     * the order the data lists them (see getEntrants()). An array written
     * before the schedule kept its entrants, by 0.2.x, lists the
     * participants in the order they first appear in the events: read
     * back, that order is the entrant list, which is the list a schedule
     * built from those events alone has.
     *
     * @param array<string, mixed> $data
     *
     * @throws InvalidInputException When the format version is not one this release reads,
     *                               a key is unknown, a field has the wrong type, a
     *                               participant or an event is malformed, a participant
     *                               ID is listed twice, or an event references a
     *                               participant ID that is not listed
     */
    public static function fromArray(array $data): self
    {
        PlainData::checkFields($data, ['participants', 'events', 'metadata'], 'schedule data');

        $participantsData = $data['participants'] ?? [];
        if (!is_array($participantsData)) {
            throw new InvalidInputException('Schedule participants must be an array');
        }

        /** @var array<string, Participant> $participantsById */
        $participantsById = [];
        foreach ($participantsData as $participantData) {
            if (!is_array($participantData)) {
                throw new InvalidInputException('Each schedule participant must be an array');
            }
            /** @var array<string, mixed> $participantData */
            $participant = Participant::fromArray($participantData);
            if (isset($participantsById[$participant->getId()])) {
                throw new InvalidInputException("Schedule data lists participant {$participant->getId()} twice");
            }
            $participantsById[$participant->getId()] = $participant;
        }

        $eventsData = $data['events'] ?? [];
        if (!is_array($eventsData)) {
            throw new InvalidInputException('Schedule events must be an array');
        }

        $events = [];
        foreach ($eventsData as $eventData) {
            if (!is_array($eventData)) {
                throw new InvalidInputException('Each schedule event must be an array');
            }
            /** @var array<string, mixed> $eventData */
            $events[] = Event::fromArray($eventData, $participantsById);
        }

        $rawMetadata = $data['metadata'] ?? [];
        if (!is_array($rawMetadata)) {
            throw new InvalidInputException('Schedule metadata must be an array');
        }
        $metadata = [];
        foreach ($rawMetadata as $key => $value) {
            $metadata[(string) $key] = $value;
        }

        return new self($events, $metadata, array_values($participantsById));
    }

    /**
     * Recreate a schedule from a JSON string produced by toJson().
     *
     * @throws JsonConversionException When the string is not valid JSON
     * @throws InvalidInputException When the JSON is not an object or an array, or the
     *                               decoded data is malformed (see fromArray())
     */
    public static function fromJson(string $json): self
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw JsonConversionException::from($exception);
        }
        if (!is_array($decoded)) {
            throw new InvalidInputException('Schedule JSON must decode to an object');
        }

        /** @var array<string, mixed> $decoded */
        return self::fromArray($decoded);
    }
}
