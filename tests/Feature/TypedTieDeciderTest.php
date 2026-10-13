<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Award;
use MissionGaming\Tactician\DTO\Decider;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Exceptions\TacticianException;
use MissionGaming\Tactician\Exceptions\UnresolvedRoundException;
use MissionGaming\Tactician\Scheduling\DoubleEliminationEngine;
use MissionGaming\Tactician\Scheduling\EliminationOptions;
use MissionGaming\Tactician\Scheduling\SingleEliminationEngine;
use MissionGaming\Tactician\Scheduling\SwissPairingEngine;
use MissionGaming\Tactician\Stage\HistoryReadingEngine;
use MissionGaming\Tactician\Stage\MatchOutcomeSelector;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Stage\StageEngineInterface;
use MissionGaming\Tactician\Stage\StageOutcome;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Standings\StandingsCalculator;
use MissionGaming\Tactician\Tests\Support\TieWinnerDeprecation;

// The typed decider of a knockout tie that finishes level: one optional
// fact on Result, for a single event and for two legs alike, that names who
// advances and changes nothing else. These tests state what the decider
// does where it is recorded; the sweeps over generated brackets are in
// EliminationInvariantsTest, and what the deprecated metadata key still
// does is pinned in DeprecationsTest.

/**
 * @return list<Participant> p1..pn, in list order
 */
function deciderField(int $count): array
{
    $participants = [];
    for ($i = 1; $i <= $count; ++$i) {
        $participants[] = new Participant("p{$i}", "P{$i}", $i);
    }

    return $participants;
}

/**
 * @param array<Event> $events
 *
 * @return list<string>
 */
function deciderPairs(array $events): array
{
    return array_values(array_map(
        static fn(Event $event): string => implode(' v ', array_map(
            static fn(Participant $participant): string => $participant->getId(),
            $event->getParticipants()
        )),
        $events
    ));
}

/**
 * The exception each of the three calls of an engine ends in for a state,
 * or null for a call that ends in none.
 *
 * @return array<string, InvalidConfigurationException|null>
 *
 * @throws TacticianException When a call fails for another reason than the state it is given
 */
function deciderRefusals(StageEngineInterface $engine, StageState $state): array
{
    $calls = [
        'isComplete' => $engine->isComplete(...),
        'pairNextRound' => $engine->pairNextRound(...),
        'getOutcome' => $engine->getOutcome(...),
    ];

    $refusals = [];
    foreach ($calls as $call => $ask) {
        try {
            $ask($state);
            $refusals[$call] = null;
        } catch (InvalidConfigurationException $exception) {
            $refusals[$call] = $exception;
        }
    }

    return $refusals;
}

/**
 * @return array<string, array{float, float, float}> Scores-for, scores-against and the difference, by participant ID
 */
function deciderScoreFigures(StageOutcome $outcome): array
{
    $figures = [];
    foreach ($outcome->getStandings()->getEntries() as $entry) {
        $figures[$entry->getParticipant()->getId()] = [$entry->getScoreFor(), $entry->getScoreAgainst(), $entry->getScoreDifference()];
    }
    ksort($figures);

    return $figures;
}

describe('a single-leg tie that finishes level', function (): void {
    it('sends on the participant its decider names, reads 1-1 still, and no engine call throws', function (
        StageEngineInterface $engine,
        string $nextRound
    ): void {
        $state = StageState::start(deciderField(4));
        $first = $engine->pairNextRound($state);
        [$p1VsP4, $p2VsP3] = $first->getEvents();
        expect(deciderPairs($first->getEvents()))->toBe(['p1 v p4', 'p2 v p3']);
        [$p2] = $p2VsP3->getParticipants();

        $level = new Result($p1VsP4, null, ['p1' => 1, 'p4' => 1], [], null, new Decider(['p4'], 'the rule of the competition'));
        $state = $state->withRoundPlayed($first, [$level, new Result($p2VsP3, $p2, ['p2' => 2, 'p3' => 0])]);

        // None of the three throws, and the state says so before and after storage
        foreach ([$state, StageState::fromJson($state->toJson())] as $asked) {
            expect(deciderRefusals($engine, $asked))->toBe(['isComplete' => null, 'pairNextRound' => null, 'getOutcome' => null])
                ->and($engine->isComplete($asked))->toBeFalse()
                ->and($engine->getOutcome($asked))->toBeNull()
                ->and(deciderPairs($engine->pairNextRound($asked)->getEvents()))->toContain($nextRound);
        }

        // The result is the result that was recorded: level, at 1-1
        $recorded = $state->getResults()[0];
        expect($recorded)->toBe($level)
            ->and($recorded->isDraw())->toBeTrue()
            ->and($recorded->getWinner())->toBeNull()
            ->and($recorded->getScores())->toBe(['p1' => 1, 'p4' => 1])
            ->and($recorded->getDecider()?->getParticipantIds())->toBe(['p4']);
    })->with([
        // p4 advances: to the final against p2, or to the winners final
        'single elimination' => [fn() => new SingleEliminationEngine(), 'p4 v p2'],
        're-seeded single elimination' => [fn() => new SingleEliminationEngine(new EliminationOptions(reseedEachRound: true)), 'p2 v p4'],
        // The losers round comes first; p1, who did not advance, is in it
        'double elimination' => [fn() => new DoubleEliminationEngine(), 'p1 v p3'],
    ]);

    // A level tie that nobody has decided yet awaits a fact, as a tie
    // without a result does: reading the bracket throws nothing, and only
    // the next round is refused.
    it('awaits its decider: the bracket is read, and the next round is refused with a reason code, its round and its participants', function (
        StageEngineInterface&HistoryReadingEngine $engine
    ): void {
        $state = StageState::start(deciderField(4));
        $first = $engine->pairNextRound($state);
        [$p1VsP4, $p2VsP3] = $first->getEvents();
        [$p2] = $p2VsP3->getParticipants();

        $state = $state->withRoundPlayed($first, [
            new Result($p1VsP4, null, ['p1' => 1, 'p4' => 1]),
            new Result($p2VsP3, $p2, ['p2' => 2, 'p3' => 0]),
        ]);

        $refusals = deciderRefusals($engine, $state);
        $refusal = $refusals['pairNextRound'];
        $readiness = $engine->getReadiness($state);
        $recorded = $engine->getRecordedRounds($state)[0];

        expect($refusals['isComplete'])->toBeNull()
            ->and($refusals['getOutcome'])->toBeNull()
            ->and($engine->isComplete($state))->toBeFalse()
            ->and($engine->getOutcome($state))->toBeNull()
            ->and($engine->getStandings($state)->getEntries())->toHaveCount(4)
            ->and($readiness->isReady())->toBeFalse()
            ->and($readiness->isComplete())->toBeFalse()
            ->and($readiness->isAwaitingResults())->toBeTrue()
            ->and($readiness->getRoundNumber())->toBe(1)
            ->and($readiness->getUndecidedEvents())->toBe([$p1VsP4])
            ->and($readiness->getUnresolvedEvents())->toBe([])
            ->and($recorded->getUndecidedEvents())->toBe([$p1VsP4])
            ->and($recorded->isResolved())->toBeFalse()
            ->and($refusal)->not->toBeNull()
            ->and($refusal)->not->toBeInstanceOf(UnresolvedRoundException::class)
            ->and($refusal?->getReason())->toBe(InvalidConfigurationReason::UndecidedTie)
            ->and($refusal?->getReason()?->value)->toBe('undecided_tie')
            ->and($refusal?->getContext())->toBe(['round' => 1, 'participants' => ['p1', 'p4']])
            ->and($refusal?->getConfigurationIssue())->toBe(
                'Elimination events cannot end in a draw (P1 vs P4): the event is level, so record who advances as the decider of its result'
            );

        // The decision arrives: the same draw, with its decider
        $decided = $state->withResultReplaced(new Result($p1VsP4, null, ['p1' => 1, 'p4' => 1], [], null, new Decider(['p4'])));

        expect($engine->getReadiness($decided)->isReady())->toBeTrue()
            ->and($engine->getReadiness($decided)->getUndecidedEvents())->toBe([])
            ->and($engine->getRecordedRounds($decided)[0]->isResolved())->toBeTrue();
    })->with([
        'single elimination' => [fn() => new SingleEliminationEngine()],
        'double elimination' => [fn() => new DoubleEliminationEngine()],
    ]);

    // The grand final and its reset are the same two entrants in two rounds:
    // the pair alone does not say which tie is unresolved
    it('names the round of the tie where the same two meet twice', function (): void {
        $engine = new DoubleEliminationEngine();
        $state = StageState::start(deciderField(2));

        $rounds = [];
        $labels = [];
        // The second-named advances from the winners final and from the
        // grand final, where it is the losers champion: a reset follows
        foreach ([1, 1, 0] as $advancing) {
            $pairing = $engine->pairNextRound($state);
            [$event] = $pairing->getEvents();
            $labels[] = $pairing->getLabel();

            // Level, and nobody decided it: the engine says which tie
            $undecided = $state->withRoundPlayed($pairing, [new Result($event)]);
            $rounds[] = deciderRefusals($engine, $undecided)['pairNextRound']?->getContext();

            $state = $state->withRoundPlayed($pairing, [
                new Result($event, null, [], [], null, new Decider([$event->getParticipants()[$advancing]->getId()])),
            ]);
        }

        expect($labels)->toBe(['winners final', 'grand final', 'grand final reset'])
            ->and($rounds)->toBe([
                ['round' => 1, 'participants' => ['p1', 'p2']],
                ['round' => 2, 'participants' => ['p2', 'p1']],
                ['round' => 3, 'participants' => ['p2', 'p1']],
            ])
            ->and($engine->isComplete($state))->toBeTrue();
    });

    it('is refused when its decider names more than the one participant a tie sends on', function (): void {
        $engine = new SingleEliminationEngine();
        $state = StageState::start(deciderField(2));
        $final = $engine->pairNextRound($state);
        $state = $state->withRoundPlayed($final, [
            new Result($final->getEvents()[0], null, [], [], null, new Decider(['p1', 'p2'])),
        ]);

        foreach (deciderRefusals($engine, $state) as $refusal) {
            expect($refusal?->getReason())->toBe(InvalidConfigurationReason::InvalidResult)
                ->and($refusal?->getContext())->toBe(['decider' => ['p1', 'p2'], 'participants' => ['p1', 'p2']]);
        }
    });
});

describe('a decider on a tie that its results already decide', function (): void {
    it('is refused by every engine call, whoever it names', function (StageEngineInterface $engine, string $named): void {
        $state = StageState::start(deciderField(4));
        $first = $engine->pairNextRound($state);
        [$p1VsP4, $p2VsP3] = $first->getEvents();
        [$p1] = $p1VsP4->getParticipants();
        [$p2] = $p2VsP3->getParticipants();

        $state = $state->withRoundPlayed($first, [
            new Result($p1VsP4, $p1, ['p1' => 2, 'p4' => 1], [], null, new Decider([$named])),
            new Result($p2VsP3, $p2),
        ]);

        foreach (deciderRefusals($engine, $state) as $call => $refusal) {
            expect($refusal)->not->toBeNull("{$call}() accepted a decider on an event that has a winner.")
                ->and($refusal?->getReason())->toBe(InvalidConfigurationReason::InvalidResult)
                ->and($refusal?->getContext())->toBe(['decider' => [$named], 'advancer' => 'p1', 'participants' => ['p1', 'p4']]);
        }
    })->with([
        'single elimination' => [fn() => new SingleEliminationEngine()],
        'double elimination' => [fn() => new DoubleEliminationEngine()],
    ])->with([
        'the winner, which repeats the result' => ['p1'],
        'the other participant, which contradicts it' => ['p4'],
    ]);

    it('is refused on a leg of a two-legged tie the leg wins decide', function (int $carrier): void {
        $engine = new SingleEliminationEngine(new EliminationOptions(legsPerTie: 2));
        $state = StageState::start(deciderField(2));
        $final = $engine->pairNextRound($state);
        [$leg1, $leg2] = $final->getEvents();
        [$p1] = $leg1->getParticipants();
        $decider = new Decider(['p2']);

        // p1 wins the first leg and the second is drawn: p1 advances on the legs
        $state = $state->withRoundPlayed($final, [
            new Result($leg1, $p1, [], [], null, $carrier === 1 ? $decider : null),
            new Result($leg2, null, [], [], null, $carrier === 2 ? $decider : null),
        ]);

        foreach (deciderRefusals($engine, $state) as $refusal) {
            expect($refusal?->getReason())->toBe(InvalidConfigurationReason::InvalidResult)
                ->and($refusal?->getContext())->toBe(['decider' => ['p2'], 'advancer' => 'p1', 'participants' => ['p1', 'p2']]);
        }
    })->with([1, 2]);

    it('is refused by the match outcome selector as by the engines', function (): void {
        [$p1, $p2] = deciderField(2);
        $event = new Event([$p1, $p2], new MissionGaming\Tactician\DTO\Round(1));
        $results = [new Result($event, $p1, [], [], null, new Decider(['p2']))];
        $outcome = new StageOutcome(
            (new StandingsCalculator())->calculate([$p1, $p2], $results),
            $results,
            [],
            new RoundPairing(1, 'final', [$event])
        );

        expect(fn() => MatchOutcomeSelector::winners()->select($outcome))
            ->toThrow(InvalidConfigurationException::class, 'A decider is recorded on a tie that its results already decide');
    });
});

describe('a two-legged tie that finishes level', function (): void {
    /**
     * The final of two entrants over two legs: 2-1 for p1, then 0-1 the
     * other way, so a leg each, with the tie's decision recorded as asked.
     *
     * @param 'decider'|'tie_winner'|'none' $decision
     *
     * @return array{SingleEliminationEngine, StageState}
     */
    $legEach = function (string $decision, string $advances, int $carrier = 2): array {
        $engine = new SingleEliminationEngine(new EliminationOptions(legsPerTie: 2));
        $state = StageState::start(deciderField(2));
        $final = $engine->pairNextRound($state);
        [$leg1, $leg2] = $final->getEvents();
        [$p1, $p2] = $leg1->getParticipants();

        $metadata = $decision === 'tie_winner' ? [TieWinnerDeprecation::KEY => $advances] : [];
        $decider = $decision === 'decider' ? new Decider([$advances]) : null;

        return [$engine, $state->withRoundPlayed($final, [
            new Result($leg1, $p1, ['p1' => 2, 'p2' => 1], $carrier === 1 ? $metadata : [], null, $carrier === 1 ? $decider : null),
            new Result($leg2, $p2, ['p1' => 0, 'p2' => 1], $carrier === 2 ? $metadata : [], null, $carrier === 2 ? $decider : null),
        ])];
    };

    $table = static fn(?StageOutcome $outcome): array => array_map(
        static fn($entry): array => [
            $entry->getParticipant()->getId(),
            $entry->getWins(),
            $entry->getDraws(),
            $entry->getLosses(),
            $entry->getScoreFor(),
            $entry->getScoreAgainst(),
        ],
        array_values($outcome?->getStandings()->getEntries() ?? [])
    );

    // The acceptance criterion: 2-1 then 0-1, decider on the second leg
    it('sends on the participant the decider on its second leg names', function (string $advances) use ($legEach, $table): void {
        [$engine, $state] = $legEach('decider', $advances);
        $other = $advances === 'p1' ? 'p2' : 'p1';

        expect(deciderRefusals($engine, $state)['isComplete'])->toBeNull()
            ->and($engine->isComplete($state))->toBeTrue();

        $outcome = $engine->getOutcome($state);
        assert($outcome instanceof StageOutcome);

        // Each leg counts as it was recorded, and the scores are the scores
        // of the two events: 2 for and 2 against for both
        expect($table($outcome))->toBe([[$advances, 1, 0, 1, 2.0, 2.0], [$other, 1, 0, 1, 2.0, 2.0]])
            ->and($outcome->getStandings()->getTiers())->toBe([0, 1])
            ->and(MatchOutcomeSelector::winners()->select($outcome)[0]->getId())->toBe($advances)
            ->and(MatchOutcomeSelector::losers()->select($outcome)[0]->getId())->toBe($other)
            ->and($outcome->getResults())->toBe($state->getResults());
    })->with(['p1', 'p2']);

    it('gets the same result from tie_winner metadata, which raises a deprecation', function (string $advances) use ($legEach, $table): void {
        [$engine, $byDecider] = $legEach('decider', $advances);
        [, $byMetadata] = $legEach('tie_winner', $advances);

        $outcome = $engine->getOutcome($byDecider);
        $legacy = TieWinnerDeprecation::once(fn(): ?StageOutcome => $engine->getOutcome($byMetadata));
        assert($outcome instanceof StageOutcome && $legacy instanceof StageOutcome);

        expect(TieWinnerDeprecation::once(fn(): bool => $engine->isComplete($byMetadata)))->toBeTrue()
            ->and($table($legacy))->toBe($table($outcome))
            ->and($legacy->getStandings()->getTiers())->toBe($outcome->getStandings()->getTiers())
            ->and(TieWinnerDeprecation::once(fn(): array => MatchOutcomeSelector::winners()->select($legacy))[0]->getId())
            ->toBe(MatchOutcomeSelector::winners()->select($outcome)[0]->getId())
            ->and(MatchOutcomeSelector::winners()->select($outcome)[0]->getId())->toBe($advances);
    })->with(['p1', 'p2']);

    it('reads the decider from the first leg as from the second', function () use ($legEach, $table): void {
        [$engine, $onSecond] = $legEach('decider', 'p2');
        [, $onFirst] = $legEach('decider', 'p2', 1);

        expect($table($engine->getOutcome($onFirst)))->toBe($table($engine->getOutcome($onSecond)));
    });

    it('awaits its decider, both legs of it, and refuses the next round with a reason code, its round and its participants', function () use ($legEach): void {
        [$engine, $state] = $legEach('none', 'p1');
        $refusals = deciderRefusals($engine, $state);

        expect($refusals['isComplete'])->toBeNull()
            ->and($refusals['getOutcome'])->toBeNull()
            ->and($engine->isComplete($state))->toBeFalse()
            ->and($engine->getOutcome($state))->toBeNull()
            ->and($engine->getReadiness($state)->getUndecidedEvents())->toBe($state->getLastRound()?->getEvents())
            ->and($engine->getReadiness($state)->getUnresolvedEvents())->toBe([])
            ->and($refusals['pairNextRound']?->getReason())->toBe(InvalidConfigurationReason::UndecidedTie)
            ->and($refusals['pairNextRound']?->getContext())->toBe(['round' => 1, 'participants' => ['p1', 'p2']]);
    });

    it('is incomplete, and not yet decided, while the decider is on a leg and the other leg has no result', function (): void {
        $engine = new SingleEliminationEngine(new EliminationOptions(legsPerTie: 2));
        $state = StageState::start(deciderField(2));
        $final = $engine->pairNextRound($state);
        [$leg1, $leg2] = $final->getEvents();
        [$p1, $p2] = $leg1->getParticipants();

        $oneLeg = $state->withRoundPlayed($final, [new Result($leg1, $p1, [], [], null, new Decider(['p2']))]);

        // The round is recorded and awaits the second leg: it is a result
        // that is missing, and no decision
        expect($engine->isComplete($oneLeg))->toBeFalse()
            ->and($engine->getReadiness($oneLeg)->getUnresolvedEvents())->toBe([$leg2])
            ->and($engine->getReadiness($oneLeg)->getUndecidedEvents())->toBe([])
            ->and(fn() => $engine->pairNextRound($oneLeg))->toThrow(UnresolvedRoundException::class);

        // The second leg levels the tie, and the decider then settles it
        $both = $oneLeg->withAdditionalResults([new Result($leg2, $p2)]);
        expect($engine->isComplete($both))->toBeTrue()
            ->and(MatchOutcomeSelector::winners()->select($engine->getOutcome($both) ?? throw new LogicException('No outcome.')))->toBe([$p2]);
    });

    it('is refused with a decider on each leg', function () use ($legEach): void {
        [$engine, $state] = $legEach('decider', 'p2');
        [$first, $second] = $state->getResults();
        $doubled = $state->withResultReplaced(
            new Result($first->getEvent(), $first->getWinner(), $first->getScores(), [], null, new Decider(['p2']))
        );

        expect($second->getDecider())->not->toBeNull();
        foreach (deciderRefusals($engine, $doubled) as $refusal) {
            expect($refusal?->getReason())->toBe(InvalidConfigurationReason::InvalidResult)
                ->and($refusal?->getContext())->toBe(['deciders' => 2, 'participants' => ['p1', 'p2']]);
        }
    });
});

describe('the score figures of an outcome with decided ties', function (): void {
    // The acceptance criterion: scores-for, scores-against and the score
    // difference come from the scores of the events only. Whatever the
    // decider says, and whatever the application keeps about its own
    // tie-break, the figures are those of the same bracket with every
    // decision left out of the count.
    it('come from the scores of the events only', function (StageEngineInterface $engine, int $legsPerTie): void {
        $play = function (bool $decided) use ($engine, $legsPerTie): StageOutcome {
            $state = StageState::start(deciderField(5));
            $score = 0;

            while (!$engine->isComplete($state)) {
                $pairing = $engine->pairNextRound($state);
                $results = [];
                foreach ($pairing->getEvents() as $event) {
                    [$first, $second] = $event->getParticipants();
                    $scores = [$first->getId() => ++$score % 4, $second->getId() => $score % 4];
                    $lastLeg = $legsPerTie === 1 || $event->getMetadataValue('tie_leg') === 2;
                    // The participant listed second in the last leg advances
                    $advances = $second;

                    $results[] = $decided
                        // Level, with a tie-break of the application's own in the metadata
                        ? new Result(
                            $event,
                            null,
                            $scores,
                            $lastLeg ? ['tie_break' => [$first->getId() => 3, $second->getId() => 5]] : [],
                            null,
                            $lastLeg ? new Decider([$advances->getId()], '5-3 in the tie-break', 'decided on the day') : null
                        )
                        // The same scores, and the last leg won by the same participant
                        : new Result($event, $lastLeg ? $advances : null, $scores);
                }
                $state = $state->withRoundPlayed($pairing, $results);
            }

            return $engine->getOutcome($state) ?? throw new LogicException('The bracket has no outcome.');
        };

        $decided = $play(true);
        $won = $play(false);
        $figures = deciderScoreFigures($decided);

        // The same advancers, so the same bracket; the same scores, so the
        // same figures, to the last digit
        expect($figures)->toBe(deciderScoreFigures($won));

        // And they are the sums of the recorded scores, counted here by hand
        $byHand = [];
        foreach ($decided->getResults() as $result) {
            $scores = $result->getScores();
            foreach ($result->getEvent()->getParticipants() as $index => $participant) {
                $other = $result->getEvent()->getParticipants()[1 - $index];
                $byHand[$participant->getId()] ??= [0.0, 0.0];
                $byHand[$participant->getId()][0] += $scores[$participant->getId()];
                $byHand[$participant->getId()][1] += $scores[$other->getId()];
            }
        }
        foreach ($byHand as $id => [$for, $against]) {
            expect($figures[$id])->toBe([$for, $against, $for - $against]);
        }
        expect(array_sum(array_column($figures, 0)))->toBeGreaterThan(0.0);
    })->with([
        'single elimination' => [fn() => new SingleEliminationEngine(), 1],
        'single elimination with a third-place tie' => [fn() => new SingleEliminationEngine(new EliminationOptions(thirdPlace: true)), 1],
        'two-legged single elimination' => [fn() => new SingleEliminationEngine(new EliminationOptions(legsPerTie: 2)), 2],
        'double elimination' => [fn() => new DoubleEliminationEngine(), 1],
    ]);
});

describe('a state that holds decided ties', function (): void {
    /**
     * A bracket played to the end with every tie level and settled by a
     * decider for its second-named participant, each with a label and a
     * reason. The state is stored and read back after every round when
     * asked to.
     *
     * @return array{StageState, list<list<string>>} The final state and the pairs of every round
     *
     * @throws TacticianException When the engine refuses the state
     */
    $playDecided = function (StageEngineInterface $engine, int $legsPerTie, bool $throughStorage): array {
        $state = StageState::start(deciderField(6));
        $rounds = [];

        while (!$engine->isComplete($state)) {
            $pairing = $engine->pairNextRound($state);
            $rounds[] = deciderPairs($pairing->getEvents());

            $results = [];
            foreach ($pairing->getEvents() as $event) {
                $lastLeg = $legsPerTie === 1 || $event->getMetadataValue('tie_leg') === 2;
                $advances = $event->getParticipants()[$legsPerTie === 1 ? 1 : 0]->getId();
                $results[] = new Result(
                    $event,
                    null,
                    [],
                    [],
                    null,
                    $lastLeg ? new Decider([$advances], "round {$pairing->getRoundNumber()}", 'é 日本') : null
                );
            }

            $state = $state->withRoundPlayed($pairing, $results);
            if ($throughStorage) {
                $state = StageState::fromJson($state->toJson());
            }
        }

        return [$state, $rounds];
    };

    // The acceptance criterion: the decider round-trips through
    // toArray()/fromArray() and the state's JSON; and fromArray() replays
    // stored history through the verbs, so a state of decided ties replays
    it('replays from its array and from its JSON, round by round and at the end', function (
        StageEngineInterface $engine,
        int $legsPerTie
    ) use ($playDecided): void {
        [$live, $liveRounds] = $playDecided($engine, $legsPerTie, false);
        [$stored, $storedRounds] = $playDecided($engine, $legsPerTie, true);

        expect($storedRounds)->toBe($liveRounds)
            ->and($stored->toArray())->toBe($live->toArray())
            ->and(StageState::fromArray($live->toArray())->toArray())->toBe($live->toArray())
            ->and(StageState::fromJson($live->toJson())->toJson())->toBe($live->toJson());

        $restored = StageState::fromJson($live->toJson());
        $deciders = 0;
        foreach ($restored->getResults() as $index => $result) {
            expect($result->getDecider())->toEqual($live->getResults()[$index]->getDecider());
            $deciders += $result->getDecider() instanceof Decider ? 1 : 0;
        }
        expect($deciders)->toBe(intdiv(count($restored->getResults()), $legsPerTie))
            ->and($live->toJson())->toContain('"decider":{"participants":["')
            ->and($live->toJson())->not->toContain(TieWinnerDeprecation::KEY);

        // The engine answers the restored state as it answers the live one
        $outcome = $engine->getOutcome($live);
        $restoredOutcome = $engine->getOutcome($restored);
        assert($outcome instanceof StageOutcome && $restoredOutcome instanceof StageOutcome);

        $ids = static fn(StageOutcome $of): array => array_map(
            static fn($entry): string => $entry->getParticipant()->getId(),
            array_values($of->getStandings()->getEntries())
        );
        expect($engine->isComplete($restored))->toBeTrue()
            ->and($ids($restoredOutcome))->toBe($ids($outcome))
            ->and($restoredOutcome->getStandings()->getTiers())->toBe($outcome->getStandings()->getTiers());
    })->with([
        'single elimination' => [fn() => new SingleEliminationEngine(), 1],
        're-seeded single elimination' => [fn() => new SingleEliminationEngine(new EliminationOptions(reseedEachRound: true)), 1],
        'single elimination with a third-place tie' => [fn() => new SingleEliminationEngine(new EliminationOptions(thirdPlace: true)), 1],
        'two-legged single elimination with a third-place tie' => [
            fn() => new SingleEliminationEngine(new EliminationOptions(legsPerTie: 2, thirdPlace: true)),
            2,
        ],
        'double elimination' => [fn() => new DoubleEliminationEngine(), 1],
        'two-legged double elimination' => [fn() => new DoubleEliminationEngine(new EliminationOptions(legsPerTie: 2)), 2],
    ]);

    it('writes no decider key for a result that has none, so a bracket without one is stored as it always was', function (): void {
        $engine = new SingleEliminationEngine();
        $state = StageState::start(deciderField(4));
        $pairing = $engine->pairNextRound($state);
        $state = $state->withRoundPlayed($pairing, array_map(
            static fn(Event $event): Result => new Result($event, $event->getParticipants()[0]),
            $pairing->getEvents()
        ));

        foreach ($state->toArray()['results'] as $result) {
            expect(array_keys($result))->toBe(['event', 'winner', 'scores', 'metadata']);
        }
        expect($state->toJson())->not->toContain('decider');
    });
});

describe('the third-place tie', function (): void {
    it('is settled by its decider as the final is, in one leg and in two', function (int $legsPerTie): void {
        $engine = new SingleEliminationEngine(new EliminationOptions(legsPerTie: $legsPerTie, thirdPlace: true));
        $state = StageState::start(deciderField(4));

        // The semifinals have winners: the first-named of each tie
        $semifinals = $engine->pairNextRound($state);
        $state = $state->withRoundPlayed($semifinals, array_map(
            static fn(Event $event): Result => new Result(
                $event,
                $event->getParticipants()[$event->getMetadataValue('tie_leg') === 2 ? 1 : 0]
            ),
            $semifinals->getEvents()
        ));

        // Both ties of the last round finish level
        $last = $engine->pairNextRound($state);
        expect(deciderPairs(array_values(array_filter(
            $last->getEvents(),
            static fn(Event $event): bool => $event->getMetadataValue('tie_leg') !== 2
        ))))->toBe(['p1 v p2', 'p4 v p3']);

        $undecided = $state->withRoundPlayed($last, array_map(static fn(Event $event): Result => new Result($event), $last->getEvents()));
        expect(deciderRefusals($engine, $undecided)['pairNextRound']?->getReason())->toBe(InvalidConfigurationReason::UndecidedTie)
            ->and($engine->getOutcome($undecided))->toBeNull()
            ->and($engine->getReadiness($undecided)->getUndecidedEvents())->toBe($last->getEvents());

        // p2 takes the final and p3 the third-place tie, each on the last leg
        $decided = $state->withRoundPlayed($last, array_map(
            static function (Event $event) use ($legsPerTie): Result {
                $ids = array_map(static fn(Participant $participant): string => $participant->getId(), $event->getParticipants());
                $lastLeg = $legsPerTie === 1 || $event->getMetadataValue('tie_leg') === 2;
                $advances = in_array('p2', $ids, true) ? 'p2' : 'p3';

                return new Result($event, null, [], [], null, $lastLeg ? new Decider([$advances]) : null);
            },
            $last->getEvents()
        ));

        $outcome = $engine->getOutcome($decided);
        assert($outcome instanceof StageOutcome);

        expect(array_map(
            static fn($entry): string => $entry->getParticipant()->getId(),
            array_values($outcome->getStandings()->getEntries())
        ))->toBe(['p2', 'p1', 'p3', 'p4'])
            ->and($outcome->getStandings()->getTiers())->toBe([0, 1, 2, 3])
            // The selector reads the final and leaves the third-place tie out
            ->and(MatchOutcomeSelector::winners()->select($outcome)[0]->getId())->toBe('p2')
            ->and(MatchOutcomeSelector::losers()->select($outcome)[0]->getId())->toBe('p1');
    })->with([1, 2]);
});

describe('an award and a decider on one result', function (): void {
    it('settles a tie the awarded result leaves level: the award says it was not played, the decider who goes on', function (
        StageEngineInterface $engine
    ): void {
        $state = StageState::start(deciderField(2));
        $pairing = $engine->pairNextRound($state);
        [$event] = $pairing->getEvents();

        $awarded = new Result($event, null, [], [], new Award('not played', 'Neither side was there'), new Decider(['p2'], 'drawn by lot'));
        $state = StageState::fromJson($state->withRoundPlayed($pairing, [$awarded])->toJson());

        expect(deciderRefusals($engine, $state)['pairNextRound']?->getReason())->toBeIn([null, InvalidConfigurationReason::BracketComplete]);

        $recorded = $state->getResults()[0];
        expect($recorded->isAwarded())->toBeTrue()
            ->and($recorded->getAward()?->getLabel())->toBe('not played')
            ->and($recorded->getDecider()?->getParticipantIds())->toBe(['p2'])
            ->and($recorded->getDecider()?->getLabel())->toBe('drawn by lot')
            ->and($recorded->isDraw())->toBeTrue();

        // p2 went on: in single elimination that is the bracket, in double
        // elimination p2 is the winners champion and the grand final follows
        if ($engine instanceof SingleEliminationEngine) {
            $entry = $engine->getOutcome($state)?->getStandings()->getEntries()[0];
            expect($entry?->getParticipant()->getId())->toBe('p2')
                ->and($entry?->getWins())->toBe(1)
                // The table still counts the event as awarded
                ->and($entry?->getAwarded())->toBe(1);
        } else {
            expect(deciderPairs($engine->pairNextRound($state)->getEvents()))->toBe(['p2 v p1']);
        }
    })->with([
        'single elimination' => [fn() => new SingleEliminationEngine()],
        'double elimination' => [fn() => new DoubleEliminationEngine()],
    ]);

    it('is refused where the awarded result names a winner: the award decided the tie', function (): void {
        $engine = new SingleEliminationEngine();
        $state = StageState::start(deciderField(2));
        $final = $engine->pairNextRound($state);
        [$event] = $final->getEvents();
        [$p1] = $event->getParticipants();

        $state = $state->withRoundPlayed($final, [new Result($event, $p1, [], [], new Award('no-show'), new Decider(['p1']))]);

        foreach (deciderRefusals($engine, $state) as $refusal) {
            expect($refusal?->getReason())->toBe(InvalidConfigurationReason::InvalidResult);
        }
    });
});

describe('a decider where nothing reads one', function (): void {
    // Only the elimination engines and the match outcome selector read a
    // decider. Elsewhere the result is the result it is without it
    it('changes nothing in a standings table: a drawn result is a draw', function (): void {
        [$p1, $p2, $p3] = $field = deciderField(3);
        $first = new Event([$p1, $p2]);
        $second = new Event([$p2, $p3]);

        $row = static fn(array $results): array => array_map(
            static fn($entry): array => [
                $entry->getParticipant()->getId(),
                $entry->getWins(),
                $entry->getDraws(),
                $entry->getLosses(),
                $entry->getRankingValue(),
                $entry->getScoreFor(),
                $entry->getScoreAgainst(),
            ],
            array_values((new StandingsCalculator())->calculate($field, $results)->getEntries())
        );

        $plain = [new Result($first, null, ['p1' => 1, 'p2' => 1]), new Result($second, $p3, ['p2' => 0, 'p3' => 2])];
        $decided = [
            new Result($first, null, ['p1' => 1, 'p2' => 1], [], null, new Decider(['p2'], 'not read here')),
            new Result($second, $p3, ['p2' => 0, 'p3' => 2], [], null, new Decider(['p2'])),
        ];

        expect($row($decided))->toBe($row($plain))
            ->and($row($decided)[0])->toBe(['p3', 1, 0, 0, 3.0, 2.0, 0.0]);
    });

    it('changes nothing in a Swiss stage: the next round is the round of the same draws', function (): void {
        $engine = new SwissPairingEngine();
        $rounds = function (bool $withDeciders) use ($engine): array {
            $state = StageState::start(deciderField(6));
            $pairs = [];
            for ($round = 1; $round <= 3; ++$round) {
                $pairing = $engine->pairNextRound($state);
                $pairs[] = deciderPairs($pairing->getEvents());
                $state = $state->withRoundPlayed($pairing, array_map(
                    static fn(Event $event): Result => new Result(
                        $event,
                        null,
                        [],
                        [],
                        null,
                        $withDeciders ? new Decider([$event->getParticipants()[1]->getId()]) : null
                    ),
                    $pairing->getEvents()
                ));
            }

            return $pairs;
        };

        expect($rounds(true))->toBe($rounds(false));
    });
});

describe('a decider beside tie_winner metadata on a bracket', function (): void {
    it('settles the tie by the decider and raises nothing where the two agree', function (): void {
        $engine = new SingleEliminationEngine();
        $state = StageState::start(deciderField(2));
        $final = $engine->pairNextRound($state);
        $state = $state->withRoundPlayed($final, [
            new Result($final->getEvents()[0], null, [], [TieWinnerDeprecation::KEY => 'p2'], null, new Decider(['p2'])),
        ]);

        $outcome = null;
        $raised = TieWinnerDeprecation::countIn(function () use ($engine, $state, &$outcome): void {
            $outcome = $engine->getOutcome($state);
        });

        expect($raised)->toBe(0)
            ->and($outcome?->getStandings()->getEntries()[0]->getParticipant()->getId())->toBe('p2');
    });

    it('refuses the state where the two name different participants', function (): void {
        $engine = new SingleEliminationEngine();
        $state = StageState::start(deciderField(2));
        $final = $engine->pairNextRound($state);
        $state = $state->withRoundPlayed($final, [
            new Result($final->getEvents()[0], null, [], [TieWinnerDeprecation::KEY => 'p1'], null, new Decider(['p2'])),
        ]);

        foreach (deciderRefusals($engine, $state) as $refusal) {
            expect($refusal?->getReason())->toBe(InvalidConfigurationReason::InvalidResult)
                ->and($refusal?->getContext())->toBe(['decider' => ['p2'], 'tie_winner' => 'p1', 'participants' => ['p1', 'p2']]);
        }
    });
});
