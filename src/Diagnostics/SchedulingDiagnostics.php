<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Diagnostics;

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;

/**
 * Comprehensive diagnostics for scheduling failures.
 *
 * This class analyzes scheduling failures and provides detailed reports
 * with actionable suggestions for resolving tournament configuration issues.
 */
class SchedulingDiagnostics
{
    /**
     * Analyze a scheduling failure and provide comprehensive diagnostics.
     *
     * @param array<Participant> $participants Tournament participants
     * @param ConstraintSet $constraints Tournament constraints
     * @param array<Event> $partialEvents Events generated before failure
     * @param int $legs Number of legs in the tournament
     * @param array<string, mixed> $context Additional context about the failure
     */
    public function analyzeSchedulingFailure(
        array $participants,
        ConstraintSet $constraints,
        array $partialEvents,
        int $legs,
        array $context = []
    ): DiagnosticReport {
        $participantCount = count($participants);
        $eventsGenerated = count($partialEvents);
        $expectedEvents = $this->calculateExpectedEvents($participantCount, $legs);

        $missingEvents = $expectedEvents - $eventsGenerated;
        $missingPairings = $this->identifyMissingPairings($participants, $partialEvents, $legs);
        $constraintViolations = $this->analyzeConstraintViolations($partialEvents, $constraints);
        $impossiblePairings = $this->identifyImpossiblePairings($participants, $constraints, $legs);
        $suggestions = $this->generateSuggestions($participants, $constraints, $partialEvents, $legs, $context);

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
     * @param ConstraintSet $constraints
     * @param int $legs
     * @return array<string>
     */
    public function identifyConstraintConflicts(
        array $participants,
        ConstraintSet $constraints,
        int $legs
    ): array {
        $conflicts = [];
        $participantCount = count($participants);
        $expectedEvents = $this->calculateExpectedEvents($participantCount, $legs);

        // Check if constraints make scheduling mathematically impossible
        if ($expectedEvents === 0) {
            $conflicts[] = 'Insufficient participants for tournament generation';
        }

        // Check for constraint conflicts that would prevent complete schedules
        if ($legs > 1 && $participantCount < 4) {
            $conflicts[] = 'Multi-leg tournaments require at least 4 participants for meaningful scheduling';
        }

        // Add specific constraint analysis
        $conflicts = [...$conflicts, ...$this->analyzeSpecificConstraints($participants, $constraints, $legs)];

        return $conflicts;
    }

    /**
     * Suggest constraint adjustments to resolve scheduling issues.
     *
     * @param DiagnosticReport $report
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
     * Calculate expected number of events for round-robin tournament.
     */
    private function calculateExpectedEvents(int $participantCount, int $legs): int
    {
        if ($participantCount < 2) {
            return 0;
        }

        // Round-robin: n * (n-1) / 2 events per leg
        $eventsPerLeg = (int) ($participantCount * ($participantCount - 1) / 2);

        return $eventsPerLeg * $legs;
    }

    /**
     * Identify which participant pairings are missing from generated events.
     *
     * @param array<Participant> $participants
     * @param array<Event> $events
     * @param int $legs
     * @return array<string>
     */
    private function identifyMissingPairings(array $participants, array $events, int $legs): array
    {
        $allExpectedPairings = $this->generateAllExpectedPairings($participants, $legs);
        $existingPairings = $this->extractExistingPairings($events);

        $missingPairings = [];
        foreach ($allExpectedPairings as $pairing) {
            if (!in_array($pairing, $existingPairings, true)) {
                $missingPairings[] = $pairing;
            }
        }

        return $missingPairings;
    }

    /**
     * Analyze constraint violations in generated events.
     *
     * @param array<Event> $events
     * @param ConstraintSet $constraints
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
     * @param ConstraintSet $constraints
     * @param int $legs
     * @return array<string>
     */
    private function identifyImpossiblePairings(array $participants, ConstraintSet $constraints, int $legs): array
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
     * @param ConstraintSet $constraints
     * @param array<Event> $partialEvents
     * @param int $legs
     * @param array<string, mixed> $context
     * @return array<string>
     */
    private function generateSuggestions(
        array $participants,
        ConstraintSet $constraints,
        array $partialEvents,
        int $legs,
        array $context
    ): array {
        $suggestions = [];

        $participantCount = count($participants);
        $eventsGenerated = count($partialEvents);
        $expectedEvents = $this->calculateExpectedEvents($participantCount, $legs);

        if ($eventsGenerated === 0) {
            $suggestions[] = 'No events were generated - check participant count and constraint configuration';
        } elseif ($eventsGenerated < $expectedEvents) {
            $percentage = (int) (($eventsGenerated / $expectedEvents) * 100);
            $suggestions[] = "Only {$percentage}% of expected events were generated - constraints may be too restrictive";
        }

        if ($legs > 1 && $participantCount % 2 !== 0) {
            $suggestions[] = 'Odd participant count in multi-leg tournaments may cause scheduling challenges';
        }

        return $suggestions;
    }

    /**
     * Analyze specific constraint types for potential conflicts.
     *
     * @param array<Participant> $participants
     * @param ConstraintSet $constraints
     * @param int $legs
     * @return array<string>
     */
    private function analyzeSpecificConstraints(array $participants, ConstraintSet $constraints, int $legs): array
    {
        $conflicts = [];

        // This would analyze specific constraint implementations
        // For now, return basic analysis

        return $conflicts;
    }

    /**
     * Generate all expected participant pairings for the tournament.
     *
     * @param array<Participant> $participants
     * @param int $legs
     * @return array<string>
     */
    private function generateAllExpectedPairings(array $participants, int $legs): array
    {
        $pairings = [];
        $participantCount = count($participants);

        for ($i = 0; $i < $participantCount; ++$i) {
            for ($j = $i + 1; $j < $participantCount; ++$j) {
                $pairing = $participants[$i]->getLabel() . ' vs ' . $participants[$j]->getLabel();

                // For multi-leg tournaments, each pairing appears multiple times
                for ($leg = 1; $leg <= $legs; ++$leg) {
                    $pairings[] = $pairing . " (Leg {$leg})";
                }
            }
        }

        return $pairings;
    }

    /**
     * Extract existing pairings from generated events.
     *
     * @param array<Event> $events
     * @return array<string>
     */
    private function extractExistingPairings(array $events): array
    {
        $pairings = [];

        foreach ($events as $event) {
            $participants = $event->getParticipants();
            if (count($participants) === 2) {
                $round = $event->getRound();
                $legInfo = $round ? " (Round {$round->getNumber()})" : '';
                $pairings[] = $participants[0]->getLabel() . ' vs ' . $participants[1]->getLabel() . $legInfo;
            }
        }

        return $pairings;
    }
}
