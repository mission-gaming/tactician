<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Exceptions\InvalidInputException;
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
     * and of none of the adjustments.
     *
     * @throws InvalidConfigurationException When the history is refused
     */
    $fromRows = function (SingleEliminationEngine|DoubleEliminationEngine $engine): StageState {
        $field = timesField(16);
        $played = timesPlayRound($engine, timesPlayRound($engine, StageState::start($field))->withoutParticipant($field[0]));

        return StageState::fromHistory(
            $field,
            $played->getRoundsPlayed(),
            $played->getResults(),
            [new Withdrawal($field[0], 1)],
            [new Adjustment('p7', 1.0), new Adjustment('p8', -4.0), new Adjustment('p4', -4.0)]
        );
    };

    // A round is read from the adjustments made before it was paired, and
    // nobody knows whether one without its round was among them. Once a
    // history holds a round every adjustment says its round, as every
    // withdrawal does (ADR 0012), so no engine is ever handed an adjustment
    // whose time it would have to guess.
    it('is refused in a history that holds rounds, with the participant and the last round', function (
        SingleEliminationEngine|DoubleEliminationEngine $engine
    ) use ($fromRows): void {
        try {
            $fromRows($engine);
            $this->fail('The history was accepted.');
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getReason())->toBe(InvalidConfigurationReason::AdjustmentWithoutRound)
                ->and($exception->getContext())->toBe(['participant' => 'p7', 'last_round' => 2])
                ->and($exception->getConfigurationIssue())->toContain('Adjustment::madeAfterRound()');
        }
    })->with([
        'fixed path' => [new SingleEliminationEngine()],
        're-seeded' => [new SingleEliminationEngine(new EliminationOptions(reseedEachRound: true))],
        'double elimination' => [new DoubleEliminationEngine()],
    ]);

    it('is refused in a Swiss history that holds rounds', function (): void {
        $engine = new SwissPairingEngine(plannedRounds: 3);
        $field = timesField(6);
        $pairing = $engine->pairNextRound(StageState::start($field));

        expect(fn() => StageState::fromHistory($field, [$pairing], [], [], [new Adjustment('p6', 9.0)]))
            ->toThrow(InvalidConfigurationException::class, 'does not say after which round it was made');
    });

    it('is read once the adjustments say their rounds', function (): void {
        $engine = new SingleEliminationEngine(new EliminationOptions(reseedEachRound: true));
        $field = timesField(16);
        $played = timesPlayRound($engine, timesPlayRound($engine, StageState::start($field))->withoutParticipant($field[0]));

        $stated = StageState::fromHistory(
            $field,
            $played->getRoundsPlayed(),
            $played->getResults(),
            [new Withdrawal($field[0], 1)],
            [
                (new Adjustment('p7', 1.0))->madeAfterRound(2),
                (new Adjustment('p8', -4.0))->madeAfterRound(2),
                (new Adjustment('p4', -4.0))->madeAfterRound(2),
            ]
        );

        expect($engine->pairNextRound($stated)->getLabel())->toBe('semifinal');
    });

    // Before the first round there is only one time it can have been made
    it('is made after round 0 in a history with no round, as withAdjustment() records it', function (): void {
        $field = timesField(4);

        $fromRows = StageState::fromHistory($field, [], [], [], [new Adjustment('p3', 2.0)]);
        $live = StageState::start($field)->withAdjustment(new Adjustment('p3', 2.0));

        expect($fromRows->getAdjustments()[0]->getAfterRound())->toBe(0)
            ->and($fromRows->toArray())->toBe($live->toArray());
    });

    // Only a revision of 0.3.0 that was never released stored an adjustment
    // without its round (0.2.x stored no adjustments): a stored state with
    // rounds is refused as rows are, and one with none is read as after
    // round 0.
    it('is refused in a stored state that holds rounds, and read as after round 0 in one that holds none', function (): void {
        $field = timesField(4);
        $engine = new SingleEliminationEngine();

        $withRounds = timesPlayRound($engine, StageState::start($field))->withAdjustment(new Adjustment('p3', 2.0))->toArray();
        unset($withRounds['adjustments'][0]['after_round']);
        $withoutRounds = StageState::start($field)->withAdjustment(new Adjustment('p3', 2.0))->toArray();
        unset($withoutRounds['adjustments'][0]['after_round']);

        expect(fn() => StageState::fromArray($withRounds))->toThrow(InvalidInputException::class, 'does not say after which round it was made')
            ->and(StageState::fromArray($withoutRounds)->getAdjustments()[0]->getAfterRound())->toBe(0);
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
    // If both entrants of a tie withdraw, nobody is there to go through
    // (ADR 0011). A tie its results do not decide is given to the entrant
    // who stays where one of the two has left; it goes to nobody where both
    // have left before the bracket moved on from it, which is before the
    // round after it was paired, or for the last tie of a bracket at any
    // time. A tie its results decide is never void.

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

    it('sends nobody on from a final both finalists left before it was played, in whichever order they left', function (
        bool $finalRecorded,
        int $first,
        int $second
    ) use ($beforeTheFinal): void {
        [$engine, $state, $field] = $beforeTheFinal($finalRecorded);

        // One finalist leaves: the other has the final without playing it
        $one = $state->withoutParticipant($field[$first]);
        $outcome = $engine->getOutcome($one);
        assert($outcome instanceof StageOutcome);

        expect(MatchOutcomeSelector::winners()->select($outcome))->toBe([$field[$second]])
            ->and($outcome->getStandings()->getTiers())->toBe([0, 1, 2, 2]);

        // The other leaves too: there is nobody to take the final. The
        // bracket is over, with no entrant in the first tier: the two
        // finalists share the tier of the final, in the order of their
        // records and then of the entrant list
        $both = $one->withoutParticipant($field[$second]);
        $outcome = $engine->getOutcome($both);
        assert($outcome instanceof StageOutcome);

        expect($engine->isComplete($both))->toBeTrue()
            ->and($engine->getReadiness($both)->isComplete())->toBeTrue()
            ->and(timesIds(array_map(static fn($entry): Participant => $entry->getParticipant(), $outcome->getStandings()->getEntries())))
            ->toBe(['p1', 'p2', 'p3', 'p4'])
            ->and($outcome->getStandings()->getTiers())->toBe([1, 1, 2, 2])
            ->and($outcome->getSettledTies())->toEqual([new SettledTie(null, [$field[0], $field[1]])])
            ->and(MatchOutcomeSelector::winners()->select($outcome))->toBe([])
            ->and(MatchOutcomeSelector::losers()->select($outcome))->toBe([$field[0], $field[1]]);
    })->with([
        'the final recorded, the second finalist leaves first' => [true, 1, 0],
        'the final recorded, the first finalist leaves first' => [true, 0, 1],
        'the final never paired, the second finalist leaves first' => [false, 1, 0],
        'the final never paired, the first finalist leaves first' => [false, 0, 1],
    ]);

    it('is read the same from rows, whatever the order of the withdrawals', function () use ($beforeTheFinal): void {
        [$engine, $state, $field] = $beforeTheFinal(true);

        $rows = static fn(array $withdrawals): StageState => StageState::fromHistory(
            $field,
            $state->getRoundsPlayed(),
            $state->getResults(),
            $withdrawals
        );
        $settled = static fn(StageState $state): ?array => $engine->getOutcome($state)?->getSettledTies();

        expect($settled($rows([new Withdrawal($field[1], 2), new Withdrawal($field[0], 2)])))->toEqual([new SettledTie(null, [$field[0], $field[1]])])
            ->and($settled($rows([new Withdrawal($field[0], 2), new Withdrawal($field[1], 2)])))->toEqual([new SettledTie(null, [$field[0], $field[1]])]);
    });

    it('is settled by its results where they are complete, whoever withdraws and in whatever order', function () use ($beforeTheFinal): void {
        [$engine, $state, $field] = $beforeTheFinal(true);
        $final = $state->getLastRound()?->getEvents()[0];
        assert($final instanceof Event);

        // p2 won the final; p2 withdraws first, then p1
        $played = $state->withAdditionalResults([new Result($final, $field[1])])
            ->withoutParticipant($field[1])
            ->withoutParticipant($field[0]);
        $outcome = $engine->getOutcome($played);
        assert($outcome instanceof StageOutcome);

        expect($outcome->getStandings()->getEntries()[0]->getParticipant())->toBe($field[1])
            ->and($outcome->getStandings()->getTiers())->toBe([0, 1, 2, 2])
            ->and(MatchOutcomeSelector::winners()->select($outcome))->toBe([$field[1]]);
    });

    // An entrant who went on from a tie nobody played was named by the round
    // after it, which was paired while it was in the stage: what it left
    // later is the next tie, which its opponent there takes.
    it('sends on the entrant who was still in the stage when the round after the tie was paired', function (): void {
        $engine = new SingleEliminationEngine();
        $field = timesField(8);
        $state = timesPlayRound($engine, StageState::start($field));

        // The semifinals are p1 v p4 and p2 v p3. p4 leaves, so p1 has the
        // tie; p2 beats p3; the final p1 v p2 is paired; then p1 leaves
        $state = $state->withoutParticipant($field[3]);
        $semifinals = $engine->pairNextRound($state);
        $state = timesPlayRound($engine, $state);
        $final = $engine->pairNextRound($state);
        $state = $state->withRoundPlayed($final, [])->withoutParticipant($field[0]);
        $outcome = $engine->getOutcome($state);
        assert($outcome instanceof StageOutcome);

        expect(timesIds($semifinals->getByes()))->toBe(['p1'])
            ->and(timesPairs($final))->toBe(['p1 v p2'])
            ->and($outcome->getSettledTies())->toEqual([new SettledTie($field[1], [$field[0]])])
            ->and(timesTiers($outcome->getStandings()))->toMatchArray(['p1' => 1, 'p2' => 0, 'p3' => 2, 'p4' => 2]);
    });

    /**
     * Four entrants: the semifinals p1 v p4 and p2 v p3 are recorded with
     * no result, p1 and p4 leave in the given order, and p2 beats p3.
     *
     * @return array{StageState, list<Participant>}
     */
    $voidSemifinal = function (SingleEliminationEngine $engine, int $first, int $second): array {
        $field = timesField(4);
        $start = StageState::start($field);
        $semifinals = $engine->pairNextRound($start);
        $state = $start->withRoundPlayed($semifinals, [])
            ->withoutParticipant($field[$first])
            ->withoutParticipant($field[$second]);
        $other = array_values(array_filter(
            $semifinals->getEvents(),
            static fn(Event $event): bool => in_array($field[1], $event->getParticipants(), true)
        ));

        return [$state->withAdditionalResults([new Result($other[0], $field[1])]), $field];
    };

    it('leaves the slot of a void semifinal empty: the other finalist has the final without playing it', function (int $first, int $second) use ($voidSemifinal): void {
        $engine = new SingleEliminationEngine();
        [$state, $field] = $voidSemifinal($engine, $first, $second);
        $outcome = $engine->getOutcome($state);
        assert($outcome instanceof StageOutcome);

        // No final is paired; p2 takes it. Nobody lost it: the two of the
        // void tie went out in the semifinals, with p3
        expect($engine->isComplete($state))->toBeTrue()
            ->and($state->getRoundsPlayed())->toHaveCount(1)
            ->and($engine->getRecordedRounds($state)[0]->getUncontestedEvents())->toHaveCount(1)
            ->and($outcome->getSettledTies())->toEqual([new SettledTie($field[1])])
            ->and(MatchOutcomeSelector::winners()->select($outcome))->toBe([$field[1]])
            ->and(MatchOutcomeSelector::losers()->select($outcome))->toBe([])
            ->and(timesTiers($outcome->getStandings()))->toMatchArray(['p1' => 2, 'p2' => 0, 'p3' => 2, 'p4' => 2]);
    })->with([
        'p1, then p4' => [0, 3],
        'p4, then p1' => [3, 0],
    ]);

    it('gives the third place to the loser of the other semifinal without a tie when a semifinal is void', function (int $first, int $second) use ($voidSemifinal): void {
        $engine = new SingleEliminationEngine(new EliminationOptions(thirdPlace: true));
        [$state, $field] = $voidSemifinal($engine, $first, $second);
        $outcome = $engine->getOutcome($state);
        assert($outcome instanceof StageOutcome);

        // Neither the final nor the third-place tie has anyone to play it:
        // p2 is first and p3 third, and the two of the void tie share the
        // fourth place
        expect($engine->isComplete($state))->toBeTrue()
            ->and($state->getRoundsPlayed())->toHaveCount(1)
            ->and(timesTiers($outcome->getStandings()))->toMatchArray(['p1' => 3, 'p2' => 0, 'p3' => 2, 'p4' => 3])
            ->and(MatchOutcomeSelector::winners()->select($outcome))->toBe([$field[1]]);
    })->with([
        'p1, then p4' => [0, 3],
        'p4, then p1' => [3, 0],
    ]);

    it('lists the entrant who takes the third place without a tie among the byes of the last round', function (): void {
        $engine = new SingleEliminationEngine(new EliminationOptions(thirdPlace: true));
        $field = timesField(4);

        // p1 and p2 win the semifinals; p4, who lost to p1, withdraws before the last round is paired
        $state = timesPlayRound($engine, StageState::start($field))->withoutParticipant($field[3]);
        $last = $engine->pairNextRound($state);

        expect(timesPairs($last))->toBe(['p1 v p2'])
            ->and(timesIds($last->getByes()))->toBe(['p3']);
    });

    // Who went on from a winners tie both entrants have left is read at the
    // next winners round, and the other of the two is the one who dropped:
    // the losers bracket it dropped into is read before that round
    it('drops the entrant who did not go on from a winners tie both entrants have left, where one did', function (): void {
        $engine = new DoubleEliminationEngine();
        $field = timesField(3);

        // Winners round 1 is p2 v p3 (p1 has a bye); p3 leaves before it is
        // played, so p2 has the tie and p3 drops. The winners final p1 v p2
        // is paired and won by p1, and p2 leaves afterwards.
        $state = StageState::start($field);
        $state = $state->withRoundPlayed($engine->pairNextRound($state), [])->withoutParticipant($field[2]);
        $winnersFinal = $engine->pairNextRound($state);
        $state = $state->withRoundPlayed($winnersFinal, [new Result($winnersFinal->getEvents()[0], $field[0])])
            ->withoutParticipant($field[1]);

        // p2 was in the stage when the winners final was paired: it went
        // through winners round 1, and p3 dropped. p3 stands alone in the
        // first losers round and had withdrawn by then, so it goes out
        // there; p2, dropped from the winners final, stands alone in the
        // losers final, and goes out there. p1 has the grand final without
        // playing it. Read as a void tie instead, winners round 1 would
        // have put both p2 and p3 out in the first losers round.
        expect($winnersFinal->getLabel())->toBe('winners final')
            ->and(timesPairs($winnersFinal))->toBe(['p1 v p2'])
            ->and($engine->isComplete($state))->toBeTrue()
            ->and(timesTiers($engine->getStandings($state)))->toBe(['p1' => 0, 'p2' => 2, 'p3' => 3]);
    });

    it('drops nobody to the losers bracket from a void winners tie, and puts both of its entrants out', function (int $first, int $second): void {
        $engine = new DoubleEliminationEngine();
        $field = timesField(4);

        // Winners round 1 (p1 v p4, p2 v p3) is recorded; both entrants of
        // its first tie leave before it is played, and p2 wins the other
        $state = StageState::start($field);
        $state = $state->withRoundPlayed($engine->pairNextRound($state), [])
            ->withoutParticipant($field[$first])
            ->withoutParticipant($field[$second]);
        $round = $state->getRoundsPlayed()[0];
        $state = $state->withAdditionalResults([new Result($round->getEvents()[1], $field[1])]);

        // p2 has the winners final and p3 the losers bracket without
        // playing: nothing is left to play but the grand final. p1 and p4
        // went out together, below everyone else
        $next = $engine->pairNextRound($state);
        $tiers = timesTiers($engine->getStandings($state));

        expect($engine->getRecordedRounds($state)[0]->getUncontestedEvents())->toBe([$round->getEvents()[0]])
            ->and($next->getLabel())->toBe('grand final')
            ->and($next->getRoundNumber())->toBe(2)
            ->and(timesPairs($next))->toBe(['p2 v p3'])
            ->and($tiers['p1'])->toBe($tiers['p4'])
            ->and($tiers['p1'])->toBeGreaterThan(max($tiers['p2'], $tiers['p3']));
    })->with([
        'p4 leaves, then p1' => [3, 0],
        'p1 leaves, then p4' => [0, 3],
    ]);

    it('sends nobody on from a void losers tie: the entrant it would have met goes on alone', function (): void {
        $engine = new DoubleEliminationEngine();
        $field = timesField(8);

        // Winners round 1 is played (p1 to p4 win); losers round 1 is p5 v
        // p8 and p6 v p7, and both of the first leave before it is played
        $state = timesPlayRound($engine, StageState::start($field));
        $losers = $engine->pairNextRound($state);
        $state = $state->withRoundPlayed($losers, [])->withoutParticipant($field[4])->withoutParticipant($field[7]);
        $state = $state->withAdditionalResults([new Result($losers->getEvents()[1], $field[5])]);

        // The winners semifinals follow; in the losers round after them the
        // dropper the void tie's winner would have met goes on alone
        $state = timesPlayRound($engine, $state);
        $major = $engine->pairNextRound($state);
        $tiers = timesTiers($engine->getStandings($state));

        expect(timesPairs($losers))->toBe(['p5 v p8', 'p6 v p7'])
            ->and($major->getEvents())->toHaveCount(1)
            ->and($major->getByes())->toHaveCount(1)
            ->and($tiers['p5'])->toBe($tiers['p8'])
            ->and($tiers['p7'])->toBe($tiers['p5']);
    });

    it('sends nobody on from a grand final, or its reset, both finalists left before it was played', function (bool $reset): void {
        $engine = new DoubleEliminationEngine();
        $state = StageState::start(timesField(4));
        for ($rounds = 4; $rounds > 0; --$rounds) {
            $state = timesPlayRound($engine, $state);
        }

        $grandFinal = $engine->pairNextRound($state);
        [$winnersChampion, $losersChampion] = array_values($grandFinal->getEvents()[0]->getParticipants());
        if ($reset) {
            // The losers champion wins the grand final, and the reset is due
            $state = $state->withRoundPlayed($grandFinal, [new Result($grandFinal->getEvents()[0], $losersChampion)]);
        }
        $last = $engine->pairNextRound($state);
        $state = $state->withRoundPlayed($last, [])->withoutParticipant($winnersChampion)->withoutParticipant($losersChampion);

        $outcome = $engine->getOutcome($state);
        assert($outcome instanceof StageOutcome);
        $tiers = $outcome->getStandings()->getTiers();

        expect($last->getLabel())->toBe($reset ? 'grand final reset' : 'grand final')
            ->and($engine->isComplete($state))->toBeTrue()
            ->and($outcome->getSettledTies())->toEqual([new SettledTie(null, [$winnersChampion, $losersChampion])])
            ->and(MatchOutcomeSelector::winners()->select($outcome))->toBe([])
            ->and($tiers[0])->toBe($tiers[1])
            ->and($tiers[0])->toBeGreaterThan(0)
            ->and($tiers[2])->toBeGreaterThan($tiers[1]);
    })->with([
        'the grand final' => [false],
        'the reset' => [true],
    ]);

    // A void tie leaves the slot it would have filled empty, so the entrant
    // who would have met its winner stands alone. Where that entrant had
    // withdrawn by the time its tie was due, nobody is there to go through
    // either: it goes out in that round, and a last tie it stands alone in
    // has no winner
    it('crowns nobody where the only entrant left to take the last tie had withdrawn, in whichever order everyone left', function (
        SingleEliminationEngine|DoubleEliminationEngine $engine,
        array $order
    ): void {
        $field = timesField(3);

        // p1 has a bye; p2 v p3 is recorded with no result; then all three leave
        $state = StageState::start($field);
        $first = $engine->pairNextRound($state);
        $state = $state->withRoundPlayed($first, []);
        foreach ($order as $index) {
            $state = $state->withoutParticipant($field[$index]);
        }

        $outcome = $engine->getOutcome($state);
        assert($outcome instanceof StageOutcome);
        $tiers = timesTiers($outcome->getStandings());

        expect(timesPairs($first))->toBe(['p2 v p3'])
            ->and($engine->isComplete($state))->toBeTrue()
            ->and(MatchOutcomeSelector::winners()->select($outcome))->toBe([])
            ->and($tiers['p1'])->toBeGreaterThan(0)
            ->and($tiers['p1'])->toBeLessThan($tiers['p2'])
            ->and($tiers['p2'])->toBe($tiers['p3']);
    })->with([
        'single elimination, p1 leaves last' => [new SingleEliminationEngine(), [1, 2, 0]],
        'single elimination, p1 leaves first' => [new SingleEliminationEngine(), [0, 1, 2]],
        'double elimination, p1 leaves last' => [new DoubleEliminationEngine(), [1, 2, 0]],
        'double elimination, p1 leaves first' => [new DoubleEliminationEngine(), [0, 1, 2]],
    ]);

    it('puts out in its round an entrant who had withdrawn when it stood alone there, and not a round later', function (): void {
        $engine = new SingleEliminationEngine();
        $field = timesField(8);

        // The quarter-finals are p1 v p8, p4 v p5, p2 v p7 and p3 v p6. All
        // but p4 v p5 are won by the lower number; p4 and p5 leave, and so
        // does p1, who won its tie
        $state = StageState::start($field);
        $quarterFinals = $engine->pairNextRound($state);
        $results = [];
        foreach ($quarterFinals->getEvents() as $event) {
            $ids = timesIds(array_values($event->getParticipants()));
            sort($ids, SORT_NATURAL);
            if ($ids[0] !== 'p4') {
                $results[] = new Result($event, $field[(int) substr($ids[0], 1) - 1]);
            }
        }
        $state = $state->withRoundPlayed($quarterFinals, $results)
            ->withoutParticipant($field[3])
            ->withoutParticipant($field[4])
            ->withoutParticipant($field[0]);

        // p1 would have met the winner of the void tie, and nobody is in
        // its semifinal: only p2 v p3 is played, and p1 is nobody's bye
        $semifinals = $engine->pairNextRound($state);
        $state = $state->withRoundPlayed($semifinals, [new Result($semifinals->getEvents()[0], $field[1])]);
        $outcome = $engine->getOutcome($state);
        assert($outcome instanceof StageOutcome);
        $tiers = timesTiers($outcome->getStandings());

        // p2 has the final alone. p1 went out in the semifinals with p3,
        // and is no finalist: the final has nobody but p2
        expect(timesPairs($semifinals))->toBe(['p2 v p3'])
            ->and($semifinals->getByes())->toBe([])
            ->and(MatchOutcomeSelector::winners()->select($outcome))->toBe([$field[1]])
            ->and(MatchOutcomeSelector::losers()->select($outcome))->toBe([])
            ->and($outcome->getSettledTies())->toEqual([new SettledTie($field[1], [])])
            ->and($tiers['p2'])->toBe(0)
            ->and($tiers['p1'])->toBe($tiers['p3'])
            ->and($tiers['p4'])->toBe($tiers['p5'])
            ->and($tiers['p4'])->toBeGreaterThan($tiers['p1']);
    });
});

describe('a withdrawal says after which round it happened', function (): void {
    // A history that does not say when an entrant withdrew is read
    // otherwise than it was played as soon as two withdrawals meet in a
    // tie, so once rounds are supplied every withdrawal states its round.

    it('is refused without its round once the history supplies rounds, with the participant and the last round', function (): void {
        $engine = new SingleEliminationEngine();
        $field = timesField(4);
        $first = $engine->pairNextRound(StageState::start($field));

        try {
            StageState::fromHistory($field, [$first], [], [new Withdrawal($field[0], 1), $field[2]]);
            $this->fail('A withdrawal without its round was accepted.');
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getReason())->toBe(InvalidConfigurationReason::WithdrawalWithoutRound)
                ->and($exception->getContext())->toBe(['participant' => 'p3', 'last_round' => 1])
                ->and($exception->getMessage())->toContain('Withdrawal');
        }
    });

    it('may be a plain participant where no round is supplied: the stage is drawn without it', function (): void {
        $field = timesField(4);

        $state = StageState::fromHistory($field, [], [], [$field[2]]);

        expect($state->toArray())->toBe(StageState::fromHistory($field, [], [], [new Withdrawal($field[2], 0)])->toArray())
            ->and($state->toArray())->toBe(StageState::start($field)->withoutParticipant($field[2])->toArray())
            ->and(timesIds($state->getEntrants()))->toBe(['p1', 'p2', 'p4'])
            ->and($state->getWithdrawnEntrants())->toBe([]);
    });

    it('is refused for a round below 0, and after a round the history does not have', function (): void {
        $field = timesField(4);

        expect(fn(): Withdrawal => new Withdrawal($field[0], -1))->toThrow(InvalidInputException::class)
            ->and(fn(): StageState => StageState::fromHistory($field, [], [], [new Withdrawal($field[0], 1)]))
            ->toThrow(InvalidConfigurationException::class, 'happened after round 1');
    });

    // Round 0 is the time before the first round was recorded. Whether the
    // entrant was in the draw is said by the first round: the entrant it
    // names was, and keeps its place; one it does not name withdrew before
    // the draw and is no entrant.
    it('keeps the place of an entrant who withdraws while the first round is played, before the round is recorded', function (
        SingleEliminationEngine|DoubleEliminationEngine|SwissPairingEngine $engine
    ): void {
        $field = timesField(4);
        $start = StageState::start($field);
        $pairing = $engine->pairNextRound($start);
        $tie = $pairing->getEvents()[0];
        $leaves = array_values($tie->getParticipants())[1];

        $recordedWhenPaired = $start->withRoundPlayed($pairing, [])->withoutParticipant($leaves);
        $recordedWhenPlayed = $start->withoutParticipant($leaves)->withRoundPlayed($pairing, []);

        // Before the round is recorded the stage is drawn without the
        // entrant, as far as anyone asks; the withdrawal is kept with round 0
        $told = $start->withoutParticipant($leaves);
        expect(timesIds($told->getEntrants()))->not->toContain($leaves->getId())
            ->and($told->getWithdrawals())->toEqual([new Withdrawal($leaves, 0)])
            ->and($told->getWithdrawnEntrants())->toBe([]);

        // The round names it: it was in the draw, and keeps its place
        expect($recordedWhenPlayed->getEntrants())->toBe($field)
            ->and($recordedWhenPlayed->getWithdrawals())->toEqual([new Withdrawal($leaves, 0)])
            ->and($recordedWhenPaired->getWithdrawals())->toEqual([new Withdrawal($leaves, 1)]);

        foreach ([$recordedWhenPaired, $recordedWhenPlayed] as $state) {
            $rounds = $engine->getRecordedRounds($state);

            expect($rounds)->toHaveCount(1)
                ->and($rounds[0]->isManual())->toBeFalse()
                ->and(timesPairs($rounds[0]->getDerivation() ?? new RoundPairing(1, null, [])))->toBe(timesPairs($pairing))
                // A bracket gives the tie away; a Swiss stage has no tie to give
                ->and($rounds[0]->getUncontestedEvents())->toBe($engine instanceof SwissPairingEngine ? [] : [$tie]);
        }

        // The two orders are one history, and rows say the same with the round each states
        expect(timesTiers($engine->getStandings($recordedWhenPlayed)))->toBe(timesTiers($engine->getStandings($recordedWhenPaired)))
            ->and($engine->getReadiness($recordedWhenPlayed))->toEqual($engine->getReadiness($recordedWhenPaired))
            ->and(StageState::fromHistory($field, [$pairing], [], [new Withdrawal($leaves, 0)])->toArray())->toBe($recordedWhenPlayed->toArray())
            ->and(StageState::fromHistory($field, [$pairing], [], [new Withdrawal($leaves, 1)])->toArray())->toBe($recordedWhenPaired->toArray())
            ->and(StageState::fromJson($recordedWhenPlayed->toJson())->toArray())->toBe($recordedWhenPlayed->toArray())
            ->and(StageState::fromJson($told->toJson())->toArray())->toBe($told->toArray());
    })->with([
        'single elimination' => [new SingleEliminationEngine()],
        'single elimination, re-seeded' => [new SingleEliminationEngine(new EliminationOptions(reseedEachRound: true))],
        'double elimination' => [new DoubleEliminationEngine()],
        'Swiss' => [new SwissPairingEngine(plannedRounds: 3)],
    ]);

    it('leaves out an entrant who withdrew before the first round was paired, also where the rows list it with round 0', function (): void {
        $engine = new SingleEliminationEngine();
        $field = timesField(5);
        $without = [$field[0], $field[1], $field[2], $field[3]];

        $told = StageState::start($field)->withoutParticipant($field[4]);
        $pairing = $engine->pairNextRound($told);
        $recorded = $told->withRoundPlayed($pairing, []);

        expect(timesPairs($pairing))->toBe(['p1 v p4', 'p2 v p3'])
            ->and($recorded->toArray())->toBe(StageState::start($without)->withRoundPlayed($pairing, [])->toArray())
            ->and($recorded->getWithdrawals())->toBe([])
            ->and(StageState::fromHistory($field, [$pairing], [], [new Withdrawal($field[4], 0)])->toArray())->toBe($recorded->toArray())
            ->and(StageState::fromHistory($without, [$pairing])->toArray())->toBe($recorded->toArray());
    });

    it('is required of a stored state for every entrant who is not active, and round 0 only of one the first round names', function (Closure $tamper, string $message): void {
        $engine = new SingleEliminationEngine();
        $field = timesField(4);
        $start = StageState::start($field);
        $pairing = $engine->pairNextRound($start);
        $stored = $start->withoutParticipant($field[3])->withRoundPlayed($pairing, [])->withoutParticipant($field[1])->toArray();

        expect(fn(): StageState => StageState::fromArray($tamper($stored)))->toThrow(InvalidInputException::class, $message);
    })->with([
        'an entrant who left with no withdrawal' => [
            static function (array $stored): array {
                array_pop($stored['withdrawals']);

                return $stored;
            },
            'participant p2 is not active and has no withdrawal',
        ],
        'a withdrawal of somebody who is not an entrant' => [
            static function (array $stored): array {
                unset($stored['entrants']);

                return $stored;
            },
            'is not one of the entrants',
        ],
        'round 0 for an entrant the first round does not name' => [
            static function (array $stored): array {
                $stored['rounds'][0]['events'] = [$stored['rounds'][0]['events'][1]];

                return $stored;
            },
            'the first round does not name',
        ],
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

    it('sends nobody on from a tie both entrants left before it was recorded, in whichever order', function (int $first, int $second): void {
        $engine = new SingleEliminationEngine();
        $field = timesField(4);
        $state = timesPlayRound($engine, StageState::start($field));

        // The final, p1 v p2, is paired; both leave; it is recorded with no result
        $final = $engine->pairNextRound($state);
        $state = $state->withoutParticipant($field[$first])->withoutParticipant($field[$second])->withRoundPlayed($final, []);

        $outcome = $engine->getOutcome($state);
        assert($outcome instanceof StageOutcome);

        expect($engine->getRecordedRounds($state))->toHaveCount(2)
            ->and($engine->getRecordedRounds($state)[1]->isManual())->toBeFalse()
            ->and($outcome->getSettledTies())->toEqual([new SettledTie(null, [$field[0], $field[1]])])
            ->and(MatchOutcomeSelector::winners()->select($outcome))->toBe([]);
    })->with([
        'p1, then p2' => [0, 1],
        'p2, then p1' => [1, 0],
    ]);

    // The limit of that reading: a round names only its own entrants. In
    // double elimination a losers round with no tie to play is not
    // recorded, and is read from who was in the stage when the round after
    // it was paired, which can be a winners round that does not name its
    // entrant. A withdrawal while that winners round is played, recorded
    // before it, is then read as made before the losers round: the entrant
    // is placed one stage lower. Nothing else changes.
    it('places one stage lower, in double elimination, an entrant of an unrecorded losers round who leaves during the next winners round, recorded first', function () use ($next): void {
        $engine = new DoubleEliminationEngine();
        $field = timesField(4);

        // Winners round 1 is p1 v p4 and p2 v p3: p1 leaves, p3 wins. The
        // first losers round, p1 v p2, has no tie to play and is not recorded:
        // p2 goes on alone. The winners final, p3 v p4, is paired and won by
        // p3, and p2 leaves while it is played.
        $state = StageState::start($field);
        $first = $engine->pairNextRound($state);
        $state = $state->withRoundPlayed($first, [])->withoutParticipant($field[0])
            ->withAdditionalResults([new Result($first->getEvents()[1], $field[2])]);
        $winnersFinal = $engine->pairNextRound($state);
        $results = [new Result($winnersFinal->getEvents()[0], $field[2])];

        $recordedWhenPaired = $state->withRoundPlayed($winnersFinal, $results)->withoutParticipant($field[1]);
        $recordedWhenPlayed = $state->withoutParticipant($field[1])->withRoundPlayed($winnersFinal, $results);

        // Recorded first, the round says p2 left during it: p2 went through
        // the first losers round and goes out in the losers final. Recorded
        // after the withdrawal, which states round 1, nothing says p2 was
        // still in when the first losers round was due: it goes out there,
        // with p1
        expect(timesPairs($first))->toBe(['p1 v p4', 'p2 v p3'])
            ->and($winnersFinal->getLabel())->toBe('winners final')
            ->and($next($engine, $recordedWhenPlayed))->toBe($next($engine, $recordedWhenPaired))
            ->and(timesTiers($engine->getStandings($recordedWhenPaired)))->toBe(['p1' => 2, 'p2' => 1, 'p3' => 0, 'p4' => 0])
            ->and(timesTiers($engine->getStandings($recordedWhenPlayed)))->toBe(['p1' => 2, 'p2' => 2, 'p3' => 0, 'p4' => 0]);
    });

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

    // The same reading where the withdrawal states an earlier round: an
    // entrant a round names was in the stage when that round was paired.
    it('reads an entrant a round lists among its byes as in that round, also where its withdrawal states a round before it', function (): void {
        $engine = new SingleEliminationEngine();
        $field = timesField(8);

        // p1, p2, p3 and p4 reach the semifinals, p1 v p4 and p2 v p3. p4
        // withdraws, so p1 has a bye in them; p2 wins the other. Then p1
        // withdraws, and p2 has the final without playing it.
        $state = timesPlayRound($engine, StageState::start($field))->withoutParticipant($field[3]);
        $semifinals = $engine->pairNextRound($state);
        $state = timesPlayRound($engine, $state)->withoutParticipant($field[0]);

        // Rows that say p1 left after round 1, before the semifinals that list it
        $early = StageState::fromHistory(
            $field,
            $state->getRoundsPlayed(),
            $state->getResults(),
            [new Withdrawal($field[3], 1), new Withdrawal($field[0], 1)]
        );
        $outcome = $engine->getOutcome($early);
        assert($outcome instanceof StageOutcome);

        // p1 went on from the semifinals and is second; it did not go out in them with p4
        expect(timesIds($semifinals->getByes()))->toBe(['p1'])
            ->and(timesTiers($engine->getStandings($early)))->toBe(timesTiers($engine->getStandings($state)))
            ->and($outcome->getSettledTies())->toEqual([new SettledTie($field[1], [$field[0]])])
            ->and(timesTiers($outcome->getStandings())['p1'])->toBe(1)
            ->and(timesPairs($engine->getRecordedRounds($early)[1]->getDerivation() ?? $state->getRoundsPlayed()[0]))
            ->toBe(timesPairs($semifinals));
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
     * A bracket of four played up to its last tie, which is between the.
    // The limit of that reading: a round names only its own entrants. In
    // double elimination a losers round with no tie to play is not
    // recorded, and is read from who was in the stage when the round after
    // it was paired, which can be a winners round that does not name its
    // entrant. A withdrawal while that winners round is played, recorded
    // before it, is then read as made before the losers round: the entrant
    // is placed one stage lower. Nothing else changes.
    it('places one stage lower, in double elimination, an entrant of an unrecorded losers round who leaves during the next winners round, recorded first', function () use ($next): void {
        $engine = new DoubleEliminationEngine();
        $field = timesField(4);

        // Winners round 1 is p1 v p4 and p2 v p3: p1 leaves, p3 wins. The
        // first losers round, p1 v p2, has no tie to play and is not recorded:
        // p2 goes on alone. The winners final, p3 v p4, is paired and won by
        // p3, and p2 leaves while it is played.
        $state = StageState::start($field);
        $first = $engine->pairNextRound($state);
        $state = $state->withRoundPlayed($first, [])->withoutParticipant($field[0])
            ->withAdditionalResults([new Result($first->getEvents()[1], $field[2])]);
        $winnersFinal = $engine->pairNextRound($state);
        $results = [new Result($winnersFinal->getEvents()[0], $field[2])];

        $recordedWhenPaired = $state->withRoundPlayed($winnersFinal, $results)->withoutParticipant($field[1]);
        $recordedWhenPlayed = $state->withoutParticipant($field[1])->withRoundPlayed($winnersFinal, $results);

        // Recorded first, the round says p2 left during it: p2 went through
        // the first losers round and goes out in the losers final. Recorded
        // after the withdrawal, which states round 1, nothing says p2 was
        // still in when the first losers round was due: it goes out there,
        // with p1
        expect(timesPairs($first))->toBe(['p1 v p4', 'p2 v p3'])
            ->and($winnersFinal->getLabel())->toBe('winners final')
            ->and($next($engine, $recordedWhenPlayed))->toBe($next($engine, $recordedWhenPaired))
            ->and(timesTiers($engine->getStandings($recordedWhenPaired)))->toBe(['p1' => 2, 'p2' => 1, 'p3' => 0, 'p4' => 0])
            ->and(timesTiers($engine->getStandings($recordedWhenPlayed)))->toBe(['p1' => 2, 'p2' => 2, 'p3' => 0, 'p4' => 0]);
    });

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
