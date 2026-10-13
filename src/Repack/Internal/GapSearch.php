<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Repack\Internal;

/**
 * The last step of a repack that still reports a gap: look for another
 * placement of as many events with fewer gap slots.
 *
 * The phases before it decide the sessions first and the slots after, and
 * the last placement step moves events without regard to contiguity, so
 * a gap they leave can be one that another choice of sessions, or of the
 * events left unplaced, would not have. This step searches the placements
 * themselves. It runs only when the schedule it is given has a gap, so a
 * schedule without one is never touched.
 *
 * It is a depth-first search over the movable events, each tried at the
 * position it has, then at every other position in grid order, then (as
 * long as no more events are left out than were) at none. A branch is
 * given up when the gap slots it can no longer close (a participant's
 * gap slots less the events it still has to place) are as many as the
 * best placement found. A placement is taken only when it has strictly
 * fewer gap slots, places at least as many events, and leaves no event
 * out that a free position could hold; the search stops at the first
 * placement with no gap.
 *
 * The events are taken in an order that puts last the ones that have to
 * do with a gap (an event of a participant with a gap, or in a session
 * with one). The search changes the last events first, so within its
 * limits it is a search of the neighbourhood of the gaps on a large
 * request, and of every placement on a small one: where it runs to its
 * end, no placement of as many events has fewer gap slots than the one
 * returned.
 *
 * Each node spends one step of the budget it is given, and the search
 * also stops after WORK_LIMIT position checks whatever the budget: a
 * limit of its own, like those of the last placement step, so that a
 * large request with a gap that cannot be closed costs a fixed amount of
 * work.
 *
 * Gap slots are counted as the audit counts them: per participant and
 * session, over the pinned and the movable slots together, and only
 * where the participant has a movable event in the session.
 *
 * @internal
 */
final class GapSearch
{
    /**
     * How many position checks one search may make, whatever the step
     * budget (a check is one look at whether an event fits a position).
     * Counting checks and not nodes makes the limit about the same amount
     * of work on a grid of nine positions and on one of fifty: about a
     * tenth of a second on the machine it was measured on.
     */
    public const int WORK_LIMIT = 200_000;

    /** @var array<int, array{int, int}> */
    private array $edges = [];

    /** @var list<array{int, int}> Position index => [session, slot], in grid order */
    private array $positionList = [];

    /** @var array<int, int> Position index => places left */
    private array $room = [];

    /** @var array<int, array<int, true>> Position index => participants there, pinned or placed */
    private array $busy = [];

    /** @var array<int, array<int, array<int, true>>> Participant => session => slots it holds, pinned or movable */
    private array $held = [];

    /** @var array<int, array<int, int>> Participant => session => movable events it has there */
    private array $movable = [];

    /** @var array<int, array<int, int>> Participant => session => gap slots of that cell */
    private array $cellGaps = [];

    /** @var array<int, int> Participant => gap slots over its sessions */
    private array $gaps = [];

    /** @var array<int, int> Participant => events of it the search has still to decide */
    private array $open = [];

    /** The gap slots no further decision can close, summed over the participants */
    private int $bound = 0;

    private int $totalGaps = 0;

    /** @var list<int> The events in the order the search decides them */
    private array $order = [];

    /** @var array<int, int|null> Event => the position it is tried at first, null for none */
    private array $first = [];

    /** @var array<int, int> Event => position index, on the current branch */
    private array $current = [];

    /** @var array<int, int>|null Event => position index of the best placement found */
    private ?array $best = null;

    private int $bestGaps = 0;

    private int $work = 0;

    private bool $stopped = false;

    /**
     * @param int $workLimit Position checks after which the search stops; the
     *                       repacker always passes WORK_LIMIT, and the unit tests
     *                       pass a smaller one to see the search stop at it
     */
    public function __construct(
        private readonly StepBudget $budget,
        private readonly int $workLimit = self::WORK_LIMIT
    ) {}

    /**
     * @param array<int, array{int, int}> $edges Event index => participant index pair
     * @param array<int, array{int, int}> $positions Placed event index => [session, slot]; replaced when
     *                                               a placement with fewer gap slots is found
     * @param list<int> $unplaced Event indexes with no position
     * @param array<int, array<int, array<int>>> $pinSlots Session => participant => pinned slots
     * @param array<int, array<int, int>> $pinCounts Session => slot => pinned event count
     * @param array<int, int> $slotCounts Session => slot count
     *
     * @return list<int> The events with no position afterwards, ascending
     */
    public function improve(
        array $edges,
        array &$positions,
        array $unplaced,
        array $pinSlots,
        array $pinCounts,
        array $slotCounts,
        int $capacityPerSlot
    ): array {
        $this->edges = $edges;
        $this->positionList = [];
        $this->room = [];
        $this->busy = [];
        $this->held = [];
        $this->movable = [];
        $this->cellGaps = [];
        $this->gaps = [];
        $this->open = [];
        $this->bound = 0;
        $this->totalGaps = 0;
        $this->current = [];
        $this->best = null;
        $this->work = 0;
        $this->stopped = false;

        /** @var array<int, array<int, int>> $indexOf */
        $indexOf = [];
        foreach ($slotCounts as $session => $slotCount) {
            for ($slot = 0; $slot < $slotCount; ++$slot) {
                $index = count($this->positionList);
                $indexOf[$session][$slot] = $index;
                $this->positionList[] = [$session, $slot];
                $this->room[$index] = $capacityPerSlot - ($pinCounts[$session][$slot] ?? 0);
            }
        }
        foreach ($pinSlots as $session => $byParticipant) {
            foreach ($byParticipant as $pid => $slots) {
                foreach ($slots as $slot) {
                    $this->busy[$indexOf[$session][$slot]][$pid] = true;
                    $this->held[$pid][$session][$slot] = true;
                }
            }
        }
        foreach ($edges as [$a, $b]) {
            $this->gaps[$a] = 0;
            $this->gaps[$b] = 0;
            $this->open[$a] = 0;
            $this->open[$b] = 0;
        }

        // The schedule as it stands: its gap slots, and which events have
        // to do with one
        foreach ($positions as $eventIndex => [$session, $slot]) {
            $this->put($eventIndex, $indexOf[$session][$slot]);
        }
        $this->bestGaps = $this->totalGaps;
        if ($this->bestGaps === 0) {
            sort($unplaced);

            return $unplaced;
        }

        $gapSessions = [];
        foreach ($this->cellGaps as $bySession) {
            foreach ($bySession as $session => $gapSlots) {
                if ($gapSlots > 0) {
                    $gapSessions[$session] = true;
                }
            }
        }
        $nearGap = [];
        $this->first = [];
        foreach (array_keys($edges) as $eventIndex) {
            [$a, $b] = $edges[$eventIndex];
            $at = isset($positions[$eventIndex])
                ? $indexOf[$positions[$eventIndex][0]][$positions[$eventIndex][1]]
                : null;
            $this->first[$eventIndex] = $at;
            $nearGap[$eventIndex] = (int) (
                $this->gaps[$a] > 0
                || $this->gaps[$b] > 0
                || ($at !== null && isset($gapSessions[$this->positionList[$at][0]]))
            );
        }
        $this->order = array_keys($edges);
        usort($this->order, static fn(int $x, int $y): int => [$nearGap[$x], $x] <=> [$nearGap[$y], $y]);

        // Start again from an empty schedule
        foreach (array_keys($positions) as $eventIndex) {
            $this->take($eventIndex);
        }
        foreach ($edges as [$a, $b]) {
            ++$this->open[$a];
            ++$this->open[$b];
        }

        $this->search(0, count($unplaced));

        if ($this->best === null) {
            sort($unplaced);

            return $unplaced;
        }

        $positions = [];
        $unplaced = [];
        foreach (array_keys($edges) as $eventIndex) {
            if (isset($this->best[$eventIndex])) {
                $positions[$eventIndex] = $this->positionList[$this->best[$eventIndex]];
            } else {
                $unplaced[] = $eventIndex;
            }
        }

        return $unplaced;
    }

    /**
     * @param int $at Place in the order of the event to decide
     * @param int $mayLeaveOut How many more events may be left without a position
     */
    private function search(int $at, int $mayLeaveOut): void
    {
        if ($this->bound >= $this->bestGaps) {
            return;
        }
        if (!isset($this->order[$at])) {
            if ($this->totalGaps < $this->bestGaps && !$this->leavesOutAPlaceableEvent()) {
                $this->bestGaps = $this->totalGaps;
                $this->best = $this->current;
            }

            return;
        }
        if ($this->work > $this->workLimit || !$this->budget->consume()) {
            $this->stopped = true;

            return;
        }

        $eventIndex = $this->order[$at];
        [$a, $b] = $this->edges[$eventIndex];
        $first = $this->first[$eventIndex];

        $this->decided($a, $b, -1);
        if ($first === null && $mayLeaveOut > 0) {
            $this->search($at + 1, $mayLeaveOut - 1);
        }
        foreach ($first === null ? [] : [$first] as $index) {
            // The position it has is free here whenever the events before
            // it are where they were
            if ($this->fits($a, $b, $index)) {
                $this->put($eventIndex, $index);
                $this->search($at + 1, $mayLeaveOut);
                $this->take($eventIndex);
            }
        }
        foreach (array_keys($this->positionList) as $index) {
            if ($this->stopped || $this->bestGaps === 0) {
                break;
            }
            if ($index === $first || !$this->fits($a, $b, $index)) {
                continue;
            }
            $this->put($eventIndex, $index);
            $this->search($at + 1, $mayLeaveOut);
            $this->take($eventIndex);
        }
        if ($first !== null && $mayLeaveOut > 0 && !$this->stopped && $this->bestGaps > 0) {
            $this->search($at + 1, $mayLeaveOut - 1);
        }
        $this->decided($a, $b, 1);
    }

    private function fits(int $a, int $b, int $index): bool
    {
        ++$this->work;

        return $this->room[$index] > 0 && !isset($this->busy[$index][$a]) && !isset($this->busy[$index][$b]);
    }

    /**
     * Whether an event without a position on the current branch could
     * take a free one: such a placement is not one the repacker returns.
     */
    private function leavesOutAPlaceableEvent(): bool
    {
        foreach ($this->order as $eventIndex) {
            if (isset($this->current[$eventIndex])) {
                continue;
            }
            [$a, $b] = $this->edges[$eventIndex];
            foreach (array_keys($this->positionList) as $index) {
                if ($this->fits($a, $b, $index)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Count an event of the two participants as decided (-1) or as open
     * again (+1), and bring the bound up to date.
     */
    private function decided(int $a, int $b, int $change): void
    {
        foreach ([$a, $b] as $pid) {
            $this->bound -= max(0, $this->gaps[$pid] - $this->open[$pid]);
            $this->open[$pid] += $change;
            $this->bound += max(0, $this->gaps[$pid] - $this->open[$pid]);
        }
    }

    private function put(int $eventIndex, int $index): void
    {
        [$session, $slot] = $this->positionList[$index];
        $this->current[$eventIndex] = $index;
        --$this->room[$index];
        foreach ($this->edges[$eventIndex] as $pid) {
            $this->busy[$index][$pid] = true;
            $this->held[$pid][$session][$slot] = true;
            $this->movable[$pid][$session] = ($this->movable[$pid][$session] ?? 0) + 1;
            $this->recount($pid, $session);
        }
    }

    private function take(int $eventIndex): void
    {
        $index = $this->current[$eventIndex];
        [$session, $slot] = $this->positionList[$index];
        unset($this->current[$eventIndex]);
        ++$this->room[$index];
        foreach ($this->edges[$eventIndex] as $pid) {
            unset($this->busy[$index][$pid], $this->held[$pid][$session][$slot]);
            --$this->movable[$pid][$session];
            $this->recount($pid, $session);
        }
    }

    /**
     * Bring the gap slots of one participant's session, and the totals
     * that hold them, up to date.
     */
    private function recount(int $pid, int $session): void
    {
        $gapSlots = 0;
        $slots = array_keys($this->held[$pid][$session] ?? []);
        if (($this->movable[$pid][$session] ?? 0) > 0 && $slots !== []) {
            $gapSlots = max($slots) - min($slots) + 1 - count($slots);
        }

        $change = $gapSlots - ($this->cellGaps[$pid][$session] ?? 0);
        if ($change === 0) {
            return;
        }
        $this->cellGaps[$pid][$session] = $gapSlots;
        $this->bound -= max(0, $this->gaps[$pid] - $this->open[$pid]);
        $this->gaps[$pid] += $change;
        $this->bound += max(0, $this->gaps[$pid] - $this->open[$pid]);
        $this->totalGaps += $change;
    }
}
