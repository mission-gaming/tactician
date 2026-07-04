<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\LegStrategies\LegStrategyInterface;
use MissionGaming\Tactician\LegStrategies\MirroredLegStrategy;
use MissionGaming\Tactician\LegStrategies\RepeatedLegStrategy;
use MissionGaming\Tactician\LegStrategies\ShuffledLegStrategy;
use Override;

/**
 * Options for round-robin scheduling: how many legs, and how pairings
 * vary across them.
 *
 * Defaults to a single mirrored leg. The strategy identifiers accepted by
 * fromArray() are stable: 'mirrored', 'repeated', and 'shuffled'.
 */
final readonly class RoundRobinOptions implements SchedulerOptions
{
    private const STRATEGY_IDENTIFIERS = [
        'mirrored' => MirroredLegStrategy::class,
        'repeated' => RepeatedLegStrategy::class,
        'shuffled' => ShuffledLegStrategy::class,
    ];

    public LegStrategyInterface $strategy;

    /**
     * @param int $legs How many times each participant meets each other participant
     * @param LegStrategyInterface|null $strategy How pairings vary across legs (default: mirrored roles)
     * @param bool $backtracking Search for a schedule when the greedy rotations cannot satisfy
     *                           the constraints (bounded, deterministic; greedy always runs first)
     * @throws InvalidConfigurationException When legs is not a positive integer
     */
    public function __construct(
        public int $legs = 1,
        ?LegStrategyInterface $strategy = null,
        public bool $backtracking = false
    ) {
        if ($legs < 1) {
            throw new InvalidConfigurationException(
                'Legs must be a positive integer',
                ['legs' => $legs, 'minimum_required' => 1]
            );
        }

        $this->strategy = $strategy ?? new MirroredLegStrategy();
    }

    /**
     * Build from plain configuration data:
     * ['legs' => 2, 'strategy' => 'mirrored', 'backtracking' => false].
     *
     * @param array<string, mixed> $config
     * @throws InvalidConfigurationException When a value is invalid or a strategy identifier is unknown
     */
    #[Override]
    public static function fromArray(array $config): static
    {
        $legs = $config['legs'] ?? 1;
        if (!is_int($legs)) {
            throw new InvalidConfigurationException(
                'Legs must be an integer',
                ['legs' => $legs]
            );
        }

        $strategyId = $config['strategy'] ?? 'mirrored';
        if (!is_string($strategyId) || !isset(self::STRATEGY_IDENTIFIERS[$strategyId])) {
            throw new InvalidConfigurationException(
                'Unknown leg strategy identifier',
                ['strategy' => $strategyId, 'known' => array_keys(self::STRATEGY_IDENTIFIERS)]
            );
        }

        $backtracking = $config['backtracking'] ?? false;
        if (!is_bool($backtracking)) {
            throw new InvalidConfigurationException(
                'backtracking must be a boolean',
                ['backtracking' => $backtracking]
            );
        }

        $strategyClass = self::STRATEGY_IDENTIFIERS[$strategyId];

        return new self($legs, new $strategyClass(), $backtracking);
    }

    /**
     * Serialize back to plain configuration data.
     *
     * Only the built-in strategies have stable identifiers; options
     * carrying a custom strategy instance cannot be expressed as plain
     * data and fail loudly rather than serializing something fromArray()
     * could not rebuild.
     *
     * @return array{legs: int, strategy: string, backtracking: bool}
     * @throws InvalidConfigurationException When the strategy is not one of the built-ins
     */
    #[Override]
    public function toArray(): array
    {
        $identifier = array_search($this->strategy::class, self::STRATEGY_IDENTIFIERS, true);
        if ($identifier === false) {
            throw new InvalidConfigurationException(
                'Custom leg strategies have no stable configuration identifier and cannot be serialized',
                ['strategy' => $this->strategy::class]
            );
        }

        return ['legs' => $this->legs, 'strategy' => $identifier, 'backtracking' => $this->backtracking];
    }
}
