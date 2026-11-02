<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Standings\BetweenTiedParticipants;
use MissionGaming\Tactician\Standings\RankingValueTiebreaker;
use MissionGaming\Tactician\Standings\Standings;
use MissionGaming\Tactician\Standings\StandingsCalculator;
use MissionGaming\Tactician\Standings\WinsTiebreaker;

// StandingsCalculator::calculateWith() given tiers: the table is in tier
// order and has them, and a tiebreaker between the tied participants forms
// its sets inside a tier
// (docs/adr/0014-a-bracket-table-is-one-table-of-its-tiers-and-byes.md).

/**
 * @return array{list<Participant>, list<Result>}
 */
function tiersCase(): array
{
    $a = new Participant('a', 'A');
    $b = new Participant('b', 'B');
    $c = new Participant('c', 'C');
    $d = new Participant('d', 'D');

    // a beat c, c beat b, b beat d, d beat a: everyone on 3 points
    return [[$a, $b, $c, $d], [
        new Result(new Event([$a, $c]), $a),
        new Result(new Event([$c, $b]), $c),
        new Result(new Event([$b, $d]), $b),
        new Result(new Event([$d, $a]), $d),
    ]];
}

/**
 * @return list<string>
 */
function tiersLines(Standings $table): array
{
    $lines = [];
    foreach ($table->getEntries() as $position => $entry) {
        $lines[] = $entry->getParticipant()->getId() . ' ' . ($table->getTiers()[$position] ?? '-') . ' '
            . ($entry->getTiebreakerValue('between-tied:ranking-value') ?? '-');
    }

    return $lines;
}

describe('a table given tiers', function (): void {
    it('forms the tied sets of a tiebreaker between the tied participants inside each tier', function (): void {
        [$participants, $results] = tiersCase();
        $calculator = new StandingsCalculator(tiebreakers: [new BetweenTiedParticipants(new RankingValueTiebreaker())]);

        // Without tiers the four are one set, and each has the 3 points of
        // its one win over the others
        expect(tiersLines($calculator->calculateWith($participants, $results)))
            ->toBe(['a - 3', 'b - 3', 'c - 3', 'd - 3']);

        // a and b never met, so they are level inside their tier; c beat b,
        // which counts for nothing between c and d, who never met either
        expect(tiersLines($calculator->calculateWith($participants, $results, tiers: ['a' => 0, 'b' => 0, 'c' => 1, 'd' => 1])))
            ->toBe(['a 0 0', 'b 0 0', 'c 1 0', 'd 1 0'])
            // a and c are in one tier and met: a's win puts it first
            ->and(tiersLines($calculator->calculateWith($participants, $results, tiers: ['a' => 1, 'b' => 0, 'c' => 1, 'd' => 0])))
            ->toBe(['b 0 3', 'd 0 0', 'a 1 3', 'c 1 0']);
    });

    it('orders by the tier before any figure, and keeps the figures inside a tier', function (): void {
        [[$a, $b, $c, $d], $results] = tiersCase();
        $results[] = new Result(new Event([$c, $d]), $c);
        $calculator = new StandingsCalculator(tiebreakers: [new WinsTiebreaker()]);

        // c has 6 points, the others 3: c is first of its tier and below b
        $table = $calculator->calculateWith([$d, $c, $b, $a], $results, tiers: ['a' => 1, 'b' => 0, 'c' => 1, 'd' => 1]);

        expect(tiersLines($table))->toBe(['b 0 -', 'c 1 -', 'a 1 -', 'd 1 -'])
            ->and($table->getTiedSets())->toHaveCount(1);
    });

    it('puts a participant the tiers do not name in the tier after the last named, and ignores an ID it does not list', function (): void {
        [$participants, $results] = tiersCase();
        $calculator = new StandingsCalculator();

        expect(tiersLines($calculator->calculateWith($participants, $results, tiers: ['c' => 3, 'b' => 1, 'x' => 9])))
            ->toBe(['b 1 -', 'c 3 -', 'a 4 -', 'd 4 -']);
    });

    it('reads a numeric ID as the same key as its string', function (): void {
        $one = new Participant('1', 'One');
        $two = new Participant('2', 'Two');
        $table = new StandingsCalculator()->calculateWith([$one, $two], [new Result(new Event([$one, $two]), $one)], tiers: [2 => 0, 1 => 1]);

        expect(tiersLines($table))->toBe(['2 0 -', '1 1 -']);
    });

    it('is the table without tiers when it is given none', function (): void {
        [$participants, $results] = tiersCase();
        $calculator = new StandingsCalculator(tiebreakers: [new BetweenTiedParticipants(new RankingValueTiebreaker())]);

        expect($calculator->calculateWith($participants, $results)->getTiers())->toBe([]);
    });
});

describe('a calculator without a tiebreaker between the tied participants given tiers', function (): void {
    it('puts the table into tiers, in the order calculate() gave inside each', function (): void {
        [$participants, $results] = tiersCase();
        $calculator = new StandingsCalculator(tiebreakers: [new WinsTiebreaker()]);

        expect(tiersLines($calculator->calculateWith($participants, $results, tiers: ['a' => 1, 'b' => 0, 'c' => 1, 'd' => 0])))
            ->toBe(['b 0 -', 'd 0 -', 'a 1 -', 'c 1 -']);
    });
});
