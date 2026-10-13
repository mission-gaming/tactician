<?php

declare(strict_types=1);

use MissionGaming\Tactician\Repack\Internal\RoundColouring;
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

        $positions = RoundColouring::of($edges, [], [], $slotCounts, intdiv($n, 2));

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

        $positions = RoundColouring::of($edges, [], [], [2 * $n], intdiv($n, 2));

        expect(array_values(eventsPerPosition($positions)))->toBe(array_fill(0, 2 * ($n - 1), intdiv($n, 2)));
        assertRoundsAreProper($edges, $positions);
    })->with([4, 6, 10, 16]);

    it('keeps a round clear of the pins of its position, and counts them towards its capacity', function (): void {
        // A round robin of four on four slots of two events. 0 and 1 are
        // pinned at slot 0 against participants from outside, so slot 0
        // has no room at all, and three slots hold the six events
        $edges = roundRobinPairs(4);

        $positions = RoundColouring::of($edges, [0 => [0 => [0], 1 => [0], 8 => [0], 9 => [0]]], [0 => [0 => 2]], [4], 2);

        expect(eventsPerPosition($positions))->toBe(['0:1' => 2, '0:2' => 2, '0:3' => 2]);
        assertRoundsAreProper($edges, $positions);
    });

    it('never puts an event where one of its participants is pinned', function (): void {
        // 0 is pinned at the first two slots of three, with room left in
        // both: its two events can only share the last slot with nobody
        $positions = RoundColouring::of([[0, 1], [0, 2], [1, 2]], [0 => [0 => [0, 1]]], [0 => [0 => 1, 1 => 1]], [3], 3);

        expect($positions[2][1])->toBeLessThan(2)
            ->and(array_filter($positions, static fn(array $position): bool => $position === [0, 2]))->toHaveCount(1)
            ->and($positions)->toHaveCount(2);
    });

    it('gives no position to an event the grid cannot hold', function (): void {
        // Three events between the same two participants, two slots
        $positions = RoundColouring::of([[0, 1], [0, 1], [0, 1]], [], [], [2], 1);

        expect($positions)->toBe([0 => [0, 0], 1 => [0, 1]]);
    });

    it('uses a later position only for an event the first rounds cannot hold', function (): void {
        // A round robin of five needs five rounds, one more than the most
        // events any participant has: the fifth is the only one outside
        // the first four positions
        $edges = roundRobinPairs(5);

        $positions = RoundColouring::of($edges, [], [], [3, 3], 2);

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

        $positions = RoundColouring::of($edges, [], [], [12], 4);

        expect($positions)->toHaveCount(6)
            ->and(array_keys(eventsPerPosition($positions)))->toBe(['0:0', '0:1', '0:2']);
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

        $positions = RoundColouring::of($edges, $pinSlots, $pinCounts, $slotCounts, $capacity);

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

    it('has nothing to lay out for no events', function (): void {
        expect(RoundColouring::of([], [], [], [3], 2))->toBe([]);
    });
});
