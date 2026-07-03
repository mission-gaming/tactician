<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\LegStrategies\LegPlanContribution;
use MissionGaming\Tactician\LegStrategies\LegStrategyInterface;
use MissionGaming\Tactician\LegStrategies\MirroredLegStrategy;
use MissionGaming\Tactician\LegStrategies\RepeatedLegStrategy;
use MissionGaming\Tactician\LegStrategies\ShuffledLegStrategy;

/**
 * @return array<Participant>
 */
function contributionParticipants(int $count): array
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

describe('LegPlanContribution', function (): void {
    it('defaults to satisfiable with no warnings', function (): void {
        $contribution = new LegPlanContribution(
            rolesMirrorAcrossLegs: true,
            requiresRandomization: false
        );

        expect($contribution->rolesMirrorAcrossLegs)->toBeTrue();
        expect($contribution->requiresRandomization)->toBeFalse();
        expect($contribution->unsatisfiableReasons)->toBe([]);
        expect($contribution->warnings)->toBe([]);
    });

    it('carries unsatisfiable reasons and warnings', function (): void {
        $contribution = new LegPlanContribution(
            rolesMirrorAcrossLegs: false,
            requiresRandomization: false,
            unsatisfiableReasons: ['impossible configuration'],
            warnings: ['a warning']
        );

        expect($contribution->unsatisfiableReasons)->toBe(['impossible configuration']);
        expect($contribution->warnings)->toBe(['a warning']);
    });
});

describe('LegStrategy plan contributions', function (): void {
    // Strategies contribute facts only — never schedule shape. The
    // rounds-per-leg and event-count arithmetic that used to drift between
    // strategies and the generator now lives solely in RoundRobinPlan.
    it('declares role mirroring only for the mirrored strategy', function (): void {
        $constraints = ConstraintSet::create()->build();
        $participants = contributionParticipants(4);

        $expectations = [
            'mirrored' => ['mirrors' => true, 'randomizes' => false],
            'repeated' => ['mirrors' => false, 'randomizes' => false],
            'shuffled' => ['mirrors' => false, 'randomizes' => true],
        ];

        foreach (allLegStrategies() as $name => $strategy) {
            $contribution = $strategy->planLegs($participants, 2, $constraints);

            expect($contribution->rolesMirrorAcrossLegs)
                ->toBe($expectations[$name]['mirrors'], "{$name}: role mirroring");
            expect($contribution->requiresRandomization)
                ->toBe($expectations[$name]['randomizes'], "{$name}: randomization");
            expect($contribution->unsatisfiableReasons)->toBe([], "{$name}: satisfiable");
        }
    });
});
