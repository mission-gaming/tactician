<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\ConstraintInterface;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\LegStrategies\MirroredLegStrategy;
use MissionGaming\Tactician\LegStrategies\RepeatedLegStrategy;
use MissionGaming\Tactician\LegStrategies\ShuffledLegStrategy;
use MissionGaming\Tactician\RoleAssignment\BalancedRoleAssignment;
use MissionGaming\Tactician\RoleAssignment\BalancedWherePossibleRoleAssignment;
use MissionGaming\Tactician\RoleAssignment\RoleAssignmentContext;
use MissionGaming\Tactician\RoleAssignment\RoleAssignmentInterface;
use MissionGaming\Tactician\RoleAssignment\RoundParityRoleAssignment;
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Scheduling\SwissOptions;
use MissionGaming\Tactician\Scheduling\SwissScheduler;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * @param Closure(): mixed $build
 */
function roundRobinOptionsRefusal(Closure $build): ?InvalidConfigurationException
{
    try {
        $build();
    } catch (InvalidConfigurationException $refusal) {
        return $refusal;
    }

    return null;
}

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
        foreach (['mirrored', 'repeated'] as $identifier) {
            $options = RoundRobinOptions::fromArray(['legs' => 2, 'strategy' => $identifier]);

            expect($options->toArray())->toBe(['format_version' => 1, 'legs' => 2, 'strategy' => $identifier, 'backtracking' => false]);
        }
    });

    // The shuffled strategy draws, so its plain data names the seed of the
    // draws: stored options then say everything that decides the schedule
    it('round-trips the shuffled strategy with its seed', function (int $seed): void {
        $data = ['legs' => 2, 'strategy' => 'shuffled', 'backtracking' => false, 'strategy_seed' => $seed];
        $options = RoundRobinOptions::fromArray($data);

        expect($options->strategy)->toBeInstanceOf(ShuffledLegStrategy::class)
            ->and($options->strategy instanceof ShuffledLegStrategy ? $options->strategy->seed : null)->toBe($seed)
            ->and($options->toArray())->toBe(['format_version' => 1, ...$data])
            ->and(new RoundRobinOptions(legs: 2, strategy: new ShuffledLegStrategy(seed: $seed))->toArray())
            ->toBe(['format_version' => 1, ...$data]);
    })->with([[0], [7], [-3], [PHP_INT_MAX]]);

    it('writes the seed before the role assignment a caller named', function (): void {
        $data = ['legs' => 3, 'strategy' => 'shuffled', 'backtracking' => true, 'strategy_seed' => 7, 'role_assignment' => 'balanced'];

        expect(RoundRobinOptions::fromArray($data)->toArray())->toBe(['format_version' => 1, ...$data]);
    });

    // The defect of issue #52: 'shuffled' in plain data used to build a
    // strategy that no seed controlled
    it('refuses the shuffled strategy without a seed', function (array $data): void {
        $refusal = roundRobinOptionsRefusal(fn(): RoundRobinOptions => RoundRobinOptions::fromArray($data));

        expect($refusal?->getReason())->toBe(InvalidConfigurationReason::IncompatibleOptions)
            ->and($refusal?->getMessage())->toContain('strategy_seed');
    })->with([
        'the identifier alone' => [['strategy' => 'shuffled']],
        'with legs' => [['legs' => 2, 'strategy' => 'shuffled', 'backtracking' => false]],
    ]);

    it('refuses a seed that is not an integer, whatever the strategy', function (string $strategy, mixed $seed): void {
        $refusal = roundRobinOptionsRefusal(
            fn(): RoundRobinOptions => RoundRobinOptions::fromArray(['strategy' => $strategy, 'strategy_seed' => $seed])
        );

        expect($refusal?->getReason())->toBe(InvalidConfigurationReason::WrongValueType)
            ->and($refusal?->getMessage())->toContain('strategy_seed must be an integer');
    })
        ->with([['shuffled'], ['mirrored'], ['repeated']])
        ->with([['7'], [7.0], [true], [[7]]]);

    // Null is the key left out, as it is for the role assignment: a stored
    // row with an empty seed column reads as a row without one
    it('reads a null seed as no seed', function (): void {
        expect(RoundRobinOptions::fromArray(['legs' => 2, 'strategy' => 'repeated', 'strategy_seed' => null])->toArray())
            ->toBe(['format_version' => 1, 'legs' => 2, 'strategy' => 'repeated', 'backtracking' => false]);

        $refusal = roundRobinOptionsRefusal(
            fn(): RoundRobinOptions => RoundRobinOptions::fromArray(['strategy' => 'shuffled', 'strategy_seed' => null])
        );

        expect($refusal?->getReason())->toBe(InvalidConfigurationReason::IncompatibleOptions);
    });

    // A seed beside a strategy that draws nothing would decide nothing, and
    // toArray() could not give it back
    it('refuses a seed for a strategy that does not draw', function (array $data): void {
        $refusal = roundRobinOptionsRefusal(fn(): RoundRobinOptions => RoundRobinOptions::fromArray($data));

        expect($refusal?->getReason())->toBe(InvalidConfigurationReason::IncompatibleOptions)
            ->and($refusal?->getMessage())->toContain('no other strategy draws');
    })->with([
        'mirrored' => [['strategy' => 'mirrored', 'strategy_seed' => 7]],
        'repeated' => [['strategy' => 'repeated', 'strategy_seed' => 7]],
        'the default strategy' => [['legs' => 2, 'strategy_seed' => 0]],
    ]);

    // The state of a randomizer is not plain data: 'shuffled' alone would be
    // data that fromArray() refuses, and a made-up seed would be a lie
    it('refuses to serialize a shuffled strategy that holds a randomizer', function (): void {
        $options = new RoundRobinOptions(legs: 2, strategy: new ShuffledLegStrategy(new Randomizer(new Mt19937(7))));
        $refusal = roundRobinOptionsRefusal(fn(): array => $options->toArray());

        expect($refusal?->getReason())->toBe(InvalidConfigurationReason::NotSerializable)
            ->and($refusal?->getMessage())->toContain('holds a randomizer');
    });

    it('maps stable strategy identifiers to the built-in strategies', function (): void {
        expect(RoundRobinOptions::fromArray(['strategy' => 'mirrored'])->strategy)
            ->toBeInstanceOf(MirroredLegStrategy::class);
        expect(RoundRobinOptions::fromArray(['strategy' => 'repeated'])->strategy)
            ->toBeInstanceOf(RepeatedLegStrategy::class);
        expect(RoundRobinOptions::fromArray(['strategy' => 'shuffled', 'strategy_seed' => 1])->strategy)
            ->toBeInstanceOf(ShuffledLegStrategy::class);
    });

    it('builds from empty configuration with the documented defaults', function (): void {
        expect(RoundRobinOptions::fromArray([])->toArray())
            ->toBe(['format_version' => 1, 'legs' => 1, 'strategy' => 'mirrored', 'backtracking' => false]);
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
        $custom = new readonly class extends MirroredLegStrategy {};

        new RoundRobinOptions(legs: 2, strategy: $custom)->toArray();
    })->throws(InvalidConfigurationException::class);

    // Balanced where possible since 0.3.0. Options that name no role
    // assignment follow the default of the release that reads them, whether
    // they are built in code or from plain data, so a payload stored
    // without the key by 0.2.x gets that default too (the changelog says
    // so). It is a class of its own and not the strict balanced one, which
    // is balanced or refused.
    it('defaults to the role assignment that is balanced where possible', function (): void {
        expect(new RoundRobinOptions()->roleAssignment)->toBeInstanceOf(BalancedWherePossibleRoleAssignment::class);
        expect(RoundRobinOptions::fromArray([])->roleAssignment)->toBeInstanceOf(BalancedWherePossibleRoleAssignment::class);

        // A payload written before the option existed
        expect(RoundRobinOptions::fromArray(['legs' => 2, 'strategy' => 'repeated', 'backtracking' => true])->roleAssignment)
            ->toBeInstanceOf(BalancedWherePossibleRoleAssignment::class);
        // And one written by toArray() for options that named none
        expect(RoundRobinOptions::fromArray(new RoundRobinOptions(legs: 2)->toArray())->roleAssignment)
            ->toBeInstanceOf(BalancedWherePossibleRoleAssignment::class);
    });

    it('maps stable identifiers to the built-in role assignments', function (): void {
        expect(RoundRobinOptions::fromArray(['role_assignment' => 'round_parity'])->roleAssignment)
            ->toBeInstanceOf(RoundParityRoleAssignment::class);
        expect(RoundRobinOptions::fromArray(['role_assignment' => 'balanced'])->roleAssignment)
            ->toBeInstanceOf(BalancedRoleAssignment::class);
        expect(RoundRobinOptions::fromArray(['role_assignment' => 'balanced_where_possible'])->roleAssignment)
            ->toBeInstanceOf(BalancedWherePossibleRoleAssignment::class);
    });

    // The wire shape of options that name no role assignment is the three
    // options it has always been, after the version of the form
    it('serializes no role assignment when none was named', function (array $payload): void {
        $threeKeys = ['format_version' => 1, 'legs' => 1, 'strategy' => 'mirrored', 'backtracking' => false];

        expect(new RoundRobinOptions()->toArray())->toBe($threeKeys);
        expect(RoundRobinOptions::fromArray($payload)->toArray())->toBe($threeKeys);
        expect(RoundRobinOptions::fromArray(RoundRobinOptions::fromArray($payload)->toArray())->toArray())->toBe($threeKeys);
    })->with([
        'an empty payload' => [[]],
        'a payload written before the option existed' => [['legs' => 1, 'strategy' => 'mirrored', 'backtracking' => false]],
        'a null role assignment' => [['role_assignment' => null]],
    ]);

    // The default changed in 0.3.0. A caller that named the default of
    // 0.2.x to keep it must still have named it after a round trip through
    // plain data, or the stored configuration would follow the new default;
    // and one that names the new default keeps saying so.
    it('keeps a role assignment that was named, the default included', function (): void {
        $pinned = ['format_version' => 1, 'legs' => 1, 'strategy' => 'mirrored', 'backtracking' => false, 'role_assignment' => 'round_parity'];

        expect(new RoundRobinOptions(roleAssignment: new RoundParityRoleAssignment())->toArray())->toBe($pinned);
        expect(RoundRobinOptions::fromArray(['role_assignment' => 'round_parity'])->toArray())->toBe($pinned);
        expect(RoundRobinOptions::fromArray($pinned)->toArray())->toBe($pinned);
        expect(RoundRobinOptions::fromArray($pinned)->roleAssignment)->toBeInstanceOf(RoundParityRoleAssignment::class);

        $balanced = new RoundRobinOptions(legs: 2, roleAssignment: new BalancedRoleAssignment());

        expect($balanced->toArray())
            ->toBe(['format_version' => 1, 'legs' => 2, 'strategy' => 'mirrored', 'backtracking' => false, 'role_assignment' => 'balanced']);
        expect(RoundRobinOptions::fromArray($balanced->toArray())->toArray())->toBe($balanced->toArray());

        // The default of 0.3.0 has a name of its own, so that it can be
        // named and then stays named: options that name it are written
        // with the key, and options that name nothing are not
        $named = new RoundRobinOptions(legs: 2, roleAssignment: new BalancedWherePossibleRoleAssignment());

        expect($named->toArray())
            ->toBe(['format_version' => 1, 'legs' => 2, 'strategy' => 'mirrored', 'backtracking' => false, 'role_assignment' => 'balanced_where_possible']);
        expect(RoundRobinOptions::fromArray($named->toArray())->toArray())->toBe($named->toArray());
        expect(RoundRobinOptions::fromArray(['legs' => 2, 'role_assignment' => 'balanced_where_possible'])->toArray())->toBe($named->toArray());
        expect(new RoundRobinOptions(legs: 2)->toArray())->not->toHaveKey('role_assignment');
    });

    it('rejects a role assignment identifier it does not know', function (mixed $identifier): void {
        expect(fn() => RoundRobinOptions::fromArray(['role_assignment' => $identifier]))
            ->toThrow(InvalidConfigurationException::class, 'Unknown role assignment identifier');
    })->with([['fair'], ['Balanced'], [true], [1]]);

    it('refuses to serialize a custom role assignment', function (): void {
        $custom = new class implements RoleAssignmentInterface {
            #[Override]
            public function assignRoles(array $rounds, RoleAssignmentContext $context): array
            {
                return $rounds;
            }

            #[Override]
            public function rule(): ?ConstraintInterface
            {
                return null;
            }
        };

        expect(fn() => new RoundRobinOptions(roleAssignment: $custom)->toArray())
            ->toThrow(InvalidConfigurationException::class, 'Custom role assignments');
    });
});

describe('SwissOptions', function (): void {
    it('defaults to 3 rounds', function (): void {
        expect(new SwissOptions()->rounds)->toBe(3);
    });

    it('rejects a non-positive round count', function (): void {
        new SwissOptions(rounds: 0);
    })->throws(InvalidConfigurationException::class);

    it('round-trips through plain configuration data', function (): void {
        $options = SwissOptions::fromArray(['rounds' => 5]);

        expect($options->rounds)->toBe(5);
        expect($options->toArray())->toBe(['format_version' => 1, 'rounds' => 5]);
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
        expect(fn() => new RoundRobinScheduler()->schedule($this->participants, new SwissOptions()))
            ->toThrow(InvalidConfigurationException::class, 'RoundRobinOptions');
    });

    it('rejects another algorithm options type on the Swiss scheduler', function (): void {
        expect(fn() => new SwissScheduler()->schedule($this->participants, new RoundRobinOptions()))
            ->toThrow(InvalidConfigurationException::class, 'SwissOptions');
    });

    // The plan reflects the configured strategy, not a default
    it('builds the round-robin plan from the configured strategy', function (): void {
        $plan = new RoundRobinScheduler()->getPlan(
            $this->participants,
            new RoundRobinOptions(legs: 2, strategy: new ShuffledLegStrategy(seed: 1))
        );

        expect($plan->getLegs())->toBe(2);
        expect($plan->rolesMirrorAcrossLegs())->toBeFalse();
        expect($plan->requiresRandomization())->toBeTrue();
    });

    it('builds the Swiss plan from the configured rounds', function (): void {
        $plan = new SwissScheduler()->getPlan($this->participants, new SwissOptions(rounds: 2));

        expect($plan->getTotalRounds())->toBe(2);
        expect($plan->getLegs())->toBeNull();
    });
});
