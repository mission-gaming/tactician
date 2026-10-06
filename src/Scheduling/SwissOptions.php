<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use Override;

/**
 * Options for whole-schedule Swiss generation: how many rounds to play.
 *
 * Swiss has rounds, not legs — this typed object is what retires the old
 * interface's overloaded "legs means rounds here" scalar.
 *
 * @experimental
 */
final readonly class SwissOptions implements SchedulerOptions
{
    /**
     * @param int $rounds Number of Swiss rounds to generate, at least 1. Whether the field is
     *                    large enough for that many rounds without a repeat pairing is checked
     *                    by the scheduler, which knows the participants
     * @throws InvalidConfigurationException When rounds is below 1
     */
    public function __construct(
        public int $rounds = 3
    ) {
        if ($rounds < 1) {
            throw new InvalidConfigurationException(
                'Rounds must be a positive integer',
                ['rounds' => $rounds, 'minimum_required' => 1],
                reason: InvalidConfigurationReason::InvalidRoundCount
            );
        }
    }

    /**
     * Build from plain configuration data: ['rounds' => 5].
     *
     * An omitted 'rounds' is 3. A key this class does not have is ignored.
     *
     * @param array<string, mixed> $config
     * @throws InvalidConfigurationException When rounds is not an integer or is below 1
     */
    #[Override]
    public static function fromArray(array $config): static
    {
        $rounds = $config['rounds'] ?? 3;
        if (!is_int($rounds)) {
            throw new InvalidConfigurationException(
                'Rounds must be an integer',
                ['rounds' => $rounds],
                reason: InvalidConfigurationReason::InvalidRoundCount
            );
        }

        return new self($rounds);
    }

    /**
     * The plain-data form fromArray() accepts.
     *
     * @return array{rounds: int}
     */
    #[Override]
    public function toArray(): array
    {
        return ['rounds' => $this->rounds];
    }
}
