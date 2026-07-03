<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Diagnostics;

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Stage\PairwisePlan;
use MissionGaming\Tactician\Stage\StagePlan;

/**
 * Comprehensive diagnostics for scheduling failures.
 *
 * This class analyzes scheduling failures and provides detailed reports
 * with actionable suggestions for resolving tournament configuration
 * issues. All shape facts (expected events, legs, pairwise meetings) are
 * read from the stage plan rather than recomputed, so the analysis is
 * correct for whatever format the plan describes.
 */
class SchedulingDiagnostics
{
    /**
     * Analyze a scheduling failure and provide comprehensive diagnostics.
     *
     * @param array<Participant> $participants Tournament participants
     * @param ConstraintSet $constraints Tournament constraints
     * @param array<Event> $partialEvents Events generated before failure
     * @param StagePlan $plan The plan for the stage that failed to generate
     * @param array<string, mixed> $context Additional context about the failure
     */
    public function analyzeSchedulingFailure(
        array $participants,
        ConstraintSet $constraints,
        array $partialEvents,
        StagePlan $plan,
        array $context = []
    ): DiagnosticReport {
        $participantCount = count($participants);
        $eventsGenerated = count($partialEvents);
        $expectedEvents = $plan->getExpectedEventCount() ?? 0;

        $missingEvents = max(0, $expectedEvents - $eventsGenerated);
        $missingPairings = $this->identifyMissingPairings($participants, $partialEvents, $plan);
        $constraintViolations = $this->analyzeConstraintViolations($partialEvents, $constraints);
        $impossiblePairings = $this->identifyImpossiblePairings($participants, $constraints, $plan);
        $suggestions = $this->generateSuggestions($participants, $constraints, $partialEvents, $plan, $context);

        return new DiagnosticReport(
            participantCount: $participantCount,
            expectedEvents: $expectedEvents,
            generatedEvents: $eventsGenerated,
            missingEvents: $missingEvents,
            missingPairings: $missingPairings,
            constraintViolations: $constraintViolations,
            impossiblePairings: $impossiblePairings,
            suggestions: $suggestions,
            analysisContext: $context
        );
    }

    /**
     * Identify constraint conflicts before scheduling begins.
     *
     * @param array<Participant> $participants
     * @return array<string>
     */
    public function identifyConstraintConflicts(
        array $participants,
        ConstraintSet $constraints,
        StagePlan $plan
    ): array {
        $conflicts = [];
        $participantCount = count($participants);
        $expectedEvents = $plan->getExpectedEventCount() ?? 0;

        // Check if constraints make scheduling mathematically impossible
        if ($expectedEvents === 0 && $plan->getExpectedEventCount() !== null) {
            $conflicts[] = 'Insufficient participants for tournament generation';
        }

        // Check for constraint conflicts that would prevent complete schedules
        if (($plan->getLegs() ?? 1) > 1 && $participantCount < 4) {
            $conflicts[] = 'Multi-leg tournaments require at least 4 participants for meaningful scheduling';
        }

        // Add specific constraint analysis
        $conflicts = [...$conflicts, ...$this->analyzeSpecificConstraints($participants, $constraints, $plan)];

        return $conflicts;
    }

    /**
     * Suggest constraint adjustments to resolve scheduling issues.
     *
     * @return array<string>
     */
    public function suggestConstraintAdjustments(DiagnosticReport $report): array
    {
        $suggestions = [];

        if ($report->getMissingEvents() > 0) {
            $suggestions[] = 'Consider relaxing constraints that may be preventing event generation';
        }

        if (!empty($report->getImpossiblePairings())) {
            $suggestions[] = 'Some participant pairings cannot be satisfied with current constraints';
        }

        if (!empty($report->getConstraintViolations())) {
            $suggestions[] = 'Review constraint configuration for potential conflicts';
        }

        // Add specific suggestions based on context
        $context = $report->getAnalysisContext();
        if (isset($context['leg']) && $context['leg'] > 1) {
            $suggestions[] = 'Multi-leg constraint validation may require different strategy';
        }

        return $suggestions;
    }

    /**
     * Identify which participant pairings are missing from generated events.
     *
     * Only pairwise plans (round-robin family) guarantee meeting counts up
     * front, so other formats yield no missing-pairing analysis. Meetings
     * are counted per unordered pairing (so reversed role orders still
     * match) and attributed to legs via their round numbers, so a duplicate
     * meeting in one leg cannot mask a missing meeting in another. Events
     * without a round count toward leg 1.
     *
     * @param array<Participant> $participants
     * @param array<Event> $events
     * @return array<string>
     */
    private function identifyMissingPairings(array $participants, array $events, StagePlan $plan): array
    {
        if (!$plan instanceof PairwisePlan) {
            return [];
        }

        $participantCount = count($participants);
        if ($participantCount < 2) {
            return [];
        }

        $legs = $plan->getLegs() ?? 1;
        $roundsPerLeg = $plan->getRoundsPerLeg() ?? $plan->getTotalRounds() ?? 1;
        if ($roundsPerLeg < 1) {
            $roundsPerLeg = 1;
        }

        /** @var array<string, array<int, int>> $legMeetingCounts */
        $legMeetingCounts = [];
        foreach ($events as $event) {
            $eventParticipants = $event->getParticipants();
            if (count($eventParticipants) !== 2) {
                continue;
            }

            $round = $event->getRound()?->getNumber();
            $leg = $round === null ? 1 : min($legs, intdiv($round - 1, $roundsPerLeg) + 1);

            $ids = [$eventParticipants[0]->getId(), $eventParticipants[1]->getId()];
            sort($ids);
            $key = implode('|', $ids);
            $legMeetingCounts[$key][$leg] = ($legMeetingCounts[$key][$leg] ?? 0) + 1;
        }

        $missingPairings = [];
        for ($i = 0; $i < $participantCount; ++$i) {
            for ($j = $i + 1; $j < $participantCount; ++$j) {
                if ($plan->getExpectedMeetings($participants[$i], $participants[$j]) === 0) {
                    continue;
                }

                $ids = [$participants[$i]->getId(), $participants[$j]->getId()];
                sort($ids);
                $key = implode('|', $ids);

                for ($leg = 1; $leg <= $legs; ++$leg) {
                    if (($legMeetingCounts[$key][$leg] ?? 0) === 0) {
                        $missingPairings[] = $participants[$i]->getLabel() . ' vs ' . $participants[$j]->getLabel()
                            . " (Leg {$leg})";
                    }
                }
            }
        }

        return $missingPairings;
    }

    /**
     * Analyze constraint violations in generated events.
     *
     * @param array<Event> $events
     * @return array<string>
     */
    private function analyzeConstraintViolations(array $events, ConstraintSet $constraints): array
    {
        $violations = [];

        // This would need to be implemented based on the specific constraint system
        // For now, return basic analysis

        return $violations;
    }

    /**
     * Identify pairings that are impossible due to constraints.
     *
     * @param array<Participant> $participants
     * @return array<string>
     */
    private function identifyImpossiblePairings(array $participants, ConstraintSet $constraints, StagePlan $plan): array
    {
        $impossiblePairings = [];

        // This would analyze constraints to determine which pairings can never be satisfied
        // Implementation depends on specific constraint analysis capabilities

        return $impossiblePairings;
    }

    /**
     * Generate actionable suggestions for resolving scheduling issues.
     *
     * @param array<Participant> $participants
     * @param array<Event> $partialEvents
     * @param array<string, mixed> $context
     * @return array<string>
     */
    private function generateSuggestions(
        array $participants,
        ConstraintSet $constraints,
        array $partialEvents,
        StagePlan $plan,
        array $context
    ): array {
        $suggestions = [];

        $participantCount = count($participants);
        $eventsGenerated = count($partialEvents);
        $expectedEvents = $plan->getExpectedEventCount() ?? 0;

        if ($eventsGenerated === 0) {
            $suggestions[] = 'No events were generated - check participant count and constraint configuration';
        } elseif ($eventsGenerated < $expectedEvents) {
            $percentage = (int) (($eventsGenerated / $expectedEvents) * 100);
            $suggestions[] = "Only {$percentage}% of expected events were generated - constraints may be too restrictive";
        }

        if (($plan->getLegs() ?? 1) > 1 && $participantCount % 2 !== 0) {
            $suggestions[] = 'Odd participant count in multi-leg tournaments may cause scheduling challenges';
        }

        return $suggestions;
    }

    /**
     * Analyze specific constraint types for potential conflicts.
     *
     * @param array<Participant> $participants
     * @return array<string>
     */
    private function analyzeSpecificConstraints(array $participants, ConstraintSet $constraints, StagePlan $plan): array
    {
        $conflicts = [];

        // This would analyze specific constraint implementations
        // For now, return basic analysis

        return $conflicts;
    }
}
