<?php

declare(strict_types=1);

use MissionGaming\Tactician\Diagnostics\DiagnosticReport;

describe('DiagnosticReport', function (): void {
    it('creates a basic diagnostic report', function (): void {
        $report = new DiagnosticReport(
            participantCount: 4,
            expectedEvents: 12,
            generatedEvents: 8,
            missingEvents: 4
        );

        expect($report->getParticipantCount())->toBe(4);
        expect($report->getExpectedEvents())->toBe(12);
        expect($report->getGeneratedEvents())->toBe(8);
        expect($report->getMissingEvents())->toBe(4);
        expect($report->getMissingPairings())->toBe([]);
        expect($report->getConstraintViolations())->toBe([]);
        expect($report->getImpossiblePairings())->toBe([]);
        expect($report->getSuggestions())->toBe([]);
        expect($report->getAnalysisContext())->toBe([]);
    });

    it('creates a comprehensive diagnostic report', function (): void {
        $missingPairings = ['Alice vs Bob', 'Carol vs Dave'];
        $constraintViolations = ['NoRepeatPairings violated in round 3'];
        $impossiblePairings = ['Alice vs Alice (same participant)'];
        $suggestions = ['Relax constraints', 'Add more participants'];
        $analysisContext = ['leg' => 2, 'algorithm' => 'round-robin'];

        $report = new DiagnosticReport(
            participantCount: 4,
            expectedEvents: 12,
            generatedEvents: 6,
            missingEvents: 6,
            missingPairings: $missingPairings,
            constraintViolations: $constraintViolations,
            impossiblePairings: $impossiblePairings,
            suggestions: $suggestions,
            analysisContext: $analysisContext
        );

        expect($report->getMissingPairings())->toBe($missingPairings);
        expect($report->getConstraintViolations())->toBe($constraintViolations);
        expect($report->getImpossiblePairings())->toBe($impossiblePairings);
        expect($report->getSuggestions())->toBe($suggestions);
        expect($report->getAnalysisContext())->toBe($analysisContext);
        expect($report->getContextValue('leg'))->toBe(2);
        expect($report->getContextValue('algorithm'))->toBe('round-robin');
        expect($report->getContextValue('nonexistent'))->toBeNull();
        expect($report->getContextValue('nonexistent', 'default'))->toBe('default');
    });

    it('calculates completion percentage correctly', function (): void {
        $report = new DiagnosticReport(
            participantCount: 4,
            expectedEvents: 10,
            generatedEvents: 7,
            missingEvents: 3
        );

        expect($report->getCompletionPercentage())->toBe(70.0);

        $zeroEventsReport = new DiagnosticReport(
            participantCount: 2,
            expectedEvents: 0,
            generatedEvents: 0,
            missingEvents: 0
        );

        expect($zeroEventsReport->getCompletionPercentage())->toBe(0.0);
    });

    it('determines success status correctly', function (): void {
        $successfulReport = new DiagnosticReport(
            participantCount: 4,
            expectedEvents: 6,
            generatedEvents: 6,
            missingEvents: 0
        );
        expect($successfulReport->isSuccessful())->toBeTrue();

        $incompleteReport = new DiagnosticReport(
            participantCount: 4,
            expectedEvents: 6,
            generatedEvents: 4,
            missingEvents: 2
        );
        expect($incompleteReport->isSuccessful())->toBeFalse();

        $violationReport = new DiagnosticReport(
            participantCount: 4,
            expectedEvents: 6,
            generatedEvents: 6,
            missingEvents: 0,
            constraintViolations: ['Some violation']
        );
        expect($violationReport->isSuccessful())->toBeFalse();
    });

    it('identifies critical issues correctly', function (): void {
        $noCriticalIssues = new DiagnosticReport(
            participantCount: 4,
            expectedEvents: 10,
            generatedEvents: 8,
            missingEvents: 2
        );
        expect($noCriticalIssues->hasCriticalIssues())->toBeFalse();

        $impossiblePairings = new DiagnosticReport(
            participantCount: 4,
            expectedEvents: 10,
            generatedEvents: 8,
            missingEvents: 2,
            impossiblePairings: ['Impossible pairing']
        );
        expect($impossiblePairings->hasCriticalIssues())->toBeTrue();

        $constraintViolations = new DiagnosticReport(
            participantCount: 4,
            expectedEvents: 10,
            generatedEvents: 8,
            missingEvents: 2,
            constraintViolations: ['Violation']
        );
        expect($constraintViolations->hasCriticalIssues())->toBeTrue();

        $tooManyMissing = new DiagnosticReport(
            participantCount: 4,
            expectedEvents: 10,
            generatedEvents: 2,
            missingEvents: 8
        );
        expect($tooManyMissing->hasCriticalIssues())->toBeTrue();
    });

    it('generates appropriate summaries', function (): void {
        $successReport = new DiagnosticReport(
            participantCount: 4,
            expectedEvents: 6,
            generatedEvents: 6,
            missingEvents: 0
        );
        expect($successReport->getSummary())->toBe('Schedule generation completed successfully.');

        $failureReport = new DiagnosticReport(
            participantCount: 4,
            expectedEvents: 10,
            generatedEvents: 6,
            missingEvents: 4,
            constraintViolations: ['Violation1', 'Violation2'],
            impossiblePairings: ['Impossible1']
        );

        $summary = $failureReport->getSummary();
        expect($summary)->toContain('Schedule generation failed at 60% completion');
        expect($summary)->toContain('4 events could not be generated');
        expect($summary)->toContain('2 constraint violations detected');
        expect($summary)->toContain('1 impossible pairings identified');
    });

    it('generates detailed string representation', function (): void {
        $report = new DiagnosticReport(
            participantCount: 3,
            expectedEvents: 6,
            generatedEvents: 4,
            missingEvents: 2,
            missingPairings: ['Alice vs Bob', 'Bob vs Carol'],
            constraintViolations: ['NoRepeat violated'],
            impossiblePairings: ['Invalid pairing'],
            suggestions: ['Add participants', 'Relax constraints']
        );

        $output = $report->toString();

        expect($output)->toContain('=== SCHEDULING DIAGNOSTIC REPORT ===');
        expect($output)->toContain('Participants: 3');
        expect($output)->toContain('Expected Events: 6');
        expect($output)->toContain('Generated Events: 4');
        expect($output)->toContain('Missing Events: 2');
        expect($output)->toContain('Completion: 66.7%');
        expect($output)->toContain('Missing Pairings:');
        expect($output)->toContain('- Alice vs Bob');
        expect($output)->toContain('- Bob vs Carol');
        expect($output)->toContain('Constraint Violations:');
        expect($output)->toContain('- NoRepeat violated');
        expect($output)->toContain('Impossible Pairings:');
        expect($output)->toContain('- Invalid pairing');
        expect($output)->toContain('Suggestions:');
        expect($output)->toContain('- Add participants');
        expect($output)->toContain('- Relax constraints');
    });

    it('handles truncation of large missing pairings lists', function (): void {
        $manyPairings = [];
        for ($i = 1; $i <= 15; ++$i) {
            $manyPairings[] = "Pairing {$i}";
        }

        $report = new DiagnosticReport(
            participantCount: 10,
            expectedEvents: 20,
            generatedEvents: 5,
            missingEvents: 15,
            missingPairings: $manyPairings
        );

        $output = $report->toString();

        expect($output)->toContain('- Pairing 1');
        expect($output)->toContain('- Pairing 10');
        expect($output)->toContain('... and 5 more');
        expect($output)->not->toContain('- Pairing 11');
    });

    it('handles empty sections gracefully', function (): void {
        $report = new DiagnosticReport(
            participantCount: 4,
            expectedEvents: 6,
            generatedEvents: 6,
            missingEvents: 0
        );

        $output = $report->toString();

        expect($output)->toContain('=== SCHEDULING DIAGNOSTIC REPORT ===');
        expect($output)->toContain('Participants: 4');
        expect($output)->not->toContain('Missing Pairings:');
        expect($output)->not->toContain('Constraint Violations:');
        expect($output)->not->toContain('Impossible Pairings:');
        expect($output)->not->toContain('Suggestions:');
    });

    it('is readonly and immutable', function (): void {
        $report = new DiagnosticReport(4, 10, 8, 2);

        expect($report)->toBeInstanceOf(DiagnosticReport::class);
        // Readonly classes cannot have properties modified after construction
    });
});
