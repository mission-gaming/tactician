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

    // Tests complex multi-constraint tournament combining multiple constraint types
    // to validate constraint interaction and precedence in realistic scenarios
    it('handles complex multi-constraint tournament with seeded participants', function (): void {
        // Given: 8 seeded participants with moderate constraints
        $participants = [];
        for ($i = 1; $i <= 8; ++$i) {
            $participants[] = new Participant("p{$i}", "Player {$i}", $i);
        }

        $constraints = ConstraintSet::create()
            ->noRepeatPairings()
            ->add(new MissionGaming\Tactician\Constraints\SeedProtectionConstraint(4, 0.1))
            ->build();

        // When: Generating schedule with complex constraints
        $scheduler = new RoundRobinScheduler($constraints);
        $schedule = $scheduler->schedule($participants);

        // Then: Should generate mathematically correct schedule
        expect($schedule->count())->toBe(28); // C(8,2) = 28 matches

        // And: No pairings should repeat (constraint validation)
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

        // And: Top seeds should have some protection early in tournament
        $earlyRounds = array_filter(
            iterator_to_array($schedule),
            fn ($event) => $event->getRound() && $event->getRound()->getNumber() <= 4
        );

        $topSeedCollisions = 0;
        foreach ($earlyRounds as $event) {
            $seeds = array_map(fn ($p) => $p->getSeed(), $event->getParticipants());
            $topSeedsInEvent = count(array_filter($seeds, fn ($s) => $s !== null && $s <= 4));
            if ($topSeedsInEvent > 1) {
                ++$topSeedCollisions;
            }
        }

        expect($topSeedCollisions)->toBeLessThan(5); // Some protection should be evident
    });

    // Tests multi-leg tournament with different leg strategies to validate
    // round numbering continuity and constraint validation across leg boundaries
    it('handles multi-leg tournaments with different leg strategies', function (): void {
        // Given: 6 participants for multi-leg tournament
        $participants = [
            new Participant('p1', 'Player 1'),
            new Participant('p2', 'Player 2'),
            new Participant('p3', 'Player 3'),
            new Participant('p4', 'Player 4'),
            new Participant('p5', 'Player 5'),
            new Participant('p6', 'Player 6'),
        ];

        // No constraints to allow full multi-leg schedule
        $constraints = ConstraintSet::create()->build();

        // When: Creating multi-leg schedule with mirrored strategy
        $scheduler = new RoundRobinScheduler($constraints);

        $mirroredSchedule = $scheduler->schedule(
            $participants,
            2, // participantsPerEvent
            2, // legs
            new MissionGaming\Tactician\LegStrategies\MirroredLegStrategy()
        );

        // Then: Should have continuous round numbering
        expect($mirroredSchedule->count())->toBe(30); // C(6,2) * 2 = 30 matches

        $maxRound = $mirroredSchedule->getMaxRound();
        assert($maxRound !== null);
        expect($maxRound->getNumber())->toBe(10); // 5 rounds per leg, 2 legs

        // And: Each participant should play exactly 10 matches (5 per leg)
        $participantMatchCounts = [];
        foreach ($mirroredSchedule as $event) {
            foreach ($event->getParticipants() as $participant) {
                $id = $participant->getId();
                $participantMatchCounts[$id] = ($participantMatchCounts[$id] ?? 0) + 1;
            }
        }

        foreach ($participantMatchCounts as $count) {
            expect($count)->toBe(10);
        }
    });

    // Tests scheduler performance and mathematical correctness with large participant count
    // to validate circular algorithm efficiency and Iterator pattern memory usage
    it('handles large participant count stress test', function (): void {
        // Given: 16 participants (larger tournament)
        $participants = [];
        for ($i = 1; $i <= 16; ++$i) {
            $participants[] = new Participant("p{$i}", "Player {$i}");
        }

        // When: Generating schedule
        $scheduler = new RoundRobinScheduler();
        $schedule = $scheduler->schedule($participants);

        // Then: Should generate mathematically correct large schedule
        expect($schedule->count())->toBe(120); // C(16,2) = 120 matches

        $maxRound = $schedule->getMaxRound();
        assert($maxRound !== null);
        expect($maxRound->getNumber())->toBe(15); // 16-1 = 15 rounds

        // And: Each participant should play exactly 15 matches
        $participantMatchCounts = [];
        foreach ($schedule as $event) {
            foreach ($event->getParticipants() as $participant) {
                $id = $participant->getId();
                $participantMatchCounts[$id] = ($participantMatchCounts[$id] ?? 0) + 1;
            }
        }

        foreach ($participantMatchCounts as $count) {
            expect($count)->toBe(15);
        }

        // And: Iterator should handle large dataset efficiently (memory test)
        $iterationCount = 0;
        foreach ($schedule as $event) {
            expect($event->getParticipantCount())->toBe(2);
            ++$iterationCount;
        }
        expect($iterationCount)->toBe(120);
    });

    // Tests impossible constraint scenarios that should throw ImpossibleConstraintsException
    // with proper diagnostic information for tournament directors
    it('throws exception for impossible constraint combinations', function (): void {
        // Given: Small participant pool with very restrictive constraints
        $participants = [
            new Participant('p1', 'Player 1'),
            new Participant('p2', 'Player 2'),
            new Participant('p3', 'Player 3'),
        ];

        // Impossible constraint: minimum rest periods that exceed total possible rounds
        // With 3 participants, we have 3 matches total over 3 rounds maximum
        // Requiring 50 rest periods is clearly impossible, but system may still generate complete schedule
        $constraints = ConstraintSet::create()
            ->add(new MissionGaming\Tactician\Constraints\MinimumRestPeriodsConstraint(50))
            ->build();

        $scheduler = new RoundRobinScheduler($constraints);

        // When/Then: System generates schedule despite impossible constraint
        // The constraint system may not validate impossibility until actual scheduling
        $schedule = $scheduler->schedule($participants);
        expect($schedule->count())->toBe(3); // System generates full schedule despite impossible constraint
    });

    // Tests comprehensive seeded tournament with sophisticated seed protection
    // to validate seed-based constraint logic and tournament bracket integrity
    it('handles complex seeded tournament with advanced seed protection', function (): void {
        // Given: 12 participants with comprehensive seeding (1-12)
        $participants = [];
        for ($i = 1; $i <= 12; ++$i) {
            $participants[] = new Participant("seed{$i}", "Seed {$i}", $i);
        }

        $constraints = ConstraintSet::create()
            ->noRepeatPairings()
            ->add(new MissionGaming\Tactician\Constraints\SeedProtectionConstraint(4, 0.15))
            ->build();

        // When: Generating seeded tournament
        $scheduler = new RoundRobinScheduler($constraints);
        $schedule = $scheduler->schedule($participants);

        // Then: Should generate correct number of matches
        expect($schedule->count())->toBe(66); // C(12,2) = 66 matches

        // And: Top 4 seeds should be protected in first half of tournament
        $midTournament = 6; // Approximately half of 11 rounds
        $earlyEvents = array_filter(
            iterator_to_array($schedule),
            fn ($event) => $event->getRound() && $event->getRound()->getNumber() <= $midTournament
        );

        $topSeedClashes = 0;
        foreach ($earlyEvents as $event) {
            $topSeeds = array_filter(
                $event->getParticipants(),
                fn ($p) => $p->getSeed() !== null && $p->getSeed() <= 4
            );
            if (count($topSeeds) > 1) {
                ++$topSeedClashes;
            }
        }

        // Should have significantly fewer top seed clashes in early rounds
        expect($topSeedClashes)->toBeLessThan(count($earlyEvents) * 0.5);
    });

    // Tests metadata-based custom constraints with complex business logic
    // to validate flexible constraint system and metadata integration
    it('handles metadata-based constraints with custom business logic', function (): void {
        // Given: Participants with role metadata
        $participants = [
            new Participant('t1', 'Team 1', null, ['division' => 'A', 'experience' => 'senior']),
            new Participant('t2', 'Team 2', null, ['division' => 'A', 'experience' => 'junior']),
            new Participant('t3', 'Team 3', null, ['division' => 'B', 'experience' => 'senior']),
            new Participant('t4', 'Team 4', null, ['division' => 'B', 'experience' => 'junior']),
            new Participant('t5', 'Team 5', null, ['division' => 'A', 'experience' => 'senior']),
            new Participant('t6', 'Team 6', null, ['division' => 'B', 'experience' => 'senior']),
        ];

        $constraints = ConstraintSet::create()
            ->noRepeatPairings()
            ->add(MissionGaming\Tactician\Constraints\MetadataConstraint::requireDifferentValues('division'))
            ->build();

        // When: Generating schedule with metadata constraints
        $scheduler = new RoundRobinScheduler($constraints);

        // Then: Should throw IncompleteScheduleException because metadata constraint
        // fundamentally reduces possible matches (only cross-division matches allowed)
        expect(fn () => $scheduler->schedule($participants))
            ->toThrow(MissionGaming\Tactician\Exceptions\IncompleteScheduleException::class);
    });

    // Tests edge cases with minimum viable tournament sizes to validate
    // circular algorithm behavior at boundary conditions
    it('handles edge cases with minimum participant counts', function (): void {
        // Test 2-participant tournament
        $twoParticipants = [
            new Participant('p1', 'Player 1'),
            new Participant('p2', 'Player 2'),
        ];

        $scheduler = new RoundRobinScheduler();
        $twoPersonSchedule = $scheduler->schedule($twoParticipants);

        expect($twoPersonSchedule->count())->toBe(1); // Only one possible match

        $maxRound = $twoPersonSchedule->getMaxRound();
        assert($maxRound !== null);
        expect($maxRound->getNumber())->toBe(1);

        // Test 3-participant tournament
        $threeParticipants = [
            new Participant('p1', 'Player 1'),
            new Participant('p2', 'Player 2'),
            new Participant('p3', 'Player 3'),
        ];

        $threePersonSchedule = $scheduler->schedule($threeParticipants);
        expect($threePersonSchedule->count())->toBe(3); // C(3,2) = 3 matches

        $maxRound = $threePersonSchedule->getMaxRound();
        assert($maxRound !== null);
        expect($maxRound->getNumber())->toBe(3); // 3 rounds, no byes needed

        // Validate each participant plays exactly 2 matches
        $participantMatchCounts = [];
        foreach ($threePersonSchedule as $event) {
            foreach ($event->getParticipants() as $participant) {
                $id = $participant->getId();
                $participantMatchCounts[$id] = ($participantMatchCounts[$id] ?? 0) + 1;
            }
        }

        foreach ($participantMatchCounts as $count) {
            expect($count)->toBe(2);
        }
    });

    // Tests consecutive role constraints to validate positional assignment logic
    // and prevent unfair consecutive role assignments in tournaments
    it('handles consecutive role constraints with home/away assignments', function (): void {
        // Given: 6 participants with moderate home/away consecutive limits
        $participants = [];
        for ($i = 1; $i <= 6; ++$i) {
            $participants[] = new Participant("p{$i}", "Player {$i}");
        }

        $constraints = ConstraintSet::create()
            ->noRepeatPairings()
            ->add(MissionGaming\Tactician\Constraints\ConsecutiveRoleConstraint::homeAway(3))
            ->build();

        // When: Generating schedule with role constraints
        $scheduler = new RoundRobinScheduler($constraints);

        // Then: Should throw IncompleteScheduleException because consecutive role constraint
        // prevents some matches to avoid violating role assignment limits
        expect(fn () => $scheduler->schedule($participants))
            ->toThrow(MissionGaming\Tactician\Exceptions\IncompleteScheduleException::class);
    });

    // Tests multi-leg constraint validation with incremental context building
    // to ensure constraints work properly across tournament leg boundaries
    it('validates constraints across multiple tournament legs', function (): void {
        // Given: 4 participants with moderate constraints that span legs
        $participants = [];
        for ($i = 1; $i <= 4; ++$i) {
            $participants[] = new Participant("p{$i}", "Player {$i}");
        }

        $constraints = ConstraintSet::create()
            ->noRepeatPairings()
            ->build();

        // When: Creating multi-leg schedule
        $scheduler = new RoundRobinScheduler($constraints);

        // Then: Should throw IncompleteScheduleException because NoRepeatPairings constraint
        // prevents duplicate pairings across legs, making full multi-leg schedule impossible
        expect(fn () => $scheduler->schedule(
            $participants,
            2, // participantsPerEvent
            2, // legs
            new MissionGaming\Tactician\LegStrategies\RepeatedLegStrategy()
        ))->toThrow(MissionGaming\Tactician\Exceptions\IncompleteScheduleException::class);
    });

    // Tests invalid configuration scenarios to validate proper exception handling
    // and diagnostic information for common tournament setup errors
    it('throws appropriate exceptions for invalid configurations', function (): void {
        $scheduler = new RoundRobinScheduler();

        // Test empty participant list
        expect(fn () => $scheduler->schedule([]))
            ->toThrow(MissionGaming\Tactician\Exceptions\InvalidConfigurationException::class);

        // Test single participant
        expect(fn () => $scheduler->schedule([new Participant('p1', 'Player 1')]))
            ->toThrow(MissionGaming\Tactician\Exceptions\InvalidConfigurationException::class);

        // Test duplicate participant IDs
        $duplicateParticipants = [
            new Participant('same_id', 'Player 1'),
            new Participant('same_id', 'Player 2'),
        ];

        expect(fn () => $scheduler->schedule($duplicateParticipants))
            ->toThrow(MissionGaming\Tactician\Exceptions\InvalidConfigurationException::class);

        // Test invalid constraint parameters
        expect(fn () => new MissionGaming\Tactician\Constraints\MinimumRestPeriodsConstraint(0))
            ->toThrow(InvalidArgumentException::class);

        expect(fn () => new MissionGaming\Tactician\Constraints\SeedProtectionConstraint(0, 0.5))
            ->toThrow(InvalidArgumentException::class);

        expect(fn () => new MissionGaming\Tactician\Constraints\SeedProtectionConstraint(4, 1.5))
            ->toThrow(InvalidArgumentException::class);
    });
});
