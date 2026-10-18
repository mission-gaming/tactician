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
use MissionGaming\Tactician\Serialization\PlainData;
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
     * The entrants as given, or as getEntrants() derives them.
     *
     * @var list<Participant>
     */
    private array $entrants;

    /**
     * Whether the entrant list was given, and is written by toArray().
     */
    private bool $entrantsGiven;

    /**
     * The list of events is kept as given: it is not sorted, and its
     * entries are not checked. The assigner gives it rounds ascending and,
     * within a round, in slot order, which is kickoff order as long as one
     * round's slots end before the next round starts.
     *
     * @param array<ScheduledEvent> $scheduledEvents
     * @param array<Participant>|null $entrants The participants of the schedule, in their
     *                                          order: those of the schedule assigned
     *                                          (Schedule::getEntrants()), which is what
     *                                          TimelineAssigner::assign() passes. Null for
     *                                          the participants of the events, in the order
     *                                          they first appear. Not checked against the
     *                                          events
     *
     * @throws InvalidInputException When two entrants share an ID
     */
    public function __construct(
        private array $scheduledEvents,
        ?array $entrants = null
    ) {
        $this->entrantsGiven = $entrants !== null;
        if ($entrants !== null) {
            $seen = [];
            foreach ($entrants as $entrant) {
                if (isset($seen[$entrant->getId()])) {
                    throw new InvalidInputException("Participant {$entrant->getId()} is in the entrant list of the scheduled schedule twice");
                }
                $seen[$entrant->getId()] = true;
            }
            $this->entrants = array_values($entrants);

            return;
        }

        /** @var array<string, Participant> $appearing */
        $appearing = [];
        foreach ($scheduledEvents as $scheduledEvent) {
            foreach ($scheduledEvent->getEvent()->getParticipants() as $participant) {
                $appearing[$participant->getId()] ??= $participant;
            }
        }
        $this->entrants = array_values($appearing);
    }

    /**
     * The participants of the schedule, in their order: the entrant list it
     * was built with, which for a schedule from TimelineAssigner::assign()
     * is that of the schedule assigned, or else the participants of its
     * events in the order they first appear.
     *
     * @return list<Participant>
     */
    public function getEntrants(): array
    {
        return $this->entrants;
    }

    /**
     * The byes of every round: the entrants that have no event in it, by
     * round number in ascending order, as Schedule::getByesByRound() gives
     * them for the schedule assigned.
     *
     * A bye is read from the events: an entrant (getEntrants()) has a bye
     * in a round when no event of that round names it. Every round that has
     * an event is a key, with an empty list where every entrant plays; the
     * entrants of a list are in entrant order. A scheduled schedule built
     * without an entrant list knows only the participants of its events, so
     * an entrant that plays in no round at all is not seen there.
     *
     * @return array<int, list<Participant>> Keyed by round number, ascending
     */
    public function getByesByRound(): array
    {
        $byes = [];
        foreach ($this->getEventsByRound() as $roundNumber => $scheduledEvents) {
            $playing = [];
            foreach ($scheduledEvents as $scheduledEvent) {
                foreach ($scheduledEvent->getEvent()->getParticipants() as $participant) {
                    $playing[$participant->getId()] = true;
                }
            }

            $byes[$roundNumber] = array_values(array_filter(
                $this->entrants,
                static fn(Participant $entrant): bool => !isset($playing[$entrant->getId()])
            ));
        }

        return $byes;
    }

    /**
     * How many byes each entrant has over the rounds of the schedule
     * (getByesByRound()), keyed by participant ID. An entrant that plays in
     * every round has no entry. This is the shape a standings calculator
     * takes byes in (Standings\StandingsCalculator::calculateWith()).
     *
     * @return array<string, int>
     */
    public function getByeCounts(): array
    {
        $counts = [];
        foreach ($this->getByesByRound() as $participants) {
            foreach ($participants as $participant) {
                $counts[$participant->getId()] = ($counts[$participant->getId()] ?? 0) + 1;
            }
        }

        return $counts;
    }

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
     * order they first appear, then any entrant that appears in no event,
     * and referenced by ID; kickoffs as ISO 8601 UTC strings to the second
     * (`2026-08-01T18:00:00Z`); each event's resource, or null. The first
     * key is `format_version`, the version of this form (1); the
     * participants and the events inside carry none. The key `entrants`,
     * the IDs of the entrant list in its order, is written only for a
     * schedule that was given one, so one built from its events alone has
     * the keys it has always had.
     *
     * @return array{format_version: int, participants: array<int, array{id: string, label?: string, seed: int|null, metadata: array<string, mixed>}>, events: array<int, array{event: array{participants: array<string>, round: array{number: int, metadata: array<string, mixed>}|null, metadata: array<string, mixed>}, kickoff: string, resource: string|null}>, entrants?: list<string>}
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
        foreach ($this->entrants as $entrant) {
            $participantsById[$entrant->getId()] ??= $entrant;
        }

        $participants = [];
        foreach ($participantsById as $participant) {
            $data = $participant->toArray();
            unset($data[PlainData::VERSION_KEY]);
            $participants[] = $data;
        }

        $events = [];
        foreach ($this->scheduledEvents as $key => $scheduledEvent) {
            $data = $scheduledEvent->toArray();
            unset($data[PlainData::VERSION_KEY]);
            $events[$key] = $data;
        }

        $data = [
            PlainData::VERSION_KEY => PlainData::VERSION,
            'participants' => $participants,
            'events' => $events,
        ];
        if ($this->entrantsGiven) {
            $data['entrants'] = array_map(static fn(Participant $entrant): string => $entrant->getId(), $this->entrants);
        }

        return $data;
    }

    /**
     * What `json_encode()` writes for the schedule: the array of toArray().
     *
     * @return array{format_version: int, participants: array<int, array{id: string, label?: string, seed: int|null, metadata: array<string, mixed>}>, events: array<int, array{event: array{participants: array<string>, round: array{number: int, metadata: array<string, mixed>}|null, metadata: array<string, mixed>}, kickoff: string, resource: string|null}>, entrants?: list<string>}
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
     * event names by ID must be in `participants`, and so must every ID of
     * `entrants`, which may be left out or null (the entrants are then the
     * participants of the events). A kickoff states its date in full, as
     * ScheduledEvent::fromArray() requires.
     *
     * @param array<string, mixed> $data
     * @throws InvalidInputException When the format version is not one this release reads,
     *                               a key is unknown (`participants`, `events` and
     *                               `entrants` are the keys), the data is malformed, an
     *                               event or the entrant list names a participant the data
     *                               does not list, the entrant list names one twice, or a kickoff does not state an instant by
     *                               itself
     */
    public static function fromArray(array $data): self
    {
        PlainData::checkFields($data, ['participants', 'events', 'entrants'], 'scheduled schedule data');

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

        if (($data['entrants'] ?? null) === null) {
            return new self($scheduledEvents);
        }

        $entrantIds = $data['entrants'];
        if (!is_array($entrantIds) || !array_is_list($entrantIds)) {
            throw new InvalidInputException('Scheduled schedule entrants must be a list of participant IDs');
        }
        $entrants = [];
        foreach ($entrantIds as $entrantId) {
            if (!is_string($entrantId) || !isset($participantsById[$entrantId])) {
                throw new InvalidInputException('Each scheduled schedule entrant must be the ID of a listed participant');
            }
            $entrants[] = $participantsById[$entrantId];
        }

        return new self($scheduledEvents, $entrants);
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
