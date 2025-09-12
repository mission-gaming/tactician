<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;

describe('InvalidConfigurationException', function (): void {
    beforeEach(function (): void {
        $this->participant1 = new Participant('p1', 'Alice');
        $this->participant2 = new Participant('p2', 'Bob');
    });

    // Tests that exception stores configuration issue and context correctly
    it('stores configuration issue and context correctly', function (): void {
        // Given: Configuration issue with context data
        $issue = 'Invalid participant count';
        $context = [
            'participant_count' => 1,
            'minimum_required' => 2,
            'provided_participants' => [$this->participant1],
        ];

        // When: Creating exception with issue and context
        $exception = new InvalidConfigurationException($issue, $context);

        // Then: Should store all data correctly
        expect($exception->getConfigurationIssue())->toBe($issue);
        expect($exception->getContext())->toBe($context);
    });

    // Tests that exception formats array values showing item counts
    it('formats array values showing item counts', function (): void {
        // Given: Exception with array context values
        $context = [
            'participants' => [$this->participant1, $this->participant2],
            'empty_array' => [],
            'large_array' => range(1, 100),
        ];
        $exception = new InvalidConfigurationException('Test issue', $context);

        // When: Getting diagnostic report
        $report = $exception->getDiagnosticReport();

        // Then: Should format arrays with item counts
        expect($report)->toContain('participants: [2 items]');
        expect($report)->toContain('empty_array: [0 items]');
        expect($report)->toContain('large_array: [100 items]');
    });

    // Tests that exception formats object values showing class names
    it('formats object values showing class names', function (): void {
        // Given: Exception with object context values
        $context = [
            'participant' => $this->participant1,
            'datetime' => new DateTime(),
        ];
        $exception = new InvalidConfigurationException('Test issue', $context);

        // When: Getting diagnostic report
        $report = $exception->getDiagnosticReport();

        // Then: Should format objects with class names
        expect($report)->toContain('participant: MissionGaming\Tactician\DTO\Participant');
        expect($report)->toContain('datetime: DateTime');
    });

    // Tests that exception formats boolean, null, and scalar values correctly
    it('formats boolean, null, and scalar values correctly', function (): void {
        // Given: Exception with various data types
        $context = [
            'enabled' => true,
            'disabled' => false,
            'missing' => null,
            'count' => 42,
            'name' => 'test',
            'pi' => 3.14159,
        ];
        $exception = new InvalidConfigurationException('Test issue', $context);

        // When: Getting diagnostic report
        $report = $exception->getDiagnosticReport();

        // Then: Should format all types correctly
        expect($report)->toContain('enabled: true');
        expect($report)->toContain('disabled: false');
        expect($report)->toContain('missing: null');
        expect($report)->toContain('count: 42');
        expect($report)->toContain('name: test');
        expect($report)->toContain('pi: 3.14159');
    });

    // Tests that exception generates comprehensive requirements list
    it('generates comprehensive requirements list', function (): void {
        // Given: Exception with any configuration issue
        $exception = new InvalidConfigurationException('Test issue');

        // When: Getting diagnostic report
        $report = $exception->getDiagnosticReport();

        // Then: Should include all standard requirements
        expect($report)->toContain('REQUIREMENTS');
        expect($report)->toContain('Participants array must contain at least 2 participants');
        expect($report)->toContain('Legs must be a positive integer (≥ 1)');
        expect($report)->toContain('All participants must have unique IDs');
        expect($report)->toContain('Constraint set must be valid');
        expect($report)->toContain('Scheduler must support the requested configuration');
    });

    // Tests that exception produces diagnostic reports with all sections
    it('produces diagnostic reports with all sections', function (): void {
        // Given: Exception with context data
        $context = [
            'participant_count' => 1,
            'minimum_required' => 2,
        ];
        $exception = new InvalidConfigurationException('Invalid participant count', $context);

        // When: Getting diagnostic report
        $report = $exception->getDiagnosticReport();

        // Then: Should contain all expected sections
        expect($report)->toContain('INVALID CONFIGURATION DIAGNOSTIC REPORT');
        expect($report)->toContain('Issue: Invalid participant count');
        expect($report)->toContain('CONFIGURATION DETAILS');
        expect($report)->toContain('participant_count: 1');
        expect($report)->toContain('minimum_required: 2');
        expect($report)->toContain('REQUIREMENTS');
    });

    // Tests that exception uses default messages when none provided
    it('uses default messages when none provided', function (): void {
        // Given: Exception without custom message
        $issue = 'Invalid participant count';
        $exception = new InvalidConfigurationException($issue);

        // When: Getting exception message
        $message = $exception->getMessage();

        // Then: Should use formatted default message
        expect($message)->toBe('Invalid scheduler configuration: Invalid participant count');
    });

    // Tests that exception uses custom messages when provided
    it('uses custom messages when provided', function (): void {
        // Given: Exception with custom message
        $customMessage = 'Custom error message for this specific case';
        $exception = new InvalidConfigurationException(
            'Some issue',
            [],
            $customMessage
        );

        // When: Getting exception message
        $message = $exception->getMessage();

        // Then: Should use custom message
        expect($message)->toBe($customMessage);
    });

    // Tests that exception handles empty context gracefully
    it('handles empty context gracefully', function (): void {
        // Given: Exception with empty context
        $exception = new InvalidConfigurationException('Test issue', []);

        // When: Getting diagnostic report
        $report = $exception->getDiagnosticReport();

        // Then: Should handle gracefully without configuration details section
        expect($report)->toContain('INVALID CONFIGURATION DIAGNOSTIC REPORT');
        expect($report)->toContain('Issue: Test issue');
        expect($report)->not->toContain('CONFIGURATION DETAILS');
        expect($report)->toContain('REQUIREMENTS');
    });

    // Tests that exception handles complex nested context data
    it('handles complex nested context data', function (): void {
        // Given: Exception with complex nested context
        $context = [
            'config' => [
                'nested' => [
                    'deep' => 'value',
                ],
            ],
            'participants' => [$this->participant1, $this->participant2],
            'settings' => (object) ['timeout' => 30],
        ];
        $exception = new InvalidConfigurationException('Complex issue', $context);

        // When: Getting diagnostic report
        $report = $exception->getDiagnosticReport();

        // Then: Should handle all complex types appropriately
        expect($report)->toContain('config: [1 items]'); // Nested array shown as item count
        expect($report)->toContain('participants: [2 items]');
        expect($report)->toContain('settings: stdClass'); // Object shown as class name
    });

    // Tests that exception provides meaningful diagnostic information for real scenarios
    it('provides meaningful diagnostic information for real scenarios', function (): void {
        // Given: Real-world scenario with invalid participant count
        $context = [
            'participant_count' => 1,
            'minimum_required' => 2,
            'provided_participants' => [$this->participant1],
            'algorithm' => 'Round Robin',
        ];
        $exception = new InvalidConfigurationException(
            'Cannot run Round Robin with only 1 participant',
            $context
        );

        // When: Getting diagnostic report
        $report = $exception->getDiagnosticReport();

        // Then: Should provide actionable information
        expect($report)->toContain('Cannot run Round Robin with only 1 participant');
        expect($report)->toContain('participant_count: 1');
        expect($report)->toContain('minimum_required: 2');
        expect($report)->toContain('algorithm: Round Robin');
        expect($report)->toContain('provided_participants: [1 items]');
        expect($report)->toContain('Participants array must contain at least 2 participants');
    });

    // Tests that exception handles inheritance properly
    it('extends SchedulingException correctly', function (): void {
        // Given: InvalidConfigurationException instance
        $exception = new InvalidConfigurationException('Test issue');

        // When: Checking inheritance
        // Then: Should be instance of SchedulingException
        expect($exception)->toBeInstanceOf(\MissionGaming\Tactician\Exceptions\SchedulingException::class);
        expect($exception)->toBeInstanceOf(\Exception::class);
    });

    // Tests that exception supports error codes and previous exceptions
    it('supports error codes and previous exceptions', function (): void {
        // Given: Previous exception
        $previousException = new \InvalidArgumentException('Previous error');
        $errorCode = 1001;

        // When: Creating exception with code and previous exception
        $exception = new InvalidConfigurationException(
            'Configuration issue',
            [],
            'Custom message',
            $errorCode,
            $previousException
        );

        // Then: Should preserve code and previous exception
        expect($exception->getCode())->toBe($errorCode);
        expect($exception->getPrevious())->toBe($previousException);
    });
});
