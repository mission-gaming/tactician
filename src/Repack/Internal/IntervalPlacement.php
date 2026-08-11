<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Repack\Internal;

/**
 * Interval-parity machinery for gap-free session packing.
 *
 * A gap-free participant occupies one contiguous run of slots. Whether a
 * session's load vector admits any choice of runs where every slot's
 * matching set has even size reduces to boundary parity: a run [a, a+d)
 * flips membership parity at positions a and a+d, and every position
 * below the slot count must end with an even flip count. That is a tiny
 * DP over bitmasks (one bit per position), which gives both a cheap
 * feasibility score for Phase A (how many positions cannot be cancelled)
 * and an enumerator of concrete run placements for Phase B, ordered by
 * total late-start depth so gap-free-and-from-the-top solutions are
 * tried first.
 *
 * Participants with pinned slots have fixed movable-target sets; their
 * boundary flips are folded into the starting mask, and only unpinned
 * participants' runs are placed.
 *
 * @internal
 */
final class IntervalPlacement
{
    /**
     * The boundary-flip mask of an arbitrary slot set.
     *
     * @param array<int> $slots Ascending
     */
    public static function maskOfSet(array $slots, int $slotCount): int
    {
        $inSet = array_fill_keys($slots, true);
        $mask = 0;
        $previous = false;
        for ($position = 0; $position < $slotCount; ++$position) {
            $current = isset($inSet[$position]);
            if ($current !== $previous) {
                $mask |= 1 << $position;
            }
            $previous = $current;
        }

        return $mask;
    }

    /**
     * The boundary-flip mask of a run of length $length starting at $start.
     */
    private static function maskOfRun(int $start, int $length, int $slotCount): int
    {
        $mask = 1 << $start;
        if ($start + $length < $slotCount) {
            $mask |= 1 << ($start + $length);
        }

        return $mask;
    }

    /**
     * How many positions cannot be parity-cancelled by any placement of
     * the flexible runs — 0 means a gap-free placement exists in parity
     * terms.
     *
     * @param int $fixedMask Combined boundary flips of the fixed target sets
     * @param array<int> $flexibleLengths Run length per unpinned participant
     */
    public static function minOddScore(int $fixedMask, array $flexibleLengths, int $slotCount): int
    {
        $states = [$fixedMask => true];
        foreach ($flexibleLengths as $length) {
            $next = [];
            foreach (array_keys($states) as $mask) {
                for ($start = 0; $start + $length <= $slotCount; ++$start) {
                    $next[$mask ^ self::maskOfRun($start, $length, $slotCount)] = true;
                }
            }
            $states = $next;
        }

        $best = PHP_INT_MAX;
        foreach (array_keys($states) as $mask) {
            $odd = 0;
            for ($position = 0; $position < $slotCount; ++$position) {
                $odd += ($mask >> $position) & 1;
            }
            $best = min($best, $odd);
        }

        return $best;
    }

    /**
     * Enumerate parity-feasible placements of the flexible runs, cheapest
     * total start depth first (all-runs-at-the-top is tried before any
     * late start), invoking the callback with the start offsets in the
     * flexible participants' given order. Stops when the callback returns
     * true (a placement packed) or the budget runs out.
     *
     * @param array<int> $flexibleLengths Run length per unpinned participant, in
     *                                    a fixed deterministic order
     * @param callable(array<int>): bool $tryPlacement
     */
    public static function enumerate(
        int $fixedMask,
        array $flexibleLengths,
        int $slotCount,
        StepBudget $budget,
        callable $tryPlacement
    ): bool {
        $count = count($flexibleLengths);
        $lengths = array_values($flexibleLengths);

        // minCost[i][mask]: cheapest total start depth for runs i.. to end
        // on parity zero, PHP_INT_MAX when unreachable
        $minCost = array_fill(0, $count + 1, []);
        $minCost[$count] = [0 => 0];
        for ($i = $count - 1; $i >= 0; --$i) {
            $length = $lengths[$i];
            foreach ($minCost[$i + 1] as $mask => $cost) {
                for ($start = 0; $start + $length <= $slotCount; ++$start) {
                    $reached = $mask ^ self::maskOfRun($start, $length, $slotCount);
                    $candidate = $cost + $start;
                    if ($candidate < ($minCost[$i][$reached] ?? PHP_INT_MAX)) {
                        $minCost[$i][$reached] = $candidate;
                    }
                }
            }
        }

        if (!isset($minCost[0][$fixedMask])) {
            return false;
        }

        $maxDepth = 0;
        foreach ($lengths as $length) {
            $maxDepth += $slotCount - $length;
        }

        $starts = array_fill(0, $count, 0);
        for ($target = $minCost[0][$fixedMask]; $target <= $maxDepth; ++$target) {
            if ($budget->isExhausted()) {
                return false;
            }
            $found = self::search(0, $fixedMask, $target, $lengths, $slotCount, $minCost, $starts, $budget, $tryPlacement);
            if ($found !== null) {
                return $found;
            }
        }

        return false;
    }

    /**
     * Depth-first enumeration of placements whose total start depth is
     * exactly $remaining, in ascending start order per participant.
     *
     * @param array<int> $lengths
     * @param array<int, array<int, int>> $minCost
     * @param array<int> $starts Mutated in place
     * @param callable(array<int>): bool $tryPlacement
     *
     * @return bool|null True/false when the callback decided, null to keep searching
     */
    private static function search(
        int $index,
        int $mask,
        int $remaining,
        array $lengths,
        int $slotCount,
        array $minCost,
        array &$starts,
        StepBudget $budget,
        callable $tryPlacement
    ): ?bool {
        if ($index === count($lengths)) {
            if ($mask !== 0 || $remaining !== 0) {
                return null;
            }

            return $tryPlacement($starts) ? true : null;
        }

        if (!$budget->consume()) {
            return false;
        }

        $length = $lengths[$index];
        for ($start = 0; $start + $length <= $slotCount && $start <= $remaining; ++$start) {
            $reached = $mask ^ self::maskOfRun($start, $length, $slotCount);
            $suffixCost = $minCost[$index + 1][$reached] ?? PHP_INT_MAX;
            if ($suffixCost > $remaining - $start) {
                continue;
            }

            $starts[$index] = $start;
            $result = self::search($index + 1, $reached, $remaining - $start, $lengths, $slotCount, $minCost, $starts, $budget, $tryPlacement);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }
}
