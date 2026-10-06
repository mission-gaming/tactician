<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Standings\BuchholzTiebreaker;
use MissionGaming\Tactician\Standings\RankingStrategy;
use MissionGaming\Tactician\Standings\SonnebornBergerTiebreaker;
use MissionGaming\Tactician\Standings\StandingEntry;
use MissionGaming\Tactician\Standings\Standings;
use MissionGaming\Tactician\Standings\StandingsCalculator;
use MissionGaming\Tactician\Standings\TiebreakerInterface;
use MissionGaming\Tactician\Standings\TiedSet;
use MissionGaming\Tactician\Standings\WinDrawLossRanking;
use MissionGaming\Tactician\Standings\WinsTiebreaker;
use Random\Engine\Mt19937;
use Random\Randomizer;

/*
 * Property tests for Standings::getTiedSets().
 *
 * The expectation is not read from the table. Each generated result set is
 * plain data (who met whom, who won, the scores), and tiedSetOracle() works
 * out from that data alone, in arithmetic of its own, the figures a table
 * compares before the final fallback: the ranking value, each configured
 * tiebreaker in order, the score difference and scores-for. Two participants
 * are tied when those figures are all equal. A tied set's first position is
 * one more than the number of participants with better figures.
 *
 * The ranking values used are whole numbers and halves, so every sum is exact
 * and "equal" has one meaning.
 */

/**
 * A generated field with a mixed fallback: some seeds missing, some shared,
 * labels that repeat and differ in case, ids in no particular order.
 *
 * @return list<Participant>
 */
function tiedSetField(Randomizer $rng): array
{
    $count = $rng->getInt(2, 10);
    $labels = ['Amy', 'amy', 'Bob', 'Player 2', 'Player 10', 'Zed'];

    $participants = [];
    foreach ($rng->shuffleArray(range(1, $count)) as $number) {
        $participants[] = new Participant(
            "p{$number}",
            $labels[$rng->getInt(0, count($labels) - 1)],
            $rng->getInt(0, 2) === 0 ? null : $rng->getInt(1, $count)
        );
    }

    return $participants;
}

/**
 * Generated games as plain data: the two ids, the winner's id or null for a
 * draw, and the two scores or null when the game was recorded without any.
 * Scores are small so that level figures are common.
 *
 * @param list<Participant> $participants
 *
 * @return list<array{home: string, away: string, winner: ?string, scores: ?array{int, int}}>
 */
function tiedSetGames(Randomizer $rng, array $participants): array
{
    // 0: no game has scores, 1: every game has, 2: a mix
    $scoring = $rng->getInt(0, 2);
    // How many of ten pairings are played; 0 leaves the table without results
    $density = $rng->getInt(0, 10);
    $legs = $rng->getInt(1, 2);
    // One table in three is a single round: each participant plays at most
    // once, which leaves several groups level at different positions
    $singleRound = $rng->getInt(0, 2) === 0;
    if ($singleRound) {
        $legs = 1;
    }

    $games = [];
    for ($leg = 1; $leg <= $legs; ++$leg) {
        foreach ($participants as $i => $home) {
            foreach (array_slice($participants, $i + 1, $singleRound ? 1 : null) as $away) {
                if ($singleRound ? $i % 2 === 1 : $rng->getInt(1, 10) > $density) {
                    continue;
                }

                $scored = $scoring === 1 || ($scoring === 2 && $rng->getInt(0, 1) === 1);
                if ($scored) {
                    $scores = [$rng->getInt(0, 1), $rng->getInt(0, 1)];
                    $winner = match ($scores[0] <=> $scores[1]) {
                        1 => $home->getId(),
                        -1 => $away->getId(),
                        default => null,
                    };
                } else {
                    $scores = null;
                    $winner = [$home->getId(), $away->getId(), null][$rng->getInt(0, 2)];
                }

                $games[] = ['home' => $home->getId(), 'away' => $away->getId(), 'winner' => $winner, 'scores' => $scores];
            }
        }
    }

    return $games;
}

/**
 * The games as the results the calculator reads.
 *
 * @param list<Participant> $participants
 * @param list<array{home: string, away: string, winner: ?string, scores: ?array{int, int}}> $games
 *
 * @return list<Result>
 */
function tiedSetResults(array $participants, array $games): array
{
    $byId = [];
    foreach ($participants as $participant) {
        $byId[$participant->getId()] = $participant;
    }

    $results = [];
    foreach ($games as $game) {
        $results[] = new Result(
            new Event([$byId[$game['home']], $byId[$game['away']]]),
            $game['winner'] === null ? null : $byId[$game['winner']],
            $game['scores'] === null ? [] : [$game['home'] => $game['scores'][0], $game['away'] => $game['scores'][1]]
        );
    }

    return $results;
}

/**
 * For each participant id, the figures compared before the fallback, in the
 * order they are compared, worked out from the games.
 *
 * @param list<Participant> $participants
 * @param list<array{home: string, away: string, winner: ?string, scores: ?array{int, int}}> $games
 * @param array{float, float, float} $values What a win, a draw and a loss are worth
 * @param list<string> $chain The names of the configured tiebreakers, in order
 *
 * @return array<string, list<float>>
 */
function tiedSetOracle(array $participants, array $games, array $values, array $chain): array
{
    [$winValue, $drawValue, $lossValue] = $values;

    $record = [];
    foreach ($participants as $participant) {
        $record[$participant->getId()] = ['wins' => 0, 'draws' => 0, 'losses' => 0, 'for' => 0, 'against' => 0];
    }

    foreach ($games as $game) {
        foreach ([[$game['home'], $game['away'], 0, 1], [$game['away'], $game['home'], 1, 0]] as [$id, $opponent, $own, $their]) {
            if ($game['winner'] === null) {
                ++$record[$id]['draws'];
            } elseif ($game['winner'] === $id) {
                ++$record[$id]['wins'];
            } else {
                ++$record[$id]['losses'];
            }

            if ($game['scores'] !== null) {
                $record[$id]['for'] += $game['scores'][$own];
                $record[$id]['against'] += $game['scores'][$their];
            }
        }
    }

    $points = [];
    foreach ($record as $id => $line) {
        $points[$id] = $line['wins'] * $winValue + $line['draws'] * $drawValue + $line['losses'] * $lossValue;
    }

    $opponentsPoints = array_fill_keys(array_keys($record), 0.0);
    $beatenPoints = array_fill_keys(array_keys($record), 0.0);
    foreach ($games as $game) {
        foreach ([[$game['home'], $game['away']], [$game['away'], $game['home']]] as [$id, $opponent]) {
            $opponentsPoints[$id] += $points[$opponent];
            if ($game['winner'] === null) {
                $beatenPoints[$id] += $points[$opponent] / 2;
            } elseif ($game['winner'] === $id) {
                $beatenPoints[$id] += $points[$opponent];
            }
        }
    }

    $figures = [];
    foreach ($record as $id => $line) {
        $key = [(float) $points[$id]];
        foreach ($chain as $name) {
            $key[] = match ($name) {
                'wins' => (float) $line['wins'],
                'buchholz' => (float) $opponentsPoints[$id],
                'sonneborn-berger' => (float) $beatenPoints[$id],
                default => throw new LogicException("No oracle for the tiebreaker {$name}"),
            };
        }
        $key[] = (float) ($line['for'] - $line['against']);
        $key[] = (float) $line['for'];

        $figures[(string) $id] = $key;
    }

    return $figures;
}

/**
 * The table order the documented rule gives: better figures first, then the
 * fallback of the decision record on a total standings order (seed, lower
 * first and unseeded last; label in case-insensitive natural order; id).
 *
 * @param list<Participant> $participants
 * @param array<string, list<float>> $figures
 *
 * @return list<string>
 */
function tiedSetReferenceOrder(array $participants, array $figures): array
{
    usort($participants, static function (Participant $a, Participant $b) use ($figures): int {
        $byFigures = $figures[$b->getId()] <=> $figures[$a->getId()];
        if ($byFigures !== 0) {
            return $byFigures;
        }

        $bySeed = ($a->getSeed() ?? PHP_INT_MAX) <=> ($b->getSeed() ?? PHP_INT_MAX);
        if ($bySeed !== 0) {
            return $bySeed;
        }

        $byLabel = strnatcasecmp($a->getLabel(), $b->getLabel());

        return $byLabel !== 0 ? $byLabel : $a->getId() <=> $b->getId();
    });

    return array_map(static fn(Participant $participant): string => $participant->getId(), $participants);
}

/**
 * The tied sets the definition gives: every group of two or more participants
 * with the same figures, its ids sorted, with the positions it spans.
 *
 * @param array<string, list<float>> $figures
 *
 * @return list<array{ids: list<string>, first: int, last: int}>
 */
function tiedSetExpectation(array $figures): array
{
    $expected = [];
    $grouped = [];
    foreach ($figures as $id => $key) {
        if (isset($grouped[$id])) {
            continue;
        }

        $ids = [];
        $better = 0;
        foreach ($figures as $otherId => $otherKey) {
            if ($otherKey === $key) {
                $ids[] = (string) $otherId;
                $grouped[$otherId] = true;
            } elseif (($otherKey <=> $key) === 1) {
                ++$better;
            }
        }

        if (count($ids) >= 2) {
            sort($ids);
            $expected[] = ['ids' => $ids, 'first' => $better + 1, 'last' => $better + count($ids)];
        }
    }

    usort($expected, static fn(array $a, array $b): int => $a['first'] <=> $b['first']);

    return $expected;
}

/**
 * @param list<string> $chain
 *
 * @return list<TiebreakerInterface>
 */
function tiedSetChain(array $chain): array
{
    return array_map(static fn(string $name): TiebreakerInterface => match ($name) {
        'wins' => new WinsTiebreaker(),
        'buchholz' => new BuchholzTiebreaker(),
        'sonneborn-berger' => new SonnebornBergerTiebreaker(),
        default => throw new LogicException("Unknown tiebreaker {$name}"),
    }, $chain);
}

/**
 * The ids p1 to p<count>.
 *
 * @return list<string>
 */
function tiedSetIds(int $count): array
{
    return array_map(static fn(int $number): string => "p{$number}", range(1, $count));
}

$rankings = [
    'the default ranking' => [new WinDrawLossRanking(), [3.0, 1.0, 0.0]],
    '3/1/0' => [WinDrawLossRanking::threeOneZero(), [3.0, 1.0, 0.0]],
    '1/half/0' => [WinDrawLossRanking::oneHalfZero(), [1.0, 0.5, 0.0]],
    'a ranking from plain data with a value for a loss' => [
        WinDrawLossRanking::fromArray(['win' => 2, 'draw' => 1, 'loss' => 0.5]),
        [2.0, 1.0, 0.5],
    ],
];

$chains = [
    'no tiebreakers' => [],
    'wins' => ['wins'],
    'Buchholz' => ['buchholz'],
    'Sonneborn-Berger' => ['sonneborn-berger'],
    'wins, then Buchholz' => ['wins', 'buchholz'],
    'Buchholz, then Sonneborn-Berger, then wins' => ['buchholz', 'sonneborn-berger', 'wins'],
];

$configurations = [];
foreach ($rankings as $rankingName => [$ranking, $values]) {
    foreach ($chains as $chainName => $chain) {
        $configurations["{$rankingName}, {$chainName}"] = [$ranking, $values, $chain];
    }
}

it('reports exactly the groups that only the fallback orders, for any results', function (
    WinDrawLossRanking $ranking,
    array $values,
    array $chain
): void {
    /** @var array{float, float, float} $values */
    /** @var list<string> $chain */
    $calculator = new StandingsCalculator($ranking, tiedSetChain($chain));

    $tablesWithATie = 0;
    $tablesWithoutATie = 0;
    $tablesWithSeveralSets = 0;
    $setsBelowFirstPosition = 0;

    for ($seed = 1; $seed <= 150; ++$seed) {
        $rng = new Randomizer(new Mt19937($seed));
        $participants = tiedSetField($rng);
        $games = tiedSetGames($rng, $participants);
        $results = tiedSetResults($participants, $games);

        $figures = tiedSetOracle($participants, $games, $values, $chain);
        $referenceOrder = tiedSetReferenceOrder($participants, $figures);
        $expected = tiedSetExpectation($figures);

        $standings = $calculator->calculate($participants, $results);
        $tiedSets = $standings->getTiedSets();
        $context = "seed {$seed}";

        // (e) The table is the one the documented rule gives, and its
        // accessors agree with it
        $order = array_map(
            static fn(StandingEntry $entry): string => $entry->getParticipant()->getId(),
            $standings->getEntries()
        );
        expect($order)->toBe($referenceOrder, $context)
            ->and(array_is_list($standings->getEntries()))->toBeTrue($context)
            ->and(iterator_to_array($standings))->toBe($standings->getEntries(), $context)
            ->and(count($standings))->toBe(count($participants), $context);

        foreach ($standings->getEntries() as $index => $entry) {
            $participant = $entry->getParticipant();
            $key = $figures[$participant->getId()];

            expect($standings->getPosition($participant))->toBe($index + 1, $context)
                ->and($standings->getEntryFor($participant))->toBe($entry, $context)
                ->and($entry->getRankingValue())->toBe($key[0], $context)
                ->and(array_keys($entry->getTiebreakers()))->toBe($chain, $context)
                ->and(array_values($entry->getTiebreakers()))->toBe(array_slice($key, 1, count($chain)), $context)
                ->and($entry->getScoreDifference())->toBe($key[count($chain) + 1], $context)
                ->and($entry->getScoreFor())->toBe($key[count($chain) + 2], $context);
        }

        // (a) Each set has at least two entries and occupies consecutive
        // positions; no participant is in two sets
        $seen = [];
        $actual = [];
        foreach ($tiedSets as $tiedSet) {
            $ids = [];
            foreach ($tiedSet->getEntries() as $offset => $entry) {
                $id = $entry->getParticipant()->getId();
                expect($seen)->not->toHaveKey($id, $context)
                    ->and($standings->getPosition($entry->getParticipant()))->toBe($tiedSet->getFirstPosition() + $offset, $context)
                    ->and($entry)->toBe($standings->getEntries()[$tiedSet->getFirstPosition() - 1 + $offset], $context);
                $seen[$id] = true;
                $ids[] = $id;
            }

            expect(count($ids))->toBeGreaterThanOrEqual(2, $context)
                ->and(count($tiedSet))->toBe(count($ids), $context)
                ->and($tiedSet->getLastPosition())->toBe($tiedSet->getFirstPosition() + count($ids) - 1, $context);

            // (b) Inside a set every figure compared before the fallback is level
            foreach ($ids as $id) {
                expect($figures[$id])->toBe($figures[$ids[0]], $context);
            }

            // (c) The entry on either side of a set differs in some figure
            foreach ([$tiedSet->getFirstPosition() - 2, $tiedSet->getLastPosition()] as $neighbour) {
                if (isset($order[$neighbour])) {
                    expect($figures[$order[$neighbour]])->not->toBe($figures[$ids[0]], $context);
                }
            }

            $inTableOrder = $ids;
            sort($ids);
            $actual[] = ['ids' => $ids, 'first' => $tiedSet->getFirstPosition(), 'last' => $tiedSet->getLastPosition()];

            // Inside a set the order is the table's
            expect($inTableOrder)->toBe(array_slice($order, $tiedSet->getFirstPosition() - 1, count($ids)), $context);
        }

        // The sets are the ones the definition gives, none missing, in table order
        expect($actual)->toBe($expected, $context);

        // (d) Neither the order of the participants nor that of the results matters
        $shuffled = $calculator->calculate($rng->shuffleArray($participants), $rng->shuffleArray($results));
        expect($shuffled->getEntries())->toEqual($standings->getEntries(), $context)
            ->and($shuffled->getTiedSets())->toEqual($tiedSets, $context);

        $tablesWithATie += $expected === [] ? 0 : 1;
        $tablesWithoutATie += $expected === [] ? 1 : 0;
        $tablesWithSeveralSets += count($expected) > 1 ? 1 : 0;
        $setsBelowFirstPosition += count(array_filter($expected, static fn(array $set): bool => $set['first'] > 1));
    }

    // The generated tables cover each shape, so no assertion above is vacuous
    expect($tablesWithATie)->toBeGreaterThan(20)
        ->and($tablesWithoutATie)->toBeGreaterThan(20)
        ->and($tablesWithSeveralSets)->toBeGreaterThan(10)
        ->and($setsBelowFirstPosition)->toBeGreaterThan(20);
})->with($configurations);

/*
 * Agreement with the calculator for any float, by behaviour.
 *
 * The oracle above needs exact sums, so it covers whole numbers and halves.
 * This test needs no arithmetic. It builds the same table twice with the
 * seeds reversed and nothing else changed. Every participant has a seed of
 * its own, so the fallback always decides at the seed, and reversing the
 * seeds reverses the order of exactly the pairs that nothing before the
 * fallback orders. Two participants are therefore tied when, and only when,
 * their order in the two tables differs. That is read from the order of the
 * table alone: no figure is compared here.
 *
 * The ranking strategy and the tiebreakers are stand-ins that return stated
 * values, drawn from floats that expose a comparison with a tolerance or by
 * text: sums with no exact binary form, neighbouring floats, both zeros,
 * the infinities and the largest float. Scores are fractions as well, so the
 * score difference and scores-for are sums of that kind too. NAN is left
 * out: a table that holds one has no defined order (see the unit tests).
 */
it('reports as tied exactly the pairs whose order follows the fallback, for any float values', function (array $names): void {
    /** @var list<string> $names */
    $pool = [
        0.0, -0.0, 0.1 + 0.2, 0.3, 0.1 + 0.7, 0.8, 1.0, 1.0 + PHP_FLOAT_EPSILON,
        -0.3, PHP_FLOAT_MAX, INF, -INF,
    ];
    $scores = [0, 1, 2, 0.1, 0.2, 0.3, 0.7];

    $tiedPairs = 0;
    $separatedPairs = 0;
    $tiedOnAnInexactSum = 0;
    $separatedByLessThanATolerance = 0;
    $setsOfThreeOrMore = 0;

    for ($seed = 1; $seed <= 400; ++$seed) {
        $rng = new Randomizer(new Mt19937($seed));
        $count = $rng->getInt(2, 7);
        $ids = tiedSetIds($count);

        // A few values per table, so that equal figures are common
        $draw = static function () use ($rng, $pool, $ids): array {
            $few = array_slice($rng->shuffleArray($pool), 0, $rng->getInt(1, 3));
            $values = [];
            foreach ($ids as $id) {
                $values[$id] = $few[$rng->getInt(0, count($few) - 1)];
            }

            return $values;
        };

        $rankingValues = $draw();
        $ranking = new class ($rankingValues) implements RankingStrategy {
            /** @param array<string, float> $values */
            public function __construct(private array $values) {}

            #[Override]
            public function rank(Participant $participant, array $results): float
            {
                return $this->values[$participant->getId()];
            }
        };

        $tiebreakers = [];
        foreach ($names as $name) {
            $tiebreakers[] = new class ($name, $draw()) implements TiebreakerInterface {
                /** @param array<string, float> $values */
                public function __construct(private readonly string $name, private array $values) {}

                #[Override]
                public function getName(): string
                {
                    return $this->name;
                }

                #[Override]
                public function calculate(Participant $participant, array $results, array $entries): float
                {
                    return $this->values[$participant->getId()];
                }
            };
        }

        $games = [];
        $density = $rng->getInt(0, 6);
        foreach ($ids as $i => $home) {
            foreach (array_slice($ids, $i + 1) as $away) {
                if ($rng->getInt(1, 10) <= $density) {
                    $games[] = [
                        $home,
                        $away,
                        $scores[$rng->getInt(0, count($scores) - 1)],
                        $scores[$rng->getInt(0, count($scores) - 1)],
                    ];
                }
            }
        }

        $calculator = new StandingsCalculator($ranking, $tiebreakers);
        $table = static function (bool $reversed) use ($calculator, $ids, $games, $count): Standings {
            $participants = [];
            foreach ($ids as $index => $id) {
                $participants[$id] = new Participant($id, 'Same', $reversed ? $count - $index : $index + 1);
            }

            $results = [];
            foreach ($games as [$home, $away, $homeScore, $awayScore]) {
                $results[] = new Result(
                    new Event([$participants[$home], $participants[$away]]),
                    null,
                    [$home => $homeScore, $away => $awayScore]
                );
            }

            return $calculator->calculate(array_values($participants), $results);
        };

        $standings = $table(false);
        $reversed = $table(true);
        $context = "seed {$seed}";

        $position = [];
        $reversedPosition = [];
        $entries = [];
        foreach (array_values($standings->getEntries()) as $index => $entry) {
            $position[$entry->getParticipant()->getId()] = $index;
            $entries[$entry->getParticipant()->getId()] = $entry;
        }
        foreach (array_values($reversed->getEntries()) as $index => $entry) {
            $reversedPosition[$entry->getParticipant()->getId()] = $index;
        }

        $setOf = [];
        foreach ($standings->getTiedSets() as $number => $tiedSet) {
            foreach ($tiedSet->getParticipants() as $participant) {
                $setOf[$participant->getId()] = $number;
            }
            $setsOfThreeOrMore += count($tiedSet) >= 3 ? 1 : 0;
        }

        foreach ($ids as $i => $one) {
            foreach (array_slice($ids, $i + 1) as $other) {
                $orderFollowsTheFallback = ($position[$one] < $position[$other]) !== ($reversedPosition[$one] < $reversedPosition[$other]);
                $inOneSet = isset($setOf[$one], $setOf[$other]) && $setOf[$one] === $setOf[$other];

                // Every pair, adjacent or not: a level pair that the table
                // kept apart would fail here
                expect($inOneSet)->toBe($orderFollowsTheFallback, "{$context}, {$one} and {$other}")
                    ->and($entries[$one]->isLevelWith($entries[$other]))->toBe($orderFollowsTheFallback, "{$context}, {$one} and {$other}")
                    ->and($entries[$other]->isLevelWith($entries[$one]))->toBe($orderFollowsTheFallback, "{$context}, {$other} and {$one}");

                $gap = abs($rankingValues[$one] - $rankingValues[$other]);
                $tiedPairs += $orderFollowsTheFallback ? 1 : 0;
                $separatedPairs += $orderFollowsTheFallback ? 0 : 1;
                $tiedOnAnInexactSum += $orderFollowsTheFallback && $rankingValues[$one] === 0.1 + 0.2 ? 1 : 0;
                $separatedByLessThanATolerance += !$orderFollowsTheFallback && $gap > 0.0 && $gap < 1e-9 ? 1 : 0;
            }
        }

        // The same sets at the same positions under either fallback; only
        // the order inside a set differs
        $summary = static fn(Standings $table): array => array_map(static function (TiedSet $tiedSet): array {
            $members = array_map(static fn(Participant $participant): string => $participant->getId(), $tiedSet->getParticipants());
            sort($members);

            return [$members, $tiedSet->getFirstPosition(), $tiedSet->getLastPosition()];
        }, $table->getTiedSets());
        expect($summary($reversed))->toBe($summary($standings), $context);
    }

    // Both answers occur, on the values that matter, so nothing above is vacuous
    expect($tiedPairs)->toBeGreaterThan(200)
        ->and($separatedPairs)->toBeGreaterThan(200)
        ->and($tiedOnAnInexactSum)->toBeGreaterThan(5)
        ->and($separatedByLessThanATolerance)->toBeGreaterThan(5)
        ->and($setsOfThreeOrMore)->toBeGreaterThan(20);
})->with([
    'no tiebreakers' => [[]],
    'one tiebreaker' => [['first']],
    'two tiebreakers' => [['first', 'second']],
    'two tiebreakers that share a name' => [['same', 'same']],
]);
