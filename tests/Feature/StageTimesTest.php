<?php

declare(strict_types=1);

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
use MissionGaming\Tactician\Stage\SettledTie;
use MissionGaming\Tactician\Stage\StageOutcome;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Stage\Withdrawal;
use MissionGaming\Tactician\Standings\Adjustment;
use MissionGaming\Tactician\Standings\Standings;
use Random\Engine\Mt19937;
use Random\Randomizer;

// A stage state says after which round each adjustment was made and each
// participant withdrew, and an engine reads a recorded round from what was
// known when it was paired (ADR 0001, ADR 0007). These are the cases: the
// re-seeded bracket that was read wrongly, what is read where the time is
// not stated, the tie both entrants left, and the selector over a bracket a
// finalist withdrew from. The sweeps are in EliminationInvariantsTest.

/**
 * @return list<Participant>
 */
function timesField(int $size): array
{
    return array_map(static fn(int $number): Participant => new Participant("p{$number}", "P{$number}"), range(1, $size));
}

/**
 * Pair the next round and record it with the entrant of the lower number
 * winning every event.
 *
 * @throws InvalidConfigurationException When the engine or the state refuses the round
 */
function timesPlayRound(SingleEliminationEngine|DoubleEliminationEngine $engine, StageState $state): StageState
{
    $pairing = $engine->pairNextRound($state);

    return $state->withRoundPlayed($pairing, array_map(
        static function (Event $event): Result {
            $entrants = array_values($event->getParticipants());
            usort($entrants, static fn(Participant $one, Participant $other): int => (int) substr($one->getId(), 1) <=> (int) substr($other->getId(), 1));

            return new Result($event, $entrants[0]);
        },
        $pairing->getEvents()
    ));
}

/**
 * The pairs of a round as sorted IDs, in the order of its events.
 *
 * @return list<string>
 */
function timesPairs(RoundPairing $pairing): array
{
    $pairs = [];
    foreach ($pairing->getEvents() as $event) {
        $ids = array_map(static fn(Participant $participant): string => $participant->getId(), array_values($event->getParticipants()));
        sort($ids, SORT_NATURAL);
        $pairs[implode(' v ', $ids)] = true;
    }

    return array_keys($pairs);
}

/**
 * @param array<Participant> $participants
 *
 * @return list<string>
 */
function timesIds(array $participants): array
{
    return array_values(array_map(static fn(Participant $participant): string => $participant->getId(), $participants));
}

/**
 * The tier of each participant of a table, by ID, in the order of the IDs.
 *
 * @return array<string, int>
 */
function timesTiers(Standings $table): array
{
    $tiers = [];
    foreach (array_values($table->getEntries()) as $index => $entry) {
        $tiers[$entry->getParticipant()->getId()] = $table->getTiers()[$index] ?? -1;
    }
    ksort($tiers, SORT_NATURAL);

    return $tiers;
}

describe('a re-seeded bracket with a withdrawal and adjustments made after a round', function (): void {
    /**
     * Sixteen entrants, re-seeded. Round 1 is played; p1 withdraws; the
     * quarter-finals are paired without it (p8, whom it would have met,
     * has a bye) and played. Then three adjustments are made, which would
     * fold the eight survivors of round 1 in another order today.
     *
     * @return array{SingleEliminationEngine, StageState, StageState} The engine, the state before the adjustments and after them
     */
    $bracket = function (): array {
        $engine = new SingleEliminationEngine(new EliminationOptions(reseedEachRound: true));
        $field = timesField(16);

        $state = timesPlayRound($engine, StageState::start($field))->withoutParticipant($field[0]);
        $state = timesPlayRound($engine, $state);

        return [
            $engine,
            $state,
            $state
                ->withAdjustment(new Adjustment('p7', 1.0))
                ->withAdjustment(new Adjustment('p8', -4.0))
                ->withAdjustment(new Adjustment('p4', -4.0)),
        ];
    };

    it('pairs the semifinals next, between the four who are still in it', function () use ($bracket): void {
        [$engine, $plain, $adjusted] = $bracket();

        $quarterFinals = $plain->getRoundsPlayed()[1];
        expect(timesPairs($quarterFinals))->toBe(['p4 v p5', 'p2 v p7', 'p3 v p6'])
            ->and(timesIds($quarterFinals->getByes()))->toBe(['p8']);

        $next = $engine->pairNextRound($adjusted);
        $in = [];
        foreach ($next->getEvents() as $event) {
            $in = [...$in, ...timesIds($event->getParticipants())];
        }
        sort($in, SORT_NATURAL);

        // The round due is the semifinal, a round of two ties. The
        // adjustments are known now and fold it: p2 and p3 lead, p4 and p8
        // have lost four points each and are level, in list order
        expect($next->getRoundNumber())->toBe(3)
            ->and($next->getLabel())->toBe('semifinal')
            ->and($next->getEvents())->toHaveCount(2)
            ->and($next->getByes())->toBe([])
            ->and($in)->toBe(['p2', 'p3', 'p4', 'p8'])
            ->and(timesPairs($next))->toBe(['p2 v p8', 'p3 v p4'])
            // Without the adjustments it is the same round, folded as the table then stands
            ->and($engine->pairNextRound($plain)->getLabel())->toBe('semifinal');
    });

    it('reads the two recorded rounds as the rounds they were, and reports neither as manual', function () use ($bracket): void {
        [$engine, $plain, $adjusted] = $bracket();

        $read = static fn(StageState $state): array => array_map(
            static fn(RecordedRound $round): array => [
                $round->getRoundNumber(),
                $round->getDerivation()?->getLabel(),
                $round->isManual(),
                $round->getDerivation() instanceof RoundPairing ? timesPairs($round->getDerivation()) : null,
            ],
            $engine->getRecordedRounds($state)
        );

        expect($read($adjusted))->toBe($read($plain))
            ->and(array_column($read($adjusted), 1))->toBe(['round of 16', 'quarterfinal'])
            ->and(array_column($read($adjusted), 2))->toBe([false, false])
            ->and($read($adjusted)[1][3])->toBe(timesPairs($adjusted->getRoundsPlayed()[1]));
    });

    it('has the withdrawn entrant go out in the quarter-finals, before the adjustments and after them', function () use ($bracket): void {
        [$engine, $plain, $adjusted] = $bracket();

        $tiers = timesTiers($engine->getStandings($adjusted));

        // Four are still in; p1 went out with the three who lost a
        // quarter-final, a tier above the eight who lost in round 1
        expect($tiers)->toBe(timesTiers($engine->getStandings($plain)))
            ->and(array_keys($tiers, 0, true))->toEqualCanonicalizing(['p2', 'p3', 'p4', 'p8'])
            ->and($tiers['p1'])->toBe($tiers['p5'])
            ->and($tiers['p1'])->toBeLessThan($tiers['p9']);
    });

    it('reads the same from rows that state the rounds, and from the stored state', function () use ($bracket): void {
        [$engine, , $adjusted] = $bracket();

        $fromRows = StageState::fromHistory(
            timesField(16),
            array_reverse($adjusted->getRoundsPlayed()),
            array_reverse($adjusted->getResults()),
            [new Withdrawal(timesField(16)[0], 1)],
            [
                (new Adjustment('p7', 1.0))->madeAfterRound(2),
                (new Adjustment('p8', -4.0))->madeAfterRound(2),
                (new Adjustment('p4', -4.0))->madeAfterRound(2),
            ]
        );

        expect($fromRows->toArray())->toBe($adjusted->toArray())
            ->and(timesPairs($engine->pairNextRound($fromRows)))->toBe(['p2 v p8', 'p3 v p4'])
            ->and(timesPairs($engine->pairNextRound(StageState::fromJson($adjusted->toJson()))))->toBe(['p2 v p8', 'p3 v p4']);
    });

    // The same adjustments, made after round 1: they were known when the
    // quarter-finals were paired, and the engine then folds the survivors
    // otherwise. The engine pairs that round, and it reads its own round.
    it('folds the quarter-finals by adjustments that were made before they were paired', function (): void {
        $engine = new SingleEliminationEngine(new EliminationOptions(reseedEachRound: true));
        $field = timesField(16);

        $state = timesPlayRound($engine, StageState::start($field))
            ->withoutParticipant($field[0])
            ->withAdjustment(new Adjustment('p7', 1.0))
            ->withAdjustment(new Adjustment('p8', -4.0))
            ->withAdjustment(new Adjustment('p4', -4.0));
        $quarterFinals = $engine->pairNextRound($state);

        // p7 leads, then p1 (withdrawn), p2, p3, p5, p6, and last p4 and p8:
        // 7 v 8, 3 v 5, 1 v 4, 2 v 6, and p4 goes on without the tie with p1
        expect(timesPairs($quarterFinals))->toBe(['p7 v p8', 'p3 v p5', 'p2 v p6'])
            ->and(timesIds($quarterFinals->getByes()))->toBe(['p4']);

        $state = timesPlayRound($engine, $state);
        $rounds = $engine->getRecordedRounds($state);

        expect($rounds[1]->isManual())->toBeFalse()
            ->and($rounds[1]->getDerivation()?->getLabel())->toBe('quarterfinal')
            ->and($engine->pairNextRound($state)->getLabel())->toBe('semifinal')
            ->and($engine->pairNextRound($state)->getEvents())->toHaveCount(2);
    });
});

describe('an adjustment that does not say after which round it was made', function (): void {
    /**
     * The bracket above from rows that state the round of the withdrawal
     * or not, and of none of the adjustments.
     *
     * @param list<Participant|Withdrawal> $withdrawals
     *
     * @throws InvalidConfigurationException When the history is refused
     */
    $fromRows = function (EliminationOptions $options, array $withdrawals): array {
        $engine = new SingleEliminationEngine($options);
        $field = timesField(16);
        $played = timesPlayRound($engine, timesPlayRound($engine, StageState::start($field))->withoutParticipant($field[0]));

        return [$engine, StageState::fromHistory(
            $field,
            $played->getRoundsPlayed(),
            $played->getResults(),
            $withdrawals,
            [new Adjustment('p7', 1.0), new Adjustment('p8', -4.0), new Adjustment('p4', -4.0)]
        )];
    };

    // What became of an entrant who had withdrawn before a round was paired
    // depends on the order the survivors were folded in, and that order is
    // not known. The engine does not take one: it refuses, on every call.
    it('is refused by a re-seeded bracket for a recorded round an entrant had withdrawn before, with the round and who is concerned', function (
        Closure $call
    ) use ($fromRows): void {
        [$engine, $state] = $fromRows(new EliminationOptions(reseedEachRound: true), [new Withdrawal(timesField(16)[0], 1)]);

        try {
            $call($engine, $state);
            $this->fail('The state was read.');
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getReason())->toBe(InvalidConfigurationReason::RecordedRoundMismatch)
                ->and($exception->getContext())->toBe([
                    'round' => 2,
                    'adjusted_without_round' => ['p7', 'p8', 'p4'],
                    'withdrawn' => ['p1'],
                ])
                ->and($exception->getConfigurationIssue())->toContain('Adjustment::madeAfterRound()')
                ->and($exception->getConfigurationIssue())->toContain('The recorded round 2 of this re-seeded bracket cannot be read');
        }
    })->with([
        'pairNextRound()' => [fn($engine, $state) => $engine->pairNextRound($state)],
        'isComplete()' => [fn($engine, $state) => $engine->isComplete($state)],
        'getOutcome()' => [fn($engine, $state) => $engine->getOutcome($state)],
        'getReadiness()' => [fn($engine, $state) => $engine->getReadiness($state)],
        'getRecordedRounds()' => [fn($engine, $state) => $engine->getRecordedRounds($state)],
        'getStandings()' => [fn($engine, $state) => $engine->getStandings($state)],
    ]);

    it('is refused the same where the withdrawal does not say its round either', function () use ($fromRows): void {
        [$engine, $state] = $fromRows(new EliminationOptions(reseedEachRound: true), [timesField(16)[0]]);

        expect(fn() => $engine->pairNextRound($state))
            ->toThrow(InvalidConfigurationException::class, 'The recorded round 2 of this re-seeded bracket cannot be read');
    });

    it('is read once the adjustments say their rounds', function () use ($fromRows): void {
        [$engine, $state] = $fromRows(new EliminationOptions(reseedEachRound: true), [new Withdrawal(timesField(16)[0], 1)]);

        $stated = StageState::fromHistory(
            $state->getEntrants(),
            $state->getRoundsPlayed(),
            $state->getResults(),
            $state->getWithdrawals(),
            array_map(static fn(Adjustment $adjustment): Adjustment => $adjustment->madeAfterRound(2), $state->getAdjustments())
        );

        expect($engine->pairNextRound($stated)->getLabel())->toBe('semifinal');
    });

    // Nobody had withdrawn before the round: every survivor is in a tie of
    // it or went on, whatever the order they were folded in, so the round is
    // read. The engine still has no round of its own to show beside it.
    it('is read by a re-seeded bracket where no entrant had withdrawn before the round, with no derivation for the rounds it could have changed', function (): void {
        // p1 withdrew after the quarter-finals were recorded: it is not in them
        // (it lost nothing: the bye of p8 is recorded), and nobody is left out
        $engine = new SingleEliminationEngine(new EliminationOptions(reseedEachRound: true));
        $field = timesField(8);
        $played = timesPlayRound($engine, timesPlayRound($engine, StageState::start($field)));

        $state = StageState::fromHistory(
            $field,
            $played->getRoundsPlayed(),
            $played->getResults(),
            [new Withdrawal($field[3], 2)],
            [new Adjustment('p2', -4.0)]
        );
        $rounds = $engine->getRecordedRounds($state);

        // The first round is the fold of the entrant list: no ranking, so
        // the adjustment has no say in it and the engine shows its round
        expect($rounds[0]->getDerivation())->toBeInstanceOf(RoundPairing::class)
            ->and($rounds[0]->isManual())->toBeFalse()
            ->and($rounds[1]->getDerivation())->toBeNull()
            ->and($rounds[1]->isManual())->toBeFalse()
            // Round 2 had p1 v p4 and p2 v p3; p1 and p2 won
            ->and(timesPairs($engine->pairNextRound($state)))->toBe(['p1 v p2'])
            // The adjustment counts in the table, whenever it was made
            ->and($engine->getStandings($state)->getEntries()[1]->getAdjustment())->toBe(-4.0);
    });

    it('is never refused by a bracket that ranks nothing: a fixed path and a double elimination bracket', function (string $variant) use ($fromRows): void {
        $field = timesField(16);

        if ($variant === 'fixed path') {
            [$engine, $state] = $fromRows(new EliminationOptions(), [new Withdrawal($field[0], 1)]);
        } else {
            $engine = new DoubleEliminationEngine();
            $played = timesPlayRound($engine, timesPlayRound($engine, StageState::start($field))->withoutParticipant($field[0]));
            $state = StageState::fromHistory(
                $field,
                $played->getRoundsPlayed(),
                $played->getResults(),
                [new Withdrawal($field[0], 1)],
                [new Adjustment('p7', 1.0), new Adjustment('p8', -4.0)]
            );
        }

        $rounds = $engine->getRecordedRounds($state);

        expect($rounds)->toHaveCount(2)
            ->and($rounds[1]->getDerivation())->toBeInstanceOf(RoundPairing::class)
            ->and($rounds[1]->isManual())->toBeFalse()
            ->and($engine->getReadiness($state)->isReady())->toBeTrue();
    })->with(['fixed path', 'double elimination']);

    // A Swiss round is paired from the table, and the table of the time is
    // not known. The engine shows no round of its own and reports nothing
    // as manual; it pairs the next round from every adjustment, as it must.
    it('leaves a Swiss stage without derivations, and counts in the table and in the round paired next', function (): void {
        $engine = new SwissPairingEngine(plannedRounds: 3);
        $field = timesField(6);

        $state = StageState::start($field);
        foreach ([1, 2] as $ignored) {
            $pairing = $engine->pairNextRound($state);
            $state = $state->withRoundPlayed($pairing, array_map(
                static fn(Event $event): Result => new Result($event, array_values($event->getParticipants())[0]),
                $pairing->getEvents()
            ));
        }

        $stated = $state->withAdjustment(new Adjustment('p6', 9.0));
        $unstated = StageState::fromHistory($field, $state->getRoundsPlayed(), $state->getResults(), [], [new Adjustment('p6', 9.0)]);

        $derivations = static fn(StageState $state): array => array_map(
            static fn(RecordedRound $round): array => [$round->getDerivation() instanceof RoundPairing, $round->isManual()],
            $engine->getRecordedRounds($state)
        );

        expect($derivations($stated))->toBe([[true, false], [true, false]])
            ->and($derivations($unstated))->toBe([[false, false], [false, false]])
            ->and(timesPairs($engine->pairNextRound($unstated)))->toBe(timesPairs($engine->pairNextRound($stated)))
            ->and($engine->getStandings($unstated)->getEntries()[0]->getParticipant()->getId())->toBe('p6');
    });
});

describe('a Swiss stage with adjustments and withdrawals between its rounds', function (): void {
    // Before the state said when, an adjustment counted in the table of
    // every round, so a round paired before it was reported as paired by
    // hand. A wider run of this sweep (600 stages of 4 to 16 participants
    // and 3 to 7 rounds, 2,680 rounds, with adjustments and withdrawals also
    // while a round was being played) reported 631 of the 912 rounds of the
    // stages that held an adjustment as manual before, and none after.
    it('reports none of the rounds the engine paired as manual', function (): void {
        $rounds = 0;
        $adjustments = 0;
        $withdrawals = 0;

        foreach ([5, 6, 8, 11] as $size) {
            foreach ([1, 2, 3, 4] as $seed) {
                $engine = new SwissPairingEngine(plannedRounds: 4);
                $rng = new Randomizer(new Mt19937(40 * $size + $seed));
                $field = timesField($size);
                $state = StageState::start($field);

                while (!$engine->isComplete($state)) {
                    if ($rng->getInt(0, 1) === 1) {
                        $state = $state->withAdjustment(new Adjustment($field[$rng->getInt(0, $size - 1)]->getId(), $rng->getInt(-6, 6) / 2.0));
                        ++$adjustments;
                    }

                    try {
                        $pairing = $engine->pairNextRound($state);
                    } catch (NoValidPairingException) {
                        // A small field that withdrawals have left without a pairing nobody has played
                        break;
                    }
                    $state = $state->withRoundPlayed($pairing, array_map(
                        static fn(Event $event): Result => new Result($event, array_values($event->getParticipants())[$rng->getInt(0, 1)]),
                        $pairing->getEvents()
                    ));

                    if ($rng->getInt(0, 2) === 0 && count($state->getParticipants()) > 3) {
                        $active = array_values($state->getParticipants());
                        $state = $state->withoutParticipant($active[$rng->getInt(0, count($active) - 1)]);
                        ++$withdrawals;
                    }

                    foreach ($engine->getRecordedRounds($state) as $round) {
                        expect($round->getDerivation())->toBeInstanceOf(RoundPairing::class)
                            ->and($round->isManual())->toBeFalse("{$size} participants, seed {$seed}, round {$round->getRoundNumber()}");
                    }
                }

                $rounds += count($state->getRoundsPlayed());
            }
        }

        expect($rounds)->toBeGreaterThan(40)
            ->and($adjustments)->toBeGreaterThan(15)
            ->and($withdrawals)->toBeGreaterThan(8);
    });
});

describe('a tie both of whose entrants have withdrawn', function (): void {
    /**
     * Four entrants on a fixed path: the semifinals are played, p1 and p2
     * are the finalists, and the final is recorded with no result or is
     * not paired at all.
     *
     * @return array{SingleEliminationEngine, StageState, list<Participant>}
     */
    $beforeTheFinal = function (bool $finalRecorded): array {
        $engine = new SingleEliminationEngine();
        $field = timesField(4);
        $state = timesPlayRound($engine, StageState::start($field));

        return [$engine, $finalRecorded ? $state->withRoundPlayed($engine->pairNextRound($state), []) : $state, $field];
    };

    // The first to withdraw gave the final away, and the other won the
    // bracket then. Its own withdrawal afterwards takes nothing back.
    it('was won by the one who withdrew second: an entrant who went on from an uncontested final and then withdraws is still first', function (
        bool $finalRecorded,
        int $first,
        int $second
    ) use ($beforeTheFinal): void {
        [$engine, $state, $field] = $beforeTheFinal($finalRecorded);

        $one = $state->withoutParticipant($field[$first]);
        $both = $one->withoutParticipant($field[$second]);

        foreach ([$one, $both] as $withdrawn) {
            $outcome = $engine->getOutcome($withdrawn);
            assert($outcome instanceof StageOutcome);

            expect($engine->isComplete($withdrawn))->toBeTrue()
                ->and($outcome->getStandings()->getEntries()[0]->getParticipant())->toBe($field[$second])
                ->and($outcome->getStandings()->getEntries()[1]->getParticipant())->toBe($field[$first])
                ->and($outcome->getStandings()->getTiers())->toBe([0, 1, 2, 2])
                ->and(MatchOutcomeSelector::winners()->select($outcome))->toBe([$field[$second]])
                ->and(MatchOutcomeSelector::losers()->select($outcome))->toBe([$field[$first]]);
        }
    })->with([
        'the final recorded, the second finalist leaves first' => [true, 1, 0],
        'the final recorded, the first finalist leaves first' => [true, 0, 1],
        'the final never paired, the second finalist leaves first' => [false, 1, 0],
        'the final never paired, the first finalist leaves first' => [false, 0, 1],
    ]);

    it('is read the same from rows: the withdrawals of one round in the order they happened', function () use ($beforeTheFinal): void {
        [$engine, $state, $field] = $beforeTheFinal(true);

        $rows = static fn(array $withdrawals): StageState => StageState::fromHistory(
            $field,
            $state->getRoundsPlayed(),
            $state->getResults(),
            $withdrawals
        );
        $first = static fn(StageState $state): ?string => $engine->getOutcome($state)?->getStandings()->getEntries()[0]->getParticipant()->getId();

        expect($first($rows([new Withdrawal($field[1], 2), new Withdrawal($field[0], 2)])))->toBe('p1')
            ->and($first($rows([new Withdrawal($field[0], 2), new Withdrawal($field[1], 2)])))->toBe('p2');
    });

    // Rows that do not say when: which of the two left first is not known,
    // and no later round names either. Neither is read as the winner.
    it('has no winner where the state does not know when the two withdrew: they share the tier of the final', function () use ($beforeTheFinal): void {
        [$engine, $state, $field] = $beforeTheFinal(true);

        $unstated = StageState::fromHistory($field, $state->getRoundsPlayed(), $state->getResults(), [$field[1], $field[0]]);
        $outcome = $engine->getOutcome($unstated);
        assert($outcome instanceof StageOutcome);

        expect($outcome->getStandings()->getTiers())->toBe([1, 1, 2, 2])
            ->and(timesIds(MatchOutcomeSelector::winners()->select($outcome)))->toBe([])
            ->and(timesIds(MatchOutcomeSelector::losers()->select($outcome)))->toBe(['p1', 'p2'])
            ->and($outcome->getSettledTies())->toEqual([new SettledTie(null, [$field[0], $field[1]])]);
    });

    it('is settled by its results where they are complete, whoever withdraws and in whatever order', function () use ($beforeTheFinal): void {
        [$engine, $state, $field] = $beforeTheFinal(true);
        $final = $state->getLastRound()?->getEvents()[0];
        assert($final instanceof Event);

        // p2 won the final; p2 withdraws first, then p1
        $played = $state->withAdditionalResults([new Result($final, $field[1])])
            ->withoutParticipant($field[1])
            ->withoutParticipant($field[0]);

        expect($engine->getOutcome($played)?->getStandings()->getEntries()[0]->getParticipant())->toBe($field[1]);
    });

    it('sends the one who withdrew second on from a double elimination tie too, and the first to the losers bracket', function (
        int $leavesFirst,
        int $leavesSecond
    ): void {
        $engine = new DoubleEliminationEngine();
        $field = timesField(4);

        // Winners round 1 (p1 v p4, p2 v p3) is recorded; both entrants of
        // its first tie leave before it is played, and p2 wins the other
        $state = StageState::start($field);
        $state = $state->withRoundPlayed($engine->pairNextRound($state), [])
            ->withoutParticipant($field[$leavesFirst])
            ->withoutParticipant($field[$leavesSecond]);
        $first = $state->getRoundsPlayed()[0];
        $state = $state->withAdditionalResults([new Result($first->getEvents()[1], $field[1])]);

        // The one who left second went on to the winners final, gives it to
        // p2 there and drops to the losers final, which it gives to p3. The
        // one who left first dropped at once and gave p3 the tie before
        // that. Nothing is left to play but the grand final.
        $next = $engine->pairNextRound($state);
        $tiers = timesTiers($engine->getStandings($state));

        expect($engine->getRecordedRounds($state)[0]->getUncontestedEvents())->toBe([$first->getEvents()[0]])
            ->and($next->getLabel())->toBe('grand final')
            ->and(timesPairs($next))->toBe(['p2 v p3'])
            ->and($tiers[$field[$leavesSecond]->getId()])->toBeLessThan($tiers[$field[$leavesFirst]->getId()]);
    })->with([
        'p4 leaves, then p1' => [3, 0],
        'p1 leaves, then p4' => [0, 3],
    ]);
});

describe('a round that is recorded after it was paired', function (): void {
    // To a state a round is paired at the moment it is recorded, and an
    // application may record a round only once it has been played: pair,
    // play, record, as the loop in the README does. A withdrawal while that
    // round is played is then recorded with the round before it. The round
    // names the entrant, so it was paired while the entrant was in the
    // stage, and it is read that way: the same as where the round was
    // recorded first and the withdrawal after it.

    /**
     * What an engine says is next, as plain values.
     *
     * @return list<mixed>
     *
     * @throws InvalidConfigurationException When the engine refuses the state
     */
    $next = static function (SingleEliminationEngine|DoubleEliminationEngine $engine, StageState $state): array {
        $readiness = $engine->getReadiness($state);
        if (!$readiness->isReady()) {
            return [$readiness->isComplete() ? 'complete' : 'awaiting', $readiness->getRoundNumber(), count($readiness->getUnresolvedEvents())];
        }
        $pairing = $engine->pairNextRound($state);

        return ['ready', $pairing->getRoundNumber(), $pairing->getLabel(), timesPairs($pairing), timesIds($pairing->getByes())];
    };

    it('is read as the round the engine paired when an entrant of it withdrew before it was recorded', function (
        SingleEliminationEngine|DoubleEliminationEngine $engine,
        int $fieldSize
    ) use ($next): void {
        $field = timesField($fieldSize);
        $state = timesPlayRound($engine, StageState::start($field));

        // The second round is paired, and an entrant of its first tie leaves
        $pairing = $engine->pairNextRound($state);
        $tie = $pairing->getEvents()[0];
        $leaves = array_values($tie->getParticipants())[0];

        $recordedWhenPaired = $state->withRoundPlayed($pairing, [])->withoutParticipant($leaves);
        $recordedWhenPlayed = $state->withoutParticipant($leaves)->withRoundPlayed($pairing, []);

        // The state's clock is the rounds it holds
        expect($recordedWhenPaired->getWithdrawals()[0]->getAfterRound())->toBe(2)
            ->and($recordedWhenPlayed->getWithdrawals()[0]->getAfterRound())->toBe(1);

        foreach ([$recordedWhenPaired, $recordedWhenPlayed] as $read) {
            $rounds = $engine->getRecordedRounds($read);

            // Both rounds are read, the second as the round the engine paired, with the tie nobody played
            expect($rounds)->toHaveCount(2)
                ->and($rounds[1]->getPairing())->toBe($pairing)
                ->and($rounds[1]->isManual())->toBeFalse()
                ->and($rounds[1]->getDerivation()?->getLabel())->toBe($pairing->getLabel())
                ->and(timesPairs($rounds[1]->getDerivation() ?? $rounds[0]->getPairing()))->toBe(timesPairs($pairing))
                ->and(timesIds($rounds[1]->getDerivation()?->getByes() ?? [$leaves]))->toBe(timesIds($pairing->getByes()))
                ->and($rounds[1]->getUncontestedEvents())->toBe([$tie]);
        }

        // And everything else is the one history it is
        expect($next($engine, $recordedWhenPlayed))->toBe($next($engine, $recordedWhenPaired))
            ->and(timesTiers($engine->getStandings($recordedWhenPlayed)))->toBe(timesTiers($engine->getStandings($recordedWhenPaired)));
    })->with([
        'single elimination, the final of three' => [new SingleEliminationEngine(), 3],
        'single elimination, the semifinals of eight' => [new SingleEliminationEngine(), 8],
        'single elimination, re-seeded' => [new SingleEliminationEngine(new EliminationOptions(reseedEachRound: true)), 8],
        'single elimination with a third-place tie, the last round of four' => [new SingleEliminationEngine(new EliminationOptions(thirdPlace: true)), 4],
        // This state was refused for good: the winners final was read as a round nobody played, and the round as the grand final
        'double elimination, the winners final of three' => [new DoubleEliminationEngine(), 3],
        'double elimination, the first losers round of eight' => [new DoubleEliminationEngine(), 8],
    ]);

    it('is read the same from rows that state the round before it for the withdrawal, and from the stored state', function (): void {
        $engine = new DoubleEliminationEngine();
        $field = timesField(3);
        $state = timesPlayRound($engine, StageState::start($field));
        $pairing = $engine->pairNextRound($state);
        $leaves = array_values($pairing->getEvents()[0]->getParticipants())[0];
        $state = $state->withoutParticipant($leaves)->withRoundPlayed($pairing, []);

        $fromRows = StageState::fromHistory($field, $state->getRoundsPlayed(), $state->getResults(), [new Withdrawal($leaves, 1)]);

        expect($fromRows->toArray())->toBe($state->toArray())
            ->and($engine->getRecordedRounds($fromRows))->toHaveCount(2)
            ->and($engine->getRecordedRounds(StageState::fromJson($state->toJson())))->toHaveCount(2)
            ->and($engine->getReadiness($fromRows)->isReady())->toBeTrue();
    });

    it('decides a tie both entrants left before it was recorded by who left first', function (int $first, int $second): void {
        $engine = new SingleEliminationEngine();
        $field = timesField(4);
        $state = timesPlayRound($engine, StageState::start($field));

        // The final, p1 v p2, is paired; both leave; it is recorded with no result
        $final = $engine->pairNextRound($state);
        $state = $state->withoutParticipant($field[$first])->withoutParticipant($field[$second])->withRoundPlayed($final, []);

        $outcome = $engine->getOutcome($state);
        assert($outcome instanceof StageOutcome);

        expect($engine->getRecordedRounds($state))->toHaveCount(2)
            ->and($outcome->getSettledTies())->toEqual([new SettledTie($field[$second], [$field[$first]])])
            ->and(MatchOutcomeSelector::winners()->select($outcome))->toBe([$field[$second]]);
    })->with([
        'p1, then p2' => [0, 1],
        'p2, then p1' => [1, 0],
    ]);

    it('is read as the round the Swiss engine paired when a participant of it withdrew before it was recorded', function (): void {
        $engine = new SwissPairingEngine(plannedRounds: 3);
        $state = StageState::start(timesField(8));
        $first = $engine->pairNextRound($state);
        $state = $state->withRoundPlayed($first, array_map(
            static fn(Event $event): Result => new Result($event, array_values($event->getParticipants())[0]),
            $first->getEvents()
        ));

        $pairing = $engine->pairNextRound($state);
        $leaves = array_values($pairing->getEvents()[0]->getParticipants())[1];
        $state = $state->withoutParticipant($leaves)->withRoundPlayed($pairing, []);

        $rounds = $engine->getRecordedRounds($state);

        expect($rounds[1]->isManual())->toBeFalse()
            ->and(timesPairs($rounds[1]->getDerivation() ?? $first))->toBe(timesPairs($pairing));
    });

    // The same reading where the withdrawal states no round at all: an
    // entrant a round names was in the stage when that round was paired.
    it('reads an entrant a round lists among its byes as in that round, also where its withdrawal states no round', function (): void {
        $engine = new SingleEliminationEngine();
        $field = timesField(8);

        // p1, p2, p3 and p4 reach the semifinals, p1 v p4 and p2 v p3. p4
        // withdraws, so p1 has a bye in them; p2 wins the other. Then p1
        // withdraws, and p2 has the final without playing it.
        $state = timesPlayRound($engine, StageState::start($field))->withoutParticipant($field[3]);
        $semifinals = $engine->pairNextRound($state);
        $state = timesPlayRound($engine, $state)->withoutParticipant($field[0]);

        $unstated = StageState::fromHistory($field, $state->getRoundsPlayed(), $state->getResults(), [$field[3], $field[0]]);
        $outcome = $engine->getOutcome($unstated);
        assert($outcome instanceof StageOutcome);

        // p1 went on from the semifinals and is second; it did not go out in them with p4
        expect(timesIds($semifinals->getByes()))->toBe(['p1'])
            ->and(timesTiers($engine->getStandings($unstated)))->toBe(timesTiers($engine->getStandings($state)))
            ->and($outcome->getSettledTies())->toEqual([new SettledTie($field[1], [$field[0]])])
            ->and(timesTiers($outcome->getStandings())['p1'])->toBe(1)
            ->and(timesPairs($engine->getRecordedRounds($unstated)[0]->getDerivation() ?? $semifinals))
            ->toBe(timesPairs($state->getRoundsPlayed()[0]));
    });

    // What the rounds cannot put right: nothing in a round says which
    // adjustments it was paired from. An adjustment made while a round is
    // played, in a state that records the round only afterwards, is read as
    // known when the round was paired. Recording the round when it is
    // paired is how an application says otherwise.
    it('reads an adjustment recorded before a round as known when the round was paired, and one recorded after it as not', function (): void {
        $engine = new SingleEliminationEngine(new EliminationOptions(reseedEachRound: true));
        $field = timesField(8);
        $state = timesPlayRound($engine, StageState::start($field));

        // The semifinals are paired from a table on which all four are level: p1 v p4, p2 v p3
        $pairing = $engine->pairNextRound($state);
        $adjustment = new Adjustment('p4', 9.0);

        $recordedWhenPaired = $state->withRoundPlayed($pairing, [])->withAdjustment($adjustment);
        $recordedWhenPlayed = $state->withAdjustment($adjustment)->withRoundPlayed($pairing, []);

        expect(timesPairs($pairing))->toBe(['p1 v p4', 'p2 v p3'])
            ->and($engine->getRecordedRounds($recordedWhenPaired)[1]->isManual())->toBeFalse()
            // With p4 nine points up the engine folds p4 v p3 and p1 v p2: the round is not that one
            ->and($engine->getRecordedRounds($recordedWhenPlayed)[1]->isManual())->toBeTrue()
            ->and(timesPairs($engine->getRecordedRounds($recordedWhenPlayed)[1]->getDerivation() ?? $pairing))->toBe(['p3 v p4', 'p1 v p2']);
    });
});

describe('the match outcome selector over a bracket a finalist withdrew from', function (): void {
    /**
     * A bracket of four played up to its last tie, which is between the
     * two entrants returned.
     *
     * @return array{SingleEliminationEngine|DoubleEliminationEngine, StageState, Participant, Participant}
     */
    $lastTie = function (string $variant): array {
        $engine = $variant === 'single' ? new SingleEliminationEngine() : new DoubleEliminationEngine();
        $state = StageState::start(timesField(4));
        for ($rounds = $variant === 'single' ? 1 : 4; $rounds > 0; --$rounds) {
            $state = timesPlayRound($engine, $state);
        }
        [$first, $second] = array_values($engine->pairNextRound($state)->getEvents()[0]->getParticipants());

        return [$engine, $state, $first, $second];
    };

    it('gives the finalist who stayed as the winner and the one who left as the loser', function (string $variant, bool $finalRecorded) use ($lastTie): void {
        [$engine, $state, $stays, $leaves] = $lastTie($variant);

        if ($finalRecorded) {
            $state = $state->withRoundPlayed($engine->pairNextRound($state), []);
        }
        $state = $state->withoutParticipant($leaves);

        $outcome = $engine->getOutcome($state);
        assert($outcome instanceof StageOutcome);

        expect($engine->isComplete($state))->toBeTrue()
            ->and($outcome->getStandings()->getEntries()[0]->getParticipant())->toBe($stays)
            ->and(MatchOutcomeSelector::winners()->select($outcome))->toBe([$stays])
            ->and(MatchOutcomeSelector::losers()->select($outcome))->toBe([$leaves])
            ->and($outcome->getSettledTies())->toEqual([new SettledTie($stays, [$leaves])])
            // Where the final was never paired the last round on record is the round before it
            ->and($outcome->getFinalRound())->toBe($state->getLastRound());
    })->with([
        'single elimination, the final recorded without a result' => ['single', true],
        'single elimination, the final never paired' => ['single', false],
        'double elimination, the grand final recorded without a result' => ['double', true],
        'double elimination, the grand final never paired' => ['double', false],
    ]);

    it('gives the same selection as the results do for a final that was played', function (string $variant) use ($lastTie): void {
        [$engine, $state] = $lastTie($variant);
        $state = timesPlayRound($engine, $state);

        $outcome = $engine->getOutcome($state);
        assert($outcome instanceof StageOutcome);
        $fromResults = new StageOutcome($outcome->getStandings(), $outcome->getResults(), $outcome->getByes(), $outcome->getFinalRound());

        expect($outcome->getSettledTies())->toHaveCount(1)
            ->and(MatchOutcomeSelector::winners()->select($outcome))->toBe(MatchOutcomeSelector::winners()->select($fromResults))
            ->and(MatchOutcomeSelector::losers()->select($outcome))->toBe(MatchOutcomeSelector::losers()->select($fromResults));
    })->with(['single', 'double']);

    it('carries the reset as the settled tie of a double elimination bracket whose grand final the losers champion won', function (): void {
        $engine = new DoubleEliminationEngine();
        $state = StageState::start(timesField(4));
        for ($rounds = 4; $rounds > 0; --$rounds) {
            $state = timesPlayRound($engine, $state);
        }

        // The losers champion wins the grand final, and the reset
        foreach (['grand final', 'grand final reset'] as $label) {
            $pairing = $engine->pairNextRound($state);
            [$winnersChampion, $losersChampion] = array_values($pairing->getEvents()[0]->getParticipants());
            expect($pairing->getLabel())->toBe($label);
            $state = $state->withRoundPlayed($pairing, [new Result($pairing->getEvents()[0], $losersChampion)]);
        }

        $outcome = $engine->getOutcome($state);
        assert($outcome instanceof StageOutcome && isset($winnersChampion, $losersChampion));

        expect($outcome->getSettledTies())->toEqual([new SettledTie($losersChampion, [$winnersChampion])])
            ->and(MatchOutcomeSelector::winners()->select($outcome))->toBe([$losersChampion]);
    });

    it('carries no settled ties on the outcome of a Swiss stage, which the selector reads from its results', function (): void {
        $engine = new SwissPairingEngine(plannedRounds: 1);
        $state = StageState::start(timesField(4));
        $pairing = $engine->pairNextRound($state);
        $state = $state->withRoundPlayed($pairing, array_map(
            static fn(Event $event): Result => new Result($event, array_values($event->getParticipants())[1]),
            $pairing->getEvents()
        ));

        $outcome = $engine->getOutcome($state);
        assert($outcome instanceof StageOutcome);

        expect($outcome->getSettledTies())->toBeNull()
            ->and(MatchOutcomeSelector::winners()->select($outcome))->toHaveCount(2);
    });
});
