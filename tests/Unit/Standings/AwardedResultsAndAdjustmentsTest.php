<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Award;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidInputException;
use MissionGaming\Tactician\Stage\StageOutcome;
use MissionGaming\Tactician\Standings\Adjustment;
use MissionGaming\Tactician\Standings\AwardedResultPolicy;
use MissionGaming\Tactician\Standings\BuchholzTiebreaker;
use MissionGaming\Tactician\Standings\RankingStrategy;
use MissionGaming\Tactician\Standings\SonnebornBergerTiebreaker;
use MissionGaming\Tactician\Standings\StandingEntry;
use MissionGaming\Tactician\Standings\Standings;
use MissionGaming\Tactician\Standings\StandingsCalculator;
use MissionGaming\Tactician\Standings\TiedParticipantsTiebreaker;
use MissionGaming\Tactician\Tests\Support\WinsBetweenTheTied;

/**
 * The line of a participant, which the table must have.
 */
function awardedTestEntry(Standings $standings, Participant $participant): StandingEntry
{
    $entry = $standings->getEntryFor($participant);
    assert($entry instanceof StandingEntry);

    return $entry;
}

/**
 * The IDs of a table, in its order.
 *
 * @return list<string>
 */
function awardedTestOrder(Standings $standings): array
{
    return array_values(array_map(
        static fn(StandingEntry $entry): string => $entry->getParticipant()->getId(),
        $standings->getEntries()
    ));
}

describe('Awarded results in a standings table', function (): void {
    beforeEach(function (): void {
        $this->p1 = new Participant('p1', 'Ada');
        $this->p2 = new Participant('p2', 'Bao');
        $this->p3 = new Participant('p3', 'Cyd');
        $this->p4 = new Participant('p4', 'Dov');
        $this->league = [$this->p1, $this->p2, $this->p3, $this->p4];

        // Five of the six events of a league of four were played
        $this->played = [
            new Result(new Event([$this->p1, $this->p2]), $this->p1, ['p1' => 2, 'p2' => 1]),
            new Result(new Event([$this->p1, $this->p3]), null, ['p1' => 1, 'p3' => 1]),
            new Result(new Event([$this->p1, $this->p4]), $this->p4, ['p1' => 0, 'p4' => 2]),
            new Result(new Event([$this->p2, $this->p3]), $this->p3, ['p2' => 0, 'p3' => 1]),
            new Result(new Event([$this->p2, $this->p4]), null, ['p2' => 2, 'p4' => 2]),
        ];
        // The sixth, p3 v p4, never took place
        $this->unplayed = new Event([$this->p3, $this->p4]);
    });

    it('gives the participant it names one more win and the points of a win', function (): void {
        $calculator = new StandingsCalculator();
        $before = awardedTestEntry($calculator->calculate($this->league, $this->played), $this->p3);

        $awarded = new Result($this->unplayed, $this->p3, award: new Award('no-show'));
        $after = awardedTestEntry($calculator->calculate($this->league, [...$this->played, $awarded]), $this->p3);

        expect([$before->getWins(), $before->getRankingValue()])->toBe([1, 4.0])
            ->and([$after->getWins(), $after->getRankingValue()])->toBe([2, 7.0])
            ->and($after->getDraws())->toBe($before->getDraws())
            ->and($after->getLosses())->toBe($before->getLosses());
    });

    it('counts against the other participant of the event as a loss', function (): void {
        $calculator = new StandingsCalculator();
        $before = awardedTestEntry($calculator->calculate($this->league, $this->played), $this->p4);

        $awarded = new Result($this->unplayed, $this->p3, award: new Award());
        $after = awardedTestEntry($calculator->calculate($this->league, [...$this->played, $awarded]), $this->p4);

        expect($after->getLosses())->toBe($before->getLosses() + 1)
            ->and($after->getWins())->toBe($before->getWins())
            ->and($after->getRankingValue())->toBe($before->getRankingValue());
    });

    it('counts as a draw for every participant of the event when it names nobody', function (): void {
        $calculator = new StandingsCalculator();
        $awarded = new Result($this->unplayed, award: new Award());

        $table = $calculator->calculate($this->league, [...$this->played, $awarded]);

        // p3 had a win and a draw, p4 a win and a draw: each has a draw more
        expect(awardedTestEntry($table, $this->p3)->getDraws())->toBe(2)
            ->and(awardedTestEntry($table, $this->p3)->getRankingValue())->toBe(5.0)
            ->and(awardedTestEntry($table, $this->p4)->getDraws())->toBe(2)
            ->and(awardedTestEntry($table, $this->p4)->getRankingValue())->toBe(5.0);
    });

    it('leaves the score difference alone without a score, and moves it by three with a nominal 3-0', function (): void {
        $before = awardedTestEntry(new StandingsCalculator()->calculate($this->league, $this->played), $this->p3);
        expect($before->getScoreDifference())->toBe(1.0);

        $nominal = new Result($this->unplayed, $this->p3, ['p3' => 3, 'p4' => 0], award: new Award());

        // "No score": the calculator is told that an awarded result carries none
        $noScore = awardedTestEntry(
            new StandingsCalculator(awardedResults: new AwardedResultPolicy(countScores: false))
                ->calculate($this->league, [...$this->played, $nominal]),
            $this->p3
        );
        // The nominal score counts
        $withScore = awardedTestEntry(
            new StandingsCalculator(awardedResults: new AwardedResultPolicy(countScores: true))
                ->calculate($this->league, [...$this->played, $nominal]),
            $this->p3
        );

        expect($noScore->getScoreDifference())->toBe(1.0)
            ->and($noScore->getScoreFor())->toBe($before->getScoreFor())
            ->and($noScore->getScoreAgainst())->toBe($before->getScoreAgainst())
            ->and($noScore->getWins())->toBe(2)
            ->and($withScore->getScoreDifference())->toBe(4.0)
            ->and($withScore->getScoreFor())->toBe($before->getScoreFor() + 3.0)
            ->and($withScore->getScoreAgainst())->toBe($before->getScoreAgainst())
            ->and($withScore->getWins())->toBe(2);
    });

    it('moves the score figures of the other participant the same way', function (): void {
        $before = awardedTestEntry(new StandingsCalculator()->calculate($this->league, $this->played), $this->p4);
        $nominal = new Result($this->unplayed, $this->p3, ['p3' => 3, 'p4' => 0], award: new Award());

        $noScore = awardedTestEntry(
            new StandingsCalculator(awardedResults: new AwardedResultPolicy(countScores: false))
                ->calculate($this->league, [...$this->played, $nominal]),
            $this->p4
        );
        $withScore = awardedTestEntry(
            new StandingsCalculator()->calculate($this->league, [...$this->played, $nominal]),
            $this->p4
        );

        expect($noScore->getScoreAgainst())->toBe($before->getScoreAgainst())
            ->and($withScore->getScoreAgainst())->toBe($before->getScoreAgainst() + 3.0)
            ->and($withScore->getScoreDifference())->toBe($before->getScoreDifference() - 3.0);
    });

    it('counts the scores of an awarded result by default', function (): void {
        $nominal = new Result($this->unplayed, $this->p3, ['p3' => 3, 'p4' => 0], award: new Award());

        $default = new StandingsCalculator()->calculate($this->league, [...$this->played, $nominal]);
        $stated = new StandingsCalculator(awardedResults: new AwardedResultPolicy(true, true))
            ->calculate($this->league, [...$this->played, $nominal]);

        expect($default)->toEqual($stated)
            ->and(awardedTestEntry($default, $this->p3)->getScoreFor())->toBe(5.0);
    });

    it('never drops the scores of a played result, whatever the policy', function (): void {
        $off = new StandingsCalculator(awardedResults: new AwardedResultPolicy(false, false));

        expect($off->calculate($this->league, $this->played))
            ->toEqual(new StandingsCalculator()->calculate($this->league, $this->played));
    });

    it('is counted in played and reported as awarded, so the record adds up', function (): void {
        $awarded = new Result($this->unplayed, $this->p3, award: new Award());
        $table = new StandingsCalculator()->calculate($this->league, [...$this->played, $awarded]);

        $p3 = awardedTestEntry($table, $this->p3);
        $p1 = awardedTestEntry($table, $this->p1);

        expect([$p3->getPlayed(), $p3->getWins(), $p3->getDraws(), $p3->getLosses(), $p3->getAwarded()])->toBe([3, 2, 1, 0, 1])
            ->and(awardedTestEntry($table, $this->p4)->getAwarded())->toBe(1)
            ->and([$p1->getPlayed(), $p1->getAwarded()])->toBe([3, 0]);
    });

    it('is handed to the ranking strategy as recorded, mark and scores included', function (): void {
        // A strategy of the application's that scores an awarded result its own way
        $strategy = new class implements RankingStrategy {
            #[Override]
            public function rank(Participant $participant, array $results): float
            {
                $value = 0.0;
                foreach ($results as $result) {
                    $value += $result->isAwarded() ? 100.0 : (float) ($result->getScoreFor($participant) ?? 0);
                }

                return $value;
            }
        };
        $nominal = new Result($this->unplayed, $this->p3, ['p3' => 3, 'p4' => 0], award: new Award());

        $table = new StandingsCalculator($strategy, [], new AwardedResultPolicy(countScores: false))
            ->calculate($this->league, [...$this->played, $nominal]);

        // p3 scored 1 and 1 in play, and has one awarded result
        expect(awardedTestEntry($table, $this->p3)->getRankingValue())->toBe(102.0);
    });

    it('counts in a tiebreaker over the opponents of the whole table under either choice', function (): void {
        $awarded = new Result($this->unplayed, $this->p3, award: new Award());
        $results = [...$this->played, $awarded];
        $tiebreakers = [new BuchholzTiebreaker(), new SonnebornBergerTiebreaker()];

        $on = new StandingsCalculator(tiebreakers: $tiebreakers)->calculate($this->league, $results);
        $off = new StandingsCalculator(tiebreakers: $tiebreakers, awardedResults: new AwardedResultPolicy(false, false))
            ->calculate($this->league, $results);

        // p3 met p1 (4), p2 (1) and, by the award, p4 (4): it beat p2 and p4 and drew with p1
        expect(awardedTestEntry($on, $this->p3)->getTiebreakerValue('buchholz'))->toBe(9.0)
            ->and(awardedTestEntry($on, $this->p3)->getTiebreakerValue('sonneborn-berger'))->toBe(7.0)
            ->and(awardedTestEntry($off, $this->p3)->getTiebreakers())->toBe(awardedTestEntry($on, $this->p3)->getTiebreakers());
    });

    it('refuses an awarded result beside a played result of the same event', function (): void {
        $played = new Result($this->unplayed, $this->p4, ['p3' => 0, 'p4' => 1]);
        $awarded = new Result($this->unplayed, $this->p3, award: new Award());

        new StandingsCalculator()->calculate($this->league, [...$this->played, $played, $awarded]);
    })->throws(InvalidInputException::class, 'Two results reference the same event');

    // The rule that holds after storage: two Event objects with the same
    // round and participants are one event, whichever result is awarded
    it('refuses an awarded result beside a played result of an equal event, in either order', function (bool $awardedFirst): void {
        $played = new Result(new Event([$this->p3, $this->p4], new Round(3)), $this->p4, ['p3' => 0, 'p4' => 1]);
        $awarded = new Result(new Event([$this->p4, $this->p3], new Round(3)), $this->p3, award: new Award());

        new StandingsCalculator()->calculate($this->league, $awardedFirst ? [$awarded, $played] : [$played, $awarded]);
    })->with([[true], [false]])->throws(InvalidInputException::class, 'Two results reference the same event; each event can have only one result (round 3');

    it('counts an awarded leg beside a played leg of one two-legged tie as two events', function (): void {
        $played = new Result(new Event([$this->p3, $this->p4], new Round(3), ['tie_leg' => 1]), $this->p4);
        $awarded = new Result(new Event([$this->p4, $this->p3], new Round(3), ['tie_leg' => 2]), $this->p3, award: new Award());

        $table = new StandingsCalculator()->calculate([$this->p3, $this->p4], [$played, $awarded]);

        expect([awardedTestEntry($table, $this->p3)->getPlayed(), awardedTestEntry($table, $this->p3)->getAwarded()])->toBe([2, 1])
            ->and(awardedTestEntry($table, $this->p3)->getRankingValue())->toBe(3.0);
    });
});

describe('Awarded results between the tied participants', function (): void {
    beforeEach(function (): void {
        // Seeds put Bao above Ada where nothing else decides
        $this->ada = new Participant('ada', 'Ada', 2);
        $this->bao = new Participant('bao', 'Bao', 1);
        $this->cyd = new Participant('cyd', 'Cyd', 3);
        $this->field = [$this->ada, $this->bao, $this->cyd];

        // Ada and Bao finish on one win each: Ada's is the event with Bao,
        // which was awarded to her, and Bao's is over Cyd
        $this->results = [
            new Result(new Event([$this->ada, $this->bao]), $this->ada, award: new Award('no-show')),
            new Result(new Event([$this->bao, $this->cyd]), $this->bao),
        ];
    });

    it('counts there by default, so the awarded win orders the two', function (): void {
        $table = new StandingsCalculator(tiebreakers: [new WinsBetweenTheTied()])
            ->calculate($this->field, $this->results);

        expect(awardedTestOrder($table))->toBe(['ada', 'bao', 'cyd'])
            ->and(awardedTestEntry($table, $this->ada)->getTiebreakerValue('wins-between-the-tied'))->toBe(1.0)
            ->and($table->getTiedSets())->toBe([]);
    });

    it('is ignored there with the exclusion on, and still counts in the table', function (): void {
        $table = new StandingsCalculator(
            tiebreakers: [new WinsBetweenTheTied()],
            awardedResults: new AwardedResultPolicy(countBetweenTiedParticipants: false)
        )->calculate($this->field, $this->results);

        $ada = awardedTestEntry($table, $this->ada);

        // The tiebreaker saw no result between the two, so nothing orders
        // them and the fallback (the seed) does
        expect($ada->getTiebreakerValue('wins-between-the-tied'))->toBe(0.0)
            ->and(awardedTestOrder($table))->toBe(['bao', 'ada', 'cyd'])
            ->and(count($table->getTiedSets()))->toBe(1)
            // The table has the awarded result all the same
            ->and([$ada->getPlayed(), $ada->getWins(), $ada->getRankingValue(), $ada->getAwarded()])->toBe([1, 1, 3.0, 1])
            ->and(awardedTestEntry($table, $this->bao)->getLosses())->toBe(1);
    });

    // Ada and Bao are level on 3 and Cyd is alone on 0. The one event
    // between Ada and Bao is the awarded one, so the tiebreaker is handed it
    // or nothing, and the entries of the two of them from just that.
    it('hands such a tiebreaker the results between the tied set and the entries they give', function (bool $counted, array $expected): void {
        $seen = new ArrayObject();
        $spy = new readonly class ($seen) implements TiedParticipantsTiebreaker {
            /**
             * @param ArrayObject<int, array{results: int, entries: list<int|string>, wins: int}> $seen
             */
            public function __construct(private ArrayObject $seen) {}

            #[Override]
            public function getName(): string
            {
                return 'spy';
            }

            #[Override]
            public function calculate(Participant $participant, array $results, array $entries): float
            {
                if ($participant->getId() === 'ada') {
                    $this->seen->append([
                        'results' => count($results),
                        'entries' => array_keys($entries),
                        'wins' => $entries['ada']->getWins(),
                    ]);
                }

                return 0.0;
            }
        };

        new StandingsCalculator(
            tiebreakers: [$spy],
            awardedResults: new AwardedResultPolicy(countBetweenTiedParticipants: $counted)
        )->calculate($this->field, $this->results);

        expect($seen->getArrayCopy())->toBe([$expected]);
    })->with([
        'awarded results counted' => [true, ['results' => 1, 'entries' => ['ada', 'bao'], 'wins' => 1]],
        'awarded results left out' => [false, ['results' => 0, 'entries' => ['ada', 'bao'], 'wins' => 0]],
    ]);

    it('leaves every other tiebreaker with all the results when the exclusion is on', function (): void {
        $table = new StandingsCalculator(
            tiebreakers: [new BuchholzTiebreaker()],
            awardedResults: new AwardedResultPolicy(countBetweenTiedParticipants: false)
        )->calculate($this->field, $this->results);

        // Ada's one opponent is Bao, met by the awarded result: 3 points
        expect(awardedTestEntry($table, $this->ada)->getTiebreakerValue('buchholz'))->toBe(3.0);
    });
});

describe('Adjustments in a standings table', function (): void {
    beforeEach(function (): void {
        $this->p1 = new Participant('p1', 'Ada');
        $this->p2 = new Participant('p2', 'Bao');
        $this->p3 = new Participant('p3', 'Cyd');
        $this->p4 = new Participant('p4', 'Dov');
        $this->p5 = new Participant('p5', 'Eli');
        $this->field = [$this->p1, $this->p2, $this->p3, $this->p4, $this->p5];

        // p1 wins three events (9). p2 wins two and draws one (7).
        $this->results = [
            new Result(new Event([$this->p1, $this->p2]), $this->p1),
            new Result(new Event([$this->p1, $this->p3]), $this->p1),
            new Result(new Event([$this->p1, $this->p4]), $this->p1),
            new Result(new Event([$this->p2, $this->p3]), $this->p2),
            new Result(new Event([$this->p2, $this->p4]), $this->p2),
            new Result(new Event([$this->p2, $this->p5])),
        ];
        $this->calculator = new StandingsCalculator();
        $this->table = $this->calculator->calculate($this->field, $this->results);
    });

    it('shows 9 earned, -3 adjustment and 6 total, and orders the participant by 6', function (): void {
        expect(awardedTestOrder($this->table))->toBe(['p1', 'p2', 'p5', 'p3', 'p4']);

        $adjusted = $this->calculator->withAdjustments($this->table, [new Adjustment('p1', -3.0, 'ruling')]);
        $p1 = awardedTestEntry($adjusted, $this->p1);

        expect($p1->getEarnedRankingValue())->toBe(9.0)
            ->and($p1->getAdjustment())->toBe(-3.0)
            ->and($p1->getRankingValue())->toBe(6.0)
            // p2 has 7, so p1 is second on its 6
            ->and(awardedTestOrder($adjusted))->toBe(['p2', 'p1', 'p5', 'p3', 'p4'])
            ->and($adjusted->getPosition($this->p1))->toBe(2);
    });

    it('changes no other figure of the entry, and no other entry', function (): void {
        $adjusted = $this->calculator->withAdjustments($this->table, [new Adjustment('p1', -3.0)]);
        $before = awardedTestEntry($this->table, $this->p1);
        $after = awardedTestEntry($adjusted, $this->p1);

        expect([$after->getPlayed(), $after->getWins(), $after->getDraws(), $after->getLosses()])
            ->toBe([$before->getPlayed(), $before->getWins(), $before->getDraws(), $before->getLosses()])
            ->and($after->getScoreFor())->toBe($before->getScoreFor())
            ->and($after->getByes())->toBe(0)
            ->and(awardedTestEntry($adjusted, $this->p2))->toEqual(awardedTestEntry($this->table, $this->p2))
            ->and(awardedTestEntry($adjusted, $this->p2)->getAdjustment())->toBe(0.0);
    });

    it('adds every adjustment of a participant, in whatever order they are given', function (): void {
        $adjustments = [
            new Adjustment('p1', -3.0, 'ruling'),
            new Adjustment('p3', 2.0, 'bonus'),
            new Adjustment('p1', 1.0, 'appeal upheld in part'),
        ];

        $forward = $this->calculator->withAdjustments($this->table, $adjustments);
        $backward = $this->calculator->withAdjustments($this->table, array_reverse($adjustments));

        expect(awardedTestEntry($forward, $this->p1)->getAdjustment())->toBe(-2.0)
            ->and(awardedTestEntry($forward, $this->p1)->getRankingValue())->toBe(7.0)
            ->and(awardedTestEntry($forward, $this->p3)->getRankingValue())->toBe(2.0)
            ->and($backward)->toEqual($forward);
    });

    it('adds amounts that floats cannot hold exactly to the same figure in every order', function (): void {
        $amounts = [0.1, 0.2, 0.3, -0.7, 1e-9, 0.6];
        $figures = [];
        foreach ([$amounts, array_reverse($amounts), [0.3, 1e-9, 0.6, 0.1, -0.7, 0.2]] as $order) {
            $adjusted = $this->calculator->withAdjustments(
                $this->table,
                array_map(static fn(float $amount): Adjustment => new Adjustment('p4', $amount), $order)
            );
            $figures[] = awardedTestEntry($adjusted, $this->p4)->getAdjustment();
        }

        expect($figures[1])->toBe($figures[0])
            ->and($figures[2])->toBe($figures[0]);
    });

    it('leaves nothing of adjustments that were each taken back, so the entry is level again', function (array $amounts): void {
        // p3 and p4 are level on their results. p4 is given 0.1 and 0.2,
        // and each is taken back with the opposite amount. A running float
        // total of these four is -2.8e-17, in whatever order they are added.
        expect($this->table->getTiedSets())->toHaveCount(1);

        $adjusted = $this->calculator->withAdjustments(
            $this->table,
            array_map(static fn(float $amount): Adjustment => new Adjustment('p4', $amount), $amounts)
        );

        expect(awardedTestEntry($adjusted, $this->p4)->getAdjustment())->toBe(0.0)
            ->and(awardedTestEntry($adjusted, $this->p4)->getRankingValue())->toBe(0.0)
            ->and(awardedTestOrder($adjusted))->toBe(awardedTestOrder($this->table))
            ->and($adjusted->getTiedSets())->toHaveCount(1)
            ->and($adjusted->getTiedSets()[0]->getParticipants())->toBe([$this->p3, $this->p4]);
    })->with([
        'in the order recorded' => [[0.1, 0.2, -0.1, -0.2]],
        'smallest first' => [[-0.2, -0.1, 0.1, 0.2]],
        'the last given first taken back' => [[0.1, 0.2, -0.2, -0.1]],
    ]);

    it('gives the float nearest to the exact sum of the amounts', function (array $amounts, float $sum): void {
        $adjusted = $this->calculator->withAdjustments(
            $this->table,
            array_map(static fn(float $amount): Adjustment => new Adjustment('p4', $amount), $amounts)
        );

        expect(awardedTestEntry($adjusted, $this->p4)->getAdjustment())->toBe($sum);
    })->with([
        // Each sum is known without a float addition: the large amounts cancel
        'a small amount beside two large ones that cancel' => [[1e16, 1.0, -1e16], 1.0],
        'the same, the small amount last' => [[1e16, -1e16, 1.0], 1.0],
        'the smallest float there is, kept beside what cancels' => [[5e-324, 1e100, -1e100], 5e-324],
        'two amounts that only together reach the next float' => [[9007199254740992.0, 1.0, 1.0], 9007199254740994.0],
        'a zero written with a sign' => [[-0.0], 0.0],
        'amounts that cancel though their running total, smallest first, would not hold' => [[1.5e308, 1.5e308, -1.5e308], 1.5e308],
    ]);

    it('refuses amounts for one participant that add up to more than a float holds', function (array $amounts): void {
        expect(fn() => $this->calculator->withAdjustments(
            $this->table,
            array_map(static fn(float $amount): Adjustment => new Adjustment('p4', $amount), $amounts)
        ))->toThrow(InvalidInputException::class, 'The adjustments of participant p4 add up to more than a float holds');
    })->with([
        'above the largest float' => [[1.5e308, 1.5e308]],
        'below the lowest' => [[-1.5e308, 1.0, -1.5e308]],
    ]);

    it('replaces the adjustments a table carries, and returns a table without any as it is', function (): void {
        $adjusted = $this->calculator->withAdjustments($this->table, [new Adjustment('p1', -3.0)]);

        expect($this->calculator->withAdjustments($this->table, []))->toBe($this->table)
            ->and($this->calculator->withAdjustments($adjusted, [new Adjustment('p1', -3.0)]))->toBe($adjusted)
            ->and(awardedTestEntry($this->calculator->withAdjustments($adjusted, []), $this->p1)->getAdjustment())->toBe(0.0)
            ->and(awardedTestOrder($this->calculator->withAdjustments($adjusted, [])))->toBe(awardedTestOrder($this->table));
    });

    it('rejects an adjustment for a participant the table does not have', function (): void {
        $this->calculator->withAdjustments($this->table, [new Adjustment('p9', -1.0)]);
    })->throws(InvalidInputException::class, 'Adjustment references participant p9 who is not in the standings');

    it('leaves entries that are level after it in the order of the table given', function (): void {
        // p1 comes down to the 7 of p2: no result separates them now, and
        // p1 was above p2 in the table given
        $adjusted = $this->calculator->withAdjustments($this->table, [new Adjustment('p1', -2.0)]);

        expect(awardedTestOrder($adjusted))->toBe(['p1', 'p2', 'p5', 'p3', 'p4'])
            ->and($adjusted->getTiedSets()[0]->getFirstPosition())->toBe(1)
            ->and($adjusted->getTiedSets()[0]->getLastPosition())->toBe(2)
            // In a stage, the entrant list decides between them
            ->and(awardedTestOrder($adjusted->withTiedSetsOrderedBy([$this->p2, $this->p1])))->toBe(['p2', 'p1', 'p5', 'p3', 'p4']);
    });

    it('is an amount of the ranking value under a strategy that is not win-draw-loss', function (): void {
        // Fewest strokes first: the strategy turns the quantity round
        $strokes = new class implements RankingStrategy {
            #[Override]
            public function rank(Participant $participant, array $results): float
            {
                $taken = 0.0;
                foreach ($results as $result) {
                    $taken += (float) ($result->getScoreFor($participant) ?? 0);
                }

                return -$taken;
            }
        };
        $calculator = new StandingsCalculator($strokes);
        $round = new Result(new Event([$this->p1, $this->p2, $this->p3]), null, ['p1' => 68, 'p2' => 69, 'p3' => 71]);
        $table = $calculator->calculate([$this->p1, $this->p2, $this->p3], [$round]);
        expect(awardedTestOrder($table))->toBe(['p1', 'p2', 'p3']);

        // Two penalty strokes are two off the ranking value
        $adjusted = $calculator->withAdjustments($table, [new Adjustment('p1', -2.0, 'penalty')]);
        $p1 = awardedTestEntry($adjusted, $this->p1);

        expect([$p1->getEarnedRankingValue(), $p1->getAdjustment(), $p1->getRankingValue()])->toBe([-68.0, -2.0, -70.0])
            ->and(awardedTestOrder($adjusted))->toBe(['p2', 'p1', 'p3']);
    });

    it('leaves the tiebreaker values as the results gave them: an opponent counts for what it earned', function (): void {
        $calculator = new StandingsCalculator(tiebreakers: [new BuchholzTiebreaker(), new SonnebornBergerTiebreaker()]);
        $table = $calculator->calculate($this->field, $this->results);

        $adjusted = $calculator->withAdjustments($table, [new Adjustment('p1', -3.0)]);

        // p2 met p1 (9 earned), p3 (0), p4 (0) and p5 (1): p1 still counts 9
        expect(awardedTestEntry($adjusted, $this->p2)->getTiebreakerValue('buchholz'))->toBe(10.0)
            ->and(awardedTestEntry($adjusted, $this->p2)->getTiebreakers())
            ->toBe(awardedTestEntry($table, $this->p2)->getTiebreakers());
    });

    it('keeps an adjustment apart from the credit of a bye, in either order', function (): void {
        $adjustments = [new Adjustment('p5', -1.0)];
        $byes = ['p5' => 1];

        $byesFirst = $this->calculator->withAdjustments($this->calculator->withByesCredited($this->table, $byes), $adjustments);
        $adjustmentsFirst = $this->calculator->withByesCredited($this->calculator->withAdjustments($this->table, $adjustments), $byes);

        $p5 = awardedTestEntry($byesFirst, $this->p5);

        // A draw (1) and a bye credited as a win (3) are earned; 1 is taken off
        expect([$p5->getEarnedRankingValue(), $p5->getAdjustment(), $p5->getRankingValue(), $p5->getByes()])
            ->toBe([4.0, -1.0, 3.0, 1])
            ->and($adjustmentsFirst)->toEqual($byesFirst);
    });

    it('does not ask what a win is worth, so no ranking strategy is refused', function (): void {
        $custom = new class implements RankingStrategy {
            #[Override]
            public function rank(Participant $participant, array $results): float
            {
                return (float) count($results);
            }
        };
        $calculator = new StandingsCalculator($custom);
        $table = $calculator->calculate($this->field, $this->results);

        expect(fn() => $calculator->withByesCredited($table, ['p5' => 1]))->toThrow(InvalidConfigurationException::class)
            ->and(awardedTestEntry($calculator->withAdjustments($table, [new Adjustment('p5', 0.5)]), $this->p5)->getRankingValue())
            ->toBe(1.5);
    });

    it('orders an entry among the entries of its own tier only', function (): void {
        // A bracket's table: p3 won it, p4 lost the final
        $entry = static fn(Participant $participant, float $value): StandingEntry => new StandingEntry($participant, 0, 0, 0, 0, $value);
        $bracket = new Standings(
            [$entry($this->p3, 6.0), $entry($this->p4, 3.0), $entry($this->p1, 0.0), $entry($this->p2, 0.0)],
            [0, 1, 2, 2]
        );

        $adjusted = $this->calculator->withAdjustments($bracket, [
            new Adjustment('p3', -100.0),
            new Adjustment('p2', 1.0),
        ]);

        expect(awardedTestOrder($adjusted))->toBe(['p3', 'p4', 'p2', 'p1'])
            ->and($adjusted->getTiers())->toBe([0, 1, 2, 2]);
    });

    it('is level with another entry on the total, not on what was earned', function (): void {
        $adjusted = $this->calculator->withAdjustments($this->table, [new Adjustment('p1', -2.0)]);

        expect(awardedTestEntry($adjusted, $this->p1)->isLevelWith(awardedTestEntry($adjusted, $this->p2)))->toBeTrue()
            ->and(awardedTestEntry($this->table, $this->p1)->isLevelWith(awardedTestEntry($this->table, $this->p2)))->toBeFalse();
    });
});

describe('Adjustments in a pooled outcome', function (): void {
    it('keeps the adjustment of a pool entry in the combined table', function (): void {
        $a1 = new Participant('a1', 'Ash');
        $a2 = new Participant('a2', 'Bryn');
        $b1 = new Participant('b1', 'Cato');
        $b2 = new Participant('b2', 'Dru');
        $calculator = new StandingsCalculator();

        $poolAResults = [new Result(new Event([$a1, $a2], new Round(1)), $a1)];
        $poolBResults = [new Result(new Event([$b1, $b2], new Round(1)), $b1)];
        $poolA = new StageOutcome(
            $calculator->withAdjustments($calculator->calculate([$a1, $a2], $poolAResults), [new Adjustment('a1', -2.0)]),
            $poolAResults
        );
        $poolB = new StageOutcome($calculator->calculate([$b1, $b2], $poolBResults), $poolBResults);

        $combined = StageOutcome::combining(['A' => $poolA, 'B' => $poolB], $calculator)->getStandings();
        $ash = awardedTestEntry($combined, $a1);

        expect([$ash->getEarnedRankingValue(), $ash->getAdjustment(), $ash->getRankingValue()])->toBe([3.0, -2.0, 1.0])
            ->and(awardedTestOrder($combined))->toBe(['b1', 'a1', 'a2', 'b2'])
            ->and(awardedTestEntry($combined, $b1)->getAdjustment())->toBe(0.0);
    });
});

describe('StandingEntry figures beside the record', function (): void {
    it('has no awarded result and no adjustment unless it is given them', function (): void {
        $entry = new StandingEntry(new Participant('p1', 'Ada'), 2, 1, 1, 0, 4.0);

        expect($entry->getAwarded())->toBe(0)
            ->and($entry->getAdjustment())->toBe(0.0)
            ->and($entry->getEarnedRankingValue())->toBe(4.0)
            ->and($entry->getRankingValue())->toBe(4.0);
    });

    it('keeps the awarded count and the adjustment through its copies', function (): void {
        $entry = new StandingEntry(new Participant('p1', 'Ada'), 2, 1, 1, 0, 4.0, awarded: 1, adjustment: -1.5);

        $withTiebreakers = $entry->withTiebreakers(['wins' => 1.0]);
        $withByes = $entry->withByes(1, 7.0);
        $withAdjustment = $entry->withAdjustment(2.0);

        expect([$withTiebreakers->getAwarded(), $withTiebreakers->getAdjustment(), $withTiebreakers->getRankingValue()])->toBe([1, -1.5, 2.5])
            ->and([$withByes->getAwarded(), $withByes->getAdjustment(), $withByes->getEarnedRankingValue(), $withByes->getRankingValue()])
            ->toBe([1, -1.5, 7.0, 5.5])
            ->and([$withAdjustment->getAwarded(), $withAdjustment->getEarnedRankingValue(), $withAdjustment->getRankingValue()])
            ->toBe([1, 4.0, 6.0])
            ->and($entry->getAdjustment())->toBe(-1.5);
    });
});
