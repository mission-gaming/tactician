<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Repack\Internal\LeftoverRecolourer;
use MissionGaming\Tactician\Repack\Internal\LoadPlanner;
use MissionGaming\Tactician\Repack\Internal\StepBudget;
use MissionGaming\Tactician\Repack\RepackOptions;
use MissionGaming\Tactician\Repack\UnplacedEvent;

/**
 * No participant is twice at one position, counting the pins, which also
 * says that no event is where one of its participants is pinned.
 *
 * @param list<array{int, int}> $edges
 * @param array<int, array{int, int}> $positions Event index => [session, slot]
 * @param array<int, array<int, array<int>>> $pinSlots Session => participant => pinned slots
 */
function expectProperPositions(array $edges, array $positions, array $pinSlots): void
{
    $busy = [];
    foreach ($pinSlots as $session => $byParticipant) {
        foreach ($byParticipant as $pid => $slots) {
            foreach ($slots as $slot) {
                $busy["{$session}:{$slot}"][$pid] = true;
            }
        }
    }
    foreach ($positions as $eventIndex => [$session, $slot]) {
        foreach ($edges[$eventIndex] as $pid) {
            expect(isset($busy["{$session}:{$slot}"][$pid]))->toBeFalse();
            $busy["{$session}:{$slot}"][$pid] = true;
        }
    }
}

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

    it('lets a path move an event that an exchange brought in', function (): void {
        // Participant 0 has four events and three positions (it is pinned
        // in the second session), so five of the six events is the most.
        // Events 3 (0 v 4) and 4 (1 v 2) are unplaced, and each of them
        // collides with two events or a pin wherever it goes but for one
        // exchange: event 3 for event 2 at the first slot. Event 4 then
        // needs the last slot, and the events it lifts can only go where
        // event 3 was just put, so event 3 has to move on. While an
        // exchanged event could not be moved, four events stayed placed
        $edges = [[0, 1], [0, 2], [0, 3], [0, 4], [1, 2], [1, 3]];
        $positions = [0 => [0, 1], 1 => [0, 2], 2 => [0, 0], 5 => [0, 2]];
        $pinSlots = [0 => [3 => [1], 4 => [1], 2 => [0], 5 => [0]], 1 => [2 => [0], 5 => [0], 1 => [0], 0 => [0]]];

        $left = (new LeftoverRecolourer(new StepBudget(1_000)))->place(
            $edges,
            $positions,
            [3, 4],
            $pinSlots,
            [0 => [1 => 1, 0 => 1], 1 => [0 => 2]],
            [0 => 3, 1 => 1],
            4
        );

        expect($left)->toHaveCount(1)
            ->and($positions)->toHaveCount(5);
        expectProperPositions($edges, $positions, $pinSlots);
    });

    it('takes two events out for one when two of the events waiting then fit', function (): void {
        // Two sessions, of two slots and of one, two events to a slot;
        // participants 0 and 1 are pinned at the second slot and 0 and 2
        // at the third. Events 3 (0 v 3) and 4 (1 v 2) are unplaced, and
        // the first slot is the only one either can play: both events
        // there are in the way of each. No exchange for one event exists.
        // Event 3 takes the slot, events 0 (0 v 1) and 1 (2 v 3) leave it,
        // event 1 goes to the second slot and event 4 beside event 3:
        // four events placed where there were three
        $edges = [[0, 1], [2, 3], [1, 3], [0, 3], [1, 2]];
        $positions = [0 => [0, 0], 1 => [0, 0], 2 => [1, 0]];
        $pinSlots = [0 => [0 => [1], 1 => [1]], 1 => [0 => [0], 2 => [0]]];
        $budget = new StepBudget(1_000);

        $left = (new LeftoverRecolourer($budget))->place(
            $edges,
            $positions,
            [3, 4],
            $pinSlots,
            [0 => [1 => 1], 1 => [0 => 1]],
            [0 => 2, 1 => 1],
            2
        );

        expect($left)->toBe([0])
            ->and($positions)->toBe([1 => [0, 1], 2 => [1, 0], 3 => [0, 0], 4 => [0, 0]])
            ->and($budget->stepsLeft())->toBe(999);
    });

    it('puts one more event at a position along an augmenting path longer than the exchanges reach', function (): void {
        // One slot of four events. Events 0 to 2 (2 v 3, 4 v 5, 6 v 7) are
        // placed and participants 0 and 1 are free. The unplaced events
        // 0 v 2, 3 v 4, 5 v 6 and 7 v 1 fit together, but only together:
        // all three placed events have to leave, one more than a chain of
        // two exchanges takes out, and there is no other slot for a path
        $edges = [[2, 3], [4, 5], [6, 7], [0, 2], [3, 4], [5, 6], [7, 1]];
        $positions = [0 => [0, 0], 1 => [0, 0], 2 => [0, 0]];
        $budget = new StepBudget(1_000);

        $left = (new LeftoverRecolourer($budget))->place($edges, $positions, [3, 4, 5, 6], [], [], [0 => 1], 4);

        expect($left)->toBe([0, 1, 2])
            ->and($positions)->toBe([3 => [0, 0], 4 => [0, 0], 5 => [0, 0], 6 => [0, 0]])
            ->and($budget->stoppedASearch())->toBeFalse();
    });

    it('starts no augmenting path at a position that is full or that the budget cannot pay for', function (): void {
        // The same request with three events to the slot: it is full, and
        // nothing changes
        $positions = [0 => [0, 0], 1 => [0, 0], 2 => [0, 0]];

        $left = (new LeftoverRecolourer(new StepBudget(1_000)))->place(
            [[2, 3], [4, 5], [6, 7], [0, 2], [3, 4], [5, 6], [7, 1]],
            $positions,
            [3, 4, 5, 6],
            [],
            [],
            [0 => 1],
            3
        );

        expect($left)->toBe([3, 4, 5, 6])
            ->and($positions)->toBe([0 => [0, 0], 1 => [0, 0], 2 => [0, 0]]);

        // And with room but no step: each unplaced event has a participant
        // that already plays the only position, so no alternating path is
        // started and none asks for a step; the augmenting path asks for
        // the first and is refused
        $positions = [0 => [0, 0], 1 => [0, 0], 2 => [0, 0]];
        $budget = new StepBudget(0);

        $left = (new LeftoverRecolourer($budget))->place(
            [[2, 3], [4, 5], [6, 7], [0, 2], [3, 4], [5, 6], [7, 1]],
            $positions,
            [3, 4, 5, 6],
            [],
            [],
            [0 => 1],
            4
        );

        expect($left)->toBe([3, 4, 5, 6])
            ->and($positions)->toBe([0 => [0, 0], 1 => [0, 0], 2 => [0, 0]])
            ->and($budget->stoppedASearch())->toBeTrue();
    });

    it('says how many events no placement of the request can exceed', function (): void {
        // A triangle on two positions: a position holds one of its events
        // at most, so two is the ceiling, and the two placed reach it
        $positions = [0 => [0, 0], 1 => [0, 1]];
        $recolourer = new LeftoverRecolourer(new StepBudget(1_000));

        $recolourer->place([[0, 1], [1, 2], [0, 2]], $positions, [2], [], [], [0 => 2], 9);

        expect($recolourer->lastCeiling())->toBe(2);

        // Four events of one slot's worth of participants, three placed
        $positions = [0 => [0, 0], 1 => [0, 0], 2 => [0, 0]];
        $recolourer->place([[2, 3], [4, 5], [6, 7], [0, 2], [3, 4], [5, 6], [7, 1]], $positions, [3, 4, 5, 6], [], [], [0 => 1], 4);

        expect($recolourer->lastCeiling())->toBe(4);
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

    it('searches for no exchange when the events left over are as many as no placement can hold', function (): void {
        // Participant 0 has four events for two slots, so two of them never
        // fit, and the two placed are the most there can be. Without the
        // count of events no placement holds, the ceiling is three and the
        // exchange search asks the empty budget for a step
        $positions = [0 => [0, 0], 1 => [0, 1]];
        $budget = new StepBudget(0);

        $left = (new LeftoverRecolourer($budget))->place(
            [[0, 1], [0, 2], [0, 3], [0, 4]],
            $positions,
            [2, 3],
            [],
            [],
            [0 => 2],
            9,
            2
        );

        expect($left)->toBe([2, 3])
            ->and($positions)->toBe([0 => [0, 0], 1 => [0, 1]])
            ->and($budget->stoppedASearch())->toBeFalse();
    });

    it('starts no search once its position checks pass the work limit, and leaves the budget flag alone', function (): void {
        // The alternating-path request above: the direct pass makes three
        // position checks, and the path needs more. With a limit of three
        // the path's second node is refused and the first is taken back;
        // with a limit of two no path is started at all. Neither is a
        // budget stopping a search
        foreach ([2, 3] as $limit) {
            $positions = [0 => [0, 0], 1 => [0, 1]];
            $budget = new StepBudget(1_000);

            $left = (new LeftoverRecolourer($budget, $limit))->place(
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
                ->and($budget->stoppedASearch())->toBeFalse();
        }
    });

    it('has the share of its work limit that a budget below the default leaves, and tells the budget when that stops a search', function (): void {
        // The exchange request above, on steps that do not run out. At the
        // default budget the step has its whole limit of 500,000 position
        // checks and the exchange places a third event. At a budget of one
        // step it has eight (one for each of the four events at each of
        // the two positions; its share of the whole limit would be two),
        // the direct and path rounds use them up, and no exchange is
        // searched for. A larger budget would have searched, so the budget
        // records a search it stopped
        $place = static function (StepBudget $budget): array {
            $positions = [0 => [0, 0], 1 => [1, 0]];
            $left = (new LeftoverRecolourer($budget))->place(
                [[3, 1], [2, 3], [3, 0], [2, 1]],
                $positions,
                [2, 3],
                [],
                [],
                [0 => 1, 1 => 1],
                9
            );

            return [$left, $positions];
        };

        $default = new StepBudget(PHP_INT_MAX, StepBudget::DEFAULT_STEPS);
        [$left, $positions] = $place($default);
        expect($left)->toHaveCount(1)
            ->and($positions)->toHaveCount(3)
            ->and($default->stoppedASearch())->toBeFalse();

        $oneStep = new StepBudget(PHP_INT_MAX, 1);
        [$left, $positions] = $place($oneStep);
        expect($left)->toBe([2, 3])
            ->and($positions)->toBe([0 => [0, 0], 1 => [1, 0]])
            ->and($oneStep->stoppedASearch())->toBeTrue();
    });

    it('keeps a position check for every event and position however small the budget is', function (): void {
        // The alternating-path request above at a budget of one step. The
        // budget's share of the limit is two position checks, which starts
        // no path (the test of the work limit above). The step has nine all
        // the same, one for each of three events at each of three
        // positions, and that is enough for the path: the event is placed,
        // and no search was stopped
        $positions = [0 => [0, 0], 1 => [0, 1]];
        $budget = new StepBudget(PHP_INT_MAX, 1);

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
            ->and($positions)->toHaveCount(3)
            ->and($budget->stoppedASearch())->toBeFalse()
            ->and(LeftoverRecolourer::WORK_PER_EVENT_AND_POSITION_AT_LEAST)->toBe(1)
            ->and(LeftoverRecolourer::workLimitAtLeast(3, 3))->toBe(9)
            ->and(LeftoverRecolourer::workLimitAtLeast(1_460, 78))->toBe(113_880)
            // Never more than the limit the default budget gives
            ->and(LeftoverRecolourer::workLimitAtLeast(10_000, 400))->toBe(3_000_000);
    });

    it('does not report its work limit for an event it would not have searched for anyway', function (): void {
        // Participant 0 has four events for two slots (the request above
        // that searches for no exchange), at a budget below the default and
        // with a limit of two position checks, which the direct pass over
        // the two events left passes. Neither gets a path, because
        // participant 0 already plays in every slot, and that is asked
        // before the limit: no larger budget would have searched, so
        // nothing is recorded
        $positions = [0 => [0, 0], 1 => [0, 1]];
        $budget = new StepBudget(PHP_INT_MAX, 1);

        $left = (new LeftoverRecolourer($budget, 2))->place(
            [[0, 1], [0, 2], [0, 3], [0, 4]],
            $positions,
            [2, 3],
            [],
            [],
            [0 => 2],
            9,
            2
        );

        expect($left)->toBe([2, 3])
            ->and($positions)->toBe([0 => [0, 0], 1 => [0, 1]])
            ->and($budget->stoppedASearch())->toBeFalse();
    });

    it('searches for no exchange once its position checks pass the work limit', function (): void {
        // The exchange request above, which places a third event with the
        // default limit; its direct and path rounds alone pass a limit of 10
        $positions = [0 => [0, 0], 1 => [1, 0]];

        $left = (new LeftoverRecolourer(new StepBudget(100), 10))->place(
            [[3, 1], [2, 3], [3, 0], [2, 1]],
            $positions,
            [2, 3],
            [],
            [],
            [0 => 1, 1 => 1],
            9
        );

        expect($left)->toBe([2, 3])
            ->and($positions)->toBe([0 => [0, 0], 1 => [1, 0]]);
    });

    it('gives the repacker a work limit of 500,000 position checks, and more on a large request', function (): void {
        // The figures the design note and the changelog state and measured:
        // a hundred checks for every event and position, never fewer than
        // half a million and never more than three million
        expect(LeftoverRecolourer::WORK_LIMIT)->toBe(500_000)
            ->and(LeftoverRecolourer::WORK_PER_EVENT_AND_POSITION)->toBe(100)
            ->and(LeftoverRecolourer::WORK_LIMIT_AT_MOST)->toBe(3_000_000)
            ->and(LeftoverRecolourer::workLimitFor(200, 20))->toBe(500_000)
            ->and(LeftoverRecolourer::workLimitFor(50, 100))->toBe(500_000)
            ->and(LeftoverRecolourer::workLimitFor(50, 101))->toBe(505_000)
            ->and(LeftoverRecolourer::workLimitFor(600, 30))->toBe(1_800_000)
            ->and(LeftoverRecolourer::workLimitFor(600, 50))->toBe(3_000_000)
            ->and(LeftoverRecolourer::workLimitFor(1_460, 75))->toBe(3_000_000)
            ->and(LeftoverRecolourer::workLimitFor(0, 0))->toBe(500_000);
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

describe('LoadPlanner', function (): void {
    it('tells the last placement step how many events no placement can hold', function (): void {
        // The first participant has seven events and five positions (five
        // sessions of one slot), so two of them fit nowhere. The last
        // placement step takes this number out of its ceiling, and so does
        // not search for a place for the two that are dropped
        $participants = [];
        for ($i = 0; $i < 6; ++$i) {
            $participants[] = new Participant("p{$i}", "P{$i}");
        }
        $edges = [[0, 2], [0, 5], [1, 3], [1, 4], [0, 1], [0, 5], [0, 2], [0, 5], [3, 5], [0, 3]];

        $plan = (new LoadPlanner(new RepackOptions(), new StepBudget(1_000)))->plan(
            $edges,
            [1 => [3 => [0], 4 => [0]]],
            [1 => [0 => 1]],
            [1, 1, 1, 1, 1],
            4,
            ['m01', 'm02', 'm03', 'm04', 'm05', 'm06', 'm07', 'm08', 'm09', 'm10'],
            $participants
        );

        expect($plan->leftOutAtLeast)->toBe(2)
            ->and($plan->shortfalls)->toBe([0 => 2]);
    });

    it('searches for the events two over-capacity participants share on the share of the node limit its budget leaves', function (): void {
        // One session of two slots. Participants 0 and 1 are short by one
        // event and 2 by two (1 and 2 are each pinned at one slot against
        // somebody from outside). Dropping the event between 0 and 1 covers
        // two of the four; dropping one between 0 and 2 and the one between
        // 1 and 2 covers all four, which the search finds at the default
        // budget. At a budget of one step it has no node, drops the first,
        // and the budget records a search it stopped
        $participants = [];
        for ($i = 0; $i < 5; ++$i) {
            $participants[] = new Participant("p{$i}", "P{$i}");
        }
        $plan = static fn(StepBudget $budget): array => array_map(
            static fn(UnplacedEvent $event): string => $event->getEventId(),
            (new LoadPlanner(new RepackOptions(), $budget))->plan(
                [[0, 1], [0, 2], [0, 2], [1, 2]],
                [0 => [1 => [0], 3 => [0], 2 => [1], 4 => [1]]],
                [0 => [0 => 1, 1 => 1]],
                [2],
                4,
                ['a', 'b', 'c', 'd'],
                $participants
            )->unplaced
        );

        $default = new StepBudget(1_000, StepBudget::DEFAULT_STEPS);
        expect($plan($default))->toBe(['b', 'd'])
            ->and($default->stoppedASearch())->toBeFalse();

        $oneStep = new StepBudget(1_000, 1);
        $dropped = $plan($oneStep);
        expect($dropped[0])->toBe('a')
            ->and(count($dropped))->toBeGreaterThan(2)
            ->and($oneStep->stoppedASearch())->toBeTrue();
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

    it('searches with the share of its node limit that a budget below the default leaves, and tells the budget when that stops it short', function (): void {
        // Participants 0 and 1 are short by one event and 2 by two. The
        // greedy start takes the event between 0 and 1, after which nothing
        // else fits: one event. Leaving it out, an event between 0 and 2 and
        // the one between 1 and 2 fit: two, which the search finds. At a
        // budget of one step the search has no node (its share of 100,000
        // rounds down to none), the greedy set stands, it is not known to
        // be a largest one, and a larger budget would have found the other
        $edges = [0 => [0, 1], 1 => [0, 2], 2 => [0, 2], 3 => [1, 2]];
        $need = [0 => 1, 1 => 1, 2 => 2];

        $default = new StepBudget(1, StepBudget::DEFAULT_STEPS);
        expect(LoadPlanner::sharedDrops($edges, $need, $exact, $default))->toBe([1, 3])
            ->and($exact)->toBeTrue()
            ->and($default->stoppedASearch())->toBeFalse();

        $oneStep = new StepBudget(1, 1);
        expect(LoadPlanner::sharedDrops($edges, $need, $exact, $oneStep))->toBe([0])
            ->and($exact)->toBeFalse()
            ->and($oneStep->stoppedASearch())->toBeTrue();
    });

    // The repacker asks this search twice: in the planner, and again when
    // the last placement step has placed a dropped event and the events
    // still unplaced are given their reasons (ScheduleRepacker::label()).
    // The tests here and of the planner above hold the search and the
    // first use. That the second use hands the search the budget too has
    // no test. Taking the budget away there changes how long a repack
    // below the default takes and which reason some unplaced events have
    // (ParticipantOverCapacity or NoSlotAvailable; 666 of 35,700 outcomes
    // of over-capacity requests when it was tried), and both outcomes are
    // ones the contract allows: which of them a share of the nodes gives
    // follows from the greedy set, and so from the repacker's own order of
    // the events, which a test could only copy from what the code returns
    it('does not report a node limit that stopped it with a set as large as any can be', function (): void {
        // The path above at a budget of one step: the greedy start is
        // already the two end events, no set is larger, and no longer
        // search would have replaced it
        $budget = new StepBudget(1, 1);

        expect(LoadPlanner::sharedDrops([0 => [1, 2], 1 => [0, 1], 2 => [2, 3]], [0 => 1, 1 => 1, 2 => 1, 3 => 1], $exact, $budget))
            ->toBe([1, 2])
            ->and($exact)->toBeTrue()
            ->and($budget->stoppedASearch())->toBeFalse();
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
