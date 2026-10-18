<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Repack\Internal;

use MissionGaming\Tactician\Repack\RepackObjective;

/**
 * The figures of a schedule that an outcome reports and an objective
 * ranks by: gap slots, lone-event sessions, idle sessions and moved
 * events, each counted from where the events are and from nothing else.
 *
 * One instance is made for a request (the participants of every movable
 * event, the pinned events, the current positions), and is then asked
 * about any number of schedules of it. Every figure is counted afresh for
 * each schedule; nothing is kept between two questions.
 *
 * An event is a list of participants here, of whatever length: nothing
 * below reads a first and a second one.
 *
 * @internal
 */
final readonly class PlacementMetrics
{
    /** @var array<int, int> Session => pinned events in it */
    private array $pinnedEvents;

    /** @var array<int, array<int, list<int>>> Participant => session => its pinned slots */
    private array $pinnedSlots;

    /**
     * @param array<int, list<int>> $participantsOf Movable event index => its participant indexes
     * @param list<array{list<int>, int, int}> $pins Each pinned event: its participant indexes,
     *                                               its session and its slot
     * @param array<int, array{int, int}> $current Movable event index => its current [session,
     *                                             slot], for the events that state one
     */
    public function __construct(
        private array $participantsOf,
        array $pins,
        private array $current
    ) {
        $pinnedEvents = [];
        $pinnedSlots = [];
        foreach ($pins as [$participants, $session, $slot]) {
            $pinnedEvents[$session] = ($pinnedEvents[$session] ?? 0) + 1;
            foreach ($participants as $pid) {
                $pinnedSlots[$pid][$session][] = $slot;
            }
        }
        $this->pinnedEvents = $pinnedEvents;
        $this->pinnedSlots = $pinnedSlots;
    }

    /**
     * The gap slots, as the audit counts them: for each participant and
     * session with a movable event of the participant in it, the slots
     * between its first and its last there (pins included) that hold none
     * of its events.
     *
     * @param array<int, array{int, int}> $positions Placed movable event index => [session, slot]
     */
    public function gapSlots(array $positions): int
    {
        $gapSlots = 0;
        foreach ($this->gaps($positions) as $bySession) {
            $gapSlots += array_sum($bySession);
        }

        return $gapSlots;
    }

    /**
     * Where the gaps are: for each participant with one, the sessions it
     * has a gap in and how many gap slots it has there.
     *
     * @param array<int, array{int, int}> $positions Placed movable event index => [session, slot]
     *
     * @return array<int, array<int, int>> Participant => session => gap slots, one or more
     */
    public function gaps(array $positions): array
    {
        /** @var array<int, array<int, array<int, true>>> $held Participant => session => slots */
        $held = [];
        foreach ($positions as $eventIndex => [$session, $slot]) {
            foreach ($this->participantsOf[$eventIndex] as $pid) {
                $held[$pid][$session][$slot] = true;
            }
        }

        $gaps = [];
        foreach ($held as $pid => $bySession) {
            foreach ($bySession as $session => $slots) {
                foreach ($this->pinnedSlots[$pid][$session] ?? [] as $slot) {
                    $slots[$slot] = true;
                }
                [$first, $last] = self::span(array_keys($slots));
                $gapSlots = $last - $first + 1 - count($slots);
                if ($gapSlots > 0) {
                    $gaps[$pid][$session] = $gapSlots;
                }
            }
        }

        return $gaps;
    }

    /**
     * The sessions that hold exactly one event, pinned events counted,
     * where that event is a movable one. Ascending.
     *
     * @param array<int, array{int, int}> $positions Placed movable event index => [session, slot]
     *
     * @return list<int>
     */
    public function loneEventSessions(array $positions): array
    {
        /** @var array<int, int> $movable Session => movable events in it */
        $movable = [];
        foreach ($positions as [$session]) {
            $movable[$session] = ($movable[$session] ?? 0) + 1;
        }

        $lone = [];
        foreach ($movable as $session => $count) {
            if ($count === 1 && !isset($this->pinnedEvents[$session])) {
                $lone[] = $session;
            }
        }
        sort($lone);

        return $lone;
    }

    /**
     * The idle sessions of each participant that has one: the sessions
     * strictly between its first and its last session with an event in
     * which it has none. Pinned events count as events; a participant
     * with no placed movable event is left out.
     *
     * @param array<int, array{int, int}> $positions Placed movable event index => [session, slot]
     *
     * @return array<int, list<int>> Participant => its idle sessions, ascending
     */
    public function idleSessionsByParticipant(array $positions): array
    {
        /** @var array<int, array<int, true>> $active Participant => sessions with an event of it */
        $active = [];
        foreach ($positions as $eventIndex => [$session]) {
            foreach ($this->participantsOf[$eventIndex] as $pid) {
                $active[$pid][$session] = true;
            }
        }

        $idle = [];
        foreach ($active as $pid => $sessions) {
            foreach (array_keys($this->pinnedSlots[$pid] ?? []) as $session) {
                $sessions[$session] = true;
            }
            [$first, $last] = self::span(array_keys($sessions));
            if ($last - $first + 1 === count($sessions)) {
                continue;
            }
            for ($session = $first + 1; $session < $last; ++$session) {
                if (!isset($sessions[$session])) {
                    $idle[$pid][] = $session;
                }
            }
        }

        return $idle;
    }

    /**
     * How many idle sessions there are, summed over the participants.
     *
     * @param array<int, array{int, int}> $positions Placed movable event index => [session, slot]
     */
    public function idleSessions(array $positions): int
    {
        $count = 0;
        foreach ($this->idleSessionsByParticipant($positions) as $sessions) {
            $count += count($sessions);
        }

        return $count;
    }

    /**
     * The placed events that state a current position and are not at it,
     * ascending by index.
     *
     * @param array<int, array{int, int}> $positions Placed movable event index => [session, slot]
     *
     * @return list<int>
     */
    public function moved(array $positions): array
    {
        $moved = [];
        foreach ($this->current as $eventIndex => $position) {
            if (isset($positions[$eventIndex]) && $positions[$eventIndex] !== $position) {
                $moved[] = $eventIndex;
            }
        }
        sort($moved);

        return $moved;
    }

    /**
     * The lowest and the highest of some indexes (of slots, or of
     * sessions).
     *
     * @param array<int> $indexes At least one
     *
     * @return array{int, int}
     */
    public static function span(array $indexes): array
    {
        $first = PHP_INT_MAX;
        $last = 0;
        foreach ($indexes as $index) {
            $first = min($first, $index);
            $last = max($last, $index);
        }

        return [$first, $last];
    }

    /**
     * What the objectives make of a schedule: one figure for each, in
     * their order, lower being better. Two schedules are compared on it
     * as PHP compares two lists of equal length, the first figure first.
     *
     * @param array<int, array{int, int}> $positions Placed movable event index => [session, slot]
     * @param list<RepackObjective> $objectives
     *
     * @return list<int>
     */
    public function score(array $positions, array $objectives): array
    {
        $score = [];
        foreach ($objectives as $objective) {
            $score[] = match ($objective) {
                RepackObjective::LeastChurn => count($this->moved($positions)),
                RepackObjective::FewestLoneEventSessions => count($this->loneEventSessions($positions)),
                RepackObjective::FewestIdleSessions => $this->idleSessions($positions),
            };
        }

        return $score;
    }
}
