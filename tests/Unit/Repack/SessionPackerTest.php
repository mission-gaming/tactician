<?php

declare(strict_types=1);

use MissionGaming\Tactician\Repack\Internal\SessionPacker;
use MissionGaming\Tactician\Repack\Internal\StepBudget;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Whether a packing leaves every participant of the session one run of
 * slots with no gap and plays no participant twice in a slot.
 *
 * @param array<int, array{int, int}> $edges
 * @param array<int, int> $assignments Event => slot
 */
function packsWithoutAGap(array $edges, array $assignments): bool
{
    $slotsOf = [];
    foreach ($assignments as $event => $slot) {
        foreach ($edges[$event] as $pid) {
            if (isset($slotsOf[$pid][$slot])) {
                return false;
            }
            $slotsOf[$pid][$slot] = true;
        }
    }

    return array_all(
        $slotsOf,
        static fn(array $slots): bool => max(array_keys($slots)) - min(array_keys($slots)) + 1 === count($slots)
    );
}

/**
 * A session of a few independent parts: each part a handful of
 * participants with events among themselves only.
 *
 * @return array<int, array{int, int}>
 */
function sessionOfParts(int $seed): array
{
    $random = new Randomizer(new Xoshiro256StarStar($seed));
    $edges = [];
    $first = 0;
    for ($part = $random->getInt(2, 4); $part > 0; --$part) {
        $size = $random->getInt(2, 4);
        for ($event = $random->getInt(1, $size + 1); $event > 0; --$event) {
            $a = $random->getInt(0, $size - 1);
            $b = ($a + $random->getInt(1, $size - 1)) % $size;
            $edges[] = [$first + min($a, $b), $first + max($a, $b)];
        }
        $first += $size;
    }

    // The parts' participants interleaved, so that no part is a run of
    // indexes and the order the search tries them in mixes the parts
    $order = $random->shuffleArray(range(0, $first - 1));

    return array_map(static fn(array $pair): array => [min($order[$pair[0]], $order[$pair[1]]), max($order[$pair[0]], $order[$pair[1]])], $edges);
}

describe('SessionPacker', function (): void {
    it('packs a session of many small parts without spending its share of the budget', function (): void {
        // A session the planner made from the end of a season in
        // progress (#242): 16 events of 24 participants in three slots,
        // in eight parts. Searched whole, the placements of the parts'
        // runs multiply, and the search used 73,363 steps before it
        // packed; with the default budget its share ran out at 50,000
        $edges = [[0, 17], [1, 16], [2, 20], [3, 23], [5, 6], [7, 21], [9, 19], [10, 22], [14, 15], [8, 21], [11, 20], [13, 18], [4, 23], [12, 20], [14, 18], [17, 19]];
        $budget = new StepBudget(200);

        $packed = new SessionPacker($budget)->pack($edges, [], [], 3, 12);

        expect($packed['leftovers'])->toBe([])
            ->and(count($packed['assignments']))->toBe(16)
            ->and(packsWithoutAGap($edges, $packed['assignments']))->toBeTrue()
            ->and($budget->stoppedASearch())->toBeFalse()
            ->and(200 - $budget->stepsLeft())->toBeLessThan(100);
    });

    it('packs a session of parts as it packs each part alone', function (int $seed): void {
        // Nothing but a slot's room ties the parts together, and the room
        // here is the whole field: the session's packing is each part's own
        $edges = sessionOfParts($seed);
        $slots = 3;
        $capacity = 20;

        $whole = new SessionPacker(new StepBudget(200_000))->pack($edges, [], [], $slots, $capacity);

        $parts = [];
        $partOf = [];
        foreach ($edges as $event => [$a, $b]) {
            $root = $partOf[$a] ?? $partOf[$b] ?? $a;
            foreach ([$a, $b] as $pid) {
                if (isset($partOf[$pid]) && $partOf[$pid] !== $root) {
                    $old = $partOf[$pid];
                    foreach ($partOf as $q => $r) {
                        if ($r === $old) {
                            $partOf[$q] = $root;
                        }
                    }
                }
                $partOf[$pid] = $root;
            }
        }
        foreach ($edges as $event => [$a]) {
            $parts[$partOf[$a]][$event] = $edges[$event];
        }

        $alone = [];
        $everyPartPacks = true;
        foreach ($parts as $part) {
            $packed = new SessionPacker(new StepBudget(200_000))->pack($part, [], [], $slots, $capacity);
            $alone += $packed['assignments'];
            $everyPartPacks = $everyPartPacks && $packed['leftovers'] === [] && packsWithoutAGap($part, $packed['assignments']);
        }
        ksort($alone);

        if ($everyPartPacks) {
            // Every part packs without a gap, so the session does, the
            // same way
            expect($whole['leftovers'])->toBe([]);
            $assignments = $whole['assignments'];
            ksort($assignments);
            expect($assignments)->toBe($alone)
                ->and(packsWithoutAGap($edges, $assignments))->toBeTrue();
        }
        expect(count($whole['assignments']) + count($whole['leftovers']))->toBe(count($edges));
    })->with(range(1, 60));

    it('searches the session whole where the parts packed alone would overfill a slot', function (): void {
        // Two parts of three events each between the same two
        // participants, in three slots of room for one event: each part
        // packs alone at the top, but together they need six places in
        // three slots. Whatever the packer makes of it, every event is
        // either placed or left over
        $edges = [[0, 1], [2, 4], [0, 1], [3, 4], [0, 1], [3, 4]];

        $packed = new SessionPacker(new StepBudget(200_000))->pack($edges, [], [], 3, 1);

        expect(count($packed['assignments']) + count($packed['leftovers']))->toBe(6);
        expect(array_count_values($packed['assignments']))->each->toBeLessThanOrEqual(1);
    });

    it('turns down a placement whose slot has an odd set of participants without searching it', function (): void {
        // Nine participants, eleven events, four slots. Many of the run
        // placements the search tries leave some slot with a set of
        // participants, joined by the events they can play there, of an
        // odd number: no perfect matching covers it. They are turned down
        // before their matching is searched, and the packing takes 33
        // steps where it took 88 when each was searched
        $edges = [[2, 3], [6, 8], [3, 6], [4, 7], [3, 8], [1, 5], [1, 5], [2, 7], [3, 8], [4, 7], [4, 5]];
        $budget = new StepBudget(200_000);

        $packed = new SessionPacker($budget)->pack($edges, [], [], 4, 4);

        expect($packed['leftovers'])->toBe([])
            ->and(packsWithoutAGap($edges, $packed['assignments']))->toBeTrue()
            ->and(200_000 - $budget->stepsLeft())->toBe(33);
    });

    it('searches the session whole below the default budget', function (): void {
        // The session of many small parts above, with a caller's budget
        // below the default: the whole search's share of 100 steps runs
        // out, as it did before the search went part by part
        $edges = [[0, 17], [1, 16], [2, 20], [3, 23], [5, 6], [7, 21], [9, 19], [10, 22], [14, 15], [8, 21], [11, 20], [13, 18], [4, 23], [12, 20], [14, 18], [17, 19]];
        $budget = new StepBudget(400, 400);

        $packed = new SessionPacker($budget)->pack($edges, [], [], 3, 12);

        expect($budget->stoppedASearch())->toBeTrue()
            ->and(count($packed['assignments']) + count($packed['leftovers']))->toBe(16);
    });
});
