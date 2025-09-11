<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Exceptions;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Validation\ConstraintViolationCollector;
use MissionGaming\Tactician\Validation\ExpectedEventCalculator;

/**
 * Exception thrown when a scheduler cannot generate a complete schedule due to constraint violations.
 *
 * This exception provides detailed diagnostic information about why the schedule is incomplete,
 * including violated constraints, affected participants, and suggestions for resolution.
 */
class IncompleteScheduleException extends SchedulingException
{
    /**
     * @param Participant[] $participants
     */
    public function __construct(
        private readonly int $expectedEventCount,
        private readonly int $actualEventCount,
        private readonly ConstraintViolationCollector $violationCollector,
        private readonly ExpectedEventCalculator $eventCalculator,
        private readonly array $participants,
        private readonly int $legs,
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        if ($message === '') {
            $message = sprintf(
                'Incomplete schedule generated: %d events created out of %d expected (%d missing)',
                $this->actualEventCount,
                $this->expectedEventCount,
                $this->expectedEventCount - $this->actualEventCount
            );
        }

        parent::__construct($message, $code, $previous);
    }

    public function getExpectedEventCount(): int
    {
        return $this->expectedEventCount;
    }

    public function getActualEventCount(): int
    {
        return $this->actualEventCount;
    }

    public function getMissingEventCount(): int
    {
        return $this->expectedEventCount - $this->actualEventCount;
    }

    public function getViolationCollector(): ConstraintViolationCollector
    {
        return $this->violationCollector;
    }

    /**
     * @throws \DivisionByZeroError
     */
    #[\Override]
    public function getDiagnosticReport(): string
    {
        $report = [];
        $report[] = '=== INCOMPLETE SCHEDULE DIAGNOSTIC REPORT ===';
        $report[] = '';
        $report[] = sprintf('Algorithm: %s', $this->eventCalculator->getAlgorithmName());
        $report[] = sprintf('Participants: %d', count($this->participants));
        $report[] = sprintf('Legs: %d', $this->legs);
        $report[] = sprintf('Expected Events: %d', $this->expectedEventCount);
        $report[] = sprintf('Generated Events: %d', $this->actualEventCount);
        $report[] = sprintf(
            'Missing Events: %d (%.1f%%)',
            $this->getMissingEventCount(),
            ($this->getMissingEventCount() / $this->expectedEventCount) * 100
        );
        $report[] = '';

        if ($this->violationCollector->hasViolations()) {
            $report[] = '=== CONSTRAINT VIOLATIONS ===';
            $violations = $this->violationCollector->getViolations();
            $violationsByConstraint = [];

            foreach ($violations as $violation) {
                $constraintName = $violation->constraint->getName();
                if (!isset($violationsByConstraint[$constraintName])) {
                    $violationsByConstraint[$constraintName] = [];
                }
                $violationsByConstraint[$constraintName][] = $violation;
            }

            foreach ($violationsByConstraint as $constraintName => $constraintViolations) {
                $report[] = sprintf(
                    '• %s: %d violations',
                    $constraintName,
                    count($constraintViolations)
                );

                $participantCounts = [];
                $roundCounts = [];

                foreach ($constraintViolations as $violation) {
                    foreach ($violation->affectedParticipants as $participant) {
                        $participantId = $participant->getId();
                        $participantCounts[$participantId] = ($participantCounts[$participantId] ?? 0) + 1;
                    }

                    if ($violation->roundNumber !== null) {
                        $roundCounts[$violation->roundNumber] = ($roundCounts[$violation->roundNumber] ?? 0) + 1;
                    }
                }

                if (!empty($participantCounts)) {
                    arsort($participantCounts);
                    $topAffected = array_slice($participantCounts, 0, 3, true);
                    $report[] = sprintf(
                        '  Most affected participants: %s',
                        implode(', ', array_map(fn ($id, $count) => "$id ($count)", array_keys($topAffected), $topAffected))
                    );
                }

                if (!empty($roundCounts)) {
                    ksort($roundCounts);
                    $report[] = sprintf(
                        '  Affected rounds: %s',
                        implode(', ', array_map(fn ($round, $count) => "$round ($count)", array_keys($roundCounts), $roundCounts))
                    );
                }

                $report[] = '';
            }
        }

        $report[] = '=== SUGGESTIONS ===';
        $report[] = $this->generateSuggestions();

        return implode("\n", $report);
    }

    private function generateSuggestions(): string
    {
        $suggestions = [];

        // Analyze the types of violations to provide specific suggestions
        $violations = $this->violationCollector->getViolations();
        $constraintTypes = [];

        foreach ($violations as $violation) {
            $constraintTypes[] = $violation->constraint::class;
        }

        $constraintTypes = array_unique($constraintTypes);

        foreach ($constraintTypes as $constraintType) {
            $constraintName = basename(str_replace('\\', '/', $constraintType));

            switch ($constraintName) {
                case 'ConsecutiveRoleConstraint':
                    $suggestions[] = '• Try reducing the consecutive role constraint limit';
                    $suggestions[] = '• Consider increasing the number of participants';
                    $suggestions[] = '• Add more legs to provide more scheduling flexibility';
                    break;

                case 'MinimumRestPeriodsConstraint':
                    $suggestions[] = '• Reduce the minimum rest period requirement';
                    $suggestions[] = '• Add more rounds to provide scheduling flexibility';
                    break;

                case 'NoRepeatPairings':
                    $suggestions[] = '• This constraint may be mathematically impossible with current settings';
                    $suggestions[] = '• Consider allowing some repeat pairings';
                    break;

                case 'SeedProtectionConstraint':
                    $suggestions[] = '• Adjust seed protection settings';
                    $suggestions[] = "• Ensure protected rounds don't conflict with other constraints";
                    break;

                default:
                    $suggestions[] = sprintf('• Review %s settings for compatibility', $constraintName);
            }
        }

        if (empty($suggestions)) {
            $suggestions[] = '• Try relaxing constraint requirements';
            $suggestions[] = '• Increase the number of participants or legs';
            $suggestions[] = '• Review constraint combinations for conflicts';
        }

        $suggestions[] = '• Use fewer or less restrictive constraints';
        $suggestions[] = '• Test with a simpler configuration first';

        return implode("\n", $suggestions);
    }
}
