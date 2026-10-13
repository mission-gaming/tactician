<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Standings\Adjustment;
use MissionGaming\Tactician\Standings\BuchholzTiebreaker;
use MissionGaming\Tactician\Standings\ByeTreatment;
use MissionGaming\Tactician\Standings\SonnebornBergerTiebreaker;
use MissionGaming\Tactician\Standings\StandingEntry;
use MissionGaming\Tactician\Standings\Standings;
use MissionGaming\Tactician\Standings\StandingsCalculator;
use MissionGaming\Tactician\Standings\TiebreakerChain;
use MissionGaming\Tactician\Standings\WinDrawLossRanking;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

// A table with byes, adjustments and every tiebreaker of the library, over
// generated result sets, held to a calculation of its own.
//
// The oracle below works on plain arrays and shares no code with the
// library: it adds the records up, values a bye, and asks the tiebreakers
// one after the other, because a tiebreaker between the tied participants
// is computed for the participants that are level on everything before the
// run of such tiebreakers it is in: those that follow one another are one
// small table of the same tied sets.
// Every amount generated is a multiple of a quarter, which a float holds
// exactly, so the figures are the same in whatever order they are added.

const BYE_TABLE_CASES = 240;

/**
 * A generated case: a field, games between its participants (a pair may
 * meet twice, or not at all), byes, adjustments, a scale with a value for a
 * bye, and a chain of tiebreakers.
 *
 * @return array{
 *     participants: list<Participant>,
 *     games: list<array{a: string, b: string, winner: ?string, scores: ?array{int, int}}>,
 *     byes: array<string, int>,
 *     adjustments: list<array{id: string, amount: float}>,
 *     scale: array{float, float, float},
 *     bye: float,
 *     perBye: int,
 *     chain: list<string|array{tiebreaker: string, byes: string}>
 * }
 */
function byeTableCase(int $seed): array
{
    $rng = new Randomizer(new Xoshiro256StarStar($seed));
    $count = $rng->getInt(3, 7);
    // One case in three is made to be level: most games drawn, the same
    // number of byes for everyone and no adjustment, so that three or more
    // participants share a total and the tiebreakers have sets to split
    $level = $seed % 3 === 0;

    $participants = [];
    for ($i = 1; $i <= $count; ++$i) {
        // Seeds now and then, and two participants may share one
        $participants[] = new Participant("p{$i}", "P{$i}", $rng->getInt(0, 2) === 0 ? $rng->getInt(1, 3) : null);
    }

    $games = [];
    for ($i = 0; $i < $count; ++$i) {
        for ($j = $i + 1; $j < $count; ++$j) {
            for ($meetings = [0, 1, 1, 1, 2][$rng->getInt(0, 4)]; $meetings > 0; --$meetings) {
                [$a, $b] = $rng->getInt(0, 1) === 1 ? [$i, $j] : [$j, $i];
                $outcome = $level && $rng->getInt(0, 3) > 0 ? 0 : $rng->getInt(0, 3);
                $games[] = [
                    'a' => $participants[$a]->getId(),
                    'b' => $participants[$b]->getId(),
                    'winner' => match ($outcome) {
                        0 => null,
                        1 => $participants[$b]->getId(),
                        default => $participants[$a]->getId(),
                    },
                    // Scores that need not agree with the winner: the table
                    // adds them up as they are recorded
                    'scores' => $rng->getInt(0, 3) === 0 ? null : [$rng->getInt(0, 3), $rng->getInt(0, 3)],
                ];
            }
        }
    }

    $byes = [];
    $everyone = $rng->getInt(0, 1);
    foreach ($participants as $participant) {
        $had = $level ? $everyone : [0, 0, 1, 1, 2][$rng->getInt(0, 4)];
        if ($had > 0) {
            $byes[$participant->getId()] = $had;
        }
    }

    $adjustments = [];
    for ($n = $level ? 0 : [0, 0, 1, 2, 3][$rng->getInt(0, 4)]; $n > 0; --$n) {
        $adjustments[] = [
            'id' => $participants[$rng->getInt(0, $count - 1)]->getId(),
            'amount' => $rng->getInt(-12, 12) / 4.0,
        ];
    }

    $scale = [[3.0, 1.0, 0.0], [1.0, 0.5, 0.0], [2.0, 1.0, -1.0]][$rng->getInt(0, 2)];

    $chain = [];
    foreach ($rng->shuffleArray(TiebreakerChain::identifiers()) as $identifier) {
        $leftOut = $rng->getInt(0, 2);
        $drawn = $rng->getInt(0, 2);
        if ($leftOut > 0) {
            continue;
        }
        $treatment = ByeTreatment::cases()[$drawn];
        $chain[] = in_array($identifier, ['buchholz', 'sonneborn-berger'], true) && $treatment !== ByeTreatment::Uncounted
            ? ['tiebreaker' => $identifier, 'byes' => $treatment->value]
            : $identifier;
    }

    return [
        'participants' => $participants,
        'games' => $games,
        'byes' => $byes,
        'adjustments' => $adjustments,
        'scale' => $scale,
        // The value of a win, of a draw, nothing, and two amounts that are
        // none of the three
        'bye' => [$scale[0], $scale[1], 0.0, 0.25, 1.5][$rng->getInt(0, 4)],
        'perBye' => [1, 1, 2][$rng->getInt(0, 2)],
        'chain' => $chain,
    ];
}

/**
 * The games as results. An event has no round, so two games between the
 * same two participants are two events.
 *
 * @param list<Participant> $participants
 * @param list<array{a: string, b: string, winner: ?string, scores: ?array{int, int}}> $games
 *
 * @return list<Result>
 */
function byeTableResults(array $participants, array $games): array
{
    $byId = [];
    foreach ($participants as $participant) {
        $byId[$participant->getId()] = $participant;
    }

    $results = [];
    foreach ($games as $game) {
        $results[] = new Result(
            new Event([$byId[$game['a']], $byId[$game['b']]]),
            $game['winner'] === null ? null : $byId[$game['winner']],
            $game['scores'] === null ? [] : [$game['a'] => $game['scores'][0], $game['b'] => $game['scores'][1]]
        );
    }

    return $results;
}

/**
 * The calculator of a case.
 *
 * @param array{scale: array{float, float, float}, bye: float, chain: list<string|array{tiebreaker: string, byes: string}>} $case
 *
 * @throws InvalidConfigurationException When the chain of the case is not one (not expected here)
 */
function byeTableCalculator(array $case): StandingsCalculator
{
    return new StandingsCalculator(
        new WinDrawLossRanking($case['scale'][0], $case['scale'][1], $case['scale'][2], $case['bye']),
        TiebreakerChain::fromIdentifiers($case['chain'])
    );
}

/**
 * The table of a case, as the library calculates it in one call.
 *
 * @param array{
 *     participants: list<Participant>,
 *     games: list<array{a: string, b: string, winner: ?string, scores: ?array{int, int}}>,
 *     byes: array<string, int>,
 *     adjustments: list<array{id: string, amount: float}>,
 *     scale: array{float, float, float},
 *     bye: float,
 *     perBye: int,
 *     chain: list<string|array{tiebreaker: string, byes: string}>
 * } $case
 *
 * @throws InvalidConfigurationException When the calculator refuses the table (not expected here)
 */
function byeTableOf(array $case): Standings
{
    return byeTableCalculator($case)->calculateWith(
        $case['participants'],
        byeTableResults($case['participants'], $case['games']),
        $case['byes'],
        array_map(
            static fn(array $adjustment): Adjustment => new Adjustment($adjustment['id'], $adjustment['amount']),
            $case['adjustments']
        ),
        $case['perBye']
    );
}

/**
 * The figures of the given participants from the given games alone: the
 * record, the scores and the value of the results.
 *
 * @param list<string> $ids
 * @param list<array{a: string, b: string, winner: ?string, scores: ?array{int, int}}> $games
 * @param array{float, float, float} $scale
 *
 * @return array<string, array{played: int, wins: int, draws: int, losses: int, for: float, against: float, results: float}>
 */
function byeTableFigures(array $ids, array $games, array $scale): array
{
    // One book per figure, each keyed by participant ID
    $played = $wins = $draws = $losses = array_fill_keys($ids, 0);
    $for = $against = $results = array_fill_keys($ids, 0.0);

    foreach ($games as $game) {
        foreach ([[$game['a'], 0, 1], [$game['b'], 1, 0]] as [$self, $own, $their]) {
            ++$played[$self];
            if ($game['winner'] === null) {
                ++$draws[$self];
                $results[$self] += $scale[1];
            } elseif ($game['winner'] === $self) {
                ++$wins[$self];
                $results[$self] += $scale[0];
            } else {
                ++$losses[$self];
                $results[$self] += $scale[2];
            }

            if ($game['scores'] !== null) {
                $for[$self] += $game['scores'][$own];
                $against[$self] += $game['scores'][$their];
            }
        }
    }

    $figures = [];
    foreach ($ids as $id) {
        $figures[$id] = [
            'played' => $played[$id],
            'wins' => $wins[$id],
            'draws' => $draws[$id],
            'losses' => $losses[$id],
            'for' => $for[$id],
            'against' => $against[$id],
            'results' => $results[$id],
        ];
    }

    return $figures;
}

/**
 * The value of one measure for one participant, from the games and the
 * figures given: those of the whole table, or those of a tied set.
 *
 * @param list<array{a: string, b: string, winner: ?string, scores: ?array{int, int}}> $games
 * @param array<string, array{played: int, wins: int, draws: int, losses: int, for: float, against: float, results: float}> $figures
 * @param array<string, float> $earned What each participant earned, byes included
 * @param array<string, int> $byes
 */
function byeTableMeasure(string $measure, string $treatment, string $id, array $games, array $figures, array $earned, array $byes): float
{
    if (!in_array($measure, ['buchholz', 'sonneborn-berger'], true)) {
        return match ($measure) {
            'wins' => (float) $figures[$id]['wins'],
            'score-difference' => $figures[$id]['for'] - $figures[$id]['against'],
            'scores-for' => $figures[$id]['for'],
            default => $figures[$id]['results'],
        };
    }

    $value = 0.0;
    foreach ($games as $game) {
        if ($game['a'] !== $id && $game['b'] !== $id) {
            continue;
        }
        $opponent = $game['a'] === $id ? $game['b'] : $game['a'];
        $worth = $treatment === 'uncounted' ? $figures[$opponent]['results'] : $earned[$opponent];

        $value += match (true) {
            $measure === 'buchholz', $game['winner'] === $id => $worth,
            $game['winner'] === null => 0.5 * $worth,
            default => 0.0,
        };
    }

    return $treatment === 'own-ranking-value' ? $value + ($byes[$id] ?? 0) * $earned[$id] : $value;
}

/**
 * Every line of the table of a case, in the order of the table, worked out
 * from the plain data alone.
 *
 * The order: the total, each tiebreaker in order, the score difference and
 * scores-for, all higher first; then what was earned and what the results
 * are worth, higher first; then the seed (lower first, none last) and the
 * ID.
 *
 * @param array{
 *     participants: list<Participant>,
 *     games: list<array{a: string, b: string, winner: ?string, scores: ?array{int, int}}>,
 *     byes: array<string, int>,
 *     adjustments: list<array{id: string, amount: float}>,
 *     scale: array{float, float, float},
 *     bye: float,
 *     perBye: int,
 *     chain: list<string|array{tiebreaker: string, byes: string}>
 * } $case
 *
 * @return list<array{id: string, played: int, wins: int, draws: int, losses: int, byes: int, for: float, against: float, results: float, earned: float, adjustment: float, total: float, tiebreakers: array<string, float>}>
 */
function byeTableOracle(array $case): array
{
    $ids = [];
    $seeds = [];
    foreach ($case['participants'] as $participant) {
        $ids[] = $participant->getId();
        $seeds[$participant->getId()] = $participant->getSeed() ?? PHP_INT_MAX;
    }

    $figures = byeTableFigures($ids, $case['games'], $case['scale']);

    $earned = $adjustment = $total = $values = [];
    foreach ($ids as $id) {
        $earned[$id] = $figures[$id]['results'] + ($case['byes'][$id] ?? 0) * $case['perBye'] * $case['bye'];
        $adjustment[$id] = 0.0;
    }
    foreach ($case['adjustments'] as $given) {
        $adjustment[$given['id']] += $given['amount'];
    }
    foreach ($ids as $id) {
        $total[$id] = $earned[$id] + $adjustment[$id];
        $values[$id] = [];
    }

    // What the participants were level on before the run of tiebreakers
    // between the tied that is being asked; null outside a run
    $beforeRun = null;
    foreach ($case['chain'] as $entry) {
        $name = is_array($entry) ? $entry['tiebreaker'] : $entry;
        $treatment = is_array($entry) ? $entry['byes'] : 'uncounted';
        $between = str_starts_with($name, 'between-tied:');
        $measure = $between ? substr($name, strlen('between-tied:')) : $name;
        $beforeRun = $between ? ($beforeRun ?? $values) : null;
        $levelBefore = $beforeRun ?? $values;

        $calculated = [];
        foreach ($ids as $id) {
            if (!$between) {
                $calculated[$id] = byeTableMeasure($measure, $treatment, $id, $case['games'], $figures, $earned, $case['byes']);

                continue;
            }

            // The tied set of this participant: everyone level with it on
            // the total and on every tiebreaker before the run this one is
            // in, whatever the earlier ones of the run made of the set
            $set = array_values(array_filter(
                $ids,
                static fn(string $other): bool => $total[$other] === $total[$id] && $levelBefore[$other] === $levelBefore[$id]
            ));
            $among = array_values(array_filter(
                $case['games'],
                static fn(array $game): bool => in_array($game['a'], $set, true) && in_array($game['b'], $set, true)
            ));
            $ofSet = byeTableFigures($set, $among, $case['scale']);
            // No bye is between two participants: what each earned from the
            // others is the value of those results
            $earnedInSet = array_map(static fn(array $line): float => $line['results'], $ofSet);

            $calculated[$id] = byeTableMeasure($measure, 'uncounted', $id, $among, $ofSet, $earnedInSet, []);
        }

        foreach ($ids as $id) {
            $values[$id][$name] = $calculated[$id];
        }
    }

    usort($ids, static function (string $one, string $other) use ($total, $values, $figures, $earned, $seeds): int {
        // Each pair is compared so that the better of the two comes first
        $steps = [[$total[$other], $total[$one]]];
        foreach (array_keys($values[$one]) as $name) {
            $steps[] = [$values[$other][$name], $values[$one][$name]];
        }
        $steps[] = [$figures[$other]['for'] - $figures[$other]['against'], $figures[$one]['for'] - $figures[$one]['against']];
        $steps[] = [$figures[$other]['for'], $figures[$one]['for']];
        $steps[] = [$earned[$other], $earned[$one]];
        $steps[] = [$figures[$other]['results'], $figures[$one]['results']];
        $steps[] = [(float) $seeds[$one], (float) $seeds[$other]];

        foreach ($steps as [$left, $right]) {
            if ($left !== $right) {
                return $left <=> $right;
            }
        }

        return strcmp($one, $other);
    });

    return array_map(static fn(string $id): array => [
        'id' => $id,
        'played' => $figures[$id]['played'],
        'wins' => $figures[$id]['wins'],
        'draws' => $figures[$id]['draws'],
        'losses' => $figures[$id]['losses'],
        'byes' => $case['byes'][$id] ?? 0,
        'for' => $figures[$id]['for'],
        'against' => $figures[$id]['against'],
        'results' => $figures[$id]['results'],
        'earned' => $earned[$id],
        'adjustment' => $adjustment[$id],
        'total' => $total[$id],
        'tiebreakers' => $values[$id],
    ], $ids);
}

/**
 * Every line of a table as plain data, in the shape byeTableOracle() returns.
 *
 * @return list<array{id: string, played: int, wins: int, draws: int, losses: int, byes: int, for: float, against: float, results: float, earned: float, adjustment: float, total: float, tiebreakers: array<string, float>}>
 */
function byeTableLines(Standings $standings): array
{
    return array_values(array_map(static fn(StandingEntry $entry): array => [
        'id' => $entry->getParticipant()->getId(),
        'played' => $entry->getPlayed(),
        'wins' => $entry->getWins(),
        'draws' => $entry->getDraws(),
        'losses' => $entry->getLosses(),
        'byes' => $entry->getByes(),
        'for' => $entry->getScoreFor(),
        'against' => $entry->getScoreAgainst(),
        'results' => $entry->getRankingValueOfResults(),
        'earned' => $entry->getEarnedRankingValue(),
        'adjustment' => $entry->getAdjustment(),
        'total' => $entry->getRankingValue(),
        // 0.0 and -0.0 are one value to the table and to the oracle
        'tiebreakers' => array_map(static fn(float $value): float => $value + 0.0, $entry->getTiebreakers()),
    ], $standings->getEntries()));
}

describe('a table with byes, adjustments and tiebreakers, over generated cases', function (): void {
    it('has the figures and the order an independent calculation gives', function (): void {
        $withByes = $betweenTheTied = $treated = $setsOfThree = 0;

        for ($seed = 1; $seed <= BYE_TABLE_CASES; ++$seed) {
            $case = byeTableCase($seed);
            $oracle = byeTableOracle($case);

            expect(byeTableLines(byeTableOf($case)))->toBe(array_map(
                static fn(array $line): array => [...$line, 'tiebreakers' => array_map(static fn(float $value): float => $value + 0.0, $line['tiebreakers'])],
                $oracle
            ), "case {$seed}");

            $withByes += $case['byes'] === [] ? 0 : 1;
            foreach ($case['chain'] as $entry) {
                $treated += is_array($entry) ? 1 : 0;
                $betweenTheTied += is_string($entry) && str_starts_with($entry, 'between-tied:') ? 1 : 0;
            }

            // A set of three or more level on the total, which a tiebreaker
            // between the tied participants is asked about as one set
            $totals = array_count_values(array_map(static fn(array $line): string => (string) $line['total'], $oracle));
            $setsOfThree += $totals !== [] && max($totals) >= 3 ? 1 : 0;
        }

        // The cases hold what the test is about
        expect($withByes)->toBeGreaterThan(BYE_TABLE_CASES / 2)
            ->and($withByes)->toBeLessThan(BYE_TABLE_CASES)
            ->and($betweenTheTied)->toBeGreaterThan(40)
            ->and($treated)->toBeGreaterThan(20)
            ->and($setsOfThree)->toBeGreaterThan(20);
    });

    it('is the same table for every order of the participants, the results, the byes and the adjustments', function (): void {
        for ($seed = 1; $seed <= BYE_TABLE_CASES; ++$seed) {
            $case = byeTableCase($seed);
            $expected = byeTableLines(byeTableOf($case));
            $rng = new Randomizer(new Xoshiro256StarStar($seed));

            for ($order = 0; $order < 3; ++$order) {
                $byes = [];
                foreach ($rng->shuffleArray(array_keys($case['byes'])) as $id) {
                    $byes[$id] = $case['byes'][$id];
                }

                $shuffled = [
                    ...$case,
                    'participants' => $rng->shuffleArray($case['participants']),
                    'games' => $rng->shuffleArray($case['games']),
                    'byes' => $byes,
                    'adjustments' => $rng->shuffleArray($case['adjustments']),
                ];

                expect(byeTableLines(byeTableOf($shuffled)))->toBe($expected, "case {$seed}, order {$order}");
            }
        }
    });

    it('has figures that add up as documented', function (): void {
        for ($seed = 1; $seed <= BYE_TABLE_CASES; ++$seed) {
            $case = byeTableCase($seed);

            foreach (byeTableOf($case)->getEntries() as $entry) {
                $id = $entry->getParticipant()->getId();
                $byes = $case['byes'][$id] ?? 0;

                expect($entry->getPlayed())->toBe($entry->getWins() + $entry->getDraws() + $entry->getLosses(), "case {$seed}, {$id}")
                    ->and($entry->getByes())->toBe($byes)
                    ->and($entry->getRankingValueOfResults())->toBe(
                        $entry->getWins() * $case['scale'][0] + $entry->getDraws() * $case['scale'][1] + $entry->getLosses() * $case['scale'][2]
                    )
                    ->and($entry->getEarnedRankingValue())->toBe($entry->getRankingValueOfResults() + $byes * $case['perBye'] * $case['bye'])
                    ->and($entry->getRankingValue())->toBe($entry->getEarnedRankingValue() + $entry->getAdjustment());
            }
        }
    });

    // With tiebreakers that read no bye and are not computed between the
    // tied participants, byes and adjustments can be applied to a table
    // that exists, and the table is the one calculated in one call.
    it('is the table the three separate steps give where the tiebreakers allow them', function (): void {
        $compared = 0;

        for ($seed = 1; $seed <= BYE_TABLE_CASES; ++$seed) {
            $case = byeTableCase($seed);
            $case['chain'] = array_values(array_filter(
                $case['chain'],
                static fn(string|array $entry): bool => is_string($entry) && !str_starts_with($entry, 'between-tied:')
            ));
            $calculator = byeTableCalculator($case);

            $stepByStep = $calculator->withAdjustments(
                $calculator->withByesCredited(
                    $calculator->calculate($case['participants'], byeTableResults($case['participants'], $case['games'])),
                    $case['byes'],
                    $case['perBye']
                ),
                array_map(
                    static fn(array $adjustment): Adjustment => new Adjustment($adjustment['id'], $adjustment['amount']),
                    $case['adjustments']
                )
            );

            // Both against the oracle, so that neither is the measure of the other
            expect(byeTableLines($stepByStep))->toBe(byeTableLines(byeTableOf($case)), "case {$seed}")
                ->and(array_column(byeTableLines($stepByStep), 'id'))->toBe(array_column(byeTableOracle($case), 'id'), "case {$seed}");
            ++$compared;
        }

        expect($compared)->toBe(BYE_TABLE_CASES);
    });
});

describe('the bye treatment of a tiebreaker computed from opponents, over generated cases', function (): void {
    /**
     * The table of a generated case with one tiebreaker under one treatment.
     */
    $under = static function (int $seed, string $tiebreaker, ByeTreatment $treatment, bool $withByes = true): Standings {
        $case = byeTableCase($seed);
        $case['chain'] = [
            $treatment === ByeTreatment::Uncounted ? $tiebreaker : ['tiebreaker' => $tiebreaker, 'byes' => $treatment->value],
        ];
        if (!$withByes) {
            $case['byes'] = [];
        }

        return byeTableOf($case);
    };

    it('changes the value of that tiebreaker by exactly what the treatment says, and no other figure', function (string $tiebreaker) use ($under): void {
        $moved = 0;

        for ($seed = 1; $seed <= BYE_TABLE_CASES; ++$seed) {
            $case = byeTableCase($seed);
            $tables = [];
            foreach (ByeTreatment::cases() as $treatment) {
                $tables[$treatment->value] = $under($seed, $tiebreaker, $treatment);
            }

            foreach ($case['participants'] as $participant) {
                $id = $participant->getId();
                $lines = [];
                $values = [];
                foreach ($tables as $treatment => $table) {
                    $entry = $table->getEntryFor($participant);
                    assert($entry instanceof StandingEntry);
                    $values[$treatment] = $entry->getTiebreakerValue($tiebreaker) ?? throw new LogicException('The entry carries no value.');
                    $lines[$treatment] = [
                        $entry->getPlayed(), $entry->getWins(), $entry->getDraws(), $entry->getLosses(), $entry->getByes(),
                        $entry->getScoreFor(), $entry->getScoreAgainst(), $entry->getRankingValueOfResults(),
                        $entry->getEarnedRankingValue(), $entry->getAdjustment(), $entry->getRankingValue(),
                    ];
                }

                // What the credit of their byes adds to the opponents this
                // participant met: in full for Buchholz, by the result for
                // Sonneborn-Berger
                $opponentsByes = 0.0;
                foreach ($case['games'] as $game) {
                    if ($game['a'] !== $id && $game['b'] !== $id) {
                        continue;
                    }
                    $opponent = $game['a'] === $id ? $game['b'] : $game['a'];
                    $weight = match (true) {
                        $tiebreaker === 'buchholz', $game['winner'] === $id => 1.0,
                        $game['winner'] === null => 0.5,
                        default => 0.0,
                    };
                    $opponentsByes += $weight * ($case['byes'][$opponent] ?? 0) * $case['perBye'] * $case['bye'];
                }
                $own = $tables['zero']->getEntryFor($participant);
                assert($own instanceof StandingEntry);

                expect($lines['zero'])->toBe($lines['uncounted'], "case {$seed}, {$id}")
                    ->and($lines['own-ranking-value'])->toBe($lines['uncounted'], "case {$seed}, {$id}")
                    ->and($values['zero'])->toBe($values['uncounted'] + $opponentsByes, "case {$seed}, {$id}: zero")
                    ->and($values['own-ranking-value'])->toBe(
                        $values['zero'] + ($case['byes'][$id] ?? 0) * $own->getEarnedRankingValue(),
                        "case {$seed}, {$id}: own ranking value"
                    );

                $moved += $values['zero'] !== $values['uncounted'] || $values['own-ranking-value'] !== $values['zero'] ? 1 : 0;
            }
        }

        // The treatment is not without effect in the cases generated
        expect($moved)->toBeGreaterThan(100);
    })->with(['buchholz', 'sonneborn-berger']);

    it('changes nothing in a table without byes', function (string $tiebreaker) use ($under): void {
        for ($seed = 1; $seed <= 60; ++$seed) {
            $uncounted = byeTableLines($under($seed, $tiebreaker, ByeTreatment::Uncounted, false));

            expect(byeTableLines($under($seed, $tiebreaker, ByeTreatment::Zero, false)))->toBe($uncounted, "case {$seed}")
                ->and(byeTableLines($under($seed, $tiebreaker, ByeTreatment::OwnRankingValue, false)))->toBe($uncounted, "case {$seed}");
        }
    })->with(['buchholz', 'sonneborn-berger']);

    // The two classes at their default give what they gave before a bye
    // could be treated: an opponent's bye is not in its value
    it('reads no bye at its default, whatever the table credits', function (): void {
        for ($seed = 1; $seed <= 60; ++$seed) {
            $case = byeTableCase($seed);
            $calculator = new StandingsCalculator(
                new WinDrawLossRanking($case['scale'][0], $case['scale'][1], $case['scale'][2], $case['bye']),
                [new BuchholzTiebreaker(), new SonnebornBergerTiebreaker()]
            );
            $results = byeTableResults($case['participants'], $case['games']);

            $withByes = $calculator->calculateWith($case['participants'], $results, $case['byes']);
            $without = $calculator->calculate($case['participants'], $results);

            foreach ($case['participants'] as $participant) {
                expect($withByes->getEntryFor($participant)?->getTiebreakers())
                    ->toBe($without->getEntryFor($participant)?->getTiebreakers(), "case {$seed}");
            }
        }
    });
});
