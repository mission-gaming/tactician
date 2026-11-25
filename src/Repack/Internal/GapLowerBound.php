<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Repack\Internal;

/**
 * A number of gap slots that no placement of every movable event goes
 * below, worked out from the request alone: what lets the search for
 * fewer gap slots (GapSearch) stop when the schedule it holds has as few
 * as that, instead of searching to its limit for one it cannot find.
 *
 * The bound is the sum, over sessions, of what the events that can only
 * be played in that session must leave. An event can be played in a
 * session when one of its slots has room once the pins are counted and
 * neither participant is pinned there. The events that can be played in
 * one session alone are split into groups that share participants; a
 * group counts only when its participants have nothing else in that
 * session, no pin and no event that may go there or elsewhere, because
 * then their events in the session are exactly the group's, whatever
 * else the schedule does. For such a group the bound is:
 *
 * - by parity: a participant whose events in a session are contiguous
 *   plays as many even slots as odd ones, give or take one, and each gap
 *   slot can change that by one more. So where every participant of the
 *   group has an even number of its events and the group has an odd
 *   number of events (three participants who all meet each other, for
 *   one), no placement is without a gap: at least one gap slot. Every
 *   other group can be coloured so that the parities say nothing more,
 *   which is why that is all they give;
 * - on slots that hold one event: the events are a sequence, and a
 *   participant is without a gap only where its events follow each other
 *   in it. Two events in a row share one participant, or two when they
 *   are between the same two, so a group of m events among v
 *   participants, with p events that repeat a pairing, has at least
 *   m - v + 1 - p gap slots;
 * - exactly, where the group is small (EXACT_EVENTS events or fewer):
 *   the fewest gap slots any placement of the group alone on a session
 *   of its length has, found by a search that leaves out the slots no
 *   event of the group plays (a slot nobody plays inside a participant's
 *   run is a gap slot for that participant, so a placement without such
 *   slots is never worse). That search has a work limit of its own, and
 *   one it does not finish gives the two bounds above.
 *
 * The other participants and the pins of the session take room the
 * group could have used; leaving that out only makes the bound smaller,
 * so it is still a bound. A placement that may leave events out has no
 * such bound (leaving one out can break a group), and the search asks
 * for one only when every event is placed.
 *
 * Everything here is a function of the request in the repacker's order,
 * and deterministic.
 *
 * @internal
 */
final class GapLowerBound
{
    /**
     * The most events a group may have for the exact search to be tried
     * on it.
     */
    public const int EXACT_EVENTS = 12;

    /**
     * How many slot checks the exact searches of one call may make
     * together, about a tenth of the work limit of the search for fewer
     * gap slots; past it a group has the bounds that need no search.
     */
    public const int WORK_LIMIT = 20_000;

    /** @var list<array{int, int}> */
    private array $events = [];

    private int $slots = 0;

    private int $capacity = 1;

    /** @var array<int, int> Slot => events in it */
    private array $load = [];

    /** @var array<int, array<int, true>> Slot => participants in it */
    private array $busy = [];

    /** @var array<int, int> Participant => lowest slot it plays */
    private array $low = [];

    /** @var array<int, int> Participant => highest slot it plays */
    private array $high = [];

    /** @var array<int, int> Participant => events of it placed */
    private array $placed = [];

    /** @var array<int, int> Participant => events of it still to place */
    private array $remaining = [];

    private int $best = PHP_INT_MAX;

    private int $floor = 0;

    private int $work = 0;

    private bool $stopped = false;

    private function __construct(private readonly int $limit) {}

    /**
     * @param array<int, array{int, int}> $edges Event index => participant index pair, in the
     *                                           repacker's order
     * @param array<int, array<int, array<int>>> $pinSlots Session => participant => pinned slots
     * @param array<int, array<int, int>> $pinCounts Session => slot => pinned event count
     * @param array<int, int> $slotCounts Session => slot count
     * @param int|null $workLimit Slot checks the exact searches may make; WORK_LIMIT when null (the
     *                            unit tests pass a small one to see a search stop at it)
     *
     * @return int Gap slots that every placement of all the events has, counted as the audit counts them
     */
    public static function of(
        array $edges,
        array $pinSlots,
        array $pinCounts,
        array $slotCounts,
        int $capacityPerSlot,
        ?int $workLimit = null
    ): int {
        /** @var array<int, array<int, array<int, true>>> $pinnedAt Session => slot => participants */
        $pinnedAt = [];
        /** @var array<int, array<int, true>> $open Session => participants that have a pin there, or
         *                                    an event that may be played there or elsewhere */
        $open = [];
        foreach ($pinSlots as $session => $byParticipant) {
            foreach ($byParticipant as $pid => $slots) {
                foreach ($slots as $slot) {
                    $pinnedAt[$session][$slot][$pid] = true;
                }
                if ($slots !== []) {
                    $open[$session][$pid] = true;
                }
            }
        }

        /** @var array<int, list<int>> $only Session => the events that can be played there alone */
        $only = [];
        foreach ($edges as $eventIndex => [$a, $b]) {
            $sessions = [];
            foreach ($slotCounts as $session => $slotCount) {
                for ($slot = 0; $slot < $slotCount; ++$slot) {
                    if (
                        $capacityPerSlot - ($pinCounts[$session][$slot] ?? 0) > 0
                        && !isset($pinnedAt[$session][$slot][$a])
                        && !isset($pinnedAt[$session][$slot][$b])
                    ) {
                        $sessions[] = $session;

                        break;
                    }
                }
            }
            if (count($sessions) === 1) {
                $only[$sessions[0]][] = $eventIndex;
            } else {
                foreach ($sessions as $session) {
                    $open[$session][$a] = true;
                    $open[$session][$b] = true;
                }
            }
        }

        $search = new self($workLimit ?? self::WORK_LIMIT);
        $bound = 0;
        foreach ($only as $session => $eventIndexes) {
            foreach (self::groups($eventIndexes, $edges) as $group) {
                foreach ($group as $eventIndex) {
                    foreach ($edges[$eventIndex] as $pid) {
                        if (isset($open[$session][$pid])) {
                            continue 3;
                        }
                    }
                }
                $bound += $search->ofGroup(
                    array_map(static fn(int $eventIndex): array => $edges[$eventIndex], $group),
                    $slotCounts[$session],
                    $capacityPerSlot
                );
            }
        }

        return $bound;
    }

    /**
     * The events split into groups that share participants, each group in
     * ascending event order and the groups by their first event.
     *
     * @param list<int> $eventIndexes Ascending
     * @param array<int, array{int, int}> $edges
     *
     * @return list<list<int>>
     */
    private static function groups(array $eventIndexes, array $edges): array
    {
        /** @var array<int, int> $parent */
        $parent = [];
        $find = static function (int $pid) use (&$parent): int {
            while ($parent[$pid] !== $pid) {
                $parent[$pid] = $parent[$parent[$pid]];
                $pid = $parent[$pid];
            }

            return $pid;
        };
        foreach ($eventIndexes as $eventIndex) {
            [$a, $b] = $edges[$eventIndex];
            $parent[$a] ??= $a;
            $parent[$b] ??= $b;
            $parent[$find($a)] = $find($b);
        }

        $groups = [];
        foreach ($eventIndexes as $eventIndex) {
            $groups[$find($edges[$eventIndex][0])][] = $eventIndex;
        }

        return array_values($groups);
    }

    /**
     * @param list<array{int, int}> $events One group, which shares participants with no other event
     *                                      of the session
     */
    private function ofGroup(array $events, int $slotCount, int $capacityPerSlot): int
    {
        $degree = [];
        $pairs = [];
        foreach ($events as [$a, $b]) {
            $degree[$a] = ($degree[$a] ?? 0) + 1;
            $degree[$b] = ($degree[$b] ?? 0) + 1;
            $pairs[min($a, $b) . ':' . max($a, $b)] = true;
        }

        $even = array_all($degree, static fn(int $count): bool => $count % 2 === 0);
        $bound = $even && count($events) % 2 === 1 ? 1 : 0;
        if ($capacityPerSlot === 1) {
            $bound = max($bound, count($events) - count($degree) + 1 - (count($events) - count($pairs)));
        }

        if (count($events) > self::EXACT_EVENTS || $this->work > $this->limit) {
            return $bound;
        }

        return $this->exact($events, min(count($events), $slotCount), $capacityPerSlot, $bound) ?? $bound;
    }

    /**
     * The fewest gap slots of the group placed alone on that many slots,
     * or null when the search stopped at its work limit or found no
     * placement.
     *
     * @param list<array{int, int}> $events
     */
    private function exact(array $events, int $slots, int $capacityPerSlot, int $floor): ?int
    {
        $this->events = $events;
        $this->slots = $slots;
        $this->capacity = $capacityPerSlot;
        $this->load = array_fill(0, $slots, 0);
        $this->busy = [];
        $this->low = [];
        $this->high = [];
        $this->placed = [];
        $this->remaining = [];
        foreach ($events as [$a, $b]) {
            $this->remaining[$a] = ($this->remaining[$a] ?? 0) + 1;
            $this->remaining[$b] = ($this->remaining[$b] ?? 0) + 1;
        }
        $this->best = PHP_INT_MAX;
        $this->floor = $floor;
        $this->stopped = false;

        $this->place(0, -1);

        return $this->stopped || $this->best === PHP_INT_MAX ? null : $this->best;
    }

    /**
     * @param int $at The event to place next
     * @param int $top The highest slot played so far, -1 for none
     */
    private function place(int $at, int $top): void
    {
        if (!isset($this->events[$at])) {
            $gaps = 0;
            foreach ($this->placed as $pid => $count) {
                $gaps += $this->high[$pid] - $this->low[$pid] + 1 - $count;
            }
            $this->best = min($this->best, $gaps);

            return;
        }

        // What the placed events already leave: the slots inside a
        // participant's run that its events still to come cannot all fill
        $leaves = 0;
        foreach ($this->placed as $pid => $count) {
            $leaves += max(0, $this->high[$pid] - $this->low[$pid] + 1 - $count - $this->remaining[$pid]);
        }
        if ($leaves >= $this->best) {
            return;
        }

        // A slot below the highest one played that nobody plays has to be
        // filled by an event still to come, or the placement has a slot
        // it does not need
        $empty = 0;
        for ($slot = 0; $slot < $top; ++$slot) {
            if ($this->load[$slot] === 0) {
                ++$empty;
            }
        }
        if ($empty > count($this->events) - $at) {
            return;
        }

        [$a, $b] = $this->events[$at];
        for ($slot = 0; $slot < $this->slots; ++$slot) {
            if ($this->stopped || $this->best <= $this->floor) {
                return;
            }
            if (++$this->work > $this->limit) {
                $this->stopped = true;

                return;
            }
            if ($this->load[$slot] >= $this->capacity || isset($this->busy[$slot][$a]) || isset($this->busy[$slot][$b])) {
                continue;
            }

            $saved = [$this->low[$a] ?? null, $this->high[$a] ?? null, $this->low[$b] ?? null, $this->high[$b] ?? null];
            ++$this->load[$slot];
            foreach ([$a, $b] as $pid) {
                $this->busy[$slot][$pid] = true;
                $this->low[$pid] = min($this->low[$pid] ?? $slot, $slot);
                $this->high[$pid] = max($this->high[$pid] ?? $slot, $slot);
                $this->placed[$pid] = ($this->placed[$pid] ?? 0) + 1;
                --$this->remaining[$pid];
            }

            $this->place($at + 1, max($top, $slot));

            --$this->load[$slot];
            foreach ([$a, $b] as $pid) {
                unset($this->busy[$slot][$pid]);
                ++$this->remaining[$pid];
                if (--$this->placed[$pid] === 0) {
                    unset($this->placed[$pid], $this->low[$pid], $this->high[$pid]);
                }
            }
            if (isset($this->placed[$a])) {
                $this->low[$a] = $saved[0] ?? $this->low[$a];
                $this->high[$a] = $saved[1] ?? $this->high[$a];
            }
            if (isset($this->placed[$b])) {
                $this->low[$b] = $saved[2] ?? $this->low[$b];
                $this->high[$b] = $saved[3] ?? $this->high[$b];
            }
        }
    }
}
