<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;

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

    // Tests that a list in the context reaches the reader entry by entry:
    // a count alone ("[2 items]") tells an operator nothing to act on
    it('writes out the entries of a list value', function (): void {
        // Given: Exception with list context values
        $context = [
            'event_ids' => ['e1', 'e2'],
            'rounds' => [3, 1, 2],
            'participants' => [$this->participant1, $this->participant2],
            'empty_array' => [],
        ];
        $exception = new InvalidConfigurationException('Test issue', $context);

        // When: Getting diagnostic report
        $report = $exception->getDiagnosticReport();

        // Then: Every entry is there, in the order given, strings quoted
        expect($report)->toContain("• event_ids: [\"e1\", \"e2\"]\n");
        expect($report)->toContain("• rounds: [3, 1, 2]\n");
        expect($report)->toContain(
            '• participants: [MissionGaming\Tactician\DTO\Participant, MissionGaming\Tactician\DTO\Participant]'
        );
        expect($report)->toContain("• empty_array: [0 items]\n");
        expect($report)->not->toContain('[2 items]');
    });

    // Tests the bound on a long list: the first REPORT_LIST_LIMIT entries,
    // then how many were left out and of how many
    it('cuts a long list at the limit and says how many entries it left out', function (): void {
        // Given: A list exactly at the limit and lists beyond it
        $limit = InvalidConfigurationException::REPORT_LIST_LIMIT;
        $exception = new InvalidConfigurationException('Test issue', [
            'at_limit' => range(1, $limit),
            'one_over' => range(1, $limit + 1),
            'large_array' => range(1, 100),
        ]);

        // When: Getting diagnostic report
        $report = $exception->getDiagnosticReport();

        // Then: The limit is 20, a list at it is whole, a longer one is cut
        expect($limit)->toBe(20);
        expect($report)->toContain('• at_limit: [' . implode(', ', range(1, 20)) . "]\n");
        expect($report)->toContain('• one_over: [' . implode(', ', range(1, 20)) . ", ... 1 more of 21]\n");
        expect($report)->toContain('• large_array: [' . implode(', ', range(1, 20)) . ", ... 80 more of 100]\n");
    });

    // Tests the bound on nesting: REPORT_NESTING_LIMIT levels are written
    // out, and a list below them is reported by its size
    it('writes nested lists out to the nesting limit and counts what is deeper', function (): void {
        // Given: Lists nested three and four levels deep
        $exception = new InvalidConfigurationException('Test issue', [
            'three_levels' => [[[1, 2]]],
            'four_levels' => [[[[1, 2], [3]]]],
        ]);

        // When: Getting diagnostic report
        $report = $exception->getDiagnosticReport();

        // Then: The third level is written, the fourth is a count
        expect(InvalidConfigurationException::REPORT_NESTING_LIMIT)->toBe(3);
        expect($report)->toContain("• three_levels: [[[1, 2]]]\n");
        expect($report)->toContain("• four_levels: [[[[2 items], [1 items]]]]\n");
    });

    // Tests that the same context gives the same report text every time,
    // and that keys are written where the array is not a list
    it('writes keyed values with their keys, in the order given', function (): void {
        // Given: A keyed array, and a list of keyed arrays as fromArray() data has them
        $context = [
            'window' => ['to' => '2026-01-02', 'from' => '2026-01-01'],
            'windows' => [['from' => 'a', 'label' => null], ['from' => 'b', 'closed' => true]],
            'sparse' => [2 => 'x', 5 => ''],
        ];

        // When: Getting the report twice
        $first = (new InvalidConfigurationException('Test issue', $context))->getDiagnosticReport();
        $second = (new InvalidConfigurationException('Test issue', $context))->getDiagnosticReport();

        // Then: Keys are kept, order is the array's own, and the text repeats
        expect($first)->toContain("• window: [to: \"2026-01-02\", from: \"2026-01-01\"]\n");
        expect($first)->toContain("• windows: [[from: \"a\", label: null], [from: \"b\", closed: true]]\n");
        expect($first)->toContain("• sparse: [2: \"x\", 5: \"\"]\n");
        expect($second)->toBe($first);
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

    // Tests the text of each kind of value at the edges: what the string
    // cast gives for a scalar, the entries of a list, a count for an empty
    // one, and the class name for an object even when the object can be a string
    it('formats a context value by its type', function (mixed $value, string $expected): void {
        $exception = new InvalidConfigurationException('Test issue', ['value' => $value]);

        expect($exception->getDiagnosticReport())->toContain("• value: {$expected}\n");
    })->with([
        'zero' => [0, '0'],
        'a negative integer' => [-3, '-3'],
        'a whole float' => [2.0, '2'],
        'negative zero' => [-0.0, '-0'],
        'a float beyond the precision' => [0.1 + 0.2, '0.3'],
        'a large float' => [1e100, '1.0E+100'],
        'infinity' => [INF, 'INF'],
        // PHP 8.5 warns when NAN is cast to a string; the report must not
        'not a number' => [NAN, 'NAN'],
        'an empty string' => ['', ''],
        'the string zero' => ['0', '0'],
        'a string of digits' => ['007', '007'],
        'an empty array' => [[], '[0 items]'],
        'a nested array' => [[[1, 2], [3]], '[[1, 2], [3]]'],
        'a list of strings' => [['a', '', 'b, c'], '["a", "", "b, c"]'],
        // One entry stays one quoted run on one line, whatever it holds: a
        // quote in it cannot close it, and a line break cannot split the report
        'a string that holds the list separator and quotes' => [['a", "b'], '["a\", \"b"]'],
        'a string that holds a backslash' => [['a\\', 'b'], '["a\\\\", "b"]'],
        'a string that holds a line break and a tab' => [["one\ntwo\tthree\r"], '["one\ntwo\tthree\r"]'],
        'a string that holds a NUL byte' => [["Europe/Lon\0don"], '["Europe/Lon\000don"]'],
        'a string that is not UTF-8' => [["caf\xE9"], "[\"caf\xE9\"]"],
        'a list of numbers, booleans and null' => [[1, -2, 1.5, true, false, null], '[1, -2, 1.5, true, false, null]'],
        'a list of floats that are not finite' => [[NAN, INF, -INF], '[NAN, INF, -INF]'],
        // The internal name of an anonymous class holds a NUL byte and the path
        // of the file that declares it; the report must not differ by machine
        'an object of an anonymous class' => [new class {}, 'class@anonymous'],
        'an object of an anonymous class that extends one' => [new class extends ArrayObject {}, 'ArrayObject@anonymous'],
        'a list that holds an object of an anonymous class' => [[new class {}, new stdClass()], '[class@anonymous, stdClass]'],
        // Pest calls a closure it finds in a dataset, so this one returns the value.
        'a closure' => [fn(): Closure => fn(): int => 1, Closure::class],
        'an object that can be a string' => [new MissionGaming\Tactician\DTO\Round(2), MissionGaming\Tactician\DTO\Round::class],
        'an enum case' => [Random\IntervalBoundary::ClosedOpen, Random\IntervalBoundary::class],
    ]);

    // Tests that a resource in the context is printed as PHP's string cast
    // prints it, open or closed
    it('formats a resource value as the string cast does', function (): void {
        // Given: Context holding an open and a closed resource
        $open = fopen('php://memory', 'r');
        $closed = fopen('php://memory', 'r');

        if ($open === false || $closed === false) {
            throw new RuntimeException('Could not open a memory stream.');
        }

        fclose($closed);

        try {
            $exception = new InvalidConfigurationException('Test issue', ['open' => $open, 'closed' => $closed]);

            // When: Getting diagnostic report
            $report = $exception->getDiagnosticReport();

            // Then: Each is printed as "Resource id #N"
            expect($report)->toContain('open: ' . (string) $open)
                ->and($report)->toContain('closed: ' . (string) $closed)
                ->and((string) $open)->toStartWith('Resource id #')
                ->and((string) $closed)->toStartWith('Resource id #');
        } finally {
            fclose($open);
        }
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
        expect($report)->toContain('config: [nested: [deep: "value"]]'); // Nested array written out with its keys
        expect($report)->toContain('participants: [MissionGaming\Tactician\DTO\Participant, MissionGaming\Tactician\DTO\Participant]');
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
        expect($report)->toContain('provided_participants: [MissionGaming\Tactician\DTO\Participant]');
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

    // Tests that the reason is a typed value a caller can branch on, so
    // that nobody has to match the message text
    it('carries the reason it was given', function (): void {
        // Given: An exception built with a reason, by name after the issue and context
        $exception = new InvalidConfigurationException(
            'Timezone is not parseable',
            ['timezone' => 'Nowhere'],
            reason: InvalidConfigurationReason::UnparseableTime
        );

        // Then: The accessor returns the case, and the message is the default one
        expect($exception->getReason())->toBe(InvalidConfigurationReason::UnparseableTime);
        expect($exception->getMessage())->toBe('Invalid scheduler configuration: Timezone is not parseable');
    });

    // Tests the compatibility of the constructor: every call written before
    // the reason existed still works and behaves as it did
    it('is built without a reason by every call written before reasons existed', function (): void {
        // Given: The four shapes of call the old constructor allowed
        $previous = new RuntimeException('cause');
        $calls = [
            new InvalidConfigurationException('Issue'),
            new InvalidConfigurationException('Issue', ['key' => 1]),
            new InvalidConfigurationException('Issue', ['key' => 1], 'Message'),
            new InvalidConfigurationException('Issue', ['key' => 1], 'Message', 7, $previous),
        ];

        foreach ($calls as $exception) {
            // Then: No reason, and the requirements block it always carried
            expect($exception->getReason())->toBeNull();
            expect($exception->getRequirements())->toBe(InvalidConfigurationException::ROUND_ROBIN_REQUIREMENTS);
            expect($exception->getDiagnosticReport())->toEndWith(
                "\n\n=== REQUIREMENTS ===\n"
                . "• Participants array must contain at least 2 participants\n"
                . "• Legs must be a positive integer (≥ 1)\n"
                . "• All participants must have unique IDs\n"
                . "• Constraint set must be valid\n"
                . '• Scheduler must support the requested configuration'
            );
        }

        expect($calls[3]->getCode())->toBe(7);
        expect($calls[3]->getPrevious())->toBe($previous);
        expect($calls[3]->getMessage())->toBe('Message');
    });

    // Tests that the requirements block belongs to the failing component:
    // an error with a reason lists what it was given, and nothing when it
    // was given nothing. The round-robin list is not a default for it.
    it('lists no requirements for an error that has a reason and states none', function (): void {
        // Given: A timezone error, which has nothing to do with a round robin
        $exception = new InvalidConfigurationException(
            'start or its timezone is not parseable',
            ['start' => '2026-08-01 19:00:00', 'timezone' => 'Neverland/Nowhere'],
            reason: InvalidConfigurationReason::UnparseableTime
        );

        // When: Getting diagnostic report
        $report = $exception->getDiagnosticReport();

        // Then: The report ends with the details and has no requirements block
        expect($exception->getRequirements())->toBe([]);
        expect($report)->toBe(
            "=== INVALID CONFIGURATION DIAGNOSTIC REPORT ===\n"
            . "\n"
            . "Issue: start or its timezone is not parseable\n"
            . "\n"
            . "=== CONFIGURATION DETAILS ===\n"
            . "• start: 2026-08-01 19:00:00\n"
            . '• timezone: Neverland/Nowhere'
        );
    });

    // Tests that a subclass written before the reason existed, which passes
    // the five old parameters by position, is built as it was
    it('is built as before by a subclass that passes the old five parameters', function (): void {
        // Given: A subclass with a constructor of its own
        $previous = new RuntimeException('cause');
        $exception = new class ('Issue', $previous) extends InvalidConfigurationException {
            public function __construct(string $issue, Throwable $previous)
            {
                parent::__construct($issue, ['key' => 1], 'Message', 7, $previous);
            }
        };

        // Then: Everything it passed is there, with no reason and the block it always had
        expect($exception->getConfigurationIssue())->toBe('Issue');
        expect($exception->getContext())->toBe(['key' => 1]);
        expect($exception->getMessage())->toBe('Message');
        expect($exception->getCode())->toBe(7);
        expect($exception->getPrevious())->toBe($previous);
        expect($exception->getReason())->toBeNull();
        expect($exception->getRequirements())->toBe(InvalidConfigurationException::ROUND_ROBIN_REQUIREMENTS);
    });

    // Tests the bound on a keyed array: it is cut like a list, keys kept
    it('cuts a long keyed array at the limit', function (): void {
        $keyed = [];
        for ($i = 1; $i <= 21; ++$i) {
            $keyed["k{$i}"] = $i;
        }
        $exception = new InvalidConfigurationException('Issue', ['keyed' => $keyed]);

        $report = $exception->getDiagnosticReport();
        expect($report)->toContain('• keyed: [k1: 1, k2: 2,');
        expect($report)->toContain('k20: 20, ... 1 more of 21]');
        expect($report)->not->toContain('k21');
    });

    it('lists the requirements it was given, one bullet each', function (): void {
        // Given: An exception with requirements of its own, with and without a reason
        $requirements = ['A grid needs at least 1 session', 'Session starts must ascend'];
        $withReason = new InvalidConfigurationException(
            'Issue',
            reason: InvalidConfigurationReason::EmptyList,
            requirements: $requirements
        );
        $withoutReason = new InvalidConfigurationException('Issue', requirements: $requirements);
        $none = new InvalidConfigurationException('Issue', requirements: []);

        // Then: The block holds exactly those, and an empty list means no block
        foreach ([$withReason, $withoutReason] as $exception) {
            expect($exception->getRequirements())->toBe($requirements);
            expect($exception->getDiagnosticReport())->toBe(
                "=== INVALID CONFIGURATION DIAGNOSTIC REPORT ===\n"
                . "\n"
                . "Issue: Issue\n"
                . "\n"
                . "=== REQUIREMENTS ===\n"
                . "• A grid needs at least 1 session\n"
                . '• Session starts must ascend'
            );
        }

        expect($none->getRequirements())->toBe([]);
        expect($none->getDiagnosticReport())->not->toContain('REQUIREMENTS');
    });
});
