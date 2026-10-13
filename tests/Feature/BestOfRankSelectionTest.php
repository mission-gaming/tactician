<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Exceptions\UndecidedSelectionException;
use MissionGaming\Tactician\Stage\BestOfRankSelector;
use MissionGaming\Tactician\Stage\CompositionValidator;
use MissionGaming\Tactician\Stage\PoolComparison;
use MissionGaming\Tactician\Stage\RankRangeSelector;
use MissionGaming\Tactician\Stage\StageOutcome;
use MissionGaming\Tactician\Stage\StageTransition;
use MissionGaming\Tactician\Standings\StandingEntry;
use MissionGaming\Tactician\Standings\Standings;
use MissionGaming\Tactician\Standings\StandingsCalculator;
use MissionGaming\Tactician\Standings\WinsTiebreaker;
use PHPUnit\Framework\Assert;
use Random\Engine\Mt19937;
use Random\Randomizer;

// The best few of the entrants that hold one rank in their pools: who they
// are, what pools of unequal size do to the comparison, and what happens
// where results do not decide it.
//
// A pool is written as its scores: [first, second, score of the first, score
// of the second], the entrants by their number in the pool. Pool 'a' has the
// entrants a1, a2, ...; the higher score wins and equal scores are a draw.

/**
 * @param array<string, list<array{int, int, int, int}>> $pools The scores of each pool, by label
 * @return array<string, list<array{string, string, int, int}>> The same with participant IDs
 */
function bestOfRankMatches(array $pools): array
{
    $matches = [];
    foreach ($pools as $label => $scores) {
        $matches[$label] = array_map(
            fn(array $score): array => ["{$label}{$score[0]}", "{$label}{$score[1]}", $score[2], $score[3]],
            $scores
        );
    }

    return $matches;
}

/**
 * A pooled outcome of the given matches, built as an application builds
 * one: a table for each pool, combined by the same calculator.
 *
 * @param array<string, list<array{string, string, int, int}>> $matches Each pool's matches by
 *                                                                      label: two IDs and their
 *                                                                      scores
 * @param Randomizer|null $shuffle When given, the pools, the entrants of each pool and the
 *                                 results are put in an order drawn from it
 * @param bool $withEntrants Whether the outcomes are given their entrant lists
 * @param array<string, int> $seeds Seed attributes by participant ID; the others have none
 * @param bool $shuffleEntrants False to draw the order of the pools and of the results only, and
 *                              leave every entrant list as it is without a randomizer
 */
function bestOfRankOutcome(
    array $matches,
    StandingsCalculator $calculator = new StandingsCalculator(),
    ?Randomizer $shuffle = null,
    bool $withEntrants = true,
    array $seeds = [],
    bool $shuffleEntrants = true
): StageOutcome {
    $labels = array_keys($matches);
    if ($shuffle !== null) {
        $labels = $shuffle->shuffleArray($labels);
    }

    $outcomes = [];
    $entrantsByLabel = [];
    foreach ($labels as $label) {
        $members = [];
        foreach ($matches[$label] as [$first, $second]) {
            $members[$first] ??= new Participant($first, strtoupper($first), $seeds[$first] ?? null);
            $members[$second] ??= new Participant($second, strtoupper($second), $seeds[$second] ?? null);
        }
        ksort($members, SORT_STRING);

        $results = [];
        foreach ($matches[$label] as [$first, $second, $firstScore, $secondScore]) {
            $results[] = new Result(
                new Event([$members[$first], $members[$second]]),
                match ($firstScore <=> $secondScore) {
                    1 => $members[$first],
                    -1 => $members[$second],
                    default => null,
                },
                [$first => $firstScore, $second => $secondScore]
            );
        }

        $entrants = array_values($members);
        if ($shuffle !== null) {
            $entrants = $shuffleEntrants ? $shuffle->shuffleArray($entrants) : $entrants;
            $results = $shuffle->shuffleArray($results);
        }

        $outcomes[$label] = new StageOutcome(
            $calculator->calculate($entrants, $results),
            $results,
            entrants: $withEntrants ? $entrants : []
        );
        $entrantsByLabel[$label] = $entrants;
    }

    // The entrant list of the whole stage: pool by pool, in the order the
    // pools are in, unless the lists are to stay as they are
    if (!$shuffleEntrants) {
        ksort($entrantsByLabel, SORT_STRING);
    }

    return StageOutcome::combining($outcomes, $calculator, $withEntrants ? array_merge(...array_values($entrantsByLabel)) : []);
}

/**
 * Pools drawn from a randomizer: two to five of them, of three to five
 * entrants, each a complete single round robin with low scores, so that
 * entrants are often level.
 *
 * @return array<string, list<array{string, string, int, int}>>
 */
function bestOfRankGeneratedMatches(Randomizer $randomizer, bool $equalSizes): array
{
    $pools = $randomizer->getInt(2, 5);
    $sharedSize = $randomizer->getInt(3, 5);
    $highestScore = $randomizer->getInt(1, 4);

    $matches = [];
    for ($pool = 0; $pool < $pools; ++$pool) {
        $label = chr(97 + $pool);
        $size = $equalSizes ? $sharedSize : $randomizer->getInt(3, 5);
        $matches[$label] = [];
        for ($first = 1; $first < $size; ++$first) {
            for ($second = $first + 1; $second <= $size; ++$second) {
                $matches[$label][] = [
                    "{$label}{$first}",
                    "{$label}{$second}",
                    $randomizer->getInt(0, $highestScore),
                    $randomizer->getInt(0, $highestScore),
                ];
            }
        }
    }

    return $matches;
}

/**
 * The oracle's record of every participant, added up from the matches and
 * from nothing else: 3 points for a win and 1 for a draw.
 *
 * @param array<string, list<array{string, string, int, int}>> $matches
 * @param array<string, true> $without Participants whose matches are not counted, by ID
 * @return array<string, array{played: int, points: int, wins: int, for: int, against: int}>
 */
function bestOfRankRecords(array $matches, array $without = []): array
{
    $records = [];
    foreach ($matches as $poolMatches) {
        foreach ($poolMatches as [$first, $second, $firstScore, $secondScore]) {
            if (isset($without[$first]) || isset($without[$second])) {
                continue;
            }

            foreach ([[$first, $firstScore, $secondScore], [$second, $secondScore, $firstScore]] as [$id, $own, $other]) {
                $records[$id] ??= ['played' => 0, 'points' => 0, 'wins' => 0, 'for' => 0, 'against' => 0];
                ++$records[$id]['played'];
                $records[$id]['points'] += $own > $other ? 3 : ($own === $other ? 1 : 0);
                $records[$id]['wins'] += $own > $other ? 1 : 0;
                $records[$id]['for'] += $own;
                $records[$id]['against'] += $other;
            }
        }
    }

    return $records;
}

/**
 * The oracle's comparison of two records: below zero when the first is the
 * better. Points, wins when the calculator has that tiebreaker, score
 * difference, scores-for. Per event the figures are compared as fractions,
 * by cross-multiplication of whole numbers, and never divided.
 *
 * @param array{played: int, points: int, wins: int, for: int, against: int} $first
 * @param array{played: int, points: int, wins: int, for: int, against: int} $second
 */
function bestOfRankOracleOrder(array $first, array $second, bool $perEvent, bool $withWins): int
{
    $figures = fn(array $record): array => $withWins
        ? [$record['points'], $record['wins'], $record['for'] - $record['against'], $record['for']]
        : [$record['points'], $record['for'] - $record['against'], $record['for']];
    $others = $figures($second);

    foreach ($figures($first) as $index => $figure) {
        $other = $others[$index] ?? 0;
        $comparison = $perEvent
            ? $other * $first['played'] <=> $figure * $second['played']
            : $other <=> $figure;
        if ($comparison !== 0) {
            return $comparison;
        }
    }

    return 0;
}

/**
 * How many pools an outcome has, and the sizes of the smallest and of the
 * largest of them.
 *
 * @return array{pools: int, smallest: int, largest: int}
 */
function bestOfRankPoolSizes(StageOutcome $outcome): array
{
    $sizes = array_values(array_map(fn(StageOutcome $pool): int => count($pool->getStandings()), $outcome->getPools()));
    sort($sizes);

    return ['pools' => count($sizes), 'smallest' => $sizes[0] ?? 0, 'largest' => $sizes[count($sizes) - 1] ?? 0];
}

/**
 * What a selection has to be, worked out apart from the selector: the pool
 * tables say who holds the rank (and who is left out by the discard), and
 * everything after that is the oracle's.
 *
 * @param array<string, list<array{string, string, int, int}>> $matches
 * @return array{
 *     undecidedPools: list<string>,
 *     holders: list<string>,
 *     order: list<string>,
 *     level: list<string>,
 *     records: array<string, array{played: int, points: int, wins: int, for: int, against: int}>
 * } `undecidedPools`: the pools whose table does not decide the holder or the cut. `order`: the
 *   holders best first. `level`: the holders of the first level group that touches the selected
 *   places; empty when results decide the selection
 */
function bestOfRankExpectation(array $matches, StageOutcome $outcome, int $rank, int $count, ?PoolComparison $comparison, bool $withWins): array
{
    $smallest = bestOfRankPoolSizes($outcome)['smallest'];
    $discard = $comparison === PoolComparison::DiscardLowest;

    $undecidedPools = [];
    $holders = [];
    $without = [];
    foreach ($outcome->getPools() as $label => $pool) {
        $ids = bestOfRankIds(array_map(
            fn(StandingEntry $entry): Participant => $entry->getParticipant(),
            $pool->getStandings()->getEntries()
        ));
        $holders[] = $ids[$rank - 1];

        $cut = $discard && count($ids) > $smallest;
        foreach ($pool->getStandings()->getTiedSets() as $tiedSet) {
            $holdsRank = $tiedSet->getFirstPosition() <= $rank && $tiedSet->getLastPosition() >= $rank;
            $spansCut = $cut && $tiedSet->getFirstPosition() <= $smallest && $tiedSet->getLastPosition() > $smallest;
            if ($holdsRank || $spansCut) {
                $undecidedPools[] = (string) $label;
                break;
            }
        }

        if ($cut) {
            foreach (array_slice($ids, $smallest) as $id) {
                $without[$id] = true;
            }
        }
    }

    $records = bestOfRankRecords($matches, $without);
    $perEvent = $comparison === PoolComparison::PerEvent;
    $order = $holders;
    usort($order, fn(string $a, string $b): int => bestOfRankOracleOrder($records[$a], $records[$b], $perEvent, $withWins));

    $level = [];
    foreach ($order as $place => $id) {
        $next = $order[$place + 1] ?? null;
        if ($place < $count && $next !== null && bestOfRankOracleOrder($records[$id], $records[$next], $perEvent, $withWins) === 0) {
            $level = array_values(array_filter(
                $order,
                fn(string $other): bool => bestOfRankOracleOrder($records[$id], $records[$other], $perEvent, $withWins) === 0
            ));
            break;
        }
    }

    return ['undecidedPools' => $undecidedPools, 'holders' => $holders, 'order' => $order, 'level' => $level, 'records' => $records];
}

/**
 * @param array<Participant> $participants
 * @return list<string>
 */
function bestOfRankIds(array $participants): array
{
    return array_values(array_map(fn(Participant $participant): string => $participant->getId(), $participants));
}

/**
 * The configuration error a call raises.
 *
 * @param Closure(): mixed $call
 *
 * @throws PHPUnit\Framework\AssertionFailedError When the call raises none
 */
function bestOfRankRefusal(Closure $call): InvalidConfigurationException
{
    try {
        $call();
    } catch (InvalidConfigurationException $exception) {
        return $exception;
    }

    Assert::fail('The call was not refused.');
}

/**
 * Three pools of four. The entrants placed third: a3 has 4 points and a
 * score difference of +1, b3 has 4 points and 0, c3 has 3 points.
 *
 * @return array<string, list<array{int, int, int, int}>>
 */
function bestOfRankThreePoolsOfFour(): array
{
    return [
        // a1 9, a2 4 (+2), a3 4 (+1: 3 for, 2 against), a4 0
        'a' => [[1, 2, 2, 0], [1, 3, 1, 0], [1, 4, 1, 0], [2, 3, 1, 1], [2, 4, 4, 0], [3, 4, 2, 0]],
        // b1 9, b2 4 (+2), b3 4 (0: 3 for, 3 against), b4 0
        'b' => [[1, 2, 2, 0], [1, 3, 2, 0], [1, 4, 1, 0], [2, 3, 1, 1], [2, 4, 4, 0], [3, 4, 2, 0]],
        // c1 9, c2 6, c3 3, c4 0
        'c' => [[1, 2, 2, 0], [1, 3, 1, 0], [1, 4, 1, 0], [2, 3, 2, 1], [2, 4, 1, 0], [3, 4, 1, 0]],
    ];
}

/**
 * Pools of four, four and three. The entrants placed third:
 *
 * - a3 drew with a2, lost to a1 and beat a4: 4 points from 3 events;
 * - b3 beat b2, lost to b1 and lost to b4: 3 points from 3 events;
 * - c3 beat c2 and lost to c1: 3 points from 2 events.
 *
 * @return array<string, list<array{int, int, int, int}>>
 */
function bestOfRankUnequalPools(): array
{
    return [
        // a1 9, a2 4 (+2), a3 4 (+1), a4 0
        'a' => [[1, 2, 2, 0], [1, 3, 1, 0], [1, 4, 1, 0], [2, 3, 1, 1], [2, 4, 4, 0], [3, 4, 2, 0]],
        // b1 9, then b2, b3 and b4 on 3 points each: b2 +1, b3 -1, b4 -3
        'b' => [[1, 2, 1, 0], [1, 3, 1, 0], [1, 4, 1, 0], [3, 2, 1, 0], [2, 4, 3, 0], [4, 3, 1, 0]],
        // 3 points each: c1 +2, c2 0, c3 -2
        'c' => [[1, 3, 3, 0], [3, 2, 1, 0], [2, 1, 1, 0]],
    ];
}

describe('the best of a rank across pools', function (): void {
    it('returns the two best third-placed entrants of three pools, best first', function (): void {
        $outcome = bestOfRankOutcome(bestOfRankMatches(bestOfRankThreePoolsOfFour()));

        // The records the selection is made from are the ones stated
        $thirds = [];
        foreach (['a', 'b', 'c'] as $label) {
            $entry = array_values($outcome->getPool($label)?->getStandings()->getEntries() ?? [])[2];
            $thirds[$entry->getParticipant()->getId()] = [$entry->getRankingValue(), $entry->getScoreDifference()];
        }
        expect($thirds)->toBe(['a3' => [4.0, 1.0], 'b3' => [4.0, 0.0], 'c3' => [3.0, -1.0]]);

        expect(bestOfRankIds(BestOfRankSelector::ofRank(3, 2)->select($outcome)))->toBe(['a3', 'b3'])
            ->and(bestOfRankIds(BestOfRankSelector::ofRank(3, 1)->select($outcome)))->toBe(['a3'])
            ->and(bestOfRankIds(BestOfRankSelector::ofRank(3, 3)->select($outcome)))->toBe(['a3', 'b3', 'c3']);
    });

    it('needs no method for pools of one size, and gives the same order under each', function (): void {
        $outcome = bestOfRankOutcome(bestOfRankMatches(bestOfRankThreePoolsOfFour()));

        foreach (PoolComparison::cases() as $comparison) {
            expect(bestOfRankIds(BestOfRankSelector::ofRank(3, 3)->comparedBy($comparison)->select($outcome)))
                ->toBe(['a3', 'b3', 'c3'], $comparison->value);
        }
    });

    it('selects the same whatever order the pools, the entrants and the results were given in', function (): void {
        $matches = bestOfRankMatches(bestOfRankThreePoolsOfFour());

        foreach (range(1, 20) as $seed) {
            $outcome = bestOfRankOutcome($matches, shuffle: new Randomizer(new Mt19937($seed)));

            expect(bestOfRankIds(BestOfRankSelector::ofRank(3, 2)->select($outcome)))->toBe(['a3', 'b3'], "seed {$seed}");
        }
    });

    it('compares by the tiebreakers of the calculator that combined the pools, before the score difference', function (): void {
        // Both entrants placed second have 3 points. x2 drew three times:
        // no win, score difference 0. y2 won once and lost twice: one win,
        // score difference -1
        $matches = bestOfRankMatches([
            'x' => [[1, 2, 0, 0], [1, 3, 1, 0], [1, 4, 1, 0], [2, 3, 0, 0], [2, 4, 0, 0], [3, 4, 0, 0]],
            'y' => [[1, 2, 1, 0], [1, 3, 5, 0], [1, 4, 5, 0], [2, 3, 1, 0], [4, 2, 1, 0], [3, 4, 1, 0]],
        ]);
        $plain = bestOfRankOutcome($matches);
        $withWins = bestOfRankOutcome($matches, new StandingsCalculator(tiebreakers: [new WinsTiebreaker()]));

        $second = function (string $pool) use ($plain): array {
            $entry = array_values($plain->getPool($pool)?->getStandings()->getEntries() ?? [])[1];

            return [$entry->getParticipant()->getId(), $entry->getRankingValue(), $entry->getWins(), $entry->getScoreDifference()];
        };
        expect($second('x'))->toBe(['x2', 3.0, 0, 0.0])
            ->and($second('y'))->toBe(['y2', 3.0, 1, -1.0]);

        expect(bestOfRankIds(BestOfRankSelector::ofRank(2, 1)->select($plain)))->toBe(['x2'])
            ->and(bestOfRankIds(BestOfRankSelector::ofRank(2, 1)->select($withWins)))->toBe(['y2']);
    });

    it('refuses pools whose tables carry different tiebreakers', function (): void {
        $x = new Participant('x', 'X');
        $y = new Participant('y', 'Y');
        $result = new Result(new Event([$x, $y]), $x);
        $table = new Standings([
            new StandingEntry($x, 1, 1, 0, 0, 3.0, tiebreakers: ['wins' => 1.0]),
            new StandingEntry($y, 1, 0, 0, 1, 0.0),
        ]);
        $outcome = new StageOutcome($table, [$result], pools: [
            'p' => new StageOutcome(new Standings([new StandingEntry($x, 1, 1, 0, 0, 3.0)]), [$result]),
            'q' => new StageOutcome(new Standings([new StandingEntry($y, 1, 0, 0, 1, 0.0)]), [$result]),
        ]);

        expect(bestOfRankRefusal(fn() => BestOfRankSelector::ofRank(1, 1)->select($outcome))->getReason())
            ->toBe(InvalidConfigurationReason::IncompatibleOutcome);
    });

    it('refuses an outcome without pools, a rank a pool does not have and more than there are pools', function (): void {
        $outcome = bestOfRankOutcome(bestOfRankMatches(bestOfRankThreePoolsOfFour()));
        $onePool = $outcome->getPool('a');
        assert($onePool !== null);

        $unpooled = bestOfRankRefusal(fn() => BestOfRankSelector::ofRank(3, 2)->select($onePool));
        expect($unpooled->getReason())->toBe(InvalidConfigurationReason::IncompatibleOutcome);

        $noRank = bestOfRankRefusal(fn() => BestOfRankSelector::ofRank(5, 2)->select($outcome));
        expect($noRank->getReason())->toBe(InvalidConfigurationReason::RankUnavailable)
            ->and($noRank->getContext())->toBe(['pool' => 'a', 'rank' => 5, 'pool_size' => 4]);

        $tooMany = bestOfRankRefusal(fn() => BestOfRankSelector::ofRank(3, 4)->select($outcome));
        expect($tooMany->getReason())->toBe(InvalidConfigurationReason::RankUnavailable)
            ->and($tooMany->getContext())->toBe(['count' => 4, 'rank' => 3, 'pools' => 3]);
    });

    it('refuses a rank or a count below 1', function (int $rank, int $count): void {
        expect(bestOfRankRefusal(fn() => BestOfRankSelector::ofRank($rank, $count))->getReason())
            ->toBe(InvalidConfigurationReason::ValueOutOfRange)
            ->and(bestOfRankRefusal(fn() => BestOfRankSelector::fromArray(['rank' => $rank, 'count' => $count]))->getReason())
            ->toBe(InvalidConfigurationReason::ValueOutOfRange);
    })->with([[0, 1], [1, 0], [-1, 2]]);
});

describe('the best of a rank across pools of unequal size', function (): void {
    beforeEach(function (): void {
        $this->outcome = bestOfRankOutcome(bestOfRankMatches(bestOfRankUnequalPools()));
    });

    it('is built from the records the test states', function (): void {
        $thirds = [];
        foreach (['a', 'b', 'c'] as $label) {
            $entry = array_values($this->outcome->getPool($label)?->getStandings()->getEntries() ?? [])[2];
            $thirds[$entry->getParticipant()->getId()] = [$entry->getRankingValue(), $entry->getPlayed(), $entry->getScoreDifference()];
        }

        expect($thirds)->toBe(['a3' => [4.0, 3, 1.0], 'b3' => [3.0, 3, -1.0], 'c3' => [3.0, 2, -2.0]]);
    });

    it('refuses to compare them until a method is chosen, naming the pool sizes', function (): void {
        $refusal = bestOfRankRefusal(fn() => BestOfRankSelector::ofRank(3, 2)->select($this->outcome));

        expect($refusal->getReason())->toBe(InvalidConfigurationReason::PoolComparisonRequired)
            ->and($refusal->getReason()?->value)->toBe('pool_comparison_required')
            ->and($refusal->getContext())->toBe([
                'pool_sizes' => ['a' => 4, 'b' => 4, 'c' => 3],
                'known' => ['raw-totals', 'per-event', 'discard-lowest'],
            ])
            ->and($refusal->getMessage())->toContain('a: 4, b: 4, c: 3')
            ->and($refusal)->not->toBeInstanceOf(UndecidedSelectionException::class);

        // Accepting an undecided order is not choosing a method
        expect(bestOfRankRefusal(fn() => BestOfRankSelector::ofRank(3, 2)->withTableOrderAccepted()->select($this->outcome))->getReason())
            ->toBe(InvalidConfigurationReason::PoolComparisonRequired);
    });

    it('ranks by raw totals: 4 points, then 3 points with the better score difference', function (): void {
        $selector = BestOfRankSelector::ofRank(3, 3)->comparedBy(PoolComparison::RawTotals);

        expect(bestOfRankIds($selector->select($this->outcome)))->toBe(['a3', 'b3', 'c3']);
    });

    it('ranks per event played: 3 from 2, then 4 from 3, then 3 from 3', function (): void {
        $selector = BestOfRankSelector::ofRank(3, 3)->comparedBy(PoolComparison::PerEvent);

        expect(bestOfRankIds($selector->select($this->outcome)))->toBe(['c3', 'a3', 'b3']);
    });

    it('ranks without the results against the entrant placed fourth in each pool of four', function (): void {
        // What is left: a3 drew with a2 and lost to a1 (1 point); b3 beat
        // b2 and lost to b1 (3 points, score difference 0); c3 keeps its
        // record (3 points, score difference -2)
        $selector = BestOfRankSelector::ofRank(3, 3)->comparedBy(PoolComparison::DiscardLowest);

        expect(bestOfRankIds($selector->select($this->outcome)))->toBe(['b3', 'c3', 'a3']);
    });

    it('selects a different pair under each method', function (): void {
        $pair = fn(PoolComparison $comparison): array => bestOfRankIds(
            BestOfRankSelector::ofRank(3, 2)->comparedBy($comparison)->select($this->outcome)
        );

        expect($pair(PoolComparison::RawTotals))->toBe(['a3', 'b3'])
            ->and($pair(PoolComparison::PerEvent))->toBe(['c3', 'a3'])
            ->and($pair(PoolComparison::DiscardLowest))->toBe(['b3', 'c3']);
    });

    it('needs the calculator of the outcome for the discard, and only for the discard', function (): void {
        // The same outcome built by hand, with no calculator
        $byHand = new StageOutcome($this->outcome->getStandings(), $this->outcome->getResults(), pools: $this->outcome->getPools());
        expect($byHand->getCalculator())->toBeNull()
            ->and($this->outcome->getCalculator())->toBeInstanceOf(StandingsCalculator::class);

        $refusal = bestOfRankRefusal(
            fn() => BestOfRankSelector::ofRank(3, 2)->comparedBy(PoolComparison::DiscardLowest)->select($byHand)
        );
        expect($refusal->getReason())->toBe(InvalidConfigurationReason::IncompatibleOutcome)
            ->and($refusal->getMessage())->toContain('calculator');

        expect(bestOfRankIds(BestOfRankSelector::ofRank(3, 2)->comparedBy(PoolComparison::PerEvent)->select($byHand)))
            ->toBe(['c3', 'a3']);

        // Given the calculator, the outcome built by hand is read as the combined one
        $withCalculator = new StageOutcome(
            $this->outcome->getStandings(),
            $this->outcome->getResults(),
            pools: $this->outcome->getPools(),
            calculator: new StandingsCalculator()
        );
        expect(bestOfRankIds(BestOfRankSelector::ofRank(3, 2)->comparedBy(PoolComparison::DiscardLowest)->select($withCalculator)))
            ->toBe(['b3', 'c3']);
    });

    it('refuses a holder of the rank that the table of the outcome does not have', function (): void {
        $pools = $this->outcome->getPools();
        $withoutThirds = new Standings(array_values(array_filter(
            $this->outcome->getStandings()->getEntries(),
            fn(StandingEntry $entry): bool => $entry->getParticipant()->getId() !== 'b3'
        )));

        $refusal = bestOfRankRefusal(fn() => BestOfRankSelector::ofRank(3, 2)
            ->comparedBy(PoolComparison::RawTotals)
            ->select(new StageOutcome($withoutThirds, $this->outcome->getResults(), pools: $pools)));

        expect($refusal->getReason())->toBe(InvalidConfigurationReason::IncompatibleOutcome)
            ->and($refusal->getContext())->toBe(['participant' => 'b3', 'rank' => 3]);
    });
});

describe('a best-of-rank selection that results do not decide', function (): void {
    beforeEach(function (): void {
        // The entrants placed third are level on everything: 3 points,
        // score difference -1, 1 scored
        $level = [[1, 2, 1, 0], [1, 3, 1, 0], [1, 4, 1, 0], [2, 3, 1, 0], [2, 4, 1, 0], [3, 4, 1, 0]];
        $this->matches = bestOfRankMatches([
            'a' => $level,
            'b' => $level,
            // c3 is behind them: 3 points and a score difference of -3
            'c' => [[1, 2, 1, 0], [1, 3, 3, 0], [1, 4, 1, 0], [2, 3, 1, 0], [2, 4, 1, 0], [3, 4, 1, 0]],
            // d3 is ahead of them: 3 points and a score difference of +1
            'd' => [[1, 2, 1, 0], [1, 3, 1, 0], [1, 4, 1, 0], [2, 3, 1, 0], [2, 4, 1, 0], [3, 4, 3, 0]],
        ]);
        $this->outcome = bestOfRankOutcome($this->matches);
    });

    it('is refused when the cut goes through level entrants, naming them and the rank', function (): void {
        try {
            BestOfRankSelector::ofRank(3, 2)->select($this->outcome);
        } catch (UndecidedSelectionException $refusal) {
            expect($refusal->getReason())->toBe(InvalidConfigurationReason::TieAcrossSelection)
                ->and($refusal->getParticipantIds())->toBe(['a3', 'b3'])
                ->and([$refusal->getFirstPlace(), $refusal->getLastPlace()])->toBe([2, 3])
                ->and($refusal->getRankAcrossPools())->toBe(3)
                ->and($refusal->getPool())->toBeNull()
                ->and([$refusal->getFrom(), $refusal->getTo()])->toBe([1, 2])
                ->and($refusal->getMessage())->toEndWith(
                    'No result separates the participants in places 2 to 3 of the entrants placed 3 in their pools'
                    . ' (a3, b3), and places 1 to 2 take only some of them'
                )
                ->and($refusal->getContext())->toBe([
                    'participants' => ['a3', 'b3'],
                    'first_place' => 2,
                    'last_place' => 3,
                    'pool' => null,
                    'from' => 1,
                    'to' => 2,
                    'rank_across_pools' => 3,
                ]);

            return;
        }

        Assert::fail('A cut through level entrants was selected.');
    });

    it('is not refused when the level entrants are all on one side of the cut it does not order', function (): void {
        // One selected: d3 alone is first. The level pair is outside
        expect(bestOfRankIds(BestOfRankSelector::ofRank(3, 1)->select($this->outcome)))->toBe(['d3']);
    });

    it('is refused when the level entrants are all selected, until that is accepted', function (): void {
        try {
            BestOfRankSelector::ofRank(3, 3)->select($this->outcome);
            Assert::fail('An undecided order was selected.');
        } catch (UndecidedSelectionException $refusal) {
            expect($refusal->getReason())->toBe(InvalidConfigurationReason::TieWithinSelection)
                ->and([$refusal->getFirstPlace(), $refusal->getLastPlace()])->toBe([2, 3])
                ->and($refusal->getRankAcrossPools())->toBe(3);
        }

        expect(bestOfRankIds(BestOfRankSelector::ofRank(3, 3)->withTiesInsideSelectionAccepted()->select($this->outcome)))
            ->toBe(['d3', 'a3', 'b3']);

        // Accepting ties inside the selection does not accept a cut through one
        expect(fn() => BestOfRankSelector::ofRank(3, 2)->withTiesInsideSelectionAccepted()->select($this->outcome))
            ->toThrow(UndecidedSelectionException::class);
    });

    it('lets the position in the entrant list decide once the order as it stands is accepted', function (): void {
        $accepting = BestOfRankSelector::ofRank(3, 2)->withTableOrderAccepted();

        // Pool a is listed before pool b
        expect(bestOfRankIds($accepting->select($this->outcome)))->toBe(['d3', 'a3']);

        // The same pools with pool b's entrants first in the entrant list
        $pools = $this->outcome->getPools();
        $entrants = [];
        foreach (['b', 'a', 'c', 'd'] as $label) {
            array_push($entrants, ...$pools[$label]->getEntrants());
        }
        $reordered = StageOutcome::combining($pools, new StandingsCalculator(), $entrants);
        expect(bestOfRankIds($accepting->select($reordered)))->toBe(['d3', 'b3']);

        // Whatever the seed attributes say: b3 is given the seed that
        // would put it first, and a3 is still listed before it
        $seeded = bestOfRankOutcome($this->matches, seeds: ['b3' => 1, 'a3' => 9]);
        expect(bestOfRankIds($accepting->select($seeded)))->toBe(['d3', 'a3']);
    });

    it('falls back on the order of the table of an outcome that has no entrant list', function (): void {
        $accepting = BestOfRankSelector::ofRank(3, 2)->withTableOrderAccepted();
        $outcome = bestOfRankOutcome($this->matches, withEntrants: false);

        expect($outcome->getEntrants())->toBe([])
            ->and(bestOfRankIds($accepting->select($outcome)))->toBe(['d3', 'a3']);

        // That table is the calculator's own, whose last fallback reads
        // the seed attribute: with no list to decide, it does
        $seeded = bestOfRankOutcome($this->matches, withEntrants: false, seeds: ['b3' => 1, 'a3' => 9]);
        expect(bestOfRankIds($accepting->select($seeded)))->toBe(['d3', 'b3']);
    });

    it('is refused for a pool with no results, naming the pool', function (): void {
        $pools = $this->outcome->getPools();
        $unplayed = [new Participant('e1', 'E1'), new Participant('e2', 'E2'), new Participant('e3', 'E3')];
        $pools['e'] = new StageOutcome((new StandingsCalculator())->calculate($unplayed, []), [], entrants: $unplayed);
        $outcome = StageOutcome::combining($pools);

        try {
            BestOfRankSelector::ofRank(3, 1)->comparedBy(PoolComparison::RawTotals)->select($outcome);
        } catch (UndecidedSelectionException $refusal) {
            expect($refusal->getReason())->toBe(InvalidConfigurationReason::UnplayedStage)
                ->and($refusal->getPool())->toBe('e')
                ->and($refusal->getRankAcrossPools())->toBeNull();

            // Accepted, the entrant at the position holds the rank, with
            // the record it has: no event played
            expect(bestOfRankIds(
                BestOfRankSelector::ofRank(3, 5)->comparedBy(PoolComparison::PerEvent)->withTableOrderAccepted()->select($outcome)
            ))->toBe(['d3', 'a3', 'b3', 'c3', 'e3']);

            return;
        }

        Assert::fail('An unplayed pool was selected from.');
    });

    it('is refused when level entries of a pool hold the rank, naming the pool', function (): void {
        // In pool z the second and the third are level
        $outcome = bestOfRankOutcome(bestOfRankMatches([
            'y' => [[1, 2, 1, 0], [1, 3, 1, 0], [2, 3, 1, 0]],
            'z' => [[1, 2, 1, 0], [1, 3, 1, 0], [2, 3, 0, 0]],
        ]));

        try {
            BestOfRankSelector::ofRank(2, 1)->select($outcome);
        } catch (UndecidedSelectionException $refusal) {
            expect($refusal->getReason())->toBe(InvalidConfigurationReason::TieAcrossSelection)
                ->and($refusal->getPool())->toBe('z')
                ->and($refusal->getParticipantIds())->toBe(['z2', 'z3'])
                ->and([$refusal->getFrom(), $refusal->getTo()])->toBe([2, 2]);

            // Accepting ties inside the selection does not accept it: only
            // one of the two holds the rank
            expect(fn() => BestOfRankSelector::ofRank(2, 1)->withTiesInsideSelectionAccepted()->select($outcome))
                ->toThrow(UndecidedSelectionException::class);
            // y2 has 3 points, z2 has 1
            expect(bestOfRankIds(BestOfRankSelector::ofRank(2, 2)->withTableOrderAccepted()->select($outcome)))->toBe(['y2', 'z2']);

            return;
        }

        Assert::fail('A rank held by level entries was selected.');
    });

    it('is refused under the discard when level entries are on both sides of the place a pool is cut at', function (): void {
        // Pool w has four entrants, of which the third and the fourth are
        // level; pool v has three. Whose results are dropped is undecided
        $outcome = bestOfRankOutcome(bestOfRankMatches([
            'v' => [[1, 2, 1, 0], [1, 3, 1, 0], [2, 3, 1, 0]],
            'w' => [[1, 2, 1, 0], [1, 3, 1, 0], [1, 4, 1, 0], [2, 3, 1, 0], [2, 4, 1, 0], [3, 4, 0, 0]],
        ]));
        $selector = BestOfRankSelector::ofRank(2, 1)->comparedBy(PoolComparison::DiscardLowest);

        try {
            $selector->select($outcome);
        } catch (UndecidedSelectionException $refusal) {
            expect($refusal->getPool())->toBe('w')
                ->and($refusal->getParticipantIds())->toBe(['w3', 'w4'])
                ->and([$refusal->getFirstPlace(), $refusal->getLastPlace()])->toBe([3, 4])
                ->and([$refusal->getFrom(), $refusal->getTo()])->toBe([2, 3])
                ->and($refusal->getReason())->toBe(InvalidConfigurationReason::TieAcrossSelection);

            // The other methods drop nothing and are not refused: w2 has 6
            // points from 3 events, v2 has 3 from 2
            expect(bestOfRankIds(BestOfRankSelector::ofRank(2, 1)->comparedBy(PoolComparison::RawTotals)->select($outcome)))
                ->toBe(['w2']);
            // Accepted, the entrant listed later of the two is left out:
            // without w4, w2 has 3 points from 2 events and a score
            // difference of 0, as v2 has, and the entrant list decides
            expect(bestOfRankIds($selector->withTableOrderAccepted()->select($outcome)))->toBe(['v2']);

            return;
        }

        Assert::fail('An undecided cut was selected through.');
    });
});

describe('a best-of-rank selector as plain data', function (): void {
    it('round-trips, and writes the comparison and the tie policy only when they were chosen', function (): void {
        $plain = BestOfRankSelector::ofRank(3, 2);
        $full = $plain->comparedBy(PoolComparison::DiscardLowest)->withTiesInsideSelectionAccepted();

        expect($plain->toArray())->toBe(['format_version' => 1, 'rank' => 3, 'count' => 2])
            ->and($plain->comparedBy(PoolComparison::PerEvent)->toArray())->toBe(['format_version' => 1, 'rank' => 3, 'count' => 2, 'comparison' => 'per-event'])
            ->and($plain->withTableOrderAccepted()->toArray())->toBe(['format_version' => 1, 'rank' => 3, 'count' => 2, 'ties' => 'accept-table-order'])
            ->and($full->toArray())->toBe(['format_version' => 1, 'rank' => 3, 'count' => 2, 'comparison' => 'discard-lowest', 'ties' => 'accept-inside-selection'])
            ->and(BestOfRankSelector::fromArray($full->toArray())->toArray())->toBe($full->toArray())
            ->and(BestOfRankSelector::fromArray(['rank' => 3, 'count' => 2, 'comparison' => null, 'ties' => 'refuse'])->toArray())
            ->toBe(['format_version' => 1, 'rank' => 3, 'count' => 2])
            // The selector a method was called on is not changed
            ->and($plain->toArray())->toBe(['format_version' => 1, 'rank' => 3, 'count' => 2]);
    });

    it('keeps the backing strings of the comparison methods', function (): void {
        $values = [];
        foreach (PoolComparison::cases() as $comparison) {
            $values[$comparison->name] = $comparison->value;
        }

        expect($values)->toBe(['RawTotals' => 'raw-totals', 'PerEvent' => 'per-event', 'DiscardLowest' => 'discard-lowest']);
    });

    it('behaves as the selector it was written from', function (): void {
        $outcome = bestOfRankOutcome(bestOfRankMatches(bestOfRankUnequalPools()));

        foreach (PoolComparison::cases() as $comparison) {
            $selector = BestOfRankSelector::ofRank(3, 2)->comparedBy($comparison);

            expect(bestOfRankIds(BestOfRankSelector::fromArray($selector->toArray())->select($outcome)))
                ->toBe(bestOfRankIds($selector->select($outcome)));
        }
    });

    it('refuses what it cannot read', function (array $config, InvalidConfigurationReason $reason): void {
        expect(bestOfRankRefusal(fn() => BestOfRankSelector::fromArray($config))->getReason())->toBe($reason);
    })->with([
        'no rank' => [['count' => 2], InvalidConfigurationReason::WrongValueType],
        'no count' => [['rank' => 3], InvalidConfigurationReason::WrongValueType],
        'a rank written as a string' => [['rank' => '3', 'count' => 2], InvalidConfigurationReason::WrongValueType],
        'a comparison nobody knows' => [['rank' => 3, 'count' => 2, 'comparison' => 'average'], InvalidConfigurationReason::UnknownIdentifier],
        'a comparison that is not a string' => [['rank' => 3, 'count' => 2, 'comparison' => 1], InvalidConfigurationReason::WrongValueType],
        'a tie policy nobody knows' => [['rank' => 3, 'count' => 2, 'ties' => 'whatever'], InvalidConfigurationReason::UnknownIdentifier],
        'a misspelt key' => [['rank' => 3, 'count' => 2, 'comparision' => 'per-event'], InvalidConfigurationReason::UnknownOptionKey],
    ]);

    it('names the key it does not know and the ones it does', function (): void {
        $refusal = bestOfRankRefusal(fn() => BestOfRankSelector::fromArray(['rank' => 3, 'count' => 2, 'mode' => 'best']));

        expect($refusal->getContext())->toBe(['unknown' => ['mode'], 'known' => ['format_version', 'rank', 'count', 'comparison', 'ties']]);
    });
});

describe('a best-of-rank selector in a declared composition', function (): void {
    it('reports its count as its yield, whatever the outcome', function (): void {
        expect(BestOfRankSelector::ofRank(3, 2)->getSelectionSize())->toBe(2)
            ->and(BestOfRankSelector::ofRank(1, 5)->comparedBy(PoolComparison::PerEvent)->getSelectionSize())->toBe(5);
    });

    it('is counted by composition validation', function (): void {
        $validator = new CompositionValidator();

        expect($validator->validateChain(12, [
            new StageTransition('play-off of the third-placed', 2, BestOfRankSelector::ofRank(3, 2)),
        ]))->toBe([]);

        expect($validator->validateChain(12, [
            new StageTransition('play-off of the third-placed', 4, BestOfRankSelector::ofRank(3, 2)),
        ]))->toBe(["Stage 'play-off of the third-placed' expects 4 entrants but its selection yields 2 from the previous stage's 12."]);
    });
});

describe('an overall rank selection across pools of unequal size', function (): void {
    beforeEach(function (): void {
        $this->outcome = bestOfRankOutcome(bestOfRankMatches(bestOfRankUnequalPools()));
    });

    it('is refused until a method is chosen, naming the pool sizes', function (): void {
        $refusal = bestOfRankRefusal(fn() => RankRangeSelector::overall(1, 4)->select($this->outcome));

        expect($refusal->getReason())->toBe(InvalidConfigurationReason::PoolComparisonRequired)
            ->and($refusal->getContext()['pool_sizes'] ?? null)->toBe(['a' => 4, 'b' => 4, 'c' => 3]);

        // Also for a selector that accepts the table as it stands: which
        // table is the question
        expect(bestOfRankRefusal(fn() => RankRangeSelector::overall(1, 4)->withTableOrderAccepted()->select($this->outcome))->getReason())
            ->toBe(InvalidConfigurationReason::PoolComparisonRequired);
    });

    it('is not refused for pools of one size, for one table or per group', function (): void {
        $equal = bestOfRankOutcome(bestOfRankMatches(bestOfRankThreePoolsOfFour()));
        $onePool = $this->outcome->getPool('a');
        assert($onePool !== null);

        // a1, b1 and c1 are level on 9 points, +4 and 4 scored
        expect(bestOfRankIds(RankRangeSelector::overall(4, 4)->select($equal)))->toBe(['c2'])
            ->and(bestOfRankIds(RankRangeSelector::overall(1, 2)->select($onePool)))->toBe(['a1', 'a2'])
            ->and(bestOfRankIds(RankRangeSelector::perGroup(3, 3)->select($this->outcome)))->toBe(['a3', 'b3', 'c3']);
    });

    it('takes the table as it stands by raw totals', function (): void {
        $table = bestOfRankIds(array_map(
            fn(StandingEntry $entry): Participant => $entry->getParticipant(),
            $this->outcome->getStandings()->getEntries()
        ));

        expect(bestOfRankIds(RankRangeSelector::overall(3, 6)->comparedBy(PoolComparison::RawTotals)->select($this->outcome)))
            ->toBe(array_slice($table, 2, 4))
            // a1 and b1 have 9 points from 3 events; a2 and a3 have 4
            ->and(array_slice($table, 2, 2))->toBe(['a2', 'a3']);
    });

    it('orders the table per event played', function (): void {
        // Points per event: a1 and b1 3, then the three of pool c on 1.5
        // (c1 +2, c2 0, c3 -2 over 2 events), then a2 and a3 on 4/3
        $selector = RankRangeSelector::overall(3, 7)->comparedBy(PoolComparison::PerEvent);

        expect(bestOfRankIds($selector->select($this->outcome)))->toBe(['c1', 'c2', 'c3', 'a2', 'a3']);
    });

    it('refuses level entries per event as it refuses level entries of a table', function (): void {
        // a1 has 9 points from 3 events and 4 scored for none against; b1
        // has 9 from 3 and 3 scored. Per event they differ, so the pair
        // that is level here is made by hand: 6 from 4 against 3 from 2
        $x = new Participant('x', 'X');
        $y = new Participant('y', 'Y');
        $z = new Participant('z', 'Z');
        $w = new Participant('w', 'W');
        $result = new Result(new Event([$x, $y]), $x);
        $pools = [
            'p' => new StageOutcome(new Standings([new StandingEntry($x, 4, 2, 0, 2, 6.0), new StandingEntry($w, 4, 0, 0, 4, 0.0)]), [$result]),
            'q' => new StageOutcome(new Standings([new StandingEntry($y, 2, 1, 0, 1, 3.0)]), [$result]),
        ];
        $table = new Standings([
            new StandingEntry($x, 4, 2, 0, 2, 6.0),
            new StandingEntry($y, 2, 1, 0, 1, 3.0),
            new StandingEntry($w, 4, 0, 0, 4, 0.0),
        ]);
        $outcome = new StageOutcome($table, [$result], pools: $pools, entrants: [$z, $y, $x, $w]);
        $selector = RankRangeSelector::overall(1, 1)->comparedBy(PoolComparison::PerEvent);

        try {
            $selector->select($outcome);
        } catch (UndecidedSelectionException $refusal) {
            expect($refusal->getReason())->toBe(InvalidConfigurationReason::TieAcrossSelection)
                // In entrant-list order: y is listed before x
                ->and($refusal->getParticipantIds())->toBe(['y', 'x'])
                ->and([$refusal->getFirstPlace(), $refusal->getLastPlace()])->toBe([1, 2])
                ->and($refusal->getRankAcrossPools())->toBeNull();

            expect(bestOfRankIds($selector->withTableOrderAccepted()->select($outcome)))->toBe(['y'])
                ->and(bestOfRankIds(RankRangeSelector::overall(1, 2)->comparedBy(PoolComparison::PerEvent)->withTiesInsideSelectionAccepted()->select($outcome)))
                ->toBe(['y', 'x'])
                // By raw totals nothing is level: 6 points against 3
                ->and(bestOfRankIds(RankRangeSelector::overall(1, 1)->comparedBy(PoolComparison::RawTotals)->select($outcome)))->toBe(['x']);

            return;
        }

        Assert::fail('Entries level per event were selected from.');
    });

    it('refuses an unplayed pooled table and a table with tiers per event', function (): void {
        $x = new Participant('x', 'X');
        $y = new Participant('y', 'Y');
        $entries = [new StandingEntry($x, 0, 0, 0, 0, 0.0), new StandingEntry($y, 0, 0, 0, 0, 0.0)];
        $pools = ['p' => new StageOutcome(new Standings([$entries[0]]), []), 'q' => new StageOutcome(new Standings([$entries[1]]), [])];
        $selector = RankRangeSelector::overall(1, 1)->comparedBy(PoolComparison::PerEvent);

        try {
            $selector->select(new StageOutcome(new Standings($entries), [], pools: $pools));
            Assert::fail('An unplayed table was selected from.');
        } catch (UndecidedSelectionException $refusal) {
            expect($refusal->getReason())->toBe(InvalidConfigurationReason::UnplayedStage);
        }

        expect(bestOfRankIds($selector->withTableOrderAccepted()->select(new StageOutcome(new Standings($entries), [], pools: $pools))))
            ->toBe(['x']);

        $tiered = new StageOutcome(new Standings($entries, [1, 2]), [new Result(new Event([$x, $y]), $x)], pools: $pools);
        expect(bestOfRankRefusal(fn() => $selector->select($tiered))->getReason())
            ->toBe(InvalidConfigurationReason::IncompatibleOutcome);
    });

    it('takes no discard, and no method per group', function (): void {
        $discard = bestOfRankRefusal(fn() => RankRangeSelector::overall(1, 4)->comparedBy(PoolComparison::DiscardLowest));
        $perGroup = bestOfRankRefusal(fn() => RankRangeSelector::topPerGroup(2)->comparedBy(PoolComparison::RawTotals));

        expect($discard->getReason())->toBe(InvalidConfigurationReason::IncompatibleOptions)
            ->and($perGroup->getReason())->toBe(InvalidConfigurationReason::IncompatibleOptions)
            ->and(bestOfRankRefusal(fn() => RankRangeSelector::fromArray(['mode' => 'overall', 'to' => 4, 'comparison' => 'discard-lowest']))->getReason())
            ->toBe(InvalidConfigurationReason::IncompatibleOptions)
            ->and(bestOfRankRefusal(fn() => RankRangeSelector::fromArray(['mode' => 'per-group', 'to' => 2, 'comparison' => 'per-event']))->getReason())
            ->toBe(InvalidConfigurationReason::IncompatibleOptions)
            ->and(bestOfRankRefusal(fn() => RankRangeSelector::fromArray(['mode' => 'overall', 'to' => 4, 'comparison' => 'mean']))->getReason())
            ->toBe(InvalidConfigurationReason::UnknownIdentifier);
    });

    it('keeps the method through plain data, and writes it only when it was chosen', function (): void {
        $selector = RankRangeSelector::overall(1, 4)->comparedBy(PoolComparison::PerEvent);
        $accepting = $selector->withTiesInsideSelectionAccepted();

        expect($selector->toArray())->toBe(['format_version' => 1, 'mode' => 'overall', 'from' => 1, 'to' => 4, 'comparison' => 'per-event'])
            ->and($accepting->toArray())
            ->toBe(['format_version' => 1, 'mode' => 'overall', 'from' => 1, 'to' => 4, 'ties' => 'accept-inside-selection', 'comparison' => 'per-event'])
            ->and(RankRangeSelector::fromArray($accepting->toArray())->toArray())->toBe($accepting->toArray())
            ->and(RankRangeSelector::overall(1, 4)->toArray())->toBe(['format_version' => 1, 'mode' => 'overall', 'from' => 1, 'to' => 4])
            ->and(bestOfRankIds(RankRangeSelector::fromArray($selector->toArray())->select($this->outcome)))
            ->toBe(bestOfRankIds($selector->select($this->outcome)));
    });
});

// Over generated pools, of one size and of several, with and without level
// entrants: every rank, every count and every comparison method, each
// against an oracle that adds the records up from the scores by itself.
describe('the best of a rank over generated pools', function (): void {
    it('is what the oracle says, for every order of the input', function (string $name, Closure $calculator, bool $withWins): void {
        $tally = ['selected' => 0, 'undecided in a pool' => 0, 'undecided across pools' => 0, 'no method' => 0];
        $unequalStages = 0;

        foreach (range(1, 90) as $seed) {
            $matches = bestOfRankGeneratedMatches(new Randomizer(new Mt19937($seed)), $seed % 3 === 0);
            $outcome = bestOfRankOutcome($matches, $calculator());
            $reordered = bestOfRankOutcome($matches, $calculator(), new Randomizer(new Mt19937($seed + 1000)));

            $sizes = bestOfRankPoolSizes($outcome);
            $unequal = $sizes['smallest'] !== $sizes['largest'];
            $unequalStages += $unequal ? 1 : 0;

            foreach (range(1, $sizes['smallest']) as $rank) {
                foreach (range(1, $sizes['pools']) as $count) {
                    foreach ([null, ...PoolComparison::cases()] as $comparison) {
                        $selector = BestOfRankSelector::ofRank($rank, $count);
                        $selector = $comparison === null ? $selector : $selector->comparedBy($comparison);
                        $case = "{$name}, seed {$seed}, rank {$rank}, count {$count}, " . ($comparison->value ?? 'no method');
                        $expected = bestOfRankExpectation($matches, $outcome, $rank, $count, $comparison, $withWins);

                        foreach (['as built' => $outcome, 'reordered' => $reordered] as $which => $given) {
                            try {
                                $selected = bestOfRankIds($selector->select($given));
                            } catch (UndecidedSelectionException $refusal) {
                                if ($refusal->getRankAcrossPools() === null) {
                                    expect(in_array($refusal->getPool(), $expected['undecidedPools'], true))
                                        ->toBeTrue("{$case}, {$which}: pool {$refusal->getPool()}");
                                    ++$tally['undecided in a pool'];

                                    continue;
                                }

                                $level = $refusal->getParticipantIds();
                                sort($level, SORT_STRING);
                                $expectedLevel = $expected['level'];
                                sort($expectedLevel, SORT_STRING);
                                expect($expected['undecidedPools'])->toBe([], "{$case}, {$which}")
                                    ->and($level)->toBe($expectedLevel, "{$case}, {$which}")
                                    ->and($level)->not->toBe([])
                                    ->and($refusal->getRankAcrossPools())->toBe($rank)
                                    ->and($refusal->getLastPlace() - $refusal->getFirstPlace() + 1)->toBe(count($level))
                                    ->and(array_slice($expected['order'], $refusal->getFirstPlace() - 1, count($level)))
                                    ->toEqualCanonicalizing($level);
                                ++$tally['undecided across pools'];

                                continue;
                            } catch (InvalidConfigurationException $refusal) {
                                expect($refusal->getReason())->toBe(InvalidConfigurationReason::PoolComparisonRequired, "{$case}, {$which}")
                                    ->and($unequal && $comparison === null)->toBeTrue("{$case}, {$which}");
                                ++$tally['no method'];

                                continue;
                            }

                            // Selected: the oracle decides it too, and agrees
                            expect($unequal && $comparison === null)->toBeFalse("{$case}, {$which}")
                                ->and($expected['undecidedPools'])->toBe([], "{$case}, {$which}")
                                ->and($expected['level'])->toBe([], "{$case}, {$which}")
                                ->and($selected)->toBe(array_slice($expected['order'], 0, $count), "{$case}, {$which}")
                                // Never more or fewer than the count
                                ->and($selected)->toHaveCount($count)
                                // Every one of them holds the rank in its pool
                                ->and(array_values(array_diff($selected, $expected['holders'])))->toBe([], "{$case}, {$which}");

                            // No holder outside the selection is as good as one inside it
                            $perEvent = $comparison === PoolComparison::PerEvent;
                            foreach (array_diff($expected['holders'], $selected) as $outside) {
                                foreach ($selected as $inside) {
                                    expect(bestOfRankOracleOrder($expected['records'][$inside], $expected['records'][$outside], $perEvent, $withWins))
                                        ->toBeLessThan(0, "{$case}, {$which}: {$outside} against {$inside}");
                                }
                            }
                            ++$tally['selected'];
                        }
                    }
                }
            }
        }

        // The generator reaches every kind of case, so none of the
        // branches above holds for want of cases
        expect($unequalStages)->toBeGreaterThan(20)
            ->and($tally['selected'])->toBeGreaterThan(500)
            ->and($tally['undecided in a pool'])->toBeGreaterThan(100)
            ->and($tally['undecided across pools'])->toBeGreaterThan(100)
            ->and($tally['no method'])->toBeGreaterThan(100);
    })->with([
        ['points, score difference, scores-for', fn() => new StandingsCalculator(), false],
        ['with wins as a tiebreaker', fn() => new StandingsCalculator(tiebreakers: [new WinsTiebreaker()]), true],
    ]);

    it('gives the count, in the oracle\'s order, when the order as it stands is accepted', function (): void {
        $selections = 0;

        foreach (range(1, 60) as $seed) {
            $matches = bestOfRankGeneratedMatches(new Randomizer(new Mt19937($seed)), $seed % 3 === 0);
            $outcome = bestOfRankOutcome($matches);
            // The pools and the results in another order; the entrant lists as they are
            $reordered = bestOfRankOutcome($matches, shuffle: new Randomizer(new Mt19937($seed + 1000)), shuffleEntrants: false);
            $sizes = bestOfRankPoolSizes($outcome);

            foreach (range(1, $sizes['smallest']) as $rank) {
                foreach (range(1, $sizes['pools']) as $count) {
                    foreach (PoolComparison::cases() as $comparison) {
                        $selector = BestOfRankSelector::ofRank($rank, $count)->comparedBy($comparison)->withTableOrderAccepted();
                        $case = "seed {$seed}, rank {$rank}, count {$count}, {$comparison->value}";
                        $expected = bestOfRankExpectation($matches, $outcome, $rank, $count, $comparison, false);
                        $selected = bestOfRankIds($selector->select($outcome));

                        expect($selected)->toHaveCount($count)
                            ->and(array_values(array_diff($selected, $expected['holders'])))->toBe([], $case)
                            ->and(bestOfRankIds($selector->select($reordered)))->toBe($selected, $case);

                        // Best first, and nobody left out is better than anybody taken
                        $perEvent = $comparison === PoolComparison::PerEvent;
                        $rest = array_values(array_diff($expected['holders'], $selected));
                        foreach ([...$selected, ...$rest] as $place => $id) {
                            foreach (array_slice([...$selected, ...$rest], $place + 1) as $later) {
                                if (in_array($id, $rest, true)) {
                                    continue;
                                }
                                expect(bestOfRankOracleOrder($expected['records'][$id], $expected['records'][$later], $perEvent, false))
                                    ->toBeLessThanOrEqual(0, "{$case}: {$id} before {$later}");
                            }
                        }
                        ++$selections;
                    }
                }
            }
        }

        expect($selections)->toBeGreaterThan(1000);
    });
});
