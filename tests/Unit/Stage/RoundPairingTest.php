<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Stage\RoundPairing;

describe('RoundPairing', function (): void {
    beforeEach(function (): void {
        $this->alice = new Participant('p1', 'Alice');
        $this->bob = new Participant('p2', 'Bob');
        $this->carol = new Participant('p3', 'Carol');
        $this->registry = ['p1' => $this->alice, 'p2' => $this->bob, 'p3' => $this->carol];
    });

    it('exposes its round number, label, events, and byes', function (): void {
        $event = new Event([$this->alice, $this->bob], new Round(2));
        $pairing = new RoundPairing(2, 'semifinal', [$event], [$this->carol]);

        expect($pairing->getRoundNumber())->toBe(2);
        expect($pairing->getLabel())->toBe('semifinal');
        expect($pairing->getEvents())->toBe([$event]);
        expect($pairing->getByes())->toBe([$this->carol]);
        expect($pairing->hasByes())->toBeTrue();
    });

    it('round-trips through its array representation', function (): void {
        $event = new Event([$this->alice, $this->bob], new Round(1));
        $pairing = new RoundPairing(1, null, [$event], [$this->carol]);

        $rebuilt = RoundPairing::fromArray($pairing->toArray(), $this->registry);

        expect($rebuilt->getRoundNumber())->toBe(1);
        expect($rebuilt->getLabel())->toBeNull();
        expect($rebuilt->getEvents()[0]->getParticipants())->toBe([$this->alice, $this->bob]);
        expect($rebuilt->getByes())->toBe([$this->carol]);
        expect($rebuilt->toArray())->toBe($pairing->toArray());
    });

    it('rejects a missing round number', function (): void {
        RoundPairing::fromArray(['label' => null, 'events' => [], 'byes' => []], $this->registry);
    })->throws(InvalidArgumentException::class, 'round number');

    it('rejects a non-string label', function (): void {
        RoundPairing::fromArray(['round' => 1, 'label' => 42, 'events' => [], 'byes' => []], $this->registry);
    })->throws(InvalidArgumentException::class, 'label');

    it('rejects malformed events data', function (): void {
        RoundPairing::fromArray(['round' => 1, 'label' => null, 'events' => 'nope', 'byes' => []], $this->registry);
    })->throws(InvalidArgumentException::class, 'events');

    it('rejects a non-array event entry', function (): void {
        RoundPairing::fromArray(['round' => 1, 'label' => null, 'events' => ['nope'], 'byes' => []], $this->registry);
    })->throws(InvalidArgumentException::class, 'event');

    it('rejects malformed byes data', function (): void {
        RoundPairing::fromArray(['round' => 1, 'label' => null, 'events' => [], 'byes' => 'nope'], $this->registry);
    })->throws(InvalidArgumentException::class, 'byes');

    it('rejects a bye referencing an unknown participant', function (): void {
        RoundPairing::fromArray(['round' => 1, 'label' => null, 'events' => [], 'byes' => ['ghost']], $this->registry);
    })->throws(InvalidArgumentException::class, 'unknown bye participant');
});
