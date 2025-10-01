<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\LegStrategies;

/**
 * Report on whether a leg strategy can satisfy given constraints.
 *
 * This value object provides detailed analysis of constraint satisfiability
 * to enable early detection of impossible tournament configurations.
 */
readonly class ConstraintSatisfiabilityReport
{
    /**
     * @param bool $canSatisfy Whether the strategy can satisfy all constraints
     * @param array<string> $satisfiableConstraints List of constraints that can be satisfied
     * @param array<string> $unsatisfiableConstraints List of constraints that cannot be satisfied
     * @param array<string> $conflictingConstraints List of constraints that conflict with each other
     * @param array<string> $suggestions Suggestions for resolving constraint issues
     * @param array<string, mixed> $analysisData Additional analysis data
     */
    public function __construct(
        private bool $canSatisfy,
        private array $satisfiableConstraints = [],
        private array $unsatisfiableConstraints = [],
        private array $conflictingConstraints = [],
        private array $suggestions = [],
        private array $analysisData = []
    ) {
    }

    /**
     * Check if the strategy can satisfy all constraints.
     */
    public function canSatisfyConstraints(): bool
    {
        return $this->canSatisfy;
    }

    /**
     * Get the list of constraints that can be satisfied.
     * @return array<string>
     */
    public function getSatisfiableConstraints(): array
    {
        return $this->satisfiableConstraints;
    }

    /**
     * Get the list of constraints that cannot be satisfied.
     * @return array<string>
     */
    public function getUnsatisfiableConstraints(): array
    {
        return $this->unsatisfiableConstraints;
    }

    /**
     * Get the list of constraints that conflict with each other.
     * @return array<string>
     */
    public function getConflictingConstraints(): array
    {
        return $this->conflictingConstraints;
    }

    /**
     * Get suggestions for resolving constraint issues.
     * @return array<string>
     */
    public function getSuggestions(): array
    {
        return $this->suggestions;
    }

    /**
     * Get additional analysis data.
     * @return array<string, mixed>
     */
    public function getAnalysisData(): array
    {
        return $this->analysisData;
    }

    /**
     * Get a specific piece of analysis data.
     */
    public function getAnalysisValue(string $key, mixed $default = null): mixed
    {
        return $this->analysisData[$key] ?? $default;
    }

    /**
     * Check if there are any constraint issues.
     */
    public function hasIssues(): bool
    {
        return !empty($this->unsatisfiableConstraints) || !empty($this->conflictingConstraints);
    }

    /**
     * Get a summary of the constraint analysis.
     */
    public function getSummary(): string
    {
        if ($this->canSatisfy) {
            return 'All constraints can be satisfied by this strategy.';
        }

        $issues = [];

        if (!empty($this->unsatisfiableConstraints)) {
            $issues[] = sprintf(
                'Unsatisfiable constraints: %s',
                implode(', ', $this->unsatisfiableConstraints)
            );
        }

        if (!empty($this->conflictingConstraints)) {
            $issues[] = sprintf(
                'Conflicting constraints: %s',
                implode(', ', $this->conflictingConstraints)
            );
        }

        return implode(' | ', $issues);
    }

    /**
     * Create a successful report.
     * @param array<string> $satisfiableConstraints
     * @param array<string, mixed> $analysisData
     */
    public static function success(
        array $satisfiableConstraints = [],
        array $analysisData = []
    ): self {
        return new self(
            canSatisfy: true,
            satisfiableConstraints: $satisfiableConstraints,
            analysisData: $analysisData
        );
    }

    /**
     * Create a failure report.
     * @param array<string> $unsatisfiableConstraints
     * @param array<string> $conflictingConstraints
     * @param array<string> $suggestions
     * @param array<string, mixed> $analysisData
     */
    public static function failure(
        array $unsatisfiableConstraints = [],
        array $conflictingConstraints = [],
        array $suggestions = [],
        array $analysisData = []
    ): self {
        return new self(
            canSatisfy: false,
            unsatisfiableConstraints: $unsatisfiableConstraints,
            conflictingConstraints: $conflictingConstraints,
            suggestions: $suggestions,
            analysisData: $analysisData
        );
    }
}
