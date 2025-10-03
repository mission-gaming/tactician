<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\ConsecutiveRoleConstraint;
use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

describe('Schedule Validation Integration', function (): void {
    beforeEach(function (): void {
        $this->participants = [
            new Participant('celtic', 'Celtic'),
            new Participant('athletic', 'Athletic Bilbao'),
            new Participant('livorno', 'AS Livorno'),
            new Participant('redstar', 'Red Star FC'),
        ];
    });

    // Tests the original problem case: consecutive role constraint that prevents complete schedule
    it('prevents silent incomplete schedule generation with consecutive role constraints', function (): void {
        // Given: The original problematic configuration from the user's example
        $constraints = ConstraintSet::create()
            ->add(ConsecutiveRoleConstraint::homeAway(2))
            ->build();

        $scheduler = new RoundRobinScheduler($constraints);

        // When: Attempting to generate schedule
        // Then: Should throw IncompleteScheduleException instead of silently generating partial schedule
        expect(fn () => $scheduler->generateMultiLegSchedule(
            $this->participants,
            2 // legs
        ))->toThrow(IncompleteScheduleException::class);
    });

    // Tests that the validation system provides detailed diagnostic information
    it('provides comprehensive diagnostic information for failed schedules', function (): void {
        // Given: Configuration that will cause violations
        $constraints = ConstraintSet::create()
            ->add(ConsecutiveRoleConstraint::homeAway(2))
            ->build();

        $scheduler = new RoundRobinScheduler($constraints);

        try {
            // When: Attempting to generate schedule
            $scheduler->generateMultiLegSchedule(
                $this->participants,
                2 // legs
            );

            // Should not reach here
            expect(false)->toBeTrue('Expected IncompleteScheduleException to be thrown');
        } catch (IncompleteScheduleException $e) {
            // Then: Exception should contain comprehensive diagnostic information
            expect($e->getExpectedEventCount())->toBe(12); // 4 participants, 2 legs = 4*3/2 * 2 = 12
            expect($e->getActualEventCount())->toBeLessThan(12);
            expect($e->getMissingEventCount())->toBeGreaterThan(0);

            $report = $e->getDiagnosticReport();
            expect($report)->toContain('INCOMPLETE SCHEDULE DIAGNOSTIC REPORT');
            expect($report)->toContain('Expected Events: 12');
            expect($report)->toContain('Algorithm: Round Robin');
            expect($report)->toContain('Participants: 4');
            expect($report)->toContain('Legs: 2');

            // Should have violation information
            $violationCollector = $e->getViolationCollector();
            expect($violationCollector->hasViolations())->toBeTrue();
            expect($violationCollector->getViolationCount())->toBeGreaterThan(0);
        }
    });

    // Tests that relaxed constraints allow successful schedule generation
    it('allows complete schedule generation with relaxed constraints', function (): void {
        // Given: More permissive constraint configuration
        $constraints = ConstraintSet::create()
            ->add(ConsecutiveRoleConstraint::homeAway(4)) // More relaxed limit
            ->build();

        $scheduler = new RoundRobinScheduler($constraints);

        // When: Generating schedule
        $schedule = $scheduler->generateMultiLegSchedule(
            $this->participants,
            2 // legs
        );

        // Then: Should generate complete schedule without throwing exception
        expect($schedule->count())->toBe(12); // Full schedule
        expect($schedule->getMaxRound()?->getNumber())->toBeGreaterThanOrEqual(6); // Expected rounds
    });

    // Tests that constraint violations are properly tracked and reported
    it('tracks constraint violations with detailed information', function (): void {
        // Given: Very restrictive constraints
        $constraints = ConstraintSet::create()
            ->add(ConsecutiveRoleConstraint::homeAway(1)) // Very restrictive
            ->build();

        $scheduler = new RoundRobinScheduler($constraints);

        try {
            // When: Attempting to generate schedule
            $scheduler->generateMultiLegSchedule(
                $this->participants,
                2 // legs
            );
            expect(false)->toBeTrue('Expected exception to be thrown');
        } catch (IncompleteScheduleException $e) {
            // Then: Should have detailed violation tracking
            $violationCollector = $e->getViolationCollector();
            expect($violationCollector->hasViolations())->toBeTrue();

            $violations = $violationCollector->getViolations();
            expect($violations)->not->toBeEmpty();

            // Check that violations have proper structure
            $firstViolation = $violations[0];
            expect($firstViolation->constraint)->toBeInstanceOf(ConsecutiveRoleConstraint::class);
            expect($firstViolation->reason)->toBeString();
            expect($firstViolation->affectedParticipants)->toBeArray();
            expect($firstViolation->affectedParticipants)->not->toBeEmpty();

            // Check violation grouping functionality
            $groupedByConstraint = $violationCollector->getViolationsByConstraint();
            expect($groupedByConstraint)->toHaveKey('Home/Away consecutive limit (1)');

            $groupedByParticipant = $violationCollector->getViolationsByParticipant();
            expect($groupedByParticipant)->not->toBeEmpty();
        }
    });

    // Tests that no constraints allows full schedule generation
    it('generates complete schedules without constraints', function (): void {
        // Given: No constraints applied
        $constraints = ConstraintSet::create()->build();
        $scheduler = new RoundRobinScheduler($constraints);

        // When: Generating schedule
        $schedule = $scheduler->generateMultiLegSchedule(
            $this->participants,
            2 // legs
        );

        // Then: Should generate complete schedule
        expect($schedule->count())->toBe(12);
        expect($schedule->getMaxRound()?->getNumber())->toBeGreaterThanOrEqual(6);

        // All participants should be scheduled equally
        foreach ($this->participants as $participant) {
            $participantEvents = array_filter(
                $schedule->getEvents(),
                fn ($event) => in_array($participant, $event->getParticipants())
            );
            expect(count($participantEvents))->toBe(6); // Each plays 6 games (3 opponents * 2 legs)
        }
    });

    // Tests edge case with minimum participants
    it('handles minimum participant count with constraints', function (): void {
        // Given: Minimum participants (2) with constraints
        $minParticipants = [
            new Participant('team1', 'Team 1'),
            new Participant('team2', 'Team 2'),
        ];

        $constraints = ConstraintSet::create()
            ->add(ConsecutiveRoleConstraint::homeAway(1))
            ->build();

        $scheduler = new RoundRobinScheduler($constraints);

        // When: Generating schedule
        $schedule = $scheduler->generateMultiLegSchedule(
            $minParticipants,
            2 // legs
        );

        // Then: Should generate complete schedule (constraint not restrictive enough to prevent)
        expect($schedule->count())->toBe(2); // 2 participants, 2 legs = 1*2 = 2 events
    });

    // Tests that suggestions are contextually appropriate
    it('provides contextual suggestions based on constraint types', function (): void {
        // Given: Different types of constraint violations
        $constraints = ConstraintSet::create()
            ->add(ConsecutiveRoleConstraint::homeAway(1))
            ->build();

        $scheduler = new RoundRobinScheduler($constraints);

        try {
            // When: Schedule fails due to consecutive role constraints
            $scheduler->generateMultiLegSchedule(
                $this->participants,
                2 // legs
            );
            expect(false)->toBeTrue('Expected exception');
        } catch (IncompleteScheduleException $e) {
            // Then: Suggestions should be relevant to consecutive role constraints
            $report = $e->getDiagnosticReport();
            expect($report)->toContain('SUGGESTIONS');
            expect($report)->toContain('Try reducing the consecutive role constraint limit');
            expect($report)->toContain('Consider increasing the number of participants');
            expect($report)->toContain('Add more legs to provide more scheduling flexibility');
        }
    });

    // Tests that validation works correctly across different leg configurations
    it('validates schedules correctly across different leg configurations', function (): void {
        // Given: Testing different leg counts
        $constraints = ConstraintSet::create()
            ->add(ConsecutiveRoleConstraint::homeAway(2))
            ->build();

        $testCases = [
            1 => 6,  // Single leg: C(4,2) = 6
            2 => 12, // Double leg: 6 * 2 = 12
            3 => 18, // Triple leg: 6 * 3 = 18
        ];

        foreach ($testCases as $legs => $expectedEvents) {
            $scheduler = new RoundRobinScheduler($constraints);

            try {
                // When: Attempting to schedule with different leg counts
                $schedule = $scheduler->generateMultiLegSchedule(
                    $this->participants,
                    $legs
                );

                // If successful, should have correct event count
                expect($schedule->count())->toBe($expectedEvents);
            } catch (IncompleteScheduleException $e) {
                // If incomplete, should report correct expected count
                expect($e->getExpectedEventCount())->toBe($expectedEvents);
                expect($e->getActualEventCount())->toBeLessThan($expectedEvents);
            }
        }
    });

    // Tests that the validation system identifies the root cause correctly
    it('correctly identifies root cause of scheduling failures', function (): void {
        // Given: Configuration known to cause specific constraint violations
        $constraints = ConstraintSet::create()
            ->add(ConsecutiveRoleConstraint::homeAway(2))
            ->build();

        $scheduler = new RoundRobinScheduler($constraints);

        try {
            // When: Failing to generate complete schedule
            $scheduler->generateMultiLegSchedule(
                $this->participants,
                2 // legs
            );
            expect(false)->toBeTrue('Expected exception');
        } catch (IncompleteScheduleException $e) {
            // Then: Should identify home/away consecutive constraint as the issue
            $violationCollector = $e->getViolationCollector();
            $violationsByConstraint = $violationCollector->getViolationsByConstraint();

            expect($violationsByConstraint)->toHaveKey('Home/Away consecutive limit (2)');

            $report = $e->getDiagnosticReport();
            expect($report)->toContain('Home/Away consecutive limit (2)');
        }
    });
});
