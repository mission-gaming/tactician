<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Exceptions\TacticianException;
use MissionGaming\Tactician\Scheduling\DoubleEliminationEngine;
use MissionGaming\Tactician\Scheduling\EliminationOptions;
use MissionGaming\Tactician\Scheduling\SingleEliminationEngine;
use MissionGaming\Tactician\Stage\MatchOutcomeSelector;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Stage\StageEngineInterface;
use MissionGaming\Tactician\Stage\StageOutcome;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Stage\TieDecision;
use MissionGaming\Tactician\Standings\StandingsCalculator;

// A single-leg elimination event that finishes level is decided by the
// application, which records who advances as 'tie_winner' metadata on the
// event's result: the key and the reading a level two-legged tie has.

/**
 * @return array<Participant>
 */
function levelEventField(int $count): array
{
    $participants = [];
    for ($i = 1; $i <= $count; ++$i) {
        $participants[] = new Participant("p{$i}", "P{$i}", $i);
    }

    return $participants;
}

/**
 * A result that finished level 1-1, with who advances when one is named.
 */
function levelResult(Event $event, ?string $advancingId = null): Result
{
    [$first, $second] = $event->getParticipants();

    return new Result(
        $event,
        null,
        [$first->getId() => 1, $second->getId() => 1],
        $advancingId === null ? [] : [TieDecision::TIE_WINNER_KEY => $advancingId]
    );
}

/**
 * A result won 2-1 by the named participant.
 */
function decisiveResult(Event $event, string $winnerId): Result
{
    [$first, $second] = $event->getParticipants();
    $winner = $first->getId() === $winnerId ? $first : $second;
    $loser = $first->getId() === $winnerId ? $second : $first;

    return new Result($event, $winner, [$winner->getId() => 2, $loser->getId() => 1]);
}

/**
 * @param array<Event> $events
 * @return list<string>
 */
function levelEventPairs(array $events): array
{
    return array_values(array_map(
        fn(Event $event): string => implode(' v ', array_map(
            fn(Participant $participant): string => $participant->getId(),
            $event->getParticipants()
        )),
        $events
    ));
}

/**
 * Pair the next round, check it against the expected pairs, and record a
 * result for each of its events: `'=p4'` is level with p4 advancing,
 * `'p2'` is a win for p2.
 *
 * @param array<string, string> $decisions Keyed by the expected pair, in event order
 * @throws TacticianException When the engine refuses the state
 */
function playLevelRound(StageEngineInterface $engine, StageState $state, ?string $label, array $decisions): StageState
{
    $pairing = $engine->pairNextRound($state);
    expect($pairing->getLabel())->toBe($label);
    expect(levelEventPairs($pairing->getEvents()))->toBe(array_keys($decisions));

    $results = [];
    foreach (array_values($decisions) as $index => $decision) {
        $event = $pairing->getEvents()[$index];
        $results[] = str_starts_with($decision, '=')
            ? levelResult($event, substr($decision, 1))
            : decisiveResult($event, $decision);
    }

    return $state->withRoundPlayed($pairing, $results);
}

/**
 * The three calls an engine answers about a state.
 *
 * @return array<string, Closure(StageState): mixed>
 */
function levelEventEngineCalls(StageEngineInterface $engine): array
{
    return [
        'isComplete' => $engine->isComplete(...),
        'pairNextRound' => $engine->pairNextRound(...),
        'getOutcome' => $engine->getOutcome(...),
    ];
}

/**
 * @return array<string, array{int, int, int}> Wins, draws and losses by participant id, in table order
 */
function levelEventRecords(StageOutcome $outcome): array
{
    $records = [];
    foreach ($outcome->getStandings()->getEntries() as $entry) {
        $records[$entry->getParticipant()->getId()] = [$entry->getWins(), $entry->getDraws(), $entry->getLosses()];
    }

    return $records;
}

describe('a level single-leg event in single elimination', function (): void {
    it('advances the participant the result names, and no engine call throws', function (): void {
        $engine = new SingleEliminationEngine();
        $state = StageState::start(levelEventField(4));

        $state = playLevelRound($engine, $state, 'semifinal', ['p1 v p4' => '=p4', 'p2 v p3' => 'p2']);

        expect($engine->isComplete($state))->toBeFalse();
        expect($engine->getOutcome($state))->toBeNull();
        expect(levelEventPairs($engine->pairNextRound($state)->getEvents()))->toBe(['p4 v p2']);

        $state = playLevelRound($engine, $state, 'final', ['p4 v p2' => 'p2']);

        expect($engine->isComplete($state))->toBeTrue();
        $outcome = $engine->getOutcome($state);
        assert($outcome !== null);
        expect(MatchOutcomeSelector::winners()->select($outcome)[0]->getId())->toBe('p2');
    });

    it('advances the first participant of the event as readily as the second', function (): void {
        $engine = new SingleEliminationEngine();
        $state = StageState::start(levelEventField(4));

        $state = playLevelRound($engine, $state, 'semifinal', ['p1 v p4' => '=p1', 'p2 v p3' => '=p3']);

        expect(levelEventPairs($engine->pairNextRound($state)->getEvents()))->toBe(['p1 v p3']);
    });

    it('decides a level final, and the standings place the bracket as wins would', function (): void {
        $engine = new SingleEliminationEngine();
        $state = StageState::start(levelEventField(4));

        $state = playLevelRound($engine, $state, 'semifinal', ['p1 v p4' => '=p4', 'p2 v p3' => 'p2']);
        $state = playLevelRound($engine, $state, 'final', ['p4 v p2' => '=p4']);

        expect($engine->isComplete($state))->toBeTrue();
        $outcome = $engine->getOutcome($state);
        assert($outcome !== null);

        expect(MatchOutcomeSelector::winners()->select($outcome)[0]->getId())->toBe('p4');
        expect(MatchOutcomeSelector::losers()->select($outcome)[0]->getId())->toBe('p2');

        // Rank 1 is the participant who advanced from the final: in the
        // standings a decided level event is a win for the one who went on
        // and a loss for the other, so the table places a bracket as it
        // always has (2-0, 1-1, then the two semifinal losers on 0-1).
        $records = levelEventRecords($outcome);
        expect(array_key_first($records))->toBe('p4');
        expect($records['p4'])->toBe([2, 0, 0]);
        expect($records['p2'])->toBe([1, 0, 1]);
        expect($records['p1'])->toBe([0, 0, 1]);
        expect($records['p3'])->toBe([0, 0, 1]);
    });

    it('leaves the recorded result as it was recorded', function (): void {
        $engine = new SingleEliminationEngine();
        $state = StageState::start(levelEventField(2));
        $state = playLevelRound($engine, $state, 'final', ['p1 v p2' => '=p2']);

        $outcome = $engine->getOutcome($state);
        assert($outcome !== null);
        $recorded = $outcome->getResults()[0];

        expect($recorded->isDraw())->toBeTrue();
        expect($recorded->getWinner())->toBeNull();
        expect($recorded->getScores())->toBe(['p1' => 1, 'p2' => 1]);
        expect($recorded->getMetadataValue(TieDecision::TIE_WINNER_KEY))->toBe('p2');
        expect($state->getResults()[0])->toBe($recorded);

        // Who advanced is read from the result with the reading the engines use.
        [$first, $second] = $recorded->getEvent()->getParticipants();
        expect(TieDecision::advancer([$recorded], $first, $second, 1)?->getId())->toBe('p2');
    });

    it('re-seeds the survivors of level events as it re-seeds winners', function (): void {
        // The same four quarter-finals twice, with the same scores: once
        // three of them are level and decided, once the participant who
        // advanced is recorded as the winner. The re-seeding reads the
        // standings, so both must give the same semifinals.
        $engine = new SingleEliminationEngine(new EliminationOptions(reseedEachRound: true));
        $advancing = ['p8', 'p4', 'p2', 'p6'];

        $start = StageState::start(levelEventField(8));
        $quarterfinals = $engine->pairNextRound($start);
        expect(levelEventPairs($quarterfinals->getEvents()))->toBe(['p1 v p8', 'p4 v p5', 'p2 v p7', 'p3 v p6']);

        $level = [];
        $won = [];
        foreach ($quarterfinals->getEvents() as $index => $event) {
            [$first, $second] = $event->getParticipants();
            $advancer = $first->getId() === $advancing[$index] ? $first : $second;
            $scores = [$first->getId() => 1, $second->getId() => 1];

            $won[] = new Result($event, $advancer, $scores);
            $level[] = $advancing[$index] === 'p4'
                ? new Result($event, $advancer, $scores)
                : new Result($event, null, $scores, [TieDecision::TIE_WINNER_KEY => $advancing[$index]]);
        }

        $afterLevel = $engine->pairNextRound($start->withRoundPlayed($quarterfinals, $level));
        $afterWon = $engine->pairNextRound($start->withRoundPlayed($quarterfinals, $won));

        expect(levelEventPairs($afterLevel->getEvents()))->toBe(levelEventPairs($afterWon->getEvents()));

        $semifinalists = array_merge(...array_map(
            fn(Event $event): array => array_map(fn(Participant $participant) => $participant->getId(), $event->getParticipants()),
            $afterLevel->getEvents()
        ));
        sort($semifinalists);
        expect($semifinalists)->toBe(['p2', 'p4', 'p6', 'p8']);
    });

    it('keeps the decision through JSON in the middle of the bracket', function (): void {
        $engine = new SingleEliminationEngine();
        $state = StageState::start(levelEventField(4));
        $state = playLevelRound($engine, $state, 'semifinal', ['p1 v p4' => '=p4', 'p2 v p3' => 'p2']);

        $restored = StageState::fromJson($state->toJson());

        expect($restored->toArray())->toBe($state->toArray());
        expect($restored->getResults()[0]->getMetadataValue(TieDecision::TIE_WINNER_KEY))->toBe('p4');
        expect(levelEventPairs($engine->pairNextRound($restored)->getEvents()))->toBe(['p4 v p2']);
    });
});

describe('a level single-leg event in double elimination', function (): void {
    it('advances the named participant in the winners bracket and drops the other', function (): void {
        $engine = new DoubleEliminationEngine();
        $state = StageState::start(levelEventField(4));

        $state = playLevelRound($engine, $state, 'winners round 1', ['p1 v p4' => '=p4', 'p2 v p3' => 'p2']);

        expect($engine->isComplete($state))->toBeFalse();
        expect($engine->getOutcome($state))->toBeNull();

        // p1 did not advance, so p1 drops into the losers bracket.
        $state = playLevelRound($engine, $state, 'losers round 1', ['p1 v p3' => 'p1']);

        // p4 advanced, so p4 is in the winners final.
        expect(levelEventPairs($engine->pairNextRound($state)->getEvents()))->toBe(['p4 v p2']);
    });

    it('advances the named participant in the losers bracket and eliminates the other', function (): void {
        $engine = new DoubleEliminationEngine();
        $state = StageState::start(levelEventField(4));

        $state = playLevelRound($engine, $state, 'winners round 1', ['p1 v p4' => 'p1', 'p2 v p3' => 'p2']);
        $state = playLevelRound($engine, $state, 'losers round 1', ['p4 v p3' => '=p3']);

        expect($engine->isComplete($state))->toBeFalse();
        expect($engine->getOutcome($state))->toBeNull();

        $state = playLevelRound($engine, $state, 'winners final', ['p1 v p2' => 'p1']);

        // p3 advanced from the level losers round and meets the dropper.
        $state = playLevelRound($engine, $state, 'losers final', ['p2 v p3' => '=p3']);

        expect(levelEventPairs($engine->pairNextRound($state)->getEvents()))->toBe(['p1 v p3']);
    });

    it('runs a whole bracket of level events, grand final and reset included', function (): void {
        $engine = new DoubleEliminationEngine();
        $state = StageState::start(levelEventField(4));

        $state = playLevelRound($engine, $state, 'winners round 1', ['p1 v p4' => '=p4', 'p2 v p3' => '=p2']);
        $state = playLevelRound($engine, $state, 'losers round 1', ['p1 v p3' => '=p3']);
        $state = playLevelRound($engine, $state, 'winners final', ['p4 v p2' => '=p2']);
        $state = playLevelRound($engine, $state, 'losers final', ['p4 v p3' => '=p3']);

        // The losers champion advances from the level grand final: both
        // finalists have now failed to advance once, so the reset is played.
        $state = playLevelRound($engine, $state, 'grand final', ['p2 v p3' => '=p3']);
        expect($engine->isComplete($state))->toBeFalse();

        $state = playLevelRound($engine, $state, 'grand final reset', ['p2 v p3' => '=p2']);
        expect($engine->isComplete($state))->toBeTrue();

        $outcome = $engine->getOutcome($state);
        assert($outcome !== null);
        expect(MatchOutcomeSelector::winners()->select($outcome)[0]->getId())->toBe('p2');
        expect(MatchOutcomeSelector::losers()->select($outcome)[0]->getId())->toBe('p3');

        // Everyone but the title holder failed to advance twice.
        $records = levelEventRecords($outcome);
        ksort($records);
        expect($records)->toBe([
            'p1' => [0, 0, 2],
            'p2' => [3, 0, 1],
            'p3' => [3, 0, 2],
            'p4' => [1, 0, 2],
        ]);
    });

    it('ends at a level grand final the winners champion advances from', function (): void {
        $engine = new DoubleEliminationEngine();
        $state = StageState::start(levelEventField(2));

        $state = playLevelRound($engine, $state, 'winners final', ['p1 v p2' => '=p2']);
        $state = playLevelRound($engine, $state, 'grand final', ['p2 v p1' => '=p2']);

        expect($engine->isComplete($state))->toBeTrue();
    });

    it('ends at a level grand final when the reset is switched off', function (): void {
        $engine = new DoubleEliminationEngine(new EliminationOptions(grandFinalReset: false));
        $state = StageState::start(levelEventField(2));

        $state = playLevelRound($engine, $state, 'winners final', ['p1 v p2' => '=p2']);
        $state = playLevelRound($engine, $state, 'grand final', ['p2 v p1' => '=p1']);

        expect($engine->isComplete($state))->toBeTrue();
        $outcome = $engine->getOutcome($state);
        assert($outcome !== null);
        expect(MatchOutcomeSelector::winners()->select($outcome)[0]->getId())->toBe('p1');
    });
});

describe('a level single-leg event with no usable decision', function (): void {
    it('is refused by every engine call, with a message naming the key to set', function (
        StageEngineInterface $engine
    ): void {
        $state = StageState::start(levelEventField(4));
        $pairing = $engine->pairNextRound($state);
        [$first, $second] = $pairing->getEvents();
        $state = $state->withRoundPlayed($pairing, [levelResult($first), decisiveResult($second, 'p2')]);

        foreach (levelEventEngineCalls($engine) as $call => $ask) {
            try {
                $ask($state);
                $thrown = null;
            } catch (InvalidConfigurationException $exception) {
                $thrown = $exception;
            }

            assert($thrown !== null, "{$call}() accepted a level event that names nobody.");
            expect($thrown->getMessage())->toBe(
                "Invalid scheduler configuration: Elimination events cannot end in a draw (P1 vs P4): the event is level, so record who advances as 'tie_winner' metadata on its result"
            );
            expect($thrown->getReason())->toBe(InvalidConfigurationReason::UndecidedTie);
            expect($thrown->getContext())->toBe(['participants' => ['p1', 'p4']]);
        }
    })->with([
        'single elimination' => [fn() => new SingleEliminationEngine()],
        're-seeded single elimination' => [fn() => new SingleEliminationEngine(new EliminationOptions(reseedEachRound: true))],
        'double elimination' => [fn() => new DoubleEliminationEngine()],
    ]);

    it('is refused when the decision names a participant outside the event, as for two legs', function (
        StageEngineInterface $engine,
        mixed $decision
    ): void {
        $state = StageState::start(levelEventField(4));
        $pairing = $engine->pairNextRound($state);
        [$first, $second] = $pairing->getEvents();
        $state = $state->withRoundPlayed($pairing, [
            new Result($first, null, [], [TieDecision::TIE_WINNER_KEY => $decision]),
            decisiveResult($second, 'p2'),
        ]);

        foreach (levelEventEngineCalls($engine) as $call => $ask) {
            try {
                $ask($state);
                $thrown = null;
            } catch (InvalidConfigurationException $exception) {
                $thrown = $exception;
            }

            assert($thrown !== null, "{$call}() accepted a decision for a participant outside the event.");
            expect($thrown->getMessage())->toBe('Invalid scheduler configuration: Tie decision names a participant who is not in the tie');
            expect($thrown->getReason())->toBe(InvalidConfigurationReason::InvalidResult);
            expect($thrown->getContext())->toBe(['tie_winner' => $decision, 'participants' => ['p1', 'p4']]);
        }
    })->with([
        'single elimination' => [fn() => new SingleEliminationEngine()],
        'double elimination' => [fn() => new DoubleEliminationEngine()],
    ])->with([
        'a participant of another event' => ['p2'],
        'nobody in the stage' => ['ghost'],
        'a value that is not an id' => [4],
        'an empty id' => [''],
    ]);

    it('gives the two-legged refusal the same exception, reason and context', function (): void {
        $engine = new SingleEliminationEngine(new EliminationOptions(legsPerTie: 2));
        $state = StageState::start(levelEventField(2));
        $pairing = $engine->pairNextRound($state);
        [$leg1, $leg2] = $pairing->getEvents();
        $state = $state->withRoundPlayed($pairing, [
            new Result($leg1),
            new Result($leg2, null, [], [TieDecision::TIE_WINNER_KEY => 'ghost']),
        ]);

        try {
            $engine->isComplete($state);
            $thrown = null;
        } catch (InvalidConfigurationException $exception) {
            $thrown = $exception;
        }

        assert($thrown !== null);
        expect($thrown->getMessage())->toBe('Invalid scheduler configuration: Tie decision names a participant who is not in the tie');
        expect($thrown->getReason())->toBe(InvalidConfigurationReason::InvalidResult);
        expect($thrown->getContext())->toBe(['tie_winner' => 'ghost', 'participants' => ['p1', 'p2']]);
    });

    it('treats a null decision as no decision', function (): void {
        $engine = new SingleEliminationEngine();
        $state = StageState::start(levelEventField(2));
        $pairing = $engine->pairNextRound($state);
        $state = $state->withRoundPlayed($pairing, [
            new Result($pairing->getEvents()[0], null, [], [TieDecision::TIE_WINNER_KEY => null]),
        ]);

        expect(fn() => $engine->isComplete($state))
            ->toThrow(InvalidConfigurationException::class, "record who advances as 'tie_winner' metadata");
    });
});

describe('a decision on a result that is not level', function (): void {
    // The decision is read only when the results leave the tie level, for
    // one leg as for two: a decisive result is not overturned by it, and a
    // value that names nobody in the event goes unread.
    it('is not read for a single-leg event that has a winner', function (
        StageEngineInterface $engine,
        mixed $decision
    ): void {
        $state = StageState::start(levelEventField(4));
        $pairing = $engine->pairNextRound($state);
        [$first, $second] = $pairing->getEvents();
        [$p1] = $first->getParticipants();

        $plain = $state->withRoundPlayed($pairing, [new Result($first, $p1), decisiveResult($second, 'p2')]);
        $carrying = $state->withRoundPlayed($pairing, [
            new Result($first, $p1, [], [TieDecision::TIE_WINNER_KEY => $decision]),
            decisiveResult($second, 'p2'),
        ]);

        expect($engine->isComplete($carrying))->toBeFalse();
        expect($engine->pairNextRound($carrying)->toArray())->toBe($engine->pairNextRound($plain)->toArray());
    })->with([
        'single elimination' => [fn() => new SingleEliminationEngine()],
        'double elimination' => [fn() => new DoubleEliminationEngine()],
    ])->with([
        'the winner' => ['p1'],
        'the loser' => ['p4'],
        'nobody in the event' => ['ghost'],
    ]);

    it('is not read for a two-legged tie the legs decide', function (mixed $decision): void {
        $engine = new SingleEliminationEngine(new EliminationOptions(legsPerTie: 2));
        $state = StageState::start(levelEventField(2));
        $pairing = $engine->pairNextRound($state);
        [$leg1, $leg2] = $pairing->getEvents();
        [$p1] = $leg1->getParticipants();

        $state = $state->withRoundPlayed($pairing, [
            new Result($leg1, $p1),
            new Result($leg2, null, [], [TieDecision::TIE_WINNER_KEY => $decision]),
        ]);

        expect($engine->isComplete($state))->toBeTrue();
        $outcome = $engine->getOutcome($state);
        assert($outcome !== null);
        expect(MatchOutcomeSelector::winners()->select($outcome)[0]->getId())->toBe('p1');
    })->with([
        'the winner' => ['p1'],
        'the loser' => ['p2'],
        'nobody in the tie' => ['ghost'],
    ]);
});

describe('the match outcome selector over a level single-leg round', function (): void {
    beforeEach(function (): void {
        [$this->p1, $this->p2, $this->p3, $this->p4, $this->p5] = levelEventField(5);
        $round = new Round(1);
        $this->first = new Event([$this->p1, $this->p4], $round);
        $this->second = new Event([$this->p2, $this->p3], $round);
        $this->round = new RoundPairing(1, 'semifinal', [$this->first, $this->second], [$this->p5]);
    });

    it('selects the named participant as the winner and the other as the loser', function (): void {
        $results = [levelResult($this->first, 'p4'), levelResult($this->second, 'p2')];
        $standings = (new StandingsCalculator())->calculate([$this->p1, $this->p2, $this->p3, $this->p4, $this->p5], $results);
        $outcome = new StageOutcome($standings, $results, ['p5' => 1], $this->round);

        $ids = fn(array $selected): array => array_map(fn(Participant $participant) => $participant->getId(), $selected);

        expect($ids(MatchOutcomeSelector::winners()->select($outcome)))->toBe(['p4', 'p2', 'p5']);
        expect($ids(MatchOutcomeSelector::losers()->select($outcome)))->toBe(['p1', 'p3']);
    });

    it('refuses a level event with no decision, naming the key, in both modes', function (
        string $mode
    ): void {
        $selector = MatchOutcomeSelector::fromArray(['mode' => $mode]);
        $results = [levelResult($this->first), levelResult($this->second, 'p2')];
        $standings = (new StandingsCalculator())->calculate([$this->p1, $this->p2, $this->p3, $this->p4], $results);
        $outcome = new StageOutcome($standings, $results, [], $this->round);

        expect(fn() => $selector->select($outcome))->toThrow(
            InvalidConfigurationException::class,
            "Elimination events cannot end in a draw (P1 vs P4): the event is level, so record who advances as 'tie_winner' metadata on its result"
        );
    })->with([
        'winners' => ['winners'],
        'losers' => ['losers'],
    ]);

    it('refuses a decision for a participant outside the event', function (): void {
        $results = [levelResult($this->first, 'p2'), levelResult($this->second, 'p2')];
        $standings = (new StandingsCalculator())->calculate([$this->p1, $this->p2, $this->p3, $this->p4], $results);
        $outcome = new StageOutcome($standings, $results, [], $this->round);

        expect(fn() => MatchOutcomeSelector::winners()->select($outcome))
            ->toThrow(InvalidConfigurationException::class, 'Tie decision names a participant who is not in the tie');
    });
});

describe('input that worked before level events could be decided', function (): void {
    it('gives a decisive bracket the pairings, results and standings it always gave', function (): void {
        $engine = new SingleEliminationEngine();
        $state = StageState::start(levelEventField(4));

        $state = playLevelRound($engine, $state, 'semifinal', ['p1 v p4' => 'p4', 'p2 v p3' => 'p2']);
        $state = playLevelRound($engine, $state, 'final', ['p4 v p2' => 'p4']);

        $outcome = $engine->getOutcome($state);
        assert($outcome !== null);
        expect($outcome->getResults())->toBe($state->getResults());
        expect(levelEventRecords($outcome))->toBe([
            'p4' => [2, 0, 0],
            'p2' => [1, 0, 1],
            'p1' => [0, 0, 1],
            'p3' => [0, 0, 1],
        ]);
    });

    it('counts a level result the bracket never reads as the draw it is', function (): void {
        // A result for an event the bracket did not make is not a tie of
        // the bracket: nothing decides it, and the standings keep it level.
        $engine = new SingleEliminationEngine();
        [$p1, $p2, $p3] = $field = levelEventField(3);
        $state = StageState::start($field);

        $semifinal = $engine->pairNextRound($state);
        $state = $state->withRoundPlayed($semifinal, [decisiveResult($semifinal->getEvents()[0], 'p2')]);
        $final = $engine->pairNextRound($state);
        $stray = new Event([$p3, $p2], new Round(2));
        $state = $state->withRoundPlayed(
            new RoundPairing(2, 'final', [...$final->getEvents(), $stray]),
            [decisiveResult($final->getEvents()[0], 'p1'), levelResult($stray, 'p3')]
        );

        expect($engine->isComplete($state))->toBeTrue();
        $outcome = $engine->getOutcome($state);
        assert($outcome !== null);
        expect($outcome->getStandings()->getEntryFor($p3)?->getDraws())->toBe(1);
        expect($outcome->getStandings()->getEntryFor($p3)?->getWins())->toBe(0);
        expect($outcome->getStandings()->getEntryFor($p1)?->getWins())->toBe(1);
    });
});
