<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Repack\Internal;

/**
 * Splits the movable events into rounds before any session is chosen,
 * and so gives the order the planner takes them in.
 *
 * A round is a set of events that can share a slot: no two of them have
 * a participant in common, and there are no more of them than the slot
 * has room for. The rounds are laid on the grid's positions in grid
 * order, so a round also keeps clear of the pins of its position: no
 * event of it has a participant pinned there, and the pinned events
 * count towards the capacity. The answer is, for each event, the place
 * of its round's position in grid order.
 *
 * It decides an order and nothing else: which session an event plays
 * in is still the planner's choice by the weights, and its slot the
 * packer's. But events taken round by round fill a session with whole
 * rounds, in which a participant has an event in every slot it is free
 * at, where events taken participant by participant fill it with
 * everything a few participants have between them, which no slot order
 * can lay out without gaps. That is how contiguity is put ahead of the
 * consolidation weight: the planner scores sessions by the weights on an
 * order in which the session it prefers is one that packs.
 *
 * As few rounds as can be are wanted: no fewer than the most events any
 * participant has, and no fewer than the events over the capacity. Each
 * event first tries the positions of that many rounds, starting at the
 * one its two indexes add up to and going round from there, and then
 * the positions after them. On a complete round robin of an even number
 * of participants with no pins this alone gives the fewest rounds there
 * can be (it is the circle method). Where two participants have several
 * events between them, the rounds are taken as that many blocks and the
 * second of the events starts in the second block, so that a round
 * robin of several legs is one circle per leg. What that leaves over is
 * placed by the search the last placement step uses
 * (LeftoverRecolourer: direct moves, alternating paths, exchanges), and
 * an event it cannot place either comes after every round.
 *
 * The search is given no step budget. Its own limit on position checks
 * (LeftoverRecolourer::workLimitFor()) bounds it instead, so the rounds,
 * and with them the order of the events, never depend on the caller's
 * budget.
 *
 * @internal
 */
final class RoundColouring
{
    /**
     * @param array<int, array{int, int}> $edges Event index => participant index pair, the events
     *                                           between the same two participants next to each other
     * @param array<int, array<int, array<int>>> $pinSlots Session => participant => pinned slots
     * @param array<int, array<int, int>> $pinCounts Session => slot => pinned event count
     * @param array<int, int> $slotCounts Session => slot count
     *
     * @return array<int, array{int, int}> Event index => [session, slot] of its round's position, in
     *                                     ascending event index order; an event the rounds could not
     *                                     hold has no entry
     */
    public static function of(
        array $edges,
        array $pinSlots,
        array $pinCounts,
        array $slotCounts,
        int $capacityPerSlot
    ): array {
        if ($edges === []) {
            return [];
        }

        /** @var list<array{int, int}> $positionList */
        $positionList = [];
        /** @var array<int, array<int, int>> $indexOf */
        $indexOf = [];
        $room = [];
        $taken = [];
        foreach ($slotCounts as $session => $slotCount) {
            for ($slot = 0; $slot < $slotCount; ++$slot) {
                $indexOf[$session][$slot] = count($positionList);
                $room[] = $capacityPerSlot - ($pinCounts[$session][$slot] ?? 0);
                $positionList[] = [$session, $slot];
            }
        }
        foreach ($pinSlots as $session => $byParticipant) {
            foreach ($byParticipant as $pid => $slots) {
                foreach ($slots as $slot) {
                    $taken[$indexOf[$session][$slot]][$pid] = true;
                }
            }
        }
        $positionCount = count($positionList);

        $degree = [];
        $copy = [];
        $copies = [];
        foreach ($edges as $eventIndex => [$a, $b]) {
            $degree[$a] = ($degree[$a] ?? 0) + 1;
            $degree[$b] = ($degree[$b] ?? 0) + 1;
            $copy[$eventIndex] = $copies[$a][$b] ?? 0;
            $copies[$a][$b] = $copy[$eventIndex] + 1;
        }
        $rounds = min(
            $positionCount,
            max(max($degree), intdiv(count($edges) + $capacityPerSlot - 1, $capacityPerSlot))
        );
        $block = intdiv($rounds + max($copy), max($copy) + 1);

        /** @var array<int, array{int, int}> $positions Event => [session, slot] */
        $positions = [];
        $left = [];
        foreach ($edges as $eventIndex => [$a, $b]) {
            $start = $copy[$eventIndex] * $block + ($a + $b) % $block;
            for ($turn = 0; $turn < $rounds; ++$turn) {
                $index = ($start + $turn) % $rounds;
                if ($room[$index] > 0 && !isset($taken[$index][$a]) && !isset($taken[$index][$b])) {
                    $positions[$eventIndex] = $positionList[$index];
                    $taken[$index][$a] = true;
                    $taken[$index][$b] = true;
                    --$room[$index];
                    continue 2;
                }
            }
            $left[] = $eventIndex;
        }

        // What is left over is searched for first on those rounds alone,
        // so that an event goes to a later position only when the rounds
        // cannot be made to hold it, and then on the whole grid
        if ($left !== [] && $rounds < $positionCount) {
            $firstSlotCounts = [];
            $firstPinSlots = [];
            $firstPinCounts = [];
            $before = 0;
            foreach ($slotCounts as $session => $slotCount) {
                if ($before >= $rounds) {
                    break;
                }
                $kept = min($slotCount, $rounds - $before);
                $firstSlotCounts[$session] = $kept;
                foreach ($pinSlots[$session] ?? [] as $pid => $slots) {
                    $slots = array_values(array_filter($slots, static fn(int $slot): bool => $slot < $kept));
                    if ($slots !== []) {
                        $firstPinSlots[$session][$pid] = $slots;
                    }
                }
                foreach ($pinCounts[$session] ?? [] as $slot => $count) {
                    if ($slot < $kept) {
                        $firstPinCounts[$session][$slot] = $count;
                    }
                }
                $before += $slotCount;
            }
            $left = (new LeftoverRecolourer(new StepBudget(PHP_INT_MAX)))->place(
                $edges,
                $positions,
                $left,
                $firstPinSlots,
                $firstPinCounts,
                $firstSlotCounts,
                $capacityPerSlot
            );
        }
        if ($left !== []) {
            (new LeftoverRecolourer(new StepBudget(PHP_INT_MAX)))->place(
                $edges,
                $positions,
                $left,
                $pinSlots,
                $pinCounts,
                $slotCounts,
                $capacityPerSlot
            );
        }

        ksort($positions);

        return $positions;
    }
}
