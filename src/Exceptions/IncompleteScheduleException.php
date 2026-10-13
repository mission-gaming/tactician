<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Exceptions;

use MissionGaming\Tactician\Diagnostics\DiagnosticReport;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Stage\StagePlan;
use MissionGaming\Tactician\Validation\ConstraintViolationCollector;

/**
 * Thrown when a scheduler cannot return a schedule that matches its stage
 * plan: constraints rejected events the plan needs, a leg strategy gave no
 * event for a pairing, a Swiss round had no valid pairing, or the generated
 * schedule failed the plan's integrity checks. No partial schedule is
 * returned.
 *
 * It carries the stage plan that was being generated, the constraint
 * rejections recorded on the way and, when the scheduler built one, an
 * analysis of which constraints block which pairings.
 *
 * @api
 */
class IncompleteScheduleException extends SchedulingException
{
    /**
     * Nothing is validated: the exception reports the values it is given.
     *
     * @param int|null $expectedEventCount Null when the plan cannot know its
     *                                     expected events up front (e.g. an
     *                                     open-ended stage failing integrity
     *                                     validation)
     * @param int $actualEventCount How many events had been generated when generation stopped
     * @param ConstraintViolationCollector $violationCollector The rejections recorded on the way
     * @param StagePlan $plan The plan the schedule was to match
     * @param Participant[] $participants The participants the scheduler was given
     * @param string $message The exception message; empty for one built from the counts
     * @param DiagnosticReport|null $analysis The failure analysis, when one was built
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

    /**
     * How many events had been generated when generation stopped. After
     * retries over rotated orderings it is the count of the last ordering
     * tried; when the backtracking search finds no first leg it is 0.
     */
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

    /**
     * The constraint rejections the round-robin scheduler recorded during
     * the generation that failed: after retries those of the last ordering
     * tried, and when the roles of a role assignment are rejected after a
     * backtracking search, that one event. Empty when nothing was recorded:
     * no constraint rejected an event, the schedule failed an integrity
     * check, or the exception comes from the Swiss scheduler, which does
     * not record the rejections of its constraints.
     */
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
     * The failure analysis: which pairings are missing and which
     * constraints block them.
     *
     * The round-robin scheduler builds one when it has constraints. Null
     * otherwise: from a round-robin scheduler without constraints, from the
     * Swiss scheduler, and when a generated schedule failed the plan's
     * integrity checks.
     */
    public function getAnalysis(): ?DiagnosticReport
    {
        return $this->analysis;
    }

    /**
     * The algorithm, the participant, round, leg and event counts, the
     * recorded rejections grouped by constraint with the participants most
     * affected and every round affected, the blocked pairings and the attribution of the
     * analysis when there is one, and suggestions. The suggestions speak
     * of the constraints that rejected an event; when no rejection was
     * recorded they do not take it that the scheduler has constraints. A
     * rejection recorded
     * without a round (null or 0) is counted for its constraint and its
     * participants and is in no affected round.
     */
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
            // "%F", not "%f": the lower-case form writes the decimal
            // separator of the locale, and the report must read the same
            // everywhere.
            $report[] = sprintf(
                'Missing Events: %d (%.1F%%)',
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
                $violationsByConstraint[$constraintName] ??= [];
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

                    // Null and 0 both mean no round (ConstraintViolation)
                    if ($violation->roundNumber !== null && $violation->roundNumber !== 0) {
                        $roundCounts[$violation->roundNumber] = ($roundCounts[$violation->roundNumber] ?? 0) + 1;
                    }
                }

                if ($participantCounts !== []) {
                    arsort($participantCounts);
                    $topAffected = array_slice($participantCounts, 0, 3, true);
                    $report[] = sprintf(
                        '  Most affected participants: %s',
                        implode(', ', array_map(fn($id, $count) => "$id ($count)", array_keys($topAffected), $topAffected))
                    );
                }

                if ($roundCounts !== []) {
                    ksort($roundCounts);
                    $report[] = sprintf(
                        '  Affected rounds: %s',
                        implode(', ', array_map(fn($round, $count) => "$round ($count)", array_keys($roundCounts), $roundCounts))
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
                    $suggestions[] = '• Try raising the consecutive role constraint limit';
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

        if ($suggestions === []) {
            // No rejection was recorded. The exception does not know why:
            // the scheduler may have no constraints at all (a leg strategy
            // that returned no event leaves a leg short without one), or be
            // one that does not record what its constraints reject. So the
            // advice about constraints is conditional, and the other cause
            // is named.
            $suggestions[] = '• No constraint rejection was recorded: if the scheduler has constraints, try relaxing them and review their combinations for conflicts';
            $suggestions[] = $hasLegs
                ? '• If the scheduler has a leg strategy of its own, check that it returns an event for every pairing in every leg'
                : '• Increase the number of participants or rounds';
        } else {
            $suggestions[] = '• Use fewer or less restrictive constraints';
        }

        $suggestions[] = '• Test with a simpler configuration first';

        return implode("\n", $suggestions);
    }
}
