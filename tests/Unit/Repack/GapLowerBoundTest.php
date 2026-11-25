<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Repack\Internal\GapLowerBound;
use MissionGaming\Tactician\Repack\MovableEvent;
use MissionGaming\Tactician\Repack\PinnedEvent;
use MissionGaming\Tactician\Repack\SessionGrid;
use MissionGaming\Tactician\Tests\Support\RepackBruteForce;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/** Three participants who all meet each other */
const TRIANGLE = [[0, 1], [1, 2], [0, 2]];

/** Five participants in a ring */
const FIVE_RING = [[0, 1], [1, 2], [2, 3], [3, 4], [0, 4]];

/** Four participants who all meet each other */
const FOUR_ALL = [[0, 1], [0, 2], [0, 3], [1, 2], [1, 3], [2, 3]];

describe('GapLowerBound', function (): void {
    it('needs a gap slot for three participants who all meet in the one session there is', function (): void {
        // Each has two events, and their slots would have to be next to
        // each other for all three: no three numbers are each one apart
        foreach ([3, 6, 100] as $slots) {
            expect(GapLowerBound::of(TRIANGLE, [], [], [$slots], 1))->toBe(1)
                ->and(GapLowerBound::of(TRIANGLE, [], [], [$slots], 3))->toBe(1);
        }
    });

    it('finds the fewest gap slots of a small group exactly', function (): void {
        // A ring of five on slots of one event needs three: the
        // participant whose two events are not next to each other waits
        // for the other three. With two events to a slot it needs one.
        // Four who all meet, one event to a slot, need five
        expect(GapLowerBound::of(FIVE_RING, [], [], [10], 1))->toBe(3)
            ->and(GapLowerBound::of(FIVE_RING, [], [], [10], 2))->toBe(1)
            ->and(GapLowerBound::of(FOUR_ALL, [], [], [6], 1))->toBe(5);
    });

    it('gives the bounds that need no search when the exact one stops at its limit', function (): void {
        // Parity gives the ring of five one gap slot; one event to a slot
        // gives the four who all meet 6 - 4 + 1 = 3. Neither group is
        // searched with a limit of one slot check
        expect(GapLowerBound::of(FIVE_RING, [], [], [10], 1, 1))->toBe(1)
            ->and(GapLowerBound::of(FOUR_ALL, [], [], [6], 1, 1))->toBe(3)
            ->and(GapLowerBound::of(FOUR_ALL, [], [], [6], 2, 1))->toBe(0);
    });

    it('counts two events between the same two participants as able to follow each other', function (): void {
        // 0 meets 1 twice and 1 meets 2: in that order, one to a slot, 0
        // plays two slots in a row and 1 three, so nobody waits
        expect(GapLowerBound::of([[0, 1], [0, 1], [1, 2]], [], [], [3], 1))->toBe(0);
    });

    it('says nothing of events that may be played in another session', function (): void {
        // With a second session the triangle can be split, and none of
        // its participants needs to wait
        expect(GapLowerBound::of(TRIANGLE, [], [], [3, 3], 1))->toBe(0);
    });

    it('counts an event as able to go to a session only where a slot has room for it', function (): void {
        // The second session has one slot, which a pin of two others
        // fills: the triangle has the first session alone
        $pinSlots = [1 => [3 => [0], 4 => [0]]];
        $pinCounts = [1 => [0 => 1]];

        expect(GapLowerBound::of(TRIANGLE, $pinSlots, $pinCounts, [3, 1], 1))->toBe(1);
    });

    it('says nothing of a group whose participants have something else in the session', function (): void {
        // A pin of participant 0 in the session, or an event of 2 that can
        // be played in either session, is something the group's parity
        // does not account for
        expect(GapLowerBound::of(TRIANGLE, [0 => [0 => [5], 9 => [5]]], [0 => [5 => 1]], [6], 1))->toBe(0);

        // 0 and 1 are pinned in the one slot of session 1, so the three
        // events of the triangle have session 0 alone; an event of 2 and 3
        // can go to either, and with it the triangle says nothing
        $pinSlots = [1 => [0 => [0], 1 => [0]]];
        $pinCounts = [1 => [0 => 1]];
        expect(GapLowerBound::of(TRIANGLE, $pinSlots, $pinCounts, [6, 1], 2))->toBe(1)
            ->and(GapLowerBound::of([...TRIANGLE, [2, 3]], $pinSlots, $pinCounts, [6, 1], 2))->toBe(0);
    });

    it('adds the bounds of groups that share no participant', function (): void {
        $two = [...TRIANGLE, [3, 4], [4, 5], [3, 5]];

        expect(GapLowerBound::of($two, [], [], [20], 1))->toBe(2)
            ->and(GapLowerBound::of($two, [], [], [20], 2))->toBe(2);
    });

    it('is never more than the fewest gap slots of every placement, on small requests', function (): void {
        // Against the exhaustive search, on requests of one or two
        // sessions small enough for it: random events, a pin now and then
        $random = new Randomizer(new Xoshiro256StarStar(169));
        $reached = 0;
        for ($case = 0; $case < 300; ++$case) {
            $participantCount = $random->getInt(3, 5);
            $participants = [];
            for ($index = 0; $index < $participantCount; ++$index) {
                $participants[] = new Participant("p{$index}", "p{$index}");
            }
            $sessions = $random->getInt(1, 2);
            $slots = $random->getInt(2, 4);
            $capacity = $random->getInt(1, 2);
            $grid = SessionGrid::shapeOnly($sessions, $slots, [], $capacity);

            $edges = [];
            $movable = [];
            $pinned = [];
            $pinSlots = [];
            $pinCounts = [];
            for ($index = 0, $count = $random->getInt(2, 6); $index < $count; ++$index) {
                $a = $random->getInt(0, $participantCount - 1);
                $b = ($a + $random->getInt(1, $participantCount - 1)) % $participantCount;
                if ($random->getInt(1, 6) === 1) {
                    $session = $random->getInt(0, $sessions - 1);
                    $slot = $random->getInt(0, $slots - 1);
                    if (
                        ($pinCounts[$session][$slot] ?? 0) < $capacity
                        && !in_array($slot, $pinSlots[$session][$a] ?? [], true)
                        && !in_array($slot, $pinSlots[$session][$b] ?? [], true)
                    ) {
                        $pinned[] = new PinnedEvent("x{$index}", $participants[$a], $participants[$b], $session, $slot);
                        $pinSlots[$session][$a][] = $slot;
                        $pinSlots[$session][$b][] = $slot;
                        $pinCounts[$session][$slot] = ($pinCounts[$session][$slot] ?? 0) + 1;

                        continue;
                    }
                }
                $movable[] = new MovableEvent("m{$index}", $participants[$a], $participants[$b]);
                $edges[] = [$a, $b];
            }
            if ($movable === [] || RepackBruteForce::maxPlaced($movable, $pinned, $grid) < count($movable)) {
                // The bound is of placements of every event
                continue;
            }

            $fewest = RepackBruteForce::fewestGapSlots($movable, $pinned, $grid);
            $bound = GapLowerBound::of($edges, $pinSlots, $pinCounts, array_fill(0, $sessions, $slots), $capacity);

            expect($bound)->toBeLessThanOrEqual($fewest);
            if ($bound > 0 && $bound === $fewest) {
                ++$reached;
            }
        }

        // And it is not a bound of nothing: some of these it reaches
        expect($reached)->toBeGreaterThan(0);
    });

    it('is never more than the fewest gap slots on irregular grids, with or without the exact search', function (): void {
        // Against the exhaustive search again, on the shapes the first
        // property leaves out: sessions of different lengths (some of a
        // single slot), up to three sessions, three events to a slot,
        // pins more often, two participants alone. The bounds that need
        // no search (a work limit of one slot check) are never more than
        // the exact one, which is never more than the fewest there are
        $random = new Randomizer(new Xoshiro256StarStar(16_900));
        $reached = 0;
        $checked = 0;
        for ($case = 0; $case < 600; ++$case) {
            $participantCount = $random->getInt(2, 5);
            $participants = [];
            for ($index = 0; $index < $participantCount; ++$index) {
                $participants[] = new Participant("p{$index}", "p{$index}");
            }
            $sessions = $random->getInt(1, 3);
            $slotCounts = [];
            for ($session = 0; $session < $sessions; ++$session) {
                $slotCounts[$session] = $random->getInt(1, 5);
            }
            $capacity = $random->getInt(1, 3);
            $grid = SessionGrid::shapeOnly($sessions, 1, $slotCounts, $capacity);

            $edges = [];
            $movable = [];
            $pinned = [];
            $pinSlots = [];
            $pinCounts = [];
            for ($index = 0, $count = $random->getInt(2, 7); $index < $count; ++$index) {
                $a = $random->getInt(0, $participantCount - 1);
                $b = ($a + $random->getInt(1, $participantCount - 1)) % $participantCount;
                if ($random->getInt(1, 3) === 1) {
                    $session = $random->getInt(0, $sessions - 1);
                    $slot = $random->getInt(0, $slotCounts[$session] - 1);
                    if (
                        ($pinCounts[$session][$slot] ?? 0) < $capacity
                        && !in_array($slot, $pinSlots[$session][$a] ?? [], true)
                        && !in_array($slot, $pinSlots[$session][$b] ?? [], true)
                    ) {
                        $pinned[] = new PinnedEvent("x{$index}", $participants[$a], $participants[$b], $session, $slot);
                        $pinSlots[$session][$a][] = $slot;
                        $pinSlots[$session][$b][] = $slot;
                        $pinCounts[$session][$slot] = ($pinCounts[$session][$slot] ?? 0) + 1;

                        continue;
                    }
                }
                $movable[] = new MovableEvent("m{$index}", $participants[$a], $participants[$b]);
                $edges[] = [$a, $b];
            }
            if (
                $movable === []
                || array_sum($slotCounts) ** count($movable) > 200_000
                || RepackBruteForce::maxPlaced($movable, $pinned, $grid) < count($movable)
            ) {
                // Too large for the exhaustive search, or not every event
                // can be placed, which is what the bound is of
                continue;
            }

            ++$checked;
            $fewest = RepackBruteForce::fewestGapSlots($movable, $pinned, $grid);
            $bound = GapLowerBound::of($edges, $pinSlots, $pinCounts, $slotCounts, $capacity);
            $withoutSearch = GapLowerBound::of($edges, $pinSlots, $pinCounts, $slotCounts, $capacity, 1);

            expect($bound)->toBeLessThanOrEqual($fewest)
                ->and($withoutSearch)->toBeLessThanOrEqual($bound);
            if ($bound > 0 && $bound === $fewest) {
                ++$reached;
            }
        }

        expect($checked)->toBeGreaterThan(300)
            ->and($reached)->toBeGreaterThan(0);
    });
});
