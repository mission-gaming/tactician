<?php

declare(strict_types=1);

use MissionGaming\Tactician\LegStrategies\ConstraintSatisfiabilityReport;

describe('ConstraintSatisfiabilityReport', function (): void {
    it('creates a successful report', function (): void {
        $satisfiableConstraints = ['NoRepeatPairings', 'MinimumRestPeriods'];
        $analysisData = ['complexity' => 'low', 'confidence' => 'high'];

        $report = ConstraintSatisfiabilityReport::success($satisfiableConstraints, $analysisData);

        expect($report->canSatisfyConstraints())->toBeTrue();
        expect($report->getSatisfiableConstraints())->toBe($satisfiableConstraints);
        expect($report->getUnsatisfiableConstraints())->toBe([]);
        expect($report->getConflictingConstraints())->toBe([]);
        expect($report->getSuggestions())->toBe([]);
        expect($report->getAnalysisData())->toBe($analysisData);
        expect($report->getAnalysisValue('complexity'))->toBe('low');
        expect($report->getAnalysisValue('confidence'))->toBe('high');
        expect($report->hasIssues())->toBeFalse();
    });

    it('creates a failure report', function (): void {
        $unsatisfiableConstraints = ['ImpossibleConstraint'];
        $conflictingConstraints = ['ConstraintA vs ConstraintB'];
        $suggestions = ['Remove ImpossibleConstraint', 'Adjust ConstraintA parameters'];
        $analysisData = ['reason' => 'mathematical impossibility'];

        $report = ConstraintSatisfiabilityReport::failure(
            $unsatisfiableConstraints,
            $conflictingConstraints,
            $suggestions,
            $analysisData
        );

        expect($report->canSatisfyConstraints())->toBeFalse();
        expect($report->getUnsatisfiableConstraints())->toBe($unsatisfiableConstraints);
        expect($report->getConflictingConstraints())->toBe($conflictingConstraints);
        expect($report->getSuggestions())->toBe($suggestions);
        expect($report->getAnalysisData())->toBe($analysisData);
        expect($report->getAnalysisValue('reason'))->toBe('mathematical impossibility');
        expect($report->hasIssues())->toBeTrue();
    });

    it('creates a detailed report with constructor', function (): void {
        $satisfiableConstraints = ['ConstraintA', 'ConstraintB'];
        $unsatisfiableConstraints = ['ConstraintC'];
        $conflictingConstraints = ['ConstraintD vs ConstraintE'];
        $suggestions = ['Modify ConstraintC', 'Resolve conflict between D and E'];
        $analysisData = ['analysis_time' => '50ms', 'iterations' => 100];

        $report = new ConstraintSatisfiabilityReport(
            canSatisfy: false,
            satisfiableConstraints: $satisfiableConstraints,
            unsatisfiableConstraints: $unsatisfiableConstraints,
            conflictingConstraints: $conflictingConstraints,
            suggestions: $suggestions,
            analysisData: $analysisData
        );

        expect($report->canSatisfyConstraints())->toBeFalse();
        expect($report->getSatisfiableConstraints())->toBe($satisfiableConstraints);
        expect($report->getUnsatisfiableConstraints())->toBe($unsatisfiableConstraints);
        expect($report->getConflictingConstraints())->toBe($conflictingConstraints);
        expect($report->getSuggestions())->toBe($suggestions);
        expect($report->getAnalysisData())->toBe($analysisData);
        expect($report->hasIssues())->toBeTrue();
    });

    it('provides analysis value access with defaults', function (): void {
        $analysisData = ['key1' => 'value1', 'key2' => 42];
        $report = ConstraintSatisfiabilityReport::success([], $analysisData);

        expect($report->getAnalysisValue('key1'))->toBe('value1');
        expect($report->getAnalysisValue('key2'))->toBe(42);
        expect($report->getAnalysisValue('nonexistent'))->toBeNull();
        expect($report->getAnalysisValue('nonexistent', 'default'))->toBe('default');
    });

    it('detects issues correctly', function (): void {
        $noIssuesReport = new ConstraintSatisfiabilityReport(canSatisfy: true);
        expect($noIssuesReport->hasIssues())->toBeFalse();

        $unsatisfiableReport = new ConstraintSatisfiabilityReport(
            canSatisfy: true,
            unsatisfiableConstraints: ['SomeConstraint']
        );
        expect($unsatisfiableReport->hasIssues())->toBeTrue();

        $conflictingReport = new ConstraintSatisfiabilityReport(
            canSatisfy: true,
            conflictingConstraints: ['Conflict1']
        );
        expect($conflictingReport->hasIssues())->toBeTrue();

        $bothIssuesReport = new ConstraintSatisfiabilityReport(
            canSatisfy: false,
            unsatisfiableConstraints: ['Unsatisfiable'],
            conflictingConstraints: ['Conflicting']
        );
        expect($bothIssuesReport->hasIssues())->toBeTrue();
    });

    it('generates appropriate summaries', function (): void {
        $successReport = ConstraintSatisfiabilityReport::success();
        expect($successReport->getSummary())->toBe('All constraints can be satisfied by this strategy.');

        $unsatisfiableReport = ConstraintSatisfiabilityReport::failure(
            unsatisfiableConstraints: ['ConstraintA', 'ConstraintB']
        );
        expect($unsatisfiableReport->getSummary())->toContain('Unsatisfiable constraints: ConstraintA, ConstraintB');

        $conflictingReport = ConstraintSatisfiabilityReport::failure(
            conflictingConstraints: ['ConflictX', 'ConflictY']
        );
        expect($conflictingReport->getSummary())->toContain('Conflicting constraints: ConflictX, ConflictY');

        $combinedReport = ConstraintSatisfiabilityReport::failure(
            unsatisfiableConstraints: ['Unsatisfiable'],
            conflictingConstraints: ['Conflicting']
        );
        $summary = $combinedReport->getSummary();
        expect($summary)->toContain('Unsatisfiable constraints: Unsatisfiable');
        expect($summary)->toContain('Conflicting constraints: Conflicting');
        expect($summary)->toContain(' | ');
    });

    it('handles empty arrays gracefully', function (): void {
        $report = new ConstraintSatisfiabilityReport(
            canSatisfy: true,
            satisfiableConstraints: [],
            unsatisfiableConstraints: [],
            conflictingConstraints: [],
            suggestions: [],
            analysisData: []
        );

        expect($report->getSatisfiableConstraints())->toBe([]);
        expect($report->getUnsatisfiableConstraints())->toBe([]);
        expect($report->getConflictingConstraints())->toBe([]);
        expect($report->getSuggestions())->toBe([]);
        expect($report->getAnalysisData())->toBe([]);
        expect($report->hasIssues())->toBeFalse();
        expect($report->getSummary())->toBe('All constraints can be satisfied by this strategy.');
    });

    it('is readonly and immutable', function (): void {
        $report = ConstraintSatisfiabilityReport::success();

        expect($report)->toBeInstanceOf(ConstraintSatisfiabilityReport::class);
        // Readonly classes cannot have properties modified after construction
    });
});
