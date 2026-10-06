<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Exceptions;

/**
 * Thrown when a configuration cannot work: a scheduler, an engine, a stage,
 * a timeline, a session grid or a repack request was given values it has to
 * reject before it can do anything.
 *
 * Three things say what went wrong, each for a different reader:
 *
 * - `getReason()` is the kind of mistake, as an enum case code can branch
 *   on without matching text;
 * - `getContext()` is the values involved, keyed by name;
 * - `getDiagnosticReport()` is both as text for an operator, with every
 *   context value written out.
 */
class InvalidConfigurationException extends SchedulingException
{
    /**
     * What a round-robin configuration has to satisfy. The round-robin
     * scheduler, its options and its plan attach this list to their errors,
     * and the diagnostic report prints it under "REQUIREMENTS".
     *
     * @var list<string>
     */
    public const array ROUND_ROBIN_REQUIREMENTS = [
        'Participants array must contain at least 2 participants',
        'Legs must be a positive integer (≥ 1)',
        'All participants must have unique IDs',
        'Constraint set must be valid',
        'Scheduler must support the requested configuration',
    ];

    /**
     * The most entries of one list the diagnostic report writes out. A
     * longer list is cut after this many, and the report says how many
     * entries it left out.
     */
    private const int REPORT_LIST_LIMIT = 20;

    /**
     * How many levels of nested lists the diagnostic report writes out.
     * A list below that depth is reported by its size only.
     */
    private const int REPORT_NESTING_LIMIT = 3;

    /** @var list<string> */
    private readonly array $requirements;

    /**
     * The first five parameters are the constructor as it has always been;
     * `$reason` and `$requirements` were added after them and are optional,
     * so pass them by name.
     *
     * @param string $configurationIssue What is wrong, in one sentence
     * @param array<string, mixed> $context The values involved, keyed by name
     * @param string $message The exception message; empty for "Invalid scheduler
     *                        configuration: " followed by the issue
     * @param ?InvalidConfigurationReason $reason The kind of mistake. The library sets one
     *                                            everywhere; null is for code outside the library
     *                                            that builds the exception without it
     * @param ?list<string> $requirements What the failing component requires, one statement per
     *                                    entry, for the "REQUIREMENTS" block of the report. With
     *                                    a reason and no requirements the report has no such
     *                                    block. With neither, the exception was built the way
     *                                    it was before reasons existed, and the report keeps the
     *                                    block it carried then ({@see self::ROUND_ROBIN_REQUIREMENTS})
     */
    public function __construct(
        private readonly string $configurationIssue,
        private readonly array $context = [],
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        private readonly ?InvalidConfigurationReason $reason = null,
        ?array $requirements = null
    ) {
        if ($message === '') {
            $message = sprintf('Invalid scheduler configuration: %s', $this->configurationIssue);
        }

        // Neither a reason nor requirements: a call written before either
        // existed, which keeps the block every report carried then.
        $requirements ??= $reason === null ? self::ROUND_ROBIN_REQUIREMENTS : [];
        $this->requirements = array_values($requirements);

        parent::__construct($message, $code, $previous);
    }

    public function getConfigurationIssue(): string
    {
        return $this->configurationIssue;
    }

    /**
     * The kind of mistake, for code that has to tell configuration errors
     * apart without reading the message.
     *
     * @return ?InvalidConfigurationReason Null when the exception was built without a reason,
     *                                     which only code outside the library does
     */
    public function getReason(): ?InvalidConfigurationReason
    {
        return $this->reason;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * What the failing component requires, one statement per entry. Empty
     * when the component states none.
     *
     * @return list<string>
     */
    public function getRequirements(): array
    {
        return $this->requirements;
    }

    /**
     * The issue, every context value, and the requirements of the failing
     * component when it states any.
     *
     * A context value that is a list is written out in full, in the order
     * it has, with strings in double quotes: `event_ids: ["e1", "e2"]`.
     * Inside the quotes a double quote and a backslash are written with a
     * backslash before them, and a control character as its C escape (`\n`,
     * `\t`, `\000` for a NUL byte), so one entry is always one quoted run on
     * one line. A string that is a context value itself, outside any list,
     * is written as it is. Keys are written where the array is not a list:
     * `[from: "2026-01-01", to: "2026-01-02"]`. An object is written as its
     * class name (an anonymous class as `class@anonymous`, or the name of
     * its parent or first interface before `@anonymous`). Two bounds keep the report readable: a list longer than
     * {@see self::REPORT_LIST_LIMIT} entries is cut there and followed by
     * the number left out (`... 80 more of 100`), and a list nested deeper
     * than {@see self::REPORT_NESTING_LIMIT} levels is written as its size
     * (`[2 items]`). An empty list is `[0 items]`.
     */
    #[\Override]
    public function getDiagnosticReport(): string
    {
        $report = [];
        $report[] = '=== INVALID CONFIGURATION DIAGNOSTIC REPORT ===';
        $report[] = '';
        $report[] = sprintf('Issue: %s', $this->configurationIssue);

        if ($this->context !== []) {
            $report[] = '';
            $report[] = '=== CONFIGURATION DETAILS ===';
            foreach ($this->context as $key => $value) {
                $report[] = sprintf('• %s: %s', $key, $this->formatValue($value, 0));
            }
        }

        if ($this->requirements !== []) {
            $report[] = '';
            $report[] = '=== REQUIREMENTS ===';
            foreach ($this->requirements as $requirement) {
                $report[] = sprintf('• %s', $requirement);
            }
        }

        return implode("\n", $report);
    }

    /**
     * @param int $depth How many lists enclose the value; 0 for a context value itself
     */
    private function formatValue(mixed $value, int $depth): string
    {
        if (is_array($value)) {
            return $this->formatList($value, $depth + 1);
        }

        if (is_object($value)) {
            // Not `$value::class`: the name of an anonymous class holds a NUL
            // byte and the path of the file that declares it, so the report
            // would differ from one machine to the next.
            return get_debug_type($value);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_null($value)) {
            return 'null';
        }

        if (is_string($value) && $depth > 0) {
            // Inside a list a string is quoted, so that an empty one and one
            // holding a comma can still be told apart from their neighbours.
            // A quote or a backslash in it is escaped, or the string could
            // close itself and read as two entries; a control character is
            // escaped so that an entry cannot break the line it is on.
            return '"' . addcslashes($value, "\0..\37\177\\\"") . '"';
        }

        if (is_float($value) && is_nan($value)) {
            // PHP 8.5 warns when NAN is cast to a string. The text is the
            // one the cast gives.
            return 'NAN';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        // Only a resource, open or closed, reaches this line. print_r()
        // gives the text the string cast gives: "Resource id #5".
        return print_r($value, true);
    }

    /**
     * @param array<mixed> $list
     * @param int $depth The nesting level of this list; 1 for a context value
     */
    private function formatList(array $list, int $depth): string
    {
        $total = count($list);
        if ($total === 0 || $depth > self::REPORT_NESTING_LIMIT) {
            return sprintf('[%d items]', $total);
        }

        $withKeys = !array_is_list($list);
        $entries = [];
        foreach (array_slice($list, 0, self::REPORT_LIST_LIMIT, true) as $key => $entry) {
            $text = $this->formatValue($entry, $depth);
            $entries[] = $withKeys ? sprintf('%s: %s', $key, $text) : $text;
        }

        if ($total > self::REPORT_LIST_LIMIT) {
            $entries[] = sprintf('... %d more of %d', $total - self::REPORT_LIST_LIMIT, $total);
        }

        return '[' . implode(', ', $entries) . ']';
    }
}
