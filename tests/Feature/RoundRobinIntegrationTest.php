<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

describe('Round Robin Integration', function (): void {
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
