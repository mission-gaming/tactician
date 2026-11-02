<?php

declare(strict_types=1);

use MissionGaming\Tactician\Repack\Internal\GapSearch;
use MissionGaming\Tactician\Repack\Internal\StepBudget;

/**
 * Four events on sessions of two and four slots, one event per slot:
 * participant 3 plays 2, 1 and 0, and 2 plays 0. All four are in the
 * second session, where participant 2 plays the first slot and the third.
 *
 * @return array{0: array<int, array{int, int}>, 1: array<int, array{int, int}>}
 */
function gapInOneSession(): array
{
    return [
        [[2, 3], [1, 3], [0, 2], [0, 3]],
        [0 => [1, 2], 1 => [1, 3], 2 => [1, 0], 3 => [1, 1]],
    ];
}

/**
 * The gap slots of a schedule with no pins.
 *
 * @param array<int, array{int, int}> $edges
 * @param array<int, array{int, int}> $positions
 */
function gapSlotsOf(array $edges, array $positions): int
{
    $slots = [];
    foreach ($positions as $eventIndex => [$session, $slot]) {
        foreach ($edges[$eventIndex] as $pid) {
            $slots["{$pid}:{$session}"][] = $slot;
        }
    }

    return array_sum(array_map(
        static fn(array $held): int => max($held) - min($held) + 1 - count($held),
        $slots
    ));
}

describe('GapSearch', function (): void {
    it('moves events to another session where that leaves no gap', function (): void {
        [$edges, $positions] = gapInOneSession();
        expect(gapSlotsOf($edges, $positions))->toBe(1);
        $budget = new StepBudget(10_000);

        $unplaced = new GapSearch($budget)->improve($edges, $positions, [], [], [], [2, 4], 1);

        expect($unplaced)->toBe([])
            ->and($positions)->toHaveCount(4)
            ->and(gapSlotsOf($edges, $positions))->toBe(0)
            ->and(count(array_unique(array_map(static fn(array $p): string => "{$p[0]}:{$p[1]}", $positions))))->toBe(4)
            ->and($budget->stoppedASearch())->toBeFalse();
    });

    it('leaves a schedule with no gap as it is, and spends no step on it', function (): void {
        // 1 plays 0 and then 2, in the last two of three slots: late, and
        // no gap. A budget with no step would be recorded as having stopped
        // a search if one were started
        $edges = [[0, 1], [1, 2]];
        $positions = [0 => [0, 1], 1 => [0, 2]];
        $budget = new StepBudget(0);

        $unplaced = new GapSearch($budget)->improve($edges, $positions, [], [], [], [3], 1);

        expect($unplaced)->toBe([])
            ->and($positions)->toBe([0 => [0, 1], 1 => [0, 2]])
            ->and($budget->stoppedASearch())->toBeFalse();
    });

    it('counts a pinned slot as played, as the audit does', function (): void {
        // 0 is pinned at slot 0 and has its one movable event at slot 2:
        // a gap at slot 1, which moving the event there closes
        $edges = [[0, 1]];
        $positions = [0 => [0, 2]];

        new GapSearch(new StepBudget(100))->improve($edges, $positions, [], [0 => [0 => [0], 9 => [0]]], [0 => [0 => 1]], [3], 1);

        expect($positions)->toBe([0 => [0, 1]]);
    });

    it('keeps the schedule it was given when its step budget ends the search, and says so', function (): void {
        [$edges, $positions] = gapInOneSession();
        $before = $positions;
        $budget = new StepBudget(2);

        $unplaced = new GapSearch($budget)->improve($edges, $positions, [], [], [], [2, 4], 1);

        expect($unplaced)->toBe([])
            ->and($positions)->toBe($before)
            ->and($budget->stoppedASearch())->toBeTrue();
    });

    it('keeps the schedule it was given when it reaches its own limit, which is not the budget', function (): void {
        [$edges, $positions] = gapInOneSession();
        $before = $positions;
        $budget = new StepBudget(10_000);

        $unplaced = new GapSearch($budget, 3)->improve($edges, $positions, [], [], [], [2, 4], 1);

        expect($unplaced)->toBe([])
            ->and($positions)->toBe($before)
            ->and($budget->stoppedASearch())->toBeFalse();
    });

    it('has the share of its limit that a budget below the default leaves, and tells the budget when that stops it', function (): void {
        // The same request on steps that do not run out. At the default
        // budget the search has its whole limit and closes the gap; at a
        // budget of one step it has one position check, which the first
        // event uses up, and a larger budget would have let it go on
        [$edges, $positions] = gapInOneSession();
        $before = $positions;

        $default = new StepBudget(PHP_INT_MAX, StepBudget::DEFAULT_STEPS);
        new GapSearch($default)->improve($edges, $positions, [], [], [], [2, 4], 1);
        expect(gapSlotsOf($edges, $positions))->toBe(0)
            ->and($default->stoppedASearch())->toBeFalse();

        $positions = $before;
        $oneStep = new StepBudget(PHP_INT_MAX, 1);
        $unplaced = new GapSearch($oneStep)->improve($edges, $positions, [], [], [], [2, 4], 1);
        expect($unplaced)->toBe([])
            ->and($positions)->toBe($before)
            ->and($oneStep->stoppedASearch())->toBeTrue();
    });

    it('may change which event is left out, and never leaves out more', function (): void {
        // One session of three slots. Participant 0 has four events, one
        // more than there are slots, so one is left out: the one against
        // 4. Participant 1 is pinned at slot 2 and plays 0 at slot 0, a
        // gap at slot 1, which closes when the events against 1 and 2
        // change slots. As many events are placed afterwards as before
        $edges = [[0, 1], [0, 2], [0, 3], [0, 4]];
        $positions = [0 => [0, 0], 1 => [0, 1], 2 => [0, 2]];
        $pinSlots = [0 => [1 => [2], 5 => [2]]];

        $unplaced = new GapSearch(new StepBudget(10_000))->improve($edges, $positions, [3], $pinSlots, [0 => [2 => 1]], [3], 2);

        expect($unplaced)->toHaveCount(1)
            ->and($positions)->toHaveCount(3)
            ->and($positions[0])->toBe([0, 1]);
    });

    it('does not take a placement that leaves out an event a free position could hold', function (): void {
        // Three slots with room for two events each. 0 plays 1 at slot 0
        // and 2 at slot 2, a gap at slot 1. The event of 3 and 4 came
        // without a position although every slot could hold it. Closing
        // the gap and leaving that event out again would be a placement of
        // as many events with fewer gap slots; it is not taken, because an
        // event that fits a free position is never left out
        $edges = [[0, 1], [0, 2], [3, 4]];
        $positions = [0 => [0, 0], 1 => [0, 2]];

        $unplaced = new GapSearch(new StepBudget(10_000))->improve($edges, $positions, [2], [], [], [3], 2);

        expect(gapSlotsOf($edges, $positions))->toBe(0)
            ->and($unplaced)->toBe([])
            ->and($positions)->toHaveCount(3);
    });
});
