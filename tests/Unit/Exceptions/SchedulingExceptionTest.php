<?php

declare(strict_types=1);

use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\SchedulingException;

describe('SchedulingException', function (): void {
    // Tests that factory method creates invalid participant count exceptions with correct context
    it('creates invalid participant count exceptions with correct context', function (): void {
        // Given: Invalid participant count
        $count = 1;

        // When: Using factory method
        $exception = SchedulingException::invalidParticipantCount($count);

        // Then: Should create InvalidConfigurationException with proper context
        expect($exception)->toBeInstanceOf(InvalidConfigurationException::class);
        expect($exception->getMessage())->toBe('Invalid participant count: 1. Must be at least 2.');

        /** @var InvalidConfigurationException $exception */
        expect($exception->getConfigurationIssue())->toBe('Invalid participant count: 1. Must be at least 2.');

        $context = $exception->getContext();
        expect($context)->toHaveKey('participant_count', 1);
        expect($context)->toHaveKey('minimum_required', 2);
    });

    // Tests that factory method creates constraint violation exceptions with proper messaging
    it('creates constraint violation exceptions with proper messaging', function (): void {
        // Given: Constraint violation description
        $constraint = 'NoRepeatPairings: Cannot pair Alice and Bob again';

        // When: Using factory method
        $exception = SchedulingException::constraintViolation($constraint);

        // Then: Should create InvalidConfigurationException with constraint context
        expect($exception)->toBeInstanceOf(InvalidConfigurationException::class);
        expect($exception->getMessage())->toBe('Constraint violation: NoRepeatPairings: Cannot pair Alice and Bob again');

        /** @var InvalidConfigurationException $exception */
        expect($exception->getConfigurationIssue())->toBe('Constraint violation: NoRepeatPairings: Cannot pair Alice and Bob again');

        $context = $exception->getContext();
        expect($context)->toHaveKey('constraint', $constraint);
    });

    // Tests that factory method creates invalid schedule exceptions with reason context
    it('creates invalid schedule exceptions with reason context', function (): void {
        // Given: Schedule validation reason
        $reason = 'Schedule contains duplicate events in round 3';

        // When: Using factory method
        $exception = SchedulingException::invalidSchedule($reason);

        // Then: Should create InvalidConfigurationException with reason context
        expect($exception)->toBeInstanceOf(InvalidConfigurationException::class);
        expect($exception->getMessage())->toBe('Invalid schedule: Schedule contains duplicate events in round 3');

        /** @var InvalidConfigurationException $exception */
        expect($exception->getConfigurationIssue())->toBe('Invalid schedule: Schedule contains duplicate events in round 3');

        $context = $exception->getContext();
        expect($context)->toHaveKey('reason', $reason);
    });

    // Tests that factory methods return InvalidConfigurationException instances
    it('factory methods return InvalidConfigurationException instances', function (): void {
        // Given: Various factory method calls
        $participantException = SchedulingException::invalidParticipantCount(0);
        $constraintException = SchedulingException::constraintViolation('test constraint');
        $scheduleException = SchedulingException::invalidSchedule('test reason');

        // When: Checking instance types
        // Then: All should be InvalidConfigurationException instances
        expect($participantException)->toBeInstanceOf(InvalidConfigurationException::class);
        expect($constraintException)->toBeInstanceOf(InvalidConfigurationException::class);
        expect($scheduleException)->toBeInstanceOf(InvalidConfigurationException::class);

        // And also SchedulingException instances (inheritance)
        expect($participantException)->toBeInstanceOf(SchedulingException::class);
        expect($constraintException)->toBeInstanceOf(SchedulingException::class);
        expect($scheduleException)->toBeInstanceOf(SchedulingException::class);
    });

    // Tests that factory methods handle edge cases in participant counts
    it('handles edge cases in participant counts', function (): void {
        // Given: Various edge case participant counts
        $zeroCases = [0, -1, -10];

        foreach ($zeroCases as $count) {
            // When: Creating exception with edge case count
            $exception = SchedulingException::invalidParticipantCount($count);

            // Then: Should handle gracefully with consistent messaging
            expect($exception->getMessage())->toContain("Invalid participant count: $count");
            expect($exception->getMessage())->toContain('Must be at least 2');

            /** @var InvalidConfigurationException $exception */
            $context = $exception->getContext();
            expect($context['participant_count'])->toBe($count);
            expect($context['minimum_required'])->toBe(2);
        }
    });

    // Tests that factory methods handle empty and null constraint names
    it('handles empty and null constraint names', function (): void {
        // Given: Various empty/null constraint cases
        $emptyCases = ['', '   ', "\t\n"];

        foreach ($emptyCases as $constraintName) {
            // When: Creating constraint violation with empty name
            $exception = SchedulingException::constraintViolation($constraintName);

            // Then: Should handle gracefully
            expect($exception->getMessage())->toBe("Constraint violation: $constraintName");
            /** @var InvalidConfigurationException $exception */
            expect($exception->getContext()['constraint'])->toBe($constraintName);
        }
    });

    // Tests that factory methods handle empty reason strings gracefully
    it('handles empty reason strings gracefully', function (): void {
        // Given: Various empty reason cases
        $emptyCases = ['', '   ', "\t\n"];

        foreach ($emptyCases as $reason) {
            // When: Creating invalid schedule with empty reason
            $exception = SchedulingException::invalidSchedule($reason);

            // Then: Should handle gracefully
            expect($exception->getMessage())->toBe("Invalid schedule: $reason");
            /** @var InvalidConfigurationException $exception */
            expect($exception->getContext()['reason'])->toBe($reason);
        }
    });

    // Tests that SchedulingException is abstract and requires getDiagnosticReport implementation
    it('is an abstract class requiring getDiagnosticReport implementation', function (): void {
        // Given: SchedulingException class
        $reflection = new ReflectionClass(SchedulingException::class);

        // When: Checking class properties
        // Then: Should be abstract
        expect($reflection->isAbstract())->toBeTrue();

        // And should have abstract getDiagnosticReport method
        $method = $reflection->getMethod('getDiagnosticReport');
        expect($method->isAbstract())->toBeTrue();
    });

    // Tests that all factory methods create exceptions with getDiagnosticReport capability
    it('factory methods create exceptions with diagnostic capabilities', function (): void {
        // Given: Exceptions from all factory methods
        $exceptions = [
            SchedulingException::invalidParticipantCount(1),
            SchedulingException::constraintViolation('test constraint'),
            SchedulingException::invalidSchedule('test reason'),
        ];

        foreach ($exceptions as $exception) {
            // When: Calling getDiagnosticReport
            $report = $exception->getDiagnosticReport();

            // Then: Should return meaningful diagnostic information
            expect($report)->toBeString();
            expect($report)->not->toBeEmpty();
            expect($report)->toContain('DIAGNOSTIC REPORT');
        }
    });

    // Tests that factory methods preserve standard exception functionality
    it('factory methods preserve standard exception functionality', function (): void {
        // Given: Exception from factory method
        $exception = SchedulingException::invalidParticipantCount(1);

        // When: Using standard exception methods
        // Then: Should work as expected
        expect($exception->getMessage())->toBeString();
        expect($exception->getMessage())->not->toBeEmpty();
        expect($exception->getCode())->toBe(0); // Default code
        expect($exception->getPrevious())->toBeNull(); // No previous exception
        expect($exception->getFile())->toBeString();
        expect($exception->getLine())->toBeGreaterThan(0);
        expect($exception->getTrace())->toBeArray();
        expect($exception->__toString())->toContain($exception->getMessage());
    });

    // Tests realistic factory method usage scenarios
    it('handles realistic factory method usage scenarios', function (): void {
        // Given: Realistic scheduling scenarios

        // Scenario 1: Single participant tournament
        $singleParticipantException = SchedulingException::invalidParticipantCount(1);
        expect($singleParticipantException->getDiagnosticReport())->toContain('participant_count: 1');
        expect($singleParticipantException->getDiagnosticReport())->toContain('minimum_required: 2');

        // Scenario 2: Complex constraint violation
        $complexConstraint = 'ConsecutiveRoleConstraint: Player Alice would exceed maximum consecutive home games (3) in round 4';
        $constraintException = SchedulingException::constraintViolation($complexConstraint);
        /** @var InvalidConfigurationException $constraintException */
        expect($constraintException->getContext()['constraint'])->toBe($complexConstraint);
        expect($constraintException->getDiagnosticReport())->toContain($complexConstraint);

        // Scenario 3: Schedule validation failure
        $validationReason = 'Generated schedule has 8 events but expected 12 for 4 participants with 2 legs';
        $scheduleException = SchedulingException::invalidSchedule($validationReason);
        /** @var InvalidConfigurationException $scheduleException */
        expect($scheduleException->getContext()['reason'])->toBe($validationReason);
        expect($scheduleException->getDiagnosticReport())->toContain($validationReason);
    });

    // Tests that factory methods work with large numbers
    it('handles large participant counts in factory methods', function (): void {
        // Given: Large participant counts
        $largeCounts = [1000000, PHP_INT_MAX, 0];

        foreach ($largeCounts as $count) {
            // When: Creating exception with large count
            $exception = SchedulingException::invalidParticipantCount($count);

            // Then: Should handle without overflow or errors
            /** @var InvalidConfigurationException $exception */
            expect($exception->getContext()['participant_count'])->toBe($count);
            expect($exception->getMessage())->toContain("Invalid participant count: $count");
        }
    });

    // Tests that factory method exceptions have proper inheritance chain
    it('factory method exceptions have proper inheritance chain', function (): void {
        // Given: Exception from factory method
        $exception = SchedulingException::invalidParticipantCount(1);

        // When: Checking inheritance
        // Then: Should have complete inheritance chain
        expect($exception)->toBeInstanceOf(InvalidConfigurationException::class);
        expect($exception)->toBeInstanceOf(SchedulingException::class);
        expect($exception)->toBeInstanceOf(\Exception::class);
        expect($exception)->toBeInstanceOf(\Throwable::class);
    });
});
