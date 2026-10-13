<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\LegStrategies\LegStrategyInterface;
use MissionGaming\Tactician\LegStrategies\MirroredLegStrategy;
use MissionGaming\Tactician\LegStrategies\RepeatedLegStrategy;
use MissionGaming\Tactician\LegStrategies\ShuffledLegStrategy;
use MissionGaming\Tactician\RoleAssignment\BalancedRoleAssignment;
use MissionGaming\Tactician\RoleAssignment\RoleAssignmentInterface;
use MissionGaming\Tactician\RoleAssignment\RoundParityRoleAssignment;
use Override;

/**
 * Options for round-robin scheduling: how many legs, how pairings vary
 * across them, and which participant of a pairing is first-named.
 *
 * Defaults to a single mirrored leg with round-parity roles. The
 * identifiers accepted by fromArray() are stable: 'mirrored', 'repeated',
 * and 'shuffled' for the leg strategy, 'round_parity' and 'balanced' for
 * the role assignment. The shuffled strategy draws, so in plain data it
 * comes with the seed of its draws, `strategy_seed`.
 *
 * @api
 */
final readonly class RoundRobinOptions implements SchedulerOptions
{
    private const array STRATEGY_IDENTIFIERS = [
        'mirrored' => MirroredLegStrategy::class,
        'repeated' => RepeatedLegStrategy::class,
        'shuffled' => ShuffledLegStrategy::class,
    ];

    private const array ROLE_ASSIGNMENT_IDENTIFIERS = [
        'round_parity' => RoundParityRoleAssignment::class,
        'balanced' => BalancedRoleAssignment::class,
    ];

    public LegStrategyInterface $strategy;

    public RoleAssignmentInterface $roleAssignment;

    /**
     * Whether the caller named a role assignment. toArray() writes the
     * `role_assignment` key for one that was named, the default included, so
     * that a choice survives a round trip through plain data when a later
     * release changes the default.
     */
    private bool $roleAssignmentNamed;

    /**
     * @param int $legs How many times each participant meets each other participant
     * @param LegStrategyInterface|null $strategy How pairings vary across legs (default: mirrored roles)
     * @param bool $backtracking Search for a schedule when the greedy rotations cannot satisfy
     *                           the constraints (bounded by a count of pairing attempts, and
     *                           deterministic for a given field order; greedy always runs first).
     *                           The search is over how the pairings of the first leg fall into
     *                           rounds and over the roles of every leg; without it the roles of
     *                           the first leg are the role assignment's and are not searched
     * @param RoleAssignmentInterface|null $roleAssignment Which participant of each pairing is first-named
     *                                                     (default: round parity, the roles the generator proposes)
     * @throws InvalidConfigurationException When legs is not a positive integer
     */
    public function __construct(
        public int $legs = 1,
        ?LegStrategyInterface $strategy = null,
        public bool $backtracking = false,
        ?RoleAssignmentInterface $roleAssignment = null
    ) {
        if ($legs < 1) {
            throw new InvalidConfigurationException(
                'Legs must be a positive integer',
                ['legs' => $legs, 'minimum_required' => 1],
                reason: InvalidConfigurationReason::InvalidLegCount,
                requirements: InvalidConfigurationException::ROUND_ROBIN_REQUIREMENTS
            );
        }

        $this->strategy = $strategy ?? new MirroredLegStrategy();
        $this->roleAssignment = $roleAssignment ?? new RoundParityRoleAssignment();
        $this->roleAssignmentNamed = $roleAssignment !== null;
    }

    /**
     * Build from plain configuration data:
     * ['legs' => 2, 'strategy' => 'mirrored', 'backtracking' => false,
     * 'role_assignment' => 'balanced'], or with the one strategy that
     * draws, ['legs' => 2, 'strategy' => 'shuffled', 'strategy_seed' => 7].
     * A key this class does not have is ignored.
     *
     * Every key is optional but one: `strategy_seed` is required with
     * 'shuffled' and refused with any other strategy. It is the integer
     * that ShuffledLegStrategy takes as its seed, so options built from the
     * same data give the same schedule on every call; nothing built here
     * draws from an unseeded source. A seed that a strategy would not read
     * is refused and not ignored, so that stored data never names a seed
     * that decides nothing. A `strategy_seed` of null is the key left out,
     * as it is for `role_assignment`.
     *
     * @param array<string, mixed> $config
     * @throws InvalidConfigurationException When legs is not an integer or is below 1, backtracking
     *                                       is not a boolean, an identifier is unknown, strategy_seed
     *                                       is not an integer, the shuffled strategy has no
     *                                       strategy_seed, or another strategy has one
     */
    #[Override]
    public static function fromArray(array $config): static
    {
        $legs = $config['legs'] ?? 1;
        if (!is_int($legs)) {
            throw new InvalidConfigurationException(
                'Legs must be an integer',
                ['legs' => $legs],
                reason: InvalidConfigurationReason::InvalidLegCount,
                requirements: InvalidConfigurationException::ROUND_ROBIN_REQUIREMENTS
            );
        }

        $strategyId = $config['strategy'] ?? 'mirrored';
        if (!is_string($strategyId) || !isset(self::STRATEGY_IDENTIFIERS[$strategyId])) {
            throw new InvalidConfigurationException(
                'Unknown leg strategy identifier',
                ['strategy' => $strategyId, 'known' => array_keys(self::STRATEGY_IDENTIFIERS)],
                reason: InvalidConfigurationReason::UnknownIdentifier,
                requirements: InvalidConfigurationException::ROUND_ROBIN_REQUIREMENTS
            );
        }

        $backtracking = $config['backtracking'] ?? false;
        if (!is_bool($backtracking)) {
            throw new InvalidConfigurationException(
                'backtracking must be a boolean',
                ['backtracking' => $backtracking],
                reason: InvalidConfigurationReason::WrongValueType,
                requirements: InvalidConfigurationException::ROUND_ROBIN_REQUIREMENTS
            );
        }

        $roleAssignmentId = $config['role_assignment'] ?? null;
        if ($roleAssignmentId !== null && (!is_string($roleAssignmentId) || !isset(self::ROLE_ASSIGNMENT_IDENTIFIERS[$roleAssignmentId]))) {
            throw new InvalidConfigurationException(
                'Unknown role assignment identifier',
                ['role_assignment' => $roleAssignmentId, 'known' => array_keys(self::ROLE_ASSIGNMENT_IDENTIFIERS)],
                reason: InvalidConfigurationReason::UnknownIdentifier,
                requirements: InvalidConfigurationException::ROUND_ROBIN_REQUIREMENTS
            );
        }

        $strategyClass = self::STRATEGY_IDENTIFIERS[$strategyId];
        $strategySeed = $config['strategy_seed'] ?? null;
        if ($strategySeed !== null && !is_int($strategySeed)) {
            throw new InvalidConfigurationException(
                'strategy_seed must be an integer',
                ['strategy_seed' => $strategySeed],
                reason: InvalidConfigurationReason::WrongValueType,
                requirements: InvalidConfigurationException::ROUND_ROBIN_REQUIREMENTS
            );
        }

        if ($strategyClass === ShuffledLegStrategy::class) {
            if (!is_int($strategySeed)) {
                throw new InvalidConfigurationException(
                    "The 'shuffled' leg strategy needs a strategy_seed: an integer that decides its draws",
                    ['strategy' => $strategyId],
                    reason: InvalidConfigurationReason::IncompatibleOptions,
                    requirements: InvalidConfigurationException::ROUND_ROBIN_REQUIREMENTS
                );
            }
            $strategy = new ShuffledLegStrategy(seed: $strategySeed);
        } elseif ($strategySeed !== null) {
            throw new InvalidConfigurationException(
                "strategy_seed is the seed of the 'shuffled' leg strategy and no other strategy draws",
                ['strategy' => $strategyId, 'strategy_seed' => $strategySeed],
                reason: InvalidConfigurationReason::IncompatibleOptions,
                requirements: InvalidConfigurationException::ROUND_ROBIN_REQUIREMENTS
            );
        } else {
            $strategy = new $strategyClass();
        }

        $roleAssignmentClass = $roleAssignmentId === null ? null : self::ROLE_ASSIGNMENT_IDENTIFIERS[$roleAssignmentId];

        return new self(
            $legs,
            $strategy,
            $backtracking,
            $roleAssignmentClass === null ? null : new $roleAssignmentClass()
        );
    }

    /**
     * Serialize back to plain configuration data.
     *
     * Only the built-in strategies and role assignments have stable
     * identifiers; options carrying a custom instance of either cannot be
     * expressed as plain data and fail loudly rather than serializing
     * something fromArray() could not rebuild.
     *
     * The `role_assignment` key is present only when a role assignment was
     * named, in the constructor or in the data given to fromArray(). Options
     * that do not name one serialize to the three keys they always have, and
     * options that name the default keep saying so, which is what holds a
     * stored configuration to its roles when a release changes the default.
     *
     * A ShuffledLegStrategy built from a seed serializes to 'shuffled' and
     * its seed under `strategy_seed`, the one key that is present for that
     * strategy only. One that holds a randomizer cannot be expressed as
     * plain data, because the state of a randomizer is not a value
     * fromArray() could rebuild it from, and fails loudly like a custom
     * strategy does.
     *
     * @return array{legs: int, strategy: string, backtracking: bool, strategy_seed?: int, role_assignment?: string}
     * @throws InvalidConfigurationException When the strategy or the role assignment is not one of the
     *                                       built-ins, or the strategy is a ShuffledLegStrategy that
     *                                       holds a randomizer
     */
    #[Override]
    public function toArray(): array
    {
        $identifier = array_search($this->strategy::class, self::STRATEGY_IDENTIFIERS, true);
        if ($identifier === false) {
            throw new InvalidConfigurationException(
                'Custom leg strategies have no stable configuration identifier and cannot be serialized',
                ['strategy' => $this->strategy::class],
                reason: InvalidConfigurationReason::NotSerializable,
                requirements: InvalidConfigurationException::ROUND_ROBIN_REQUIREMENTS
            );
        }

        $config = ['legs' => $this->legs, 'strategy' => $identifier, 'backtracking' => $this->backtracking];

        if ($this->strategy instanceof ShuffledLegStrategy) {
            if ($this->strategy->seed === null) {
                throw new InvalidConfigurationException(
                    'A shuffled leg strategy that holds a randomizer cannot be serialized: build it from a seed',
                    ['strategy' => $this->strategy::class],
                    reason: InvalidConfigurationReason::NotSerializable,
                    requirements: InvalidConfigurationException::ROUND_ROBIN_REQUIREMENTS
                );
            }
            $config['strategy_seed'] = $this->strategy->seed;
        }

        if (!$this->roleAssignmentNamed) {
            return $config;
        }

        $roleAssignmentIdentifier = array_search($this->roleAssignment::class, self::ROLE_ASSIGNMENT_IDENTIFIERS, true);
        if ($roleAssignmentIdentifier === false) {
            throw new InvalidConfigurationException(
                'Custom role assignments have no stable configuration identifier and cannot be serialized',
                ['role_assignment' => $this->roleAssignment::class],
                reason: InvalidConfigurationReason::NotSerializable,
                requirements: InvalidConfigurationException::ROUND_ROBIN_REQUIREMENTS
            );
        }

        $config['role_assignment'] = $roleAssignmentIdentifier;

        return $config;
    }
}
