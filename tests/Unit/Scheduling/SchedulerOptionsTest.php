<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\LegStrategies\MirroredLegStrategy;
use MissionGaming\Tactician\LegStrategies\RepeatedLegStrategy;
use MissionGaming\Tactician\LegStrategies\ShuffledLegStrategy;
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Scheduling\SwissOptions;
use MissionGaming\Tactician\Scheduling\SwissScheduler;

describe('RoundRobinOptions', function (): void {
    it('defaults to a single mirrored leg', function (): void {
        $options = new RoundRobinOptions();

        expect($options->legs)->toBe(1);
        expect($options->strategy)->toBeInstanceOf(MirroredLegStrategy::class);
    });

    it('rejects a non-positive leg count', function (): void {
        new RoundRobinOptions(legs: 0);
    })->throws(InvalidConfigurationException::class);

    it('round-trips through plain configuration data', function (): void {
        foreach (['mirrored', 'repeated', 'shuffled'] as $identifier) {
            $options = RoundRobinOptions::fromArray(['legs' => 2, 'strategy' => $identifier]);

            expect($options->toArray())->toBe(['legs' => 2, 'strategy' => $identifier, 'backtracking' => false]);
        }
    });

    it('maps stable strategy identifiers to the built-in strategies', function (): void {
        expect(RoundRobinOptions::fromArray(['strategy' => 'mirrored'])->strategy)
            ->toBeInstanceOf(MirroredLegStrategy::class);
        expect(RoundRobinOptions::fromArray(['strategy' => 'repeated'])->strategy)
            ->toBeInstanceOf(RepeatedLegStrategy::class);
        expect(RoundRobinOptions::fromArray(['strategy' => 'shuffled'])->strategy)
            ->toBeInstanceOf(ShuffledLegStrategy::class);
    });

    it('builds from empty configuration with the documented defaults', function (): void {
        expect(RoundRobinOptions::fromArray([])->toArray())
            ->toBe(['legs' => 1, 'strategy' => 'mirrored', 'backtracking' => false]);
    });

    it('rejects unknown strategy identifiers', function (): void {
        RoundRobinOptions::fromArray(['strategy' => 'time-travel']);
    })->throws(InvalidConfigurationException::class);

    it('rejects non-integer legs configuration', function (): void {
        RoundRobinOptions::fromArray(['legs' => 'two']);
    })->throws(InvalidConfigurationException::class);

    // Custom strategies have no stable identifier, so serializing them
    // would produce config fromArray() could not rebuild
    it('refuses to serialize a custom strategy', function (): void {
        $custom = new readonly class () extends MirroredLegStrategy {
        };

        (new RoundRobinOptions(legs: 2, strategy: $custom))->toArray();
    })->throws(InvalidConfigurationException::class);
});

describe('SwissOptions', function (): void {
    it('defaults to 3 rounds', function (): void {
        expect((new SwissOptions())->rounds)->toBe(3);
    });

    it('rejects a non-positive round count', function (): void {
        new SwissOptions(rounds: 0);
    })->throws(InvalidConfigurationException::class);

    it('round-trips through plain configuration data', function (): void {
        $options = SwissOptions::fromArray(['rounds' => 5]);

        expect($options->rounds)->toBe(5);
        expect($options->toArray())->toBe(['rounds' => 5]);
    });

    it('rejects non-integer rounds configuration', function (): void {
        SwissOptions::fromArray(['rounds' => 'five']);
    })->throws(InvalidConfigurationException::class);
});

describe('Scheduler options type checks', function (): void {
    beforeEach(function (): void {
        $this->participants = [
            new Participant('p1', 'Alice'),
            new Participant('p2', 'Bob'),
            new Participant('p3', 'Carol'),
            new Participant('p4', 'Dave'),
        ];
    });

    it('rejects another algorithm options type on the round-robin scheduler', function (): void {
        expect(fn () => (new RoundRobinScheduler())->schedule($this->participants, new SwissOptions()))
            ->toThrow(InvalidConfigurationException::class, 'RoundRobinOptions');
    });

    it('rejects another algorithm options type on the Swiss scheduler', function (): void {
        expect(fn () => (new SwissScheduler())->schedule($this->participants, new RoundRobinOptions()))
            ->toThrow(InvalidConfigurationException::class, 'SwissOptions');
    });

    // The plan reflects the configured strategy, not a default
    it('builds the round-robin plan from the configured strategy', function (): void {
        $plan = (new RoundRobinScheduler())->getPlan(
            $this->participants,
            new RoundRobinOptions(legs: 2, strategy: new ShuffledLegStrategy())
        );

        expect($plan->getLegs())->toBe(2);
        expect($plan->rolesMirrorAcrossLegs())->toBeFalse();
        expect($plan->requiresRandomization())->toBeTrue();
    });

    it('builds the Swiss plan from the configured rounds', function (): void {
        $plan = (new SwissScheduler())->getPlan($this->participants, new SwissOptions(rounds: 2));

        expect($plan->getTotalRounds())->toBe(2);
        expect($plan->getLegs())->toBeNull();
    });
});
