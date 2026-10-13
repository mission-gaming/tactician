<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Exceptions\InvalidInputException;
use MissionGaming\Tactician\Scheduling\SingleEliminationEngine;
use MissionGaming\Tactician\Scheduling\SwissPairingEngine;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Standings\Standings;
use MissionGaming\Tactician\Standings\StandingsCalculator;
use MissionGaming\Tactician\Tests\Support\AwkwardIds;
use MissionGaming\Tactician\Tests\Support\PlainDataForms;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

// An event has one result. The standings calculator used to tell two
// results of one event by the Event object they hold, and that object does
// not survive storage: a result recorded twice was refused on the live
// state and counted twice once the state had been through JSON, where each
// result holds an Event object of its own.
//
// The rule now: two results are of one event when they hold the same object,
// or when their events carry the same round number, the same participants in
// any order and the same tie leg.

/**
 * A table as plain values, for comparing two of them: each entry's ID with
 * what it played, won, drew and lost.
 *
 * @return list<string>
 */
function tableOf(Standings $standings): array
{
    $rows = [];
    foreach ($standings->getEntries() as $entry) {
        $rows[] = sprintf(
            '%s %d %d-%d-%d',
            $entry->getParticipant()->getId(),
            $entry->getPlayed(),
            $entry->getWins(),
            $entry->getDraws(),
            $entry->getLosses()
        );
    }

    return $rows;
}

describe('two results of one event', function (): void {
    beforeEach(function (): void {
        $this->alice = new Participant('a', 'Alice');
        $this->bob = new Participant('b', 'Bob');
        $this->carol = new Participant('c', 'Carol');
        $this->dan = new Participant('d', 'Dan');
        $this->field = [$this->alice, $this->bob, $this->carol, $this->dan];
    });

    it('are refused when the two events are equal and are not one object', function (): void {
        $recorded = new Result(new Event([$this->alice, $this->bob], new Round(1)), $this->alice);
        $again = new Result(new Event([$this->alice, $this->bob], new Round(1)), $this->alice);

        expect(fn(): Standings => (new StandingsCalculator())->calculate($this->field, [$recorded, $again]))->toThrow(
            InvalidInputException::class,
            'Two results reference the same event; each event can have only one result (round 1, a vs b).'
            . ' Events with the same round number, participants and tie leg are one event: if these are two'
            . ' (one table over two stages or seasons, or two meetings in one round), calculate a table for each,'
            . " continue the round numbers, or give each event a 'tie_leg'"
        );
    });

    // An event has no identifier, so a table over two schedules that number
    // their rounds alike is refused at the first pair that met in the same
    // round of both. The exception has to say what to do about it, and each
    // of the three ways it names has to work.
    it('are refused across two schedules numbered alike, with a message that says how to go on', function (): void {
        $seasonOne = [new Result(new Event([$this->alice, $this->bob], new Round(1)), $this->alice)];
        $seasonTwo = [new Result(new Event([$this->bob, $this->alice], new Round(1)), $this->bob)];
        $calculator = new StandingsCalculator();

        expect(fn(): Standings => $calculator->calculate($this->field, [...$seasonOne, ...$seasonTwo]))->toThrow(
            InvalidInputException::class,
            "calculate a table for each, continue the round numbers, or give each event a 'tie_leg'"
        );

        $continued = [new Result(new Event([$this->bob, $this->alice], new Round(2)), $this->bob)];
        $legs = [
            new Result(new Event([$this->alice, $this->bob], new Round(1), ['tie_leg' => 1]), $this->alice),
            new Result(new Event([$this->bob, $this->alice], new Round(1), ['tie_leg' => 2]), $this->bob),
        ];

        expect(tableOf($calculator->calculate([$this->alice, $this->bob], $seasonOne)))->toBe(['a 1 1-0-0', 'b 1 0-0-1'])
            ->and(tableOf($calculator->calculate([$this->alice, $this->bob], $seasonTwo)))->toBe(['b 1 1-0-0', 'a 1 0-0-1'])
            ->and(tableOf($calculator->calculate([$this->alice, $this->bob], [...$seasonOne, ...$continued])))->toBe(['a 2 1-0-1', 'b 2 1-0-1'])
            ->and(tableOf($calculator->calculate([$this->alice, $this->bob], $legs)))->toBe(['a 2 1-0-1', 'b 2 1-0-1']);
    });

    it('are refused whichever way round the participants are named', function (): void {
        $recorded = new Result(new Event([$this->alice, $this->bob], new Round(2)), $this->alice);
        $again = new Result(new Event([$this->bob, $this->alice], new Round(2)), $this->bob);

        expect(fn(): Standings => (new StandingsCalculator())->calculate($this->field, [$recorded, $again]))
            ->toThrow(InvalidInputException::class, '(round 2, b vs a)');
    });

    // The defect as it was reported
    it('are refused after the state has been through JSON, as they are before', function (): void {
        $engine = new SwissPairingEngine();
        $state = StageState::start($this->field);
        $pairing = $engine->pairNextRound($state);
        $event = $pairing->getEvents()[0];
        $winner = $event->getParticipants()[0];

        // The same request sent twice: the result is recorded, then added
        // again. Every event of the round has a result, so the engine is not
        // waiting for one and reads its table.
        $state = $state
            ->withRoundPlayed($pairing, array_map(
                static fn(Event $played): Result => new Result($played, $played->getParticipants()[0]),
                $pairing->getEvents()
            ))
            ->withAdditionalResults([new Result($event, $winner)]);
        $restored = StageState::fromJson($state->toJson());

        $calculator = new StandingsCalculator();
        $live = fn(): Standings => $calculator->calculate($state->getParticipants(), $state->getResults());
        $stored = fn(): Standings => $calculator->calculate($restored->getParticipants(), $restored->getResults());

        expect($live)->toThrow(InvalidInputException::class, 'Two results reference the same event')
            ->and($stored)->toThrow(InvalidInputException::class, 'Two results reference the same event')
            // The engine pairs from the table, so it refuses the state too
            ->and(fn(): mixed => $engine->pairNextRound($state))->toThrow(InvalidInputException::class, 'Two results reference the same event')
            ->and(fn(): mixed => $engine->pairNextRound($restored))->toThrow(InvalidInputException::class, 'Two results reference the same event');
    });

    it('are refused by a bracket engine before and after JSON, with the reason it always gave', function (): void {
        $engine = new SingleEliminationEngine();
        $state = StageState::start([$this->alice, $this->bob]);
        $pairing = $engine->pairNextRound($state);
        $final = $pairing->getEvents()[0];
        $state = $state->withRoundPlayed($pairing, [new Result($final, $this->alice)])
            ->withAdditionalResults([new Result($final, $this->alice)]);

        foreach ([$state, StageState::fromJson($state->toJson())] as $either) {
            try {
                $engine->getOutcome($either);
                $this->fail('The state was read.');
            } catch (InvalidConfigurationException $exception) {
                expect($exception->getReason())->toBe(InvalidConfigurationReason::DuplicateResult);
            }
        }
    });

    it('are repaired by replacing the result, in a state that came back from JSON', function (): void {
        $engine = new SwissPairingEngine();
        $state = StageState::start($this->field);
        $pairing = $engine->pairNextRound($state);
        [$first, $second] = $pairing->getEvents();
        $state = $state->withRoundPlayed($pairing, [
            new Result($first, $first->getParticipants()[0]),
            new Result($second, $second->getParticipants()[0]),
        ])->withAdditionalResults([new Result($first, $first->getParticipants()[0])]);

        $restored = StageState::fromJson($state->toJson());
        $repaired = $restored->withResultReplaced($restored->getResults()[0]);

        $table = tableOf((new StandingsCalculator())->calculate($repaired->getParticipants(), $repaired->getResults()));

        expect($repaired->getResults())->toHaveCount(2);
        foreach ($table as $row) {
            // Everyone has played once
            expect($row)->toMatch('/^\S+ 1 (1-0-0|0-0-1)$/');
        }
    });
});

describe('results that are not of one event', function (): void {
    beforeEach(function (): void {
        $this->alice = new Participant('a', 'Alice');
        $this->bob = new Participant('b', 'Bob');
    });

    it('are counted apart: the two legs of a tie, in one round', function (): void {
        $round = new Round(1);
        $results = [
            new Result(new Event([$this->alice, $this->bob], $round, ['tie_leg' => 1]), $this->alice),
            new Result(new Event([$this->bob, $this->alice], $round, ['tie_leg' => 2]), $this->alice),
        ];

        expect(tableOf((new StandingsCalculator())->calculate([$this->alice, $this->bob], $results)))
            ->toBe(['a 2 2-0-0', 'b 2 0-0-2']);
    });

    it('are counted apart: the same two participants in two rounds', function (): void {
        $results = [
            new Result(new Event([$this->alice, $this->bob], new Round(1)), $this->alice),
            new Result(new Event([$this->bob, $this->alice], new Round(4)), $this->bob),
        ];

        expect(tableOf((new StandingsCalculator())->calculate([$this->alice, $this->bob], $results)))
            ->toBe(['a 2 1-0-1', 'b 2 1-0-1']);
    });

    // An event with no round says nothing that tells it from another
    // meeting of the same two, so only the object does
    it('are counted apart: two events with no round', function (): void {
        $results = [
            new Result(new Event([$this->alice, $this->bob]), $this->alice),
            new Result(new Event([$this->alice, $this->bob]), $this->alice),
        ];

        expect(tableOf((new StandingsCalculator())->calculate([$this->alice, $this->bob], $results)))
            ->toBe(['a 2 2-0-0', 'b 2 0-0-2']);
    });

    it('are still one event when one object with no round has two results', function (): void {
        $event = new Event([$this->alice, $this->bob]);

        expect(fn(): Standings => (new StandingsCalculator())->calculate(
            [$this->alice, $this->bob],
            [new Result($event, $this->alice), new Result($event, $this->bob)]
        ))->toThrow(InvalidInputException::class, 'Two results reference the same event; each event can have only one result');
    });
});

describe('a table from a stored state', function (): void {
    // Whatever a state holds, reading it back changes nothing a table
    // shows: the same table, or the same refusal.
    it('is the table of the live state, or refused as the live state is', function (array $ids): void {
        $field = AwkwardIds::participants($ids);
        $generate = PlainDataForms::all()['stage state']['generate'];
        assert($generate !== null);
        $random = new Randomizer(new Xoshiro256StarStar(53));
        $calculator = new StandingsCalculator();
        $tables = 0;
        $refusals = 0;

        for ($i = 0; $i < 60; ++$i) {
            $state = $generate($random, $field);
            assert($state instanceof StageState);

            // Every third state has one of its results recorded a second time
            $results = $state->getResults();
            if ($i % 3 === 0 && $results !== [] && $state->getLastRound() instanceof RoundPairing) {
                $last = $results[count($results) - 1];
                if ($last->getEvent()->getRound()?->getNumber() === $state->getLastRound()->getRoundNumber()) {
                    $state = $state->withAdditionalResults([$last]);
                }
            }

            $restored = StageState::fromJson($state->toJson());
            $table = function (StageState $either) use ($calculator): array {
                try {
                    return tableOf($calculator->calculate($either->getAllSeenParticipants(), $either->getResults()));
                } catch (InvalidInputException $exception) {
                    return ['refused: ' . $exception->getMessage()];
                }
            };

            $live = $table($state);
            expect($table($restored))->toBe($live, "state {$i}");
            str_starts_with($live[0] ?? '', 'refused: ') ? ++$refusals : ++$tables;
        }

        expect($tables)->toBeGreaterThan(20)
            ->and($refusals)->toBeGreaterThan(3);
    })->with(AwkwardIds::dataset());
});
