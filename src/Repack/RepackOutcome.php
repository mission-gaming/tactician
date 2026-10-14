<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Repack;

use MissionGaming\Tactician\Exceptions\InvalidInputException;
use MissionGaming\Tactician\Repack\Internal\CanonicalEncoding;
use MissionGaming\Tactician\Serialization\PlainData;

/**
 * The product of a repack: the schedule and every compromise in it, as
 * structured data.
 *
 * Deliberately not exception-driven: an operator repairing a broken
 * season needs "here is the schedule, and here are the compromises"
 * rather than a refusal. Every movable event is either in the
 * assignments or in the unplaced list — the counts reconcile exactly.
 * The caller decides which violations are fatal
 * (RepackOptions(throwOnViolations: true) turns any violation into a
 * RepackViolationsException carrying this outcome, as a convenience).
 *
 * All three lists are deterministically ordered, so two outcomes from the
 * same input serialize byte-identically.
 *
 * @api
 */
final readonly class RepackOutcome
{
    /**
     * The fingerprint scheme this version of the library writes. It is the
     * part of a fingerprint before the colon.
     */
    public const string FINGERPRINT_SCHEME = 'v1';

    /*
     * The keys of each record in the canonical document of scheme v1. They
     * are written out, and not read from toArray(), so that a key added to
     * a toArray() later leaves every v1 fingerprint as it was. Changing a
     * list below changes the scheme.
     */
    private const array FINGERPRINT_ASSIGNMENT_KEYS = ['event_id', 'session', 'slot', 'kickoff'];

    private const array FINGERPRINT_UNPLACED_KEYS = ['event_id', 'reason', 'participant'];

    private const array FINGERPRINT_VIOLATION_KEYS = [
        ParticipantDoubleBooked::class => ['kind', 'participant', 'session', 'slot', 'event_ids'],
        EventUnplaced::class => ['kind', 'event_id', 'reason', 'participant'],
        ContiguityBroken::class => ['kind', 'participant', 'session', 'gap_slots', 'occupied_slots'],
        LateStart::class => ['kind', 'participant', 'session', 'first_slot'],
        CapacityExceeded::class => ['kind', 'participant', 'demand', 'capacity', 'shortfall'],
    ];

    /** @var array<string, SlotAssignment> Keyed by event id */
    private array $assignmentsById;

    /**
     * The repacker builds outcomes; an application builds one by hand to
     * test its own handling of an outcome. The lists are kept in the order
     * given: the orders named below are what the repacker passes, and the
     * constructor neither sorts nor checks them, nor that the lists agree
     * with each other.
     *
     * The last three arguments are figures the repacker works out from
     * the request, which an outcome does not hold (it has neither the
     * participants of an event nor the pinned events). An outcome built
     * by hand states them, as it states its lists, and one that states
     * none has none: no lone-event session, no idle session, no moved
     * event.
     *
     * @param array<SlotAssignment> $assignments Sorted by event id; when two carry
     *                                           the same id, getAssignmentFor()
     *                                           returns the later one
     * @param array<UnplacedEvent> $unplaced Sorted by event id
     * @param array<RepackViolation> $violations Sorted by kind, then scope
     * @param bool $budgetExhausted Whether the step budget stopped a search
     *                              while this outcome was computed
     * @param list<int> $loneEventSessions The sessions holding one event and no
     *                                     more, ascending
     * @param int $idleSessions How many sessions participants sit out between
     *                          sessions they have an event in, summed over the
     *                          participants
     * @param list<string> $movedEventIds The ids of the events placed somewhere
     *                                    other than their current position, sorted
     *                                    as the assignments are
     *
     * @throws InvalidInputException When a list holds anything other than the
     *                               objects or values it is declared to hold, or
     *                               the count of idle sessions is negative
     */
    public function __construct(
        private array $assignments,
        private array $unplaced,
        private array $violations,
        private bool $budgetExhausted = false,
        private array $loneEventSessions = [],
        private int $idleSessions = 0,
        private array $movedEventIds = []
    ) {
        self::requireInstances($assignments, SlotAssignment::class, 'assignment');
        self::requireInstances($unplaced, UnplacedEvent::class, 'unplaced event');
        self::requireInstances($violations, RepackViolation::class, 'violation');

        foreach ($loneEventSessions as $key => $session) {
            if (!is_int($session) || $session < 0) {
                throw new InvalidInputException(sprintf(
                    'Every lone-event session of a repack outcome must be a session index of 0 or more; the entry at key %s is %s',
                    is_int($key) ? (string) $key : "'{$key}'",
                    is_int($session) ? (string) $session : 'of type ' . get_debug_type($session)
                ));
            }
        }

        if ($idleSessions < 0) {
            throw new InvalidInputException(sprintf(
                'The idle sessions of a repack outcome are a count of 0 or more, %d given',
                $idleSessions
            ));
        }

        foreach ($movedEventIds as $key => $eventId) {
            if (!is_string($eventId)) {
                throw new InvalidInputException(sprintf(
                    'Every moved event of a repack outcome must be an event id, a string; the entry at key %s is of type %s',
                    is_int($key) ? (string) $key : "'{$key}'",
                    get_debug_type($eventId)
                ));
            }
        }

        $byId = [];
        foreach ($assignments as $assignment) {
            $byId[$assignment->getEventId()] = $assignment;
        }
        $this->assignmentsById = $byId;
    }

    /**
     * Every placed movable event's assignment, ordered by event id
     * (ascending byte order, as `strcmp()` compares).
     *
     * Pinned events are not re-emitted — they hold their input positions
     * by contract.
     *
     * @return array<SlotAssignment>
     */
    public function getAssignments(): array
    {
        return $this->assignments;
    }

    /**
     * The assignment of one movable event, or null when it is unplaced or
     * unknown.
     */
    public function getAssignmentFor(string $eventId): ?SlotAssignment
    {
        return $this->assignmentsById[$eventId] ?? null;
    }

    /**
     * Every movable event that received no position, with reasons,
     * ordered by event id.
     *
     * @return array<UnplacedEvent>
     */
    public function getUnplaced(): array
    {
        return $this->unplaced;
    }

    /**
     * Every compromise in the returned schedule, itemised.
     *
     * Ordered by kind, in the order ViolationKind declares its cases.
     * Within a kind, as the repacker returns them: double-bookings by
     * session, slot and participant ID; unplaced events by event id;
     * interior gaps and late starts by participant ID, then session;
     * capacity shortfalls with the participants first, the largest
     * shortfall first, and the grid last.
     *
     * @return array<RepackViolation>
     */
    public function getViolations(): array
    {
        return $this->violations;
    }

    /**
     * The violations of one kind, in the order getViolations() has them.
     *
     * The elements are typed as the interface. The five accessors below
     * return the same lists typed as their classes, so that the getters of
     * a kind can be read without an `instanceof` check.
     *
     * The two differ only for an outcome built by hand with a violation
     * of a class from outside the library: that violation is returned
     * here, under the kind it states, and by none of the five accessors,
     * each of which returns objects of its own class only.
     *
     * @return array<RepackViolation>
     */
    public function getViolationsOfKind(ViolationKind $kind): array
    {
        return array_values(array_filter(
            $this->violations,
            static fn(RepackViolation $violation): bool => $violation->getKind() === $kind
        ));
    }

    /**
     * The double-bookings: a participant at one position twice. Always
     * empty for an outcome the repacker returned.
     *
     * @return list<ParticipantDoubleBooked>
     */
    public function getParticipantDoubleBookedViolations(): array
    {
        return $this->violationsOfClass(ParticipantDoubleBooked::class);
    }

    /**
     * The movable events that received no position, as violations. One for
     * each entry of getUnplaced(), carrying the same event id, reason and
     * participant.
     *
     * @return list<EventUnplaced>
     */
    public function getEventUnplacedViolations(): array
    {
        return $this->violationsOfClass(EventUnplaced::class);
    }

    /**
     * The interior gaps: one for each participant and session in which the
     * participant's slots are not consecutive.
     *
     * @return list<ContiguityBroken>
     */
    public function getContiguityBrokenViolations(): array
    {
        return $this->violationsOfClass(ContiguityBroken::class);
    }

    /**
     * The late starts: one for each participant and session in which the
     * participant's first slot is not the session's first.
     *
     * @return list<LateStart>
     */
    public function getLateStartViolations(): array
    {
        return $this->violationsOfClass(LateStart::class);
    }

    /**
     * The capacity shortfalls: one for each participant with more events
     * than free positions, and one for the grid when it is smaller than
     * the event list.
     *
     * @return list<CapacityExceeded>
     */
    public function getCapacityExceededViolations(): array
    {
        return $this->violationsOfClass(CapacityExceeded::class);
    }

    /**
     * How many movable events received no position: the size of
     * getUnplaced(). The first figure to rank outcomes by: an outcome that
     * places more events is the better one whatever else it holds.
     */
    public function getUnplacedCount(): int
    {
        return count($this->unplaced);
    }

    /**
     * How many slots participants wait between their own events within a
     * session: the gap slots of every ContiguityBroken violation, added
     * up. A participant with events at slots 0 and 3 of a session waits
     * two slots there.
     *
     * It is read from the violations, so it is scoped as they are (a
     * participant's slots in a session count where the participant has a
     * movable event in it, pins included in the pattern), and a violation
     * of a class from outside the library adds nothing to it. A late
     * start is not a wait in this sense and is not counted; the late
     * starts are getLateStartViolations().
     */
    public function getGapSlotCount(): int
    {
        $gapSlots = 0;
        foreach ($this->violations as $violation) {
            if ($violation instanceof ContiguityBroken) {
                $gapSlots += $violation->getGapSlots();
            }
        }

        return $gapSlots;
    }

    /**
     * The lone-event sessions, as 0-based session indexes, ascending: the
     * sessions that hold exactly one event, pinned events counted, where
     * that one event is a movable one. A session is opened for it alone.
     *
     * It is a figure of the grid and not of a participant: a session with
     * two events is not a lone-event session, although each of four
     * participants has one event in it. A session whose one event is
     * pinned is not counted: it is history the repack did not arrange. An
     * empty session is not counted either. The repacker counts events,
     * however many participants each has.
     *
     * @return list<int>
     */
    public function getLoneEventSessions(): array
    {
        return $this->loneEventSessions;
    }

    /**
     * How many lone-event sessions there are: the size of
     * getLoneEventSessions().
     */
    public function getLoneEventSessionCount(): int
    {
        return count($this->loneEventSessions);
    }

    /**
     * How many idle sessions the schedule has, summed over the
     * participants. A session is idle for a participant when the
     * participant has no event in it and has one both in an earlier
     * session and in a later one; pinned events count as events. A
     * participant with events in sessions 1, 2 and 5 has two idle
     * sessions, 3 and 4, and the sessions before 1 and after 5 are not
     * idle.
     *
     * Only participants with a placed movable event are counted: the idle
     * sessions of a participant whose events are all pinned are history.
     * A session is counted once for each participant it is idle for, and
     * a participant is every participant of an event, however many an
     * event has.
     */
    public function getIdleSessionCount(): int
    {
        return $this->idleSessions;
    }

    /**
     * The ids of the moved events, in ascending byte order: the movable
     * events that stated a current position (MovableEvent) and were
     * assigned another one, in another session or at another slot of the
     * same session.
     *
     * An event that stated no current position is never in it, and
     * neither is an unplaced event, which is in getUnplaced(). An event
     * whose current position is not on the grid, or is held by pinned
     * events, is in it whenever it was placed.
     *
     * @return list<string>
     */
    public function getMovedEventIds(): array
    {
        return $this->movedEventIds;
    }

    /**
     * How many events moved: the size of getMovedEventIds().
     */
    public function getMovedCount(): int
    {
        return count($this->movedEventIds);
    }

    /**
     * Whether every event was placed with no compromises at all. The
     * lone-event sessions, the idle sessions and the moved events are
     * figures, not compromises, and do not make an outcome unclean.
     */
    public function isClean(): bool
    {
        return $this->unplaced === [] && $this->violations === [];
    }

    /**
     * Whether the step budget (RepackOptions::$stepBudget) stopped a search
     * while this outcome was computed.
     *
     * True means that the budget cut at least one of the repacker's
     * searches short, so the outcome is what was reached by then. Either
     * a search wanted another step and was refused (the session-load
     * improvement and parity repair, the exact packing of a session, the
     * repair of a greedy packing, the last placement step that moves
     * placed events to make room for unplaced ones, the search for fewer
     * gap slots; the last two have a budget of their own, of the same
     * size, and count here as the others do). Or the budget was below
     * the default, and a search that counts its own work (the laying out
     * of the events before any session is chosen, the last placement
     * step, the search for fewer gap slots, the search for the events
     * two over-capacity participants share) reached the share of its
     * work limit that such a budget leaves it. A larger budget may give
     * a different outcome for the same request. It is not a promise of a
     * better one: the instance may have no better packing, and the
     * searches are heuristics.
     *
     * False means that no search was stopped by the budget, so a larger
     * budget gives the same outcome. It does not mean that the outcome is
     * the best possible: at the default budget and above the searches
     * that count their own work stop at limits no budget lifts, and a
     * search that stops there is not one the budget stopped.
     *
     * The flag says nothing about violations. An outcome can be clean with
     * the flag true (the fallback packed everything after the exact search
     * ran out), and can carry violations with it false.
     *
     * It is not part of toArray() or of fingerprint(): it describes how
     * the outcome was reached, not the outcome.
     */
    public function isBudgetExhausted(): bool
    {
        return $this->budgetExhausted;
    }

    /**
     * A stable identifier of what this outcome holds: equal for two
     * outcomes with the same assignments, unplaced events and violations,
     * and different when any of them differs. For detecting that a plan
     * computed again is not the plan that was shown before.
     *
     * The format is a contract. A fingerprint is the scheme, a colon, and
     * 64 lowercase hexadecimal digits: `v1:` followed by the SHA-256 of
     * the canonical document below. A change to the canonical document is
     * a new scheme, so two fingerprints are comparable when their schemes
     * are equal, and a stored fingerprint of another scheme is recomputed,
     * never compared.
     *
     * The canonical document of scheme v1 is the bytes
     *
     *     "tactician.repack.outcome.v1\n" SET(assignments) SET(unplaced) SET(violations)
     *
     * where each of the three is a set of records, and a record is a map
     * with exactly these keys:
     *
     *     an assignment              event_id, session, slot, kickoff
     *     an unplaced event          event_id, reason, participant
     *     a ParticipantDoubleBooked  kind, participant, session, slot, event_ids
     *     an EventUnplaced           kind, event_id, reason, participant
     *     a ContiguityBroken         kind, participant, session, gap_slots, occupied_slots
     *     a LateStart                kind, participant, session, first_slot
     *     a CapacityExceeded         kind, participant, demand, capacity, shortfall
     *
     * Each key holds what toArray() of the record holds under it: an event
     * id as a string, a session, a slot and a count as integers, a
     * participant as its id or null, a kind and a reason as their backing
     * strings, the lists as lists, and a kickoff as the string
     * `2026-08-12T19:00:00Z` or null. The keys are named here because
     * they are the scheme: a key that toArray() gains in a later version
     * is not part of scheme v1 and does not change a v1 fingerprint. A
     * violation of any other class is the whole of what its toArray()
     * returns. And
     *
     *     SET(records)  = "l" COUNT ":" the VALUE of every record, in ascending byte order ";"
     *     VALUE(null)   = "n;"
     *     VALUE(bool)   = "b1;" for true, "b0;" for false
     *     VALUE(int)    = "i" DECIMAL ";"
     *     VALUE(float)  = "f" the 16 hexadecimal digits of the IEEE 754 double, big-endian ";"
     *     VALUE(string) = "s" LENGTH-IN-BYTES ":" the bytes ";"
     *     VALUE(list)   = "l" COUNT ":" the VALUE of every item, in the list's order ";"
     *     VALUE(map)    = "m" COUNT ":" VALUE(key) VALUE(item) for every entry,
     *                     the entries in ascending byte order ";"
     *
     * COUNT, LENGTH-IN-BYTES and DECIMAL are ASCII decimal digits with no
     * leading zeros, DECIMAL with a leading "-" when negative. A list is
     * an array whose keys are 0, 1, 2 and so on in order; any other array
     * is a map. What is put in byte order is the encoded bytes: of each
     * record in a set, and of each entry (its key and item together) in a
     * map, so the entry of the key `slot` ("s4:slot;...") comes before
     * that of `kickoff` ("s7:kickoff;..."). Byte order compares unsigned
     * bytes, a shorter string before a longer one it begins.
     *
     * What follows from that:
     *
     * - The order of the three lists does not matter, and neither does the
     *   order of the keys of a record. The order of a list inside a
     *   record does (the occupied slots of a ContiguityBroken, the event
     *   ids of a ParticipantDoubleBooked); the repacker always writes
     *   those ascending.
     * - Every field the records have today is covered, so a change to any
     *   field of any assignment, unplaced event or violation changes the
     *   fingerprint. A participant is covered by its id and by nothing
     *   else of it. The kickoff is covered to the second, and is null for
     *   a shape-only grid, so the same positions on a shape-only grid and
     *   on an instant-based one have different fingerprints.
     * - isBudgetExhausted() is not covered. Two outcomes holding the same
     *   plan are the same plan, however each search ended.
     * - Nothing in it depends on the PHP version, the platform, the
     *   locale or an ini setting.
     *
     * @throws InvalidInputException When a violation of a class from outside the library
     *                               serializes to something the encoding has no form for
     *                               (an object, a resource)
     */
    public function fingerprint(): string
    {
        $assignments = [];
        foreach ($this->assignments as $assignment) {
            $assignments[] = self::fields($assignment->toArray(), self::FINGERPRINT_ASSIGNMENT_KEYS);
        }

        $unplaced = [];
        foreach ($this->unplaced as $unplacedEvent) {
            $unplaced[] = self::fields($unplacedEvent->toArray(), self::FINGERPRINT_UNPLACED_KEYS);
        }

        $violations = [];
        foreach ($this->violations as $violation) {
            $keys = self::FINGERPRINT_VIOLATION_KEYS[$violation::class] ?? null;
            // A class from outside the library has no key list to hold it to
            $violations[] = $keys === null ? $violation->toArray() : self::fields($violation->toArray(), $keys);
        }

        return self::FINGERPRINT_SCHEME . ':' . hash(
            'sha256',
            "tactician.repack.outcome.v1\n"
            . CanonicalEncoding::set($assignments)
            . CanonicalEncoding::set($unplaced)
            . CanonicalEncoding::set($violations)
        );
    }

    /**
     * The entries of a record that a fingerprint scheme names, and no
     * others.
     *
     * @param array<string, mixed> $record
     * @param list<string> $keys
     *
     * @return array<string, mixed>
     */
    private static function fields(array $record, array $keys): array
    {
        return array_intersect_key($record, array_flip($keys));
    }

    /**
     * Serialize to plain data: each list in its order, every entry as its
     * own toArray() gives it. Deterministic: the same input request
     * always produces the same array, whatever order its lists were in.
     * isBudgetExhausted() is not part of it.
     *
     * The first key is `format_version`, the version of this form (1). It
     * is written once, here: the records inside are written without the
     * one their own toArray() carries. It is another thing than the `v1`
     * of fingerprint(), which is the version of the fingerprint scheme;
     * the fingerprint covers the keys that scheme names and never this
     * one, so the two change independently.
     *
     * The last key is `metrics`, the figures an outcome is ranked by:
     * `unplaced` and `gap_slots` (two counts, which the lists above also
     * give), `lone_event_sessions` (the session indexes), `idle_sessions`
     * (a count) and `moved` (the event ids). The last three cannot be
     * worked out from the lists, which is why they are written. The
     * fingerprint does not cover them: they follow from the request and
     * the assignments, which it does cover.
     *
     * @return array{format_version: int, assignments: array<array{event_id: string, session: int, slot: int, kickoff: string|null}>, unplaced: array<array{event_id: string, reason: string, participant: string|null}>, violations: array<array<string, mixed>>, metrics: array{unplaced: int, gap_slots: int, lone_event_sessions: list<int>, idle_sessions: int, moved: list<string>}}
     */
    public function toArray(): array
    {
        $assignments = [];
        foreach ($this->assignments as $key => $assignment) {
            $record = $assignment->toArray();
            unset($record[PlainData::VERSION_KEY]);
            $assignments[$key] = $record;
        }

        $unplaced = [];
        foreach ($this->unplaced as $key => $unplacedEvent) {
            $record = $unplacedEvent->toArray();
            unset($record[PlainData::VERSION_KEY]);
            $unplaced[$key] = $record;
        }

        $violations = [];
        foreach ($this->violations as $key => $violation) {
            $record = $violation->toArray();
            unset($record[PlainData::VERSION_KEY]);
            $violations[$key] = $record;
        }

        return [
            PlainData::VERSION_KEY => PlainData::VERSION,
            'assignments' => $assignments,
            'unplaced' => $unplaced,
            'violations' => $violations,
            'metrics' => [
                'unplaced' => $this->getUnplacedCount(),
                'gap_slots' => $this->getGapSlotCount(),
                'lone_event_sessions' => array_values($this->loneEventSessions),
                'idle_sessions' => $this->idleSessions,
                'moved' => array_values($this->movedEventIds),
            ],
        ];
    }

    /**
     * @template T of RepackViolation
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    private function violationsOfClass(string $class): array
    {
        $matching = [];
        foreach ($this->violations as $violation) {
            if ($violation instanceof $class) {
                $matching[] = $violation;
            }
        }

        return $matching;
    }

    /**
     * @param array<mixed> $items
     * @param class-string $class
     *
     * @throws InvalidInputException When an item is not an instance of the class
     */
    private static function requireInstances(array $items, string $class, string $noun): void
    {
        foreach ($items as $key => $item) {
            if (!$item instanceof $class) {
                throw new InvalidInputException(sprintf(
                    'Every %s of a repack outcome must be a %s; the entry at key %s is of type %s',
                    $noun,
                    $class,
                    is_int($key) ? (string) $key : "'{$key}'",
                    get_debug_type($item)
                ));
            }
        }
    }
}
