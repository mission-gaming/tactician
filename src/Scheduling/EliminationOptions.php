<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;

/**
 * Options for the elimination bracket presets.
 *
 * - legsPerTie: knockout ties played over one event or two (mirrored
 *   roles). With two legs the aggregate is decided app-side and recorded
 *   as a tie decision (see TieDecision).
 * - reseedEachRound: fixed bracket path (survivors keep their bracket
 *   slots; the default) versus re-seeded knockout (survivors re-ranked by
 *   standings and re-folded each round). Single elimination only in this
 *   cut.
 * - grandFinalReset: double elimination's reset match when the losers
 *   champion wins the grand final.
 */
final readonly class EliminationOptions
{
    /**
     * @throws InvalidConfigurationException When legsPerTie is not 1 or 2
     */
    public function __construct(
        public int $legsPerTie = 1,
        public bool $reseedEachRound = false,
        public bool $grandFinalReset = true
    ) {
        if ($legsPerTie !== 1 && $legsPerTie !== 2) {
            throw new InvalidConfigurationException(
                'Ties are played over 1 or 2 legs',
                ['legs_per_tie' => $legsPerTie]
            );
        }
    }

    /**
     * Build from plain configuration data:
     * ['legs_per_tie' => 2, 'reseed_each_round' => false, 'grand_final_reset' => true].
     *
     * @param array<string, mixed> $config
     * @throws InvalidConfigurationException When a value has the wrong type
     */
    public static function fromArray(array $config): self
    {
        $legsPerTie = $config['legs_per_tie'] ?? 1;
        if (!is_int($legsPerTie)) {
            throw new InvalidConfigurationException(
                'legs_per_tie must be an integer',
                ['legs_per_tie' => $legsPerTie]
            );
        }

        $reseedEachRound = $config['reseed_each_round'] ?? false;
        $grandFinalReset = $config['grand_final_reset'] ?? true;
        if (!is_bool($reseedEachRound) || !is_bool($grandFinalReset)) {
            throw new InvalidConfigurationException(
                'reseed_each_round and grand_final_reset must be booleans',
                ['reseed_each_round' => $reseedEachRound, 'grand_final_reset' => $grandFinalReset]
            );
        }

        return new self($legsPerTie, $reseedEachRound, $grandFinalReset);
    }

    /**
     * @return array{legs_per_tie: int, reseed_each_round: bool, grand_final_reset: bool}
     */
    public function toArray(): array
    {
        return [
            'legs_per_tie' => $this->legsPerTie,
            'reseed_each_round' => $this->reseedEachRound,
            'grand_final_reset' => $this->grandFinalReset,
        ];
    }
}
