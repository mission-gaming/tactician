<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Repack\Internal;

/**
 * The last placement step: give a position to events the other phases
 * left unplaced, by moving events that are already placed.
 *
 * The repacker calls it only when an event is still unplaced after the
 * final sweep, so a run that places everything never reaches it. It is
 * given every unplaced event, the ones dropped for an over-capacity
 * participant included: a participant's events can never take more
 * positions than it has free, so placing a dropped event only means
 * another of that participant's events is the one that does not fit.
 *
 * Three moves, in rounds, while a round places at least one event:
 *
 * - Direct: an event takes a position with room where neither of its
 *   participants is busy or pinned. This is not a search and spends no
 *   step.
 * - Alternating path (an ejection chain): the event takes a position
 *   where it collides with exactly one placed event, which is lifted and
 *   takes a position of its own the same way, and so on until an event
 *   lands where it collides with nothing. A collision is a participant
 *   both events share, or the last free place of a full position. A
 *   two-slot alternating chain (a Kempe chain) is one such path. Where
 *   both participants are in the way, both events are lifted and the
 *   path branches. Each event is lifted at most once per search, so a
 *   search expands at most one node per placed event. A search is not
 *   started for an event one of whose participants already plays every
 *   position it is not pinned at: the event of that participant that
 *   would be lifted has nowhere to go.
 * - Exchange: an unplaced event takes the position of one placed event
 *   it collides with, that event leaves the schedule, and the exchange
 *   stands only if some unplaced event (the one that left included) can
 *   then be placed by an alternating path, or, one level deeper, by a
 *   further exchange (EXCHANGE_DEPTH). One in, one out and one more in:
 *   one more event placed. It is searched only when no alternating path
 *   placed anything in the round, and only while fewer events are placed
 *   than the ceiling (ceiling()) allows, and it tries at most
 *   EXCHANGE_NODE_LIMIT exchanges per search.
 *
 * Every move is applied as it is searched and recorded in a journal, and
 * taken back from the journal where it fails, so every state keeps the
 * rules the final schedule keeps: no participant in two events at one
 * position, no event at a position where one of its participants is
 * pinned, no position over capacity. Pinned events never move. A move
 * that does not place one more event in the end is taken back entirely,
 * so the step changes nothing it cannot improve.
 *
 * Each alternating-path node and each exchange spends one step from the
 * budget it is given; with no step left the searches stop and only
 * direct moves are made. The exchange limit is a limit of the search's
 * own, like the depth, and does not depend on the budget.
 *
 * The whole step also stops searching after WORK_LIMIT position checks
 * (a check is one look at whether an event fits a position; each costs
 * well under a microsecond). The ceiling cannot prove of every request
 * that nothing more fits, and where it cannot, the exchange search
 * would otherwise run to its limits on every round: seconds on requests
 * of a few hundred events that 0.2.2 repacked in milliseconds. The
 * limit is fixed, like the others, so a larger budget gives the same
 * outcome and the budget flag is not set when it is reached. Direct
 * moves go on after it, so an event left unplaced as NoSlotAvailable
 * still has no position with room and both participants free. It is
 * about 25 times what the largest complete round robin of the tests (40
 * participants) and the small corpus need, and on some requests it
 * leaves an event unplaced that a longer search would have placed.
 * A lifted event
 * tries the positions of its own session first, in slot order, and then
 * the rest of the grid in grid order, so the session loads the planner
 * chose change as little as they can; the gaps the moves leave are the
 * audit's to report.
 *
 * All ids are the orchestrator's dense integer indexes; index order is
 * caller-id order, so index tie-breaks are id tie-breaks.
 *
 * @internal
 */
final class LeftoverRecolourer
{
    /**
     * How many exchanges one branch may chain before an alternating path
     * must place an event.
     */
    private const int EXCHANGE_DEPTH = 2;

    /**
     * How many exchanges one search for an exchange may try: a limit of
     * the search's own, which the step budget does not lift.
     */
    private const int EXCHANGE_NODE_LIMIT = 2_000;

    /**
     * How many position checks the whole step may make before it starts
     * no further search: a limit of the step's own, which the step budget
     * does not lift (see the class docblock). About a sixth of a second
     * on the machine it was measured on.
     */
    public const int WORK_LIMIT = 500_000;

    private int $exchangeNodes = 0;

    /** Position checks made so far, against the work limit */
    private int $work = 0;

    /** @var array<int, array{int, int}> */
    private array $edges = [];

    /** @var list<array{int, int}> Position index => [session, slot], in grid order */
    private array $positionList = [];

    /** @var array<int, list<int>> Session => its position indexes, in slot order */
    private array $positionsOfSession = [];

    /** @var array<int, int> Position index => places left */
    private array $room = [];

    /** @var array<int, array<int, int>> Position index => participant => movable event there */
    private array $occupant = [];

    /** @var array<int, array<int, true>> Position index => participants pinned there */
    private array $pinned = [];

    /** @var array<int, int> Placed event => position index */
    private array $positionOf = [];

    /** @var array<int, int> Participant => positions it is not pinned at */
    private array $unpinned = [];

    /** @var array<int, int> Participant => placed movable events */
    private array $load = [];

    /** @var array<int, true> Events lifted, or fixed in place, during the current search */
    private array $lifted = [];

    /**
     * @var list<array{bool, int, int}> Every placement change since the last committed move:
     *                                  [put (true) or removed (false), event, position]
     */
    private array $journal = [];

    /**
     * @param int $workLimit Position checks after which no further search
     *                       starts; the repacker always passes WORK_LIMIT, and
     *                       the unit tests pass a smaller one to see the step
     *                       stop at it
     */
    public function __construct(
        private readonly StepBudget $budget,
        private readonly int $workLimit = self::WORK_LIMIT
    ) {}

    /**
     * @param array<int, array{int, int}> $edges Event index => participant index pair
     * @param array<int, array{int, int}> $positions Placed event index => [session, slot]; updated in place
     * @param array<int> $leftovers Event indexes with no position
     * @param array<int, array<int, array<int>>> $pinSlots Session => participant => pinned slots
     * @param array<int, array<int, int>> $pinCounts Session => slot => pinned event count
     * @param array<int, int> $slotCounts Session => slot count
     * @param int $leftOutAtLeast How many of the events no placement can hold, at
     *                            least (LoadPlan::$leftOutAtLeast; see ceiling())
     *
     * @return list<int> The events that are still unplaced, ascending
     */
    public function place(
        array $edges,
        array &$positions,
        array $leftovers,
        array $pinSlots,
        array $pinCounts,
        array $slotCounts,
        int $capacityPerSlot,
        int $leftOutAtLeast = 0
    ): array {
        $this->edges = $edges;
        $this->positionList = [];
        $this->positionsOfSession = [];
        $this->room = [];
        $this->occupant = [];
        $this->pinned = [];
        $this->positionOf = [];
        $this->work = 0;

        /** @var array<int, array<int, int>> $indexOf */
        $indexOf = [];
        foreach ($slotCounts as $session => $slotCount) {
            for ($slot = 0; $slot < $slotCount; ++$slot) {
                $index = count($this->positionList);
                $indexOf[$session][$slot] = $index;
                $this->positionList[] = [$session, $slot];
                $this->positionsOfSession[$session][] = $index;
                $this->room[$index] = $capacityPerSlot - ($pinCounts[$session][$slot] ?? 0);
            }
        }
        foreach ($pinSlots as $session => $byParticipant) {
            foreach ($byParticipant as $pid => $slots) {
                foreach ($slots as $slot) {
                    $this->pinned[$indexOf[$session][$slot]][$pid] = true;
                }
            }
        }
        $this->unpinned = [];
        $this->load = [];
        foreach ($edges as [$a, $b]) {
            $this->unpinned[$a] = count($this->positionList);
            $this->unpinned[$b] = count($this->positionList);
        }
        foreach ($this->pinned as $pids) {
            foreach (array_keys($pids) as $pid) {
                if (isset($this->unpinned[$pid])) {
                    --$this->unpinned[$pid];
                }
            }
        }
        foreach ($positions as $eventIndex => [$session, $slot]) {
            $this->put($eventIndex, $indexOf[$session][$slot]);
        }

        $remaining = array_values($leftovers);
        sort($remaining);
        $ceiling = $this->ceiling($remaining, $leftOutAtLeast);
        do {
            $before = count($remaining);
            $remaining = $this->placeEach($remaining);
            if (count($remaining) === $before && $remaining !== [] && count($this->positionOf) < $ceiling) {
                $remaining = $this->exchangeOne($remaining);
            }
        } while (count($remaining) < $before && $remaining !== []);

        // An exchange takes an event out of the schedule, so the positions
        // are written afresh
        $positions = [];
        foreach ($this->positionOf as $eventIndex => $index) {
            $positions[$eventIndex] = $this->positionList[$index];
        }
        ksort($positions);

        return $remaining;
    }

    /**
     * A number of placed events no placement can exceed, the smallest of
     * four bounds. All the events less the ones no placement can hold
     * ($leftOutAtLeast, from the planner: every over-capacity participant
     * must leave out its shortfall, and an event between two of them
     * counts for both; the planner gives its own drop count only when its
     * search for those shared events is known to have found a largest
     * set, and a bound that needs no search otherwise, so this is never
     * more than the true number). Half the sum, over the participants, of the smaller of
     * its event count and its unpinned positions. The places the grid
     * has. And, position by position, the smaller of the position's
     * places and the events it could hold at most: an event takes two
     * participants of one group of participants linked by events (a
     * connected component), so a position holds at most half of each
     * group's participants not pinned there, rounded down. The last bound
     * is the one that sees an odd group: a single round robin of an odd
     * number of participants always leaves one of them out of every
     * position. An exchange is searched for only below the ceiling, so a
     * schedule that already places as many events as can be placed costs
     * no exchange search.
     *
     * @param list<int> $remaining
     */
    private function ceiling(array $remaining, int $leftOutAtLeast): int
    {
        $events = [...array_keys($this->positionOf), ...$remaining];

        $count = [];
        /** @var array<int, int> $root Participant => a participant closer to its group's root */
        $root = [];
        $find = static function (int $pid) use (&$root): int {
            while ($root[$pid] !== $pid) {
                $root[$pid] = $root[$root[$pid]];
                $pid = $root[$pid];
            }

            return $pid;
        };
        foreach ($events as $eventIndex) {
            [$a, $b] = $this->edges[$eventIndex];
            $count[$a] = ($count[$a] ?? 0) + 1;
            $count[$b] = ($count[$b] ?? 0) + 1;
            $root[$a] ??= $a;
            $root[$b] ??= $b;
            $ra = $find($a);
            $rb = $find($b);
            if ($ra !== $rb) {
                $root[max($ra, $rb)] = min($ra, $rb);
            }
        }

        $ends = 0;
        /** @var array<int, int> $groupSize Group root => participants */
        $groupSize = [];
        foreach ($count as $pid => $eventCount) {
            $ends += min($eventCount, $this->unpinned[$pid]);
            $group = $find($pid);
            $groupSize[$group] = ($groupSize[$group] ?? 0) + 1;
        }

        $placedAt = array_count_values($this->positionOf);
        $byPositions = 0;
        foreach (array_keys($this->positionList) as $index) {
            $free = $groupSize;
            foreach (array_keys($this->pinned[$index] ?? []) as $pid) {
                if (isset($count[$pid])) {
                    --$free[$find($pid)];
                }
            }
            $pairs = 0;
            foreach ($free as $participants) {
                $pairs += intdiv($participants, 2);
            }
            $byPositions += min($pairs, $this->room[$index] + ($placedAt[$index] ?? 0));
        }

        return min(
            count($events) - $leftOutAtLeast,
            intdiv($ends, 2),
            count($this->positionOf) + array_sum($this->room),
            $byPositions
        );
    }

    /**
     * One round of direct moves and alternating paths over the unplaced
     * events, in index order.
     *
     * @param list<int> $remaining Ascending
     *
     * @return list<int> The events still unplaced, ascending
     */
    private function placeEach(array $remaining): array
    {
        $stillLeft = [];
        foreach ($remaining as $eventIndex) {
            // Each event's search starts from a committed state
            $this->journal = [];
            if ($this->placeDirectly($eventIndex)) {
                continue;
            }
            // The search asks the budget for its first step itself, so a
            // budget that is already spent stops it there and says so
            if ($this->hasRoomAnywhere()) {
                $this->lifted = [$eventIndex => true];
                if ($this->insert($eventIndex, null)) {
                    continue;
                }
            }
            $stillLeft[] = $eventIndex;
        }

        return $stillLeft;
    }

    /**
     * Try exchanges until one places one more event.
     *
     * @param list<int> $remaining Ascending
     *
     * @return list<int> The events still unplaced, ascending; unchanged when no exchange worked
     */
    private function exchangeOne(array $remaining): array
    {
        if (!$this->hasRoomAnywhere()) {
            // Every alternating path ends at a free place, and there is none
            return $remaining;
        }

        $this->exchangeNodes = 0;
        $this->journal = [];

        return $this->exchange($remaining, [], self::EXCHANGE_DEPTH) ?? $remaining;
    }

    /**
     * One level of the exchange search: an unplaced event takes the
     * place of a placed one, and then either an alternating path places
     * one of the events waiting, or (below the depth limit) a further
     * exchange is tried. Events an exchange brought in are not taken out
     * again on the same branch.
     *
     * @param list<int> $remaining Ascending
     * @param array<int, true> $fixed Events this branch brought in
     *
     * @return list<int>|null The events still unplaced after a branch that placed one more, or null
     */
    private function exchange(array $remaining, array $fixed, int $depth): ?array
    {
        foreach ($remaining as $incoming) {
            foreach (array_keys($this->positionList) as $index) {
                foreach ($this->exchangeCandidates($incoming, $index) as $outgoing) {
                    if (isset($fixed[$outgoing])) {
                        continue;
                    }
                    if (
                        ++$this->exchangeNodes > self::EXCHANGE_NODE_LIMIT
                        || $this->work > $this->workLimit
                        || !$this->budget->consume()
                    ) {
                        return null;
                    }

                    $mark = count($this->journal);
                    $this->remove($outgoing);
                    $this->put($incoming, $index);
                    $nowFixed = $fixed + [$incoming => true];

                    $waiting = array_values(array_filter(
                        $remaining,
                        static fn(int $eventIndex): bool => $eventIndex !== $incoming
                    ));
                    $waiting[] = $outgoing;
                    sort($waiting);
                    foreach ($waiting as $candidate) {
                        $this->lifted = $nowFixed + [$candidate => true];
                        if ($this->placeDirectly($candidate) || $this->insert($candidate, null)) {
                            return array_values(array_filter(
                                $waiting,
                                static fn(int $eventIndex): bool => $eventIndex !== $candidate
                            ));
                        }
                    }

                    if ($depth > 1) {
                        $deeper = $this->exchange($waiting, $nowFixed, $depth - 1);
                        if ($deeper !== null) {
                            return $deeper;
                        }
                    }

                    $this->rollBack($mark);
                }
            }
        }

        return null;
    }

    /**
     * The placed events the event could take the position of: the one it
     * collides with through a participant, or, at a full position where
     * it collides with nobody, each event there in index order.
     *
     * @return list<int>
     */
    private function exchangeCandidates(int $eventIndex, int $index): array
    {
        $blocking = $this->blocking($eventIndex, $index);
        if ($blocking === null || count($blocking) > 1) {
            return [];
        }
        if ($blocking !== []) {
            return $blocking;
        }
        if ($this->room[$index] > 0) {
            // Not reached: a free place with nobody in the way is a direct
            // move, which the round before the exchange already made
            return [];
        }

        $there = array_values(array_unique($this->occupant[$index] ?? []));
        sort($there);

        return $there;
    }

    private function placeDirectly(int $eventIndex): bool
    {
        foreach (array_keys($this->positionList) as $index) {
            if ($this->room[$index] > 0 && $this->blocking($eventIndex, $index) === []) {
                $this->put($eventIndex, $index);

                return true;
            }
        }

        return false;
    }

    /**
     * Find a position for an event that has none, lifting at most one
     * placed event at each step of the path.
     *
     * @param int|null $fromSession The session the event was lifted from, null for an unplaced one
     */
    private function insert(int $eventIndex, ?int $fromSession): bool
    {
        if ($this->work > $this->workLimit) {
            return false;
        }
        if ($this->hasSaturatedParticipant($eventIndex)) {
            // A participant whose every unpinned position already holds one
            // of its events is in the way wherever the event goes, and the
            // event of its that is lifted has nowhere to go: no path exists
            return false;
        }
        if (!$this->budget->consume()) {
            return false;
        }

        $order = $this->candidatePositions($fromSession);

        // A position where it collides with nothing ends the path at once
        foreach ($order as $index) {
            if ($this->room[$index] > 0 && $this->blocking($eventIndex, $index) === []) {
                $this->put($eventIndex, $index);

                return true;
            }
        }

        foreach ($order as $index) {
            $lift = $this->liftFor($eventIndex, $index);
            if ($lift === null) {
                continue;
            }

            $mark = count($this->journal);
            $this->lifted[$lift] = true;
            $this->remove($lift);
            $this->put($eventIndex, $index);
            if ($this->insert($lift, $this->positionList[$index][0])) {
                return true;
            }
            $this->rollBack($mark);
        }

        // Where both participants are in the way, both events are lifted,
        // and the path branches: each of them needs a position of its own
        foreach ($order as $index) {
            $blocking = $this->blocking($eventIndex, $index);
            if (
                $blocking === null
                || count($blocking) !== 2
                || isset($this->lifted[$blocking[0]])
                || isset($this->lifted[$blocking[1]])
            ) {
                continue;
            }

            $mark = count($this->journal);
            $session = $this->positionList[$index][0];
            $this->lifted[$blocking[0]] = true;
            $this->lifted[$blocking[1]] = true;
            $this->remove($blocking[0]);
            $this->remove($blocking[1]);
            $this->put($eventIndex, $index);
            if ($this->insert($blocking[0], $session) && $this->insert($blocking[1], $session)) {
                return true;
            }
            $this->rollBack($mark);
        }

        return false;
    }

    /**
     * Take back every placement change made since the journal held the
     * given number of entries, latest first.
     */
    private function rollBack(int $mark): void
    {
        while (count($this->journal) > $mark) {
            [$put, $eventIndex, $index] = $this->journal[count($this->journal) - 1];
            array_pop($this->journal);
            if ($put) {
                $this->remove($eventIndex, false);
            } else {
                $this->put($eventIndex, $index, false);
            }
        }
    }

    /**
     * The one placed event to lift so that the event can take the
     * position, or null when that takes more than one, or one already
     * lifted, or a pin is in the way.
     */
    private function liftFor(int $eventIndex, int $index): ?int
    {
        $blocking = $this->blocking($eventIndex, $index);
        if ($blocking === null || count($blocking) > 1) {
            return null;
        }

        if ($blocking === []) {
            // Full, and nobody in it shares a participant: lift one of
            // its events, the first in index order not lifted yet
            $there = array_values(array_unique($this->occupant[$index] ?? []));
            sort($there);
            foreach ($there as $candidate) {
                if (!isset($this->lifted[$candidate])) {
                    return $candidate;
                }
            }

            return null;
        }

        return isset($this->lifted[$blocking[0]]) ? null : $blocking[0];
    }

    /**
     * The placed events the event would collide with at the position
     * through a shared participant, or null when one of its participants
     * is pinned there.
     *
     * @return list<int>|null
     */
    private function blocking(int $eventIndex, int $index): ?array
    {
        ++$this->work;
        $blocking = [];
        foreach ($this->edges[$eventIndex] as $pid) {
            if (isset($this->pinned[$index][$pid])) {
                return null;
            }
            $other = $this->occupant[$index][$pid] ?? null;
            if ($other !== null && !in_array($other, $blocking, true)) {
                $blocking[] = $other;
            }
        }

        return $blocking;
    }

    private function hasSaturatedParticipant(int $eventIndex): bool
    {
        foreach ($this->edges[$eventIndex] as $pid) {
            if (($this->load[$pid] ?? 0) >= $this->unpinned[$pid]) {
                return true;
            }
        }

        return false;
    }

    private function hasRoomAnywhere(): bool
    {
        foreach ($this->room as $room) {
            if ($room > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<int> Position indexes: the given session's first, in slot order, then the rest in grid order
     */
    private function candidatePositions(?int $fromSession): array
    {
        if ($fromSession === null) {
            return array_keys($this->positionList);
        }

        $order = $this->positionsOfSession[$fromSession];
        foreach (array_keys($this->positionList) as $index) {
            if ($this->positionList[$index][0] !== $fromSession) {
                $order[] = $index;
            }
        }

        return $order;
    }

    private function put(int $eventIndex, int $index, bool $record = true): void
    {
        foreach ($this->edges[$eventIndex] as $pid) {
            $this->occupant[$index][$pid] = $eventIndex;
            $this->load[$pid] = ($this->load[$pid] ?? 0) + 1;
        }
        $this->positionOf[$eventIndex] = $index;
        --$this->room[$index];
        if ($record) {
            $this->journal[] = [true, $eventIndex, $index];
        }
    }

    private function remove(int $eventIndex, bool $record = true): void
    {
        $index = $this->positionOf[$eventIndex];
        foreach ($this->edges[$eventIndex] as $pid) {
            unset($this->occupant[$index][$pid]);
            --$this->load[$pid];
        }
        unset($this->positionOf[$eventIndex]);
        ++$this->room[$index];
        if ($record) {
            $this->journal[] = [false, $eventIndex, $index];
        }
    }
}
