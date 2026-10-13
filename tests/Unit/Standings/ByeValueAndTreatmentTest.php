<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Standings\Adjustment;
use MissionGaming\Tactician\Standings\BetweenTiedParticipants;
use MissionGaming\Tactician\Standings\BuchholzTiebreaker;
use MissionGaming\Tactician\Standings\ByeTreatment;
use MissionGaming\Tactician\Standings\RankingStrategy;
use MissionGaming\Tactician\Standings\RankingStrategyWithByeValue;
use MissionGaming\Tactician\Standings\ScoreDifferenceTiebreaker;
use MissionGaming\Tactician\Standings\SonnebornBergerTiebreaker;
use MissionGaming\Tactician\Standings\StandingEntry;
use MissionGaming\Tactician\Standings\StandingsCalculator;
use MissionGaming\Tactician\Standings\TiebreakerInterface;
use MissionGaming\Tactician\Standings\TiebreakerWithByeTreatment;
use MissionGaming\Tactician\Standings\WinDrawLossRanking;
use MissionGaming\Tactician\Standings\WinsTiebreaker;

/**
 * The reason an InvalidConfigurationException is thrown with, or null when
 * the call goes through.
 *
 * @param Closure(): mixed $call
 */
function byeRefusal(Closure $call): ?InvalidConfigurationReason
{
    try {
        $call();
    } catch (InvalidConfigurationException $exception) {
        return $exception->getReason();
    }

    return null;
}

describe('the value of a bye on a win/draw/loss scale', function (): void {
    it('is the value of a win unless another is given', function (): void {
        expect((new WinDrawLossRanking())->getByeValue())->toBe(3.0)
            ->and(WinDrawLossRanking::oneHalfZero()->getByeValue())->toBe(1.0)
            ->and((new WinDrawLossRanking(2.0, 1.0, -1.0))->getByeValue())->toBe(2.0)
            ->and((new WinDrawLossRanking(3.0, 1.0, 0.0, 1.0))->getByeValue())->toBe(1.0)
            ->and(WinDrawLossRanking::threeOneZero()->withByeValue(0.0)->getByeValue())->toBe(0.0)
            ->and(WinDrawLossRanking::threeOneZero()->withByeValue(4.5)->getByeValue())->toBe(4.5);
    });

    it('leaves the three values and the ranking of results as they are', function (): void {
        $alice = new Participant('a', 'Alice');
        $bob = new Participant('b', 'Bob');
        $results = [new Result(new Event([$alice, $bob]), $alice), new Result(new Event([$bob, $alice]))];

        $ranking = WinDrawLossRanking::threeOneZero()->withByeValue(0.5);

        expect([$ranking->getWinValue(), $ranking->getDrawValue(), $ranking->getLossValue()])->toBe([3.0, 1.0, 0.0])
            ->and($ranking->rank($alice, $results))->toBe(4.0)
            ->and($ranking)->toBeInstanceOf(RankingStrategyWithByeValue::class);
    });

    it('is read from plain data as a number or as the value of an outcome', function (mixed $bye, float $expected): void {
        expect(WinDrawLossRanking::fromArray(['win' => 4, 'draw' => 1.5, 'loss' => -1, 'bye' => $bye])->getByeValue())->toBe($expected);
    })->with([
        'the value of a win' => ['win', 4.0],
        'the value of a draw' => ['draw', 1.5],
        'the value of a loss' => ['loss', -1.0],
        'zero' => [0, 0.0],
        'another amount' => [2.25, 2.25],
        'null, which is the key left out' => [null, 4.0],
    ]);

    it('is the value of a win when plain data does not state it', function (): void {
        expect(WinDrawLossRanking::fromArray(['win' => 2])->getByeValue())->toBe(2.0)
            ->and(WinDrawLossRanking::fromArray([])->getByeValue())->toBe(3.0)
            // The outcome named is read from the same array, defaults included
            ->and(WinDrawLossRanking::fromArray(['bye' => 'draw'])->getByeValue())->toBe(1.0);
    });

    it('is refused in plain data when it is no value', function (mixed $bye, InvalidConfigurationReason $reason): void {
        expect(byeRefusal(fn() => WinDrawLossRanking::fromArray(['win' => 3, 'bye' => $bye])))->toBe($reason);
    })->with([
        'an outcome that is not one' => ['won', InvalidConfigurationReason::UnknownIdentifier],
        'a numeric string' => ['1', InvalidConfigurationReason::UnknownIdentifier],
        'a boolean' => [true, InvalidConfigurationReason::WrongValueType],
        'a list' => [[1], InvalidConfigurationReason::WrongValueType],
        'infinity' => [INF, InvalidConfigurationReason::ValueOutOfRange],
        'not a number' => [NAN, InvalidConfigurationReason::ValueOutOfRange],
    ]);

    // A ranking stored before a bye had a value has the three keys it had,
    // and reads back as one that values a bye as a win
    it('writes the key only for a ranking that was given a value', function (): void {
        expect(WinDrawLossRanking::threeOneZero()->toArray())->toBe(['format_version' => 1, 'win' => 3.0, 'draw' => 1.0, 'loss' => 0.0])
            ->and(WinDrawLossRanking::threeOneZero()->withByeValue(1.0)->toArray())
            ->toBe(['format_version' => 1, 'win' => 3.0, 'draw' => 1.0, 'loss' => 0.0, 'bye' => 1.0])
            // Also when the value given is that of a win
            ->and((new WinDrawLossRanking(3.0, 1.0, 0.0, 3.0))->toArray()['bye'] ?? null)->toBe(3.0)
            ->and(WinDrawLossRanking::fromArray(['win' => 3.0, 'draw' => 1.0, 'loss' => 0.0]))->toEqual(new WinDrawLossRanking())
            ->and(WinDrawLossRanking::fromArray(WinDrawLossRanking::oneHalfZero()->withByeValue(0.0)->toArray()))
            ->toEqual(WinDrawLossRanking::oneHalfZero()->withByeValue(0.0));
    });
});

describe('a table calculated with its byes', function (): void {
    beforeEach(function (): void {
        $this->p = new Participant('p', 'P');
        $this->a = new Participant('a', 'A');
        $this->b = new Participant('b', 'B');
        $this->field = [$this->p, $this->a, $this->b];

        // P had a bye, beat A and lost to B. A beat B. B had a bye too.
        $this->results = [
            new Result(new Event([$this->p, $this->a]), $this->p),
            new Result(new Event([$this->b, $this->p]), $this->b),
            new Result(new Event([$this->a, $this->b]), $this->a),
        ];
        $this->byes = ['p' => 1, 'b' => 1];
    });

    it('counts a bye as a figure of its own and credits it with the value the ranking gives it', function (?float $byeValue, array $expected): void {
        $ranking = $byeValue === null ? new WinDrawLossRanking() : (new WinDrawLossRanking())->withByeValue($byeValue);
        $entry = (new StandingsCalculator($ranking))->calculateWith($this->field, $this->results, $this->byes)->getEntryFor($this->p);
        assert($entry instanceof StandingEntry);

        expect([
            $entry->getPlayed(), $entry->getWins(), $entry->getDraws(), $entry->getLosses(), $entry->getByes(),
            $entry->getRankingValueOfResults(), $entry->getEarnedRankingValue(), $entry->getRankingValue(),
        ])->toBe($expected);
    })->with([
        'as a win, the default' => [null, [2, 1, 0, 1, 1, 3.0, 6.0, 6.0]],
        'as a draw' => [1.0, [2, 1, 0, 1, 1, 3.0, 4.0, 4.0]],
        'as nothing: counted, and scoring nothing' => [0.0, [2, 1, 0, 1, 1, 3.0, 3.0, 3.0]],
        'as another amount' => [1.5, [2, 1, 0, 1, 1, 3.0, 4.5, 4.5]],
    ]);

    it('shows what the results are worth, what was earned and the total apart', function (): void {
        $entry = (new StandingsCalculator())
            ->calculateWith($this->field, $this->results, $this->byes, [new Adjustment('p', -2.0)], creditsPerBye: 2)
            ->getEntryFor($this->p);

        // 3 for the win, 2 x 3 for the bye of a tie of two legs, 2 taken away
        expect([$entry?->getRankingValueOfResults(), $entry?->getEarnedRankingValue(), $entry?->getAdjustment(), $entry?->getRankingValue()])
            ->toBe([3.0, 9.0, -2.0, 7.0])
            ->and($entry?->getByes())->toBe(1);
    });

    it('is the table of the results where there is no bye and no adjustment', function (): void {
        $calculator = new StandingsCalculator(tiebreakers: [new WinsTiebreaker(), new BuchholzTiebreaker()]);

        expect($calculator->calculateWith($this->field, $this->results))->toEqual($calculator->calculate($this->field, $this->results))
            // An ID the table does not have, and a count below 1, are no bye
            ->and($calculator->calculateWith($this->field, $this->results, ['nobody' => 2, 'p' => 0, 'a' => -1]))
            ->toEqual($calculator->calculate($this->field, $this->results));
    });

    it('keeps the value of the results when byes are credited to a table twice', function (): void {
        $calculator = new StandingsCalculator();
        $once = $calculator->withByesCredited($calculator->calculate($this->field, $this->results), ['p' => 1]);
        $twice = $calculator->withByesCredited($once, ['p' => 1]);

        expect($twice->getEntryFor($this->p)?->getRankingValueOfResults())->toBe(3.0)
            ->and($twice->getEntryFor($this->p)?->getEarnedRankingValue())->toBe(9.0)
            ->and($twice->getEntryFor($this->p)?->getByes())->toBe(2)
            // An entry built by hand without it has what it earned
            ->and((new StandingEntry($this->p, 1, 1, 0, 0, 3.0))->getRankingValueOfResults())->toBe(3.0);
    });

    it('credits a bye under a ranking strategy of the application that says what one is worth', function (): void {
        $strategy = new readonly class implements RankingStrategyWithByeValue {
            #[Override]
            public function rank(Participant $participant, array $results): float
            {
                return 10.0 * count($results);
            }

            #[Override]
            public function getByeValue(): float
            {
                return 7.0;
            }
        };

        $table = (new StandingsCalculator($strategy))->calculateWith($this->field, $this->results, $this->byes);

        expect($table->getEntryFor($this->p)?->getRankingValue())->toBe(27.0)
            ->and($table->getEntryFor($this->a)?->getRankingValue())->toBe(20.0);
    });

    // What is left of the refusal: nothing says what a bye is worth
    it('refuses a bye under a ranking strategy that does not say what one is worth, and only a bye', function (): void {
        $strategy = new readonly class implements RankingStrategy {
            #[Override]
            public function rank(Participant $participant, array $results): float
            {
                return 10.0 * count($results);
            }
        };
        $calculator = new StandingsCalculator($strategy);

        try {
            $calculator->calculateWith($this->field, $this->results, $this->byes);
            $this->fail('The bye was credited.');
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getReason())->toBe(InvalidConfigurationReason::IncompatibleOptions)
                ->and($exception->getMessage())->toContain('RankingStrategyWithByeValue');
        }

        expect(byeRefusal(fn() => $calculator->withByesCredited($calculator->calculate($this->field, $this->results), $this->byes)))
            ->toBe(InvalidConfigurationReason::IncompatibleOptions)
            // No bye, nothing to refuse: adjustments are defined on every scale
            ->and($calculator->calculateWith($this->field, $this->results, [], [new Adjustment('p', 1.0)])->getEntryFor($this->p)?->getRankingValue())
            ->toBe(21.0)
            ->and($calculator->calculateWith($this->field, $this->results, ['nobody' => 1])->getEntryFor($this->p)?->getRankingValue())
            ->toBe(20.0);
    });
});

describe('the bye treatment of a tiebreaker computed from opponents', function (): void {
    beforeEach(function (): void {
        $this->p = new Participant('p', 'P');
        $this->a = new Participant('a', 'A');
        $this->b = new Participant('b', 'B');
        $this->field = [$this->p, $this->a, $this->b];

        // The example of the two class docblocks: P had a bye, beat A and
        // lost to B. A beat B and has 3, all from results. B has 6, of
        // which 3 for a bye. P earned 6.
        $this->results = [
            new Result(new Event([$this->p, $this->a]), $this->p),
            new Result(new Event([$this->b, $this->p]), $this->b),
            new Result(new Event([$this->a, $this->b]), $this->a),
        ];
        $this->byes = ['p' => 1, 'b' => 1];
    });

    it('is uncounted by default, and states itself', function (): void {
        expect((new BuchholzTiebreaker())->getByeTreatment())->toBe(ByeTreatment::Uncounted)
            ->and((new SonnebornBergerTiebreaker())->getByeTreatment())->toBe(ByeTreatment::Uncounted)
            ->and((new BuchholzTiebreaker(ByeTreatment::Zero))->getByeTreatment())->toBe(ByeTreatment::Zero)
            ->and((new SonnebornBergerTiebreaker(ByeTreatment::OwnRankingValue))->getByeTreatment())->toBe(ByeTreatment::OwnRankingValue)
            // The name, and so the key of the value, is the same under each
            ->and((new BuchholzTiebreaker(ByeTreatment::OwnRankingValue))->getName())->toBe('buchholz')
            ->and((new SonnebornBergerTiebreaker(ByeTreatment::Zero))->getName())->toBe('sonneborn-berger')
            ->and(array_map(static fn(ByeTreatment $treatment): string => $treatment->value, ByeTreatment::cases()))
            ->toBe(['uncounted', 'zero', 'own-ranking-value']);
    });

    it('gives Buchholz the documented value for a participant with a bye under each treatment', function (
        ByeTreatment $treatment,
        float $ofP,
        float $ofA,
        float $ofB
    ): void {
        $table = (new StandingsCalculator(tiebreakers: [new BuchholzTiebreaker($treatment)]))
            ->calculateWith($this->field, $this->results, $this->byes);

        expect(array_map(
            static fn(Participant $participant): ?float => $table->getEntryFor($participant)?->getTiebreakerValue('buchholz'),
            $this->field
        ))->toBe([$ofP, $ofA, $ofB]);
    })->with([
        // P: A's results 3 + B's results 3. A: P's 3 + B's 3. B: P's 3 + A's 3.
        'uncounted' => [ByeTreatment::Uncounted, 6.0, 6.0, 6.0],
        // An opponent counts for what it earned: P 6, A 3, B 6
        'zero' => [ByeTreatment::Zero, 9.0, 12.0, 9.0],
        // And each bye adds the participant's own 6
        'own ranking value' => [ByeTreatment::OwnRankingValue, 15.0, 12.0, 15.0],
    ]);

    it('gives Sonneborn-Berger the documented value for a participant with a bye under each treatment', function (
        ByeTreatment $treatment,
        float $ofP,
        float $ofA,
        float $ofB
    ): void {
        $table = (new StandingsCalculator(tiebreakers: [new SonnebornBergerTiebreaker($treatment)]))
            ->calculateWith($this->field, $this->results, $this->byes);

        expect(array_map(
            static fn(Participant $participant): ?float => $table->getEntryFor($participant)?->getTiebreakerValue('sonneborn-berger'),
            $this->field
        ))->toBe([$ofP, $ofA, $ofB]);
    })->with([
        // Each beat one opponent, whose results are worth 3
        'uncounted' => [ByeTreatment::Uncounted, 3.0, 3.0, 3.0],
        // P beat A (3), A beat B (6), B beat P (6)
        'zero' => [ByeTreatment::Zero, 3.0, 6.0, 6.0],
        // The bye of P and of B counts as an opponent beaten, worth their own 6
        'own ranking value' => [ByeTreatment::OwnRankingValue, 9.0, 6.0, 12.0],
    ]);

    it('halves a drawn opponent and leaves out one lost to, under every treatment', function (ByeTreatment $treatment, float $expected): void {
        // P drew with A, who had a bye, and lost to B
        $results = [new Result(new Event([$this->p, $this->a])), new Result(new Event([$this->b, $this->p]), $this->b)];

        $table = (new StandingsCalculator(tiebreakers: [new SonnebornBergerTiebreaker($treatment)]))
            ->calculateWith($this->field, $results, ['a' => 1]);

        expect($table->getEntryFor($this->p)?->getTiebreakerValue('sonneborn-berger'))->toBe($expected);
    })->with([
        // Half of A's draw
        [ByeTreatment::Uncounted, 0.5],
        // Half of A's draw and bye: (1 + 3) / 2
        [ByeTreatment::Zero, 2.0],
        // P has no bye of its own to add
        [ByeTreatment::OwnRankingValue, 2.0],
    ]);

    it('reads no adjustment of an opponent, under any treatment', function (ByeTreatment $treatment): void {
        $calculator = new StandingsCalculator(tiebreakers: [new BuchholzTiebreaker($treatment), new SonnebornBergerTiebreaker($treatment)]);

        $adjusted = $calculator->calculateWith($this->field, $this->results, $this->byes, [new Adjustment('a', -3.0), new Adjustment('b', 5.0)]);
        $plain = $calculator->calculateWith($this->field, $this->results, $this->byes);

        foreach ($this->field as $participant) {
            expect($adjusted->getEntryFor($participant)?->getTiebreakers())->toBe($plain->getEntryFor($participant)?->getTiebreakers());
        }
    })->with(ByeTreatment::cases());

    it('counts a bye once, whatever a bye is worth and however many legs it stands for', function (): void {
        // A bye worth nothing over a tie of two legs: P's own earned value is
        // 3, and its one bye adds it once
        $table = (new StandingsCalculator(
            WinDrawLossRanking::threeOneZero()->withByeValue(0.0),
            [new BuchholzTiebreaker(ByeTreatment::OwnRankingValue)]
        ))->calculateWith($this->field, $this->results, $this->byes, [], 2);

        // A's 3 and B's 3, and 3 for the bye
        expect($table->getEntryFor($this->p)?->getTiebreakerValue('buchholz'))->toBe(9.0);
    });
});

describe('what the calculator hands a tiebreaker', function (): void {
    /**
     * A tiebreaker that records the entries it is handed for the first
     * participant asked about.
     *
     * @param ArrayObject<int, array<string, array{byes: int, earned: float, results: float, total: float}>> $seen
     */
    $spyOn = static function (ArrayObject $seen, bool $readsByes): TiebreakerInterface {
        $record = static function (array $entries) use ($seen): void {
            if (count($seen) > 0) {
                return;
            }
            $seen->append(array_map(static fn(StandingEntry $entry): array => [
                'byes' => $entry->getByes(),
                'earned' => $entry->getEarnedRankingValue(),
                'results' => $entry->getRankingValueOfResults(),
                'total' => $entry->getRankingValue(),
            ], $entries));
        };

        if (!$readsByes) {
            return new readonly class ($record) implements TiebreakerInterface {
                /**
                 * @param Closure(array<string, StandingEntry>): void $record
                 */
                public function __construct(private Closure $record) {}

                #[Override]
                public function getName(): string
                {
                    return 'spy';
                }

                #[Override]
                public function calculate(Participant $participant, array $results, array $entries): float
                {
                    ($this->record)($entries);

                    return 0.0;
                }
            };
        }

        return new readonly class ($record) implements TiebreakerWithByeTreatment {
            /**
             * @param Closure(array<string, StandingEntry>): void $record
             */
            public function __construct(private Closure $record) {}

            #[Override]
            public function getName(): string
            {
                return 'spy';
            }

            #[Override]
            public function getByeTreatment(): ByeTreatment
            {
                return ByeTreatment::Zero;
            }

            #[Override]
            public function calculate(Participant $participant, array $results, array $entries): float
            {
                ($this->record)($entries);

                return 0.0;
            }
        };
    };

    beforeEach(function (): void {
        $this->p = new Participant('p', 'P');
        $this->a = new Participant('a', 'A');
        $this->results = [new Result(new Event([$this->p, $this->a]), $this->p)];
    });

    it('hands a tiebreaker that reads byes the entries with the byes credited and no adjustment', function () use ($spyOn): void {
        $seen = new ArrayObject();
        (new StandingsCalculator(tiebreakers: [$spyOn($seen, true)]))
            ->calculateWith([$this->p, $this->a], $this->results, ['a' => 2], [new Adjustment('a', -1.0)]);

        expect($seen->getArrayCopy())->toBe([[
            'p' => ['byes' => 0, 'earned' => 3.0, 'results' => 3.0, 'total' => 3.0],
            'a' => ['byes' => 2, 'earned' => 6.0, 'results' => 0.0, 'total' => 6.0],
        ]]);
    });

    // As before a bye could be read: what a tiebreaker of the application
    // computed from the entries it was handed, it still computes
    it('hands every other tiebreaker the entries of the results alone', function () use ($spyOn): void {
        $seen = new ArrayObject();
        (new StandingsCalculator(tiebreakers: [$spyOn($seen, false)]))
            ->calculateWith([$this->p, $this->a], $this->results, ['a' => 2], [new Adjustment('a', -1.0)]);

        expect($seen->getArrayCopy())->toBe([[
            'p' => ['byes' => 0, 'earned' => 3.0, 'results' => 3.0, 'total' => 3.0],
            'a' => ['byes' => 0, 'earned' => 0.0, 'results' => 0.0, 'total' => 0.0],
        ]]);
    });
});

describe('byes and adjustments applied to a table that exists', function (): void {
    beforeEach(function (): void {
        $this->p = new Participant('p', 'P');
        $this->a = new Participant('a', 'A');
        $this->field = [$this->p, $this->a];
        $this->results = [new Result(new Event([$this->p, $this->a]), $this->p)];
    });

    it('are refused where a tiebreaker depends on them, and only when they change the table', function (
        TiebreakerInterface $tiebreaker,
        bool $byesRefused,
        bool $adjustmentsRefused
    ): void {
        $calculator = new StandingsCalculator(tiebreakers: [$tiebreaker]);
        $table = $calculator->calculate($this->field, $this->results);
        $refused = static fn(bool $is): ?InvalidConfigurationReason => $is ? InvalidConfigurationReason::IncompatibleOptions : null;

        expect(byeRefusal(fn() => $calculator->withByesCredited($table, ['a' => 1])))->toBe($refused($byesRefused))
            ->and(byeRefusal(fn() => $calculator->withAdjustments($table, [new Adjustment('a', 1.0)])))->toBe($refused($adjustmentsRefused))
            // Nothing to credit and nothing to adjust is nothing to refuse
            ->and($calculator->withByesCredited($table, []))->toBe($table)
            ->and($calculator->withByesCredited($table, ['nobody' => 1]))->toBe($table)
            ->and($calculator->withAdjustments($table, []))->toBe($table)
            // The one call takes both, whatever the tiebreaker
            ->and($calculator->calculateWith($this->field, $this->results, ['a' => 1], [new Adjustment('a', 1.0)])->getEntryFor($this->a)?->getRankingValue())
            ->toBe(4.0);
    })->with([
        'a tiebreaker that reads no bye' => [new WinsTiebreaker(), false, false],
        'Buchholz at its default' => [new BuchholzTiebreaker(), false, false],
        'Buchholz that counts byes' => [new BuchholzTiebreaker(ByeTreatment::Zero), true, false],
        'Sonneborn-Berger that counts byes' => [new SonnebornBergerTiebreaker(ByeTreatment::OwnRankingValue), true, false],
        'a tiebreaker between the tied participants' => [new BetweenTiedParticipants(new WinsTiebreaker()), true, true],
    ]);

    it('says which call takes them', function (): void {
        $calculator = new StandingsCalculator(tiebreakers: [new BetweenTiedParticipants(new WinsTiebreaker())]);
        $table = $calculator->calculate($this->field, $this->results);

        expect(fn() => $calculator->withByesCredited($table, ['a' => 1]))
            ->toThrow(InvalidConfigurationException::class, 'Give the byes to calculateWith()')
            ->and(fn() => $calculator->withAdjustments($table, [new Adjustment('a', 1.0)]))
            ->toThrow(InvalidConfigurationException::class, 'Give the adjustments to calculateWith()');
    });
});

describe('entries that no figure separates, in a table with byes and adjustments', function (): void {
    // The one call and the separate steps give one order, whichever
    // tiebreakers the calculator has: what was earned first, then what the
    // results are worth, and only then the fallback of a table (the seed).
    // Either way the entries are a tied set.
    it('are ordered by what they earned, then by what their results are worth, in the one call as in the steps', function (
        array $tiebreakers
    ): void {
        $won = new Participant('won', 'Won', 3);
        $satOut = new Participant('sat-out', 'Sat out', 2);
        $idle = new Participant('idle', 'Idle', 1);
        $beaten = new Participant('beaten', 'Beaten', 4);
        $field = [$idle, $satOut, $won, $beaten];

        // Won has a win without scores, Sat out a bye worth as much, and
        // both have that taken away again; Idle and Beaten have nothing
        $results = [new Result(new Event([$won, $beaten]), $won)];
        $byes = ['sat-out' => 1];
        $adjustments = [new Adjustment('won', -3.0), new Adjustment('sat-out', -3.0)];

        $calculator = new StandingsCalculator(tiebreakers: $tiebreakers);
        $table = $calculator->calculateWith($field, $results, $byes, $adjustments);

        expect(array_map(static fn(StandingEntry $entry): string => $entry->getParticipant()->getId(), $table->getEntries()))
            ->toBe(['won', 'sat-out', 'idle', 'beaten'])
            ->and($table->getRanks())->toBe([1, 1, 1, 1]);
    })->with([
        'no tiebreaker' => [[]],
        'a tiebreaker that allows the steps' => [[new ScoreDifferenceTiebreaker()]],
        'a tiebreaker between the tied participants' => [[new BetweenTiedParticipants(new ScoreDifferenceTiebreaker())]],
    ]);
});
