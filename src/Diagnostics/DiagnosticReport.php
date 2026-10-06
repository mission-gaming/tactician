<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Diagnostics;

/**
 * The analysis of a schedule that could not be completed: how far
 * generation got, which pairings are missing, which constraints reject
 * them, and suggestions.
 *
 * The round-robin scheduler builds one when generation fails under
 * constraints; `IncompleteScheduleException::getAnalysis()` returns it. The
 * analysis asks whether each missing pairing could join the schedule that
 * was generated. It does not prove that no schedule exists.
 *
 * The lists hold lines of text for people. Their wording is not stable.
 *
 * @experimental
 */
readonly class DiagnosticReport
{
    /**
     * Nothing is validated, and the counts are not checked against each
     * other or against the lists.
     *
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
    ) {}

    /**
     * The number of participants the scheduler was given.
     */
    public function getParticipantCount(): int
    {
        return $this->participantCount;
    }

    /**
     * The number of events the stage plan expects, over all legs. 0 when
     * the plan cannot know its event count up front.
     */
    public function getExpectedEvents(): int
    {
        return $this->expectedEvents;
    }

    /**
     * The number of events generated before the failure, in the ordering
     * the report is about (the last one tried).
     */
    public function getGeneratedEvents(): int
    {
        return $this->generatedEvents;
    }

    /**
     * The expected events minus the generated ones, never below 0.
     */
    public function getMissingEvents(): int
    {
        return $this->missingEvents;
    }

    /**
     * The pairings without a meeting, one line per pairing and leg, by
     * participant label: `A vs B (Leg 2)`. In the order of the participant
     * list, then by leg. Empty for a plan that does not state how often
     * each pair meets (Swiss, elimination).
     *
     * @return array<string>
     */
    public function getMissingPairings(): array
    {
        return $this->missingPairings;
    }

    /**
     * What each constraint rejects, one line per constraint name:
     * `<name> rejects A vs B in 3 of 10 rounds; A vs C in ...`. A round is
     * counted for a constraint when it rejects the missing pairing there in
     * both role orders.
     *
     * These are findings of the analysis, not the rejections recorded
     * during generation: those are `ConstraintViolation` objects in the
     * exception's violation collector.
     *
     * @return array<string>
     */
    public function getConstraintViolations(): array
    {
        return $this->constraintViolations;
    }

    /**
     * The missing pairings that the constraints reject in every round and
     * both role orders, one line each, naming the constraints that reject
     * them everywhere. "Impossible" means that the pairing cannot join the
     * schedule that was generated, not that no schedule holds it.
     *
     * @return array<string>
     */
    public function getImpossiblePairings(): array
    {
        return $this->impossiblePairings;
    }

    /**
     * Suggestions for the caller, one line each, among them every pairing
     * that the constraints allow only in rounds that are already full.
     *
     * @return array<string>
     */
    public function getSuggestions(): array
    {
        return $this->suggestions;
    }

    /**
     * Further values the builder of the report attached, keyed by name.
     * The library attaches none, so a report from a scheduler has an empty
     * context.
     *
     * @return array<string, mixed>
     */
    public function getAnalysisContext(): array
    {
        return $this->analysisContext;
    }

    /**
     * One value of the analysis context, or the default when the key is
     * missing or holds null.
     */
    public function getContextValue(string $key, mixed $default = null): mixed
    {
        return $this->analysisContext[$key] ?? $default;
    }

    /**
     * The generated events as a percentage of the expected ones, from 0.0
     * to 100.0 for consistent counts. 0.0 when no events are expected.
     */
    public function getCompletionPercentage(): float
    {
        if ($this->expectedEvents === 0) {
            return 0.0;
        }

        return ($this->generatedEvents / $this->expectedEvents) * 100.0;
    }

    /**
     * True when no event is missing and the analysis attributes nothing to
     * a constraint. It reads the count and the list as the report was
     * given them.
     */
    public function isSuccessful(): bool
    {
        return $this->missingEvents === 0 && $this->constraintViolations === [];
    }

    /**
     * True when a pairing is impossible, a constraint is charged with a
     * rejection, or more than half of the expected events are missing.
     */
    public function hasCriticalIssues(): bool
    {
        return $this->impossiblePairings !== []
               || $this->constraintViolations !== []
               || $this->missingEvents > ($this->expectedEvents / 2);
    }

    /**
     * One line of text: the completion percentage, rounded down, and the
     * numbers of missing events, attributed constraints and impossible
     * pairings that are not zero.
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

        if ($this->constraintViolations !== []) {
            $violationCount = count($this->constraintViolations);
            $summary .= "{$violationCount} constraint violations detected. ";
        }

        if ($this->impossiblePairings !== []) {
            $impossibleCount = count($this->impossiblePairings);
            $summary .= "{$impossibleCount} impossible pairings identified. ";
        }

        return trim($summary);
    }

    /**
     * The whole report as text, lines separated by "\n": the counts, then
     * each list that is not empty. Only the first 10 missing pairings are
     * written out, followed by how many more there are.
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

        if ($this->missingPairings !== []) {
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

        if ($this->constraintViolations !== []) {
            $output[] = 'Constraint Violations:';
            foreach ($this->constraintViolations as $violation) {
                $output[] = "  - {$violation}";
            }
            $output[] = '';
        }

        if ($this->impossiblePairings !== []) {
            $output[] = 'Impossible Pairings:';
            foreach ($this->impossiblePairings as $pairing) {
                $output[] = "  - {$pairing}";
            }
            $output[] = '';
        }

        if ($this->suggestions !== []) {
            $output[] = 'Suggestions:';
            foreach ($this->suggestions as $suggestion) {
                $output[] = "  - {$suggestion}";
            }
            $output[] = '';
        }

        return implode("\n", $output);
    }
}
