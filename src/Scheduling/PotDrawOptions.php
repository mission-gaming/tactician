<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use Override;

/**
 * Options for a pot draw: how many pots the entrants are cut into, how many
 * opponents every entrant meets from each pot, and the seed of the draw.
 *
 * The number of rounds is not an option. It is derived: pots × opponents
 * per pot. Whether the numbers fit the entrants (equal pots, an even field,
 * enough members in a pot) is decided when the entrants are known, by
 * {@see \MissionGaming\Tactician\Stage\PotDrawPlan}.
 *
 * The seed is part of the options, and not a `Random\Randomizer` handed to
 * the scheduler, so that a draw can be stored and repeated from plain data:
 * the same entrants, pots, opponents per pot and seed give the same schedule
 * on every call.
 *
 * @experimental
 */
final readonly class PotDrawOptions implements SchedulerOptions
{
    /**
     * The keys fromArray() reads and toArray() writes.
     */
    private const array KEYS = ['pots', 'opponents_per_pot', 'seed'];

    /**
     * @param int $pots How many pots the entrants are cut into, in list order (at least 1)
     * @param int $opponentsPerPot How many opponents every entrant meets from each pot,
     *                             its own included (at least 1)
     * @param int $seed Selects the draw: any integer, and each one names one schedule
     * @throws InvalidConfigurationException When pots or opponentsPerPot is below 1
     */
    public function __construct(
        public int $pots = 1,
        public int $opponentsPerPot = 1,
        public int $seed = 0
    ) {
        if ($pots < 1) {
            throw new InvalidConfigurationException(
                'Pots must be a positive integer',
                ['pots' => $pots, 'minimum_required' => 1],
                reason: InvalidConfigurationReason::ValueOutOfRange
            );
        }

        if ($opponentsPerPot < 1) {
            throw new InvalidConfigurationException(
                'Opponents per pot must be a positive integer',
                ['opponents_per_pot' => $opponentsPerPot, 'minimum_required' => 1],
                reason: InvalidConfigurationReason::ValueOutOfRange
            );
        }
    }

    /**
     * Build from plain configuration data:
     * ['pots' => 4, 'opponents_per_pot' => 2, 'seed' => 2026].
     *
     * An omitted key takes the constructor's default. A key this class does
     * not have is refused, so that a misspelt option is not silently read as
     * its default.
     *
     * @param array<string, mixed> $config
     * @throws InvalidConfigurationException When a key is unknown, a value is not an integer,
     *                                       or pots or opponents_per_pot is below 1
     */
    #[Override]
    public static function fromArray(array $config): static
    {
        $unknown = array_values(array_diff(array_keys($config), self::KEYS));
        if ($unknown !== []) {
            throw new InvalidConfigurationException(
                'Unknown pot draw option',
                ['unknown' => $unknown, 'known' => self::KEYS],
                reason: InvalidConfigurationReason::UnknownOptionKey
            );
        }

        $values = [];
        foreach (self::KEYS as $key) {
            $value = $config[$key] ?? null;
            if (array_key_exists($key, $config) && !is_int($value)) {
                throw new InvalidConfigurationException(
                    sprintf('%s must be an integer', $key),
                    [$key => $value],
                    reason: InvalidConfigurationReason::WrongValueType
                );
            }
            $values[$key] = $value;
        }

        return new self(
            is_int($values['pots']) ? $values['pots'] : 1,
            is_int($values['opponents_per_pot']) ? $values['opponents_per_pot'] : 1,
            is_int($values['seed']) ? $values['seed'] : 0
        );
    }

    /**
     * @return array{pots: int, opponents_per_pot: int, seed: int}
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'pots' => $this->pots,
            'opponents_per_pot' => $this->opponentsPerPot,
            'seed' => $this->seed,
        ];
    }
}
