<?php

declare(strict_types=1);

use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Repack\RepackOptions;

describe('RepackOptions', function (): void {
    it('defaults to consolidation-dominant weights and a bounded budget', function (): void {
        $options = new RepackOptions();

        expect($options->consolidationWeight)->toBe(3);
        expect($options->earlyFillWeight)->toBe(1);
        expect($options->stepBudget)->toBe(200_000);
        expect($options->throwOnViolations)->toBeFalse();
    });

    it('round-trips through plain configuration data', function (): void {
        $options = RepackOptions::fromArray([
            'consolidation_weight' => 5,
            'early_fill_weight' => 2,
            'step_budget' => 1_000,
            'throw_on_violations' => true,
        ]);

        expect(RepackOptions::fromArray($options->toArray())->toArray())->toBe($options->toArray());
    });

    it('rejects negative weights', function (): void {
        new RepackOptions(consolidationWeight: -1);
    })->throws(InvalidConfigurationException::class);

    it('rejects a non-positive step budget', function (): void {
        new RepackOptions(stepBudget: 0);
    })->throws(InvalidConfigurationException::class);

    it('rejects malformed configuration values', function (): void {
        RepackOptions::fromArray(['step_budget' => 'plenty']);
    })->throws(InvalidConfigurationException::class);
});
