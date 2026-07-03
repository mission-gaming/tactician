<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use Override;

/**
 * Options for whole-schedule Swiss generation: how many rounds to play.
 *
 * Swiss has rounds, not legs — this typed object is what retires the old
 * interface's overloaded "legs means rounds here" scalar.
 */
final readonly class SwissOptions implements SchedulerOptions
{
    /**
     * @param int $rounds Number of Swiss rounds to generate
     * @throws InvalidConfigurationException When rounds is not a positive integer
     */
    public function __construct(
        public int $rounds = 3
    ) {
        if ($rounds < 1) {
            throw new InvalidConfigurationException(
                'Rounds must be a positive integer',
                ['rounds' => $rounds, 'minimum_required' => 1]
            );
        }
    }

    /**
     * Build from plain configuration data: ['rounds' => 5].
     *
     * @param array<string, mixed> $config
     * @throws InvalidConfigurationException When rounds is not an integer
     */
    #[Override]
    public static function fromArray(array $config): static
    {
        $rounds = $config['rounds'] ?? 3;
        if (!is_int($rounds)) {
            throw new InvalidConfigurationException(
                'Rounds must be an integer',
                ['rounds' => $rounds]
            );
        }

        return new self($rounds);
    }

    /**
     * @return array{rounds: int}
     */
    #[Override]
    public function toArray(): array
    {
        return ['rounds' => $this->rounds];
    }
}
