<?php

declare(strict_types=1);

use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Scheduling\PotDrawOptions;
use MissionGaming\Tactician\Scheduling\SchedulerOptions;

/**
 * The configuration error a call ends in.
 *
 * @param Closure(): mixed $call
 */
function potDrawOptionsRefusal(Closure $call): InvalidConfigurationException
{
    try {
        $call();
    } catch (InvalidConfigurationException $exception) {
        return $exception;
    }

    throw new LogicException('The call was not refused.');
}

describe('PotDrawOptions', function (): void {
    it('is a scheduler options type', function (): void {
        expect(new PotDrawOptions())->toBeInstanceOf(SchedulerOptions::class);
    });

    it('defaults to one pot, one opponent per pot and seed 0', function (): void {
        $options = new PotDrawOptions();

        expect($options->pots)->toBe(1)
            ->and($options->opponentsPerPot)->toBe(1)
            ->and($options->seed)->toBe(0)
            ->and(PotDrawOptions::fromArray([])->toArray())->toBe($options->toArray());
    });

    it('is built from plain data', function (): void {
        $options = PotDrawOptions::fromArray(['pots' => 4, 'opponents_per_pot' => 2, 'seed' => 2026]);

        expect($options->pots)->toBe(4)
            ->and($options->opponentsPerPot)->toBe(2)
            ->and($options->seed)->toBe(2026);
    });

    it('serializes to the plain data it is built from', function (): void {
        $data = ['pots' => 4, 'opponents_per_pot' => 2, 'seed' => -7];

        expect((new PotDrawOptions(4, 2, -7))->toArray())->toBe($data)
            ->and(PotDrawOptions::fromArray($data)->toArray())->toBe($data);
    });

    it('takes the default for a key that is left out', function (): void {
        expect(PotDrawOptions::fromArray(['pots' => 3])->toArray())
            ->toBe(['pots' => 3, 'opponents_per_pot' => 1, 'seed' => 0]);
    });

    it('throws on an unknown key', function (array $config, array $unknown): void {
        $refusal = potDrawOptionsRefusal(fn() => PotDrawOptions::fromArray($config));

        expect($refusal->getReason())->toBe(InvalidConfigurationReason::UnknownOptionKey)
            ->and($refusal->getContext())->toBe([
                'unknown' => $unknown,
                'known' => ['pots', 'opponents_per_pot', 'seed'],
            ]);
    })->with([
        'a misspelt key' => [['pots' => 4, 'opponents_per_pots' => 2], ['opponents_per_pots']],
        'the constructor name of a key' => [['pots' => 4, 'opponentsPerPot' => 2], ['opponentsPerPot']],
        'the option of another format' => [['rounds' => 8], ['rounds']],
        'two unknown keys' => [['pots' => 4, 'legs' => 2, 'rounds' => 8], ['legs', 'rounds']],
        'a list' => [[4, 2], [0, 1]],
    ]);

    it('throws on a value that is not an integer', function (array $config, array $context): void {
        $refusal = potDrawOptionsRefusal(fn() => PotDrawOptions::fromArray($config));

        expect($refusal->getReason())->toBe(InvalidConfigurationReason::WrongValueType)
            ->and($refusal->getContext())->toBe($context);
    })->with([
        'pots as a string' => [['pots' => '4'], ['pots' => '4']],
        'opponents per pot as a float' => [['opponents_per_pot' => 2.0], ['opponents_per_pot' => 2.0]],
        'a null seed' => [['seed' => null], ['seed' => null]],
        'a seed as a string' => [['seed' => 'lucky'], ['seed' => 'lucky']],
    ]);

    it('throws on a count below 1', function (Closure $build, array $context): void {
        $refusal = potDrawOptionsRefusal($build);

        expect($refusal->getReason())->toBe(InvalidConfigurationReason::ValueOutOfRange)
            ->and($refusal->getContext())->toBe($context);
    })->with([
        'zero pots' => [fn() => new PotDrawOptions(pots: 0), ['pots' => 0, 'minimum_required' => 1]],
        'negative pots from plain data' => [fn() => PotDrawOptions::fromArray(['pots' => -2]), ['pots' => -2, 'minimum_required' => 1]],
        'zero opponents per pot' => [fn() => new PotDrawOptions(opponentsPerPot: 0), ['opponents_per_pot' => 0, 'minimum_required' => 1]],
        'zero opponents per pot from plain data' => [
            fn() => PotDrawOptions::fromArray(['opponents_per_pot' => 0]),
            ['opponents_per_pot' => 0, 'minimum_required' => 1],
        ],
    ]);

    it('accepts any integer as a seed', function (int $seed): void {
        expect((new PotDrawOptions(seed: $seed))->seed)->toBe($seed);
    })->with([0, -1, PHP_INT_MAX, PHP_INT_MIN]);
});
