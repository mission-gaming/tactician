<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Repack\Internal;

/**
 * Computes a participant's ideal slot set within one session.
 *
 * Gap-free-and-from-the-top means a participant with load d and no pins
 * occupies exactly slots 0..d-1. Pins bend the ideal: the target bridges
 * the free slots between the participant's pinned slots first (interior
 * gaps are the hard constraint), then extends downward toward slot 0
 * (late start is the softer objective), then upward. When the movable
 * load cannot even bridge the pins, the lowest interior holes are filled
 * and the residual gap is unavoidable — it will be reported, not hidden.
 *
 * Shared by the load planner (parity feasibility is checked against these
 * targets, so pins are consulted before any per-session load is fixed)
 * and the session packer (the per-slot matching sets derive from them).
 *
 * @internal
 */
final class ContiguityTargets
{
    /**
     * The slots a participant's movable events should ideally occupy.
     *
     * @param array<int> $pinnedSlots The participant's pinned slots in the session
     * @param int $movableCount How many movable events the participant has in the session
     * @param int $slotCount The session's slot count
     *
     * @return array<int> Ascending slot indexes, exactly $movableCount of them
     *                    (fewer only if the load exceeds the free slots, which the
     *                    planner prevents)
     */
    public static function movableTarget(array $pinnedSlots, int $movableCount, int $slotCount): array
    {
        if ($movableCount <= 0) {
            return [];
        }

        if ($pinnedSlots === []) {
            return range(0, min($movableCount, $slotCount) - 1);
        }

        $pinned = array_fill_keys($pinnedSlots, true);
        $lo = min($pinnedSlots);
        $hi = max($pinnedSlots);

        $interior = [];
        for ($slot = $lo + 1; $slot < $hi; ++$slot) {
            if (!isset($pinned[$slot])) {
                $interior[] = $slot;
            }
        }

        if ($movableCount <= count($interior)) {
            return array_slice($interior, 0, $movableCount);
        }

        $target = $interior;
        $remaining = $movableCount - count($interior);

        for ($slot = $lo - 1; $slot >= 0 && $remaining > 0; --$slot) {
            if (!isset($pinned[$slot])) {
                $target[] = $slot;
                --$remaining;
            }
        }

        for ($slot = $hi + 1; $slot < $slotCount && $remaining > 0; ++$slot) {
            if (!isset($pinned[$slot])) {
                $target[] = $slot;
                --$remaining;
            }
        }

        sort($target);

        return $target;
    }
}
