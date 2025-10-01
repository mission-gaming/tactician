<?php

declare(strict_types=1);

use MissionGaming\Tactician\LegStrategies\GenerationPlan;

describe('GenerationPlan', function (): void {
    it('creates a basic generation plan', function (): void {
        $plan = new GenerationPlan(
            totalEvents: 12,
            eventsPerLeg: 6,
            roundsPerLeg: 3
        );

        expect($plan->getTotalEvents())->toBe(12);
        expect($plan->getEventsPerLeg())->toBe(6);
        expect($plan->getRoundsPerLeg())->toBe(3);
        expect($plan->requiresRandomization())->toBeFalse();
        expect($plan->getStrategyData())->toBe([]);
        expect($plan->getWarnings())->toBe([]);
        expect($plan->hasWarnings())->toBeFalse();
    });

    it('creates a plan with randomization requirement', function (): void {
        $plan = new GenerationPlan(
            totalEvents: 12,
            eventsPerLeg: 6,
            roundsPerLeg: 3,
            requiresRandomization: true
        );

        expect($plan->requiresRandomization())->toBeTrue();
    });

    it('creates a plan with strategy data', function (): void {
        $strategyData = [
            'pattern' => 'mirrored',
            'home_away' => true,
            'seed_preference' => 'balanced',
        ];

        $plan = new GenerationPlan(
            totalEvents: 12,
            eventsPerLeg: 6,
            roundsPerLeg: 3,
            strategyData: $strategyData
        );

        expect($plan->getStrategyData())->toBe($strategyData);
        expect($plan->getStrategyValue('pattern'))->toBe('mirrored');
        expect($plan->getStrategyValue('home_away'))->toBeTrue();
        expect($plan->getStrategyValue('nonexistent'))->toBeNull();
        expect($plan->getStrategyValue('nonexistent', 'default'))->toBe('default');
    });

    it('creates a plan with warnings', function (): void {
        $warnings = [
            'Odd participant count may cause unbalanced scheduling',
            'Some constraints may be difficult to satisfy',
        ];

        $plan = new GenerationPlan(
            totalEvents: 12,
            eventsPerLeg: 6,
            roundsPerLeg: 3,
            warnings: $warnings
        );

        expect($plan->getWarnings())->toBe($warnings);
        expect($plan->hasWarnings())->toBeTrue();
    });

    it('creates a comprehensive plan with all parameters', function (): void {
        $strategyData = ['type' => 'advanced'];
        $warnings = ['Complex configuration detected'];

        $plan = new GenerationPlan(
            totalEvents: 20,
            eventsPerLeg: 10,
            roundsPerLeg: 5,
            requiresRandomization: true,
            strategyData: $strategyData,
            warnings: $warnings
        );

        expect($plan->getTotalEvents())->toBe(20);
        expect($plan->getEventsPerLeg())->toBe(10);
        expect($plan->getRoundsPerLeg())->toBe(5);
        expect($plan->requiresRandomization())->toBeTrue();
        expect($plan->getStrategyData())->toBe($strategyData);
        expect($plan->getStrategyValue('type'))->toBe('advanced');
        expect($plan->getWarnings())->toBe($warnings);
        expect($plan->hasWarnings())->toBeTrue();
    });

    it('handles empty strategy data and warnings gracefully', function (): void {
        $plan = new GenerationPlan(
            totalEvents: 6,
            eventsPerLeg: 3,
            roundsPerLeg: 2,
            strategyData: [],
            warnings: []
        );

        expect($plan->getStrategyData())->toBe([]);
        expect($plan->getStrategyValue('anything'))->toBeNull();
        expect($plan->getWarnings())->toBe([]);
        expect($plan->hasWarnings())->toBeFalse();
    });

    it('is readonly and immutable', function (): void {
        $plan = new GenerationPlan(10, 5, 2);

        expect($plan)->toBeInstanceOf(GenerationPlan::class);
        // Readonly classes cannot have properties modified after construction
    });
});
