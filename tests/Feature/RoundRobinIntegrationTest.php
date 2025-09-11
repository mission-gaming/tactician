<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

describe('Round Robin Integration', function (): void {
    // Tests that the round-robin algorithm generates the correct number of matches (6)
    // and rounds (3) for 4 participants, with each participant playing exactly 3 matches
    it('generates complete round robin schedule for 4 participants', function (): void {
        // Given: 4 participants
        $participants = [
            new Participant('alice', 'Alice'),
            new Participant('bob', 'Bob'),
            new Participant('carol', 'Carol'),
            new Participant('dave', 'Dave'),
        ];

        // When: Generating round robin schedule
        $scheduler = new RoundRobinScheduler();
        $schedule = $scheduler->schedule($participants);

        // Then: Should generate correct number of matches
        expect($schedule->count())->toBe(6); // C(4,2) = 6 matches

        $maxRound = $schedule->getMaxRound();
        assert($maxRound !== null);
        expect($maxRound->getNumber())->toBe(3); // 4-1 = 3 rounds

        // And: Each participant should play exactly 3 matches
        $participantMatchCounts = [];
        foreach ($schedule as $event) {
            foreach ($event->getParticipants() as $participant) {
                $id = $participant->getId();
                $participantMatchCounts[$id] = ($participantMatchCounts[$id] ?? 0) + 1;
            }
        }

        foreach ($participantMatchCounts as $count) {
            expect($count)->toBe(3);
        }
    });

    // Tests that the scheduler properly applies the no-repeat-pairings constraint,
    // ensuring no two teams play each other more than once in the tournament
    it('respects constraints during scheduling', function (): void {
        // Given: Participants and no-repeat-pairings constraint
        $participants = [
            new Participant('p1', 'Player 1'),
            new Participant('p2', 'Player 2'),
            new Participant('p3', 'Player 3'),
            new Participant('p4', 'Player 4'),
        ];

        $constraints = ConstraintSet::create()
            ->noRepeatPairings()
            ->build();

        // When: Generating schedule with constraints
        $scheduler = new RoundRobinScheduler($constraints);
        $schedule = $scheduler->schedule($participants);

        // Then: No pairings should repeat
        $pairingSeen = [];
        foreach ($schedule as $event) {
            $eventParticipants = $event->getParticipants();
            $pair = [
                $eventParticipants[0]->getId(),
                $eventParticipants[1]->getId(),
            ];
            sort($pair);
            $pairKey = implode('-', $pair);

            expect($pairingSeen)->not->toContain($pairKey);
            $pairingSeen[] = $pairKey;
        }
    });

    // Tests round-robin scheduling with 5 participants (odd number), which requires
    // automatic bye handling where one team sits out each round, creating 5 total rounds
    it('handles odd number of participants with byes', function (): void {
        // Given: 5 participants (odd number)
        $participants = [
            new Participant('p1', 'Player 1'),
            new Participant('p2', 'Player 2'),
            new Participant('p3', 'Player 3'),
            new Participant('p4', 'Player 4'),
            new Participant('p5', 'Player 5'),
        ];

        // When: Generating schedule
        $scheduler = new RoundRobinScheduler();
        $schedule = $scheduler->schedule($participants);

        // Then: Should generate correct schedule accounting for byes
        expect($schedule->count())->toBe(10); // C(5,2) = 10 matches

        $maxRound = $schedule->getMaxRound();
        assert($maxRound !== null);
        expect($maxRound->getNumber())->toBe(5); // 5 rounds with byes

        // And: Each participant should play exactly 4 matches
        $participantMatchCounts = [];
        foreach ($schedule as $event) {
            foreach ($event->getParticipants() as $participant) {
                $id = $participant->getId();
                $participantMatchCounts[$id] = ($participantMatchCounts[$id] ?? 0) + 1;
            }
        }

        foreach ($participantMatchCounts as $count) {
            expect($count)->toBe(4);
        }
    });

    // Tests that the generated schedule implements proper PHP interfaces (Iterator, Countable)
    // allowing it to be used in foreach loops and with count() function
    it('produces schedule that can be iterated and counted', function (): void {
        // Given: Participants
        $participants = [
            new Participant('p1', 'Player 1'),
            new Participant('p2', 'Player 2'),
            new Participant('p3', 'Player 3'),
        ];

        // When: Generating schedule
        $scheduler = new RoundRobinScheduler();
        $schedule = $scheduler->schedule($participants);

        // Then: Schedule should be iterable and countable
        $eventCount = 0;
        foreach ($schedule as $event) {
            expect($event->getParticipantCount())->toBe(2);
            ++$eventCount;
        }

        expect($eventCount)->toBe(count($schedule));
        expect($schedule->count())->toBe(3); // C(3,2) = 3 matches
    });

    // Tests that the schedule includes descriptive metadata like algorithm type,
    // participant count, and round information for tournament management purposes
    it('includes useful metadata in generated schedule', function (): void {
        // Given: Participants
        $participants = [
            new Participant('p1', 'Player 1'),
            new Participant('p2', 'Player 2'),
            new Participant('p3', 'Player 3'),
            new Participant('p4', 'Player 4'),
        ];

        // When: Generating schedule
        $scheduler = new RoundRobinScheduler();
        $schedule = $scheduler->schedule($participants);

        // Then: Schedule should contain useful metadata
        expect($schedule->hasMetadata('algorithm'))->toBeTrue();
        expect($schedule->getMetadataValue('algorithm'))->toBe('round-robin');
        expect($schedule->getMetadataValue('participant_count'))->toBe(4);
        expect($schedule->getMetadataValue('total_rounds'))->toBe(3);
    });
});
