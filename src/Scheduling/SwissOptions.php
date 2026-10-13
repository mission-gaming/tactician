<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Serialization\PlainData;
use Override;

/**
 * Options for whole-schedule Swiss generation: how many rounds to play,
 * and the seed of the draw.
 *
 * Swiss has rounds, not legs — this typed object is what retires the old
 * interface's overloaded "legs means rounds here" scalar.
 *
 * The seed makes a random draw repeatable from plain data: the same
 * participants, rounds and seed give the same schedule on every call, on
 * one scheduler or on many. Without a seed the scheduler draws from the
 * `Random\Randomizer` it was built with, if it has one, and pairs the
 * participants in list order when it has none.
 *
 * @experimental
 */
final readonly class SwissOptions implements SchedulerOptions
{
    /**
     * @param int $rounds Number of Swiss rounds to generate, at least 1. Whether the field is
     *                    large enough for that many rounds without a repeat pairing is checked
     *                    by the scheduler, which knows the participants
     * @param int|null $seed Selects the draw: any integer, and each one names one schedule
     *                       for a list of participants. Null for no seed
     * @throws InvalidConfigurationException When rounds is below 1
     */
    public function __construct(
        public int $rounds = 3,
        public ?int $seed = null
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
     * Build from plain configuration data: ['rounds' => 5, 'seed' => 2026].
     *
     * An omitted 'rounds' is 3, and an omitted or null 'seed' is no seed.
     * A key this class does not have is refused, so that a misspelt option
     * is not silently read as its default.
     *
     * @param array<string, mixed> $config
     * @throws InvalidConfigurationException When the format version is not one this release reads
     *                                       (reason `UnsupportedFormatVersion`), a key is unknown
     *                                       (`UnknownOptionKey`), rounds is not an integer or is
     *                                       below 1 (`InvalidRoundCount`), or the seed is neither
     *                                       an integer nor null (`WrongValueType`)
     */
    #[Override]
    public static function fromArray(array $config): static
    {
        PlainData::checkOptions($config, ['rounds', 'seed'], 'Swiss options');

        $rounds = $config['rounds'] ?? 3;
        if (!is_int($rounds)) {
            throw new InvalidConfigurationException(
                'Rounds must be an integer',
                ['rounds' => $rounds],
                reason: InvalidConfigurationReason::InvalidRoundCount
            );
        }

        $seed = $config['seed'] ?? null;
        if ($seed !== null && !is_int($seed)) {
            throw new InvalidConfigurationException(
                'The seed must be an integer',
                ['seed' => $seed],
                reason: InvalidConfigurationReason::WrongValueType
            );
        }

        return new self($rounds, $seed);
    }

    /**
     * The plain-data form fromArray() accepts: `format_version`, the
     * version of this form (1), then the rounds, and the seed when there
     * is one.
     *
     * @return array{format_version: int, rounds: int, seed?: int}
     */
    #[Override]
    public function toArray(): array
    {
        $data = [PlainData::VERSION_KEY => PlainData::VERSION, 'rounds' => $this->rounds];
        if ($this->seed !== null) {
            $data['seed'] = $this->seed;
        }

        return $data;
    }
}
