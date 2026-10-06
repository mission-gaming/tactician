<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;

/**
 * Options for the elimination bracket presets.
 *
 * - legsPerTie: knockout ties played over one event or two (mirrored
 *   roles). A tie that finishes level - a drawn single event, or two legs
 *   that do not decide - is decided app-side and recorded as a tie
 *   decision (see TieDecision).
 * - reseedEachRound: fixed bracket path (survivors keep their bracket
 *   slots; the default) versus re-seeded knockout (survivors re-ranked by
 *   standings and re-folded each round). Single elimination only in this
 *   cut.
 * - grandFinalReset: double elimination's reset match when the losers
 *   champion wins the grand final.
 *
 * @experimental
 */
final readonly class EliminationOptions
{
    /**
     * The options are not checked against an engine here: the double
     * elimination engine refuses reseedEachRound when it is constructed,
     * and the single elimination engine does not read grandFinalReset.
     *
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
                ['legs_per_tie' => $legsPerTie],
                reason: InvalidConfigurationReason::InvalidLegCount
            );
        }
    }

    /**
     * Build from plain configuration data:
     * ['legs_per_tie' => 2, 'reseed_each_round' => false, 'grand_final_reset' => true].
     *
     * Every key is optional and takes the constructor's default when it is
     * left out. A key this class does not have is ignored.
     *
     * @param array<string, mixed> $config
     * @throws InvalidConfigurationException When a value has the wrong type, or legs_per_tie is
     *                                       not 1 or 2
     */
    public static function fromArray(array $config): self
    {
        $legsPerTie = $config['legs_per_tie'] ?? 1;
        if (!is_int($legsPerTie)) {
            throw new InvalidConfigurationException(
                'legs_per_tie must be an integer',
                ['legs_per_tie' => $legsPerTie],
                reason: InvalidConfigurationReason::InvalidLegCount
            );
        }

        $reseedEachRound = $config['reseed_each_round'] ?? false;
        $grandFinalReset = $config['grand_final_reset'] ?? true;
        if (!is_bool($reseedEachRound) || !is_bool($grandFinalReset)) {
            throw new InvalidConfigurationException(
                'reseed_each_round and grand_final_reset must be booleans',
                ['reseed_each_round' => $reseedEachRound, 'grand_final_reset' => $grandFinalReset],
                reason: InvalidConfigurationReason::WrongValueType
            );
        }

        return new self($legsPerTie, $reseedEachRound, $grandFinalReset);
    }

    /**
     * The plain-data form fromArray() accepts: all three keys, always.
     *
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
