<?php

declare(strict_types=1);

use MissionGaming\Tactician\Repack\Internal\LeftoverRecolourer;
use MissionGaming\Tactician\Repack\Internal\LoadPlanner;
use MissionGaming\Tactician\Repack\Internal\StepBudget;

describe('LeftoverRecolourer', function (): void {
    it('places an event where a position is free for it without spending a step', function (): void {
        $budget = new StepBudget(0);
        $positions = [0 => [0, 0]];

        $left = (new LeftoverRecolourer($budget))->place([[0, 1], [2, 3]], $positions, [1], [], [], [0 => 2], 1);

        expect($left)->toBe([])
            ->and($positions)->toBe([0 => [0, 0], 1 => [0, 1]])
            ->and($budget->stoppedASearch())->toBeFalse();
    });

    it('moves a placed event along an alternating path to open a position', function (): void {
        // One session of three slots, capacity 2, participant 0 pinned at
        // slot 2. Event 2 (0 v 1) finds participant 0 busy at slot 0 and
        // participant 1 busy at slot 1: event 0 (0 v 2) moves to slot 1
        $positions = [0 => [0, 0], 1 => [0, 1]];
        $budget = new StepBudget(10);

        $left = (new LeftoverRecolourer($budget))->place(
            [[0, 2], [1, 3], [0, 1]],
            $positions,
            [2],
            [0 => [0 => [2], 9 => [2]]],
            [0 => [2 => 1]],
            [0 => 3],
            2
        );

        expect($left)->toBe([])
            ->and($positions)->toBe([0 => [0, 1], 1 => [0, 1], 2 => [0, 0]])
            ->and($budget->stoppedASearch())->toBeFalse();
    });

    it('takes every move back when the budget stops the path half way', function (): void {
        // The same request, with a step for the first node of the path and
        // none for the second
        $positions = [0 => [0, 0], 1 => [0, 1]];
        $budget = new StepBudget(1);

        $left = (new LeftoverRecolourer($budget))->place(
            [[0, 2], [1, 3], [0, 1]],
            $positions,
            [2],
            [0 => [0 => [2], 9 => [2]]],
            [0 => [2 => 1]],
            [0 => 3],
            2
        );

        expect($left)->toBe([2])
            ->and($positions)->toBe([0 => [0, 0], 1 => [0, 1]])
            ->and($budget->stoppedASearch())->toBeTrue();
    });

    it('lifts both events in the way when the event can go nowhere else', function (): void {
        // Event 2 (0 v 1) can only play slot 0: participant 0 is pinned at
        // slot 1 and participant 1 at slot 2. Events 0 (0 v 2) and 1 (1 v 3)
        // are both in its way there, and each has a slot of its own to go to
        $positions = [0 => [0, 0], 1 => [0, 0]];

        $left = (new LeftoverRecolourer(new StepBudget(10)))->place(
            [[0, 2], [1, 3], [0, 1]],
            $positions,
            [2],
            [0 => [0 => [1], 7 => [1], 1 => [2], 8 => [2]]],
            [0 => [1 => 1, 2 => 1]],
            [0 => 3],
            2
        );

        expect($left)->toBe([])
            ->and($positions)->toBe([0 => [0, 2], 1 => [0, 1], 2 => [0, 0]]);
    });

    it('moves an event out of a full position nobody in it blocks', function (): void {
        // Capacity 2. Slot 0 holds events 0 and 1, which share nobody with
        // event 3 (2 v 3); participant 2 is busy at slot 1 and participant 3
        // pinned at slot 2. Event 0 moves to slot 1 and event 3 takes its place
        $positions = [0 => [0, 0], 1 => [0, 0], 2 => [0, 1]];

        $left = (new LeftoverRecolourer(new StepBudget(10)))->place(
            [[0, 1], [4, 5], [2, 6], [2, 3]],
            $positions,
            [3],
            [0 => [3 => [2], 9 => [2]]],
            [0 => [2 => 1]],
            [0 => 3],
            2
        );

        expect($left)->toBe([])
            ->and($positions)->toBe([0 => [0, 1], 1 => [0, 0], 2 => [0, 1], 3 => [0, 0]]);
    });

    it('exchanges an event out of the schedule when that lets two in', function (): void {
        // Two one-slot sessions, no capacity limit to speak of. Participant
        // 3 has three events and two positions. Event 2 (3 v 0) takes the
        // place of event 0 (3 v 1), and event 3 (2 v 1) then fits beside it:
        // three events placed where there were two
        $positions = [0 => [0, 0], 1 => [1, 0]];

        $left = (new LeftoverRecolourer(new StepBudget(100)))->place(
            [[3, 1], [2, 3], [3, 0], [2, 1]],
            $positions,
            [2, 3],
            [],
            [],
            [0 => 1, 1 => 1],
            9
        );

        expect($left)->toBe([0])
            ->and($positions)->toBe([1 => [1, 0], 2 => [0, 0], 3 => [0, 0]]);
    });

    it('leaves the schedule as it was when no move places one more event', function (): void {
        // A triangle on two positions: one of its events never fits
        $positions = [0 => [0, 0], 1 => [0, 1]];
        $budget = new StepBudget(1_000);

        $left = (new LeftoverRecolourer($budget))->place([[0, 1], [1, 2], [0, 2]], $positions, [2], [], [], [0 => 2], 9);

        expect($left)->toBe([2])
            ->and($positions)->toBe([0 => [0, 0], 1 => [0, 1]])
            ->and($budget->stoppedASearch())->toBeFalse();
    });

    it('spends no step on an event whose participant has no free position left', function (): void {
        // Participant 0 already plays the only slot
        $positions = [0 => [0, 0]];
        $budget = new StepBudget(0);

        $left = (new LeftoverRecolourer($budget))->place([[0, 1], [0, 2]], $positions, [1], [], [], [0 => 1], 2);

        expect($left)->toBe([1])
            ->and($positions)->toBe([0 => [0, 0]])
            ->and($budget->stoppedASearch())->toBeFalse();
    });

    it('never moves a pinned participant onto its pinned position', function (): void {
        // Participant 1 is pinned at the only other slot, so event 1 (1 v 2)
        // cannot go there, and event 0 is in its way at slot 0
        $positions = [0 => [0, 0]];

        $left = (new LeftoverRecolourer(new StepBudget(100)))->place(
            [[0, 1], [1, 2]],
            $positions,
            [1],
            [0 => [1 => [1], 5 => [1]]],
            [0 => [1 => 1]],
            [0 => 2],
            2
        );

        expect($left)->toBe([1])
            ->and($positions)->toBe([0 => [0, 0]]);
    });
});

describe('LoadPlanner::sharedDrops', function (): void {
    it('takes as many shared events as the shortfalls allow, not the first ones it meets', function (): void {
        // A path 0 - 1 - 2 - 3, each participant one event short. Taking
        // the middle event first, as index order would, covers two of the
        // four; the two end events cover all four
        expect(LoadPlanner::sharedDrops([0 => [1, 2], 1 => [0, 1], 2 => [2, 3]], [0 => 1, 1 => 1, 2 => 1, 3 => 1]))
            ->toBe([1, 2]);
    });

    it('takes no more shared events at a participant than its shortfall', function (): void {
        expect(LoadPlanner::sharedDrops([0 => [0, 1], 1 => [0, 1], 2 => [0, 1]], [0 => 1, 1 => 3]))->toBe([0]);
    });

    it('ignores events with a participant that is not short', function (): void {
        expect(LoadPlanner::sharedDrops([0 => [0, 1], 1 => [1, 2]], [0 => 1, 2 => 1]))->toBe([]);
    });

    it('finds a largest set on a cycle with a participant short by two', function (): void {
        // Participant 0 needs two, the others one each: six units of need,
        // two per event, so three events is the most there can be, and the
        // cycle 0 - 1 - 3 - 4 - 2 - 0 has three that fit
        $edges = [0 => [0, 1], 1 => [0, 2], 2 => [1, 3], 3 => [2, 4], 4 => [3, 4]];
        $need = [0 => 2, 1 => 1, 2 => 1, 3 => 1, 4 => 1];

        $chosen = LoadPlanner::sharedDrops($edges, $need);

        expect($chosen)->toHaveCount(3);
        $used = [];
        foreach ($chosen as $eventIndex) {
            foreach ($edges[$eventIndex] as $pid) {
                $used[$pid] = ($used[$pid] ?? 0) + 1;
            }
        }
        foreach ($used as $pid => $count) {
            expect($count)->toBeLessThanOrEqual($need[$pid]);
        }
    });
});
