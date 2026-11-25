<?php

declare(strict_types=1);

use MissionGaming\Tactician\Repack\Internal\RoundColouring;
use MissionGaming\Tactician\Repack\Internal\StepBudget;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * The events of a complete round robin, as index pairs in pair order,
 * each pair $legs times.
 *
 * @return list<array{int, int}>
 */
function roundRobinPairs(int $participants, int $legs = 1): array
{
    $pairs = [];
    for ($a = 0; $a < $participants; ++$a) {
        for ($b = $a + 1; $b < $participants; ++$b) {
            for ($leg = 0; $leg < $legs; ++$leg) {
                $pairs[] = [$a, $b];
            }
        }
    }

    return $pairs;
}

/**
 * How many events each position holds, by "session:slot".
 *
 * @param array<int, array{int, int}> $positions
 *
 * @return array<string, int>
 */
function eventsPerPosition(array $positions): array
{
    $counts = [];
    foreach ($positions as [$session, $slot]) {
        $counts["{$session}:{$slot}"] = ($counts["{$session}:{$slot}"] ?? 0) + 1;
    }
    ksort($counts);

    return $counts;
}

/**
 * Assert that no participant has two events at one position.
 *
 * @param array<int, array{int, int}> $edges
 * @param array<int, array{int, int}> $positions
 */
function assertRoundsAreProper(array $edges, array $positions): void
{
    $seen = [];
    foreach ($positions as $eventIndex => [$session, $slot]) {
        foreach ($edges[$eventIndex] as $pid) {
            expect(isset($seen["{$session}:{$slot}:{$pid}"]))->toBeFalse();
            $seen["{$session}:{$slot}:{$pid}"] = true;
        }
    }
}

describe('RoundColouring', function (): void {
    it('lays a complete round robin of an even number on one round fewer than its participants', function (int $n): void {
        $edges = roundRobinPairs($n);
        $slotCounts = array_fill(0, intdiv($n + 3, 4), 4);

        $positions = RoundColouring::of($edges, [], [], $slotCounts, intdiv($n, 2), new StepBudget(PHP_INT_MAX));

        // n - 1 positions, the first in grid order, each with half the field
        expect($positions)->toHaveCount(count($edges))
            ->and(array_values(eventsPerPosition($positions)))->toBe(array_fill(0, $n - 1, intdiv($n, 2)));
        assertRoundsAreProper($edges, $positions);
        foreach ($positions as [$session, $slot]) {
            expect(4 * $session + $slot)->toBeLessThan($n - 1);
        }
    })->with([4, 6, 8, 10, 12, 14, 16, 18, 20, 22, 24, 30, 36, 40]);

    it('lays a round robin of two legs on twice as many rounds, each a whole round', function (int $n): void {
        $edges = roundRobinPairs($n, 2);

        $positions = RoundColouring::of($edges, [], [], [2 * $n], intdiv($n, 2), new StepBudget(PHP_INT_MAX));

        expect(array_values(eventsPerPosition($positions)))->toBe(array_fill(0, 2 * ($n - 1), intdiv($n, 2)));
        assertRoundsAreProper($edges, $positions);
    })->with([4, 6, 10, 16]);

    it('keeps a round clear of the pins of its position, and counts them towards its capacity', function (): void {
        // A round robin of four on four slots of two events. 0 and 1 are
        // pinned at slot 0 against participants from outside, so slot 0
        // has no room at all, and three slots hold the six events
        $edges = roundRobinPairs(4);

        $positions = RoundColouring::of($edges, [0 => [0 => [0], 1 => [0], 8 => [0], 9 => [0]]], [0 => [0 => 2]], [4], 2, new StepBudget(PHP_INT_MAX));

        expect(eventsPerPosition($positions))->toBe(['0:1' => 2, '0:2' => 2, '0:3' => 2]);
        assertRoundsAreProper($edges, $positions);
    });

    it('never puts an event where one of its participants is pinned', function (): void {
        // 0 is pinned at the first two slots of three, with room left in
        // both: its two events can only share the last slot with nobody
        $positions = RoundColouring::of([[0, 1], [0, 2], [1, 2]], [0 => [0 => [0, 1]]], [0 => [0 => 1, 1 => 1]], [3], 3, new StepBudget(PHP_INT_MAX));

        expect($positions[2][1])->toBeLessThan(2)
            ->and(array_filter($positions, static fn(array $position): bool => $position === [0, 2]))->toHaveCount(1)
            ->and($positions)->toHaveCount(2);
    });

    it('gives no position to an event the grid cannot hold', function (): void {
        // Three events between the same two participants, two slots
        $positions = RoundColouring::of([[0, 1], [0, 1], [0, 1]], [], [], [2], 1, new StepBudget(PHP_INT_MAX));

        expect($positions)->toBe([0 => [0, 0], 1 => [0, 1]]);
    });

    it('uses a later position only for an event the first rounds cannot hold', function (): void {
        // A round robin of five needs five rounds, one more than the most
        // events any participant has: the fifth is the only one outside
        // the first four positions
        $edges = roundRobinPairs(5);

        $positions = RoundColouring::of($edges, [], [], [3, 3], 2, new StepBudget(PHP_INT_MAX));

        expect($positions)->toHaveCount(10)
            ->and(array_keys(eventsPerPosition($positions)))->toBe(['0:0', '0:1', '0:2', '1:0', '1:1']);
        assertRoundsAreProper($edges, $positions);
    });

    it('searches the fewest rounds for room before it uses a position after them', function (): void {
        // Six events of five participants, none of whom has more than
        // three: three rounds hold them. Taking each event at the first of
        // those rounds free for it leaves one over, which only moving
        // events within the three rounds places there; the slots after
        // them are free and are not used
        $edges = [[0, 2], [0, 4], [1, 2], [1, 3], [1, 4], [2, 4]];

        $positions = RoundColouring::of($edges, [], [], [12], 4, new StepBudget(PHP_INT_MAX));

        expect($positions)->toHaveCount(6)
            ->and(array_keys(eventsPerPosition($positions)))->toBe(['0:0', '0:1', '0:2']);
        assertRoundsAreProper($edges, $positions);
    });

    it('searches with the share of its work limit that a budget below the default leaves, and tells the budget when that stops it', function (): void {
        // Five events on one session of three slots, three to a slot.
        // Participant 5 has three of them, one for every slot. The first
        // pass leaves one event over, and it takes a search to place it. At
        // the default budget the search has its whole limit and the three
        // slots hold all five. At a budget of one step it has fifteen
        // position checks (one for every event and position), stops there,
        // and the event has no round. The budget records a search it
        // stopped, because a larger one gives other rounds
        $edges = [[1, 4], [2, 3], [2, 5], [3, 5], [4, 5]];

        $default = new StepBudget(PHP_INT_MAX, StepBudget::DEFAULT_STEPS);
        $positions = RoundColouring::of($edges, [], [], [3], 3, $default);
        expect($positions)->toHaveCount(5)
            ->and($default->stoppedASearch())->toBeFalse();
        assertRoundsAreProper($edges, $positions);

        $oneStep = new StepBudget(PHP_INT_MAX, 1);
        $positions = RoundColouring::of($edges, [], [], [3], 3, $oneStep);
        expect($positions)->toHaveCount(4)
            ->and($oneStep->stoppedASearch())->toBeTrue();
        assertRoundsAreProper($edges, $positions);
    });

    it('lays out random requests properly, within capacity and around their pins', function (int $seed): void {
        $rng = new Randomizer(new Mt19937($seed));
        $count = $rng->getInt(4, 9);
        $edges = [];
        for ($i = $rng->getInt(4, 20); $i > 0; --$i) {
            $a = $rng->getInt(0, $count - 2);
            $edges[] = [$a, $rng->getInt($a + 1, $count - 1)];
        }
        sort($edges);
        $slotCounts = [$rng->getInt(1, 4), $rng->getInt(1, 4), $rng->getInt(1, 4)];
        $capacity = $rng->getInt(1, 4);
        $pinned = $rng->getInt(0, $count - 1);
        $pinSlots = [1 => [$pinned => [0]]];
        $pinCounts = [1 => [0 => 1]];

        $positions = RoundColouring::of($edges, $pinSlots, $pinCounts, $slotCounts, $capacity, new StepBudget(PHP_INT_MAX));

        assertRoundsAreProper($edges, $positions);
        foreach (eventsPerPosition($positions) as $position => $events) {
            expect($events)->toBeLessThanOrEqual($position === '1:0' ? $capacity - 1 : $capacity);
        }
        foreach ($positions as $eventIndex => [$session, $slot]) {
            expect($slot)->toBeLessThan($slotCounts[$session]);
            if ([$session, $slot] === [1, 0]) {
                expect($edges[$eventIndex])->not->toContain($pinned);
            }
        }
        expect(array_keys($positions))->toBe(array_values(array_intersect(array_keys($edges), array_keys($positions))));
    })->with(range(1, 40));

    it('lays the rounds of a season with its first rounds pinned on the positions after them, where the layout over every position has to search', function (): void {
        // A round robin of eight on one session of seven slots, four events
        // to a slot, its first two rounds pinned in order at slots 0 and 1:
        // those two slots are full. Five rounds are left, and on the five
        // slots with room they need no search at all, so a budget of one
        // step stops nothing. The layout over every position counts the two
        // full slots among its five rounds, has three for twenty events, and
        // that one step's share of its work limit stops its search; it gets
        // there at the default budget
        $rounds = [
            [[0, 7], [1, 6], [2, 5], [3, 4]],
            [[0, 6], [5, 7], [1, 4], [2, 3]],
            [[0, 5], [4, 6], [3, 7], [1, 2]],
            [[0, 4], [3, 5], [2, 6], [1, 7]],
            [[0, 3], [2, 4], [1, 5], [6, 7]],
            [[0, 2], [1, 3], [4, 7], [5, 6]],
            [[0, 1], [2, 7], [3, 6], [4, 5]],
        ];
        $pinSlots = [];
        $pinCounts = [];
        $edges = [];
        foreach ($rounds as $round => $pairs) {
            foreach ($pairs as $pair) {
                if ($round < 2) {
                    foreach ($pair as $pid) {
                        $pinSlots[0][$pid][] = $round;
                    }
                    $pinCounts[0][$round] = ($pinCounts[0][$round] ?? 0) + 1;
                } else {
                    $edges[] = [min($pair), max($pair)];
                }
            }
        }
        sort($edges);
        $everyRound = ['0:2' => 4, '0:3' => 4, '0:4' => 4, '0:5' => 4, '0:6' => 4];

        $onRoom = new StepBudget(PHP_INT_MAX, 1);
        $positions = RoundColouring::of($edges, $pinSlots, $pinCounts, [7], 4, $onRoom, true);
        expect(eventsPerPosition($positions))->toBe($everyRound)
            ->and($onRoom->stoppedASearch())->toBeFalse();
        assertRoundsAreProper($edges, $positions);

        $everywhere = new StepBudget(PHP_INT_MAX, 1);
        RoundColouring::of($edges, $pinSlots, $pinCounts, [7], 4, $everywhere);
        expect($everywhere->stoppedASearch())->toBeTrue();

        $positions = RoundColouring::of($edges, $pinSlots, $pinCounts, [7], 4, new StepBudget(PHP_INT_MAX));
        expect(eventsPerPosition($positions))->toBe($everyRound);
    });

    it('lays the rounds on the positions with room exactly as on every position where pins fill none', function (int $seed): void {
        $rng = new Randomizer(new Mt19937($seed));
        $count = $rng->getInt(4, 9);
        $edges = [];
        for ($i = $rng->getInt(4, 20); $i > 0; --$i) {
            $a = $rng->getInt(0, $count - 2);
            $edges[] = [$a, $rng->getInt($a + 1, $count - 1)];
        }
        sort($edges);
        $slotCounts = [$rng->getInt(1, 4), $rng->getInt(1, 4)];
        $capacity = $rng->getInt(2, 4);
        $pinned = $rng->getInt(0, $count - 1);
        $pinSlots = [1 => [$pinned => [0]]];
        $pinCounts = [1 => [0 => 1]];

        expect(RoundColouring::fillsAPosition($pinCounts, $capacity))->toBeFalse()
            ->and(RoundColouring::of($edges, $pinSlots, $pinCounts, $slotCounts, $capacity, new StepBudget(PHP_INT_MAX), true))
            ->toBe(RoundColouring::of($edges, $pinSlots, $pinCounts, $slotCounts, $capacity, new StepBudget(PHP_INT_MAX)));
    })->with(range(1, 20));

    it('never lays a round on a position its pins fill', function (int $seed): void {
        $rng = new Randomizer(new Mt19937($seed));
        $count = $rng->getInt(4, 9);
        $edges = [];
        for ($i = $rng->getInt(4, 20); $i > 0; --$i) {
            $a = $rng->getInt(0, $count - 2);
            $edges[] = [$a, $rng->getInt($a + 1, $count - 1)];
        }
        sort($edges);
        $slotCounts = [$rng->getInt(2, 5), $rng->getInt(1, 4)];
        // The first slot of the first session is full of pins between two
        // participants from outside
        $pinSlots = [0 => [20 => [0], 21 => [0]]];
        $pinCounts = [0 => [0 => 1]];

        $positions = RoundColouring::of($edges, $pinSlots, $pinCounts, $slotCounts, 1, new StepBudget(PHP_INT_MAX), true);

        expect(RoundColouring::fillsAPosition($pinCounts, 1))->toBeTrue()
            ->and(array_keys(eventsPerPosition($positions)))->not->toContain('0:0')
            ->and(max([0, ...eventsPerPosition($positions)]))->toBeLessThanOrEqual(1);
        assertRoundsAreProper($edges, $positions);
    })->with(range(1, 20));

    it('lays the rounds past a position its pins fill in the middle of the grid', function (): void {
        // Two sessions of three slots, two events to a slot, and the second
        // slot of the first session full of two pinned events between
        // participants from outside. The round robin of four needs three
        // rounds, and on the positions with room they are the first, the
        // third and the fourth (0:0, 0:2, 1:0): round k of an event is the
        // k-th of those, not the k-th position of the grid. In pair order
        // the events start at rounds 1, 2, 0, 0, 1 and 2; 1-3 finds round 1
        // taken by 0-1 and goes on to round 2, and 2-3 finds rounds 2 and 0
        // full and goes on to round 1. No search is needed
        $edges = roundRobinPairs(4);
        $pinSlots = [0 => [20 => [1], 21 => [1], 22 => [1], 23 => [1]]];
        $pinCounts = [0 => [1 => 2]];
        $onRoom = new StepBudget(PHP_INT_MAX);

        $positions = RoundColouring::of($edges, $pinSlots, $pinCounts, [3, 3], 2, $onRoom, true);

        expect($positions)->toBe([[0, 2], [1, 0], [0, 0], [0, 0], [1, 0], [0, 2]])
            ->and($onRoom->stoppedASearch())->toBeFalse();
        assertRoundsAreProper($edges, $positions);
    });

    it('has no place for an event where pins fill every position', function (): void {
        $positions = RoundColouring::of([[0, 1], [2, 3]], [0 => [4 => [0, 1], 5 => [0, 1]]], [0 => [0 => 1, 1 => 1]], [2], 1, new StepBudget(PHP_INT_MAX), true);

        expect($positions)->toBe([]);
    });

    it('has nothing to lay out for no events', function (): void {
        expect(RoundColouring::of([], [], [], [3], 2, new StepBudget(PHP_INT_MAX)))->toBe([]);
    });
});
