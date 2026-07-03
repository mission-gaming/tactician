<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\LegStrategies\LegStrategyInterface;
use MissionGaming\Tactician\LegStrategies\MirroredLegStrategy;
use MissionGaming\Tactician\LegStrategies\RepeatedLegStrategy;
use MissionGaming\Tactician\LegStrategies\ShuffledLegStrategy;

/**
 * @return array<Participant>
 */
function planningParticipants(int $count): array
{
    $participants = [];
    for ($i = 1; $i <= $count; ++$i) {
        $participants[] = new Participant("p{$i}", "Player {$i}");
    }

    return $participants;
}

/**
 * @return array<string, LegStrategyInterface>
 */
function allLegStrategies(): array
{
    return [
        'mirrored' => new MirroredLegStrategy(),
        'repeated' => new RepeatedLegStrategy(),
        'shuffled' => new ShuffledLegStrategy(),
    ];
}

describe('LegStrategy planning', function (): void {
    // Regression: plans reported n-1 rounds per leg for odd participant
    // counts, which need n rounds for the bye rotation.
    it('plans the correct rounds per leg for odd and even participant counts', function (): void {
        $constraints = ConstraintSet::create()->build();

        foreach (allLegStrategies() as $name => $strategy) {
            $evenPlan = $strategy->planGeneration(planningParticipants(4), 2, 2, $constraints);
            expect($evenPlan->getRoundsPerLeg())->toBe(3, "{$name}: even count should need n-1 rounds");
            expect($evenPlan->getEventsPerLeg())->toBe(6);
            expect($evenPlan->getTotalEvents())->toBe(12);

            $oddPlan = $strategy->planGeneration(planningParticipants(5), 2, 2, $constraints);
            expect($oddPlan->getRoundsPerLeg())->toBe(5, "{$name}: odd count should need n rounds");
            expect($oddPlan->getEventsPerLeg())->toBe(10);
            expect($oddPlan->getTotalEvents())->toBe(20);
        }
    });

    // Regression: failure reasons were passed into the report's
    // satisfiableConstraints slot, so failing reports carried no explanation.
    it('reports unsatisfiable configurations with reasons in the correct slot', function (): void {
        $constraints = ConstraintSet::create()->build();

        foreach (allLegStrategies() as $name => $strategy) {
            $report = $strategy->canSatisfyConstraints(planningParticipants(4), 2, 3, $constraints);

            expect($report->canSatisfyConstraints())->toBeFalse("{$name} should reject 3 participants per event");
            expect($report->hasIssues())->toBeTrue();
            expect($report->getUnsatisfiableConstraints())->not->toBeEmpty();
            expect($report->getSatisfiableConstraints())->toBeEmpty();
            expect($report->getSummary())->toContain('2 participants per event');
        }
    });

    it('reports satisfiable configurations without issues', function (): void {
        $constraints = ConstraintSet::create()->build();

        foreach (allLegStrategies() as $name => $strategy) {
            $report = $strategy->canSatisfyConstraints(planningParticipants(4), 2, 2, $constraints);

            expect($report->canSatisfyConstraints())->toBeTrue("{$name} should accept a standard configuration");
            expect($report->hasIssues())->toBeFalse();
        }
    });
});
