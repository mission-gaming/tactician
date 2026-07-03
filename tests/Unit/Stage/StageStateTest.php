<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Stage\StageState;

describe('StageState', function (): void {
    beforeEach(function (): void {
        $this->alice = new Participant('p1', 'Alice', 1);
        $this->bob = new Participant('p2', 'Bob', 2);
        $this->carol = new Participant('p3', 'Carol', 3);
        $this->dave = new Participant('p4', 'Dave', 4);
        $this->participants = [$this->alice, $this->bob, $this->carol, $this->dave];
    });

    it('starts with the given participants and no history', function (): void {
        $state = StageState::start($this->participants);

        expect($state->getParticipants())->toBe($this->participants);
        expect($state->getRoundsPlayed())->toBe([]);
        expect($state->getResults())->toBe([]);
        expect($state->getLastRound())->toBeNull();
        expect($state->getNextRoundNumber())->toBe(1);
        expect($state->getPlayedEvents())->toBe([]);
        expect($state->getByeIds())->toBe([]);
        expect($state->getByeCounts())->toBe([]);
    });

    it('rejects duplicate participant IDs at the start', function (): void {
        StageState::start([$this->alice, new Participant('p1', 'Alice Clone')]);
    })->throws(InvalidConfigurationException::class);

    it('records a played round immutably', function (): void {
        $event1 = new Event([$this->alice, $this->bob], new Round(1));
        $event2 = new Event([$this->carol, $this->dave], new Round(1));
        $pairing = new RoundPairing(1, null, [$event1, $event2]);
        $results = [new Result($event1, $this->alice), new Result($event2, $this->carol)];

        $start = StageState::start($this->participants);
        $state = $start->withRoundPlayed($pairing, $results);

        // Original state unchanged
        expect($start->getRoundsPlayed())->toBe([]);

        expect($state->getRoundsPlayed())->toBe([$pairing]);
        expect($state->getLastRound())->toBe($pairing);
        expect($state->getNextRoundNumber())->toBe(2);
        expect($state->getResults())->toBe($results);
        expect($state->getPlayedEvents())->toBe([$event1, $event2]);
    });

    it('accepts a round recorded without results', function (): void {
        $event = new Event([$this->alice, $this->bob], new Round(1));
        $state = StageState::start($this->participants)
            ->withRoundPlayed(new RoundPairing(1, null, [$event]), []);

        expect($state->getPlayedEvents())->toBe([$event]);
        expect($state->getResults())->toBe([]);
        expect($state->getNextRoundNumber())->toBe(2);
    });

    it('rejects results from a different round than the pairing', function (): void {
        $event = new Event([$this->alice, $this->bob], new Round(2));
        $pairing = new RoundPairing(1, null, [new Event([$this->alice, $this->bob], new Round(1))]);

        StageState::start($this->participants)
            ->withRoundPlayed($pairing, [new Result($event, $this->alice)]);
    })->throws(InvalidConfigurationException::class);

    it('rejects rounds recorded out of play order', function (): void {
        $state = StageState::start($this->participants)
            ->withRoundPlayed(new RoundPairing(2, null, [
                new Event([$this->alice, $this->bob], new Round(2)),
            ]), []);

        $state->withRoundPlayed(new RoundPairing(2, null, [
            new Event([$this->carol, $this->dave], new Round(2)),
        ]), []);
    })->throws(InvalidConfigurationException::class, 'play order');

    it('rejects pairings whose events carry a different round number', function (): void {
        StageState::start($this->participants)->withRoundPlayed(
            new RoundPairing(1, null, [new Event([$this->alice, $this->bob], new Round(3))]),
            []
        );
    })->throws(InvalidConfigurationException::class, 'different round');

    it('accumulates byes across rounds', function (): void {
        $state = StageState::start($this->participants)
            ->withRoundPlayed(new RoundPairing(1, null, [
                new Event([$this->alice, $this->bob], new Round(1)),
            ], [$this->carol]), [])
            ->withRoundPlayed(new RoundPairing(2, null, [
                new Event([$this->alice, $this->carol], new Round(2)),
            ], [$this->bob]), [])
            ->withRoundPlayed(new RoundPairing(3, null, [
                new Event([$this->bob, $this->carol], new Round(3)),
            ], [$this->carol]), []);

        expect($state->getByeIds())->toBe(['p3', 'p2', 'p3']);
        expect($state->getByeCounts())->toBe(['p3' => 2, 'p2' => 1]);
    });

    it('withdraws a participant while keeping their history', function (): void {
        $event = new Event([$this->alice, $this->bob], new Round(1));
        $result = new Result($event, $this->alice);
        $state = StageState::start($this->participants)
            ->withRoundPlayed(new RoundPairing(1, null, [$event]), [$result])
            ->withoutParticipant($this->bob);

        expect(array_map(fn (Participant $p) => $p->getId(), $state->getParticipants()))
            ->toBe(['p1', 'p3', 'p4']);
        expect($state->getResults())->toBe([$result]);
        expect($state->getPlayedEvents())->toBe([$event]);
    });

    it('round-trips through its array representation', function (): void {
        $event1 = new Event([$this->alice, $this->bob], new Round(1));
        $event2 = new Event([$this->carol, $this->dave], new Round(1));
        $state = StageState::start($this->participants)
            ->withRoundPlayed(
                new RoundPairing(1, 'opening round', [$event1, $event2]),
                [new Result($event1, $this->alice), new Result($event2, null, ['p3' => 1, 'p4' => 1])]
            )
            ->withoutParticipant($this->dave);

        $rebuilt = StageState::fromArray($state->toArray());

        expect(array_map(fn (Participant $p) => $p->getId(), $rebuilt->getParticipants()))
            ->toBe(['p1', 'p2', 'p3']);
        expect($rebuilt->getNextRoundNumber())->toBe(2);
        expect($rebuilt->getLastRound()?->getLabel())->toBe('opening round');
        expect($rebuilt->getPlayedEvents())->toHaveCount(2);

        $results = $rebuilt->getResults();
        expect($results)->toHaveCount(2);
        expect($results[0]->getWinner()?->getId())->toBe('p1');
        expect($results[1]->isDraw())->toBeTrue();
        expect($results[1]->getScoreFor($this->carol))->toBe(1);

        // Withdrawn Dave is rehydrated inside the recorded round, seed intact
        $rebuiltDave = $rebuilt->getPlayedEvents()[1]->getParticipants()[1];
        expect($rebuiltDave->getId())->toBe('p4');
        expect($rebuiltDave->getSeed())->toBe(4);

        // The rebuilt state serializes identically
        expect($rebuilt->toArray())->toBe($state->toArray());
    });

    it('round-trips through JSON with byes intact', function (): void {
        $event = new Event([$this->alice, $this->bob], new Round(1));
        $state = StageState::start([$this->alice, $this->bob, $this->carol])
            ->withRoundPlayed(
                new RoundPairing(1, null, [$event], [$this->carol]),
                [new Result($event, $this->alice)]
            );

        $rebuilt = StageState::fromJson($state->toJson());

        expect($rebuilt->getByeIds())->toBe(['p3']);
        expect($rebuilt->getByeCounts())->toBe(['p3' => 1]);
        expect($rebuilt->getLastRound()?->getRoundNumber())->toBe(1);
        expect($rebuilt->toJson())->toBe($state->toJson());
    });

    it('rejects malformed serialized data', function (): void {
        StageState::fromArray(['participants' => 'nope']);
    })->throws(InvalidArgumentException::class);

    // start() enforces unique IDs; rehydration must uphold the same
    // invariant instead of silently letting later entries win
    it('rejects duplicate participant IDs in serialized data', function (): void {
        StageState::fromArray([
            'participants' => [
                ['id' => 'p1', 'label' => 'Alice', 'seed' => null, 'metadata' => []],
                ['id' => 'p1', 'label' => 'Alice Clone', 'seed' => null, 'metadata' => []],
            ],
            'active' => ['p1'],
            'rounds' => [],
            'results' => [],
        ]);
    })->throws(InvalidArgumentException::class, 'twice');

    it('rejects an active reference to an unknown participant', function (): void {
        StageState::fromArray([
            'participants' => [['id' => 'p1', 'label' => 'Alice', 'seed' => null, 'metadata' => []]],
            'active' => ['p1', 'ghost'],
            'rounds' => [],
            'results' => [],
        ]);
    })->throws(InvalidArgumentException::class);
});
