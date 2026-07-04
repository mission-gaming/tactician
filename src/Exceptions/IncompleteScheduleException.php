<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Exceptions;

use MissionGaming\Tactician\Diagnostics\DiagnosticReport;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Stage\StagePlan;
use MissionGaming\Tactician\Validation\ConstraintViolationCollector;

/**
 * Exception thrown when a scheduler cannot generate a complete schedule due to constraint violations.
 *
 * This exception provides detailed diagnostic information about why the schedule is incomplete,
 * including the stage plan that was being generated, violated constraints, affected participants,
 * and suggestions for resolution.
 */
class IncompleteScheduleException extends SchedulingException
{
    /**
     * @param int|null $expectedEventCount Null when the plan cannot know its
     *                                     expected events up front (e.g. an
     *                                     open-ended stage failing integrity
     *                                     validation)
     * @param Participant[] $participants
     */
    public function __construct(
        private readonly ?int $expectedEventCount,
        private readonly int $actualEventCount,
        private readonly ConstraintViolationCollector $violationCollector,
        private readonly StagePlan $plan,
        private readonly array $participants,
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        private readonly ?DiagnosticReport $analysis = null
    ) {
        if ($message === '') {
            $message = $this->expectedEventCount === null
                ? sprintf(
                    'Schedule failed validation with %d events generated (expected count unknowable up front)',
                    $this->actualEventCount
                )
                : sprintf(
                    'Incomplete schedule generated: %d events created out of %d expected (%d missing)',
                    $this->actualEventCount,
                    $this->expectedEventCount,
                    $this->getMissingEventCount()
                );
        }

        parent::__construct($message, $code, $previous);
    }

    /**
     * Null when the plan could not know its expected events up front.
     */
    public function getExpectedEventCount(): ?int
    {
        return $this->expectedEventCount;
    }

    public function getActualEventCount(): int
    {
        return $this->actualEventCount;
    }

    /**
     * Zero when the expected count is unknowable: no missing-event claim
     * can honestly be made for an open-ended stage.
     */
    public function getMissingEventCount(): int
    {
        if ($this->expectedEventCount === null) {
            return 0;
        }

        return max(0, $this->expectedEventCount - $this->actualEventCount);
    }

    public function getViolationCollector(): ConstraintViolationCollector
    {
        return $this->violationCollector;
    }

    /**
     * The stage plan whose shape the generated schedule failed to match.
     */
    public function getPlan(): StagePlan
    {
        return $this->plan;
    }

    /**
     * The deep failure analysis attached at the throw site, when the
     * scheduler could build one (constraints configured, pairwise plan).
     */
    public function getAnalysis(): ?DiagnosticReport
    {
        return $this->analysis;
    }

    #[\Override]
    public function getDiagnosticReport(): string
    {
        $report = [];
        $report[] = '=== INCOMPLETE SCHEDULE DIAGNOSTIC REPORT ===';
        $report[] = '';
        $report[] = sprintf('Algorithm: %s', $this->plan->getAlgorithm());
        $report[] = sprintf('Participants: %d', count($this->participants));

        $totalRounds = $this->plan->getTotalRounds();
        if ($totalRounds !== null) {
            $report[] = sprintf('Rounds: %d', $totalRounds);
        }

        $legs = $this->plan->getLegs();
        if ($legs !== null) {
            $report[] = sprintf('Legs: %d', $legs);
        }

        if ($this->expectedEventCount === null) {
            $report[] = 'Expected Events: unknown (not knowable up front for this stage)';
            $report[] = sprintf('Generated Events: %d', $this->actualEventCount);
        } else {
            $report[] = sprintf('Expected Events: %d', $this->expectedEventCount);
            $report[] = sprintf('Generated Events: %d', $this->actualEventCount);
            $report[] = sprintf(
                'Missing Events: %d (%.1f%%)',
                $this->getMissingEventCount(),
                $this->expectedEventCount === 0 ? 0.0 : ($this->getMissingEventCount() / $this->expectedEventCount) * 100
            );
        }
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

        if ($this->analysis !== null) {
            if ($this->analysis->getImpossiblePairings() !== []) {
                $report[] = '=== BLOCKED PAIRINGS ===';
                foreach ($this->analysis->getImpossiblePairings() as $blocked) {
                    $report[] = "• {$blocked}";
                }
                $report[] = '';
            }

            if ($this->analysis->getConstraintViolations() !== []) {
                $report[] = '=== CONSTRAINT ATTRIBUTION ===';
                foreach ($this->analysis->getConstraintViolations() as $attribution) {
                    $report[] = "• {$attribution}";
                }
                $report[] = '';
            }
        }

        $report[] = '=== SUGGESTIONS ===';
        $report[] = $this->generateSuggestions();

        if ($this->analysis !== null) {
            foreach ($this->analysis->getSuggestions() as $suggestion) {
                $report[] = "• {$suggestion}";
            }
        }

        return implode("\n", $report);
    }

    private function generateSuggestions(): string
    {
        $suggestions = [];
        $hasLegs = $this->plan->getLegs() !== null;

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
                    $suggestions[] = $hasLegs
                        ? '• Add more legs to provide more scheduling flexibility'
                        : '• Add more rounds to provide more scheduling flexibility';
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
            $suggestions[] = $hasLegs
                ? '• Increase the number of participants or legs'
                : '• Increase the number of participants or rounds';
            $suggestions[] = '• Review constraint combinations for conflicts';
        }

        $suggestions[] = '• Use fewer or less restrictive constraints';
        $suggestions[] = '• Test with a simpler configuration first';

        return implode("\n", $suggestions);
    }
}
