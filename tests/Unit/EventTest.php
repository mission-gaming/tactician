<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;

describe('Event', function () {
    beforeEach(function () {
        $this->participant1 = new Participant('p1', 'Alice');
        $this->participant2 = new Participant('p2', 'Bob');
        $this->participant3 = new Participant('p3', 'Carol');
    });

    it('creates an event with required fields', function () {
        $event = new Event([$this->participant1, $this->participant2]);

        expect($event->getParticipants())->toBe([$this->participant1, $this->participant2]);
        expect($event->getRound())->toBeNull();
        expect($event->getMetadata())->toBe([]);
        expect($event->getParticipantCount())->toBe(2);
    });

    it('creates an event with optional fields', function () {
        $metadata = ['court' => 1, 'time' => '10:00'];
        $event = new Event([$this->participant1, $this->participant2], 3, $metadata);

        expect($event->getParticipants())->toBe([$this->participant1, $this->participant2]);
        expect($event->getRound())->toBe(3);
        expect($event->getMetadata())->toBe($metadata);
    });

    it('checks if participant is in event', function () {
        $event = new Event([$this->participant1, $this->participant2]);

        expect($event->hasParticipant($this->participant1))->toBeTrue();
        expect($event->hasParticipant($this->participant2))->toBeTrue();
        expect($event->hasParticipant($this->participant3))->toBeFalse();
    });

    it('throws exception with less than 2 participants', function () {
        expect(fn () => new Event([$this->participant1]))
            ->toThrow(InvalidArgumentException::class, 'An event must have at least 2 participants');
    });

    it('throws exception with no participants', function () {
        expect(fn () => new Event([]))
            ->toThrow(InvalidArgumentException::class, 'An event must have at least 2 participants');
    });

    it('handles more than 2 participants', function () {
        $event = new Event([$this->participant1, $this->participant2, $this->participant3]);

        expect($event->getParticipantCount())->toBe(3);
        expect($event->hasParticipant($this->participant1))->toBeTrue();
        expect($event->hasParticipant($this->participant2))->toBeTrue();
        expect($event->hasParticipant($this->participant3))->toBeTrue();
    });

    it('checks metadata existence', function () {
        $metadata = ['court' => 1];
        $event = new Event([$this->participant1, $this->participant2], null, $metadata);

        expect($event->hasMetadata('court'))->toBeTrue();
        expect($event->hasMetadata('time'))->toBeFalse();
    });

    it('gets metadata values with defaults', function () {
        $metadata = ['court' => 1];
        $event = new Event([$this->participant1, $this->participant2], null, $metadata);

        expect($event->getMetadataValue('court'))->toBe(1);
        expect($event->getMetadataValue('time'))->toBeNull();
        expect($event->getMetadataValue('time', '12:00'))->toBe('12:00');
    });
});
