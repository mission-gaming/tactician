<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Scheduling\EliminationOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Scheduling\SingleEliminationEngine;
use MissionGaming\Tactician\Scheduling\SwissOptions;
use MissionGaming\Tactician\Scheduling\SwissPairingEngine;
use MissionGaming\Tactician\Scheduling\SwissScheduler;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Standings\BuchholzTiebreaker;
use MissionGaming\Tactician\Standings\ByeTreatment;
use MissionGaming\Tactician\Standings\RankingStrategy;
use MissionGaming\Tactician\Standings\RankingStrategyWithByeValue;
use MissionGaming\Tactician\Standings\StandingsCalculator;
use MissionGaming\Tactician\Standings\WinDrawLossRanking;

// What a bye is worth is the caller's choice, and a stage uses one value for
// the order a round is paired from and for the table it ends with.
//
// The Swiss tests drive a stage in which the better-placed participant wins
// every event, and keep a table of their own beside it: wins times the value
// of a win plus byes times the value of a bye, level participants in list
// order. Nothing of that table is read from the library. A ranking strategy
// that does not say what a bye is worth is one more case, with a bye worth
// nothing (ADR 0019).

/**
 * A calculator on the 3/1/0 scale: a WinDrawLossRanking with the given value
 * of a bye, or, for null, a strategy of the application's that scores the
 * same and does not say what a bye is worth (it is no
 * RankingStrategyWithByeValue).
 *
 * @param list<MissionGaming\Tactician\Standings\TiebreakerInterface> $tiebreakers
 */
function byeStageCalculator(?float $byeValue, array $tiebreakers = []): StandingsCalculator
{
    if ($byeValue !== null) {
        return new StandingsCalculator(WinDrawLossRanking::threeOneZero()->withByeValue($byeValue), $tiebreakers);
    }

    return new StandingsCalculator(new readonly class implements RankingStrategy {
        #[Override]
        public function rank(Participant $participant, array $results): float
        {
            $value = 0.0;
            foreach ($results as $result) {
                $value += $result->isWinFor($participant) ? 3.0 : ($result->isDraw() ? 1.0 : 0.0);
            }

            return $value;
        }
    }, $tiebreakers);
}

/**
 * @return list<Participant>
 */
function byeStageField(int $count): array
{
    $field = [];
    for ($i = 1; $i <= $count; ++$i) {
        $field[] = new Participant("p{$i}", "P{$i}");
    }

    return $field;
}

/**
 * The IDs of a field in the order of the test's own table: by wins times 3
 * plus byes times the value of a bye, higher first, and level participants
 * in list order.
 *
 * @param list<Participant> $field
 * @param array<string, int> $wins
 * @param array<string, int> $byes
 *
 * @return list<string>
 */
function byeStageOrder(array $field, array $wins, array $byes, float $byeValue): array
{
    $rows = [];
    foreach ($field as $position => $participant) {
        $id = $participant->getId();
        $rows[] = [-(($wins[$id] ?? 0) * 3.0 + ($byes[$id] ?? 0) * $byeValue), $position, $id];
    }
    sort($rows);

    return array_column($rows, 2);
}

/**
 * The pairs of a round, whoever is first-named and whatever order the events
 * are in: the two IDs of each event in ID order, and the events in the order
 * of those.
 *
 * @return list<string>
 */
function byeStagePairs(RoundPairing $pairing): array
{
    $pairs = [];
    foreach ($pairing->getEvents() as $event) {
        $ids = array_map(static fn(Participant $participant): string => $participant->getId(), $event->getParticipants());
        sort($ids);
        $pairs[] = implode(' v ', $ids);
    }
    sort($pairs);

    return $pairs;
}

describe('a Swiss stage in which the better-placed participant wins every event', function (): void {
    // The acceptance cases of the issue: 5 participants and 3 rounds with a
    // bye valued as a win, and the same with a bye valued at zero; around
    // them other odd fields and other values.
    it('pairs every round from the table of the round before and ends with that table, for every value of a bye', function (
        int $size,
        ?float $byeValue
    ): void {
        $field = byeStageField($size);
        $engine = new SwissPairingEngine(standingsCalculator: byeStageCalculator($byeValue), plannedRounds: 3);
        // A strategy that does not say what a bye is worth credits it with nothing
        $credit = $byeValue ?? 0.0;

        $state = StageState::start($field);
        $wins = $byes = [];

        for ($round = 1; $round <= 3; ++$round) {
            $order = byeStageOrder($field, $wins, $byes, $credit);
            $place = array_flip($order);
            $pairing = $engine->pairNextRound($state);

            // The bye goes to the lowest-placed of those who have had the
            // fewest, read from the test's table
            $fewest = PHP_INT_MAX;
            foreach ($order as $id) {
                $fewest = min($fewest, $byes[$id] ?? 0);
            }
            $candidates = array_values(array_filter($order, static fn(string $id): bool => ($byes[$id] ?? 0) === $fewest));
            $sittingOut = array_map(static fn(Participant $participant): string => $participant->getId(), $pairing->getByes());
            expect($sittingOut)->toBe([$candidates[count($candidates) - 1]], "round {$round}");

            // The pairs are made from the top of that table down: the
            // best-placed participant not yet paired is in the next event
            $unpaired = array_values(array_diff($order, $sittingOut));
            $results = [];
            foreach ($pairing->getEvents() as $event) {
                $pair = array_map(static fn(Participant $participant): string => $participant->getId(), $event->getParticipants());
                expect(in_array($unpaired[0], $pair, true))->toBeTrue("round {$round}: {$unpaired[0]} is the best-placed left and is not in " . implode(' v ', $pair));
                $unpaired = array_values(array_diff($unpaired, $pair));

                $winner = $place[$pair[0]] < $place[$pair[1]] ? $event->getParticipants()[0] : $event->getParticipants()[1];
                $results[] = new Result($event, $winner);
                $wins[$winner->getId()] = ($wins[$winner->getId()] ?? 0) + 1;
            }
            expect($unpaired)->toBe([]);

            foreach ($sittingOut as $id) {
                $byes[$id] = ($byes[$id] ?? 0) + 1;
            }
            $state = $state->withRoundPlayed($pairing, $results);
        }

        $table = $engine->getOutcome($state)?->getStandings();
        assert($table !== null);

        expect(array_map(static fn($entry): string => $entry->getParticipant()->getId(), $table->getEntries()))
            ->toBe(byeStageOrder($field, $wins, $byes, $credit));

        foreach ($table->getEntries() as $entry) {
            $id = $entry->getParticipant()->getId();

            expect($entry->getWins())->toBe($wins[$id] ?? 0, $id)
                ->and($entry->getByes())->toBe($byes[$id] ?? 0, $id)
                ->and($entry->getPlayed())->toBe(3 - ($byes[$id] ?? 0), $id)
                ->and($entry->getPlayed())->toBe($entry->getWins() + $entry->getLosses())
                ->and($entry->getRankingValue())->toBe(($wins[$id] ?? 0) * 3.0 + ($byes[$id] ?? 0) * $credit, $id);
        }

        // Every round had a bye, and with a bye valued at zero the table
        // shows them and scores none
        expect(array_sum($byes))->toBe(3);
        if ($credit === 0.0) {
            foreach ($table->getEntries() as $entry) {
                expect($entry->getRankingValue())->toBe($entry->getWins() * 3.0);
            }
        }
    })->with([
        'five, a bye valued as a win' => [5, 3.0],
        'five, a bye valued at zero' => [5, 0.0],
        'five, a bye valued as a draw' => [5, 1.0],
        'five, a bye valued above a win' => [5, 4.5],
        'seven, a bye valued as a win' => [7, 3.0],
        'seven, a bye valued at zero' => [7, 0.0],
        'nine, a bye valued as a draw' => [9, 1.0],
        'nine, a bye valued at zero' => [9, 0.0],
        'five, a strategy without a bye value' => [5, null],
        'seven, a strategy without a bye value' => [7, null],
        'nine, a strategy without a bye value' => [9, null],
        'eleven, a strategy without a bye value' => [11, null],
    ]);

    // After round 1 the participant who sat out is level with the winners
    // when a bye is a win, and with the losers when it is worth nothing.
    it('ranks the participant who sat out with the winners or with the losers, by the value of a bye', function (
        ?float $byeValue,
        array $expected
    ): void {
        $field = byeStageField(5);
        $engine = new SwissPairingEngine(standingsCalculator: byeStageCalculator($byeValue), plannedRounds: 1);
        $opening = $engine->pairNextRound(StageState::start($field));

        // Round 1 pairs position 1 with 2 and 3 with 4, and position 5 sits out
        expect(array_map(static fn(Participant $participant): string => $participant->getId(), $opening->getByes()))->toBe(['p5']);

        $byId = [];
        foreach ($field as $participant) {
            $byId[$participant->getId()] = $participant;
        }
        $state = StageState::start($field)->withRoundPlayed($opening, array_map(
            static function (Event $event) use ($byId): Result {
                $ids = array_map(static fn(Participant $participant): string => $participant->getId(), $event->getParticipants());
                sort($ids);

                // The better-placed of the two wins: p1 and p3
                return new Result($event, $byId[$ids[0]]);
            },
            $opening->getEvents()
        ));

        $table = $engine->getOutcome($state)?->getStandings();

        expect(array_map(static fn($entry): string => $entry->getParticipant()->getId(), $table?->getEntries() ?? []))->toBe($expected);
    })->with([
        'as a win: level with the two winners, after them in list order' => [3.0, ['p1', 'p3', 'p5', 'p2', 'p4']],
        'as a draw: between the winners and the losers' => [1.0, ['p1', 'p3', 'p5', 'p2', 'p4']],
        'as nothing: level with the two losers, after them in list order' => [0.0, ['p1', 'p3', 'p2', 'p4', 'p5']],
        'above a win: first' => [4.0, ['p5', 'p1', 'p3', 'p2', 'p4']],
        'under a strategy without a bye value: as nothing' => [null, ['p1', 'p3', 'p2', 'p4', 'p5']],
    ]);

    // The pairing order and the outcome are one table also where a
    // tiebreaker reads the byes: the outcome of a stage planned for r rounds
    // is the table round r + 1 is paired from.
    it('pairs the next round from the table an outcome of the rounds so far shows, with a tiebreaker that reads byes', function (
        ByeTreatment $treatment,
        ?float $byeValue
    ): void {
        $field = byeStageField(7);
        $calculator = byeStageCalculator($byeValue, [new BuchholzTiebreaker($treatment)]);
        $pairs = new SwissPairingEngine(standingsCalculator: $calculator, plannedRounds: 5);

        $state = StageState::start($field);
        for ($round = 1; $round <= 4; ++$round) {
            $pairing = $pairs->pairNextRound($state);
            $state = $state->withRoundPlayed($pairing, array_map(
                // A win for the first-named, a draw in every third event
                static fn(Event $event, int $index): Result => new Result($event, ($index + $round) % 3 === 0 ? null : $event->getParticipants()[0]),
                $pairing->getEvents(),
                array_keys($pairing->getEvents())
            ));

            $table = (new SwissPairingEngine(standingsCalculator: $calculator, plannedRounds: $round))->getOutcome($state)?->getStandings();
            assert($table !== null);
            $order = array_map(static fn($entry): string => $entry->getParticipant()->getId(), $table->getEntries());

            $next = $pairs->pairNextRound($state);
            $unpaired = array_values(array_diff(
                $order,
                array_map(static fn(Participant $participant): string => $participant->getId(), $next->getByes())
            ));
            foreach ($next->getEvents() as $event) {
                $pair = array_map(static fn(Participant $participant): string => $participant->getId(), $event->getParticipants());
                expect(in_array($unpaired[0], $pair, true))->toBeTrue("after round {$round}: {$unpaired[0]} is the best-placed left and is not in " . implode(' v ', $pair));
                $unpaired = array_values(array_diff($unpaired, $pair));
            }
        }
    })->with([
        [ByeTreatment::Uncounted, 3.0],
        [ByeTreatment::Zero, 3.0],
        [ByeTreatment::OwnRankingValue, 3.0],
        [ByeTreatment::OwnRankingValue, 1.0],
        [ByeTreatment::Zero, 0.0],
        [ByeTreatment::Uncounted, null],
        [ByeTreatment::Zero, null],
        [ByeTreatment::OwnRankingValue, null],
    ]);
});

describe('a ranking strategy of the application that says what a bye is worth', function (): void {
    /**
     * Two for each result that names the participant the winner, and half
     * of that for a bye.
     */
    $twoPerWin = new readonly class implements RankingStrategyWithByeValue {
        #[Override]
        public function rank(Participant $participant, array $results): float
        {
            $value = 0.0;
            foreach ($results as $result) {
                $value += $result->isWinFor($participant) ? 2.0 : 0.0;
            }

            return $value;
        }

        #[Override]
        public function getByeValue(): float
        {
            return 1.0;
        }
    };

    it('has its byes credited in a Swiss stage, where a strategy that does not say credits them with nothing', function () use ($twoPerWin): void {
        $field = byeStageField(5);
        $engine = new SwissPairingEngine(standingsCalculator: new StandingsCalculator($twoPerWin), plannedRounds: 2);

        $state = StageState::start($field);
        while (!$engine->isComplete($state)) {
            $pairing = $engine->pairNextRound($state);
            $state = $state->withRoundPlayed($pairing, array_map(
                static fn(Event $event): Result => new Result($event, $event->getParticipants()[0]),
                $pairing->getEvents()
            ));
        }

        $table = $engine->getOutcome($state)?->getStandings();
        assert($table !== null);
        $credited = 0;
        foreach ($table->getEntries() as $entry) {
            expect($entry->getRankingValue())->toBe(2.0 * $entry->getWins() + 1.0 * $entry->getByes());
            $credited += $entry->getByes();
        }

        expect($credited)->toBe(2);
    });

    it('re-seeds a bracket whose field is not a power of two', function (int $size) use ($twoPerWin): void {
        $engine = new SingleEliminationEngine(new EliminationOptions(reseedEachRound: true), new StandingsCalculator($twoPerWin));

        $state = StageState::start(byeStageField($size));
        $rounds = 0;
        while (!$engine->isComplete($state)) {
            $pairing = $engine->pairNextRound($state);
            $state = $state->withRoundPlayed($pairing, array_map(
                static fn(Event $event): Result => new Result($event, $event->getParticipants()[0]),
                $pairing->getEvents()
            ));
            ++$rounds;
        }

        expect($rounds)->toBe((int) ceil(log($size, 2)))
            ->and(count($engine->getOutcome($state)?->getStandings() ?? []))->toBe($size);
    })->with([[3], [5], [6], [7], [12]]);
});

describe('re-seeding a bracket of five, where three entrants sit the first round out', function (): void {
    // Position 4 beats position 5 in round 1. Before round 2 the survivors
    // are ranked: the one who played has a win, the three who sat out have
    // what a bye is worth. The ranking is folded, first against last.
    it('folds the second round from a ranking in which a bye is worth what the caller says', function (float $byeValue, array $expected): void {
        $field = byeStageField(5);
        $engine = new SingleEliminationEngine(
            new EliminationOptions(reseedEachRound: true),
            new StandingsCalculator(WinDrawLossRanking::threeOneZero()->withByeValue($byeValue))
        );

        $opening = $engine->pairNextRound(StageState::start($field));
        expect(byeStagePairs($opening))->toBe(['p4 v p5']);

        $state = StageState::start($field)->withRoundPlayed($opening, [new Result($opening->getEvents()[0], $field[3])]);

        expect(byeStagePairs($engine->pairNextRound($state)))->toBe($expected);
    })->with([
        // All four on 3: the list decides, and it is the fixed bracket
        'as a win, the default' => [3.0, ['p1 v p4', 'p2 v p3']],
        // p4 on 3 is ranked first, then p1, p2, p3 on 0 in list order
        'as nothing' => [0.0, ['p1 v p2', 'p3 v p4']],
        // p1, p2, p3 on 4 in list order, then p4 on 3
        'above a win' => [4.0, ['p1 v p4', 'p2 v p3']],
    ]);
});

describe('the byes of a schedule', function (): void {
    it('are the entrants with no event in a round, which is what the round robin records in its metadata', function (int $size, int $legs): void {
        $field = byeStageField($size);
        $schedule = (new RoundRobinScheduler())->schedule($field, new RoundRobinOptions(legs: $legs));

        $byRound = $schedule->getByesByRound();
        $ids = array_map(
            static fn(array $participants): array => array_map(static fn(Participant $participant): string => $participant->getId(), $participants),
            $byRound
        );

        // One key for every round, and one bye in each exactly when the field is odd
        expect(array_keys($byRound))->toBe(range(1, count($byRound)))
            ->and(count($byRound))->toBe($legs * ($size % 2 === 0 ? $size - 1 : $size));
        foreach ($ids as $round => $sittingOut) {
            expect(count($sittingOut))->toBe($size % 2, "round {$round}");
        }

        $recorded = $schedule->getMetadataValue('byes');
        assert(is_array($recorded));
        ksort($recorded);
        expect(array_map(static fn(array $sittingOut): string => $sittingOut[0], array_filter($ids)))->toBe($recorded);

        // Everyone sits out once a leg in a field of odd size
        expect($schedule->getByeCounts())->toBe(
            $size % 2 === 0 ? [] : array_fill_keys(array_keys($schedule->getByeCounts()), $legs)
        )->and(count($schedule->getByeCounts()))->toBe($size % 2 === 0 ? 0 : $size);

        // The same after storage, where a consumer may have dropped the metadata
        $stored = $schedule->toArray();
        $stored['metadata'] = [];
        $restored = Schedule::fromArray($stored);
        expect($restored->getByeCounts())->toBe($schedule->getByeCounts())
            ->and(array_map(
                static fn(array $participants): array => array_map(static fn(Participant $participant): string => $participant->getId(), $participants),
                $restored->getByesByRound()
            ))->toBe($ids);
    })->with([[2, 1], [3, 1], [4, 2], [5, 1], [5, 2], [6, 1], [7, 3], [9, 1]]);

    it('are what the whole-schedule Swiss preset records, one for each round of a field of odd size', function (int $size, int $rounds): void {
        $schedule = (new SwissScheduler())->schedule(byeStageField($size), new SwissOptions(rounds: $rounds, seed: 58));

        $ids = array_map(
            static fn(array $participants): array => array_map(static fn(Participant $participant): string => $participant->getId(), $participants),
            $schedule->getByesByRound()
        );
        $recorded = $schedule->getMetadataValue('byes');
        assert(is_array($recorded));

        expect(array_keys($ids))->toBe(range(1, $rounds))
            ->and(array_map(static fn(array $sittingOut): string => $sittingOut[0], array_filter($ids)))->toBe($recorded)
            ->and(array_sum($schedule->getByeCounts()))->toBe($size % 2 === 0 ? 0 : $rounds);
    })->with([[3, 2], [4, 3], [5, 3], [7, 5], [8, 3], [9, 4]]);

    it('lists an entrant that has no event at all in every round, and reads no event without a round', function (): void {
        [$a, $b, $c, $d] = byeStageField(4);
        $schedule = new Schedule(
            [
                new Event([$a, $b], new MissionGaming\Tactician\DTO\Round(1)),
                new Event([$c, $a], new MissionGaming\Tactician\DTO\Round(3)),
                new Event([$b, $c]),
            ],
            [],
            [$d, $c, $b, $a]
        );

        $ids = array_map(
            static fn(array $participants): array => array_map(static fn(Participant $participant): string => $participant->getId(), $participants),
            $schedule->getByesByRound()
        );

        // In entrant order, for the two rounds that have an event
        expect($ids)->toBe([1 => ['p4', 'p3'], 3 => ['p4', 'p2']])
            ->and($schedule->getByeCounts())->toBe(['p4' => 2, 'p3' => 1, 'p2' => 1])
            ->and((new Schedule())->getByesByRound())->toBe([])
            ->and((new Schedule())->getByeCounts())->toBe([]);
    });
});

describe('the byes of a stage state', function (): void {
    it('are listed for every recorded round, as the pairing of the round has them', function (): void {
        $field = byeStageField(5);
        // The rounds are recorded without results, which is a mode of the engine
        $engine = new SwissPairingEngine(plannedRounds: 3, resultsFree: true);

        $state = StageState::start($field);
        $expected = [];
        while (!$engine->isComplete($state)) {
            $pairing = $engine->pairNextRound($state);
            $expected[$pairing->getRoundNumber()] = array_map(
                static fn(Participant $participant): string => $participant->getId(),
                $pairing->getByes()
            );
            $state = $state->withRoundPlayed($pairing, []);
        }

        $read = fn(StageState $state): array => array_map(
            static fn(array $participants): array => array_map(static fn(Participant $participant): string => $participant->getId(), $participants),
            $state->getByesByRound()
        );

        expect($read($state))->toBe($expected)
            ->and(array_keys($expected))->toBe([1, 2, 3])
            ->and($read(StageState::fromJson($state->toJson())))->toBe($expected)
            ->and(array_sum($state->getByeCounts()))->toBe(3)
            ->and(StageState::start($field)->getByesByRound())->toBe([]);

        // A round of a field of even size is listed with no bye
        $even = StageState::start(byeStageField(4));
        $even = $even->withRoundPlayed($engine->pairNextRound($even), []);
        expect($even->getByesByRound())->toBe([1 => []]);
    });
});

describe('the score groups of a Swiss stage with a bye of very little value', function (): void {
    // The Swiss engine reads two win/draw/loss totals as level when they are
    // no further apart than the rounding of a float sum can put them. A bye
    // worth less than that allowance is still a bye: the participant who had
    // it is ahead of those who lost, and is paired as the table has it.
    it('never reads totals that a bye separates as level', function (): void {
        $field = byeStageField(7);
        $engine = new SwissPairingEngine(
            standingsCalculator: new StandingsCalculator((new WinDrawLossRanking(1.0, 0.5, 0.0))->withByeValue(1.0e-16))
        );

        $opening = $engine->pairNextRound(StageState::start($field));
        expect(byeStagePairs($opening))->toBe(['p1 v p2', 'p3 v p4', 'p5 v p6']);

        $byId = [];
        foreach ($field as $participant) {
            $byId[$participant->getId()] = $participant;
        }
        // p1, p3 and p5 win; p7 sat out
        $state = StageState::start($field)->withRoundPlayed($opening, array_map(
            static function (Event $event) use ($byId): Result {
                $ids = array_map(static fn(Participant $participant): string => $participant->getId(), $event->getParticipants());
                sort($ids);

                return new Result($event, $byId[$ids[0]]);
            },
            $opening->getEvents()
        ));

        // The table: p1, p3, p5 on 1, then p7 on its bye, then p2, p4, p6.
        // p6 has the bye, and the third winner meets p7, not a loser.
        $second = $engine->pairNextRound($state);
        expect(byeStagePairs($second))->toBe(['p1 v p3', 'p2 v p4', 'p5 v p7'])
            ->and(array_map(static fn(Participant $participant): string => $participant->getId(), $second->getByes()))->toBe(['p6']);
    });
});
