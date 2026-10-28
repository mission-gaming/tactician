<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Quality\RoleBalanceMetric;
use MissionGaming\Tactician\Quality\ScheduleScorer;

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

    // A schedule is traversed with foreach: it hands out an iterator of its
    // own for each loop (IteratorAggregate), keyed from 0 in schedule order
    it('is traversed with foreach, keyed from 0 in schedule order', function (): void {
        $events = [$this->event1, $this->event2];
        $schedule = new Schedule($events);

        $iteratedEvents = [];
        foreach ($schedule as $key => $event) {
            $iteratedEvents[$key] = $event;
        }

        expect($schedule)->toBeInstanceOf(IteratorAggregate::class)
            ->and($iteratedEvents)->toBe([0 => $this->event1, 1 => $this->event2])
            ->and(iterator_to_array($schedule->getIterator()))->toBe([0 => $this->event1, 1 => $this->event2]);
    });

    // Until 0.3.0 a schedule was its own iterator with one cursor, so a loop
    // over the schedule inside a loop over the same schedule moved the
    // cursor and ended the outer loop after its first event (issue #54).
    it('visits every pair of events in a loop nested inside a loop over the same schedule', function (): void {
        $event3 = new Event([$this->participant1, $this->participant3], $this->round1);
        $schedule = new Schedule([$this->event1, $this->event2, $event3]);

        $pairs = [];
        foreach ($schedule as $outer => $first) {
            foreach ($schedule as $inner => $second) {
                $pairs[] = [$outer, $inner];
                expect($first)->toBe($schedule->getEvents()[$outer])
                    ->and($second)->toBe($schedule->getEvents()[$inner]);
            }
        }

        expect($pairs)->toBe([[0, 0], [0, 1], [0, 2], [1, 0], [1, 1], [1, 2], [2, 0], [2, 1], [2, 2]]);
    });

    // The case issue #54 names: a quality metric that loops over the
    // schedule (RoleBalanceMetric does) measured inside a loop over the
    // same schedule. The outer loop visits every event, and every
    // measurement reads the whole schedule.
    it('lets a metric that loops over the schedule measure it inside a loop over it', function (): void {
        $event3 = new Event([$this->participant3, $this->participant1], $this->round2);
        $schedule = new Schedule([$this->event1, $this->event2, $event3]);
        $scorer = ScheduleScorer::of(new RoleBalanceMetric());
        $alone = $scorer->score($schedule);

        $visited = [];
        $scores = [];
        foreach ($schedule as $index => $event) {
            $visited[] = $index;
            $scores[] = $scorer->score($schedule);
        }

        expect($visited)->toBe([0, 1, 2])
            ->and($scores)->toBe([$alone, $alone, $alone]);
    });

    it('hands out an iterator of its own on every call, which a loop elsewhere does not move', function (): void {
        $schedule = new Schedule([$this->event1, $this->event2]);

        $first = $schedule->getIterator();
        $first->next();
        foreach ($schedule as $event) {
            // A whole loop over the same schedule
        }
        $second = $schedule->getIterator();

        expect($first)->not->toBe($second)
            ->and($first->key())->toBe(1)
            ->and($first->current())->toBe($this->event2)
            ->and($second->key())->toBe(0)
            ->and($second->current())->toBe($this->event1);
    });

    it('is a final readonly class, and no iterator of its own', function (): void {
        $class = new ReflectionClass(Schedule::class);

        expect($class->isFinal())->toBeTrue()
            ->and($class->isReadOnly())->toBeTrue()
            ->and($class->implementsInterface(Iterator::class))->toBeFalse();
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

    // Tests grouping events by round number - the natural shape for consumers
    // that process a schedule round by round (dates per round, matchday views)
    it('groups events by round in ascending order', function (): void {
        $round5 = new Round(5);
        $event3 = new Event([$this->participant1, $this->participant3], $this->round1);
        $event4 = new Event([$this->participant2, $this->participant3], $round5);
        // Deliberately out of round order
        $schedule = new Schedule([$event4, $this->event2, $this->event1, $event3]);

        $byRound = $schedule->getEventsByRound();

        expect(array_keys($byRound))->toBe([1, 2, 5]);
        expect($byRound[1])->toBe([$this->event1, $event3]);
        expect($byRound[2])->toBe([$this->event2]);
        expect($byRound[5])->toBe([$event4]);
    });

    it('excludes round-less events from the round grouping', function (): void {
        $roundless = new Event([$this->participant1, $this->participant3]);
        $schedule = new Schedule([$this->event1, $roundless]);

        $byRound = $schedule->getEventsByRound();

        expect($byRound)->toBe([1 => [$this->event1]]);
        expect($schedule->getEvents())->toHaveCount(2);
    });

    it('returns an empty round grouping for an empty schedule', function (): void {
        expect(new Schedule()->getEventsByRound())->toBe([]);
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
