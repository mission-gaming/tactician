<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\ConstraintInterface;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\ImpossibleConstraintsException;
use MissionGaming\Tactician\Scheduling\SchedulingContext;

// Test double constraint with __toString method
class TestConstraintWithToString implements ConstraintInterface
{
    #[\Override]
    public function getName(): string
    {
        return 'TestConstraintWithToString';
    }

    #[\Override]
    public function __toString(): string
    {
        return 'Test constraint with custom description';
    }

    #[\Override]
    public function isSatisfied(Event $event, SchedulingContext $context): bool
    {
        return true;
    }
}

// Test double constraint without __toString method
class TestConstraintWithoutToString implements ConstraintInterface
{
    #[\Override]
    public function getName(): string
    {
        return 'TestConstraintWithoutToString';
    }

    #[\Override]
    public function isSatisfied(Event $event, SchedulingContext $context): bool
    {
        return true;
    }
}

// Mock constraint with getLimit method for testing
class MockConsecutiveRoleConstraintWithLimit implements ConstraintInterface
{
    public function __construct(private int $limit)
    {
    }

    #[\Override]
    public function getName(): string
    {
        return 'ConsecutiveRoleConstraint';
    }

    public function getLimit(): int
    {
        return $this->limit;
    }

    #[\Override]
    public function isSatisfied(Event $event, SchedulingContext $context): bool
    {
        return true;
    }
}

// Mock constraint with getMinimumRest method for testing
class MockMinimumRestPeriodsConstraintWithRest implements ConstraintInterface
{
    public function __construct(private int $minimumRest)
    {
    }

    #[\Override]
    public function getName(): string
    {
        return 'MinimumRestPeriodsConstraint';
    }

    public function getMinimumRest(): int
    {
        return $this->minimumRest;
    }

    #[\Override]
    public function isSatisfied(Event $event, SchedulingContext $context): bool
    {
        return true;
    }
}

describe('ImpossibleConstraintsException', function (): void {
    beforeEach(function (): void {
        $this->participant1 = new Participant('p1', 'Alice');
        $this->participant2 = new Participant('p2', 'Bob');
        $this->participant3 = new Participant('p3', 'Carol');
        $this->participant4 = new Participant('p4', 'Dave');

        $this->participants = [
            $this->participant1,
            $this->participant2,
            $this->participant3,
            $this->participant4,
        ];

        $this->constraint1 = new TestConstraintWithToString();
        $this->constraint2 = new TestConstraintWithoutToString();
        $this->conflictingConstraints = [$this->constraint1, $this->constraint2];
        $this->legs = 2;
    });

    // Tests that exception stores constraint and participant data correctly
    it('stores constraint and participant data correctly', function (): void {
        // Given: Exception with constraints and participants
        $exception = new ImpossibleConstraintsException(
            $this->conflictingConstraints,
            $this->participants,
            $this->legs
        );

        // When: Getting stored data
        // Then: Should store all data correctly
        expect($exception->getConflictingConstraints())->toBe($this->conflictingConstraints);
        expect($exception->getParticipants())->toBe($this->participants);
        expect($exception->getLegs())->toBe($this->legs);
    });

    // Tests that exception generates default messages correctly
    it('generates default messages correctly', function (): void {
        // Given: Exception without custom message
        $exception = new ImpossibleConstraintsException(
            $this->conflictingConstraints,
            $this->participants,
            $this->legs
        );

        // When: Getting message
        $message = $exception->getMessage();

        // Then: Should generate informative default message
        expect($message)->toBe('Impossible constraint configuration detected with 4 participants and 2 legs');
    });

    // Tests that exception allows custom messages
    it('allows custom messages', function (): void {
        // Given: Exception with custom message
        $customMessage = 'Custom impossible constraints error message';
        $exception = new ImpossibleConstraintsException(
            $this->conflictingConstraints,
            $this->participants,
            $this->legs,
            $customMessage
        );

        // When: Getting message
        $message = $exception->getMessage();

        // Then: Should use custom message
        expect($message)->toBe($customMessage);
    });

    // Tests that exception generates mathematical analysis for round robin calculations
    it('generates mathematical analysis for round robin calculations', function (): void {
        // Given: Exception with 4 participants and 2 legs
        $exception = new ImpossibleConstraintsException(
            $this->conflictingConstraints,
            $this->participants,
            $this->legs
        );

        // When: Getting diagnostic report
        $report = $exception->getDiagnosticReport();

        // Then: Should include mathematical analysis
        expect($report)->toContain('MATHEMATICAL ANALYSIS');
        // Round Robin with 4 participants and 2 legs: (4 * 3 / 2) * 2 = 12 events
        expect($report)->toContain('Total events needed for Round Robin with 2 legs: 12');
    });

    // Tests that exception describes constraints with and without toString methods
    it('describes constraints with and without toString methods', function (): void {
        // Given: Exception with mixed constraint types
        $exception = new ImpossibleConstraintsException(
            $this->conflictingConstraints,
            $this->participants,
            $this->legs
        );

        // When: Getting diagnostic report
        $report = $exception->getDiagnosticReport();

        // Then: Should describe both constraint types appropriately
        expect($report)->toContain('CONFLICTING CONSTRAINTS');
        expect($report)->toContain('TestConstraintWithToString: Test constraint with custom description');
        expect($report)->toContain('TestConstraintWithoutToString');
    });

    // Tests that exception analyzes consecutive role constraints with limits
    it('analyzes consecutive role constraints with limits', function (): void {
        // Given: Mock constraint with getLimit method
        $constraintWithLimit = new MockConsecutiveRoleConstraintWithLimit(2);
        $exception = new ImpossibleConstraintsException(
            [$constraintWithLimit],
            $this->participants,
            $this->legs
        );

        // When: Getting diagnostic report
        $report = $exception->getDiagnosticReport();

        // Then: Should analyze constraint with fallback message for unrecognized class name
        expect($report)->toContain('MockConsecutiveRoleConstraintWithLimit creates scheduling restrictions that may be impossible');
    });

    // Tests that exception analyzes minimum rest period constraints
    it('analyzes minimum rest period constraints', function (): void {
        // Given: Mock constraint with getMinimumRest method
        $constraintWithRest = new MockMinimumRestPeriodsConstraintWithRest(3);
        $exception = new ImpossibleConstraintsException(
            [$constraintWithRest],
            $this->participants,
            $this->legs
        );

        // When: Getting diagnostic report
        $report = $exception->getDiagnosticReport();

        // Then: Should analyze constraint with fallback message for unrecognized class name
        expect($report)->toContain('MockMinimumRestPeriodsConstraintWithRest creates scheduling restrictions that may be impossible');
    });

    // Tests that exception handles unknown constraint types gracefully
    it('handles unknown constraint types gracefully', function (): void {
        // Given: Constraint with unknown type
        $unknownConstraint = new TestConstraintWithoutToString();
        $exception = new ImpossibleConstraintsException(
            [$unknownConstraint],
            $this->participants,
            $this->legs
        );

        // When: Getting diagnostic report
        $report = $exception->getDiagnosticReport();

        // Then: Should handle gracefully with generic message
        expect($report)->toContain('TestConstraintWithoutToString creates scheduling restrictions that may be impossible');
    });

    // Tests that exception generates relevant suggestions based on constraint types
    it('generates relevant suggestions based on constraint types', function (): void {
        // Given: Exception with any constraints
        $exception = new ImpossibleConstraintsException(
            $this->conflictingConstraints,
            $this->participants,
            $this->legs
        );

        // When: Getting diagnostic report
        $report = $exception->getDiagnosticReport();

        // Then: Should provide actionable suggestions
        expect($report)->toContain('SUGGESTIONS');
        expect($report)->toContain('Reduce constraint restrictions (lower limits, fewer requirements)');
        expect($report)->toContain('Increase the number of participants to provide more scheduling flexibility');
        expect($report)->toContain('Add more legs if constraints are per-leg based');
        expect($report)->toContain('Remove conflicting constraints');
        expect($report)->toContain('Test with a minimal constraint set first');
        expect($report)->toContain('Review constraint documentation for compatibility guidelines');
    });

    // Tests that exception produces comprehensive diagnostic reports
    it('produces comprehensive diagnostic reports', function (): void {
        // Given: Exception with multiple constraints
        $exception = new ImpossibleConstraintsException(
            $this->conflictingConstraints,
            $this->participants,
            $this->legs
        );

        // When: Getting diagnostic report
        $report = $exception->getDiagnosticReport();

        // Then: Should contain all major sections
        expect($report)->toContain('IMPOSSIBLE CONSTRAINTS DIAGNOSTIC REPORT');
        expect($report)->toContain('Participants: 4');
        expect($report)->toContain('Legs: 2');
        expect($report)->toContain('CONFLICTING CONSTRAINTS');
        expect($report)->toContain('MATHEMATICAL ANALYSIS');
        expect($report)->toContain('SUGGESTIONS');
    });

    // Tests that exception handles division by zero in mathematical calculations
    it('handles division by zero in mathematical calculations', function (): void {
        // Given: Edge case that might cause division by zero (0 participants)
        $noParticipants = [];
        $exception = new ImpossibleConstraintsException(
            $this->conflictingConstraints,
            $noParticipants,
            $this->legs
        );

        // When: Getting diagnostic report (should not throw)
        $report = $exception->getDiagnosticReport();

        // Then: Should handle gracefully
        expect($report)->toBeString();
        expect($report)->toContain('Participants: 0');
        expect($report)->toContain('Total events needed for Round Robin with 2 legs: 0');
    });

    // Tests that exception formats participant and leg counts correctly
    it('formats participant and leg counts correctly', function (): void {
        // Given: Various participant and leg counts
        $testCases = [
            ['participants' => 2, 'legs' => 1, 'expected_events' => 1],
            ['participants' => 3, 'legs' => 1, 'expected_events' => 3],
            ['participants' => 4, 'legs' => 1, 'expected_events' => 6],
            ['participants' => 4, 'legs' => 2, 'expected_events' => 12],
            ['participants' => 5, 'legs' => 2, 'expected_events' => 20],
        ];

        foreach ($testCases as $case) {
            // Given: Specific participant/leg configuration
            $participants = array_slice($this->participants, 0, $case['participants']);

            // Add additional participant if needed for 5-participant test case
            if ($case['participants'] === 5) {
                $participants[] = new Participant('p5', 'Eve');
            }

            $exception = new ImpossibleConstraintsException(
                $this->conflictingConstraints,
                $participants,
                $case['legs']
            );

            // When: Getting diagnostic report
            $report = $exception->getDiagnosticReport();

            // Then: Should format counts correctly
            expect($report)->toContain("Participants: {$case['participants']}");
            expect($report)->toContain("Legs: {$case['legs']}");
            expect($report)->toContain("Total events needed for Round Robin with {$case['legs']} legs: {$case['expected_events']}");
        }
    });

    // Tests that exception works with real constraint classes
    it('works with real constraint classes', function (): void {
        // Given: Real constraint classes from the system
        $realConstraints = [];

        // Add ConsecutiveRoleConstraint if it exists
        if (class_exists('MissionGaming\\Tactician\\Constraints\\ConsecutiveRoleConstraint')) {
            $realConstraints[] = \MissionGaming\Tactician\Constraints\ConsecutiveRoleConstraint::homeAway(2);
        }

        // Only run this test if we have real constraints
        if (empty($realConstraints)) {
            $this->markTestSkipped('No real constraint classes available for testing');
        }

        $exception = new ImpossibleConstraintsException(
            $realConstraints,
            $this->participants,
            $this->legs
        );

        // When: Getting diagnostic report
        $report = $exception->getDiagnosticReport();

        // Then: Should handle real constraints appropriately
        expect($report)->toContain('IMPOSSIBLE CONSTRAINTS DIAGNOSTIC REPORT');
        expect($report)->toContain('CONFLICTING CONSTRAINTS');
        expect($report)->toContain('MATHEMATICAL ANALYSIS');
        expect($report)->toContain('SUGGESTIONS');
    });

    // Tests that exception supports standard exception functionality
    it('supports standard exception functionality', function (): void {
        // Given: Exception with error code and previous exception
        $previousException = new \RuntimeException('Previous error');
        $errorCode = 2001;

        $exception = new ImpossibleConstraintsException(
            $this->conflictingConstraints,
            $this->participants,
            $this->legs,
            'Custom message',
            $errorCode,
            $previousException
        );

        // When: Checking standard exception properties
        // Then: Should preserve all standard functionality
        expect($exception->getCode())->toBe($errorCode);
        expect($exception->getPrevious())->toBe($previousException);
        expect($exception->getFile())->toBeString();
        expect($exception->getLine())->toBeGreaterThan(0);
        expect($exception->getTrace())->toBeArray();
    });

    // Tests that exception extends SchedulingException correctly
    it('extends SchedulingException correctly', function (): void {
        // Given: ImpossibleConstraintsException instance
        $exception = new ImpossibleConstraintsException(
            $this->conflictingConstraints,
            $this->participants,
            $this->legs
        );

        // When: Checking inheritance
        // Then: Should be instance of SchedulingException
        expect($exception)->toBeInstanceOf(\MissionGaming\Tactician\Exceptions\SchedulingException::class);
        expect($exception)->toBeInstanceOf(\Exception::class);
    });

    // Tests that exception handles constraint analysis edge cases
    it('handles constraint analysis edge cases', function (): void {
        // Given: Constraint with unusual configuration
        $edgeCaseConstraint = new MockConsecutiveRoleConstraintWithLimit(0); // Zero limit
        $exception = new ImpossibleConstraintsException(
            [$edgeCaseConstraint],
            $this->participants,
            $this->legs
        );

        // When: Getting diagnostic report
        $report = $exception->getDiagnosticReport();

        // Then: Should handle edge case gracefully with fallback message for unrecognized class name
        expect($report)->toContain('MockConsecutiveRoleConstraintWithLimit creates scheduling restrictions that may be impossible');
        expect($report)->not->toThrow(\DivisionByZeroError::class);
    });

    // Tests realistic impossible constraint scenarios
    it('handles realistic impossible constraint scenarios', function (): void {
        // Given: Realistic impossible scenario
        // - 4 participants
        // - Constraint that limits consecutive roles to 1 (very restrictive)
        // - 2 legs (requires many rounds)
        $veryRestrictiveConstraint = new MockConsecutiveRoleConstraintWithLimit(1);
        $highRestConstraint = new MockMinimumRestPeriodsConstraintWithRest(5);

        $exception = new ImpossibleConstraintsException(
            [$veryRestrictiveConstraint, $highRestConstraint],
            $this->participants,
            $this->legs,
            'These constraints are mathematically impossible with current participant count'
        );

        // When: Getting diagnostic report
        $report = $exception->getDiagnosticReport();

        // Then: Should provide comprehensive analysis with fallback messages for unrecognized class names
        expect($report)->toContain('MockConsecutiveRoleConstraintWithLimit creates scheduling restrictions that may be impossible');
        expect($report)->toContain('MockMinimumRestPeriodsConstraintWithRest creates scheduling restrictions that may be impossible');
        expect($report)->toContain('Total events needed for Round Robin with 2 legs: 12');
        expect($report)->toContain('Reduce constraint restrictions');
        expect($report)->toContain('Increase the number of participants');
    });
});
