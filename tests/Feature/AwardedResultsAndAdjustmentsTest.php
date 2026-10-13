<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Award;
use MissionGaming\Tactician\DTO\Decider;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Exceptions\NoValidPairingException;
use MissionGaming\Tactician\Scheduling\DoubleEliminationEngine;
use MissionGaming\Tactician\Scheduling\EliminationOptions;
use MissionGaming\Tactician\Scheduling\SingleEliminationEngine;
use MissionGaming\Tactician\Scheduling\SwissPairingEngine;
use MissionGaming\Tactician\Stage\MatchOutcomeSelector;
use MissionGaming\Tactician\Stage\RecordedRound;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Stage\StageEngineInterface;
use MissionGaming\Tactician\Stage\StageOutcome;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Standings\Adjustment;
use MissionGaming\Tactician\Standings\AwardedResultPolicy;
use MissionGaming\Tactician\Standings\BuchholzTiebreaker;
use MissionGaming\Tactician\Standings\SonnebornBergerTiebreaker;
use MissionGaming\Tactician\Standings\StandingEntry;
use MissionGaming\Tactician\Standings\Standings;
use MissionGaming\Tactician\Standings\StandingsCalculator;
use MissionGaming\Tactician\Standings\TiebreakerInterface;
use MissionGaming\Tactician\Standings\WinDrawLossRanking;
use MissionGaming\Tactician\Standings\WinsTiebreaker;
use MissionGaming\Tactician\Tests\Support\WinsBetweenTheTied;
use Random\Engine\Mt19937;
use Random\Randomizer;

/*
 * Awarded results and adjustments, over generated cases and in the engines.
 *
 * The property tests do not read their expectation from the table. A case is
 * plain data: who met whom, who won, the scores, which results were awarded,
 * and which amounts were added to whom. awardsOracle() works every figure of
 * every line out of that data in arithmetic of its own, and the order of the
 * table with it. Ranking values, scores and amounts are whole numbers and
 * halves, so every sum is exact and "equal" has one meaning.
 */

/**
 * One generated case: a field, the games between its members, adjustments,
 * a ranking scale, tiebreakers and an awarded result policy.
 *
 * @return array{
 *     participants: list<Participant>,
 *     games: list<array{a: string, b: string, winner: ?string, scores: ?array{int, int}, awarded: bool}>,
 *     adjustments: list<array{id: string, amount: float}>,
 *     scale: array{float, float, float},
 *     tiebreakers: list<string>,
 *     countScores: bool,
 *     countBetweenTied: bool
 * }
 */
function awardsCase(int $seed): array
{
    $rng = new Randomizer(new Mt19937($seed));

    $count = $rng->getInt(2, 8);
    $participants = [];
    foreach ($rng->shuffleArray(range(1, $count)) as $number) {
        $participants[] = new Participant(
            "p{$number}",
            "Entrant {$number}",
            $rng->getInt(0, 2) === 0 ? null : $rng->getInt(1, $count)
        );
    }

    // How many of ten pairings have a result; how many of ten are awarded
    $density = $rng->getInt(2, 10);
    $awardedShare = $rng->getInt(0, 6);
    $legs = $rng->getInt(1, 2);

    $games = [];
    for ($leg = 1; $leg <= $legs; ++$leg) {
        foreach ($participants as $i => $first) {
            foreach (array_slice($participants, $i + 1) as $second) {
                if ($rng->getInt(1, 10) > $density) {
                    continue;
                }

                $awarded = $rng->getInt(1, 10) <= $awardedShare;
                $winner = [$first->getId(), $second->getId(), null][$rng->getInt(0, 2)];
                // An awarded result has a nominal score or none; so has a played one
                $scores = $rng->getInt(0, 2) === 0 ? null : [$rng->getInt(0, 3), $rng->getInt(0, 3)];

                $games[] = [
                    'a' => $first->getId(),
                    'b' => $second->getId(),
                    'winner' => $winner,
                    'scores' => $scores,
                    'awarded' => $awarded,
                ];
            }
        }
    }

    $adjustments = [];
    for ($n = $rng->getInt(0, 5); $n > 0; --$n) {
        $adjustments[] = [
            'id' => $participants[$rng->getInt(0, $count - 1)]->getId(),
            'amount' => $rng->getInt(-12, 12) / 2.0,
        ];
    }

    $names = ['wins', 'buchholz', 'sonneborn-berger', WinsBetweenTheTied::NAME];
    $tiebreakers = [];
    foreach ($rng->shuffleArray($names) as $name) {
        if ($rng->getInt(0, 1) === 1) {
            $tiebreakers[] = $name;
        }
    }

    return [
        'participants' => $participants,
        'games' => $games,
        'adjustments' => $adjustments,
        'scale' => [[3.0, 1.0, 0.0], [1.0, 0.5, 0.0], [2.0, 1.0, -1.0]][$rng->getInt(0, 2)],
        'tiebreakers' => $tiebreakers,
        'countScores' => $rng->getInt(0, 1) === 1,
        'countBetweenTied' => $rng->getInt(0, 1) === 1,
    ];
}

/**
 * The games as the results the calculator reads. An event has no round, so
 * two games between the same two participants are two events.
 *
 * @param list<Participant> $participants
 * @param list<array{a: string, b: string, winner: ?string, scores: ?array{int, int}, awarded: bool}> $games
 *
 * @return list<Result>
 */
function awardsResults(array $participants, array $games): array
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
            $game['scores'] === null ? [] : [$game['a'] => $game['scores'][0], $game['b'] => $game['scores'][1]],
            [],
            $game['awarded'] ? new Award('generated') : null
        );
    }

    return $results;
}

/**
 * @param list<array{id: string, amount: float}> $adjustments
 *
 * @return list<Adjustment>
 */
function awardsAdjustments(array $adjustments): array
{
    return array_map(
        static fn(array $adjustment): Adjustment => new Adjustment($adjustment['id'], $adjustment['amount']),
        $adjustments
    );
}

/**
 * The calculator of a case.
 *
 * @param array{float, float, float} $scale
 * @param list<string> $tiebreakers
 */
function awardsCalculator(array $scale, array $tiebreakers, bool $countScores, bool $countBetweenTied): StandingsCalculator
{
    return new StandingsCalculator(
        new WinDrawLossRanking($scale[0], $scale[1], $scale[2]),
        array_map(static fn(string $name): TiebreakerInterface => match ($name) {
            'wins' => new WinsTiebreaker(),
            'buchholz' => new BuchholzTiebreaker(),
            'sonneborn-berger' => new SonnebornBergerTiebreaker(),
            default => new WinsBetweenTheTied(),
        }, $tiebreakers),
        new AwardedResultPolicy($countScores, $countBetweenTied)
    );
}

/**
 * The value of one tiebreaker for one participant, from the plain data.
 *
 * @param list<array{a: string, b: string, winner: ?string, scores: ?array{int, int}, awarded: bool}> $games
 * @param array<string, float> $earned What each participant earned, without an adjustment
 * @param array<string, string> $levelOn What each participant is level on when the tiebreaker is asked
 */
function awardsTiebreakerValue(string $name, string $id, array $games, array $earned, array $levelOn, bool $countBetweenTied): float
{
    $value = 0.0;
    foreach ($games as $game) {
        if ($game['a'] !== $id && $game['b'] !== $id) {
            continue;
        }
        $opponent = $game['a'] === $id ? $game['b'] : $game['a'];
        $won = $game['winner'] === $id;

        // An opponent counts for what it earned: no adjustment is in it
        $value += match ($name) {
            'buchholz' => $earned[$opponent],
            'sonneborn-berger' => match (true) {
                $won => $earned[$opponent],
                $game['winner'] === null => 0.5 * $earned[$opponent],
                default => 0.0,
            },
            // Between the tied participants: a win over one it is level with
            WinsBetweenTheTied::NAME => $won
                && $levelOn[$opponent] === $levelOn[$id]
                && (!$game['awarded'] || $countBetweenTied) ? 1.0 : 0.0,
            default => 0.0,
        };
    }

    return $value;
}

/**
 * Every line of the table of a case, in the order of the table, worked out
 * from the plain data alone.
 *
 * The order is that of a table calculated on its own and then adjusted: the
 * total, each tiebreaker in order, the score difference and scores-for, all
 * higher first. Lines that are level on those keep the order they had
 * before the adjustments, which is what they earned (higher first), then the
 * seed (lower first, none last), then the ID.
 *
 * @param array{
 *     participants: list<Participant>,
 *     games: list<array{a: string, b: string, winner: ?string, scores: ?array{int, int}, awarded: bool}>,
 *     adjustments: list<array{id: string, amount: float}>,
 *     scale: array{float, float, float},
 *     tiebreakers: list<string>,
 *     countScores: bool,
 *     countBetweenTied: bool
 * } $case
 *
 * @return list<array{id: string, played: int, wins: int, draws: int, losses: int, awarded: int, for: float, against: float, earned: float, adjustment: float, total: float, tiebreakers: array<string, float>}>
 */
function awardsOracle(array $case): array
{
    [$win, $draw, $loss] = $case['scale'];

    // One book per figure, each keyed by participant ID
    $ids = [];
    $seeds = [];
    $played = $wins = $draws = $losses = $awarded = [];
    $for = $against = $earned = $adjustment = [];
    foreach ($case['participants'] as $participant) {
        $id = $participant->getId();
        $ids[] = $id;
        $seeds[$id] = $participant->getSeed() ?? PHP_INT_MAX;
        $played[$id] = $wins[$id] = $draws[$id] = $losses[$id] = $awarded[$id] = 0;
        $for[$id] = $against[$id] = $earned[$id] = $adjustment[$id] = 0.0;
    }

    foreach ($case['games'] as $game) {
        foreach ([[$game['a'], 0, 1], [$game['b'], 1, 0]] as [$self, $own, $their]) {
            ++$played[$self];
            if ($game['awarded']) {
                ++$awarded[$self];
            }

            if ($game['winner'] === null) {
                ++$draws[$self];
                $earned[$self] += $draw;
            } elseif ($game['winner'] === $self) {
                ++$wins[$self];
                $earned[$self] += $win;
            } else {
                ++$losses[$self];
                $earned[$self] += $loss;
            }

            if ($game['scores'] !== null && (!$game['awarded'] || $case['countScores'])) {
                $for[$self] += $game['scores'][$own];
                $against[$self] += $game['scores'][$their];
            }
        }
    }

    foreach ($case['adjustments'] as $given) {
        $adjustment[$given['id']] += $given['amount'];
    }

    $total = [];
    $tiebreakers = [];
    // What a participant is level on so far, as text: the total, then the
    // value of every tiebreaker asked
    $levelOn = [];
    foreach ($ids as $id) {
        $total[$id] = $earned[$id] + $adjustment[$id];
        $tiebreakers[$id] = [];
        $levelOn[$id] = serialize($total[$id] + 0.0);
    }

    // The tiebreakers one after the other, because the one between the tied
    // participants asks who is level on the total and on every tiebreaker
    // before it
    foreach ($case['tiebreakers'] as $name) {
        $values = [];
        foreach ($ids as $id) {
            $values[$id] = $name === 'wins'
                ? (float) $wins[$id]
                : awardsTiebreakerValue($name, $id, $case['games'], $earned, $levelOn, $case['countBetweenTied']);
        }

        foreach ($values as $id => $value) {
            $tiebreakers[$id][$name] = $value;
            $levelOn[$id] .= '|' . serialize($value + 0.0);
        }
    }

    usort($ids, static function (string $one, string $other) use ($case, $total, $tiebreakers, $for, $against, $earned, $seeds): int {
        // Each pair is compared so that the better of the two comes first
        $steps = [[$total[$other], $total[$one]]];
        foreach ($case['tiebreakers'] as $name) {
            $steps[] = [$tiebreakers[$other][$name], $tiebreakers[$one][$name]];
        }
        $steps[] = [$for[$other] - $against[$other], $for[$one] - $against[$one]];
        $steps[] = [$for[$other], $for[$one]];
        $steps[] = [$earned[$other], $earned[$one]];
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
        'played' => $played[$id],
        'wins' => $wins[$id],
        'draws' => $draws[$id],
        'losses' => $losses[$id],
        'awarded' => $awarded[$id],
        'for' => $for[$id],
        'against' => $against[$id],
        'earned' => $earned[$id],
        'adjustment' => $adjustment[$id],
        'total' => $total[$id],
        'tiebreakers' => $tiebreakers[$id],
    ], $ids);
}

/**
 * Every line of a table as plain data, in the shape awardsOracle() returns.
 *
 * @return list<array{id: string, played: int, wins: int, draws: int, losses: int, awarded: int, for: float, against: float, earned: float, adjustment: float, total: float, tiebreakers: array<string, float>}>
 */
function awardsLines(Standings $standings): array
{
    return array_values(array_map(static fn(StandingEntry $entry): array => [
        'id' => $entry->getParticipant()->getId(),
        'played' => $entry->getPlayed(),
        'wins' => $entry->getWins(),
        'draws' => $entry->getDraws(),
        'losses' => $entry->getLosses(),
        'awarded' => $entry->getAwarded(),
        'for' => $entry->getScoreFor(),
        'against' => $entry->getScoreAgainst(),
        'earned' => $entry->getEarnedRankingValue(),
        'adjustment' => $entry->getAdjustment(),
        'total' => $entry->getRankingValue(),
        'tiebreakers' => $entry->getTiebreakers(),
    ], $standings->getEntries()));
}

/**
 * The table of a case, as the library calculates it.
 *
 * @param array{
 *     participants: list<Participant>,
 *     games: list<array{a: string, b: string, winner: ?string, scores: ?array{int, int}, awarded: bool}>,
 *     adjustments: list<array{id: string, amount: float}>,
 *     scale: array{float, float, float},
 *     tiebreakers: list<string>,
 *     countScores: bool,
 *     countBetweenTied: bool
 * } $case
 *
 * @throws InvalidConfigurationException When the calculator refuses the table (not expected here)
 */
function awardsTable(array $case): Standings
{
    $calculator = awardsCalculator($case['scale'], $case['tiebreakers'], $case['countScores'], $case['countBetweenTied']);

    // In one call: who is tied, for the tiebreaker between the tied
    // participants, is read from the totals with the adjustments in them
    return $calculator->calculateWith(
        $case['participants'],
        awardsResults($case['participants'], $case['games']),
        [],
        awardsAdjustments($case['adjustments'])
    );
}

/**
 * The lines of a table by participant ID, without the order.
 *
 * @return array<string, array{id: string, played: int, wins: int, draws: int, losses: int, awarded: int, for: float, against: float, earned: float, adjustment: float, total: float, tiebreakers: array<string, float>}>
 */
function awardsLinesById(Standings $standings): array
{
    $byId = [];
    foreach (awardsLines($standings) as $line) {
        assert(is_string($line['id']));
        $byId[$line['id']] = $line;
    }
    ksort($byId);

    return $byId;
}

const AWARDS_CASES = 400;

describe('a table with awarded results and adjustments, over generated cases', function (): void {
    it('has the figures and the order an independent calculation gives', function (): void {
        $withAwards = 0;
        $withAdjustments = 0;

        for ($seed = 1; $seed <= AWARDS_CASES; ++$seed) {
            $case = awardsCase($seed);
            $withAwards += array_filter($case['games'], static fn(array $game): bool => $game['awarded']) === [] ? 0 : 1;
            $withAdjustments += $case['adjustments'] === [] ? 0 : 1;

            expect(awardsLines(awardsTable($case)))->toBe(awardsOracle($case), "case {$seed}");
        }

        // The generator does produce what the test is about
        expect($withAwards)->toBeGreaterThan(AWARDS_CASES / 2)
            ->and($withAdjustments)->toBeGreaterThan(AWARDS_CASES / 2);
    });

    it('is the same table for every order of the participants, the results and the adjustments', function (): void {
        for ($seed = 1; $seed <= AWARDS_CASES; ++$seed) {
            $case = awardsCase($seed);
            $expected = awardsLines(awardsTable($case));
            $rng = new Randomizer(new Mt19937($seed));

            for ($shuffle = 0; $shuffle < 3; ++$shuffle) {
                $shuffled = $case;
                $shuffled['participants'] = $rng->shuffleArray($case['participants']);
                $shuffled['games'] = $rng->shuffleArray($case['games']);
                $shuffled['adjustments'] = $rng->shuffleArray($case['adjustments']);

                expect(awardsLines(awardsTable($shuffled)))->toBe($expected, "case {$seed}, shuffle {$shuffle}");
            }
        }
    });

    it('has a record that adds up, with the awarded count and the adjustment beside it', function (): void {
        for ($seed = 1; $seed <= AWARDS_CASES; ++$seed) {
            $case = awardsCase($seed);
            $table = awardsTable($case);

            $wins = $draws = $losses = $awarded = 0;
            $adjusted = 0.0;
            foreach ($table->getEntries() as $entry) {
                expect($entry->getPlayed())->toBe($entry->getWins() + $entry->getDraws() + $entry->getLosses(), "case {$seed}")
                    ->and($entry->getAwarded())->toBeLessThanOrEqual($entry->getPlayed())
                    ->and($entry->getRankingValue())->toBe($entry->getEarnedRankingValue() + $entry->getAdjustment())
                    ->and($entry->getByes())->toBe(0);

                $wins += $entry->getWins();
                $draws += $entry->getDraws();
                $losses += $entry->getLosses();
                $awarded += $entry->getAwarded();
                $adjusted += $entry->getAdjustment();
            }

            $games = count($case['games']);
            $drawn = count(array_filter($case['games'], static fn(array $game): bool => $game['winner'] === null));
            $awardedGames = count(array_filter($case['games'], static fn(array $game): bool => $game['awarded']));

            // Every event between two has a winner and a loser, or two draws
            expect([$wins, $losses, $draws])->toBe([$games - $drawn, $games - $drawn, 2 * $drawn], "case {$seed}")
                ->and($awarded)->toBe(2 * $awardedGames)
                ->and($adjusted)->toBe(array_sum(array_column($case['adjustments'], 'amount')) + 0.0);
        }
    });

    it('changes the score figures by the nominal scores, and nothing else, when the scores of awarded results count', function (): void {
        for ($seed = 1; $seed <= AWARDS_CASES; ++$seed) {
            $case = awardsCase($seed);
            $off = awardsLinesById(awardsTable([...$case, 'countScores' => false]));
            $on = awardsLinesById(awardsTable([...$case, 'countScores' => true]));

            // What the awarded results carry as scores, by participant
            $nominal = [];
            foreach ($case['games'] as $game) {
                if ($game['awarded'] && $game['scores'] !== null) {
                    $nominal[$game['a']]['for'] = ($nominal[$game['a']]['for'] ?? 0.0) + $game['scores'][0];
                    $nominal[$game['a']]['against'] = ($nominal[$game['a']]['against'] ?? 0.0) + $game['scores'][1];
                    $nominal[$game['b']]['for'] = ($nominal[$game['b']]['for'] ?? 0.0) + $game['scores'][1];
                    $nominal[$game['b']]['against'] = ($nominal[$game['b']]['against'] ?? 0.0) + $game['scores'][0];
                }
            }

            foreach ($off as $id => $line) {
                $expected = $line;
                $expected['for'] += $nominal[$id]['for'] ?? 0.0;
                $expected['against'] += $nominal[$id]['against'] ?? 0.0;

                expect($on[$id])->toBe($expected, "case {$seed}, participant {$id}");
            }
        }
    });

    it('changes the value of a tiebreaker between the tied participants, and nothing else, when awarded results count in it', function (): void {
        $changed = 0;

        for ($seed = 1; $seed <= AWARDS_CASES; ++$seed) {
            $case = awardsCase($seed);
            $off = awardsLinesById(awardsTable([...$case, 'countBetweenTied' => false]));
            $on = awardsLinesById(awardsTable([...$case, 'countBetweenTied' => true]));

            foreach ($off as $id => $line) {
                $withoutIt = static function (array $line): array {
                    assert(is_array($line['tiebreakers']));
                    unset($line['tiebreakers'][WinsBetweenTheTied::NAME]);

                    return $line;
                };

                expect($withoutIt($on[$id]))->toBe($withoutIt($line), "case {$seed}, participant {$id}");

                if ($on[$id] !== $line) {
                    ++$changed;
                }
            }
        }

        // The choice is not without effect in the cases generated. It has
        // one only where an awarded result is between participants who are
        // level on the total and on every tiebreaker before this one, which
        // is a handful of the lines generated
        expect($changed)->toBeGreaterThan(5);
    });

    it('is the table of a calculator without a policy when no result is awarded, whatever the policy', function (): void {
        for ($seed = 1; $seed <= 150; ++$seed) {
            $case = awardsCase($seed);
            $case['games'] = array_map(static fn(array $game): array => [...$game, 'awarded' => false], $case['games']);
            $case['adjustments'] = [];

            $results = awardsResults($case['participants'], $case['games']);
            $plain = new StandingsCalculator(
                awardsCalculator($case['scale'], $case['tiebreakers'], true, true)->getRankingStrategy(),
                awardsCalculator($case['scale'], $case['tiebreakers'], true, true)->getTiebreakers()
            );
            $expected = awardsLines($plain->calculate($case['participants'], $results));

            foreach ([[true, true], [true, false], [false, true], [false, false]] as [$scores, $betweenTied]) {
                $calculator = awardsCalculator($case['scale'], $case['tiebreakers'], $scores, $betweenTied);
                $table = $calculator->calculate($case['participants'], $results);

                expect(awardsLines($table))->toBe($expected, "case {$seed}")
                    ->and($calculator->withAdjustments($table, []))->toBe($table);
            }
        }
    });
});

/**
 * The pairs of a round, each as its two IDs in order, in the order of the
 * events.
 *
 * @return list<list<string>>
 */
function awardsPairs(RoundPairing $pairing): array
{
    $pairs = [];
    foreach ($pairing->getEvents() as $event) {
        $ids = array_map(static fn(Participant $participant): string => $participant->getId(), $event->getParticipants());
        sort($ids);
        $pairs[] = $ids;
    }

    return $pairs;
}

/**
 * The first complete pairing of the IDs in the order given: take the first
 * one still unpaired, try every later one in order, skip two who have met,
 * and go on with the rest. Null when there is none.
 *
 * @param list<string> $order
 * @param array<string, true> $met Keys "a|b" with a before b
 *
 * @return list<list<string>>|null
 */
function awardsFirstPairing(array $order, array $met): ?array
{
    if ($order === []) {
        return [];
    }

    $first = array_shift($order);
    foreach ($order as $index => $candidate) {
        $pair = [$first, $candidate];
        sort($pair);
        if (isset($met[implode('|', $pair)])) {
            continue;
        }

        $rest = $order;
        unset($rest[$index]);
        $below = awardsFirstPairing(array_values($rest), $met);
        if ($below !== null) {
            return [$pair, ...$below];
        }
    }

    return null;
}

/**
 * @param int<1, max> $count
 *
 * @return list<Participant>
 */
function awardsEntrants(int $count): array
{
    return array_map(static fn(int $number): Participant => new Participant("e{$number}", "Entrant {$number}"), range(1, $count));
}

/**
 * A played result of the event, won by its better-placed participant: the
 * one earlier in the list awardsEntrants() gives. Which of the two an engine
 * names first is not read.
 */
function awardsWonByTheBetterPlaced(Event $event, ?Award $award = null): Result
{
    $winner = null;
    foreach ($event->getParticipants() as $participant) {
        if ($winner === null || (int) substr($participant->getId(), 1) < (int) substr($winner->getId(), 1)) {
            $winner = $participant;
        }
    }

    return new Result($event, $winner, [], [], $award);
}

/**
 * The same, won by the other participant.
 */
function awardsWonByTheLowerPlaced(Event $event, ?Award $award = null): Result
{
    $better = awardsWonByTheBetterPlaced($event)->getWinner();
    foreach ($event->getParticipants() as $participant) {
        if ($participant !== $better) {
            return new Result($event, $participant, [], [], $award);
        }
    }

    throw new LogicException('The event has one participant.');
}

describe('a Swiss stage with awarded results and adjustments', function (): void {
    it('pairs the next round in the order of the adjusted totals', function (): void {
        $entrants = awardsEntrants(6);
        [$e1, $e2, $e3, $e4, $e5, $e6] = $entrants;
        $engine = new SwissPairingEngine();

        $state = StageState::start($entrants);
        $first = $engine->pairNextRound($state);
        expect(awardsPairs($first))->toBe([['e1', 'e2'], ['e3', 'e4'], ['e5', 'e6']]);

        // e1, e3 and e5 win
        $state = $state->withRoundPlayed($first, array_map(awardsWonByTheBetterPlaced(...), $first->getEvents()));

        // As it stands the winners are on top in list order: e1, e3, e5, then e2, e4, e6
        expect(awardsPairs($engine->pairNextRound($state)))->toBe([['e1', 'e3'], ['e2', 'e5'], ['e4', 'e6']]);

        // e1 has its three points removed: e3 and e5 lead, and e1 is first of
        // the four on nothing. It has met e2, so it meets e4.
        $adjusted = $state->withAdjustment(new Adjustment('e1', -3.0, 'ruling'));

        expect(awardsPairs($engine->pairNextRound($adjusted)))->toBe([['e3', 'e5'], ['e1', 'e4'], ['e2', 'e6']])
            // The stored state pairs the same round
            ->and(awardsPairs($engine->pairNextRound(StageState::fromJson($adjusted->toJson()))))
            ->toBe([['e3', 'e5'], ['e1', 'e4'], ['e2', 'e6']]);
    });

    it('shows the adjustment in the table of its outcome, beside what was earned', function (): void {
        $entrants = awardsEntrants(4);
        $engine = new SwissPairingEngine(plannedRounds: 1);

        $state = StageState::start($entrants);
        $pairing = $engine->pairNextRound($state);
        // e1 and e3 win
        $state = $state
            ->withRoundPlayed($pairing, array_map(awardsWonByTheBetterPlaced(...), $pairing->getEvents()))
            ->withAdjustment(new Adjustment('e1', -3.0))
            ->withAdjustment(new Adjustment('e4', 1.0));

        $table = $engine->getOutcome($state)?->getStandings();
        assert($table instanceof Standings);
        $lines = awardsLinesById($table);

        expect([$lines['e1']['earned'], $lines['e1']['adjustment'], $lines['e1']['total']])->toBe([3.0, -3.0, 0.0])
            ->and([$lines['e4']['earned'], $lines['e4']['adjustment'], $lines['e4']['total']])->toBe([0.0, 1.0, 1.0])
            // e3 on 3, e4 on 1, then e1 and e2 on nothing, in list order
            ->and(array_column(awardsLines($table), 'id'))->toBe(['e3', 'e4', 'e1', 'e2']);
    });

    it('counts an awarded result in the table the next round is paired from', function (): void {
        $entrants = awardsEntrants(4);
        $engine = new SwissPairingEngine(plannedRounds: 2);

        $state = StageState::start($entrants);
        $first = $engine->pairNextRound($state);
        [$e1e2, $e3e4] = $first->getEvents();

        // e1 v e2 did not take place and is awarded to e2; e3 beats e4
        $state = $state->withRoundPlayed($first, [
            awardsWonByTheLowerPlaced($e1e2, new Award('no-show')),
            awardsWonByTheBetterPlaced($e3e4),
        ]);

        // e2 and e3 lead, in list order; e1 and e4 follow
        expect(awardsPairs($engine->pairNextRound($state)))->toBe([['e2', 'e3'], ['e1', 'e4']]);
    });

    it('completes a round whose only results are awarded', function (): void {
        $entrants = awardsEntrants(4);
        $engine = new SwissPairingEngine(plannedRounds: 1);

        $state = StageState::start($entrants);
        $pairing = $engine->pairNextRound($state);
        // Both events are awarded, to e2 and to e4
        $state = $state->withRoundPlayed($pairing, array_map(
            static fn(Event $event): Result => awardsWonByTheLowerPlaced($event, new Award()),
            $pairing->getEvents()
        ));

        $outcome = $engine->getOutcome($state);
        assert($outcome instanceof StageOutcome);

        expect($engine->isComplete($state))->toBeTrue()
            ->and(array_column(awardsLines($outcome->getStandings()), 'id'))->toBe(['e2', 'e4', 'e1', 'e3'])
            ->and(array_column(awardsLines($outcome->getStandings()), 'awarded'))->toBe([1, 1, 1, 1]);
    });

    it('pairs every round as an independent search over the adjusted totals does', function (): void {
        $rounds = 0;

        for ($seed = 1; $seed <= 120; ++$seed) {
            $rng = new Randomizer(new Mt19937($seed));
            $entrants = awardsEntrants(2 * $rng->getInt(2, 5));
            $ids = array_map(static fn(Participant $participant): string => $participant->getId(), $entrants);
            $engine = new SwissPairingEngine();

            // The books of the test: points earned, amounts adjusted, who has met
            $earned = array_fill_keys($ids, 0.0);
            $adjustment = array_fill_keys($ids, 0.0);
            $awarded = array_fill_keys($ids, 0);
            $met = [];

            $state = StageState::start($entrants);
            for ($round = 1; $round <= 4; ++$round) {
                for ($n = $rng->getInt(0, 2); $n > 0; --$n) {
                    $id = $ids[$rng->getInt(0, count($ids) - 1)];
                    $amount = $rng->getInt(-8, 8) / 2.0;
                    $adjustment[$id] += $amount;
                    $state = $state->withAdjustment(new Adjustment($id, $amount));
                }
                if ($rng->getInt(0, 1) === 1) {
                    $state = StageState::fromJson($state->toJson());
                }

                // Higher total first; where two are level, the position in the list
                $order = $ids;
                $position = array_flip($ids);
                usort($order, static function (string $one, string $other) use ($earned, $adjustment, $position): int {
                    $byTotal = ($earned[$other] + $adjustment[$other]) <=> ($earned[$one] + $adjustment[$one]);

                    return $byTotal !== 0 ? $byTotal : $position[$one] <=> $position[$other];
                });
                $expected = awardsFirstPairing($order, $met);

                try {
                    $pairing = $engine->pairNextRound($state);
                } catch (NoValidPairingException) {
                    expect($expected)->toBeNull("seed {$seed}, round {$round}");
                    break;
                }

                expect(awardsPairs($pairing))->toBe($expected, "seed {$seed}, round {$round}");
                ++$rounds;

                $results = [];
                foreach ($pairing->getEvents() as $event) {
                    [$one, $other] = $event->getParticipants();
                    $pair = [$one->getId(), $other->getId()];
                    sort($pair);
                    $met[implode('|', $pair)] = true;

                    $winner = [$one, $other, null][$rng->getInt(0, 2)];
                    $isAwarded = $rng->getInt(0, 3) === 0;
                    foreach ([$one, $other] as $participant) {
                        $earned[$participant->getId()] += match (true) {
                            $winner === null => 1.0,
                            $winner === $participant => 3.0,
                            default => 0.0,
                        };
                        $awarded[$participant->getId()] += $isAwarded ? 1 : 0;
                    }
                    $results[] = new Result($event, $winner, [], [], $isAwarded ? new Award() : null);
                }
                $state = $state->withRoundPlayed($pairing, $results);
            }

            // The table of the engine is the books of the test
            $table = (new SwissPairingEngine(plannedRounds: max(1, count($state->getRoundsPlayed()))))->getOutcome($state)?->getStandings();
            if ($table instanceof Standings) {
                foreach (awardsLinesById($table) as $id => $line) {
                    expect([$line['earned'], $line['adjustment'], $line['total'], $line['awarded']])
                        ->toBe([$earned[$id], $adjustment[$id], $earned[$id] + $adjustment[$id], $awarded[$id]], "seed {$seed}, {$id}");
                }
            }
        }

        expect($rounds)->toBeGreaterThan(350);
    });
});

/**
 * Play a bracket to its end. Who advances from each tie is drawn once for
 * the tie, so the legs of a two-legged tie agree; one result in three is
 * marked as awarded when $award is set. The draws are the same with and
 * without the marks.
 *
 * @throws InvalidConfigurationException When the engine refuses the state
 * @throws NoValidPairingException When the engine cannot pair a round
 */
function awardsPlayBracket(StageEngineInterface $engine, StageState $state, int $seed, bool $award): StageState
{
    $rng = new Randomizer(new Mt19937($seed));

    for ($guard = 0; !$engine->isComplete($state); ++$guard) {
        if ($guard > 64) {
            throw new LogicException('The bracket did not complete.');
        }

        $pairing = $engine->pairNextRound($state);
        $advancing = [];
        $results = [];
        foreach ($pairing->getEvents() as $event) {
            $ids = array_map(static fn(Participant $participant): string => $participant->getId(), $event->getParticipants());
            sort($ids);
            $tie = implode('|', $ids);
            $advancing[$tie] ??= $ids[$rng->getInt(0, 1)];
            $marked = $rng->getInt(0, 2) === 0;

            $winner = null;
            foreach ($event->getParticipants() as $participant) {
                if ($participant->getId() === $advancing[$tie]) {
                    $winner = $participant;
                }
            }

            $results[] = new Result($event, $winner, [], [], $award && $marked ? new Award('generated') : null);
        }

        $state = $state->withRoundPlayed($pairing, $results);
    }

    return $state;
}

describe('an elimination bracket with awarded results and adjustments', function (): void {
    it('advances the participant an awarded result names', function (): void {
        $entrants = awardsEntrants(4);
        [$e1, $e2, $e3, $e4] = $entrants;
        $engine = new SingleEliminationEngine();

        $state = StageState::start($entrants);
        $semifinals = $engine->pairNextRound($state);
        expect(awardsPairs($semifinals))->toBe([['e1', 'e4'], ['e2', 'e3']]);

        // e1 v e4 is not played and is awarded to e4; e2 beats e3
        $state = $state->withRoundPlayed($semifinals, [
            new Result($semifinals->getEvents()[0], $e4, award: new Award('no-show', 'e1 did not arrive')),
            new Result($semifinals->getEvents()[1], $e2),
        ]);

        $final = $engine->pairNextRound($state);
        expect(awardsPairs($final))->toBe([['e2', 'e4']]);

        // The final is awarded as well
        $state = $state->withRoundPlayed($final, [new Result($final->getEvents()[0], $e4, award: new Award())]);
        $outcome = $engine->getOutcome($state);
        assert($outcome instanceof StageOutcome);

        expect($engine->isComplete($state))->toBeTrue()
            ->and(array_column(awardsLines($outcome->getStandings()), 'id'))->toBe(['e4', 'e2', 'e1', 'e3'])
            ->and(MatchOutcomeSelector::winners()->select($outcome))->toBe([$e4])
            ->and(awardsLinesById($outcome->getStandings())['e4']['awarded'])->toBe(2);
    });

    it('needs an awarded result for each leg to settle a two-legged tie that is not played', function (): void {
        $entrants = awardsEntrants(2);
        [$e1, $e2] = $entrants;
        $engine = new SingleEliminationEngine(new EliminationOptions(legsPerTie: 2));

        $state = StageState::start($entrants);
        $final = $engine->pairNextRound($state);
        [$firstLeg, $secondLeg] = $final->getEvents();

        $oneLeg = $state->withRoundPlayed($final, [new Result($firstLeg, $e2, award: new Award())]);
        $bothLegs = $oneLeg->withAdditionalResults([new Result($secondLeg, $e2, award: new Award())]);

        expect($engine->isComplete($oneLeg))->toBeFalse()
            ->and($engine->isComplete($bothLegs))->toBeTrue()
            ->and(array_column(awardsLines($engine->getOutcome($bothLegs)?->getStandings() ?? new Standings([])), 'id'))->toBe(['e2', 'e1']);
    });

    it('counts an awarded leg as a leg won, and leaves a tie that is level to the tie decision', function (): void {
        $entrants = awardsEntrants(2);
        [$e1, $e2] = $entrants;
        $engine = new SingleEliminationEngine(new EliminationOptions(legsPerTie: 2));

        $state = StageState::start($entrants);
        $final = $engine->pairNextRound($state);
        [$firstLeg, $secondLeg] = $final->getEvents();

        // e1 won the first leg on the field; the second is awarded to e2
        $level = $state->withRoundPlayed($final, [
            new Result($firstLeg, $e1),
            new Result($secondLeg, $e2, award: new Award()),
        ]);
        $decided = $state->withRoundPlayed($final, [
            new Result($firstLeg, $e1),
            new Result($secondLeg, $e2, [], [], new Award(), new Decider(['e2'])),
        ]);

        expect($engine->isComplete($level))->toBeFalse()
            ->and($engine->getReadiness($level)->getUndecidedEvents())->toBe([$firstLeg, $secondLeg]);

        try {
            $engine->pairNextRound($level);
            $this->fail('The level tie was resolved without a decision.');
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getReason())->toBe(InvalidConfigurationReason::UndecidedTie);
        }

        expect(MatchOutcomeSelector::winners()->select($engine->getOutcome($decided) ?? throw new LogicException('No outcome.')))->toBe([$e2]);
    });

    it('leaves a tie level when the awarded result names no winner, and keeps the mark where the tie decision resolves it', function (): void {
        $entrants = awardsEntrants(2);
        [$e1, $e2] = $entrants;
        $engine = new SingleEliminationEngine();

        $state = StageState::start($entrants);
        $final = $engine->pairNextRound($state);

        $level = $state->withRoundPlayed($final, [new Result($final->getEvents()[0], award: new Award())]);
        $decided = $state->withRoundPlayed($final, [
            new Result($final->getEvents()[0], null, [], [], new Award('ruling'), new Decider(['e1'])),
        ]);

        expect($engine->isComplete($level))->toBeFalse()
            ->and($engine->getOutcome($level))->toBeNull()
            ->and(fn() => $engine->pairNextRound($level))->toThrow(InvalidConfigurationException::class, 'the decider of its result');

        $outcome = $engine->getOutcome($decided);
        assert($outcome instanceof StageOutcome);
        $lines = awardsLinesById($outcome->getStandings());

        // The table counts the event as a win for e1, and still as awarded
        expect([$lines['e1']['wins'], $lines['e1']['awarded']])->toBe([1, 1])
            ->and([$lines['e2']['losses'], $lines['e2']['awarded']])->toBe([1, 1])
            // The outcome's results are the results as recorded
            ->and($outcome->getResults()[0]->isDraw())->toBeTrue()
            ->and($outcome->getResults()[0]->getAward())->toEqual(new Award('ruling'));
    });

    it('orders the entrants a round put out together by their adjusted totals, and moves nobody out of a tier', function (): void {
        $entrants = awardsEntrants(4);
        [$e1, $e2, $e3, $e4] = $entrants;
        $engine = new SingleEliminationEngine();

        // e4 and e3 win the semifinals; e4 wins the final
        $play = function (StageState $state) use ($engine, $e3, $e4): StageState {
            $semifinals = $engine->pairNextRound($state);
            $state = $state->withRoundPlayed($semifinals, [
                new Result($semifinals->getEvents()[0], $e4),
                new Result($semifinals->getEvents()[1], $e3),
            ]);
            $final = $engine->pairNextRound($state);

            return $state->withRoundPlayed($final, [new Result($final->getEvents()[0], $e4)]);
        };

        $plain = $engine->getOutcome($play(StageState::start($entrants)))?->getStandings();
        $adjusted = $engine->getOutcome($play(
            StageState::start($entrants)
                ->withAdjustment(new Adjustment('e1', -1.0))
                ->withAdjustment(new Adjustment('e4', -50.0))
        ))?->getStandings();
        assert($plain instanceof Standings && $adjusted instanceof Standings);

        // e1 and e2 went out in the semifinals, level, in list order
        expect(array_column(awardsLines($plain), 'id'))->toBe(['e4', 'e3', 'e1', 'e2'])
            // e1 is now below e2; e4 won the bracket and is first whatever was taken from it
            ->and(array_column(awardsLines($adjusted), 'id'))->toBe(['e4', 'e3', 'e2', 'e1'])
            ->and($adjusted->getTiers())->toBe($plain->getTiers())
            ->and(awardsLinesById($adjusted)['e4']['total'])->toBe(-44.0)
            ->and(awardsLinesById($adjusted)['e4']['adjustment'])->toBe(-50.0);
    });

    it('folds the survivors of a re-seeded round by their adjusted totals', function (): void {
        $entrants = awardsEntrants(8);
        $engine = new SingleEliminationEngine(new EliminationOptions(reseedEachRound: true));

        $playFirstRound = function (StageState $state) use ($engine): StageState {
            $pairing = $engine->pairNextRound($state);

            // The better-placed entrant of every tie wins: e1, e2, e3 and e4 go on
            return $state->withRoundPlayed($pairing, array_map(awardsWonByTheBetterPlaced(...), $pairing->getEvents()));
        };

        $plain = $playFirstRound(StageState::start($entrants));
        $adjusted = $playFirstRound(StageState::start($entrants)->withAdjustment(new Adjustment('e1', -1.0)));

        // Level survivors fold in list order: 1 v 4, 2 v 3
        expect(awardsPairs($engine->pairNextRound($plain)))->toBe([['e1', 'e4'], ['e2', 'e3']])
            // e1 is the last of the four now: e2, e3, e4, e1 fold as 1 v 4, 2 v 3
            ->and(awardsPairs($engine->pairNextRound($adjusted)))->toBe([['e1', 'e2'], ['e3', 'e4']]);
    });

    // The adjustment below is recorded before round 3 is paired and is meant
    // for it. The state records that it was made after round 2, so round 2
    // is ranked without it: the engine's own round 2 is the round that was
    // played, and nothing is reported as manual. The final is between the
    // two who advanced from it.
    it('ranks a recorded round of a re-seeded bracket without an adjustment made after it was paired', function (): void {
        $entrants = awardsEntrants(8);
        $engine = new SingleEliminationEngine(new EliminationOptions(reseedEachRound: true));

        $state = StageState::start($entrants);
        foreach ([1, 2] as $round) {
            $pairing = $engine->pairNextRound($state);
            $state = $state->withRoundPlayed($pairing, array_map(awardsWonByTheBetterPlaced(...), $pairing->getEvents()));
        }
        expect(awardsPairs($engine->pairNextRound($state)))->toBe([['e1', 'e2']]);

        // Round 2 was folded with e1 on top; the adjustment puts it last
        $late = $state->withAdjustment(new Adjustment('e1', -1.0));

        $final = $engine->pairNextRound($late);
        $rounds = $engine->getRecordedRounds($late);
        $derivation = $rounds[1]->getDerivation();
        assert($derivation !== null);

        expect($final->getRoundNumber())->toBe(3)
            ->and(awardsPairs($final))->toBe([['e1', 'e2']])
            ->and($rounds[1]->getPairing())->toBe($late->getRoundsPlayed()[1])
            ->and($late->getAdjustments()[0]->getAfterRound())->toBe(2)
            ->and(awardsPairs($rounds[1]->getPairing()))->toBe([['e1', 'e4'], ['e2', 'e3']])
            ->and(awardsPairs($derivation))->toBe([['e1', 'e4'], ['e2', 'e3']])
            ->and($rounds[1]->isManual())->toBeFalse();
    });

    // The same adjustment, said to have been made after round 1: it was
    // known when round 2 was paired, the engine would then have folded e1
    // last, and the round that was played is another one. It is read as
    // recorded and reported as manual, with the engine's round beside it.
    it('ranks a recorded round of a re-seeded bracket with an adjustment that says it was made before it', function (): void {
        $entrants = awardsEntrants(8);
        $engine = new SingleEliminationEngine(new EliminationOptions(reseedEachRound: true));

        $state = StageState::start($entrants);
        foreach ([1, 2] as $round) {
            $pairing = $engine->pairNextRound($state);
            $state = $state->withRoundPlayed($pairing, array_map(awardsWonByTheBetterPlaced(...), $pairing->getEvents()));
        }

        $earlier = $state->withAdjustment((new Adjustment('e1', -1.0))->madeAfterRound(1));
        $rounds = $engine->getRecordedRounds($earlier);
        $derivation = $rounds[1]->getDerivation();
        assert($derivation !== null);

        expect(awardsPairs($rounds[1]->getPairing()))->toBe([['e1', 'e4'], ['e2', 'e3']])
            ->and(awardsPairs($derivation))->toBe([['e1', 'e2'], ['e3', 'e4']])
            ->and($rounds[1]->isManual())->toBeTrue()
            ->and($rounds[0]->isManual())->toBeFalse()
            // The final follows from the round that was played
            ->and(awardsPairs($engine->pairNextRound($earlier)))->toBe([['e1', 'e2']]);
    });

    // Issue #154: adjustments recorded at any point of a re-seeded bracket,
    // also after the rounds they would have changed and after the last.
    // Every round is ranked from the adjustments made before it, so the
    // engine's own round is the round it paired, at every later point.
    it('reads a re-seeded bracket to its end with adjustments recorded at any point of it, and reports none of its own rounds as manual', function (): void {
        $engine = new SingleEliminationEngine(new EliminationOptions(reseedEachRound: true));
        $wouldDiffer = 0;

        for ($size = 4; $size <= 9; ++$size) {
            foreach ([1, 2] as $seed) {
                $rng = new Randomizer(new Mt19937($size * 10 + $seed));
                $entrants = awardsEntrants($size);
                $adjust = static fn(StageState $state): StageState => $state->withAdjustment(
                    new Adjustment($entrants[$rng->getInt(0, $size - 1)]->getId(), $rng->getInt(-6, 6) / 2.0)
                );

                $state = StageState::start($entrants);
                while (!$engine->isComplete($state)) {
                    $pairing = $engine->pairNextRound($state);
                    $state = $adjust($state->withRoundPlayed($pairing, array_map(awardsWonByTheBetterPlaced(...), $pairing->getEvents())));

                    // Every round recorded so far is read as it was recorded
                    $read = array_map(
                        static fn(RecordedRound $round): RoundPairing => $round->getPairing(),
                        $engine->getRecordedRounds($state)
                    );
                    expect($read)->toBe($state->getRoundsPlayed(), "{$size} entrants, seed {$seed}");
                }
                $state = $adjust($state);

                foreach ($engine->getRecordedRounds($state) as $round) {
                    $derivation = $round->getDerivation();
                    assert($derivation instanceof RoundPairing);

                    expect($round->isManual())->toBeFalse("{$size} entrants, seed {$seed}, round {$round->getRoundNumber()}")
                        ->and(awardsPairs($derivation))->toBe(awardsPairs($round->getPairing()));
                }

                // What a ranking with every adjustment, whenever it was made,
                // gives for the same rounds: the reading this replaces
                $allAtTheStart = StageState::fromHistory(
                    $entrants,
                    $state->getRoundsPlayed(),
                    $state->getResults(),
                    [],
                    array_map(
                        static fn(Adjustment $adjustment): Adjustment => $adjustment->madeAfterRound(0),
                        $state->getAdjustments()
                    )
                );
                $wouldDiffer += count(array_filter(
                    $engine->getRecordedRounds($allAtTheStart),
                    static fn(RecordedRound $round): bool => $round->isManual()
                ));

                $outcome = $engine->getOutcome(StageState::fromJson($state->toJson()));
                $final = $state->getLastRound()?->getEvents()[0];
                assert($outcome instanceof StageOutcome && $final instanceof Event);

                // The bracket ends with whoever won the final that was played
                expect($outcome->getStandings()->getEntries()[0]->getParticipant()->getId())
                    ->toBe(awardsWonByTheBetterPlaced($final)->getWinner()?->getId());
            }
        }

        // The adjustments did change what the engine would pair with all of
        // them: these are the states an engine that ranked every round with
        // every adjustment refused
        expect($wouldDiffer)->toBeGreaterThan(0);
    });

    it('reads a re-seeded bracket to its end when every adjustment was recorded before the second round is paired', function (): void {
        $engine = new SingleEliminationEngine(new EliminationOptions(reseedEachRound: true));
        $reordered = 0;

        for ($size = 4; $size <= 9; ++$size) {
            foreach ([1, 2, 3, 4] as $seed) {
                $rng = new Randomizer(new Mt19937($size * 10 + $seed));
                $entrants = awardsEntrants($size);

                $plain = StageState::start($entrants);
                $first = $engine->pairNextRound($plain);
                $plain = $plain->withRoundPlayed($first, array_map(awardsWonByTheBetterPlaced(...), $first->getEvents()));

                // Before round 1 and after it, and nothing once round 2 is paired
                $adjusted = StageState::start($entrants)
                    ->withAdjustment(new Adjustment($entrants[$rng->getInt(0, $size - 1)]->getId(), -1.0))
                    ->withRoundPlayed($first, array_map(awardsWonByTheBetterPlaced(...), $first->getEvents()))
                    ->withAdjustment(new Adjustment($entrants[$rng->getInt(0, $size - 1)]->getId(), $rng->getInt(-6, 6) / 2.0))
                    ->withAdjustment(new Adjustment($entrants[$rng->getInt(0, $size - 1)]->getId(), $rng->getInt(-6, 6) / 2.0));
                $reordered += awardsPairs($engine->pairNextRound($adjusted)) === awardsPairs($engine->pairNextRound($plain)) ? 0 : 1;

                $finished = awardsPlayBracket($engine, $adjusted, $seed, true);
                $outcome = $engine->getOutcome(StageState::fromJson($finished->toJson()));

                expect($engine->isComplete($finished))->toBeTrue("{$size} entrants, seed {$seed}")
                    ->and($outcome)->toBeInstanceOf(StageOutcome::class)
                    ->and($finished->getAdjustments())->toHaveCount(3);
            }
        }

        // The adjustments did change rounds: these are not brackets they left alone
        expect($reordered)->toBeGreaterThan(5);
    });

    it('sends the same entrants on, to the same places, whether results are awarded or played', function (string $variant): void {
        for ($size = 2; $size <= 9; ++$size) {
            foreach ([1, 2, 3] as $seed) {
                $engine = match ($variant) {
                    'single' => new SingleEliminationEngine(),
                    'two legs' => new SingleEliminationEngine(new EliminationOptions(legsPerTie: 2)),
                    're-seeded' => new SingleEliminationEngine(new EliminationOptions(reseedEachRound: true)),
                    default => new DoubleEliminationEngine(),
                };
                $entrants = awardsEntrants($size);

                $played = $engine->getOutcome(awardsPlayBracket($engine, StageState::start($entrants), $seed, false));
                $awarded = $engine->getOutcome(awardsPlayBracket($engine, StageState::start($entrants), $seed, true));
                assert($played instanceof StageOutcome && $awarded instanceof StageOutcome);

                $marks = count(array_filter($awarded->getResults(), static fn(Result $result): bool => $result->isAwarded()));
                $counted = array_sum(array_column(awardsLines($awarded->getStandings()), 'awarded'));
                $withoutCount = static fn(array $line): array => [...$line, 'awarded' => 0];

                expect(array_map($withoutCount, awardsLines($awarded->getStandings())))
                    ->toBe(awardsLines($played->getStandings()), "{$variant}, {$size} entrants, seed {$seed}")
                    ->and($awarded->getStandings()->getTiers())->toBe($played->getStandings()->getTiers())
                    ->and($counted)->toBe(2 * $marks)
                    ->and(count($awarded->getResults()))->toBe(count($played->getResults()));
            }
        }
    })->with([['single'], ['two legs'], ['re-seeded'], ['double']]);

    it('keeps every entrant in its tier under any adjustments, and shows each its own', function (string $variant): void {
        for ($size = 2; $size <= 9; ++$size) {
            $rng = new Randomizer(new Mt19937($size));
            $engine = $variant === 'single' ? new SingleEliminationEngine() : new DoubleEliminationEngine();
            $entrants = awardsEntrants($size);

            $sums = [];
            $state = StageState::start($entrants);
            for ($n = 0; $n < 4; ++$n) {
                $id = $entrants[$rng->getInt(0, $size - 1)]->getId();
                $amount = $rng->getInt(-10, 10) / 2.0;
                $sums[$id] = ($sums[$id] ?? 0.0) + $amount;
                $state = $state->withAdjustment(new Adjustment($id, $amount));
            }

            $plain = $engine->getOutcome(awardsPlayBracket($engine, StageState::start($entrants), $size, true))?->getStandings();
            $adjusted = $engine->getOutcome(awardsPlayBracket($engine, $state, $size, true))?->getStandings();
            assert($plain instanceof Standings && $adjusted instanceof Standings);

            // Who is in which tier, without the order inside a tier
            $tiersOf = static function (Standings $standings): array {
                $tiers = [];
                foreach (array_values($standings->getEntries()) as $index => $entry) {
                    $tiers[$entry->getParticipant()->getId()] = $standings->getTiers()[$index];
                }
                ksort($tiers);

                return $tiers;
            };

            expect($tiersOf($adjusted))->toBe($tiersOf($plain), "{$variant}, {$size} entrants");
            foreach (awardsLinesById($adjusted) as $id => $line) {
                expect($line['adjustment'])->toBe($sums[$id] ?? 0.0, "{$variant}, {$size} entrants, {$id}")
                    ->and($line['earned'])->toBe(awardsLinesById($plain)[$id]['earned']);
            }
        }
    })->with([['single'], ['double']]);
});
