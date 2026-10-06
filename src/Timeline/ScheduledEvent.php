<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Timeline;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\InvalidInputException;

/**
 * An event decorated with its assigned kickoff time.
 *
 * Decoration, not mutation: the wrapped event is untouched, so pairing
 * logic and schedule serialization stay unaware of times and
 * re-assignment is cheap. Kickoffs are always UTC — display-timezone
 * policy stays application-side.
 *
 * @experimental
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
     * @throws InvalidInputException When fields are malformed or a participant ID is unknown, or
     *                               the kickoff does not state an instant by itself (the form
     *                               `toArray()` writes does; `now`, `tomorrow`, an empty
     *                               string, a time of day without a date or a date that does
     *                               not exist does not). A kickoff without a zone is read as
     *                               UTC, and one without a time of day as midnight
     */
    public static function fromArray(array $data, array $participantsById): self
    {
        $eventData = $data['event'] ?? null;
        if (!is_array($eventData)) {
            throw new InvalidInputException('Scheduled event data requires an event array');
        }
        /** @var array<string, mixed> $eventData */
        $event = Event::fromArray($eventData, $participantsById);

        $kickoffValue = $data['kickoff'] ?? null;
        if (!is_string($kickoffValue)) {
            throw new InvalidInputException('Scheduled event data requires a kickoff string');
        }

        // A kickoff is an instant. A string that is relative to the current
        // time, or leaves a part of the date out, is never handed to PHP,
        // which would answer from the clock. A string PHP cannot parse is
        // handed over, for PHP's error.
        $statesInstant = DateTimeString::statesAnInstant($kickoffValue);
        $kickoff = null;
        $previous = null;
        if ($statesInstant || DateTimeString::isMalformed($kickoffValue)) {
            try {
                $kickoff = new DateTimeImmutable($kickoffValue, new DateTimeZone('UTC'));
            } catch (Exception $exception) {
                $previous = $exception;
            }
        }

        if (!$statesInstant || $kickoff === null) {
            throw new InvalidInputException('Scheduled event kickoff is not parseable', 0, $previous);
        }

        $resource = $data['resource'] ?? null;
        if ($resource !== null && (!is_string($resource) || $resource === '')) {
            throw new InvalidInputException('Scheduled event resource must be a non-empty string or null');
        }

        return new self($event, $kickoff, $resource);
    }
}
