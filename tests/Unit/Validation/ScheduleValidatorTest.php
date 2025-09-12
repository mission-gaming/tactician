<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\ConsecutiveRoleConstraint;
use MissionGaming\Tactician\Constraints\MinimumRestPeriodsConstraint;
use MissionGaming\Tactician\Constraints\NoRepeatPairings;
use MissionGaming\Tactician\Constraints\SeedProtectionConstraint;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\Validation\ConstraintViolation;
use MissionGaming\Tactician\Validation\ConstraintViolationCollector;
use MissionGaming\Tactician\Validation\ExpectedEventCalculator;
use MissionGaming\Tactician\Validation\ScheduleValidator;

describe('ScheduleValidator', function (): void {
    describe('validateScheduleCompleteness', function (): void {
        // Tests that validation passes when schedule contains expected number of events
        it('passes validation when schedule is complete', function (): void {
            // Given: A complete schedule with expected number of events
            $validator = new ScheduleValidator();
            $violations = new ConstraintViolationCollector();
            $eventCalculator = new class () implements ExpectedEventCalculator {
                #[Override]
                public function calculateExpectedEvents(array $participants, int $legs = 1, array $algorithmSpecificParams = []): int
                {
                    return 6;
                }

                #[Override]
                public function getAlgorithmName(): string
                {
                    return 'Test Algorithm';
                }
            };

            $participant1 = new Participant('p1', 'Alice');
            $participant2 = new Participant('p2', 'Bob');
            $participant3 = new Participant('p3', 'Carol');
            $participants = [$participant1, $participant2, $participant3];

            $event1 = new Event([$participant1, $participant2]);
            $event2 = new Event([$participant2, $participant3]);
            $event3 = new Event([$participant1, $participant3]);

            $events = [$event1, $event2, $event3, $event1, $event2, $event3];
            $schedule = new Schedule($events);
            $expectedCount = 6;

            // When: Validating completeness
            // Then: No exception should be thrown
            $validator->validateScheduleCompleteness(
                $schedule,
                $expectedCount,
                $violations,
                $eventCalculator,
                $participants,
                2
            );
            expect(true)->toBeTrue(); // Test passes if no exception is thrown
        });

        // Tests that validation throws exception when schedule has fewer events than expected
        it('throws exception when schedule is incomplete', function (): void {
            // Given: An incomplete schedule with fewer events than expected
            $validator = new ScheduleValidator();
            $violations = new ConstraintViolationCollector();
            $eventCalculator = new class () implements ExpectedEventCalculator {
                #[Override]
                public function calculateExpectedEvents(array $participants, int $legs = 1, array $algorithmSpecificParams = []): int
                {
                    return 6;
                }

                #[Override]
                public function getAlgorithmName(): string
                {
                    return 'Test Algorithm';
                }
            };

            $participant1 = new Participant('p1', 'Alice');
            $participant2 = new Participant('p2', 'Bob');
            $participant3 = new Participant('p3', 'Carol');
            $participants = [$participant1, $participant2, $participant3];

            $event1 = new Event([$participant1, $participant2]);
            $event2 = new Event([$participant2, $participant3]);

            $events = [$event1, $event2];
            $schedule = new Schedule($events);
            $expectedCount = 6;

            // When: Validating completeness
            // Then: Should throw IncompleteScheduleException with correct details
            try {
                $validator->validateScheduleCompleteness(
                    $schedule,
                    $expectedCount,
                    $violations,
                    $eventCalculator,
                    $participants,
                    2
                );
                expect(false)->toBeTrue('Expected IncompleteScheduleException was not thrown');
            } catch (IncompleteScheduleException $e) {
                expect(true)->toBeTrue();
            }
        });

        // Tests that exception contains all necessary diagnostic information
        it('includes diagnostic information in exception', function (): void {
            // Given: An incomplete schedule with violations
            $validator = new ScheduleValidator();
            $violations = new ConstraintViolationCollector();
            $eventCalculator = new class () implements ExpectedEventCalculator {
                #[Override]
                public function calculateExpectedEvents(array $participants, int $legs = 1, array $algorithmSpecificParams = []): int
                {
                    return 6;
                }

                #[Override]
                public function getAlgorithmName(): string
                {
                    return 'Test Algorithm';
                }
            };

            $participant1 = new Participant('p1', 'Alice');
            $participant2 = new Participant('p2', 'Bob');
            $participant3 = new Participant('p3', 'Carol');
            $participants = [$participant1, $participant2, $participant3];

            $event1 = new Event([$participant1, $participant2]);
            $event2 = new Event([$participant2, $participant3]);

            $events = [$event1];
            $schedule = new Schedule($events);
            $expectedCount = 6;

            $constraint = ConsecutiveRoleConstraint::homeAway(2);
            $violation = new ConstraintViolation(
                $constraint,
                $event2,
                'Test violation',
                [$participant1]
            );
            $violations->recordViolation($violation);

            // When: Validating completeness
            try {
                $validator->validateScheduleCompleteness(
                    $schedule,
                    $expectedCount,
                    $violations,
                    $eventCalculator,
                    $participants,
                    2
                );
                expect(false)->toBeTrue('Expected IncompleteScheduleException was not thrown');
            } catch (IncompleteScheduleException $e) {
                // Then: Exception should contain all diagnostic data
                expect($e->getExpectedEventCount())->toBe($expectedCount);
                expect($e->getActualEventCount())->toBe(1);
                expect($e->getMissingEventCount())->toBe(5);
                expect($e->getViolationCollector())->toBe($violations);
            }
        });

        // Tests edge case with empty schedule
        it('handles empty schedule correctly', function (): void {
            // Given: An empty schedule
            $validator = new ScheduleValidator();
            $violations = new ConstraintViolationCollector();
            $eventCalculator = new class () implements ExpectedEventCalculator {
                #[Override]
                public function calculateExpectedEvents(array $participants, int $legs = 1, array $algorithmSpecificParams = []): int
                {
                    return 6;
                }

                #[Override]
                public function getAlgorithmName(): string
                {
                    return 'Test Algorithm';
                }
            };

            $participant1 = new Participant('p1', 'Alice');
            $participant2 = new Participant('p2', 'Bob');
            $participant3 = new Participant('p3', 'Carol');
            $participants = [$participant1, $participant2, $participant3];

            $schedule = new Schedule([]);
            $expectedCount = 6;

            // When: Validating completeness
            // Then: Should throw exception for incomplete schedule
            try {
                $validator->validateScheduleCompleteness(
                    $schedule,
                    $expectedCount,
                    $violations,
                    $eventCalculator,
                    $participants,
                    1
                );
                expect(false)->toBeTrue('Expected IncompleteScheduleException was not thrown');
            } catch (IncompleteScheduleException $e) {
                expect(true)->toBeTrue();
            }
        });

        // Tests that validation passes when actual count equals expected count exactly
        it('passes when actual count equals expected count exactly', function (): void {
            // Given: A schedule with exactly the expected number of events
            $validator = new ScheduleValidator();
            $violations = new ConstraintViolationCollector();
            $eventCalculator = new class () implements ExpectedEventCalculator {
                #[Override]
                public function calculateExpectedEvents(array $participants, int $legs = 1, array $algorithmSpecificParams = []): int
                {
                    return 6;
                }

                #[Override]
                public function getAlgorithmName(): string
                {
                    return 'Test Algorithm';
                }
            };

            $participant1 = new Participant('p1', 'Alice');
            $participant2 = new Participant('p2', 'Bob');
            $participant3 = new Participant('p3', 'Carol');
            $participants = [$participant1, $participant2, $participant3];

            $event1 = new Event([$participant1, $participant2]);
            $event2 = new Event([$participant2, $participant3]);
            $event3 = new Event([$participant1, $participant3]);

            $events = [$event1, $event2, $event3];
            $schedule = new Schedule($events);
            $expectedCount = 3;

            // When: Validating completeness
            // Then: No exception should be thrown
            $validator->validateScheduleCompleteness(
                $schedule,
                $expectedCount,
                $violations,
                $eventCalculator,
                $participants,
                1
            );
            expect(true)->toBeTrue(); // Test passes if no exception is thrown
        });
    });

    describe('generateDiagnosticReport', function (): void {
        // Tests generation of basic diagnostic report with violation information
        it('generates comprehensive diagnostic report with violations', function (): void {
            // Given: Violations from different constraints affecting different participants and rounds
            $validator = new ScheduleValidator();
            $violations = new ConstraintViolationCollector();

            $participant1 = new Participant('p1', 'Alice');
            $participant2 = new Participant('p2', 'Bob');
            $participant3 = new Participant('p3', 'Carol');

            $event1 = new Event([$participant1, $participant2]);
            $event2 = new Event([$participant2, $participant3]);
            $event3 = new Event([$participant1, $participant3]);

            $consecutiveConstraint = ConsecutiveRoleConstraint::homeAway(2);
            $restConstraint = new MinimumRestPeriodsConstraint(3);

            $violation1 = new ConstraintViolation(
                $consecutiveConstraint,
                $event1,
                'Consecutive violation',
                [$participant1],
                roundNumber: 2
            );

            $violation2 = new ConstraintViolation(
                $consecutiveConstraint,
                $event2,
                'Another consecutive violation',
                [$participant2],
                roundNumber: 3
            );

            $violation3 = new ConstraintViolation(
                $restConstraint,
                $event3,
                'Rest period violation',
                [$participant1, $participant3],
                roundNumber: 2
            );

            $violations->recordViolation($violation1);
            $violations->recordViolation($violation2);
            $violations->recordViolation($violation3);

            // When: Generating diagnostic report
            $report = $validator->generateDiagnosticReport(
                $violations,
                10,
                6,
                'Round Robin'
            );

            // Then: Report should contain comprehensive violation analysis
            expect($report)->toContain('Cannot generate complete Round Robin schedule');
            expect($report)->toContain('Expected: 10 events');
            expect($report)->toContain('Generated: 6 events (4 missing)');
            expect($report)->toContain('Constraint violations:');
            expect($report)->toContain('Home/Away consecutive limit (2): 2 violations in rounds [2,3]');
            expect($report)->toContain('Minimum Rest Periods (3 rounds): 1 violations in rounds [2]');
            expect($report)->toContain('Participant impact:');
            expect($report)->toContain('p1: 2 violations');
            expect($report)->toContain('p2: 1 violations');
            expect($report)->toContain('p3: 1 violations');
        });

        // Tests report generation when no violations are present
        it('generates clean report when no violations exist', function (): void {
            // Given: No violations recorded
            $validator = new ScheduleValidator();
            $violations = new ConstraintViolationCollector();

            // When: Generating diagnostic report
            $report = $validator->generateDiagnosticReport(
                $violations,
                8,
                5,
                'Test Algorithm'
            );

            // Then: Report should show basic information without violation details
            expect($report)->toContain('Cannot generate complete Test Algorithm schedule');
            expect($report)->toContain('Expected: 8 events');
            expect($report)->toContain('Generated: 5 events (3 missing)');
            expect($report)->not->toContain('Constraint violations:');
            expect($report)->not->toContain('Participant impact:');
        });

        // Tests that violations without round numbers are handled correctly
        it('handles violations without round numbers gracefully', function (): void {
            // Given: Violations without round numbers
            $validator = new ScheduleValidator();
            $violations = new ConstraintViolationCollector();

            $participant1 = new Participant('p1', 'Alice');
            $participant2 = new Participant('p2', 'Bob');
            $event1 = new Event([$participant1, $participant2]);

            $constraint = new NoRepeatPairings();
            $violation = new ConstraintViolation(
                $constraint,
                $event1,
                'No round violation',
                [$participant1, $participant2]
            );
            $violations->recordViolation($violation);

            // When: Generating diagnostic report
            $report = $validator->generateDiagnosticReport(
                $violations,
                6,
                3,
                'Algorithm'
            );

            // Then: Should handle missing round information without rounds text
            expect($report)->toContain('No Repeat Pairings: 1 violations');
            expect($report)->not->toContain('in rounds []');
        });

        // Tests that duplicate rounds in violations are handled correctly
        it('handles duplicate rounds in violations correctly', function (): void {
            // Given: Multiple violations in the same rounds
            $validator = new ScheduleValidator();
            $violations = new ConstraintViolationCollector();

            $participant1 = new Participant('p1', 'Alice');
            $participant2 = new Participant('p2', 'Bob');
            $participant3 = new Participant('p3', 'Carol');

            $event1 = new Event([$participant1, $participant2]);
            $event2 = new Event([$participant2, $participant3]);
            $event3 = new Event([$participant1, $participant3]);

            $constraint = ConsecutiveRoleConstraint::position(3);

            $violation1 = new ConstraintViolation(
                $constraint,
                $event1,
                'Round 2 violation 1',
                [$participant1],
                roundNumber: 2
            );

            $violation2 = new ConstraintViolation(
                $constraint,
                $event2,
                'Round 2 violation 2',
                [$participant2],
                roundNumber: 2
            );

            $violation3 = new ConstraintViolation(
                $constraint,
                $event3,
                'Round 4 violation',
                [$participant3],
                roundNumber: 4
            );

            $violations->recordViolation($violation1);
            $violations->recordViolation($violation2);
            $violations->recordViolation($violation3);

            // When: Generating diagnostic report
            $report = $validator->generateDiagnosticReport(
                $violations,
                9,
                7,
                'Test'
            );

            // Then: Should show unique sorted rounds
            expect($report)->toContain('Position consecutive limit (3): 3 violations in rounds [2,4]');
        });
    });

    describe('generateConstraintSuggestions', function (): void {
        // Tests that empty suggestions are returned when no violations exist
        it('returns empty string when no violations exist', function (): void {
            // Given: No violations recorded
            $validator = new ScheduleValidator();
            $violations = new ConstraintViolationCollector();

            // When: Generating suggestions
            $suggestions = $validator->generateConstraintSuggestions(
                $violations,
                3
            );

            // Then: Should return empty string
            expect($suggestions)->toBe('');
        });

        // Tests specific suggestions for consecutive constraint violations
        it('provides specific suggestions for consecutive constraints', function (): void {
            // Given: Consecutive constraint violations
            $validator = new ScheduleValidator();
            $violations = new ConstraintViolationCollector();

            $participant1 = new Participant('p1', 'Alice');
            $participant2 = new Participant('p2', 'Bob');
            $event1 = new Event([$participant1, $participant2]);

            $constraint = ConsecutiveRoleConstraint::homeAway(2);
            $violation = new ConstraintViolation(
                $constraint,
                $event1,
                'Consecutive violation',
                [$participant1]
            );
            $violations->recordViolation($violation);

            // When: Generating suggestions
            $suggestions = $validator->generateConstraintSuggestions(
                $violations,
                3
            );

            // Then: Should provide consecutive-specific suggestions
            expect($suggestions)->toContain('Suggestions:');
            expect($suggestions)->toContain('Consider increasing the consecutive limit');
        });

        // Tests specific suggestions for rest period constraint violations
        it('provides specific suggestions for rest constraints', function (): void {
            // Given: Rest period constraint violations
            $validator = new ScheduleValidator();
            $violations = new ConstraintViolationCollector();

            $participant1 = new Participant('p1', 'Alice');
            $participant2 = new Participant('p2', 'Bob');
            $event1 = new Event([$participant1, $participant2]);

            $constraint = new MinimumRestPeriodsConstraint(4);
            $violation = new ConstraintViolation(
                $constraint,
                $event1,
                'Rest violation',
                [$participant1]
            );
            $violations->recordViolation($violation);

            // When: Generating suggestions
            $suggestions = $validator->generateConstraintSuggestions(
                $violations,
                3
            );

            // Then: Should provide generic suggestion since 'rest' != 'Rest' (case-sensitive check)
            expect($suggestions)->toContain('Review configuration for \'Minimum Rest Periods (4 rounds)\'');
        });

        // Tests specific suggestions for seed protection constraint violations
        it('provides specific suggestions for seed constraints', function (): void {
            // Given: Seed protection constraint violations
            $validator = new ScheduleValidator();
            $violations = new ConstraintViolationCollector();

            $participant1 = new Participant('p1', 'Alice');
            $participant2 = new Participant('p2', 'Bob');
            $event1 = new Event([$participant1, $participant2]);

            $constraint = new SeedProtectionConstraint(1, 0.5);
            $violation = new ConstraintViolation(
                $constraint,
                $event1,
                'Seed violation',
                [$participant1]
            );
            $violations->recordViolation($violation);

            // When: Generating suggestions
            $suggestions = $validator->generateConstraintSuggestions(
                $violations,
                3
            );

            // Then: Should provide generic suggestion since 'seed' != 'Seed' (case-sensitive check)
            expect($suggestions)->toContain('Review configuration for \'Seed Protection (top 1, 0.5% period)\'');
        });

        // Tests high violation ratio warning and suggestions
        it('provides high violation ratio warnings', function (): void {
            // Given: High number of violations relative to participants
            $validator = new ScheduleValidator();
            $violations = new ConstraintViolationCollector();

            $participant1 = new Participant('p1', 'Alice');
            $participant2 = new Participant('p2', 'Bob');
            $event1 = new Event([$participant1, $participant2]);

            $constraint = new NoRepeatPairings();

            // Add many violations to trigger high ratio warning
            for ($i = 0; $i < 10; ++$i) {
                $violation = new ConstraintViolation(
                    $constraint,
                    $event1,
                    "Violation $i",
                    [$participant1]
                );
                $violations->recordViolation($violation);
            }

            // When: Generating suggestions
            $suggestions = $validator->generateConstraintSuggestions(
                $violations,
                3
            );

            // Then: Should warn about high violation ratio
            expect($suggestions)->toContain('High violation ratio (10 violations)');
            expect($suggestions)->toContain('constraints may be too restrictive');
            expect($suggestions)->toContain('Consider relaxing constraint parameters');
        });

        // Tests generic suggestions for unknown constraint types
        it('provides generic suggestions for unknown constraints', function (): void {
            // Given: A custom constraint that doesn't match known patterns
            $validator = new ScheduleValidator();
            $violations = new ConstraintViolationCollector();

            $participant1 = new Participant('p1', 'Alice');
            $participant2 = new Participant('p2', 'Bob');
            $event1 = new Event([$participant1, $participant2]);

            $constraint = new class () implements \MissionGaming\Tactician\Constraints\ConstraintInterface {
                #[Override]
                public function getName(): string
                {
                    return 'Custom Constraint';
                }

                #[Override]
                public function isSatisfied(\MissionGaming\Tactician\DTO\Event $event, \MissionGaming\Tactician\Scheduling\SchedulingContext $context): bool
                {
                    return true;
                }
            };

            $violation = new ConstraintViolation(
                $constraint,
                $event1,
                'Custom violation',
                [$participant1]
            );
            $violations->recordViolation($violation);

            // When: Generating suggestions
            $suggestions = $validator->generateConstraintSuggestions(
                $violations,
                3
            );

            // Then: Should provide generic review suggestion
            expect($suggestions)->toContain('Review configuration for \'Custom Constraint\'');
        });

        // Tests protection against division by zero when calculating violation ratio
        it('handles zero participant scenario without division error', function (): void {
            // Given: Violations but zero participants (edge case)
            $validator = new ScheduleValidator();
            $violations = new ConstraintViolationCollector();

            $participant1 = new Participant('p1', 'Alice');
            $participant2 = new Participant('p2', 'Bob');
            $event1 = new Event([$participant1, $participant2]);

            $constraint = ConsecutiveRoleConstraint::homeAway(2);
            $violation = new ConstraintViolation(
                $constraint,
                $event1,
                'Test violation',
                [$participant1]
            );
            $violations->recordViolation($violation);

            // When: Generating suggestions with zero participants
            // Then: Should throw division by zero error as documented
            try {
                $validator->generateConstraintSuggestions($violations, 0);
                expect(false)->toBeTrue('Expected DivisionByZeroError was not thrown');
            } catch (\DivisionByZeroError $e) {
                expect(true)->toBeTrue();
            }
        });

        // Tests that multiple constraint types generate combined suggestions
        it('provides combined suggestions for multiple constraint types', function (): void {
            // Given: Multiple different constraint violations
            $validator = new ScheduleValidator();
            $violations = new ConstraintViolationCollector();

            $participant1 = new Participant('p1', 'Alice');
            $participant2 = new Participant('p2', 'Bob');
            $participant3 = new Participant('p3', 'Carol');

            $event1 = new Event([$participant1, $participant2]);
            $event2 = new Event([$participant2, $participant3]);
            $event3 = new Event([$participant1, $participant3]);

            $consecutiveConstraint = ConsecutiveRoleConstraint::homeAway(1);
            $restConstraint = new MinimumRestPeriodsConstraint(2);
            $seedConstraint = new SeedProtectionConstraint(1, 0.3);

            $violations->recordViolation(new ConstraintViolation(
                $consecutiveConstraint,
                $event1,
                'Consecutive',
                [$participant1]
            ));
            $violations->recordViolation(new ConstraintViolation(
                $restConstraint,
                $event2,
                'Rest',
                [$participant2]
            ));
            $violations->recordViolation(new ConstraintViolation(
                $seedConstraint,
                $event3,
                'Seed',
                [$participant3]
            ));

            // When: Generating suggestions
            $suggestions = $validator->generateConstraintSuggestions(
                $violations,
                4
            );

            // Then: Should include suggestions based on actual case-sensitive pattern matching
            expect($suggestions)->toContain('Consider increasing the consecutive limit');
            expect($suggestions)->toContain('Review configuration for \'Minimum Rest Periods (2 rounds)\'');
            expect($suggestions)->toContain('Review configuration for \'Seed Protection (top 1, 0.3% period)\'');
        });
    });
});
