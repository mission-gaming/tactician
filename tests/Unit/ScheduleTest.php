<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;

describe('Schedule', function () {
    beforeEach(function () {
        $this->participant1 = new Participant('p1', 'Alice');
        $this->participant2 = new Participant('p2', 'Bob');
        $this->participant3 = new Participant('p3', 'Carol');
        
        $this->event1 = new Event([$this->participant1, $this->participant2], 1);
        $this->event2 = new Event([$this->participant2, $this->participant3], 2);
    });

    it('creates an empty schedule', function () {
        $schedule = new Schedule();

        expect($schedule->getEvents())->toBe([]);
        expect($schedule->getMetadata())->toBe([]);
        expect($schedule->isEmpty())->toBeTrue();
        expect($schedule->count())->toBe(0);
    });

    it('creates a schedule with events', function () {
        $events = [$this->event1, $this->event2];
        $schedule = new Schedule($events);

        expect($schedule->getEvents())->toBe($events);
        expect($schedule->isEmpty())->toBeFalse();
        expect($schedule->count())->toBe(2);
    });

    it('creates a schedule with metadata', function () {
        $metadata = ['algorithm' => 'round-robin', 'rounds' => 3];
        $schedule = new Schedule([], $metadata);

        expect($schedule->getMetadata())->toBe($metadata);
        expect($schedule->hasMetadata('algorithm'))->toBeTrue();
        expect($schedule->getMetadataValue('algorithm'))->toBe('round-robin');
    });

    it('adds events immutably', function () {
        $originalSchedule = new Schedule([$this->event1]);
        $newSchedule = $originalSchedule->addEvent($this->event2);

        expect($originalSchedule->count())->toBe(1);
        expect($newSchedule->count())->toBe(2);
        expect($newSchedule->getEvents())->toBe([$this->event1, $this->event2]);
    });

    it('implements Iterator interface', function () {
        $events = [$this->event1, $this->event2];
        $schedule = new Schedule($events);

        $iteratedEvents = [];
        foreach ($schedule as $key => $event) {
            $iteratedEvents[$key] = $event;
        }

        expect($iteratedEvents)->toBe([0 => $this->event1, 1 => $this->event2]);
    });

    it('implements Countable interface', function () {
        $schedule = new Schedule([$this->event1, $this->event2]);

        expect(count($schedule))->toBe(2);
    });

    it('gets events for specific round', function () {
        $event3 = new Event([$this->participant1, $this->participant3], 1);
        $schedule = new Schedule([$this->event1, $this->event2, $event3]);

        $round1Events = $schedule->getEventsForRound(1);
        $round2Events = $schedule->getEventsForRound(2);

        expect($round1Events)->toHaveCount(2);
        expect($round2Events)->toHaveCount(1);
        expect($round2Events[0])->toBe($this->event2);
    });

    it('gets maximum round number', function () {
        $event3 = new Event([$this->participant1, $this->participant3], 5);
        $schedule = new Schedule([$this->event1, $this->event2, $event3]);

        expect($schedule->getMaxRound())->toBe(5);
    });

    it('returns null for max round with empty schedule', function () {
        $schedule = new Schedule();

        expect($schedule->getMaxRound())->toBeNull();
    });

    it('returns null for max round with no round numbers', function () {
        $eventWithoutRound = new Event([$this->participant1, $this->participant2]);
        $schedule = new Schedule([$eventWithoutRound]);

        expect($schedule->getMaxRound())->toBeNull();
    });

    it('handles metadata operations', function () {
        $metadata = ['tournament' => 'Championship'];
        $schedule = new Schedule([], $metadata);

        expect($schedule->hasMetadata('tournament'))->toBeTrue();
        expect($schedule->hasMetadata('location'))->toBeFalse();
        expect($schedule->getMetadataValue('tournament'))->toBe('Championship');
        expect($schedule->getMetadataValue('location', 'Unknown'))->toBe('Unknown');
    });
});
