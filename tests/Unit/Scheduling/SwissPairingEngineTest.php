<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\NoValidPairingException;
use MissionGaming\Tactician\Scheduling\SchedulingContext;
use MissionGaming\Tactician\Scheduling\SwissPairingEngine;

describe('SwissPairingEngine', function (): void {
    beforeEach(function (): void {
        $this->alice = new Participant('p1', 'Alice');
        $this->bob = new Participant('p2', 'Bob');
        $this->carol = new Participant('p3', 'Carol');
        $this->dave = new Participant('p4', 'Dave');
        $this->eve = new Participant('p5', 'Eve');
        $this->participants = [$this->alice, $this->bob, $this->carol, $this->dave];
    });

    it('pairs round 1 adjacently in standings order', function (): void {
        $pairing = (new SwissPairingEngine())->pairNextRound($this->participants, []);

        expect($pairing->getRoundNumber())->toBe(1);
        expect($pairing->hasBye())->toBeFalse();

        $pairs = array_map(
            fn (Event $event) => array_map(fn (Participant $p) => $p->getId(), $event->getParticipants()),
            $pairing->getEvents()
        );

        // All on zero points, ordered by label: Alice-Bob, Carol-Dave.
        // Home counts are tied at zero, so the lower-placed participant is home.
        expect($pairs)->toBe([['p2', 'p1'], ['p4', 'p3']]);
    });

    it('pairs winners against winners in round 2', function (): void {
        $results = [
            new Result(new Event([$this->alice, $this->bob], new Round(1)), $this->alice),
            new Result(new Event([$this->carol, $this->dave], new Round(1)), $this->carol),
        ];

        $pairing = (new SwissPairingEngine())->pairNextRound($this->participants, $results);

        expect($pairing->getRoundNumber())->toBe(2);

        $pairKeys = array_map(function (Event $event) {
            $ids = array_map(fn (Participant $p) => $p->getId(), $event->getParticipants());
            sort($ids);

            return implode('-', $ids);
        }, $pairing->getEvents());
        sort($pairKeys);

        // Winners (Alice, Carol) meet; losers (Bob, Dave) meet
        expect($pairKeys)->toBe(['p1-p3', 'p2-p4']);
    });

    it('avoids repeat pairings by backtracking to lower-placed opponents', function (): void {
        $results = [
            new Result(new Event([$this->alice, $this->bob], new Round(1)), $this->alice),
            new Result(new Event([$this->carol, $this->dave], new Round(1)), $this->carol),
            new Result(new Event([$this->alice, $this->carol], new Round(2)), $this->alice),
            new Result(new Event([$this->bob, $this->dave], new Round(2)), $this->bob),
        ];

        $pairing = (new SwissPairingEngine())->pairNextRound($this->participants, $results);

        expect($pairing->getRoundNumber())->toBe(3);

        $pairKeys = array_map(function (Event $event) {
            $ids = array_map(fn (Participant $p) => $p->getId(), $event->getParticipants());
            sort($ids);

            return implode('-', $ids);
        }, $pairing->getEvents());
        sort($pairKeys);

        // Alice has played Bob and Carol; only Dave remains for her
        expect($pairKeys)->toBe(['p1-p4', 'p2-p3']);
    });

    it('balances home assignments and gives ties to the lower-placed participant', function (): void {
        $results = [
            new Result(new Event([$this->alice, $this->bob], new Round(1)), $this->alice),
            new Result(new Event([$this->carol, $this->dave], new Round(1)), $this->carol),
        ];

        $pairing = (new SwissPairingEngine())->pairNextRound($this->participants, $results);

        foreach ($pairing->getEvents() as $event) {
            $ids = array_map(fn (Participant $p) => $p->getId(), $event->getParticipants());
            if (in_array('p1', $ids, true)) {
                // Alice and Carol both had one home game: the lower-placed
                // participant (Carol) gets home
                expect($ids)->toBe(['p3', 'p1']);
            } else {
                // Bob and Dave both had none: lower-placed Dave gets home
                expect($ids)->toBe(['p4', 'p2']);
            }
        }
    });

    it('gives the bye to the lowest-placed participant without one', function (): void {
        $participants = [...$this->participants, $this->eve];

        $round1 = (new SwissPairingEngine())->pairNextRound($participants, []);
        expect($round1->hasBye())->toBeTrue();
        // All tied on zero points, label order: Eve is lowest placed
        expect($round1->getBye()?->getId())->toBe('p5');

        // With Eve's bye recorded, the next-lowest without a bye sits out
        $results = [
            new Result(new Event([$this->alice, $this->bob], new Round(1)), $this->alice),
            new Result(new Event([$this->carol, $this->dave], new Round(1)), $this->carol),
        ];
        $round2 = (new SwissPairingEngine())->pairNextRound($participants, $results, ['p5']);

        expect($round2->getBye()?->getId())->not->toBe('p5');
        expect($round2->getEvents())->toHaveCount(2);
    });

    it('throws when every pairing has already been played', function (): void {
        $results = [
            new Result(new Event([$this->alice, $this->bob], new Round(1)), $this->alice),
            new Result(new Event([$this->carol, $this->dave], new Round(1)), $this->carol),
            new Result(new Event([$this->alice, $this->carol], new Round(2)), $this->alice),
            new Result(new Event([$this->bob, $this->dave], new Round(2)), $this->bob),
            new Result(new Event([$this->dave, $this->alice], new Round(3)), $this->alice),
            new Result(new Event([$this->carol, $this->bob], new Round(3)), $this->bob),
        ];

        $engine = new SwissPairingEngine();

        expect(fn () => $engine->pairNextRound($this->participants, $results))
            ->toThrow(NoValidPairingException::class);
    });

    it('respects constraints when pairing', function (): void {
        // Forbid Alice vs Bob entirely
        $constraints = ConstraintSet::create()
            ->custom(
                function (Event $event, SchedulingContext $context): bool {
                    $ids = array_map(fn (Participant $p) => $p->getId(), $event->getParticipants());
                    sort($ids);

                    return $ids !== ['p1', 'p2'];
                },
                'No Alice vs Bob'
            )
            ->build();

        $pairing = (new SwissPairingEngine($constraints))->pairNextRound($this->participants, []);

        foreach ($pairing->getEvents() as $event) {
            $ids = array_map(fn (Participant $p) => $p->getId(), $event->getParticipants());
            sort($ids);
            expect($ids)->not->toBe(['p1', 'p2']);
        }
    });

    it('accepts an explicit round number', function (): void {
        $pairing = (new SwissPairingEngine())->pairNextRound($this->participants, [], [], 7);

        expect($pairing->getRoundNumber())->toBe(7);
        foreach ($pairing->getEvents() as $event) {
            expect($event->getRound()?->getNumber())->toBe(7);
        }
    });

    it('rejects fewer than two participants', function (): void {
        $engine = new SwissPairingEngine();

        expect(fn () => $engine->pairNextRound([$this->alice], []))
            ->toThrow(InvalidConfigurationException::class);
    });

    it('rejects duplicate participant IDs', function (): void {
        $engine = new SwissPairingEngine();
        $duplicate = new Participant('p1', 'Alice Clone');

        expect(fn () => $engine->pairNextRound([$this->alice, $duplicate], []))
            ->toThrow(InvalidConfigurationException::class);
    });

    it('runs a full five-round tournament for eight participants without repeats', function (): void {
        $participants = [];
        for ($i = 1; $i <= 8; ++$i) {
            $participants[] = new Participant("t{$i}", "Team {$i}");
        }

        $engine = new SwissPairingEngine();
        $results = [];
        $seenPairings = [];

        for ($round = 1; $round <= 5; ++$round) {
            $pairing = $engine->pairNextRound($participants, $results);
            expect($pairing->getEvents())->toHaveCount(4);

            foreach ($pairing->getEvents() as $event) {
                $ids = array_map(fn (Participant $p) => $p->getId(), $event->getParticipants());
                sort($ids);
                $key = implode('-', $ids);
                expect($seenPairings)->not->toContain($key);
                $seenPairings[] = $key;

                // Winner is always the lexicographically smaller ID for determinism
                $winner = $event->getParticipants()[0]->getId() < $event->getParticipants()[1]->getId()
                    ? $event->getParticipants()[0]
                    : $event->getParticipants()[1];
                $results[] = new Result($event, $winner);
            }
        }

        expect($seenPairings)->toHaveCount(20);
    });
});
