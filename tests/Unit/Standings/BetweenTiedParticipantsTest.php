<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Standings\Adjustment;
use MissionGaming\Tactician\Standings\BetweenTiedParticipants;
use MissionGaming\Tactician\Standings\RankingValueTiebreaker;
use MissionGaming\Tactician\Standings\ScoreDifferenceTiebreaker;
use MissionGaming\Tactician\Standings\ScoresForTiebreaker;
use MissionGaming\Tactician\Standings\Standings;
use MissionGaming\Tactician\Standings\StandingsCalculator;
use MissionGaming\Tactician\Standings\TiedParticipantsTiebreaker;
use MissionGaming\Tactician\Standings\WinsTiebreaker;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

// A tiebreaker between the tied participants is computed for each set of
// participants that are level when its turn in the chain comes, from the
// events between them. The cases here are the ones where a calculation over
// the whole table, or one that depends on the order of its input, gives
// another answer.

/**
 * A result of an event between two participants: a win for the first, or a
 * draw when the scores are level.
 */
function tiedResult(Participant $first, Participant $second, int $firstScore = 1, int $secondScore = 0): Result
{
    return new Result(
        new Event([$first, $second]),
        $firstScore === $secondScore ? null : ($firstScore > $secondScore ? $first : $second),
        [$first->getId() => $firstScore, $second->getId() => $secondScore]
    );
}

/**
 * The value of one tiebreaker for every entry, by participant ID in table
 * order.
 *
 * @return array<string, float|null>
 */
function tiedValues(Standings $table, string $name): array
{
    $values = [];
    foreach ($table->getEntries() as $entry) {
        $values[$entry->getParticipant()->getId()] = $entry->getTiebreakerValue($name);
    }

    return $values;
}

describe('the library\'s tiebreaker between the tied participants', function (): void {
    it('is named after its measure and is of the kind the calculator recomputes for each tied set', function (): void {
        $tiebreaker = new BetweenTiedParticipants(new ScoreDifferenceTiebreaker());

        expect($tiebreaker->getName())->toBe('between-tied:score-difference')
            ->and($tiebreaker)->toBeInstanceOf(TiedParticipantsTiebreaker::class)
            ->and($tiebreaker->getMeasure())->toBeInstanceOf(ScoreDifferenceTiebreaker::class)
            ->and((new BetweenTiedParticipants(new RankingValueTiebreaker()))->getName())->toBe('between-tied:ranking-value')
            ->and((new BetweenTiedParticipants(new WinsTiebreaker()))->getName())->toBe('between-tied:wins')
            ->and((new BetweenTiedParticipants(new ScoresForTiebreaker()))->getName())->toBe('between-tied:scores-for');
    });

    it('refuses a measure that is itself computed between the tied participants', function (): void {
        try {
            new BetweenTiedParticipants(new BetweenTiedParticipants(new WinsTiebreaker()));
            $this->fail('The measure was accepted.');
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getReason())->toBe(InvalidConfigurationReason::IncompatibleOptions);
        }
    });
});

describe('two participants level on the ranking value', function (): void {
    beforeEach(function (): void {
        $this->a = new Participant('a', 'A');
        $this->b = new Participant('b', 'B');
        $this->c = new Participant('c', 'C');
        $this->field = [$this->a, $this->b, $this->c];
    });

    it('are ordered by the event between them, whatever the rest of the table says', function (): void {
        // B and A both have two wins. B beat A; A has by far the better
        // score difference overall.
        $results = [
            tiedResult($this->b, $this->a, 1, 0),
            tiedResult($this->a, $this->c, 9, 0),
            tiedResult($this->a, $this->c, 9, 0),
            tiedResult($this->b, $this->c, 1, 0),
        ];

        $overall = (new StandingsCalculator())->calculate($this->field, $results);
        $betweenFirst = (new StandingsCalculator(tiebreakers: [new BetweenTiedParticipants(new RankingValueTiebreaker())]))
            ->calculate($this->field, $results);

        expect(array_keys(tiedValues($overall, 'none')))->toBe(['a', 'b', 'c'])
            ->and(tiedValues($betweenFirst, 'between-tied:ranking-value'))->toBe(['b' => 3.0, 'a' => 0.0, 'c' => 0.0])
            ->and($betweenFirst->getTiedSets())->toBe([]);
    });

    it('stay level when they have not met: the measure has nothing to read', function (): void {
        $results = [tiedResult($this->a, $this->c, 2, 0), tiedResult($this->b, $this->c, 2, 0)];

        $table = (new StandingsCalculator(tiebreakers: [
            new BetweenTiedParticipants(new RankingValueTiebreaker()),
            new BetweenTiedParticipants(new ScoreDifferenceTiebreaker()),
            new BetweenTiedParticipants(new ScoresForTiebreaker()),
            new BetweenTiedParticipants(new WinsTiebreaker()),
        ]))->calculate($this->field, $results);

        expect($table->getEntryFor($this->a)?->getTiebreakers())->toBe([
            'between-tied:ranking-value' => 0.0,
            'between-tied:score-difference' => 0.0,
            'between-tied:scores-for' => 0.0,
            'between-tied:wins' => 0.0,
        ])->and(count($table->getTiedSets()))->toBe(1)
            ->and($table->getRanks())->toBe([1, 1, 3]);
    });

    it('are those level on the total, with the adjustments and the byes in it', function (): void {
        // A has two wins and B three, one of them over A: 6 and 9
        $results = [
            tiedResult($this->b, $this->a),
            tiedResult($this->a, $this->c),
            tiedResult($this->a, $this->c),
            tiedResult($this->b, $this->c),
            tiedResult($this->b, $this->c),
        ];
        $calculator = new StandingsCalculator(tiebreakers: [new BetweenTiedParticipants(new WinsTiebreaker())]);

        $unadjusted = $calculator->calculate($this->field, $results);
        $adjusted = $calculator->calculateWith($this->field, $results, [], [new Adjustment('b', -3.0)]);

        // As the results stand nobody is level with anybody. With three
        // points taken from B, both stand on 6.
        expect(tiedValues($unadjusted, 'between-tied:wins'))->toBe(['b' => 0.0, 'a' => 0.0, 'c' => 0.0])
            ->and(tiedValues($adjusted, 'between-tied:wins'))->toBe(['b' => 1.0, 'a' => 0.0, 'c' => 0.0])
            ->and($adjusted->getEntryFor($this->a)?->getRankingValue())->toBe($adjusted->getEntryFor($this->b)?->getRankingValue());

        // A bye levels two participants in the same way: B has 9 and A has 6
        // and a bye worth 3
        $withBye = $calculator->calculateWith($this->field, $results, ['a' => 1]);
        expect(tiedValues($withBye, 'between-tied:wins'))->toBe(['b' => 1.0, 'a' => 0.0, 'c' => 0.0]);
    });
});

describe('three participants level on the measure among themselves', function (): void {
    beforeEach(function (): void {
        $this->a = new Participant('a', 'A', 3);
        $this->b = new Participant('b', 'B', 1);
        $this->c = new Participant('c', 'C', 2);
        $this->d = new Participant('d', 'D');

        // A beat B, B beat C, C beat A, and each of the three beat D, all
        // by the same score
        $this->results = [
            tiedResult($this->a, $this->b),
            tiedResult($this->b, $this->c),
            tiedResult($this->c, $this->a),
            tiedResult($this->a, $this->d),
            tiedResult($this->b, $this->d),
            tiedResult($this->c, $this->d),
        ];
    });

    it('stay one tied set, the same for every order of the participants and the results', function (): void {
        $calculator = new StandingsCalculator(tiebreakers: [
            new BetweenTiedParticipants(new RankingValueTiebreaker()),
            new BetweenTiedParticipants(new WinsTiebreaker()),
        ]);
        $rng = new Randomizer(new Xoshiro256StarStar(58));

        $tables = [];
        for ($order = 0; $order < 12; ++$order) {
            $table = $calculator->calculate(
                $rng->shuffleArray([$this->a, $this->b, $this->c, $this->d]),
                $rng->shuffleArray($this->results)
            );

            $tables[] = [
                tiedValues($table, 'between-tied:ranking-value'),
                tiedValues($table, 'between-tied:wins'),
                $table->getRanks(),
            ];
        }

        // Each took 3 from the other two. Nothing separates them, and the
        // fallback (the seed) orders them: B, C, A.
        expect($tables[0])->toBe([
            ['b' => 3.0, 'c' => 3.0, 'a' => 3.0, 'd' => 0.0],
            ['b' => 1.0, 'c' => 1.0, 'a' => 1.0, 'd' => 0.0],
            [1, 1, 1, 4],
        ])->and(array_unique($tables, SORT_REGULAR))->toHaveCount(1);
    });

    it('are split by a measure over the three events among them, and the two still level go on to the next tiebreaker', function (): void {
        // The same six events with other scores
        $results = [
            tiedResult($this->a, $this->b, 3, 0),
            tiedResult($this->b, $this->c, 2, 0),
            tiedResult($this->c, $this->a, 1, 0),
            tiedResult($this->a, $this->d, 1, 0),
            tiedResult($this->b, $this->d, 1, 0),
            tiedResult($this->c, $this->d, 9, 0),
        ];

        $table = (new StandingsCalculator(tiebreakers: [
            new BetweenTiedParticipants(new RankingValueTiebreaker()),
            new BetweenTiedParticipants(new ScoreDifferenceTiebreaker()),
            new ScoreDifferenceTiebreaker(),
        ]))->calculate([$this->d, $this->c, $this->b, $this->a], array_reverse($results));

        // Among the three: A +3 -1, B -3 +2, C -2 +1. Overall C has +8.
        expect(tiedValues($table, 'between-tied:score-difference'))->toBe(['a' => 2.0, 'c' => -1.0, 'b' => -1.0, 'd' => 0.0])
            ->and(tiedValues($table, 'score-difference'))->toBe(['a' => 3.0, 'c' => 8.0, 'b' => 0.0, 'd' => -11.0])
            ->and($table->getTiedSets())->toBe([]);
    });
});

describe('a tied set that a measure splits into subsets', function (): void {
    beforeEach(function (): void {
        $this->a = new Participant('a', 'A');
        $this->b = new Participant('b', 'B');
        $this->c = new Participant('c', 'C');
        $this->d = new Participant('d', 'D');
        $this->e = new Participant('e', 'E');
        $this->field = [$this->a, $this->b, $this->c, $this->d, $this->e];

        // A, B, C and D all finish on 7; E has 4.
        // Among the four: A beat B and C, B beat C and D, C beat D.
        // Against E: A and B drew; C won and drew; D won twice and drew.
        $this->results = [
            tiedResult($this->a, $this->b),
            tiedResult($this->a, $this->c),
            tiedResult($this->b, $this->c),
            tiedResult($this->b, $this->d),
            tiedResult($this->c, $this->d),
            tiedResult($this->a, $this->e, 0, 0),
            tiedResult($this->b, $this->e, 0, 0),
            tiedResult($this->c, $this->e),
            tiedResult($this->c, $this->e, 0, 0),
            tiedResult($this->d, $this->e),
            tiedResult($this->d, $this->e),
            tiedResult($this->d, $this->e, 0, 0),
        ];
    });

    it('asks the next tiebreaker about each subset on its own', function (): void {
        $table = (new StandingsCalculator(tiebreakers: [
            new BetweenTiedParticipants(new RankingValueTiebreaker()),
            new BetweenTiedParticipants(new WinsTiebreaker()),
        ]))->calculate($this->field, $this->results);

        $totals = [];
        foreach ($table->getEntries() as $entry) {
            $totals[$entry->getParticipant()->getId()] = $entry->getRankingValue();
        }

        // The first measure, over the events among the four: 6, 6, 3, 0.
        // It leaves A and B level, and C and D each alone.
        expect($totals)->toBe(['a' => 7.0, 'b' => 7.0, 'c' => 7.0, 'd' => 7.0, 'e' => 4.0])
            ->and(tiedValues($table, 'between-tied:ranking-value'))->toBe(['a' => 6.0, 'b' => 6.0, 'c' => 3.0, 'd' => 0.0, 'e' => 0.0])
            // The second is asked about A and B alone, who met once: A won.
            // Among the four B has two wins, as A has; those are not read.
            ->and(tiedValues($table, 'between-tied:wins'))->toBe(['a' => 1.0, 'b' => 0.0, 'c' => 0.0, 'd' => 0.0, 'e' => 0.0])
            ->and($table->getTiedSets())->toBe([]);
    });

    it('gives the same values for every order of the participants and the results', function (): void {
        $calculator = new StandingsCalculator(tiebreakers: [
            new BetweenTiedParticipants(new RankingValueTiebreaker()),
            new BetweenTiedParticipants(new WinsTiebreaker()),
            new BetweenTiedParticipants(new ScoreDifferenceTiebreaker()),
        ]);
        $rng = new Randomizer(new Xoshiro256StarStar(2026));
        $expected = $calculator->calculate($this->field, $this->results);

        for ($order = 0; $order < 20; ++$order) {
            $table = $calculator->calculate($rng->shuffleArray($this->field), $rng->shuffleArray($this->results));

            expect($table)->toEqual($expected, "order {$order}");
        }
    });

    it('does not ask the same tiebreaker again about a subset it leaves level', function (): void {
        // One measure only. It splits the four into A and B, C, and D. A and
        // B keep the value they have over the four: the measure is not taken
        // again over the one event between the two, which A won. What
        // orders them is the next figure, the score difference of the table.
        $table = (new StandingsCalculator(tiebreakers: [new BetweenTiedParticipants(new RankingValueTiebreaker())]))
            ->calculate($this->field, $this->results);

        expect(tiedValues($table, 'between-tied:ranking-value'))->toBe(['a' => 6.0, 'b' => 6.0, 'c' => 3.0, 'd' => 0.0, 'e' => 0.0])
            ->and($table->getEntryFor($this->a)?->getScoreDifference())->toBe(2.0)
            ->and($table->getEntryFor($this->b)?->getScoreDifference())->toBe(1.0)
            ->and($table->getRanks())->toBe([1, 2, 3, 4, 5]);
    });
});
