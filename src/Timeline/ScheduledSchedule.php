<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Timeline;

use ArrayIterator;
use Countable;
use InvalidArgumentException;
use Iterator;
use IteratorAggregate;
use JsonSerializable;
use MissionGaming\Tactician\DTO\Participant;
use Override;

/**
 * A schedule's events decorated with their assigned kickoff times.
 *
 * Purely a decorated view: the underlying Schedule (and its pairing
 * logic, metadata, and serialization) is untouched, and re-assigning
 * against a different timeline just produces another ScheduledSchedule.
 *
 * @implements IteratorAggregate<int, ScheduledEvent>
 */
final readonly class ScheduledSchedule implements Countable, IteratorAggregate, JsonSerializable
{
    /**
     * @param array<ScheduledEvent> $scheduledEvents In kickoff order
     */
    public function __construct(
        private array $scheduledEvents
    ) {
    }

    /**
     * @return array<ScheduledEvent>
     */
    public function getScheduledEvents(): array
    {
        return $this->scheduledEvents;
    }

    /**
     * Scheduled events grouped by round number, ascending.
     *
     * @return array<int, array<ScheduledEvent>>
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

    #[Override]
    public function count(): int
    {
        return count($this->scheduledEvents);
    }

    /**
     * @return Iterator<int, ScheduledEvent>
     */
    #[Override]
    public function getIterator(): Iterator
    {
        return new ArrayIterator($this->scheduledEvents);
    }

    /**
     * Convert to a serializable array: participants listed once and
     * referenced by ID, kickoffs as ISO 8601 UTC strings.
     *
     * @return array{participants: array<int, array{id: string, label: string, seed: int|null, metadata: array<string, mixed>}>, events: array<int, array{event: array{participants: array<string>, round: array{number: int, metadata: array<string, mixed>}|null, metadata: array<string, mixed>}, kickoff: string}>}
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
                fn (Participant $participant) => $participant->toArray(),
                $participantsById
            )),
            'events' => array_map(
                fn (ScheduledEvent $scheduledEvent) => $scheduledEvent->toArray(),
                $this->scheduledEvents
            ),
        ];
    }

    /**
     * @return array{participants: array<int, array{id: string, label: string, seed: int|null, metadata: array<string, mixed>}>, events: array<int, array{event: array{participants: array<string>, round: array{number: int, metadata: array<string, mixed>}|null, metadata: array<string, mixed>}, kickoff: string}>}
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Serialize to a JSON string.
     *
     * @throws \JsonException When the schedule contains values JSON cannot represent
     */
    public function toJson(): string
    {
        return json_encode($this, JSON_THROW_ON_ERROR);
    }

    /**
     * Recreate a scheduled schedule from its array representation.
     *
     * @param array<string, mixed> $data
     * @throws InvalidArgumentException When the data is malformed
     */
    public static function fromArray(array $data): self
    {
        $participantsData = $data['participants'] ?? [];
        if (!is_array($participantsData)) {
            throw new InvalidArgumentException('Scheduled schedule participants must be an array');
        }

        /** @var array<string, Participant> $participantsById */
        $participantsById = [];
        foreach ($participantsData as $participantData) {
            if (!is_array($participantData)) {
                throw new InvalidArgumentException('Each scheduled schedule participant must be an array');
            }
            /** @var array<string, mixed> $participantData */
            $participant = Participant::fromArray($participantData);
            $participantsById[$participant->getId()] = $participant;
        }

        $eventsData = $data['events'] ?? [];
        if (!is_array($eventsData)) {
            throw new InvalidArgumentException('Scheduled schedule events must be an array');
        }

        $scheduledEvents = [];
        foreach ($eventsData as $eventData) {
            if (!is_array($eventData)) {
                throw new InvalidArgumentException('Each scheduled schedule event must be an array');
            }
            /** @var array<string, mixed> $eventData */
            $scheduledEvents[] = ScheduledEvent::fromArray($eventData, $participantsById);
        }

        return new self($scheduledEvents);
    }

    /**
     * Recreate a scheduled schedule from its JSON representation.
     *
     * @throws \JsonException When the JSON is malformed
     * @throws InvalidArgumentException When the decoded data is malformed
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new InvalidArgumentException('Scheduled schedule JSON must decode to an array');
        }

        /** @var array<string, mixed> $data */
        return self::fromArray($data);
    }
}
