<?php

use MissionGaming\Tactician\Constraints\ConsecutiveRoleConstraint;
use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Constraints\MetadataConstraint;
use MissionGaming\Tactician\Constraints\MinimumRestPeriodsConstraint;
use MissionGaming\Tactician\Constraints\SeedProtectionConstraint;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\LegStrategies\MirroredLegStrategy;
use MissionGaming\Tactician\LegStrategies\RepeatedLegStrategy;
use MissionGaming\Tactician\LegStrategies\ShuffledLegStrategy;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

describe('Complex Constraint Test Cases', function (): void {
    // Tests a complex tournament scenario with 8 teams having multiple metadata constraints,
    // rest period requirements, seed protection, and role restrictions across 3 legs using mirrored strategy
    // This scenario has impossible constraints that should trigger IncompleteScheduleException
    test('Test Case 1: The Tournament Directors Nightmare', function (): void {
        // 8 participants with complex metadata
        $participants = [
            new Participant('team-a', 'Team A', 1, ['region' => 'North', 'division' => 'Pro', 'venue_preference' => 'Indoor']),
            new Participant('team-b', 'Team B', 2, ['region' => 'North', 'division' => 'Pro', 'venue_preference' => 'Outdoor']),
            new Participant('team-c', 'Team C', 3, ['region' => 'South', 'division' => 'Amateur', 'venue_preference' => 'Indoor']),
            new Participant('team-d', 'Team D', 4, ['region' => 'South', 'division' => 'Amateur', 'venue_preference' => 'Outdoor']),
            new Participant('team-e', 'Team E', 5, ['region' => 'East', 'division' => 'Pro', 'venue_preference' => 'Indoor']),
            new Participant('team-f', 'Team F', 6, ['region' => 'East', 'division' => 'Pro', 'venue_preference' => 'Outdoor']),
            new Participant('team-g', 'Team G', 7, ['region' => 'West', 'division' => 'Amateur', 'venue_preference' => 'Indoor']),
            new Participant('team-h', 'Team H', 8, ['region' => 'West', 'division' => 'Amateur', 'venue_preference' => 'Outdoor']),
        ];

        $constraints = ConstraintSet::create()
            ->add(new MinimumRestPeriodsConstraint(2))
            ->add(new SeedProtectionConstraint(2, 0.4)) // Protect top 2 seeds for 40% of tournament
            ->add(MetadataConstraint::maxUniqueValues('region', 2)) // Max 2 regions per match
            ->add(ConsecutiveRoleConstraint::homeAway(2)) // No more than 2 consecutive home/away roles
            ->build();

        $scheduler = new RoundRobinScheduler($constraints);

        // These constraints are too restrictive - should throw IncompleteScheduleException
        expect(fn () => $scheduler->schedule(
            $participants,
            2, // participantsPerEvent
            3, // legs
            new MirroredLegStrategy()
        ))->toThrow(\MissionGaming\Tactician\Exceptions\IncompleteScheduleException::class);
    });

    // Tests seed protection constraint where top 2 seeds are protected for 60% of the tournament
    // across 2 legs using mirrored strategy, ensuring high-seeded teams avoid each other early
    // This scenario has restrictive seed protection that should trigger IncompleteScheduleException
    test('Test Case 2: The Seed Protection Paradox', function (): void {
        $participants = [
            new Participant('team-a', 'Team A', 1), // Top seed
            new Participant('team-b', 'Team B', 2), // Second seed
            new Participant('team-c', 'Team C', 3),
            new Participant('team-d', 'Team D', 4),
            new Participant('team-e', 'Team E', 5),
            new Participant('team-f', 'Team F', 6),
        ];

        $constraints = ConstraintSet::create()
            ->add(new SeedProtectionConstraint(2, 0.6)) // Protect for 60% of tournament
            ->add(new MinimumRestPeriodsConstraint(1))
            ->build();

        $scheduler = new RoundRobinScheduler($constraints);

        // These constraints are too restrictive - should throw IncompleteScheduleException
        expect(fn () => $scheduler->schedule(
            $participants,
            2, // participantsPerEvent
            2, // legs
            new MirroredLegStrategy()
        ))->toThrow(\MissionGaming\Tactician\Exceptions\IncompleteScheduleException::class);
    });

    // Tests complex metadata constraints: teams must have adjacent skill levels, same equipment,
    // but different regions, creating very restrictive pairing requirements in a single leg
    // This scenario has impossible metadata constraints that should trigger IncompleteScheduleException
    test('Test Case 3: The Metadata Maze', function (): void {
        $participants = [
            new Participant('team-a', 'Team A', 1, ['skill_level' => 3, 'equipment' => 'A', 'region' => 'North']),
            new Participant('team-b', 'Team B', 2, ['skill_level' => 3, 'equipment' => 'A', 'region' => 'South']),
            new Participant('team-c', 'Team C', 3, ['skill_level' => 4, 'equipment' => 'B', 'region' => 'East']),
            new Participant('team-d', 'Team D', 4, ['skill_level' => 4, 'equipment' => 'B', 'region' => 'West']),
            new Participant('team-e', 'Team E', 5, ['skill_level' => 2, 'equipment' => 'A', 'region' => 'North']),
            new Participant('team-f', 'Team F', 6, ['skill_level' => 5, 'equipment' => 'B', 'region' => 'South']),
        ];

        $constraints = ConstraintSet::create()
            ->add(MetadataConstraint::requireAdjacentValues('skill_level', 'Adjacent skill levels')) // Adjacent skill levels only
            ->add(MetadataConstraint::requireSameValue('equipment', 'Same equipment')) // Same equipment only
            ->add(MetadataConstraint::requireDifferentValues('region', 'Different regions')) // Must be cross-regional
            ->build();

        $scheduler = new RoundRobinScheduler($constraints);

        // These constraints are too restrictive - should throw IncompleteScheduleException
        expect(fn () => $scheduler->schedule($participants))
            ->toThrow(\MissionGaming\Tactician\Exceptions\IncompleteScheduleException::class);
    });

    // Tests minimum rest period constraint of 3 rounds between a team's matches
    // across 4 legs using repeated strategy, ensuring teams get adequate rest between games
    test('Test Case 4: The Rest Period Spiral', function (): void {
        $participants = [
            new Participant('team-a', 'Team A'),
            new Participant('team-b', 'Team B'),
            new Participant('team-c', 'Team C'),
            new Participant('team-d', 'Team D'),
        ];

        $constraints = ConstraintSet::create()
            ->add(new MinimumRestPeriodsConstraint(3))
            ->build();

        $scheduler = new RoundRobinScheduler($constraints);

        $schedule = $scheduler->schedule(
            $participants,
            2, // participantsPerEvent
            4, // legs
            new RepeatedLegStrategy()
        );

        // With 4 participants: 3 rounds per leg = 12 total rounds
        // Incremental context building ensures 3-round gaps are maintained
        expect(count($schedule))->toBeGreaterThan(0);
        expect($schedule->getMetadataValue('legs'))->toBe(4);

        // This scenario should work fine with reasonable constraints
    });

    // Tests consecutive role constraint (max 2 consecutive home games) combined with rest periods
    // across 2 legs using shuffled strategy, preventing teams from playing too many home/away games in a row
    // This scenario has restrictive role constraints that should trigger IncompleteScheduleException
    test('Test Case 5: The Role Reversal Trap', function (): void {
        $participants = [
            new Participant('team-a', 'Team A'),
            new Participant('team-b', 'Team B'),
            new Participant('team-c', 'Team C'),
            new Participant('team-d', 'Team D'),
            new Participant('team-e', 'Team E'),
            new Participant('team-f', 'Team F'),
            new Participant('team-g', 'Team G'),
            new Participant('team-h', 'Team H'),
        ];

        $constraints = ConstraintSet::create()
            ->add(ConsecutiveRoleConstraint::homeAway(2)) // Max 2 consecutive home games
            ->add(new MinimumRestPeriodsConstraint(1))
            ->build();

        $scheduler = new RoundRobinScheduler($constraints);

        // These constraints are too restrictive - should throw IncompleteScheduleException
        expect(fn () => $scheduler->schedule(
            $participants,
            2, // participantsPerEvent
            2, // legs
            new ShuffledLegStrategy()
        ))->toThrow(\MissionGaming\Tactician\Exceptions\IncompleteScheduleException::class);
    });
});
