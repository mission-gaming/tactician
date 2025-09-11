<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\ConsecutiveRoleConstraint;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\Validation\ConstraintViolation;
use MissionGaming\Tactician\Validation\ConstraintViolationCollector;
use MissionGaming\Tactician\Validation\RoundRobinEventCalculator;

describe('IncompleteScheduleException', function (): void {
    beforeEach(function (): void {
        $this->participant1 = new Participant('p1', 'Alice');
        $this->participant2 = new Participant('p2', 'Bob');
        $this->participant3 = new Participant('p3', 'Carol');
        $this->participant4 = new Participant('p4', 'Dave');

        $this->participants = [$this->participant1, $this->participant2, $this->participant3, $this->participant4];
        $this->eventCalculator = new RoundRobinEventCalculator();
        $this->constraint = ConsecutiveRoleConstraint::homeAway(2);
    });

    // Tests that exception properly stores all diagnostic information
    it('stores comprehensive diagnostic information', function (): void {
        // Given: Violation collector with some violations
        $violationCollector = new ConstraintViolationCollector();
        $violation = new ConstraintViolation(
            constraint: $this->constraint,
            rejectedEvent: new Event([$this->participant1, $this->participant3]),
            reason: 'Would exceed consecutive home limit',
            affectedParticipants: [$this->participant1],
            roundNumber: 3
        );
        $violationCollector->recordViolation($violation);

        // When: Creating exception with diagnostic data
        $exception = new IncompleteScheduleException(
            expectedEventCount: 12,
            actualEventCount: 2,
            violationCollector: $violationCollector,
            eventCalculator: $this->eventCalculator,
            participants: $this->participants,
            legs: 2
        );

        // Then: Should store all information correctly
        expect($exception->getExpectedEventCount())->toBe(12);
        expect($exception->getActualEventCount())->toBe(2);
        expect($exception->getViolationCollector())->toBe($violationCollector);
    });

    // Tests that exception generates comprehensive diagnostic reports
    it('generates comprehensive diagnostic reports', function (): void {
        // Given: Exception with violation data
        $violationCollector = new ConstraintViolationCollector();
        $violation = new ConstraintViolation(
            constraint: $this->constraint,
            rejectedEvent: new Event([$this->participant1, $this->participant3]),
            reason: 'Would exceed consecutive home limit',
            affectedParticipants: [$this->participant1],
            roundNumber: 3
        );
        $violationCollector->recordViolation($violation);

        $exception = new IncompleteScheduleException(
            expectedEventCount: 12,
            actualEventCount: 2,
            violationCollector: $violationCollector,
            eventCalculator: $this->eventCalculator,
            participants: $this->participants,
            legs: 2
        );

        // When: Getting diagnostic report
        $report = $exception->getDiagnosticReport();

        // Then: Should contain comprehensive information
        expect($report)->toContain('INCOMPLETE SCHEDULE DIAGNOSTIC REPORT');
        expect($report)->toContain('Expected Events: 12');
        expect($report)->toContain('Generated Events: 2');
        expect($report)->toContain('Missing Events: 10');
        expect($report)->toContain('Participants: 4');
        expect($report)->toContain('Legs: 2');
        expect($report)->toContain('Home/Away consecutive limit (2): 1 violations');
    });

    // Tests that exception properly calculates event count differences
    it('calculates missing event counts correctly', function (): void {
        // Given: Exception with known event counts
        $exception = new IncompleteScheduleException(
            expectedEventCount: 12,
            actualEventCount: 2,
            violationCollector: new ConstraintViolationCollector(),
            eventCalculator: $this->eventCalculator,
            participants: $this->participants,
            legs: 2
        );

        // When: Getting missing event count
        $missingEvents = $exception->getMissingEventCount();

        // Then: Should calculate difference correctly
        expect($missingEvents)->toBe(10); // 12 expected - 2 actual = 10 missing
    });

    // Tests that exception handles empty violation collectors gracefully
    it('handles empty violation collectors', function (): void {
        // Given: Exception without violations
        $exception = new IncompleteScheduleException(
            expectedEventCount: 12,
            actualEventCount: 2,
            violationCollector: new ConstraintViolationCollector(),
            eventCalculator: $this->eventCalculator,
            participants: $this->participants,
            legs: 2
        );

        // When: Getting diagnostic report
        $report = $exception->getDiagnosticReport();

        // Then: Should handle gracefully (no violations section when empty)
        expect($report)->toContain('INCOMPLETE SCHEDULE DIAGNOSTIC REPORT');
        expect($report)->not->toContain('CONSTRAINT VIOLATIONS');
        expect($report)->toContain('SUGGESTIONS');
    });

    // Tests that exception message is informative by default
    it('provides informative default messages', function (): void {
        // Given: Exception with default message
        $exception = new IncompleteScheduleException(
            expectedEventCount: 12,
            actualEventCount: 2,
            violationCollector: new ConstraintViolationCollector(),
            eventCalculator: $this->eventCalculator,
            participants: $this->participants,
            legs: 2
        );

        // When: Getting exception message
        $message = $exception->getMessage();

        // Then: Should be informative
        expect($message)->toContain('Incomplete schedule generated');
        expect($message)->toContain('2 events created out of 12 expected');
        expect($message)->toContain('10 missing');
    });

    // Tests that exception allows custom messages
    it('allows custom exception messages', function (): void {
        // Given: Exception with custom message
        $customMessage = 'Custom error message for incomplete schedule';
        $exception = new IncompleteScheduleException(
            expectedEventCount: 12,
            actualEventCount: 2,
            violationCollector: new ConstraintViolationCollector(),
            eventCalculator: $this->eventCalculator,
            participants: $this->participants,
            legs: 2,
            message: $customMessage
        );

        // When: Getting exception message
        $message = $exception->getMessage();

        // Then: Should use custom message
        expect($message)->toBe($customMessage);
    });

    // Tests that exception handles edge case of complete schedule incorrectly flagged
    it('handles complete schedule edge cases', function (): void {
        // Given: Exception where actual equals expected (edge case)
        $exception = new IncompleteScheduleException(
            expectedEventCount: 2,
            actualEventCount: 2, // Same as expected
            violationCollector: new ConstraintViolationCollector(),
            eventCalculator: $this->eventCalculator,
            participants: $this->participants,
            legs: 1
        );

        // When: Getting missing event count
        $missingEvents = $exception->getMissingEventCount();

        // Then: Should handle gracefully
        expect($missingEvents)->toBe(0);
    });

    // Tests that exception aggregates violation information correctly in diagnostic report
    it('aggregates violation information correctly', function (): void {
        // Given: Multiple violations of different types
        $violationCollector = new ConstraintViolationCollector();

        $homeAwayConstraint = ConsecutiveRoleConstraint::homeAway(2);
        $positionConstraint = ConsecutiveRoleConstraint::position(3);

        $violation1 = new ConstraintViolation(
            constraint: $homeAwayConstraint,
            rejectedEvent: new Event([$this->participant1, $this->participant2]),
            reason: 'Home/away violation 1',
            affectedParticipants: [$this->participant1],
            roundNumber: 2
        );

        $violation2 = new ConstraintViolation(
            constraint: $homeAwayConstraint,
            rejectedEvent: new Event([$this->participant2, $this->participant3]),
            reason: 'Home/away violation 2',
            affectedParticipants: [$this->participant2],
            roundNumber: 3
        );

        $violation3 = new ConstraintViolation(
            constraint: $positionConstraint,
            rejectedEvent: new Event([$this->participant3, $this->participant4]),
            reason: 'Position violation',
            affectedParticipants: [$this->participant3],
            roundNumber: 4
        );

        $violationCollector->recordViolation($violation1);
        $violationCollector->recordViolation($violation2);
        $violationCollector->recordViolation($violation3);

        $exception = new IncompleteScheduleException(
            expectedEventCount: 12,
            actualEventCount: 2,
            violationCollector: $violationCollector,
            eventCalculator: $this->eventCalculator,
            participants: $this->participants,
            legs: 2
        );

        // When: Getting diagnostic report
        $report = $exception->getDiagnosticReport();

        // Then: Should aggregate violations correctly
        expect($report)->toContain('CONSTRAINT VIOLATIONS');
        expect($report)->toContain('Home/Away consecutive limit (2): 2 violations');
        expect($report)->toContain('Position consecutive limit (3): 1 violations');
        expect($report)->toContain('Most affected participants');
        // Rounds are now grouped by constraint type
        expect($report)->toContain('Affected rounds: 2 (1), 3 (1)'); // Home/Away violations
        expect($report)->toContain('Affected rounds: 4 (1)'); // Position violations
    });

    // Tests that diagnostic report includes algorithm information
    it('includes algorithm information in diagnostic report', function (): void {
        // Given: Exception with round robin calculator
        $exception = new IncompleteScheduleException(
            expectedEventCount: 12,
            actualEventCount: 2,
            violationCollector: new ConstraintViolationCollector(),
            eventCalculator: $this->eventCalculator,
            participants: $this->participants,
            legs: 2
        );

        // When: Getting diagnostic report
        $report = $exception->getDiagnosticReport();

        // Then: Should include algorithm name
        expect($report)->toContain('Algorithm: Round Robin');
    });

    // Tests that diagnostic report provides suggestions section
    it('provides suggestions in diagnostic report', function (): void {
        // Given: Exception with constraint violations
        $violationCollector = new ConstraintViolationCollector();
        $violation = new ConstraintViolation(
            constraint: $this->constraint,
            rejectedEvent: new Event([$this->participant1, $this->participant3]),
            reason: 'Would exceed consecutive home limit',
            affectedParticipants: [$this->participant1]
        );
        $violationCollector->recordViolation($violation);

        $exception = new IncompleteScheduleException(
            expectedEventCount: 12,
            actualEventCount: 2,
            violationCollector: $violationCollector,
            eventCalculator: $this->eventCalculator,
            participants: $this->participants,
            legs: 2
        );

        // When: Getting diagnostic report
        $report = $exception->getDiagnosticReport();

        // Then: Should provide actionable suggestions
        expect($report)->toContain('SUGGESTIONS');
        expect($report)->toContain('Try reducing the consecutive role constraint limit');
        expect($report)->toContain('Consider increasing the number of participants');
        expect($report)->toContain('Add more legs to provide more scheduling flexibility');
    });
});
