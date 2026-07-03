<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\DTO\Round;

describe('Result', function (): void {
    beforeEach(function (): void {
        $this->alice = new Participant('p1', 'Alice');
        $this->bob = new Participant('p2', 'Bob');
        $this->event = new Event([$this->alice, $this->bob], new Round(1));
        $this->registry = ['p1' => $this->alice, 'p2' => $this->bob];
    });

    it('records winners, draws, scores, and metadata', function (): void {
        $result = new Result($this->event, $this->alice, ['p1' => 3, 'p2' => 1], ['note' => 'derby']);

        expect($result->getEvent())->toBe($this->event);
        expect($result->getWinner())->toBe($this->alice);
        expect($result->isDraw())->toBeFalse();
        expect($result->isWinFor($this->alice))->toBeTrue();
        expect($result->getScoreFor($this->alice))->toBe(3);
        expect($result->getScores())->toBe(['p1' => 3, 'p2' => 1]);
        expect($result->getMetadata())->toBe(['note' => 'derby']);
        expect($result->hasMetadata('note'))->toBeTrue();
        expect($result->getMetadataValue('note'))->toBe('derby');
        expect($result->getMetadataValue('missing', 'fallback'))->toBe('fallback');

        expect((new Result($this->event))->isDraw())->toBeTrue();
    });

    it('rejects winners and scores outside the event', function (): void {
        $outsider = new Participant('x1', 'Outsider');

        expect(fn () => new Result($this->event, $outsider))
            ->toThrow(InvalidArgumentException::class, 'Winner');
        expect(fn () => new Result($this->event, null, ['x1' => 2]))
            ->toThrow(InvalidArgumentException::class, 'Score references');
    });

    it('round-trips through its array representation', function (): void {
        $result = new Result($this->event, $this->alice, ['p1' => 2, 'p2' => 2], ['tie_winner' => 'p1']);

        $rebuilt = Result::fromArray($result->toArray(), $this->registry);

        expect($rebuilt->getWinner()?->getId())->toBe('p1');
        expect($rebuilt->getScores())->toBe(['p1' => 2, 'p2' => 2]);
        expect($rebuilt->getMetadataValue('tie_winner'))->toBe('p1');
        expect($rebuilt->toArray())->toBe($result->toArray());

        $draw = Result::fromArray((new Result($this->event))->toArray(), $this->registry);
        expect($draw->isDraw())->toBeTrue();
    });

    it('rejects malformed serialized data', function (): void {
        $valid = (new Result($this->event, $this->alice))->toArray();

        expect(fn () => Result::fromArray(['winner' => 'p1'], $this->registry))
            ->toThrow(InvalidArgumentException::class, 'event');
        expect(fn () => Result::fromArray([...$valid, 'winner' => 42], $this->registry))
            ->toThrow(InvalidArgumentException::class, 'winner');
        expect(fn () => Result::fromArray([...$valid, 'winner' => 'ghost'], $this->registry))
            ->toThrow(InvalidArgumentException::class, 'unknown winner');
        expect(fn () => Result::fromArray([...$valid, 'scores' => 'nope'], $this->registry))
            ->toThrow(InvalidArgumentException::class, 'scores');
        expect(fn () => Result::fromArray([...$valid, 'scores' => ['p1' => 'three']], $this->registry))
            ->toThrow(InvalidArgumentException::class, 'numeric');
        expect(fn () => Result::fromArray([...$valid, 'metadata' => 'nope'], $this->registry))
            ->toThrow(InvalidArgumentException::class, 'metadata');
    });
});
