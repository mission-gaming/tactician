<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Diagnostics\DiagnosticReport;
use MissionGaming\Tactician\Diagnostics\SchedulingDiagnostics;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Stage\RoundRobinPlan;
use MissionGaming\Tactician\Stage\StagePlan;

describe('SchedulingDiagnostics', function (): void {
    beforeEach(function (): void {
        $this->alice = new Participant('p1', 'Alice');
        $this->bob = new Participant('p2', 'Bob');
        $this->carol = new Participant('p3', 'Carol');
        $this->participants = [$this->alice, $this->bob, $this->carol];
        $this->constraints = ConstraintSet::create()->build();
        $this->diagnostics = new SchedulingDiagnostics();
    });

    // Regression: expected pairings were labelled "(Leg N)" and existing
    // pairings "(Round N)", so no pairing ever matched and every pairing was
    // always reported missing - even for complete schedules.
    it('reports no missing pairings for a complete schedule', function (): void {
        $events = [
            new Event([$this->alice, $this->bob], new Round(1)),
            new Event([$this->bob, $this->carol], new Round(2)),
            new Event([$this->alice, $this->carol], new Round(3)),
        ];

        $report = $this->diagnostics->analyzeSchedulingFailure(
            $this->participants,
            $this->constraints,
            $events,
            new RoundRobinPlan($this->participants, 1)
        );

        expect($report->getMissingPairings())->toBe([]);
        expect($report->getMissingEvents())->toBe(0);
        expect($report->getCompletionPercentage())->toBe(100.0);
        expect($report->isSuccessful())->toBeTrue();
    });

    it('reports the specific pairing that is missing', function (): void {
        $events = [
            new Event([$this->alice, $this->bob], new Round(1)),
            new Event([$this->bob, $this->carol], new Round(2)),
        ];

        $report = $this->diagnostics->analyzeSchedulingFailure(
            $this->participants,
            $this->constraints,
            $events,
            new RoundRobinPlan($this->participants, 1)
        );

        expect($report->getMissingPairings())->toBe(['Alice vs Carol (Leg 1)']);
        expect($report->getMissingEvents())->toBe(1);
    });

    it('matches pairings regardless of home/away order', function (): void {
        // Mirrored legs reverse the participant order; the pairing must
        // still be recognized as played
        $events = [
            new Event([$this->alice, $this->bob], new Round(1)),
            new Event([$this->bob, $this->alice], new Round(4)),
        ];

        $report = $this->diagnostics->analyzeSchedulingFailure(
            [$this->alice, $this->bob],
            $this->constraints,
            $events,
            new RoundRobinPlan([$this->alice, $this->bob], 2)
        );

        expect($report->getMissingPairings())->toBe([]);
    });

    it('reports the missing leg occurrences for multi-leg tournaments', function (): void {
        $events = [
            new Event([$this->alice, $this->bob], new Round(1)),
        ];

        $report = $this->diagnostics->analyzeSchedulingFailure(
            [$this->alice, $this->bob],
            $this->constraints,
            $events,
            new RoundRobinPlan([$this->alice, $this->bob], 3)
        );

        expect($report->getMissingPairings())->toBe([
            'Alice vs Bob (Leg 2)',
            'Alice vs Bob (Leg 3)',
        ]);
        expect($report->getGeneratedEvents())->toBe(1);
        expect($report->getExpectedEvents())->toBe(3);
    });

    // Regression: meetings were counted without leg attribution, so a
    // duplicate meeting in leg 1 masked the same pairing missing from leg 2
    it('does not let a duplicate meeting in one leg mask a missing meeting in another', function (): void {
        // 2 participants, 2 legs => rounds per leg is 1; both meetings landed
        // in leg 1 (rounds 1 and... round 1 again) and leg 2 never happened
        $events = [
            new Event([$this->alice, $this->bob], new Round(1)),
            new Event([$this->alice, $this->bob], new Round(1)),
        ];

        $report = $this->diagnostics->analyzeSchedulingFailure(
            [$this->alice, $this->bob],
            $this->constraints,
            $events,
            new RoundRobinPlan([$this->alice, $this->bob], 2)
        );

        expect($report->getMissingPairings())->toBe(['Alice vs Bob (Leg 2)']);
    });

    it('flags small multi-leg fields as conflicts', function (): void {
        $conflicts = $this->diagnostics->identifyConstraintConflicts(
            $this->participants,
            $this->constraints,
            new RoundRobinPlan($this->participants, 2)
        );

        expect($conflicts)->toContain('Multi-leg tournaments require at least 4 participants for meaningful scheduling');
    });

    // Library plans refuse to construct for fields that cannot play at all,
    // but a custom plan may still declare zero expected events
    it('flags plans declaring zero expected events as insufficient', function (): void {
        $emptyPlan = new readonly class () implements StagePlan {
            #[Override]
            public function getAlgorithm(): string
            {
                return 'custom';
            }

            #[Override]
            public function getTotalRounds(): ?int
            {
                return null;
            }

            #[Override]
            public function getLegs(): ?int
            {
                return null;
            }

            #[Override]
            public function getRoundsPerLeg(): ?int
            {
                return null;
            }

            #[Override]
            public function getExpectedEventCount(): int
            {
                return 0;
            }

            #[Override]
            public function validateIntegrity(Schedule $schedule): array
            {
                return [];
            }
        };

        $conflicts = $this->diagnostics->identifyConstraintConflicts(
            [$this->alice],
            $this->constraints,
            $emptyPlan
        );

        expect($conflicts)->toContain('Insufficient participants for tournament generation');
    });

    // Missing-pairing analysis is a pairwise-plan capability; other
    // formats yield none
    it('reports no missing pairings for non-pairwise plans', function (): void {
        $report = $this->diagnostics->analyzeSchedulingFailure(
            $this->participants,
            $this->constraints,
            [],
            new MissionGaming\Tactician\Stage\SwissPlan($this->participants, 3)
        );

        expect($report->getMissingPairings())->toBe([]);
    });

    it('skips malformed events in the meeting count', function (): void {
        $events = [
            new Event([$this->alice, $this->bob, $this->carol], new Round(1)), // not pairwise
            new Event([$this->alice, $this->bob], new Round(1)),
        ];

        $report = $this->diagnostics->analyzeSchedulingFailure(
            $this->participants,
            $this->constraints,
            $events,
            new RoundRobinPlan($this->participants, 1)
        );

        // Only the malformed event is ignored; real pairings still count
        expect($report->getMissingPairings())->toBe([
            'Alice vs Carol (Leg 1)',
            'Bob vs Carol (Leg 1)',
        ]);
    });

    it('suggests checking configuration when nothing was generated and flags odd multi-leg fields', function (): void {
        $oddField = [$this->alice, $this->bob, $this->carol];
        $report = $this->diagnostics->analyzeSchedulingFailure(
            $oddField,
            $this->constraints,
            [],
            new RoundRobinPlan($oddField, 2)
        );

        expect($report->getSuggestions())->toContain('No events were generated - check participant count and constraint configuration');
        expect($report->getSuggestions())->toContain('Odd participant count in multi-leg tournaments may cause scheduling challenges');
    });

    it('suggests adjustments for impossible pairings and violations in a report', function (): void {
        $report = new DiagnosticReport(
            participantCount: 4,
            expectedEvents: 6,
            generatedEvents: 4,
            missingEvents: 2,
            constraintViolations: ['seed protection rejected round 1'],
            impossiblePairings: ['Alice vs Bob']
        );

        $suggestions = $this->diagnostics->suggestConstraintAdjustments($report);

        expect($suggestions)->toContain('Some participant pairings cannot be satisfied with current constraints');
        expect($suggestions)->toContain('Review constraint configuration for potential conflicts');
    });

    it('suggests adjustments proportional to the failure', function (): void {
        $report = $this->diagnostics->analyzeSchedulingFailure(
            $this->participants,
            $this->constraints,
            [],
            new RoundRobinPlan($this->participants, 1),
            ['leg' => 2]
        );

        $suggestions = $this->diagnostics->suggestConstraintAdjustments($report);

        expect($suggestions)->toContain('Consider relaxing constraints that may be preventing event generation');
        expect($suggestions)->toContain('Multi-leg constraint validation may require different strategy');
    });
});
