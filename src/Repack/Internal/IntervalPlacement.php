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
     * The widest session the parity machinery handles. The DPs hold one
     * bit per slot and up to 2^slotCount mask states, so cost grows
     * exponentially with slot count — beyond this width callers skip
     * parity reasoning entirely (Phase A treats the session as feasible,
     * Phase B falls straight to the greedy packing) rather than letting a
     * single "step" blow past the step budget's intent. Real repack grids
     * run a handful of slots per session; 20 bits (~a million states)
     * is comfortably past anything a session of concurrent events needs.
     */
    public const MAX_SLOTS = 20;

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
     * The boundary-flip mask of every run of one length, by start slot.
     *
     * @return list<int>
     */
    private static function runMasks(int $length, int $slotCount): array
    {
        $masks = [];
        for ($start = 0; $start + $length <= $slotCount; ++$start) {
            $masks[] = self::maskOfRun($start, $length, $slotCount);
        }

        return $masks;
    }

    /**
     * Every boundary-flip mask some placement of the flexible runs ends on.
     *
     * The set is the same in whatever order the runs are applied (each
     * placement XORs one run mask per participant into the fixed mask), so
     * runs of one length are applied together. For one length, let R(k) be
     * the set after k of its runs. R(k) holds R(k - 2): take any mask of
     * R(k - 2) and apply one run twice, which changes nothing. So when
     * R(k) is no larger than R(k - 2) the two are the same set, and from
     * there the sets repeat with period two: R(k + 1) is R(k - 1), R(k + 2)
     * is R(k), and so on. The remaining runs of that length need not be
     * applied, only counted. (A length with no run that fits gives the
     * empty set from its first run on, which the same test covers.)
     *
     * @param array<int> $flexibleLengths Run length per unpinned participant
     * @return array<int, true> Keyed by mask
     */
    private static function reachableMasks(int $fixedMask, array $flexibleLengths, int $slotCount): array
    {
        $copiesByLength = array_count_values($flexibleLengths);
        ksort($copiesByLength);

        $states = [$fixedMask => true];
        foreach ($copiesByLength as $length => $copies) {
            $runMasks = self::runMasks($length, $slotCount);
            $sizeTwoBack = -1;

            for ($applied = 1; $applied <= $copies; ++$applied) {
                $next = [];
                foreach ($states as $mask => $unused) {
                    foreach ($runMasks as $runMask) {
                        $next[$mask ^ $runMask] = true;
                    }
                }

                if (count($next) === $sizeTwoBack) {
                    // $next is R(applied) = R(applied - 2) and $states is
                    // R(applied - 1): an even number of runs left ends on
                    // the first, an odd number on the second.
                    if (($copies - $applied) % 2 === 0) {
                        $states = $next;
                    }
                    break;
                }

                $sizeTwoBack = count($states);
                $states = $next;
            }
        }

        return $states;
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
        $states = self::reachableMasks($fixedMask, $flexibleLengths, $slotCount);
        if (isset($states[0])) {
            return 0;
        }

        $best = PHP_INT_MAX;
        foreach ($states as $mask => $unused) {
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

        if (!$budget->hasStepsLeft()) {
            // Nothing can be enumerated: the loop below would stop at its
            // first look at the budget. All that is left to decide is
            // whether it would have got that far, which is whether any
            // placement ends on parity zero. The set of reachable masks
            // answers that without the cost table, which is what holds a
            // row of up to 2^slotCount entries for every participant.
            if (isset(self::reachableMasks($fixedMask, $lengths, $slotCount)[0])) {
                // Recorded as a search the budget stopped, as the loop does.
                $budget->isExhausted();
            }

            return false;
        }

        /** @var list<list<int>> $runMasks Run masks by participant, then by start slot */
        $runMasks = [];
        foreach ($lengths as $length) {
            $runMasks[] = self::runMasks($length, $slotCount);
        }

        // minCost[i][mask]: cheapest total start depth for runs i.. to end
        // on parity zero, PHP_INT_MAX when unreachable
        $minCost = array_fill(0, $count + 1, []);
        $minCost[$count] = [0 => 0];
        for ($i = $count - 1; $i >= 0; --$i) {
            $row = [];
            foreach ($minCost[$i + 1] as $mask => $cost) {
                foreach ($runMasks[$i] as $start => $runMask) {
                    $reached = $mask ^ $runMask;
                    $candidate = $cost + $start;
                    if ($candidate < ($row[$reached] ?? PHP_INT_MAX)) {
                        $row[$reached] = $candidate;
                    }
                }
            }
            $minCost[$i] = $row;
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
            $found = self::search(0, $fixedMask, $target, $runMasks, $minCost, $starts, $budget, $tryPlacement);
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
     * @param list<list<int>> $runMasks Run masks by participant, then by start slot
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
        array $runMasks,
        array $minCost,
        array &$starts,
        StepBudget $budget,
        callable $tryPlacement
    ): ?bool {
        if (!isset($runMasks[$index])) {
            if ($mask !== 0 || $remaining !== 0) {
                return null;
            }

            return $tryPlacement($starts) ? true : null;
        }

        if (!$budget->consume()) {
            return false;
        }

        $suffixCosts = $minCost[$index + 1];
        foreach ($runMasks[$index] as $start => $runMask) {
            if ($start > $remaining) {
                break;
            }

            $reached = $mask ^ $runMask;
            $suffixCost = $suffixCosts[$reached] ?? PHP_INT_MAX;
            if ($suffixCost > $remaining - $start) {
                continue;
            }

            $starts[$index] = $start;
            $result = self::search($index + 1, $reached, $remaining - $start, $runMasks, $minCost, $starts, $budget, $tryPlacement);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }
}
