<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Scheduling\EliminationOptions;
use MissionGaming\Tactician\Stage\EliminationPlan;
use MissionGaming\Tactician\Stage\PairwisePlan;

/**
 * @return array<Participant>
 */
function eliminationPlanField(int $count): array
{
    $participants = [];
    for ($i = 1; $i <= $count; ++$i) {
        $participants[] = new Participant("s{$i}", "Seed {$i}");
    }

    return $participants;
}

describe('EliminationPlan', function (): void {
    it('knows single elimination shape up front', function (): void {
        $plan = new EliminationPlan(eliminationPlanField(6), 'single-elimination');

        expect($plan->getAlgorithm())->toBe('single-elimination');
        expect($plan->getBracketSize())->toBe(8);
        expect($plan->getTotalRounds())->toBe(3);
        expect($plan->getExpectedEventCount())->toBe(5); // n-1 ties
        expect($plan->getLegsPerTie())->toBe(1);
        expect($plan->getParticipants())->toHaveCount(6);
        expect($plan)->not->toBeInstanceOf(PairwisePlan::class);
    });

    // Brackets have no legs: legsPerTie is a tie-structure fact, and
    // conflating the two would recreate the legs/rounds overload
    it('reports null legs even with two-legged ties', function (): void {
        $plan = new EliminationPlan(eliminationPlanField(4), 'single-elimination', 2);

        expect($plan->getLegs())->toBeNull();
        expect($plan->getRoundsPerLeg())->toBeNull();
        expect($plan->getLegsPerTie())->toBe(2);
        expect($plan->getExpectedEventCount())->toBe(6); // 3 ties x 2 legs
    });

    it('reports unknowable totals for double elimination', function (): void {
        $plan = new EliminationPlan(eliminationPlanField(8), 'double-elimination');

        // The grand final may or may not reset
        expect($plan->getTotalRounds())->toBeNull();
        expect($plan->getExpectedEventCount())->toBeNull();
    });

    // Invalid identifiers were silently treated as double elimination
    // (null totals); invalid leg counts produced nonsensical event counts
    it('rejects unknown algorithm identifiers and invalid leg counts', function (): void {
        expect(fn () => new EliminationPlan(eliminationPlanField(4), 'triple-elimination'))
            ->toThrow(InvalidConfigurationException::class, 'algorithm');
        expect(fn () => new EliminationPlan(eliminationPlanField(4), 'single-elimination', 3))
            ->toThrow(InvalidConfigurationException::class, '1 or 2');
    });

    it('rejects fewer than 2 participants', function (): void {
        new EliminationPlan(eliminationPlanField(1), 'single-elimination');
    })->throws(InvalidConfigurationException::class);

    it('validates schedule integrity against its participants', function (): void {
        [$s1, $s2] = eliminationPlanField(2);
        $plan = new EliminationPlan([$s1, $s2], 'single-elimination');
        $outsider = new Participant('x1', 'Outsider');

        expect($plan->validateIntegrity(new Schedule([
            new Event([$s1, $s2], new Round(1)),
        ])))->toBe([]);

        $violations = $plan->validateIntegrity(new Schedule([
            new Event([$s1, $outsider], new Round(1)),
            new Event([$s1, $s2, $outsider], new Round(1)),
        ]));
        expect($violations)->toHaveCount(2);
        expect($violations[0])->toContain('not in the tournament');
        expect($violations[1])->toContain('exactly 2 participants');
    });
});

describe('EliminationOptions', function (): void {
    it('defaults to single-leg fixed-path brackets with a reset', function (): void {
        $options = new EliminationOptions();

        expect($options->legsPerTie)->toBe(1);
        expect($options->reseedEachRound)->toBeFalse();
        expect($options->grandFinalReset)->toBeTrue();
    });

    it('round-trips through plain configuration data', function (): void {
        $options = EliminationOptions::fromArray([
            'legs_per_tie' => 2,
            'reseed_each_round' => true,
            'grand_final_reset' => false,
        ]);

        expect($options->toArray())->toBe([
            'legs_per_tie' => 2,
            'reseed_each_round' => true,
            'grand_final_reset' => false,
        ]);

        expect(EliminationOptions::fromArray([])->toArray())
            ->toBe(['legs_per_tie' => 1, 'reseed_each_round' => false, 'grand_final_reset' => true]);
    });

    it('rejects invalid configuration', function (): void {
        expect(fn () => new EliminationOptions(legsPerTie: 3))
            ->toThrow(InvalidConfigurationException::class, '1 or 2');
        expect(fn () => EliminationOptions::fromArray(['legs_per_tie' => 'two']))
            ->toThrow(InvalidConfigurationException::class, 'integer');
        expect(fn () => EliminationOptions::fromArray(['reseed_each_round' => 'yes']))
            ->toThrow(InvalidConfigurationException::class, 'boolean');
    });
});
