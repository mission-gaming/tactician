<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Diagnostics;

/**
 * Comprehensive report on scheduling failure analysis.
 *
 * This value object contains detailed diagnostic information about
 * why a tournament schedule could not be generated, along with
 * actionable suggestions for resolving the issues.
 */
readonly class DiagnosticReport
{
    /**
     * @param int $participantCount Number of participants in the tournament
     * @param int $expectedEvents Expected total number of events
     * @param int $generatedEvents Actual number of events generated before failure
     * @param int $missingEvents Number of events that could not be generated
     * @param array<string> $missingPairings Specific pairings that are missing
     * @param array<string> $constraintViolations Constraint violations found
     * @param array<string> $impossiblePairings Pairings that cannot be satisfied
     * @param array<string> $suggestions Actionable suggestions for resolution
     * @param array<string, mixed> $analysisContext Additional analysis context
     */
    public function __construct(
        private int $participantCount,
        private int $expectedEvents,
        private int $generatedEvents,
        private int $missingEvents,
        private array $missingPairings = [],
        private array $constraintViolations = [],
        private array $impossiblePairings = [],
        private array $suggestions = [],
        private array $analysisContext = []
    ) {
    }

    /**
     * Get the number of participants in the tournament.
     */
    public function getParticipantCount(): int
    {
        return $this->participantCount;
    }

    /**
     * Get the expected total number of events.
     */
    public function getExpectedEvents(): int
    {
        return $this->expectedEvents;
    }

    /**
     * Get the actual number of events generated before failure.
     */
    public function getGeneratedEvents(): int
    {
        return $this->generatedEvents;
    }

    /**
     * Get the number of events that could not be generated.
     */
    public function getMissingEvents(): int
    {
        return $this->missingEvents;
    }

    /**
     * Get specific pairings that are missing.
     * @return array<string>
     */
    public function getMissingPairings(): array
    {
        return $this->missingPairings;
    }

    /**
     * Get constraint violations found during analysis.
     * @return array<string>
     */
    public function getConstraintViolations(): array
    {
        return $this->constraintViolations;
    }

    /**
     * Get pairings that cannot be satisfied with current constraints.
     * @return array<string>
     */
    public function getImpossiblePairings(): array
    {
        return $this->impossiblePairings;
    }

    /**
     * Get actionable suggestions for resolving the scheduling issues.
     * @return array<string>
     */
    public function getSuggestions(): array
    {
        return $this->suggestions;
    }

    /**
     * Get additional analysis context.
     * @return array<string, mixed>
     */
    public function getAnalysisContext(): array
    {
        return $this->analysisContext;
    }

    /**
     * Get a specific piece of analysis context.
     */
    public function getContextValue(string $key, mixed $default = null): mixed
    {
        return $this->analysisContext[$key] ?? $default;
    }

    /**
     * Get the completion percentage of the schedule.
     */
    public function getCompletionPercentage(): float
    {
        if ($this->expectedEvents === 0) {
            return 0.0;
        }

        return ($this->generatedEvents / $this->expectedEvents) * 100.0;
    }

    /**
     * Check if the scheduling was successful.
     */
    public function isSuccessful(): bool
    {
        return $this->missingEvents === 0 && empty($this->constraintViolations);
    }

    /**
     * Check if there are critical issues that prevent scheduling.
     */
    public function hasCriticalIssues(): bool
    {
        return !empty($this->impossiblePairings) ||
               !empty($this->constraintViolations) ||
               $this->missingEvents > ($this->expectedEvents / 2);
    }

    /**
     * Get a summary of the diagnostic analysis.
     */
    public function getSummary(): string
    {
        if ($this->isSuccessful()) {
            return 'Schedule generation completed successfully.';
        }

        $completionPercentage = (int) $this->getCompletionPercentage();
        $summary = "Schedule generation failed at {$completionPercentage}% completion. ";

        if ($this->missingEvents > 0) {
            $summary .= "{$this->missingEvents} events could not be generated. ";
        }

        if (!empty($this->constraintViolations)) {
            $violationCount = count($this->constraintViolations);
            $summary .= "{$violationCount} constraint violations detected. ";
        }

        if (!empty($this->impossiblePairings)) {
            $impossibleCount = count($this->impossiblePairings);
            $summary .= "{$impossibleCount} impossible pairings identified. ";
        }

        return trim($summary);
    }

    /**
     * Get the diagnostic report as a formatted string.
     */
    public function toString(): string
    {
        $output = [];
        $output[] = '=== SCHEDULING DIAGNOSTIC REPORT ===';
        $output[] = '';
        $output[] = 'Tournament Configuration:';
        $output[] = "  Participants: {$this->participantCount}";
        $output[] = "  Expected Events: {$this->expectedEvents}";
        $output[] = "  Generated Events: {$this->generatedEvents}";
        $output[] = "  Missing Events: {$this->missingEvents}";
        $output[] = '  Completion: ' . number_format($this->getCompletionPercentage(), 1) . '%';
        $output[] = '';

        if (!empty($this->missingPairings)) {
            $output[] = 'Missing Pairings:';
            foreach (array_slice($this->missingPairings, 0, 10) as $pairing) {
                $output[] = "  - {$pairing}";
            }
            if (count($this->missingPairings) > 10) {
                $remaining = count($this->missingPairings) - 10;
                $output[] = "  ... and {$remaining} more";
            }
            $output[] = '';
        }

        if (!empty($this->constraintViolations)) {
            $output[] = 'Constraint Violations:';
            foreach ($this->constraintViolations as $violation) {
                $output[] = "  - {$violation}";
            }
            $output[] = '';
        }

        if (!empty($this->impossiblePairings)) {
            $output[] = 'Impossible Pairings:';
            foreach ($this->impossiblePairings as $pairing) {
                $output[] = "  - {$pairing}";
            }
            $output[] = '';
        }

        if (!empty($this->suggestions)) {
            $output[] = 'Suggestions:';
            foreach ($this->suggestions as $suggestion) {
                $output[] = "  - {$suggestion}";
            }
            $output[] = '';
        }

        return implode("\n", $output);
    }
}
