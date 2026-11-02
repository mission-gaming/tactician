<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
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
        expect(fn() => new EliminationPlan(eliminationPlanField(4), 'triple-elimination'))
            ->toThrow(InvalidConfigurationException::class, 'algorithm');
        expect(fn() => new EliminationPlan(eliminationPlanField(4), 'single-elimination', 3))
            ->toThrow(InvalidConfigurationException::class, '1 or 2');
    });

    it('rejects fewer than 2 participants', function (): void {
        new EliminationPlan(eliminationPlanField(1), 'single-elimination');
    })->throws(InvalidConfigurationException::class);

    // One tie more in the last round: the rounds are the rounds of the
    // bracket without it, and the events are one tie's more
    it('counts the third-place tie in the events and not in the rounds', function (int $legsPerTie): void {
        foreach ([4, 5, 6, 7, 8, 9, 16, 33] as $size) {
            $without = new EliminationPlan(eliminationPlanField($size), 'single-elimination', $legsPerTie);
            $with = new EliminationPlan(eliminationPlanField($size), 'single-elimination', $legsPerTie, true);

            expect($without->hasThirdPlaceTie())->toBeFalse("n={$size}")
                ->and($without->getExpectedEventCount())->toBe(($size - 1) * $legsPerTie, "n={$size}")
                ->and($with->hasThirdPlaceTie())->toBeTrue("n={$size}")
                ->and($with->getExpectedEventCount())->toBe($size * $legsPerTie, "n={$size}")
                ->and($with->getTotalRounds())->toBe($without->getTotalRounds(), "n={$size}")
                ->and($with->getBracketSize())->toBe($without->getBracketSize(), "n={$size}");
        }
    })->with([[1], [2]]);

    // Three entrants: one goes out before the final and is third
    it('has no third-place tie in a field of three', function (int $legsPerTie): void {
        $plan = new EliminationPlan(eliminationPlanField(3), 'single-elimination', $legsPerTie, true);

        expect($plan->hasThirdPlaceTie())->toBeFalse()
            ->and($plan->getExpectedEventCount())->toBe(2 * $legsPerTie)
            ->and($plan->getTotalRounds())->toBe(2);
    })->with([[1], [2]]);

    it('refuses the third-place option for a field of two', function (): void {
        try {
            new EliminationPlan(eliminationPlanField(2), 'single-elimination', 1, true);
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getReason())->toBe(InvalidConfigurationReason::TooFewParticipants)
                ->and($exception->getContext())->toBe(['participant_count' => 2, 'minimum_required' => 3])
                ->and($exception->getMessage())->toContain('no third place to decide');

            return;
        }

        $this->fail('A field of two was given a third-place option.');
    });

    it('refuses the third-place option for double elimination', function (): void {
        try {
            new EliminationPlan(eliminationPlanField(8), 'double-elimination', 1, true);
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getReason())->toBe(InvalidConfigurationReason::IncompatibleOptions)
                ->and($exception->getMessage())->toContain('losers bracket');

            return;
        }

        $this->fail('A double elimination plan was given a third-place option.');
    });

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
            'format_version' => 1,
            'legs_per_tie' => 2,
            'reseed_each_round' => true,
            'grand_final_reset' => false,
        ]);

        expect(EliminationOptions::fromArray([])->toArray())
            ->toBe(['format_version' => 1, 'legs_per_tie' => 1, 'reseed_each_round' => false, 'grand_final_reset' => true]);
    });

    // The form of options without the tie has the keys it had before the
    // option existed: the key is written only when the option is on, as
    // `seed` is in Swiss options (an optional key of version 1, ADR 0005)
    it('writes no third-place key for options without the tie', function (): void {
        expect(new EliminationOptions(thirdPlace: false)->toArray())
            ->toBe(['format_version' => 1, 'legs_per_tie' => 1, 'reseed_each_round' => false, 'grand_final_reset' => true])
            ->and(EliminationOptions::fromArray(['third_place' => false])->toArray())->not->toHaveKey('third_place');
    });

    it('has no third-place tie unless it is asked for', function (): void {
        expect(new EliminationOptions()->thirdPlace)->toBeFalse()
            ->and(new EliminationOptions(2, true, false)->thirdPlace)->toBeFalse()
            ->and(new EliminationOptions(thirdPlace: true)->thirdPlace)->toBeTrue();
    });

    // Options stored before the option existed hold three keys
    it('reads plain data without the third-place key as a bracket without the tie', function (): void {
        $stored = ['legs_per_tie' => 2, 'reseed_each_round' => true, 'grand_final_reset' => false];

        expect(EliminationOptions::fromArray($stored)->thirdPlace)->toBeFalse()
            ->and(EliminationOptions::fromArray($stored))->toEqual(new EliminationOptions(2, true, false));
    });

    it('round-trips the third-place option through plain configuration data', function (): void {
        $options = EliminationOptions::fromArray(['third_place' => true]);

        expect($options->thirdPlace)->toBeTrue()
            ->and($options->toArray())->toBe([
                'format_version' => 1,
                'legs_per_tie' => 1,
                'reseed_each_round' => false,
                'grand_final_reset' => true,
                'third_place' => true,
            ])
            ->and(EliminationOptions::fromArray($options->toArray()))->toEqual($options);
    });

    it('rejects a third-place value that is not a boolean', function (mixed $value): void {
        try {
            EliminationOptions::fromArray(['third_place' => $value]);
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getReason())->toBe(InvalidConfigurationReason::WrongValueType)
                ->and($exception->getMessage())->toContain('third_place must be a boolean')
                ->and($exception->getContext())->toBe(['third_place' => $value]);

            return;
        }

        $this->fail('A third_place value that is not a boolean was accepted.');
    })->with([['yes'], [1], [0], ['']]);

    it('rejects invalid configuration', function (): void {
        expect(fn() => new EliminationOptions(legsPerTie: 3))
            ->toThrow(InvalidConfigurationException::class, '1 or 2');
        expect(fn() => EliminationOptions::fromArray(['legs_per_tie' => 'two']))
            ->toThrow(InvalidConfigurationException::class, 'integer');
        expect(fn() => EliminationOptions::fromArray(['reseed_each_round' => 'yes']))
            ->toThrow(InvalidConfigurationException::class, 'boolean');
    });
});
