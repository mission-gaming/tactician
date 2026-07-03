<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Timeline;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use InvalidArgumentException;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;

/**
 * An event decorated with its assigned kickoff time.
 *
 * Decoration, not mutation: the wrapped event is untouched, so pairing
 * logic and schedule serialization stay unaware of times and
 * re-assignment is cheap. Kickoffs are always UTC — display-timezone
 * policy stays application-side.
 */
final readonly class ScheduledEvent
{
    private DateTimeImmutable $kickoff;

    /**
     * @param Event $event The wrapped, unmodified event
     * @param DateTimeImmutable $kickoff The assigned kickoff; normalized to
     *                                   UTC on construction, so the class
     *                                   invariant holds whatever zone the
     *                                   caller supplied
     * @param string|null $resource The named resource hosting the event
     *                              (venue, pitch, court...); null when the
     *                              timeline declares no resources
     */
    public function __construct(
        private Event $event,
        DateTimeImmutable $kickoff,
        private ?string $resource = null
    ) {
        $this->kickoff = $kickoff->setTimezone(new DateTimeZone('UTC'));
    }

    public function getEvent(): Event
    {
        return $this->event;
    }

    /**
     * The assigned kickoff, in UTC.
     */
    public function getKickoff(): DateTimeImmutable
    {
        return $this->kickoff;
    }

    /**
     * The named resource hosting the event, or null when the timeline
     * declares no resources.
     */
    public function getResource(): ?string
    {
        return $this->resource;
    }

    /**
     * Convert to a serializable array; the kickoff is an ISO 8601 UTC
     * string, the event in its own array form (participants by ID).
     *
     * @return array{event: array{participants: array<string>, round: array{number: int, metadata: array<string, mixed>}|null, metadata: array<string, mixed>}, kickoff: string, resource: string|null}
     */
    public function toArray(): array
    {
        return [
            'event' => $this->event->toArray(),
            'kickoff' => $this->kickoff->format('Y-m-d\TH:i:s\Z'),
            'resource' => $this->resource,
        ];
    }

    /**
     * Recreate a scheduled event from its array representation.
     *
     * @param array<string, mixed> $data
     * @param array<string, Participant> $participantsById Registry resolving participant IDs
     *
     * @throws InvalidArgumentException When fields are malformed or a participant ID is unknown
     */
    public static function fromArray(array $data, array $participantsById): self
    {
        $eventData = $data['event'] ?? null;
        if (!is_array($eventData)) {
            throw new InvalidArgumentException('Scheduled event data requires an event array');
        }
        /** @var array<string, mixed> $eventData */
        $event = Event::fromArray($eventData, $participantsById);

        $kickoffValue = $data['kickoff'] ?? null;
        if (!is_string($kickoffValue)) {
            throw new InvalidArgumentException('Scheduled event data requires a kickoff string');
        }

        try {
            $kickoff = new DateTimeImmutable($kickoffValue, new DateTimeZone('UTC'));
        } catch (Exception $exception) {
            throw new InvalidArgumentException('Scheduled event kickoff is not parseable', 0, $exception);
        }

        $resource = $data['resource'] ?? null;
        if ($resource !== null && !is_string($resource)) {
            throw new InvalidArgumentException('Scheduled event resource must be a string or null');
        }

        return new self($event, $kickoff, $resource);
    }
}
