<?php

declare(strict_types=1);

use MissionGaming\Tactician\Repack\Internal\StructuralOrder;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * A request written in the order StructuralOrder gives its participants:
 * every movable event and every pin with the places of its participants
 * in that order for their numbers. Two requests that differ only in how
 * the participants are numbered have the same form exactly when the order
 * took their participants alike.
 *
 * @param array<int, array{int, int}> $events
 * @param array<int, array{int, int, int, int}> $pins
 *
 * @return array{events: list<string>, pins: list<string>}
 */
function structuralForm(int $count, array $events, array $pins): array
{
    $place = array_flip(StructuralOrder::of($count, $events, $pins));

    $writtenEvents = [];
    foreach ($events as [$a, $b]) {
        $writtenEvents[] = min($place[$a], $place[$b]) . '-' . max($place[$a], $place[$b]);
    }
    $writtenPins = [];
    foreach ($pins as [$a, $b, $session, $slot]) {
        $writtenPins[] = min($place[$a], $place[$b]) . '-' . max($place[$a], $place[$b]) . "@{$session}:{$slot}";
    }
    sort($writtenEvents);
    sort($writtenPins);

    return ['events' => $writtenEvents, 'pins' => $writtenPins];
}

/**
 * The same request with its participants numbered by a seeded shuffle,
 * and its lists in another order.
 *
 * @param array<int, array{int, int}> $events
 * @param array<int, array{int, int, int, int}> $pins
 *
 * @return array{0: array<int, array{int, int}>, 1: array<int, array{int, int, int, int}>}
 */
function renumbered(int $count, array $events, array $pins, int $seed): array
{
    $rng = new Randomizer(new Mt19937($seed));
    $number = $rng->shuffleArray(range(0, $count - 1));

    $newEvents = [];
    foreach ($events as [$a, $b]) {
        $newEvents[] = $rng->getInt(0, 1) === 1 ? [$number[$a], $number[$b]] : [$number[$b], $number[$a]];
    }
    $newPins = [];
    foreach ($pins as [$a, $b, $session, $slot]) {
        $newPins[] = [$number[$b], $number[$a], $session, $slot];
    }

    return [$rng->shuffleArray($newEvents), $rng->shuffleArray($newPins)];
}

describe('StructuralOrder', function (): void {
    it('puts the participant with more movable events first', function (): void {
        // 1 plays the three others, who play nobody else
        expect(StructuralOrder::of(4, [[0, 1], [1, 2], [3, 1]], []))->toBe([1, 0, 2, 3]);
    });

    it('tells two participants with as many events apart by where they are pinned, whatever their numbers', function (): void {
        // 0 plays the two others once each; one of the two is pinned
        // with 0 at the first position. The one that is not comes first
        expect(StructuralOrder::of(3, [[0, 1], [0, 2]], [[2, 0, 0, 0]]))->toBe([0, 1, 2])
            ->and(StructuralOrder::of(3, [[0, 1], [0, 2]], [[1, 0, 0, 0]]))->toBe([0, 2, 1]);
    });

    it('tells two participants apart by whom they play, where their own events and pins do not', function (): void {
        // A line of five: 2 is in the middle, and 1 and 3 each play one
        // of the ends. All three have two events
        $line = [[0, 1], [1, 2], [2, 3], [3, 4]];
        $order = StructuralOrder::of(5, $line, []);

        // The same line with the numbers moved one on: 3 is in the middle
        $moved = StructuralOrder::of(5, [[1, 2], [2, 3], [3, 4], [4, 0]], []);

        expect(array_search(2, $order, true))->toBe(array_search(3, $moved, true))
            ->and(array_slice($order, 3))->toEqualCanonicalizing([0, 4])
            ->and(array_slice($moved, 3))->toEqualCanonicalizing([0, 1]);
    });

    it('puts participants that can be exchanged in the order of their numbers', function (): void {
        // A complete round robin of five: any two can be exchanged
        $events = [];
        for ($a = 0; $a < 5; ++$a) {
            for ($b = $a + 1; $b < 5; ++$b) {
                $events[] = [$a, $b];
            }
        }

        expect(StructuralOrder::of(5, $events, []))->toBe([0, 1, 2, 3, 4]);
    });

    it('orders a request alike however its participants are numbered', function (int $count, array $events, array $pins): void {
        $form = structuralForm($count, $events, $pins);

        foreach ([1, 2, 3, 4, 5] as $seed) {
            [$newEvents, $newPins] = renumbered($count, $events, $pins, $seed);

            expect(structuralForm($count, $newEvents, $newPins))->toBe($form);
        }
    })->with([
        'a line of five' => [5, [[0, 1], [1, 2], [2, 3], [3, 4]], []],
        'a ring of six, where everybody is alike and no two can simply be exchanged' => [
            6, [[0, 1], [1, 2], [2, 3], [3, 4], [4, 5], [5, 0]], [],
        ],
        'a ring of three and a ring of four, where everybody has two events and the rings differ' => [
            7, [[0, 1], [1, 2], [2, 0], [3, 4], [4, 5], [5, 6], [6, 3]], [],
        ],
        'two triangles joined by one event' => [
            6, [[0, 1], [1, 2], [2, 0], [3, 4], [4, 5], [5, 3], [0, 3]], [],
        ],
        'a round robin of six with one round taken out' => [
            6, [[0, 2], [0, 3], [0, 4], [0, 5], [1, 2], [1, 3], [1, 4], [1, 5], [2, 4], [2, 5], [3, 4], [3, 5]], [],
        ],
        'a round robin of six with one round pinned' => [
            6,
            [[0, 2], [0, 3], [0, 4], [0, 5], [1, 2], [1, 3], [1, 4], [1, 5], [2, 4], [2, 5], [3, 4], [3, 5]],
            [[0, 1, 0, 0], [2, 3, 0, 0], [4, 5, 0, 0]],
        ],
        'repeated events and pins at several positions' => [
            5,
            [[0, 1], [0, 1], [1, 2], [2, 3], [3, 4], [4, 0], [2, 4]],
            [[0, 2, 0, 1], [1, 3, 1, 0], [1, 4, 0, 1]],
        ],
    ]);

    it('orders random requests alike however their participants are numbered', function (int $seed): void {
        $rng = new Randomizer(new Mt19937($seed));
        $count = $rng->getInt(4, 9);
        $events = [];
        for ($i = $rng->getInt(4, 16); $i > 0; --$i) {
            $a = $rng->getInt(0, $count - 1);
            $b = ($a + $rng->getInt(1, $count - 1)) % $count;
            $events[] = [$a, $b];
        }
        $pins = [];
        for ($i = $rng->getInt(0, 3); $i > 0; --$i) {
            $a = $rng->getInt(0, $count - 1);
            $pins[] = [$a, ($a + $rng->getInt(1, $count - 1)) % $count, $rng->getInt(0, 1), $i];
        }
        $form = structuralForm($count, $events, $pins);

        foreach ([1, 2, 3] as $shuffle) {
            [$newEvents, $newPins] = renumbered($count, $events, $pins, 100 * $seed + $shuffle);

            expect(structuralForm($count, $newEvents, $newPins))->toBe($form);
        }
    })->with(range(1, 60));

    it('falls back to the numbers, class by class, when it is given no work to do', function (): void {
        // A ring of six with a participant outside it that plays nobody
        // in it twice: more events still come first, and the ring, which
        // takes trying each of its participants to order, stays as numbered
        $events = [[1, 2], [2, 3], [3, 4], [4, 5], [5, 6], [6, 1], [0, 1], [0, 3], [0, 5]];

        $order = StructuralOrder::of(7, $events, [], 0);

        expect($order)->toEqualCanonicalizing([0, 1, 2, 3, 4, 5, 6])
            ->and(array_slice($order, 0, 4))->toBe([0, 1, 3, 5])
            ->and(array_slice($order, 4))->toBe([2, 4, 6]);
    });

    it('stops splitting the classes once it is past its work limit, and leaves them in the order of the numbers', function (): void {
        // A line of nine. Splitting to the end takes a pass for each step
        // from the ends to the middle: 4 first, then 3 and 5, 2 and 6, 1
        // and 7, and the ends. With no work to do, only the pass that was
        // under way when the limit was passed is made: the two events of
        // 2 to 6 are both against a participant with two events, those of
        // 1 and 7 are not, and the ends have one event. A line of a
        // thousand would otherwise take five hundred passes over all of
        // it, whatever the limit
        $line = [[0, 1], [1, 2], [2, 3], [3, 4], [4, 5], [5, 6], [6, 7], [7, 8]];

        expect(StructuralOrder::of(9, $line, [], 1))->toBe([2, 3, 4, 5, 6, 1, 7, 0, 8])
            ->and(StructuralOrder::of(9, $line, [])[0])->toBe(4);
    });

    it('orders a round robin of forty with one round played within its work limit', function (): void {
        // The hardest request of that size for the ordering: everybody has
        // the same events and the same pin, and no two can be exchanged,
        // so each participant left is tried at every step. Within the
        // limit the order is the one unlimited work gives, and so does not
        // depend on the numbers; at 600,000 opponents looked at, which the
        // limit once was, it fell back to the numbers
        $events = [];
        $pins = [];
        for ($i = 0; $i < 40; ++$i) {
            for ($j = $i + 1; $j < 40; ++$j) {
                $round = $j === 39 ? (2 * $i) % 39 : ($i + $j) % 39;
                if ($round === 0) {
                    $pins[] = [$i, $j, 0, 0];
                } else {
                    $events[] = [$i, $j];
                }
            }
        }
        [$events, $pins] = renumbered(40, $events, $pins, 7);

        expect(StructuralOrder::of(40, $events, $pins))->toBe(StructuralOrder::of(40, $events, $pins, PHP_INT_MAX));
    });

    it('orders no participants as nothing, and participants with no events by their numbers', function (): void {
        expect(StructuralOrder::of(0, [], []))->toBe([])
            ->and(StructuralOrder::of(3, [], []))->toBe([0, 1, 2]);
    });
});
