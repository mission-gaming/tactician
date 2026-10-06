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
 * the role assignment.
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
     * @param int $legs How many times each participant meets each other participant
     * @param LegStrategyInterface|null $strategy How pairings vary across legs (default: mirrored roles)
     * @param bool $backtracking Search for a schedule when the greedy rotations cannot satisfy
     *                           the constraints (bounded, deterministic; greedy always runs first)
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
    }

    /**
     * Build from plain configuration data:
     * ['legs' => 2, 'strategy' => 'mirrored', 'backtracking' => false,
     * 'role_assignment' => 'balanced']. Every key is optional.
     *
     * @param array<string, mixed> $config
     * @throws InvalidConfigurationException When a value is invalid or an identifier is unknown
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

        $roleAssignmentId = $config['role_assignment'] ?? 'round_parity';
        if (!is_string($roleAssignmentId) || !isset(self::ROLE_ASSIGNMENT_IDENTIFIERS[$roleAssignmentId])) {
            throw new InvalidConfigurationException(
                'Unknown role assignment identifier',
                ['role_assignment' => $roleAssignmentId, 'known' => array_keys(self::ROLE_ASSIGNMENT_IDENTIFIERS)],
                reason: InvalidConfigurationReason::UnknownIdentifier,
                requirements: InvalidConfigurationException::ROUND_ROBIN_REQUIREMENTS
            );
        }

        $strategyClass = self::STRATEGY_IDENTIFIERS[$strategyId];
        $roleAssignmentClass = self::ROLE_ASSIGNMENT_IDENTIFIERS[$roleAssignmentId];

        return new self($legs, new $strategyClass(), $backtracking, new $roleAssignmentClass());
    }

    /**
     * Serialize back to plain configuration data.
     *
     * Only the built-in strategies and role assignments have stable
     * identifiers; options carrying a custom instance of either cannot be
     * expressed as plain data and fail loudly rather than serializing
     * something fromArray() could not rebuild.
     *
     * The `role_assignment` key is present only when the role assignment is
     * not the default, so options that do not set it serialize to the three
     * keys they always have.
     *
     * @return array{legs: int, strategy: string, backtracking: bool, role_assignment?: string}
     * @throws InvalidConfigurationException When the strategy or the role assignment is not one of the built-ins
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

        if ($this->roleAssignment instanceof RoundParityRoleAssignment) {
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
