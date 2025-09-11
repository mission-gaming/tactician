<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use Random\Engine\Mt19937;
use Random\Randomizer;

describe('RoundRobinScheduler', function (): void {
    beforeEach(function (): void {
        $this->participants = [
            new Participant('p1', 'Alice'),
            new Participant('p2', 'Bob'),
            new Participant('p3', 'Carol'),
            new Participant('p4', 'Dave'),
        ];
    });

    // Tests creating a basic round-robin scheduler without any constraints,
    // ensuring the scheduler initializes properly for unrestricted tournaments
    it('creates scheduler with no constraints', function (): void {
        $scheduler = new RoundRobinScheduler();

        expect($scheduler)->toBeInstanceOf(RoundRobinScheduler::class);
    });

    // Tests creating a round-robin scheduler with constraint sets,
    // ensuring constraints are properly integrated into the scheduling process
    it('creates scheduler with constraints', function (): void {
        $constraints = ConstraintSet::create()->noRepeatPairings()->build();
        $scheduler = new RoundRobinScheduler($constraints);

        expect($scheduler)->toBeInstanceOf(RoundRobinScheduler::class);
    });

    // Tests validation that prevents scheduling tournaments with insufficient participants,
    // since round-robin requires at least 2 participants to create matches
    it('throws exception with less than 2 participants', function (): void {
        $scheduler = new RoundRobinScheduler();

        expect(fn () => $scheduler->schedule([new Participant('p1', 'Alice')]))
            ->toThrow(InvalidConfigurationException::class, 'Invalid scheduler configuration: Round-robin scheduling requires at least 2 participants');
    });

    // Tests the minimal case of 2 participants, which should create exactly
    // 1 match in 1 round, verifying basic round-robin logic works
    it('generates correct schedule for 2 participants', function (): void {
        $participants = [
            new Participant('p1', 'Alice'),
            new Participant('p2', 'Bob'),
        ];
        $scheduler = new RoundRobinScheduler();

        $schedule = $scheduler->schedule($participants);

        expect($schedule)->toBeInstanceOf(Schedule::class);
        expect($schedule->count())->toBe(1); // 1 match

        $maxRound = $schedule->getMaxRound();
        assert($maxRound !== null);
        expect($maxRound->getNumber())->toBe(1);

        $event = $schedule->getEvents()[0];
        expect($event->getParticipants())->toHaveCount(2);

        $round = $event->getRound();
        assert($round !== null);
        expect($round->getNumber())->toBe(1);
    });

    // Tests round-robin with even number of participants (4), which should create
    // 3 rounds with 2 matches each, totaling 6 matches with no byes needed
    it('generates correct schedule for 4 participants (even)', function (): void {
        $scheduler = new RoundRobinScheduler();
        $schedule = $scheduler->schedule($this->participants);

        // 4 participants = 3 rounds, 2 matches per round = 6 total matches
        expect($schedule->count())->toBe(6);

        $maxRound = $schedule->getMaxRound();
        assert($maxRound !== null);
        expect($maxRound->getNumber())->toBe(3);

        // Check that each round has 2 matches
        expect($schedule->getEventsForRound(new Round(1)))->toHaveCount(2);
        expect($schedule->getEventsForRound(new Round(2)))->toHaveCount(2);
        expect($schedule->getEventsForRound(new Round(3)))->toHaveCount(2);
    });

    // Tests round-robin with odd number of participants (3), which requires
    // bye handling where one participant sits out each round
    it('generates correct schedule for 3 participants (odd)', function (): void {
        $participants = [
            new Participant('p1', 'Alice'),
            new Participant('p2', 'Bob'),
            new Participant('p3', 'Carol'),
        ];
        $scheduler = new RoundRobinScheduler();
        $schedule = $scheduler->schedule($participants);

        // 3 participants = 3 rounds, 1 match per round (one bye each round) = 3 total matches
        expect($schedule->count())->toBe(3);

        $maxRound = $schedule->getMaxRound();
        assert($maxRound !== null);
        expect($maxRound->getNumber())->toBe(3);

        // Each round should have exactly 1 match (the other participant has a bye)
        expect($schedule->getEventsForRound(new Round(1)))->toHaveCount(1);
        expect($schedule->getEventsForRound(new Round(2)))->toHaveCount(1);
        expect($schedule->getEventsForRound(new Round(3)))->toHaveCount(1);
    });

    // Tests the core round-robin principle that every participant must play
    // every other participant exactly once, with no duplicate or missing pairings
    it('ensures each participant plays every other participant exactly once', function (): void {
        $scheduler = new RoundRobinScheduler();
        $schedule = $scheduler->schedule($this->participants);

        $pairings = [];
        foreach ($schedule as $event) {
            $participants = $event->getParticipants();
            $pair = [
                $participants[0]->getId(),
                $participants[1]->getId(),
            ];
            sort($pair); // Normalize pair order
            $pairings[] = implode('-', $pair);
        }

        // Should have exactly 6 unique pairings for 4 participants
        expect($pairings)->toHaveCount(6);
        expect($pairings)->toBe(array_unique($pairings)); // All pairings should be unique

        // Verify all expected pairings exist
        $expectedPairings = ['p1-p2', 'p1-p3', 'p1-p4', 'p2-p3', 'p2-p4', 'p3-p4'];
        sort($pairings);
        sort($expectedPairings);
        expect($pairings)->toBe($expectedPairings);
    });

    // Tests that generated schedules include descriptive metadata about the algorithm,
    // participant count, and round structure for tournament management
    it('includes correct metadata in schedule', function (): void {
        $scheduler = new RoundRobinScheduler();
        $schedule = $scheduler->schedule($this->participants);

        expect($schedule->hasMetadata('algorithm'))->toBeTrue();
        expect($schedule->getMetadataValue('algorithm'))->toBe('round-robin');
        expect($schedule->getMetadataValue('participant_count'))->toBe(4);
        expect($schedule->getMetadataValue('total_rounds'))->toBe(3);
    });

    // Tests that the scheduler properly applies the no-repeat-pairings constraint,
    // ensuring constraint validation is integrated into the scheduling algorithm
    it('respects no repeat pairings constraint', function (): void {
        $constraints = ConstraintSet::create()->noRepeatPairings()->build();
        $scheduler = new RoundRobinScheduler($constraints);
        $schedule = $scheduler->schedule($this->participants);

        // Verify no duplicate pairings
        $pairings = [];
        foreach ($schedule as $event) {
            $participants = $event->getParticipants();
            $pair = [
                $participants[0]->getId(),
                $participants[1]->getId(),
            ];
            sort($pair);
            $pairingKey = implode('-', $pair);

            expect($pairings)->not->toContain($pairingKey);
            $pairings[] = $pairingKey;
        }
    });

    // Tests that the scheduler produces identical results when given the same
    // random seed, ensuring reproducible tournament brackets for testing and fairness
    it('produces deterministic results with same seed', function (): void {
        $randomizer1 = new Randomizer(new Mt19937(42));
        $randomizer2 = new Randomizer(new Mt19937(42));

        $scheduler1 = new RoundRobinScheduler(null, $randomizer1);
        $scheduler2 = new RoundRobinScheduler(null, $randomizer2);

        $schedule1 = $scheduler1->schedule($this->participants);
        $schedule2 = $scheduler2->schedule($this->participants);

        // Both schedules should have the same events in the same order
        expect($schedule1->count())->toBe($schedule2->count());

        $events1 = $schedule1->getEvents();
        $events2 = $schedule2->getEvents();

        for ($i = 0; $i < count($events1); ++$i) {
            $participants1 = $events1[$i]->getParticipants();
            $participants2 = $events2[$i]->getParticipants();

            expect($participants1[0]->getId())->toBe($participants2[0]->getId());
            expect($participants1[1]->getId())->toBe($participants2[1]->getId());
        }
    });
});
