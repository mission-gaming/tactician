<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Timeline;

use ArrayIterator;
use Countable;
use Iterator;
use IteratorAggregate;
use JsonSerializable;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\InvalidInputException;
use MissionGaming\Tactician\Exceptions\JsonConversionException;
use Override;

/**
 * A schedule's events decorated with their assigned kickoff times.
 *
 * Purely a decorated view: the underlying Schedule (and its pairing
 * logic, metadata, and serialization) is untouched, and re-assigning
 * against a different timeline just produces another ScheduledSchedule.
 *
 * @implements IteratorAggregate<int, ScheduledEvent>
 *
 * @experimental
 */
final readonly class ScheduledSchedule implements Countable, IteratorAggregate, JsonSerializable
{
    /**
     * The list is kept as given: it is not sorted, and its entries are not
     * checked. The assigner gives it rounds ascending and, within a round,
     * in slot order, which is kickoff order as long as one round's slots
     * end before the next round starts.
     *
     * @param array<ScheduledEvent> $scheduledEvents
     */
    public function __construct(
        private array $scheduledEvents
    ) {}

    /**
     * Every scheduled event, in the order the schedule was built with (see
     * the constructor); not sorted by kickoff.
     *
     * @return array<ScheduledEvent>
     */
    public function getScheduledEvents(): array
    {
        return $this->scheduledEvents;
    }

    /**
     * Scheduled events grouped by round number, ascending; within a round
     * in the schedule's order. An event without a round is in no group.
     *
     * @return array<int, array<ScheduledEvent>> Keyed by 1-based round number
     */
    public function getEventsByRound(): array
    {
        $grouped = [];
        foreach ($this->scheduledEvents as $scheduledEvent) {
            $roundNumber = $scheduledEvent->getEvent()->getRound()?->getNumber();
            if ($roundNumber !== null) {
                $grouped[$roundNumber][] = $scheduledEvent;
            }
        }
        ksort($grouped);

        return $grouped;
    }

    /**
     * The number of scheduled events.
     */
    #[Override]
    public function count(): int
    {
        return count($this->scheduledEvents);
    }

    /**
     * Iterates the scheduled events in the order of getScheduledEvents().
     *
     * @return Iterator<int, ScheduledEvent>
     */
    #[Override]
    public function getIterator(): Iterator
    {
        return new ArrayIterator($this->scheduledEvents);
    }

    /**
     * Convert to a serializable array: participants listed once, in the
     * order they first appear, and referenced by ID; kickoffs as ISO 8601
     * UTC strings to the second (`2026-08-01T18:00:00Z`); each event's
     * resource, or null.
     *
     * @return array{participants: array<int, array{id: string, label: string, seed: int|null, metadata: array<string, mixed>}>, events: array<int, array{event: array{participants: array<string>, round: array{number: int, metadata: array<string, mixed>}|null, metadata: array<string, mixed>}, kickoff: string, resource: string|null}>}
     */
    public function toArray(): array
    {
        /** @var array<string, Participant> $participantsById */
        $participantsById = [];
        foreach ($this->scheduledEvents as $scheduledEvent) {
            foreach ($scheduledEvent->getEvent()->getParticipants() as $participant) {
                $participantsById[$participant->getId()] ??= $participant;
            }
        }

        return [
            'participants' => array_values(array_map(
                fn(Participant $participant) => $participant->toArray(),
                $participantsById
            )),
            'events' => array_map(
                fn(ScheduledEvent $scheduledEvent) => $scheduledEvent->toArray(),
                $this->scheduledEvents
            ),
        ];
    }

    /**
     * What `json_encode()` writes for the schedule: the array of toArray().
     *
     * @return array{participants: array<int, array{id: string, label: string, seed: int|null, metadata: array<string, mixed>}>, events: array<int, array{event: array{participants: array<string>, round: array{number: int, metadata: array<string, mixed>}|null, metadata: array<string, mixed>}, kickoff: string, resource: string|null}>}
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Serialize to a JSON string that fromJson() reads back.
     *
     * @throws JsonConversionException When the schedule contains values JSON cannot represent
     *                                 (a string that is not valid UTF-8, a metadata value
     *                                 such as NAN)
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
     * Recreate a scheduled schedule from its array representation
     * (toArray()), events in the order of the data. Every participant an
     * event names by ID must be in `participants`. A kickoff states its
     * date in full, as ScheduledEvent::fromArray() requires.
     *
     * @param array<string, mixed> $data
     * @throws InvalidInputException When the data is malformed, an event names a participant
     *                               the data does not list, or a kickoff does not state an
     *                               instant by itself
     */
    public static function fromArray(array $data): self
    {
        $participantsData = $data['participants'] ?? [];
        if (!is_array($participantsData)) {
            throw new InvalidInputException('Scheduled schedule participants must be an array');
        }

        /** @var array<string, Participant> $participantsById */
        $participantsById = [];
        foreach ($participantsData as $participantData) {
            if (!is_array($participantData)) {
                throw new InvalidInputException('Each scheduled schedule participant must be an array');
            }
            /** @var array<string, mixed> $participantData */
            $participant = Participant::fromArray($participantData);
            $participantsById[$participant->getId()] = $participant;
        }

        $eventsData = $data['events'] ?? [];
        if (!is_array($eventsData)) {
            throw new InvalidInputException('Scheduled schedule events must be an array');
        }

        $scheduledEvents = [];
        foreach ($eventsData as $eventData) {
            if (!is_array($eventData)) {
                throw new InvalidInputException('Each scheduled schedule event must be an array');
            }
            /** @var array<string, mixed> $eventData */
            $scheduledEvents[] = ScheduledEvent::fromArray($eventData, $participantsById);
        }

        return new self($scheduledEvents);
    }

    /**
     * Recreate a scheduled schedule from its JSON representation (toJson()).
     *
     * @throws JsonConversionException When the JSON is malformed
     * @throws InvalidInputException When the decoded data is malformed
     */
    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw JsonConversionException::from($exception);
        }
        if (!is_array($data)) {
            throw new InvalidInputException('Scheduled schedule JSON must decode to an array');
        }

        /** @var array<string, mixed> $data */
        return self::fromArray($data);
    }
}
