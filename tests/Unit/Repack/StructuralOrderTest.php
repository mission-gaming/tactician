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

/**
 * A request with the current positions of its movable events, written in
 * the order StructuralOrder gives its participants when it is given those
 * positions: every event with the places of its participants for their
 * numbers and its current position. Two requests that differ only in how
 * the participants are numbered have the same form exactly when the order
 * took their participants alike, current positions included.
 *
 * @param array<int, array{int, int}> $events
 * @param array<int, array{int, int, int, int}> $pins
 * @param array<int, array{int, int, int, int}> $current
 *
 * @return list<string>
 */
function structuralFormWithPositions(int $count, array $events, array $pins, array $current): array
{
    $place = array_flip(StructuralOrder::of($count, $events, $pins, StructuralOrder::WORK_LIMIT, $current));

    $written = [];
    foreach ($events as [$a, $b]) {
        $written[] = min($place[$a], $place[$b]) . '-' . max($place[$a], $place[$b]);
    }
    foreach ($pins as [$a, $b, $session, $slot]) {
        $written[] = min($place[$a], $place[$b]) . '-' . max($place[$a], $place[$b]) . " pinned@{$session}:{$slot}";
    }
    foreach ($current as [$a, $b, $session, $slot]) {
        $written[] = min($place[$a], $place[$b]) . '-' . max($place[$a], $place[$b]) . " now@{$session}:{$slot}";
    }
    sort($written);

    return $written;
}

/**
 * A round robin laid out round by round by the circle method, one round to
 * a session of one slot: the events of the first $pinned rounds as pins,
 * the others as movable events that are now where they were laid out, but
 * for those $moved puts elsewhere (by their place among the movable ones).
 *
 * @param array<int, array{int, int}> $moved
 *
 * @return array{0: list<array{int, int}>, 1: list<array{int, int, int, int}>, 2: list<array{int, int, int, int}>}
 */
function roundRobinLaidOut(int $count, int $legs, int $pinned, array $moved = []): array
{
    $events = [];
    $pins = [];
    $current = [];
    $round = 0;
    for ($leg = 0; $leg < $legs; ++$leg) {
        $seats = range(0, $count - 1);
        for ($r = 0; $r < $count - 1; ++$r, ++$round) {
            for ($i = 0; $i < intdiv($count, 2); ++$i) {
                [$a, $b] = [$seats[$i], $seats[$count - 1 - $i]];
                if ($round < $pinned) {
                    $pins[] = [$a, $b, $round, 0];
                } else {
                    [$session, $slot] = $moved[count($events)] ?? [$round, 0];
                    $events[] = [$a, $b];
                    $current[] = [$a, $b, $session, $slot];
                }
            }
            array_splice($seats, 1, 0, [array_pop($seats)]);
        }
    }

    return [$events, $pins, $current];
}

/**
 * The same request with its participants numbered by a seeded shuffle,
 * current positions included, and its lists in another order.
 *
 * @param array<int, array{int, int}> $events
 * @param array<int, array{int, int, int, int}> $pins
 * @param array<int, array{int, int, int, int}> $current
 *
 * @return array{0: list<array{int, int}>, 1: list<array{int, int, int, int}>, 2: list<array{int, int, int, int}>}
 */
function renumberedWithPositions(int $count, array $events, array $pins, array $current, int $seed): array
{
    $rng = new Randomizer(new Mt19937($seed));
    $number = $rng->shuffleArray(range(0, $count - 1));
    $events = array_map(static fn(array $event): array => [$number[$event[1]], $number[$event[0]]], $events);
    $pins = array_map(static fn(array $pin): array => [$number[$pin[1]], $number[$pin[0]], $pin[2], $pin[3]], $pins);
    $current = array_map(static fn(array $at): array => [$number[$at[1]], $number[$at[0]], $at[2], $at[3]], $current);

    return [$rng->shuffleArray($events), $rng->shuffleArray($pins), $rng->shuffleArray($current)];
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

    it('puts participants that can be exchanged in the order of their current positions, where it is given them', function (): void {
        // A complete round robin of four. 0-1 says it is at the second
        // position and 2-3 at the first; the other events say nothing
        $events = [[0, 1], [0, 2], [0, 3], [1, 2], [1, 3], [2, 3]];
        $current = [[0, 1, 1, 0], [2, 3, 0, 0]];

        expect(StructuralOrder::of(4, $events, [], StructuralOrder::WORK_LIMIT, $current))->toBe([2, 3, 0, 1])
            // Numbered the other way round, the same two come first
            ->and(StructuralOrder::of(4, $events, [], StructuralOrder::WORK_LIMIT, [[2, 3, 1, 0], [0, 1, 0, 0]]))->toBe([0, 1, 2, 3])
            // And with no current positions the numbers decide, as before
            ->and(StructuralOrder::of(4, $events, []))->toBe([0, 1, 2, 3]);
    });

    it('reads current positions only between participants that nothing else tells apart', function (): void {
        // 1 plays the three others: it comes first however the current
        // positions put the others. Of those, 2 states none, then 3 is at
        // the first position and 0 at the second
        expect(StructuralOrder::of(4, [[0, 1], [1, 2], [3, 1]], [], StructuralOrder::WORK_LIMIT, [[3, 1, 0, 0], [0, 1, 1, 0]]))
            ->toBe([1, 2, 3, 0]);
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

    /** @param array<int, array{int, int}> $moved */
    it('orders a round robin with current positions alike however its participants are numbered', function (int $count, int $legs, int $pinned, array $moved): void {
        // Everybody plays in every round, so a participant's own positions
        // say nothing against a class of them all; what tells them apart
        // is where they meet the participants already put in order. When
        // the order read only their own positions, the numbers chose which
        // participant went first in the class, and the form depended on them
        [$events, $pins, $current] = roundRobinLaidOut($count, $legs, $pinned, $moved);
        $form = structuralFormWithPositions($count, $events, $pins, $current);

        foreach ([1, 2, 3, 4, 5] as $seed) {
            [$newEvents, $newPins, $newCurrent] = renumberedWithPositions($count, $events, $pins, $current, $seed);

            expect(structuralFormWithPositions($count, $newEvents, $newPins, $newCurrent))->toBe($form);
        }
    })->with([
        'a double round robin of four, nothing pinned, everything where it is (participants that can be exchanged)' => [4, 2, 0, []],
        'a double round robin of four, nothing pinned, two events elsewhere' => [4, 2, 0, [0 => [5, 0], 7 => [0, 0]]],
        'a round robin of six, nothing pinned, one event elsewhere' => [6, 1, 0, [4 => [0, 0]]],
        'a round robin of six with one round pinned (no two can simply be exchanged)' => [6, 1, 1, []],
        'a round robin of six with one round pinned, two events elsewhere' => [6, 1, 1, [0 => [4, 0], 9 => [1, 0]]],
        'a double round robin of eight with one round pinned, one event elsewhere' => [8, 2, 1, [20 => [3, 0]]],
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

    it('leaves the classes past its work limit in the order of their current positions, where those are given', function (): void {
        // The line of nine again, its events now the later the lower their
        // numbers: within each class left at the limit the participant with
        // the earlier current positions goes first
        $line = [[0, 1], [1, 2], [2, 3], [3, 4], [4, 5], [5, 6], [6, 7], [7, 8]];
        $current = array_map(static fn(array $event): array => [$event[0], $event[1], 8 - $event[0], 0], $line);

        expect(StructuralOrder::of(9, $line, [], 1, $current))->toBe([6, 5, 4, 3, 2, 7, 1, 8, 0]);
    });

    it('lets the current positions decide nothing more once their refinement is past the work limit', function (): void {
        // A round robin of six with one round pinned: the six are tried in
        // number order as the one put first, all with equal descriptions,
        // and with the whole limit the current positions choose 1. A trial
        // whose refinement with the positions is cut short describes
        // nothing, so wherever the limit falls the choice is 1, or 0 when
        // it falls before 1 is compared. When an empty description counted
        // as the smallest, the trial under way when the limit was passed
        // was chosen: 2, 3, 4 or 5, depending on the limit alone
        [$events, $pins, $current] = roundRobinLaidOut(6, 1, 1);

        $firsts = [];
        for ($limit = 0; $limit <= 1_200; $limit += 6) {
            $firsts[StructuralOrder::of(6, $events, $pins, $limit, $current)[0]] = true;
        }

        expect(StructuralOrder::of(6, $events, $pins, StructuralOrder::WORK_LIMIT, $current)[0])->toBe(1)
            ->and(array_keys($firsts))->toEqualCanonicalizing([0, 1]);
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

    it('orders that round robin of forty with its current positions within their limit, and never cuts the refinement short for them', function (): void {
        // The same round robin of forty, every event where it was laid out.
        // Reading the positions takes about twice the work, 2,610,000, and
        // has twice the limit: within it the order is the one unlimited
        // work gives, so the ids decide nothing the positions could (at
        // the limit of the refinement without them, 1,500,000, the
        // positions stopped deciding from 36 participants). Their work is
        // counted apart, so the refinement without them still finishes,
        // and the order is the one without them up to a symmetry of the
        // request; counted together, that refinement was cut short and the
        // participants left fell to their positions alone
        $events = [];
        $pins = [];
        $current = [];
        for ($i = 0; $i < 40; ++$i) {
            for ($j = $i + 1; $j < 40; ++$j) {
                $round = $j === 39 ? (2 * $i) % 39 : ($i + $j) % 39;
                if ($round === 0) {
                    $pins[] = [$i, $j, 0, 0];
                } else {
                    $events[] = [$i, $j];
                    $current[] = [$i, $j, $round, 0];
                }
            }
        }
        /**
         * @param list<int> $order
         *
         * @return list<string>
         */
        $form = static function (array $order) use ($events, $pins): array {
            $place = array_flip($order);
            $written = [];
            foreach ($events as [$a, $b]) {
                $written[] = min($place[$a], $place[$b]) . '-' . max($place[$a], $place[$b]);
            }
            foreach ($pins as [$a, $b]) {
                $written[] = min($place[$a], $place[$b]) . '-' . max($place[$a], $place[$b]) . ' pinned';
            }
            sort($written);

            return $written;
        };

        $order = StructuralOrder::of(40, $events, $pins, StructuralOrder::WORK_LIMIT, $current);

        expect($order)->toBe(StructuralOrder::of(40, $events, $pins, PHP_INT_MAX, $current))
            ->and($form($order))->toBe($form(StructuralOrder::of(40, $events, $pins)));
    });

    it('orders no participants as nothing, and participants with no events by their numbers', function (): void {
        expect(StructuralOrder::of(0, [], []))->toBe([])
            ->and(StructuralOrder::of(3, [], []))->toBe([0, 1, 2]);
    });
});
