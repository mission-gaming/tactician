<?php

declare(strict_types=1);

use MissionGaming\Tactician\Repack\Internal\ObjectivePolish;
use MissionGaming\Tactician\Repack\Internal\PlacementMetrics;
use MissionGaming\Tactician\Repack\Internal\StepBudget;
use MissionGaming\Tactician\Repack\RepackObjective;

/**
 * A repair on a small state: the events by their participants, pins as
 * [participants, session, slot], and the slot count of every session.
 *
 * @param list<RepackObjective> $objectives
 * @param array<int, list<int>> $participantsOf
 * @param list<array{list<int>, int, int}> $pins
 * @param array<int, int> $slotCounts
 * @param array<int, array{int, int}> $current
 */
function objectivePolish(
    array $objectives,
    array $participantsOf,
    array $pins,
    array $slotCounts,
    int $capacity,
    array $current,
    StepBudget $budget
): ObjectivePolish {
    $pinSlots = [];
    $pinCounts = [];
    foreach ($pins as [$participants, $session, $slot]) {
        $pinCounts[$session][$slot] = ($pinCounts[$session][$slot] ?? 0) + 1;
        foreach ($participants as $pid) {
            $pinSlots[$session][$pid][] = $slot;
        }
    }

    return new ObjectivePolish(
        new PlacementMetrics($participantsOf, $pins, $current),
        $objectives,
        $participantsOf,
        $pinSlots,
        $pinCounts,
        $slotCounts,
        $capacity,
        $current,
        $budget
    );
}

describe('ObjectivePolish', function (): void {
    it('puts an event back at its current position when that position is free', function (): void {
        $polish = objectivePolish([RepackObjective::LeastChurn], [0 => [0, 1]], [], [0 => 2], 1, [0 => [0, 0]], new StepBudget(100));

        expect($polish->improve([0 => [0, 1]], []))->toBe([0 => [0, 0]]);
    });

    it('leaves an event where it is when going back would open a gap', function (): void {
        // Participant 0 plays at slots 1 and 2; with event 0 back at slot 0
        // it would wait at slot 1
        $polish = objectivePolish(
            [RepackObjective::LeastChurn],
            [0 => [0, 1], 1 => [0, 2], 2 => [1, 3]],
            [],
            [0 => 3],
            2,
            [0 => [0, 0]],
            new StepBudget(100)
        );
        $positions = [0 => [0, 1], 1 => [0, 2], 2 => [0, 2]];

        expect($polish->improve($positions, []))->toBe($positions);
    });

    it('leaves an event where it is when its current position is held by another event of a participant, by a pin, or is full', function (): void {
        // Event 0, of participants 0 and 1, is at slot 1 and says slot 0.
        // Each case: the pins, the other events (all at slot 0) and the
        // capacity of a slot
        $cases = [
            'another event of participant 1 is there' => [[], [1 => [1, 2]], 2],
            'participant 0 is pinned there' => [[[[0, 5], 0, 0]], [], 2],
            'the position is full' => [[], [1 => [2, 3]], 1],
            'the position is full of pins' => [[[[4, 5], 0, 0]], [], 1],
        ];

        foreach ($cases as $name => [$pins, $others, $capacity]) {
            $participantsOf = [0 => [0, 1]] + $others;
            $positions = [0 => [0, 1]];
            foreach (array_keys($others) as $eventIndex) {
                $positions[$eventIndex] = [0, 0];
            }
            $polish = objectivePolish([RepackObjective::LeastChurn], $participantsOf, $pins, [0 => 2], $capacity, [0 => [0, 0]], new StepBudget(100));

            expect($polish->improve($positions, []))->toBe($positions, $name);
        }
    });

    it('leaves an event where it is when an unplaced event would fit the position it leaves', function (): void {
        // Slot 1 is full with events 0 and 1; event 2 is unplaced because
        // its participant 2 is pinned at slot 0. If event 0 went back to
        // slot 0, slot 1 would have room for event 2, and an unplaced event
        // must be one that no position holds
        $polish = objectivePolish(
            [RepackObjective::LeastChurn],
            [0 => [0, 1], 1 => [4, 5], 2 => [2, 3]],
            [[[2, 9], 0, 0]],
            [0 => 2],
            2,
            [0 => [0, 0]],
            new StepBudget(100)
        );
        $positions = [0 => [0, 1], 1 => [0, 1]];

        expect($polish->improve($positions, [2]))->toBe($positions)
            // With nothing unplaced the same move is made
            ->and($polish->improve($positions, []))->toBe([0 => [0, 0], 1 => [0, 1]]);
    });

    it('moves the one event of a lone-event session to a session that has an event', function (): void {
        $polish = objectivePolish(
            [RepackObjective::FewestLoneEventSessions],
            [0 => [0, 1], 1 => [2, 3], 2 => [4, 5]],
            [],
            [0 => 1, 1 => 1, 2 => 1],
            3,
            [],
            new StepBudget(100)
        );

        // Session 0 holds one event, session 1 two, session 2 none: the
        // lone event goes to session 1 and not to the empty one
        expect($polish->improve([0 => [0, 0], 1 => [1, 0], 2 => [1, 0]], []))->toBe([0 => [1, 0], 1 => [1, 0], 2 => [1, 0]]);
    });

    it('gives a lone-event session a second event when its own cannot leave', function (): void {
        // Participant 0 fills both slots of session 0 and has a third event
        // alone in session 1, which therefore cannot go to session 0. One
        // of the three events of session 0 can join it
        $metrics = new PlacementMetrics([0 => [0, 1], 1 => [0, 2], 2 => [0, 3], 3 => [4, 5]], [], []);
        $polish = objectivePolish(
            [RepackObjective::FewestLoneEventSessions],
            [0 => [0, 1], 1 => [0, 2], 2 => [0, 3], 3 => [4, 5]],
            [],
            [0 => 2, 1 => 2],
            2,
            [],
            new StepBudget(100)
        );
        $positions = [0 => [0, 0], 1 => [0, 1], 2 => [1, 0], 3 => [0, 0]];

        $improved = $polish->improve($positions, []);

        expect($metrics->loneEventSessions($positions))->toBe([1])
            ->and($metrics->loneEventSessions($improved))->toBe([])
            ->and($metrics->gapSlots($improved))->toBe(0)
            ->and($improved[2])->toBe([1, 0]);
    });

    it('moves an event of a participant into a session it sits out', function (): void {
        // Participant 0 plays in sessions 0 and 2 and sits out session 1
        $polish = objectivePolish(
            [RepackObjective::FewestIdleSessions],
            [0 => [0, 1], 1 => [0, 2]],
            [],
            [0 => 1, 1 => 1, 2 => 1],
            1,
            [],
            new StepBudget(100)
        );

        expect($polish->improve([0 => [0, 0], 1 => [2, 0]], []))->toBe([0 => [1, 0], 1 => [2, 0]]);
    });

    it('counts a pinned session as one the participant has an event in', function (): void {
        // Participant 0 is pinned in session 2 and has its movable event in
        // session 0; session 1 is idle until the event moves there
        $polish = objectivePolish(
            [RepackObjective::FewestIdleSessions],
            [0 => [0, 1]],
            [[[0, 9], 2, 0]],
            [0 => 1, 1 => 1, 2 => 1],
            1,
            [],
            new StepBudget(100)
        );

        expect($polish->improve([0 => [0, 0]], []))->toBe([0 => [1, 0]]);
    });

    it('never trades an earlier objective for a later one', function (): void {
        // Event 0 is at its current position, alone in session 0. Joining
        // session 1 ends a lone-event session and moves an event
        $state = [[0 => [0, 1], 1 => [2, 3], 2 => [4, 5]], [], [0 => 1, 1 => 1], 3, [0 => [0, 0]]];
        $positions = [0 => [0, 0], 1 => [1, 0], 2 => [1, 0]];

        $churnFirst = objectivePolish([RepackObjective::LeastChurn, RepackObjective::FewestLoneEventSessions], ...[...$state, new StepBudget(100)]);
        $loneFirst = objectivePolish([RepackObjective::FewestLoneEventSessions, RepackObjective::LeastChurn], ...[...$state, new StepBudget(100)]);

        expect($churnFirst->improve($positions, []))->toBe($positions)
            ->and($loneFirst->improve($positions, []))->toBe([0 => [1, 0], 1 => [1, 0], 2 => [1, 0]]);
    });

    it('does nothing without an objective, and nothing to an empty schedule', function (): void {
        $budget = new StepBudget(100);
        $positions = [0 => [0, 1]];

        expect(objectivePolish([], [0 => [0, 1]], [], [0 => 2], 1, [0 => [0, 0]], $budget)->improve($positions, []))->toBe($positions)
            ->and(objectivePolish([RepackObjective::LeastChurn], [0 => [0, 1]], [], [0 => 2], 1, [0 => [0, 0]], $budget)->improve([], [0]))->toBe([])
            ->and($budget->stepsLeft())->toBe(100);
    });

    it('spends one step for every move it weighs, and stops when the steps are gone', function (): void {
        // Two events, each one slot away from its current position
        $make = static fn(StepBudget $budget): ObjectivePolish => objectivePolish(
            [RepackObjective::LeastChurn],
            [0 => [0, 1], 1 => [2, 3]],
            [],
            [0 => 2, 1 => 2],
            1,
            [0 => [0, 0], 1 => [1, 0]],
            $budget
        );
        $positions = [0 => [0, 1], 1 => [1, 1]];

        $enough = new StepBudget(10);
        expect($make($enough)->improve($positions, []))->toBe([0 => [0, 0], 1 => [1, 0]])
            ->and($enough->stepsLeft())->toBe(8)
            ->and($enough->stoppedASearch())->toBeFalse();

        $one = new StepBudget(1);
        expect($make($one)->improve($positions, []))->toBe([0 => [0, 0], 1 => [1, 1]])
            ->and($one->stoppedASearch())->toBeTrue();
    });

    it('has the share of its limit that a budget below the default leaves it, and says so when that stops it', function (): void {
        $make = static fn(StepBudget $budget): ObjectivePolish => objectivePolish(
            [RepackObjective::LeastChurn],
            [0 => [0, 1], 1 => [2, 3]],
            [],
            [0 => 2, 1 => 2],
            1,
            [0 => [0, 0], 1 => [1, 0]],
            $budget
        );
        $positions = [0 => [0, 1], 1 => [1, 1]];

        // A two-thousandth of the default budget leaves one move of the limit
        $share = new StepBudget(1_000, intdiv(StepBudget::DEFAULT_STEPS, ObjectivePolish::EVALUATION_LIMIT));
        expect($make($share)->improve($positions, []))->toBe([0 => [0, 0], 1 => [1, 1]])
            ->and($share->stoppedASearch())->toBeTrue()
            ->and($share->stepsLeft())->toBe(999);

        // Twice that leaves two, which is all this state needs
        $twice = new StepBudget(1_000, 2 * intdiv(StepBudget::DEFAULT_STEPS, ObjectivePolish::EVALUATION_LIMIT));
        expect($make($twice)->improve($positions, []))->toBe([0 => [0, 0], 1 => [1, 0]])
            ->and($twice->stoppedASearch())->toBeFalse();
    });
});
