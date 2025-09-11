<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Validation;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;

/**
 * Generic validation service for scheduling completeness.
 */
class ScheduleValidator
{
    /**
     * Validate that a generated schedule is complete.
     *
     * @param array<Participant> $participants
     * @throws IncompleteScheduleException
     */
    public function validateScheduleCompleteness(
        Schedule $generated,
        int $expectedEventCount,
        ConstraintViolationCollector $violations,
        ExpectedEventCalculator $eventCalculator,
        array $participants,
        int $legs
    ): void {
        $actualEventCount = count($generated);

        if ($actualEventCount < $expectedEventCount) {
            throw new IncompleteScheduleException(
                $expectedEventCount,
                $actualEventCount,
                $violations,
                $eventCalculator,
                $participants,
                $legs
            );
        }
    }

    /**
     * Generate a detailed diagnostic report.
     */
    public function generateDiagnosticReport(
        ConstraintViolationCollector $violations,
        int $expectedEvents,
        int $actualEvents,
        string $algorithmName
    ): string {
        $missing = $expectedEvents - $actualEvents;
        $report = "Cannot generate complete {$algorithmName} schedule.\n";
        $report .= "Expected: {$expectedEvents} events\n";
        $report .= "Generated: {$actualEvents} events ({$missing} missing)\n\n";

        if ($violations->hasViolations()) {
            $report .= "Constraint violations:\n";

            $violationsByConstraint = $violations->getViolationsByConstraint();
            foreach ($violationsByConstraint as $constraintName => $constraintViolations) {
                $count = count($constraintViolations);
                $rounds = [];
                foreach ($constraintViolations as $violation) {
                    if ($violation->roundNumber !== null) {
                        $rounds[] = $violation->roundNumber;
                    }
                }
                $rounds = array_unique($rounds);
                sort($rounds);
                $roundsText = empty($rounds) ? '' : ' in rounds [' . implode(',', $rounds) . ']';

                $report .= "  - {$constraintName}: {$count} violations{$roundsText}\n";
            }

            $report .= "\nParticipant impact:\n";
            $violationsByParticipant = $violations->getViolationsByParticipant();
            foreach ($violationsByParticipant as $participantId => $participantViolations) {
                $count = count($participantViolations);
                $report .= "  - {$participantId}: {$count} violations\n";
            }
        }

        return $report;
    }

    /**
     * Generate constraint adjustment suggestions.
     *
     * @throws \DivisionByZeroError
     */
    public function generateConstraintSuggestions(
        ConstraintViolationCollector $violations,
        int $participantCount
    ): string {
        if (!$violations->hasViolations()) {
            return '';
        }

        $suggestions = "\nSuggestions:\n";
        $violationCounts = $violations->getViolationCountsByConstraint();

        foreach ($violationCounts as $constraintName => $violationCount) {
            // Generate constraint-specific suggestions
            if (strpos($constraintName, 'consecutive') !== false) {
                $suggestions .= "  - Consider increasing the consecutive limit for '{$constraintName}'\n";
            } elseif (strpos($constraintName, 'rest') !== false) {
                $suggestions .= "  - Consider reducing rest period requirements for '{$constraintName}'\n";
            } elseif (strpos($constraintName, 'seed') !== false) {
                $suggestions .= "  - Consider reducing seed protection rounds for '{$constraintName}'\n";
            } else {
                $suggestions .= "  - Review configuration for '{$constraintName}' ({$violationCount} violations)\n";
            }
        }

        // General suggestions based on violation patterns
        $totalViolations = $violations->getViolationCount();
        $violationRatio = $totalViolations / ($participantCount * ($participantCount - 1) / 2);

        if ($violationRatio > 0.5) {
            $suggestions .= "  - High violation ratio ({$totalViolations} violations) suggests constraints may be too restrictive\n";
            $suggestions .= "  - Consider relaxing constraint parameters or reducing participant count\n";
        }

        return $suggestions;
    }
}
