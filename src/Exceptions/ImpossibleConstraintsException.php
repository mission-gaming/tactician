<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Exceptions;

use MissionGaming\Tactician\Constraints\ConstraintInterface;
use MissionGaming\Tactician\DTO\Participant;

/**
 * Exception thrown when constraints are mathematically impossible to satisfy.
 *
 * This exception is thrown when the scheduler detects that the given constraints
 * cannot possibly be satisfied with the provided participants and configuration,
 * before any scheduling attempts are made.
 */
class ImpossibleConstraintsException extends SchedulingException
{
    /**
     * @param ConstraintInterface[] $conflictingConstraints
     * @param Participant[] $participants
     */
    public function __construct(
        private readonly array $conflictingConstraints,
        private readonly array $participants,
        private readonly int $legs,
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        if ($message === '') {
            $message = sprintf(
                'Impossible constraint configuration detected with %d participants and %d legs',
                count($this->participants),
                $this->legs
            );
        }

        parent::__construct($message, $code, $previous);
    }

    /**
     * @return ConstraintInterface[]
     */
    public function getConflictingConstraints(): array
    {
        return $this->conflictingConstraints;
    }

    /**
     * @return Participant[]
     */
    public function getParticipants(): array
    {
        return $this->participants;
    }

    public function getLegs(): int
    {
        return $this->legs;
    }

    /**
     * @throws \DivisionByZeroError
     */
    #[\Override]
    public function getDiagnosticReport(): string
    {
        $report = [];
        $report[] = '=== IMPOSSIBLE CONSTRAINTS DIAGNOSTIC REPORT ===';
        $report[] = '';
        $report[] = sprintf('Participants: %d', count($this->participants));
        $report[] = sprintf('Legs: %d', $this->legs);
        $report[] = '';

        $report[] = '=== CONFLICTING CONSTRAINTS ===';
        foreach ($this->conflictingConstraints as $constraint) {
            $report[] = sprintf('• %s', $this->getConstraintDescription($constraint));
        }
        $report[] = '';

        $report[] = '=== MATHEMATICAL ANALYSIS ===';
        $report[] = $this->analyzeMathematicalImpossibility();
        $report[] = '';

        $report[] = '=== SUGGESTIONS ===';
        $report[] = $this->generateSuggestions();

        return implode("\n", $report);
    }

    private function getConstraintDescription(ConstraintInterface $constraint): string
    {
        $className = basename(str_replace('\\', '/', $constraint::class));

        // Try to get more specific information if the constraint has useful methods
        if (method_exists($constraint, '__toString')) {
            return sprintf('%s: %s', $className, $constraint->__toString());
        }

        return $className;
    }

    /**
     * @throws \DivisionByZeroError
     */
    private function analyzeMathematicalImpossibility(): string
    {
        $analysis = [];
        $participantCount = count($this->participants);
        $totalEventsNeeded = intval($participantCount * ($participantCount - 1) / 2) * $this->legs;

        $analysis[] = sprintf(
            'Total events needed for Round Robin with %d legs: %d',
            $this->legs,
            $totalEventsNeeded
        );

        // Analyze each constraint for mathematical bounds
        foreach ($this->conflictingConstraints as $constraint) {
            $constraintAnalysis = $this->analyzeConstraint($constraint, $participantCount);
            $analysis[] = $constraintAnalysis;
        }

        if (count($analysis) === 1) {
            $analysis[] = 'The constraint configuration creates impossible scheduling requirements.';
        }

        return implode("\n", $analysis);
    }

    /**
     * @throws \DivisionByZeroError
     */
    private function analyzeConstraint(ConstraintInterface $constraint, int $participantCount): string
    {
        $className = basename(str_replace('\\', '/', $constraint::class));

        switch ($className) {
            case 'ConsecutiveRoleConstraint':
                // Try to extract limit if possible - this is implementation-specific
                if (method_exists($constraint, 'getLimit')) {
                    $limit = $constraint->getLimit();
                    $minRoundsNeeded = ceil($participantCount / $limit);

                    return sprintf(
                        'ConsecutiveRoleConstraint with limit %d requires at least %d rounds',
                        $limit,
                        $minRoundsNeeded
                    );
                }

                return 'ConsecutiveRoleConstraint may require more rounds than available';

            case 'MinimumRestPeriodsConstraint':
                if (method_exists($constraint, 'getMinimumRest')) {
                    $minRest = $constraint->getMinimumRest();

                    return sprintf('MinimumRestPeriodsConstraint requires %d rest periods between events', $minRest);
                }

                return 'MinimumRestPeriodsConstraint may require more scheduling space';

            default:
                return sprintf('%s creates scheduling restrictions that may be impossible', $className);
        }
    }

    private function generateSuggestions(): string
    {
        $suggestions = [
            '• Reduce constraint restrictions (lower limits, fewer requirements)',
            '• Increase the number of participants to provide more scheduling flexibility',
            '• Add more legs if constraints are per-leg based',
            '• Remove conflicting constraints',
            '• Test with a minimal constraint set first',
            '• Review constraint documentation for compatibility guidelines',
        ];

        return implode("\n", $suggestions);
    }
}
