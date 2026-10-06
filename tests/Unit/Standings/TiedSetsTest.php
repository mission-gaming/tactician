<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Exceptions\InvalidInputException;
use MissionGaming\Tactician\Standings\BuchholzTiebreaker;
use MissionGaming\Tactician\Standings\SonnebornBergerTiebreaker;
use MissionGaming\Tactician\Standings\StandingEntry;
use MissionGaming\Tactician\Standings\Standings;
use MissionGaming\Tactician\Standings\StandingsCalculator;
use MissionGaming\Tactician\Standings\TiedSet;
use MissionGaming\Tactician\Standings\WinDrawLossRanking;
use MissionGaming\Tactician\Standings\WinsTiebreaker;

/**
 * The tied sets of a table as plain data: the participant ids of each set in
 * table order, with the first and the last position the set spans.
 *
 * @return list<array{ids: list<string>, first: int, last: int}>
 */
function tiedSetSummary(Standings $standings): array
{
    $summary = [];
    foreach ($standings->getTiedSets() as $tiedSet) {
        $summary[] = [
            'ids' => array_map(
                static fn(Participant $participant): string => $participant->getId(),
                $tiedSet->getParticipants()
            ),
            'first' => $tiedSet->getFirstPosition(),
            'last' => $tiedSet->getLastPosition(),
        ];
    }

    return $summary;
}

/**
 * The participant ids of a table, best-placed first.
 *
 * @return list<string>
 */
function tableOrder(Standings $standings): array
{
    return array_values(array_map(
        static fn(StandingEntry $entry): string => $entry->getParticipant()->getId(),
        $standings->getEntries()
    ));
}

/**
 * A pool of four with fallback-distinct seeds and labels, so the fallback
 * alone gives the order d, c, b, a.
 *
 * @return array{a: Participant, b: Participant, c: Participant, d: Participant}
 */
function tiedPool(): array
{
    return [
        'a' => new Participant('a', 'Amy', 4),
        'b' => new Participant('b', 'Bob', 3),
        'c' => new Participant('c', 'Cat', 2),
        'd' => new Participant('d', 'Zed', 1),
    ];
}

describe('Standings::getTiedSets()', function (): void {
    it('reports one tied set of four, spanning positions 1 to 4, when every event of a pool of 4 is drawn 0-0', function (): void {
        $pool = tiedPool();
        $participants = array_values($pool);

        $results = [];
        foreach ($participants as $i => $home) {
            foreach (array_slice($participants, $i + 1) as $away) {
                $results[] = new Result(
                    new Event([$home, $away]),
                    null,
                    [$home->getId() => 0, $away->getId() => 0]
                );
            }
        }

        $standings = (new StandingsCalculator())->calculate($participants, $results);

        // The seeds alone order the table; every result-based figure is level
        expect(tableOrder($standings))->toBe(['d', 'c', 'b', 'a'])
            ->and(tiedSetSummary($standings))->toBe([
                ['ids' => ['d', 'c', 'b', 'a'], 'first' => 1, 'last' => 4],
            ]);
    });

    it('reports one tied set of every participant when a pool has no results', function (
        StandingsCalculator $calculator
    ): void {
        $participants = array_values(tiedPool());
        $participants[] = new Participant('e', 'Eve');

        $standings = $calculator->calculate($participants, []);

        expect(tiedSetSummary($standings))->toBe([
            ['ids' => ['d', 'c', 'b', 'a', 'e'], 'first' => 1, 'last' => 5],
        ]);
    })->with([
        'no tiebreakers' => [new StandingsCalculator()],
        'every built-in tiebreaker' => [new StandingsCalculator(
            WinDrawLossRanking::oneHalfZero(),
            [new WinsTiebreaker(), new BuchholzTiebreaker(), new SonnebornBergerTiebreaker()]
        )],
    ]);

    it('reports no tied sets when no two entries are level', function (): void {
        $pool = tiedPool();

        // a beats everyone, b beats c and d, c beats d: 9, 6, 3 and 0 points
        $results = [
            new Result(new Event([$pool['a'], $pool['b']]), $pool['a']),
            new Result(new Event([$pool['a'], $pool['c']]), $pool['a']),
            new Result(new Event([$pool['a'], $pool['d']]), $pool['a']),
            new Result(new Event([$pool['b'], $pool['c']]), $pool['b']),
            new Result(new Event([$pool['b'], $pool['d']]), $pool['b']),
            new Result(new Event([$pool['c'], $pool['d']]), $pool['c']),
        ];

        $standings = (new StandingsCalculator())->calculate(array_values($pool), $results);

        expect(tableOrder($standings))->toBe(['a', 'b', 'c', 'd'])
            ->and($standings->getTiedSets())->toBe([]);
    });

    it('reports two separate ties as two sets with their own positions', function (): void {
        $a = new Participant('a', 'Amy');
        $b = new Participant('b', 'Bob');
        $c = new Participant('c', 'Cat');
        $d = new Participant('d', 'Dan');
        $e = new Participant('e', 'Eve');
        $f = new Participant('f', 'Fay');

        // Eve wins 2-1, Amy and Bob win 1-0: the three winners have 3 points
        // and a score difference of +1, and Eve has scored more. Fay loses
        // 1-2, Cat and Dan lose 0-1: three on 0 points and -1, and Fay has
        // scored more.
        $results = [
            new Result(new Event([$a, $c]), $a, ['a' => 1, 'c' => 0]),
            new Result(new Event([$b, $d]), $b, ['b' => 1, 'd' => 0]),
            new Result(new Event([$e, $f]), $e, ['e' => 2, 'f' => 1]),
        ];

        $standings = (new StandingsCalculator())->calculate([$f, $e, $d, $c, $b, $a], $results);

        expect(tableOrder($standings))->toBe(['e', 'a', 'b', 'f', 'c', 'd'])
            ->and(tiedSetSummary($standings))->toBe([
                ['ids' => ['a', 'b'], 'first' => 2, 'last' => 3],
                ['ids' => ['c', 'd'], 'first' => 5, 'last' => 6],
            ]);
    });

    it('returns the entries of a set in table order, as the entries of the table', function (): void {
        $participants = array_values(tiedPool());
        $standings = (new StandingsCalculator())->calculate($participants, []);

        $tiedSets = $standings->getTiedSets();

        expect($tiedSets)->toHaveCount(1)
            ->and($tiedSets[0]->getEntries())->toBe(array_values($standings->getEntries()))
            ->and($tiedSets[0])->toHaveCount(4);

        foreach ($tiedSets[0]->getParticipants() as $offset => $participant) {
            expect($standings->getPosition($participant))->toBe($tiedSets[0]->getFirstPosition() + $offset);
        }
    });

    it('leaves the entries, their order and every existing accessor as they were', function (): void {
        $pool = tiedPool();
        $participants = array_values($pool);
        $results = [new Result(new Event([$pool['a'], $pool['b']]), $pool['a'], ['a' => 2, 'b' => 1])];

        $calculator = new StandingsCalculator(WinDrawLossRanking::threeOneZero(), [new WinsTiebreaker()]);
        $untouched = $calculator->calculate($participants, $results);
        $standings = $calculator->calculate($participants, $results);

        $standings->getTiedSets();

        // a won; c and d are level and the seeds put d first; b lost
        expect(tableOrder($standings))->toBe(['a', 'd', 'c', 'b'])
            ->and($standings->getEntries())->toEqual($untouched->getEntries())
            ->and(iterator_to_array($standings))->toEqual($untouched->getEntries())
            ->and($standings)->toHaveCount(4)
            ->and($standings->getPosition($pool['a']))->toBe(1)
            ->and($standings->getPosition($pool['d']))->toBe(2)
            ->and($standings->getPosition($pool['c']))->toBe(3)
            ->and($standings->getPosition($pool['b']))->toBe(4)
            ->and($standings->getEntryFor($pool['a'])?->getRankingValue())->toBe(3.0)
            ->and($standings->getEntryFor($pool['a'])?->getTiebreakers())->toBe(['wins' => 1.0])
            ->and($standings->getEntryFor(new Participant('x', 'Stranger')))->toBeNull()
            ->and($standings->getPosition(new Participant('x', 'Stranger')))->toBeNull();
    });

    it('reports nothing for an empty table and for a table of one', function (): void {
        expect((new StandingsCalculator())->calculate([], [])->getTiedSets())->toBe([])
            ->and((new StandingsCalculator())->calculate([new Participant('a', 'Amy')], [])->getTiedSets())->toBe([]);
    });

    it('returns the same sets on every call', function (): void {
        $standings = (new StandingsCalculator())->calculate(array_values(tiedPool()), []);

        expect($standings->getTiedSets())->toEqual($standings->getTiedSets());
    });
});

describe('what decides an order is not a tie', function (): void {
    it('does not report entries the fallback did not have to order, whatever their seeds, labels and ids', function (): void {
        // The same level pair under four different fallbacks: the tie is
        // reported each time, and only the order inside the set follows the
        // fallback (seed, then label, then id).
        $cases = [
            'seed' => [new Participant('a', 'Amy', 2), new Participant('b', 'Bob', 1), ['b', 'a']],
            'seeded before unseeded' => [new Participant('a', 'Amy'), new Participant('b', 'Bob', 9), ['b', 'a']],
            'label' => [new Participant('a', 'Zoe'), new Participant('b', 'Bob'), ['b', 'a']],
            'id' => [new Participant('b', 'Same'), new Participant('a', 'Same'), ['a', 'b']],
        ];

        foreach ($cases as [$first, $second, $order]) {
            $standings = (new StandingsCalculator())->calculate([$first, $second], []);

            expect(tiedSetSummary($standings))->toBe([['ids' => $order, 'first' => 1, 'last' => 2]]);
        }
    });

    it('does not report a tie the wins tiebreaker breaks', function (): void {
        $a = new Participant('a', 'Amy');
        $b = new Participant('b', 'Bob');
        $x = new Participant('x', 'Xan');
        $y1 = new Participant('y1', 'Yan 1');
        $y2 = new Participant('y2', 'Yan 2');
        $y3 = new Participant('y3', 'Yan 3');
        $participants = [$a, $b, $x, $y1, $y2, $y3];

        // Amy: one win. Bob: three draws. Both have 3 points and no scores.
        $results = [
            new Result(new Event([$a, $x]), $a),
            new Result(new Event([$b, $y1])),
            new Result(new Event([$b, $y2])),
            new Result(new Event([$b, $y3])),
        ];

        $level = (new StandingsCalculator())->calculate($participants, $results);
        $broken = (new StandingsCalculator(WinDrawLossRanking::threeOneZero(), [new WinsTiebreaker()]))
            ->calculate($participants, $results);

        expect(tiedSetSummary($level)[0])->toBe(['ids' => ['a', 'b'], 'first' => 1, 'last' => 2])
            ->and(tableOrder($broken))->toBe(['a', 'b', 'y1', 'y2', 'y3', 'x'])
            ->and(tiedSetSummary($broken))->toBe([['ids' => ['y1', 'y2', 'y3'], 'first' => 3, 'last' => 5]]);
    });

    it('does not report a tie the Buchholz or the Sonneborn-Berger tiebreaker breaks', function (): void {
        $a = new Participant('a', 'Amy');
        $b = new Participant('b', 'Bob');
        $c = new Participant('c', 'Cat');
        $d = new Participant('d', 'Dan');
        $participants = [$a, $b, $c, $d];

        // Amy beat Bob, Cat beat Dan, Bob beat Dan: Amy, Bob and Cat have
        // one win and 3 points each, Dan has none.
        // Buchholz (opponents' points): Amy 3, Bob 3 + 0, Cat 0.
        // Sonneborn-Berger (beaten opponents' points): Amy 3, Bob 0, Cat 0.
        $results = [
            new Result(new Event([$a, $b]), $a),
            new Result(new Event([$c, $d]), $c),
            new Result(new Event([$b, $d]), $b),
        ];
        $ranking = WinDrawLossRanking::threeOneZero();

        $level = (new StandingsCalculator($ranking))->calculate($participants, $results);
        $buchholz = (new StandingsCalculator($ranking, [new BuchholzTiebreaker()]))->calculate($participants, $results);
        $sonnebornBerger = (new StandingsCalculator($ranking, [new SonnebornBergerTiebreaker()]))
            ->calculate($participants, $results);
        // Buchholz first leaves Amy and Bob level; Sonneborn-Berger second separates them
        $both = (new StandingsCalculator($ranking, [new BuchholzTiebreaker(), new SonnebornBergerTiebreaker()]))
            ->calculate($participants, $results);

        expect(tiedSetSummary($level))->toBe([['ids' => ['a', 'b', 'c'], 'first' => 1, 'last' => 3]])
            ->and(tiedSetSummary($buchholz))->toBe([['ids' => ['a', 'b'], 'first' => 1, 'last' => 2]])
            ->and(tiedSetSummary($sonnebornBerger))->toBe([['ids' => ['b', 'c'], 'first' => 2, 'last' => 3]])
            ->and($both->getTiedSets())->toBe([]);
    });

    it('does not report a tie the score difference breaks', function (int $amyScore, array $expected): void {
        $a = new Participant('a', 'Amy');
        $b = new Participant('b', 'Bob');
        $x = new Participant('x', 'Xan');
        $y = new Participant('y', 'Yan');

        // Bob wins 2-1. Amy wins by the same margin or by one more; she
        // concedes one as well, so her scores-for can match Bob's.
        $results = [
            new Result(new Event([$a, $x]), $a, ['a' => $amyScore, 'x' => $amyScore === 2 ? 1 : 0]),
            new Result(new Event([$b, $y]), $b, ['b' => 2, 'y' => 1]),
        ];

        $standings = (new StandingsCalculator())->calculate([$a, $b, $x, $y], $results);

        expect(tiedSetSummary($standings))->toBe($expected);
    })->with([
        'control: 2-1 and 2-1 are level' => [2, [
            ['ids' => ['a', 'b'], 'first' => 1, 'last' => 2],
            ['ids' => ['x', 'y'], 'first' => 3, 'last' => 4],
        ]],
        // Amy +3, Bob +1; Yan -1, Xan -3
        '3-0 against 2-1' => [3, []],
    ]);

    it('does not report a tie the scores-for figure breaks', function (int $highScore, array $expected): void {
        $high = new Participant('hi', 'High');
        $low = new Participant('lo', 'Low');
        $foilA = new Participant('fa', 'Foil A');
        $foilB = new Participant('fb', 'Foil B');

        // Two draws: every entry has 1 point and a score difference of 0
        $results = [
            new Result(new Event([$high, $foilA]), null, ['hi' => $highScore, 'fa' => $highScore]),
            new Result(new Event([$low, $foilB]), null, ['lo' => 1, 'fb' => 1]),
        ];

        $standings = (new StandingsCalculator())->calculate([$high, $low, $foilA, $foilB], $results);

        expect(tiedSetSummary($standings))->toBe($expected);
    })->with([
        'control: 1-1 and 1-1 are level' => [1, [
            ['ids' => ['fa', 'fb', 'hi', 'lo'], 'first' => 1, 'last' => 4],
        ]],
        '2-2 against 1-1' => [2, [
            ['ids' => ['fa', 'hi'], 'first' => 1, 'last' => 2],
            ['ids' => ['fb', 'lo'], 'first' => 3, 'last' => 4],
        ]],
    ]);

    it('treats ranking values as level only when they are equal, as the table order does', function (): void {
        // Three wins at 0.1 add up to 0.30000000000000004, which is above one
        // draw at 0.3. The calculator places Amy above Bob on the ranking
        // value although Bob has the better seed, so the two are not tied.
        $a = new Participant('a', 'Amy', 2);
        $b = new Participant('b', 'Bob', 1);
        $x = new Participant('x', 'Xan', 3);
        $ranking = WinDrawLossRanking::fromArray(['win' => 0.1, 'draw' => 0.3, 'loss' => 0.0]);

        $results = [
            new Result(new Event([$a, $x]), $a),
            new Result(new Event([$a, $x]), $a),
            new Result(new Event([$a, $x]), $a),
            new Result(new Event([$b, $x])),
        ];

        $standings = (new StandingsCalculator($ranking))->calculate([$a, $b, $x], $results);

        expect($standings->getEntryFor($a)?->getRankingValue())->not->toBe($standings->getEntryFor($b)?->getRankingValue())
            ->and(tableOrder($standings))->toBe(['a', 'b', 'x'])
            // Xan's one draw is the same 0.3 as Bob's, so those two are level
            ->and(tiedSetSummary($standings))->toBe([['ids' => ['b', 'x'], 'first' => 2, 'last' => 3]]);
    });
});

describe('StandingEntry::isLevelWith()', function (): void {
    $entry = static fn(
        string $id,
        float $rankingValue = 0.0,
        float $scoreFor = 0.0,
        float $scoreAgainst = 0.0,
        array $tiebreakers = [],
        int $played = 0,
        int $wins = 0,
    ): StandingEntry => new StandingEntry(
        new Participant($id, strtoupper($id)),
        $played,
        $wins,
        0,
        0,
        $rankingValue,
        $scoreFor,
        $scoreAgainst,
        $tiebreakers
    );

    it('is true when the ranking value, every tiebreaker value, the score difference and scores-for are equal', function () use ($entry): void {
        $one = $entry('a', 4.0, 3.0, 1.0, ['wins' => 1.0, 'buchholz' => 2.5]);
        $other = $entry('b', 4.0, 3.0, 1.0, ['wins' => 1.0, 'buchholz' => 2.5]);

        expect($one->isLevelWith($other))->toBeTrue()
            ->and($other->isLevelWith($one))->toBeTrue()
            ->and($one->isLevelWith($one))->toBeTrue();
    });

    it('is false when one compared figure differs', function (StandingEntry $other) use ($entry): void {
        $one = $entry('a', 4.0, 3.0, 1.0, ['wins' => 1.0, 'buchholz' => 2.5]);

        expect($one->isLevelWith($other))->toBeFalse()
            ->and($other->isLevelWith($one))->toBeFalse();
    })->with([
        'ranking value' => [fn() => $entry('b', 4.5, 3.0, 1.0, ['wins' => 1.0, 'buchholz' => 2.5])],
        'first tiebreaker' => [fn() => $entry('b', 4.0, 3.0, 1.0, ['wins' => 2.0, 'buchholz' => 2.5])],
        'second tiebreaker' => [fn() => $entry('b', 4.0, 3.0, 1.0, ['wins' => 1.0, 'buchholz' => 2.0])],
        'score difference' => [fn() => $entry('b', 4.0, 3.0, 2.0, ['wins' => 1.0, 'buchholz' => 2.5])],
        // 4 - 2 is the same difference as 3 - 1
        'scores-for' => [fn() => $entry('b', 4.0, 4.0, 2.0, ['wins' => 1.0, 'buchholz' => 2.5])],
    ]);

    it('ignores the record and the participant, which no comparison step reads', function () use ($entry): void {
        $one = $entry('a', 3.0, played: 1, wins: 1);
        $other = $entry('b', 3.0, played: 3, wins: 0);

        expect($one->isLevelWith($other))->toBeTrue();
    });

    it('reads a tiebreaker value an entry does not carry as zero, as the calculator does', function () use ($entry): void {
        $without = $entry('a', 1.0);

        expect($without->isLevelWith($entry('b', 1.0, tiebreakers: ['wins' => 0.0])))->toBeTrue()
            ->and($entry('b', 1.0, tiebreakers: ['wins' => 0.0])->isLevelWith($without))->toBeTrue()
            ->and($without->isLevelWith($entry('b', 1.0, tiebreakers: ['wins' => 1.0])))->toBeFalse()
            ->and($entry('b', 1.0, tiebreakers: ['wins' => 1.0])->isLevelWith($without))->toBeFalse();
    });

    it('treats positive and negative zero as level', function () use ($entry): void {
        expect($entry('a', 0.0)->isLevelWith($entry('b', -0.0)))->toBeTrue();
    });

    it('treats equal infinities as level and opposite ones as not', function () use ($entry): void {
        expect($entry('a', INF)->isLevelWith($entry('b', INF)))->toBeTrue()
            ->and($entry('a', -INF)->isLevelWith($entry('b', -INF)))->toBeTrue()
            ->and($entry('a', INF)->isLevelWith($entry('b', -INF)))->toBeFalse()
            ->and($entry('a', INF)->isLevelWith($entry('b', PHP_FLOAT_MAX)))->toBeFalse();
    });

    it('treats a figure that is not a number as level with nothing, itself included', function (StandingEntry $notANumber) use ($entry): void {
        // The calculator's comparison does not return "equal" for NAN either
        expect($notANumber->isLevelWith($notANumber))->toBeFalse()
            ->and($notANumber->isLevelWith($entry('b', 1.0)))->toBeFalse()
            ->and($entry('b', 1.0)->isLevelWith($notANumber))->toBeFalse();
    })->with([
        'ranking value' => [fn() => $entry('a', NAN)],
        'tiebreaker value' => [fn() => $entry('a', 1.0, tiebreakers: ['wins' => NAN])],
        'scores-for' => [fn() => $entry('a', 1.0, NAN)],
        // Infinity for and against: the difference is not a number
        'score difference' => [fn() => $entry('a', 1.0, INF, INF)],
    ]);

    it('is level by the next neighbouring float and not by a rounded text form', function () use ($entry): void {
        $one = $entry('a', 1.0, tiebreakers: ['buchholz' => 0.3]);

        expect($one->isLevelWith($entry('b', 1.0, tiebreakers: ['buchholz' => 0.1 + 0.2])))->toBeFalse()
            ->and($one->isLevelWith($entry('b', 1.0 + PHP_FLOAT_EPSILON, tiebreakers: ['buchholz' => 0.3])))->toBeFalse()
            // 0.3 - 0.1 is not 0.2 - 0.0, and 0.1 + 0.2 is not 0.3
            ->and($entry('a', 0.0, 0.3, 0.1)->isLevelWith($entry('b', 0.0, 0.2, 0.0)))->toBeFalse()
            ->and($entry('a', 0.0, 0.1 + 0.2, 0.1 + 0.2)->isLevelWith($entry('b', 0.0, 0.3, 0.3)))->toBeFalse();
    });
});

describe('a table built by hand', function (): void {
    it('reports each run of adjacent level entries', function (): void {
        $entry = static fn(string $id, float $rankingValue): StandingEntry => new StandingEntry(
            new Participant($id, $id),
            0,
            0,
            0,
            0,
            $rankingValue
        );

        // String keys and an order no calculator would give: the level
        // entries a and d are not adjacent, so they are not one set
        $standings = new Standings([
            'first' => $entry('a', 2.0),
            'second' => $entry('b', 1.0),
            'third' => $entry('c', 1.0),
            'fourth' => $entry('d', 2.0),
        ]);

        expect(tiedSetSummary($standings))->toBe([['ids' => ['b', 'c'], 'first' => 2, 'last' => 3]]);
    });
});

describe('TiedSet', function (): void {
    $entries = static fn(int $count): array => array_map(
        static fn(int $number): StandingEntry => new StandingEntry(
            new Participant("p{$number}", "P{$number}"),
            0,
            0,
            0,
            0,
            0.0
        ),
        range(1, $count)
    );

    it('states its entries, its participants and the positions it spans', function () use ($entries): void {
        $three = $entries(3);
        $tiedSet = new TiedSet($three, 4);

        expect($tiedSet->getEntries())->toBe($three)
            ->and(array_map(static fn(Participant $participant): string => $participant->getId(), $tiedSet->getParticipants()))
            ->toBe(['p1', 'p2', 'p3'])
            ->and($tiedSet->getFirstPosition())->toBe(4)
            ->and($tiedSet->getLastPosition())->toBe(6)
            ->and(count($tiedSet))->toBe(3);
    });

    it('lists its entries from zero whatever keys it was given', function () use ($entries): void {
        [$one, $two] = $entries(2);
        $tiedSet = new TiedSet(['x' => $one, 'y' => $two], 1);

        expect($tiedSet->getEntries())->toBe([$one, $two]);
    });

    it('refuses fewer than two entries', function (int $count) use ($entries): void {
        expect(fn() => new TiedSet($count === 0 ? [] : $entries($count), 1))
            ->toThrow(InvalidInputException::class, 'A tied set needs at least 2 entries');
    })->with([0, 1]);

    it('refuses a first position below 1', function (int $position) use ($entries): void {
        expect(fn() => new TiedSet($entries(2), $position))
            ->toThrow(InvalidInputException::class, 'The first position of a tied set must be 1 or higher');
    })->with([0, -1]);
});
