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
 * Four moves, in rounds, while a round places at least one event. The
 * last two are tried only when the first two placed nothing in the
 * round, only while fewer events are placed than the ceiling (ceiling())
 * allows, and only while some position has a free place:
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
 * - Rematch (rematchOne()): a position with room takes one more event
 *   by an augmenting path among the events placed there and the
 *   unplaced ones: unplaced events go in, the placed events between
 *   them go out and are unplaced for the next round. It changes which
 *   events of an over-capacity participant are the ones left out, any
 *   number of them at one position, where an exchange changes one or
 *   two.
 * - Exchange: an unplaced event takes the position of one placed event
 *   it collides with, that event leaves the schedule, and the exchange
 *   stands only if some unplaced event (the one that left included) can
 *   then be placed by an alternating path, or, one level deeper, by a
 *   further exchange (EXCHANGE_DEPTH). One in, one out and one more in:
 *   one more event placed. Every single exchange is tried before any
 *   chain of two, and between the two an exchange for two events
 *   (exchangeForTwo()): the unplaced event takes a position where both
 *   its participants have an event, both leave, and two of the events
 *   waiting must then be placed. Each of the three searches tries at
 *   most EXCHANGE_NODE_LIMIT exchanges. An event an exchange brought in
 *   is not taken out again on the same branch, but a path may move it.
 *
 * Every move is applied as it is searched and recorded in a journal, and
 * taken back from the journal where it fails, so every state keeps the
 * rules the final schedule keeps: no participant in two events at one
 * position, no event at a position where one of its participants is
 * pinned, no position over capacity. Pinned events never move. A move
 * that does not place one more event in the end is taken back entirely,
 * so the step changes nothing it cannot improve.
 *
 * Each alternating-path node, each start of an augmenting path and each
 * exchange spends one step from the budget it is given; with no step
 * left the searches stop and only direct moves are made. The exchange
 * limit is a limit of the search's own, like the depth, and does not
 * depend on the budget.
 *
 * The whole step also stops searching after a number of position checks
 * (a check is one look at whether an event fits a position; each costs
 * well under a microsecond): WORK_LIMIT, or WORK_PER_EVENT_AND_POSITION
 * for every event and position of the request where that is more, up to
 * WORK_LIMIT_AT_MOST (workLimitFor()). The ceiling cannot prove of every request
 * that nothing more fits, and where it cannot, the exchange search
 * would otherwise run to its limits on every round: seconds on requests
 * of a few hundred events that 0.2.2 repacked in milliseconds. That is
 * the limit at the default step budget and at every larger one, where a
 * larger budget gives the same outcome and the budget flag is not set
 * when the limit is reached. A step is one node of a search and a node
 * looks at every position, so steps alone do not bound the work in
 * proportion to the budget; below the default budget the step has the
 * same share of the limit as the budget is of the default
 * (StepBudget::workLimit()), and never less than
 * WORK_PER_EVENT_AND_POSITION_AT_LEAST for every event and position,
 * which is about one search that fails and grows with the request as
 * the passes that are not searches do. A search the smaller limit stops
 * is reported to the budget as a search it stopped, because a larger
 * budget would have let it go on. Direct moves go on after the limit, so
 * an event left unplaced as NoSlotAvailable still has no position with
 * room and both participants free. On some requests it leaves an event
 * unplaced that a longer search would have placed.
 * A lifted event
 * tries the positions of its own session first, in slot order, and then
 * the rest of the grid in grid order, so the session loads the planner
 * chose change as little as they can; the gaps the moves leave are the
 * audit's to report.
 *
 * All ids are the orchestrator's dense integer indexes, given in the
 * order the request itself gives (StructuralOrder for the participants,
 * RoundColouring for the events), so an index tie-break follows what the
 * request says and not what its ids happen to be.
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
     * no further search: a limit of the step's own, which no step budget
     * lifts and one below the default makes smaller (see the class
     * docblock). About a sixth of a second on the machine it was
     * measured on.
     */
    public const int WORK_LIMIT = 500_000;

    /**
     * How many position checks the step may make for every event and
     * position of the request, where that comes to more than WORK_LIMIT.
     * An alternating path lifts each placed event once at most and looks
     * at each position for it a few times, so the events times the
     * positions is about what one search that fails costs, and the fixed
     * limit alone was the work of some five of them on 770 events and 39
     * positions: the step stopped there with events it was still
     * placing, and placed fewer than release 0.2.3 on requests of that
     * size with many pins. A hundred of them is 3 million checks on such
     * a request, about a second; a request of 200 events on 20 positions
     * stays at WORK_LIMIT.
     */
    public const int WORK_PER_EVENT_AND_POSITION = 100;

    /**
     * How many position checks the step may make for every event and
     * position of the request however small the step budget is: one,
     * which is about what a single search that fails costs. Without it a
     * budget of a few steps left the round layout no search at all, and
     * the layout is what the planner's order of events comes from: on
     * seasons of 40 participants over two legs with rounds played, a
     * budget of one step placed 14,339 of 16,184 events, fewer than
     * release 0.2.3 at the same budget (14,849), and with it 15,517, for
     * about a fifth more time. It is work in proportion to the request,
     * like the passes that are not searches, so it is part of what no
     * budget shortens. Two and four for every event and position placed
     * a little more again for twice and four times the cost.
     */
    public const int WORK_PER_EVENT_AND_POSITION_AT_LEAST = 1;

    /**
     * The most position checks the step makes on any request, however
     * large: without it a round robin of two legs among 40 participants
     * (1,460 events on some 75 positions) had four times this to spend
     * each time the step ran, and took eight seconds.
     */
    public const int WORK_LIMIT_AT_MOST = 3_000_000;

    private int $exchangeNodes = 0;

    /** Position checks made so far, against the work limit */
    private int $work = 0;

    /** The work limit of the call of place() in progress */
    private int $limit = self::WORK_LIMIT;

    /** Whether that limit is smaller than the one the default budget gives */
    private bool $limitIsShortened = false;

    /** The ceiling of the last call of place() (see ceiling()) */
    private int $placedCeiling = 0;

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
     * @param int|null $workLimit Position checks after which no further search
     *                            starts. The repacker passes none, and the limit
     *                            is then worked out from the request it is given
     *                            (workLimitFor()) and the budget
     *                            (StepBudget::workLimit()); the unit tests pass a
     *                            small one to see the step stop at it
     */
    public function __construct(
        private readonly StepBudget $budget,
        private readonly ?int $workLimit = null
    ) {}

    /**
     * The position checks the step may make on a request of so many
     * events and positions: WORK_LIMIT, or WORK_PER_EVENT_AND_POSITION
     * for every event and position where that is more, and never more
     * than WORK_LIMIT_AT_MOST. This is the limit at the default step
     * budget and above; place() takes the share of it a smaller budget
     * leaves, and no less than workLimitAtLeast().
     */
    public static function workLimitFor(int $events, int $positions): int
    {
        return min(
            self::WORK_LIMIT_AT_MOST,
            max(self::WORK_LIMIT, self::WORK_PER_EVENT_AND_POSITION * $events * $positions)
        );
    }

    /**
     * The position checks the step may make on a request of so many
     * events and positions however small the step budget is:
     * WORK_PER_EVENT_AND_POSITION_AT_LEAST for every event and position,
     * and never more than the limit the default budget gives.
     */
    public static function workLimitAtLeast(int $events, int $positions): int
    {
        return min(
            self::workLimitFor($events, $positions),
            self::WORK_PER_EVENT_AND_POSITION_AT_LEAST * $events * $positions
        );
    }

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
        $positionCount = (int) array_sum($slotCounts);
        $whole = self::workLimitFor(count($edges), $positionCount);
        $this->limit = $this->workLimit
            ?? max($this->budget->workLimit($whole), self::workLimitAtLeast(count($edges), $positionCount));
        $this->limitIsShortened = $this->limit < $whole;

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
        $this->placedCeiling = $this->ceiling($remaining, $leftOutAtLeast);
        do {
            $before = count($remaining);
            $remaining = $this->placeEach($remaining);
            if (
                count($remaining) === $before
                && $remaining !== []
                && count($this->positionOf) < $this->placedCeiling
                && $this->hasRoomAnywhere()
            ) {
                // Both moves below end at a free place, so neither is
                // tried on a grid that has none. The cheap one comes first
                $remaining = $this->rematchOne($remaining);
                if (count($remaining) === $before) {
                    $remaining = $this->exchangeOne($remaining);
                }
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
     * The ceiling the last call of place() worked out: a number of placed
     * events that no placement of that request exceeds (see ceiling()).
     * A schedule that places as many is one no other order of the events
     * can place more than; one that places fewer may or may not be.
     */
    public function lastCeiling(): int
    {
        return $this->placedCeiling;
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
        // Breadth before depth: every single exchange is tried before any
        // chain of two, and between them the exchanges that take two
        // events out. Each of the three has the node limit to itself
        for ($depth = 1; $depth <= self::EXCHANGE_DEPTH; ++$depth) {
            $this->exchangeNodes = 0;
            $this->journal = [];
            $found = $this->exchange($remaining, [], $depth);
            if ($found !== null) {
                return $found;
            }

            if ($depth === 1) {
                $this->exchangeNodes = 0;
                $this->journal = [];
                $found = $this->exchangeForTwo($remaining);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return $remaining;
    }

    /**
     * One level of the exchange search: an unplaced event takes the
     * place of a placed one, and then, at the last level, an alternating
     * path places one of the events waiting; above it a further exchange
     * is tried. Events an exchange brought in are not taken out again on
     * the same branch; a path may move them.
     *
     * The search is run once for every depth up to EXCHANGE_DEPTH, so a
     * level above the last does not look for a path: the run before
     * looked for it there.
     *
     * Of several unplaced events between the same two participants only
     * the first is brought in at a level, and an event is not exchanged
     * for another between the same two: either changes nothing but which
     * of them is placed.
     *
     * @param list<int> $remaining Ascending
     * @param array<int, true> $fixed Events this branch brought in
     * @param int $depth Levels still to go, this one included
     *
     * @return list<int>|null The events still unplaced after a branch that placed one more, or null
     */
    private function exchange(array $remaining, array $fixed, int $depth): ?array
    {
        $tried = [];
        foreach ($remaining as $incoming) {
            [$a, $b] = $this->edges[$incoming];
            if (isset($tried[$a][$b])) {
                continue;
            }
            $tried[$a][$b] = true;

            foreach (array_keys($this->positionList) as $index) {
                foreach ($this->exchangeCandidates($incoming, $index) as $outgoing) {
                    if (isset($fixed[$outgoing]) || $this->edges[$outgoing] === $this->edges[$incoming]) {
                        continue;
                    }
                    if (
                        ++$this->exchangeNodes > self::EXCHANGE_NODE_LIMIT
                        || $this->isPastWorkLimit()
                        || !$this->budget->consume()
                    ) {
                        return null;
                    }

                    $mark = count($this->journal);
                    $this->remove($outgoing);
                    $this->put($incoming, $index);

                    $waiting = array_values(array_filter(
                        $remaining,
                        static fn(int $eventIndex): bool => $eventIndex !== $incoming
                    ));
                    $waiting[] = $outgoing;
                    sort($waiting);

                    if ($depth > 1) {
                        $deeper = $this->exchange($waiting, $fixed + [$incoming => true], $depth - 1);
                        if ($deeper !== null) {
                            return $deeper;
                        }
                    } else {
                        foreach ($waiting as $candidate) {
                            $this->lifted = [$candidate => true];
                            if ($this->placeDirectly($candidate) || $this->insert($candidate, null)) {
                                return array_values(array_filter(
                                    $waiting,
                                    static fn(int $eventIndex): bool => $eventIndex !== $candidate
                                ));
                            }
                        }
                    }

                    $this->rollBack($mark);
                }
            }
        }

        return null;
    }

    /**
     * An exchange that takes two events out: an unplaced event takes a
     * position where each of its participants has an event, both leave
     * the schedule, and the exchange stands only if direct moves and
     * alternating paths then place at least two of the events waiting
     * (the two that left included). One in, two out and two more in: one
     * more event placed. It is what gets a schedule out of a state where
     * every unplaced event has two events in its way wherever it goes,
     * which no exchange for one event can leave.
     *
     * @param list<int> $remaining Ascending
     *
     * @return list<int>|null The events still unplaced after an exchange that placed one more, or null
     */
    private function exchangeForTwo(array $remaining): ?array
    {
        $tried = [];
        foreach ($remaining as $incoming) {
            [$a, $b] = $this->edges[$incoming];
            if (isset($tried[$a][$b])) {
                continue;
            }
            $tried[$a][$b] = true;

            foreach (array_keys($this->positionList) as $index) {
                $blocking = $this->blocking($incoming, $index);
                if ($blocking === null || count($blocking) !== 2) {
                    continue;
                }
                if (
                    ++$this->exchangeNodes > self::EXCHANGE_NODE_LIMIT
                    || $this->isPastWorkLimit()
                    || !$this->budget->consume()
                ) {
                    return null;
                }

                $mark = count($this->journal);
                $this->remove($blocking[0]);
                $this->remove($blocking[1]);
                $this->put($incoming, $index);

                $waiting = array_values(array_filter(
                    $remaining,
                    static fn(int $eventIndex): bool => $eventIndex !== $incoming
                ));
                $waiting[] = $blocking[0];
                $waiting[] = $blocking[1];
                sort($waiting);

                $stillLeft = [];
                foreach ($waiting as $candidate) {
                    $this->lifted = [$candidate => true];
                    if (!$this->placeDirectly($candidate) && !$this->insert($candidate, null)) {
                        $stillLeft[] = $candidate;
                    }
                }
                if (count($stillLeft) < count($remaining)) {
                    return $stillLeft;
                }

                $this->rollBack($mark);
            }
        }

        return null;
    }

    /**
     * Try, position by position, to put one more event at a position
     * with room by exchanging events there for unplaced ones, until one
     * position takes one more.
     *
     * At one position the events placed there are a matching of the
     * participants, and the unplaced events are other pairs of them. An
     * augmenting path starts at a participant free there, takes an
     * unplaced event of its to a participant that plays there, follows
     * that participant's event to its opponent, and so on, until an
     * unplaced event ends at a participant that is free: the unplaced
     * events of the path go in, the placed ones go out, and the position
     * holds one more. The events that went out are unplaced then, and the
     * next round offers them to every position. The search follows each
     * participant once and does not look inside an odd cycle, so it can
     * miss a path that exists. Each start spends one step.
     *
     * @param list<int> $remaining Ascending
     *
     * @return list<int> The events still unplaced, ascending; unchanged when no position took one more
     */
    private function rematchOne(array $remaining): array
    {
        foreach (array_keys($this->positionList) as $index) {
            if ($this->room[$index] < 1) {
                continue;
            }

            /** @var array<int, list<array{int, int}>> $waiting Participant => [unplaced event, opponent] */
            $waiting = [];
            foreach ($remaining as $eventIndex) {
                [$a, $b] = $this->edges[$eventIndex];
                if (isset($this->pinned[$index][$a]) || isset($this->pinned[$index][$b])) {
                    continue;
                }
                $waiting[$a][] = [$eventIndex, $b];
                $waiting[$b][] = [$eventIndex, $a];
            }

            foreach (array_keys($waiting) as $start) {
                if (isset($this->occupant[$index][$start])) {
                    continue;
                }
                if ($this->isPastWorkLimit() || !$this->budget->consume()) {
                    return $remaining;
                }

                $visited = [$start => true];
                $path = [];
                if (!$this->augment($index, $start, $waiting, $visited, $path)) {
                    continue;
                }

                $in = [];
                foreach ($path as [$incoming, $outgoing]) {
                    $in[$incoming] = true;
                    if ($outgoing !== null) {
                        $this->remove($outgoing);
                        $remaining[] = $outgoing;
                    }
                }
                foreach (array_keys($in) as $incoming) {
                    $this->put($incoming, $index);
                }
                $remaining = array_values(array_filter(
                    $remaining,
                    static fn(int $eventIndex): bool => !isset($in[$eventIndex])
                ));
                sort($remaining);

                return $remaining;
            }
        }

        return $remaining;
    }

    /**
     * Extend an augmenting path at one position from a participant that
     * is free there (see rematchOne()).
     *
     * @param array<int, list<array{int, int}>> $waiting Participant => [unplaced event, opponent]
     * @param array<int, true> $visited Participants on the path or given up
     * @param list<array{int, int|null}> $path [unplaced event to bring in, placed event to take out
     *                                         or null at the end of the path]
     */
    private function augment(int $index, int $from, array $waiting, array &$visited, array &$path): bool
    {
        foreach ($waiting[$from] ?? [] as [$eventIndex, $other]) {
            ++$this->work;
            if (isset($visited[$other])) {
                continue;
            }

            $matched = $this->occupant[$index][$other] ?? null;
            if ($matched === null) {
                $path[] = [$eventIndex, null];

                return true;
            }

            [$x, $y] = $this->edges[$matched];
            $next = $x === $other ? $y : $x;
            if (isset($visited[$next])) {
                continue;
            }
            $visited[$other] = true;
            $visited[$next] = true;
            $path[] = [$eventIndex, $matched];
            if ($this->augment($index, $next, $waiting, $visited, $path)) {
                return true;
            }
            array_pop($path);
        }

        return false;
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
        if ($this->hasSaturatedParticipant($eventIndex)) {
            // A participant whose every unpinned position already holds one
            // of its events is in the way wherever the event goes, and the
            // event of its that is lifted has nowhere to go: no path exists.
            // Asked before the work limit, so that a limit is not reported
            // as having stopped a search that was never going to be made
            return false;
        }
        if ($this->isPastWorkLimit() || !$this->budget->consume()) {
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

    /**
     * Whether the step has made its position checks, asked by a search
     * that is about to start or go on: a true answer is a search cut
     * short, and when the limit is smaller than the one the default
     * budget gives, the budget is told
     * (StepBudget::stoppedAtWorkLimit()).
     */
    private function isPastWorkLimit(): bool
    {
        if ($this->work <= $this->limit) {
            return false;
        }
        if ($this->limitIsShortened) {
            $this->budget->stoppedAtWorkLimit();
        }

        return true;
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
        return array_any($this->room, fn($room) => $room > 0);
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
