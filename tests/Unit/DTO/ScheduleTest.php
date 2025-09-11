<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\DTO\Schedule;

describe('Schedule', function (): void {
    beforeEach(function (): void {
        $this->participant1 = new Participant('p1', 'Alice');
        $this->participant2 = new Participant('p2', 'Bob');
        $this->participant3 = new Participant('p3', 'Carol');

        $this->round1 = new Round(1);
        $this->round2 = new Round(2);
        $this->event1 = new Event([$this->participant1, $this->participant2], $this->round1);
        $this->event2 = new Event([$this->participant2, $this->participant3], $this->round2);
    });

    // Tests creating a schedule with no events, verifying it reports as empty
    // with zero count and empty arrays for events and metadata
    it('creates an empty schedule', function (): void {
        $schedule = new Schedule();

        expect($schedule->getEvents())->toBe([]);
        expect($schedule->getMetadata())->toBe([]);
        expect($schedule->isEmpty())->toBeTrue();
        expect($schedule->count())->toBe(0);
    });

    // Tests creating a schedule with a list of events, ensuring the events
    // are properly stored and the schedule reports correct count and non-empty status
    it('creates a schedule with events', function (): void {
        $events = [$this->event1, $this->event2];
        $schedule = new Schedule($events);

        expect($schedule->getEvents())->toBe($events);
        expect($schedule->isEmpty())->toBeFalse();
        expect($schedule->count())->toBe(2);
    });

    // Tests creating a schedule with tournament metadata (algorithm type, round count),
    // useful for storing configuration and organizational information
    it('creates a schedule with metadata', function (): void {
        $metadata = ['algorithm' => 'round-robin', 'rounds' => 3];
        $schedule = new Schedule([], $metadata);

        expect($schedule->getMetadata())->toBe($metadata);
        expect($schedule->hasMetadata('algorithm'))->toBeTrue();
        expect($schedule->getMetadataValue('algorithm'))->toBe('round-robin');
    });

    // Tests that adding events to a schedule returns a new schedule instance
    // without modifying the original, ensuring immutable data structures
    it('adds events immutably', function (): void {
        $originalSchedule = new Schedule([$this->event1]);
        $newSchedule = $originalSchedule->addEvent($this->event2);

        expect($originalSchedule->count())->toBe(1);
        expect($newSchedule->count())->toBe(2);
        expect($newSchedule->getEvents())->toBe([$this->event1, $this->event2]);
    });

    // Tests that schedules can be used in foreach loops by implementing
    // the Iterator interface, allowing easy traversal of events
    it('implements Iterator interface', function (): void {
        $events = [$this->event1, $this->event2];
        $schedule = new Schedule($events);

        $iteratedEvents = [];
        foreach ($schedule as $key => $event) {
            $iteratedEvents[$key] = $event;
        }

        expect($iteratedEvents)->toBe([0 => $this->event1, 1 => $this->event2]);
    });

    // Tests that schedules work with PHP's count() function by implementing
    // the Countable interface for convenient event counting
    it('implements Countable interface', function (): void {
        $schedule = new Schedule([$this->event1, $this->event2]);

        expect(count($schedule))->toBe(2);
    });

    // Tests filtering events by round number, useful for displaying tournament
    // brackets or managing events within specific tournament rounds
    it('gets events for specific round', function (): void {
        $event3 = new Event([$this->participant1, $this->participant3], $this->round1);
        $schedule = new Schedule([$this->event1, $this->event2, $event3]);

        $round1Events = $schedule->getEventsForRound($this->round1);
        $round2Events = $schedule->getEventsForRound($this->round2);

        expect($round1Events)->toHaveCount(2);
        expect($round2Events)->toHaveCount(1);
        expect($round2Events[0])->toBe($this->event2);
    });

    // Tests finding the highest round number in the schedule, useful for
    // determining tournament length and current tournament progress
    it('gets maximum round number', function (): void {
        $round5 = new Round(5);
        $event3 = new Event([$this->participant1, $this->participant3], $round5);
        $schedule = new Schedule([$this->event1, $this->event2, $event3]);

        expect($schedule->getMaxRound())->toBe($round5);
    });

    // Tests that an empty schedule properly returns null for maximum round
    // rather than throwing errors, providing safe handling of edge cases
    it('returns null for max round with empty schedule', function (): void {
        $schedule = new Schedule();

        expect($schedule->getMaxRound())->toBeNull();
    });

    // Tests that schedules with events but no round assignments return null
    // for max round, handling cases where events aren't organized into rounds
    it('returns null for max round with no round numbers', function (): void {
        $eventWithoutRound = new Event([$this->participant1, $this->participant2]);
        $schedule = new Schedule([$eventWithoutRound]);

        expect($schedule->getMaxRound())->toBeNull();
    });

    // Tests schedule metadata operations (checking existence, getting values with defaults)
    // for storing tournament configuration and organizational information
    it('handles metadata operations', function (): void {
        $metadata = ['tournament' => 'Championship'];
        $schedule = new Schedule([], $metadata);

        expect($schedule->hasMetadata('tournament'))->toBeTrue();
        expect($schedule->hasMetadata('location'))->toBeFalse();
        expect($schedule->getMetadataValue('tournament'))->toBe('Championship');
        expect($schedule->getMetadataValue('location', 'Unknown'))->toBe('Unknown');
    });
});
