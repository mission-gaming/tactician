<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;

describe('Event', function (): void {
    beforeEach(function (): void {
        $this->participant1 = new Participant('p1', 'Alice');
        $this->participant2 = new Participant('p2', 'Bob');
        $this->participant3 = new Participant('p3', 'Carol');
    });

    // Tests creating a basic event with only the required participants array,
    // verifying default values for optional fields (null round, empty metadata)
    it('creates an event with required fields', function (): void {
        $event = new Event([$this->participant1, $this->participant2]);

        expect($event->getParticipants())->toBe([$this->participant1, $this->participant2]);
        expect($event->getRound())->toBeNull();
        expect($event->getMetadata())->toBe([]);
        expect($event->getParticipantCount())->toBe(2);
    });

    // Tests creating an event with all optional fields populated (round, metadata),
    // ensuring the event properly stores and returns these additional data
    it('creates an event with optional fields', function (): void {
        $metadata = ['court' => 1, 'time' => '10:00'];
        $round = new Round(3);
        $event = new Event([$this->participant1, $this->participant2], $round, $metadata);

        expect($event->getParticipants())->toBe([$this->participant1, $this->participant2]);
        expect($event->getRound())->toBe($round);
        expect($event->getMetadata())->toBe($metadata);
    });

    // Tests the hasParticipant method to verify it correctly identifies whether
    // a specific participant is involved in the event or not
    it('checks if participant is in event', function (): void {
        $event = new Event([$this->participant1, $this->participant2]);

        expect($event->hasParticipant($this->participant1))->toBeTrue();
        expect($event->hasParticipant($this->participant2))->toBeTrue();
        expect($event->hasParticipant($this->participant3))->toBeFalse();
    });

    // Tests validation that prevents creating invalid events with only 1 participant,
    // since tournament events require at least 2 participants to compete
    it('throws exception with less than 2 participants', function (): void {
        expect(fn () => new Event([$this->participant1]))
            ->toThrow(InvalidArgumentException::class, 'An event must have at least 2 participants');
    });

    // Tests validation that prevents creating completely empty events with no participants,
    // ensuring events always have at least the minimum required participants
    it('throws exception with no participants', function (): void {
        expect(fn () => new Event([]))
            ->toThrow(InvalidArgumentException::class, 'An event must have at least 2 participants');
    });

    // Tests that events can support more than 2 participants (for team tournaments,
    // multi-way matches, etc.) and properly track all involved participants
    it('handles more than 2 participants', function (): void {
        $event = new Event([$this->participant1, $this->participant2, $this->participant3]);

        expect($event->getParticipantCount())->toBe(3);
        expect($event->hasParticipant($this->participant1))->toBeTrue();
        expect($event->hasParticipant($this->participant2))->toBeTrue();
        expect($event->hasParticipant($this->participant3))->toBeTrue();
    });

    // Tests the hasMetadata method to verify it correctly identifies whether
    // specific metadata keys exist on the event or not
    it('checks metadata existence', function (): void {
        $metadata = ['court' => 1];
        $event = new Event([$this->participant1, $this->participant2], null, $metadata);

        expect($event->hasMetadata('court'))->toBeTrue();
        expect($event->hasMetadata('time'))->toBeFalse();
    });

    // Tests retrieving metadata values with support for default values when
    // the requested metadata key doesn't exist on the event
    it('gets metadata values with defaults', function (): void {
        $metadata = ['court' => 1];
        $event = new Event([$this->participant1, $this->participant2], null, $metadata);

        expect($event->getMetadataValue('court'))->toBe(1);
        expect($event->getMetadataValue('time'))->toBeNull();
        expect($event->getMetadataValue('time', '12:00'))->toBe('12:00');
    });
});
