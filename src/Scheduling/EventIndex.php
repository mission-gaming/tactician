<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use MissionGaming\Tactician\DTO\Event;

/**
 * Lookup maps over one SchedulingContext's event list, so that a question
 * about one participant, one pairing or one round reads the few events it
 * concerns and not the whole schedule.
 *
 * Every map answers exactly what a scan of the list answers: the same
 * events, under the keys they have in the list, in the order of the list.
 * `tests/Unit/Scheduling/EventIndexTest.php` holds each map to a rescan.
 *
 * A map is built the first time it is asked for, and only as far as it is
 * needed: a context that no constraint queries pays nothing. A context made
 * by SchedulingContext::withEvents() takes over the maps its parent had
 * already built and adds only the appended events (see adopt()).
 *
 * The object changes as its maps fill in, which is why it is a separate
 * class and not state on the readonly context. What it answers never
 * changes: the event list is fixed at construction.
 *
 * @internal Not public API: SchedulingContext is the way to ask.
 */
final class EventIndex
{
    private readonly int $count;

    private ?bool $isList = null;

    /** @var array<array-key, array<array-key, Event>> Participant id => that participant's events */
    private array $byParticipant = [];

    /** How many leading events (by position) $byParticipant covers. */
    private int $byParticipantCovered = 0;

    /** @var array<int, array<array-key, Event>> Round number => the round's events */
    private array $byRound = [];

    private int $byRoundCovered = 0;

    /**
     * @var array<array-key, array<array-key, array<array-key, Event>>> Smaller id (byte order) => larger id
     *                                                                  => the events both take part in
     */
    private array $byPair = [];

    private int $byPairCovered = 0;

    /** @var array<string, array{covered: int, events: array<array-key, Event>}> "first:last" round range => its events */
    private array $byRoundRange = [];

    /**
     * @param array<array-key, Event> $events The context's event list, exactly as the context holds it
     */
    public function __construct(private readonly array $events)
    {
        $this->count = count($events);
    }

    /**
     * Take over the maps a parent context has built, for a list that is the
     * parent's list with events appended.
     *
     * Sound only when the first $parent->count entries of this list are the
     * parent's entries under the same keys. The array spread that
     * withEvents() uses guarantees that when the parent's list is a list in
     * PHP's sense (keys 0, 1, 2, ...): it keeps those keys and appends. For
     * any other parent list the spread renumbers integer keys and may
     * overwrite a string key, so nothing is taken over and the maps are
     * built from this list alone.
     */
    public function adopt(self $parent): void
    {
        if ($parent->count > $this->count || !$parent->isList()) {
            return;
        }

        $this->byParticipant = $parent->byParticipant;
        $this->byParticipantCovered = $parent->byParticipantCovered;
        $this->byRound = $parent->byRound;
        $this->byRoundCovered = $parent->byRoundCovered;
        $this->byPair = $parent->byPair;
        $this->byPairCovered = $parent->byPairCovered;
        $this->byRoundRange = $parent->byRoundRange;
    }

    /**
     * The events the participant with this id takes part in.
     *
     * @return array<array-key, Event>
     */
    public function forParticipant(string $participantId): array
    {
        if ($this->byParticipantCovered < $this->count) {
            foreach ($this->eventsFrom($this->byParticipantCovered) as $key => $event) {
                foreach ($this->distinctIds($event) as $id) {
                    $this->byParticipant[$id][$key] = $event;
                }
            }
            $this->byParticipantCovered = $this->count;
        }

        return $this->byParticipant[$participantId] ?? [];
    }

    /**
     * The events in which the participants with these two ids both take
     * part. For one id given twice, that participant's events.
     *
     * @return array<array-key, Event>
     */
    public function between(string $firstId, string $secondId): array
    {
        if ($firstId === $secondId) {
            return $this->forParticipant($firstId);
        }

        if ($this->byPairCovered < $this->count) {
            foreach ($this->eventsFrom($this->byPairCovered) as $key => $event) {
                $ids = $this->distinctIds($event);
                $idCount = count($ids);
                for ($i = 0; $i < $idCount - 1; ++$i) {
                    for ($j = $i + 1; $j < $idCount; ++$j) {
                        if (strcmp($ids[$i], $ids[$j]) < 0) {
                            $this->byPair[$ids[$i]][$ids[$j]][$key] = $event;
                        } else {
                            $this->byPair[$ids[$j]][$ids[$i]][$key] = $event;
                        }
                    }
                }
            }
            $this->byPairCovered = $this->count;
        }

        return strcmp($firstId, $secondId) < 0
            ? $this->byPair[$firstId][$secondId] ?? []
            : $this->byPair[$secondId][$firstId] ?? [];
    }

    /**
     * The events of one round.
     *
     * @return array<array-key, Event>
     */
    public function inRound(int $round): array
    {
        if ($this->byRoundCovered < $this->count) {
            foreach ($this->eventsFrom($this->byRoundCovered) as $key => $event) {
                $number = $event->getRound()?->getNumber();
                if ($number !== null) {
                    $this->byRound[$number][$key] = $event;
                }
            }
            $this->byRoundCovered = $this->count;
        }

        return $this->byRound[$round] ?? [];
    }

    /**
     * The events whose round number is from $firstRound to $lastRound
     * inclusive. An event without a round is in no range.
     *
     * Kept per range and not assembled from inRound(): the result is in the
     * order of the event list, which is not the order of the rounds when
     * the list was not built round by round.
     *
     * @return array<array-key, Event>
     */
    public function inRoundRange(int $firstRound, int $lastRound): array
    {
        $range = $firstRound . ':' . $lastRound;
        $entry = $this->byRoundRange[$range] ?? ['covered' => 0, 'events' => []];

        if ($entry['covered'] < $this->count) {
            foreach ($this->eventsFrom($entry['covered']) as $key => $event) {
                $number = $event->getRound()?->getNumber();
                if ($number !== null && $number >= $firstRound && $number <= $lastRound) {
                    $entry['events'][$key] = $event;
                }
            }
            $entry['covered'] = $this->count;
            $this->byRoundRange[$range] = $entry;
        }

        return $entry['events'];
    }

    private function isList(): bool
    {
        return $this->isList ??= array_is_list($this->events);
    }

    /**
     * The events from a position on, under their own keys.
     *
     * @return array<array-key, Event>
     */
    private function eventsFrom(int $position): array
    {
        return $position === 0 ? $this->events : array_slice($this->events, $position, null, true);
    }

    /**
     * The ids of an event's participants, each once: an event that lists a
     * participant twice is still one event of that participant.
     *
     * @return list<string>
     */
    private function distinctIds(Event $event): array
    {
        $participants = $event->getParticipants();
        if (count($participants) === 2 && isset($participants[0], $participants[1])) {
            $first = $participants[0]->getId();
            $second = $participants[1]->getId();

            return $first === $second ? [$first] : [$first, $second];
        }

        $ids = [];
        foreach ($participants as $participant) {
            $id = $participant->getId();
            if (!in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
