<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Scheduling\EliminationOptions;
use MissionGaming\Tactician\Scheduling\SingleEliminationEngine;
use MissionGaming\Tactician\Scheduling\SwissPairingEngine;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Stage\StageState;

// StageState::withResultReplaced() corrects the recorded result of an event
// of the last recorded round. Before it existed a wrong result could only be
// corrected by rebuilding the state, and a result an engine cannot accept
// (a drawn knockout match) left a state that every engine call rejected.

describe('StageState::withResultReplaced()', function (): void {
    beforeEach(function (): void {
        $this->alice = new Participant('p1', 'Alice', 1);
        $this->bob = new Participant('p2', 'Bob', 2);
        $this->carol = new Participant('p3', 'Carol', 3);
        $this->dave = new Participant('p4', 'Dave', 4);
        $this->participants = [$this->alice, $this->bob, $this->carol, $this->dave];

        $this->first = new Event([$this->alice, $this->bob], new Round(1));
        $this->second = new Event([$this->carol, $this->dave], new Round(1));
        $this->pairing = new RoundPairing(1, null, [$this->first, $this->second]);
        $this->aliceWins = new Result($this->first, $this->alice);
        $this->carolWins = new Result($this->second, $this->carol);
        $this->state = StageState::start($this->participants)
            ->withRoundPlayed($this->pairing, [$this->aliceWins, $this->carolWins]);
    });

    it('replaces the result of an event of the last round, in a new state', function (): void {
        $bobWins = new Result($this->first, $this->bob, ['p1' => 1, 'p2' => 2]);

        $corrected = $this->state->withResultReplaced($bobWins);

        expect($corrected->getResults())->toBe([$bobWins, $this->carolWins])
            ->and($corrected->getRoundsPlayed())->toBe([$this->pairing])
            ->and($corrected->getParticipants())->toBe($this->participants);

        // The state it was called on is unchanged.
        expect($this->state->getResults())->toBe([$this->aliceWins, $this->carolWins]);
    });

    it('keeps the position of the result it replaces', function (): void {
        $daveWins = new Result($this->second, $this->dave);

        expect($this->state->withResultReplaced($daveWins)->getResults())
            ->toBe([$this->aliceWins, $daveWins]);
    });

    it('finds the event by round, participants and leg, not by object', function (): void {
        // A state that came back from storage holds other objects, and the
        // replacement may name the two participants in either order.
        $restored = StageState::fromJson($this->state->toJson());
        $replacement = new Result(new Event([$this->bob, $this->alice], new Round(1)), $this->bob);

        $results = $restored->withResultReplaced($replacement)->getResults();

        expect($results)->toHaveCount(2)
            ->and($results[0])->toBe($replacement)
            ->and($results[1]->getWinner()?->getId())->toBe('p3');
    });

    it('replaces one leg of a two-legged tie and leaves the other', function (): void {
        $firstLeg = new Event([$this->alice, $this->bob], new Round(1), ['tie_leg' => 1]);
        $secondLeg = new Event([$this->bob, $this->alice], new Round(1), ['tie_leg' => 2]);
        $legOne = new Result($firstLeg, $this->alice);
        $legTwo = new Result($secondLeg, $this->bob);
        $state = StageState::start([$this->alice, $this->bob])
            ->withRoundPlayed(new RoundPairing(1, null, [$firstLeg, $secondLeg]), [$legOne, $legTwo]);

        $corrected = new Result($secondLeg, $this->alice);

        expect($state->withResultReplaced($corrected)->getResults())->toBe([$legOne, $corrected]);
    });

    it('leaves one result where the event had been given two', function (): void {
        // withAdditionalResults() does not look for a result the event
        // already has, and an elimination engine rejects the pair.
        $again = new Result(new Event([$this->alice, $this->bob], new Round(1)), $this->bob);
        $state = $this->state->withAdditionalResults([$again]);
        expect($state->getResults())->toHaveCount(3);

        $final = new Result($this->first, $this->bob);

        expect($state->withResultReplaced($final)->getResults())->toBe([$final, $this->carolWins]);
    });

    it('keeps withdrawals and byes', function (): void {
        $eve = new Participant('p5', 'Eve', 5);
        $state = StageState::start([...$this->participants, $eve])
            ->withRoundPlayed(
                new RoundPairing(1, null, [$this->first, $this->second], [$eve]),
                [$this->aliceWins, $this->carolWins]
            )
            ->withoutParticipant($this->dave);

        $corrected = $state->withResultReplaced(new Result($this->first, $this->bob));

        expect($corrected->getParticipants())->toBe([$this->alice, $this->bob, $this->carol, $eve])
            ->and($corrected->getByeIds())->toBe(['p5']);
    });

    it('fails when no round has been recorded', function (): void {
        StageState::start($this->participants)->withResultReplaced($this->aliceWins);
    })->throws(InvalidConfigurationException::class, 'No round has been recorded to replace a result in');

    it('fails when the event has no recorded result', function (): void {
        $state = StageState::start($this->participants)->withRoundPlayed($this->pairing, [$this->aliceWins]);

        try {
            $state->withResultReplaced(new Result($this->second, $this->dave));
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getMessage())->toContain(
                'No result is recorded for the event; record a first result with withRoundPlayed() or withAdditionalResults()'
            );
            expect($exception->getContext())->toBe(['round' => 1, 'participants' => ['p3', 'p4']]);

            return;
        }

        throw new LogicException('A result was replaced that was never recorded.');
    });

    it('fails for an event that is not in any recorded round', function (): void {
        $stranger = new Result(new Event([$this->alice, $this->carol], new Round(1)), $this->alice);

        $this->state->withResultReplaced($stranger);
    })->throws(InvalidConfigurationException::class, 'No result is recorded for the event');

    it('fails once a later round is recorded, and says which', function (): void {
        $final = new Event([$this->alice, $this->carol], new Round(2));
        $state = $this->state->withRoundPlayed(new RoundPairing(2, null, [$final]), []);

        try {
            $state->withResultReplaced(new Result($this->first, $this->bob));
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getMessage())->toContain(
                'A result of round 1 cannot be replaced: round 2 was paired from the results of round 1.'
            );
            expect($exception->getContext())->toBe(['round' => 1, 'last_round' => 2]);

            return;
        }

        throw new LogicException('A result was replaced under a round that was paired from it.');
    });

    it('replaces a result of the last round when earlier rounds exist', function (): void {
        $final = new Event([$this->alice, $this->carol], new Round(2));
        $carolWins = new Result($final, $this->carol);
        $state = $this->state->withRoundPlayed(new RoundPairing(2, null, [$final]), [new Result($final, $this->alice)]);

        expect($state->withResultReplaced($carolWins)->getResults())
            ->toBe([$this->aliceWins, $this->carolWins, $carolWins]);
    });
});

describe('correcting a result an engine cannot use', function (): void {
    beforeEach(function (): void {
        $this->participants = [
            new Participant('p1', 'Alice', 1),
            new Participant('p2', 'Bob', 2),
            new Participant('p3', 'Carol', 3),
            new Participant('p4', 'Dave', 4),
        ];
    });

    it('lets a bracket go on after a drawn knockout match is corrected', function (): void {
        $engine = new SingleEliminationEngine();
        $state = StageState::start($this->participants);

        $semifinals = $engine->pairNextRound($state);
        [$first, $second] = $semifinals->getEvents();

        // The state accepts the draw: it does not know the format.
        $state = $state->withRoundPlayed($semifinals, [new Result($first), new Result($second, $second->getParticipants()[0])]);

        expect(fn() => $engine->isComplete($state))
            ->toThrow(InvalidConfigurationException::class, 'Elimination events cannot end in a draw');
        expect(fn() => $engine->pairNextRound($state))->toThrow(InvalidConfigurationException::class);
        expect(fn() => $engine->getOutcome($state))->toThrow(InvalidConfigurationException::class);

        $state = $state->withResultReplaced(new Result($first, $first->getParticipants()[1]));

        expect($engine->isComplete($state))->toBeFalse();

        $final = $engine->pairNextRound($state);
        expect($final->getLabel())->toBe('final');

        $finalists = array_map(fn(Participant $p) => $p->getId(), $final->getEvents()[0]->getParticipants());
        expect($finalists)->toContain($first->getParticipants()[1]->getId())
            ->and($finalists)->toContain($second->getParticipants()[0]->getId());
    });

    it('re-pairs the next knockout round from the corrected winner', function (): void {
        $engine = new SingleEliminationEngine(new EliminationOptions());
        $state = StageState::start($this->participants);

        $semifinals = $engine->pairNextRound($state);
        [$first, $second] = $semifinals->getEvents();
        [$home, $away] = $first->getParticipants();
        $state = $state->withRoundPlayed($semifinals, [
            new Result($first, $home),
            new Result($second, $second->getParticipants()[0]),
        ]);

        $before = $engine->pairNextRound($state)->getEvents()[0];
        $after = $engine->pairNextRound($state->withResultReplaced(new Result($first, $away)))->getEvents()[0];

        expect($before->hasParticipant($home))->toBeTrue()
            ->and($after->hasParticipant($home))->toBeFalse()
            ->and($after->hasParticipant($away))->toBeTrue();
    });

    it('pairs the next Swiss round from the corrected table', function (): void {
        $engine = new SwissPairingEngine();
        $state = StageState::start($this->participants);

        $round = $engine->pairNextRound($state);
        [$first, $second] = $round->getEvents();
        $state = $state->withRoundPlayed($round, [
            new Result($first, $first->getParticipants()[0]),
            new Result($second, $second->getParticipants()[0]),
        ]);

        $corrected = $state->withResultReplaced(new Result($first, $first->getParticipants()[1]));
        $leaders = $engine->pairNextRound($corrected)->getEvents()[0];

        // The two winners meet: after the correction, the other
        // participant of the first event is one of them.
        expect($leaders->hasParticipant($first->getParticipants()[1]))->toBeTrue()
            ->and($leaders->hasParticipant($second->getParticipants()[0]))->toBeTrue();
    });

    it('is rebuilt, not replaced, when the round is no longer the last', function (): void {
        // What the failure tells the caller to do: replay the rounds that
        // stand, with the corrected result, and pair again from there.
        $engine = new SingleEliminationEngine();
        $state = StageState::start($this->participants);

        $semifinals = $engine->pairNextRound($state);
        [$first, $second] = $semifinals->getEvents();
        $wrong = [new Result($first, $first->getParticipants()[0]), new Result($second, $second->getParticipants()[0])];
        $state = $state->withRoundPlayed($semifinals, $wrong);

        $final = $engine->pairNextRound($state);
        $state = $state->withRoundPlayed($final, []);

        $correction = new Result($first, $first->getParticipants()[1]);
        expect(fn() => $state->withResultReplaced($correction))->toThrow(InvalidConfigurationException::class);

        $rebuilt = StageState::start($this->participants)->withRoundPlayed($semifinals, [$correction, $wrong[1]]);

        expect($engine->pairNextRound($rebuilt)->getEvents()[0]->hasParticipant($first->getParticipants()[1]))
            ->toBeTrue();
    });
});
