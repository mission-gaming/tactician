<?php

declare(strict_types=1);

use MissionGaming\Tactician\Repack\Internal\ContiguityTargets;
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

/**
 * A small session of two to four independent parts, on two to four
 * slots, with some participants holding a pinned slot (an event against
 * someone outside the session's events) and room for one to four events
 * a slot beyond the pins: small enough that every assignment of its
 * events to slots can be tried.
 *
 * @return array{array<int, array{int, int}>, array<int, list<int>>, array<int, int>, int, int}
 *         The events, participant => pinned slots, slot => pinned events, the slots, the room
 */
function smallSessionOfParts(int $seed): array
{
    $random = new Randomizer(new Xoshiro256StarStar($seed));
    while (true) {
        $slots = $random->getInt(2, 4);
        $most = [2 => 9, 3 => 8, 4 => 7][$slots];
        $edges = [];
        $first = 0;
        for ($part = $random->getInt(2, 4); $part > 0 && count($edges) < $most; --$part) {
            $size = $random->getInt(2, 4);
            for ($event = $random->getInt(1, $size + 1); $event > 0 && count($edges) < $most; --$event) {
                $a = $random->getInt(0, $size - 1);
                $b = ($a + $random->getInt(1, $size - 1)) % $size;
                $edges[] = [$first + min($a, $b), $first + max($a, $b)];
            }
            $first += $size;
        }
        $order = $random->shuffleArray(range(0, $first - 1));
        $edges = array_map(static fn(array $pair): array => [min($order[$pair[0]], $order[$pair[1]]), max($order[$pair[0]], $order[$pair[1]])], $edges);

        $loads = [];
        foreach ($edges as [$a, $b]) {
            $loads[$a] = ($loads[$a] ?? 0) + 1;
            $loads[$b] = ($loads[$b] ?? 0) + 1;
        }
        $pinSlots = [];
        $pinCounts = [];
        foreach ($loads as $pid => $load) {
            if ($load < $slots && $random->getInt(0, 3) === 0) {
                $slot = $random->getInt(0, $slots - 1);
                $pinSlots[$pid] = [$slot];
                $pinCounts[$slot] = ($pinCounts[$slot] ?? 0) + 1;
            }
        }
        if (max([0, ...$loads]) > $slots) {
            continue;
        }

        return [$edges, $pinSlots, $pinCounts, $slots, $random->getInt(1, 4) + max([0, ...$pinCounts])];
    }
}

/**
 * The total late start of the unpinned participants under a packing
 * that leaves every unpinned participant one run with no gap and every
 * pinned one on its target (ContiguityTargets), with no participant
 * twice in a slot, none on a slot it is pinned at, and no slot over its
 * room; null when the packing is not one.
 *
 * @param array<int, array{int, int}> $edges
 * @param array<int, list<int>> $pinSlots
 * @param array<int, int> $pinCounts
 * @param array<int, int> $assignments Event => slot
 */
function lateStartOfAGapFreePacking(array $edges, array $pinSlots, array $pinCounts, int $slots, int $capacity, array $assignments): ?int
{
    if (count($assignments) !== count($edges)) {
        return null;
    }
    $slotsOf = [];
    $used = [];
    foreach ($assignments as $event => $slot) {
        foreach ($edges[$event] as $pid) {
            if (isset($slotsOf[$pid][$slot]) || in_array($slot, $pinSlots[$pid] ?? [], true)) {
                return null;
            }
            $slotsOf[$pid][$slot] = true;
        }
        $used[$slot] = ($used[$slot] ?? 0) + 1;
        if ($used[$slot] > $capacity - ($pinCounts[$slot] ?? 0)) {
            return null;
        }
    }

    $late = 0;
    foreach ($slotsOf as $pid => $set) {
        $held = array_keys($set);
        sort($held);
        if (($pinSlots[$pid] ?? []) !== []) {
            $target = ContiguityTargets::movableTarget($pinSlots[$pid], count($held), $slots);
            sort($target);
            if ($held !== $target) {
                return null;
            }
            continue;
        }
        if ($held[count($held) - 1] - $held[0] + 1 !== count($held)) {
            return null;
        }
        $late += $held[0];
    }

    return $late;
}

/**
 * The least total late start over every assignment of the events to
 * slots that is a gap-free packing (lateStartOfAGapFreePacking()); null
 * when none is.
 *
 * @param array<int, array{int, int}> $edges
 * @param array<int, list<int>> $pinSlots
 * @param array<int, int> $pinCounts
 */
function leastLateStartOfAGapFreePacking(array $edges, array $pinSlots, array $pinCounts, int $slots, int $capacity): ?int
{
    $least = null;
    $events = array_keys($edges);
    for ($code = 0, $all = $slots ** count($events); $code < $all; ++$code) {
        $assignments = [];
        $rest = $code;
        foreach ($events as $event) {
            $assignments[$event] = $rest % $slots;
            $rest = intdiv($rest, $slots);
        }
        $late = lateStartOfAGapFreePacking($edges, $pinSlots, $pinCounts, $slots, $capacity, $assignments);
        if ($late !== null && ($least === null || $late < $least)) {
            $least = $late;
        }
    }

    return $least;
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

    it('packs a session of parts as the whole search does, at the least late start there is', function (int $seed): void {
        // Small sessions of two to four parts, with pins and little room,
        // so that the parts' own packings often overfill a slot. The
        // search part by part (the default budget) and the session
        // searched whole (a caller's budget below the default, with steps
        // to spare) give the same packing; and where some placement of
        // the events packs every participant on its target without a gap
        // (tried here one assignment of events to slots at a time), the
        // packing is one, with the least total late start
        [$edges, $pinSlots, $pinCounts, $slots, $capacity] = smallSessionOfParts($seed);

        $byParts = new StepBudget(10_000_000);
        $packed = new SessionPacker($byParts)->pack($edges, $pinSlots, $pinCounts, $slots, $capacity);
        $wholeBudget = new StepBudget(10_000_000, StepBudget::DEFAULT_STEPS - 1);
        $whole = new SessionPacker($wholeBudget)->pack($edges, $pinSlots, $pinCounts, $slots, $capacity);

        // The same events in the same slots: the part-by-part search
        // lists them by event, the whole one in the order it matched them
        ksort($packed['assignments']);
        ksort($whole['assignments']);
        expect($byParts->stoppedASearch())->toBeFalse()
            ->and($wholeBudget->stoppedASearch())->toBeFalse()
            ->and($packed)->toBe($whole);

        $least = leastLateStartOfAGapFreePacking($edges, $pinSlots, $pinCounts, $slots, $capacity);
        if ($least !== null) {
            expect($packed['leftovers'])->toBe([])
                ->and(lateStartOfAGapFreePacking($edges, $pinSlots, $pinCounts, $slots, $capacity, $packed['assignments']))
                ->toBe($least);
        }
    })->with(range(1, 150));

    it('searches the session whole where the parts packed alone would overfill a slot, and packs it there', function (): void {
        // Two events with no participant in common, two slots of room for
        // one event: each part packs alone at the top, and together they
        // overfill it. Searched whole, the placement with the least late
        // start that is first by participant puts the event of 1 and 3
        // second
        $edges = [[1, 3], [0, 2]];

        $packed = new SessionPacker(new StepBudget(200_000))->pack($edges, [], [], 2, 1);
        ksort($packed['assignments']);

        expect($packed)->toBe(['assignments' => [0 => 1, 1 => 0], 'leftovers' => []]);
    });

    it('searches the session whole where the parts packed alone would overfill a slot', function (): void {
        // Two parts, three events of 0 and 1 and three of 2, 3 and 4, in
        // three slots of room for one event: each part packs alone at the
        // top, but together they need six places in three slots. Whatever
        // the packer makes of it, every event is either placed or left
        // over
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
