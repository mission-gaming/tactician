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
 * - thirdPlace: a third-place tie in the last round of a single
 *   elimination bracket, between the two entrants who went out in the
 *   round before it, so that the bracket decides places 3 and 4 (off by
 *   default; see SingleEliminationEngine). Single elimination only: a
 *   double elimination bracket decides those places in its losers bracket.
 *
 * @experimental
 */
final readonly class EliminationOptions
{
    /**
     * The options are not checked against an engine here: the double
     * elimination engine refuses reseedEachRound and thirdPlace when it is
     * constructed, and the single elimination engine does not read
     * grandFinalReset.
     *
     * @throws InvalidConfigurationException When legsPerTie is not 1 or 2
     */
    public function __construct(
        public int $legsPerTie = 1,
        public bool $reseedEachRound = false,
        public bool $grandFinalReset = true,
        public bool $thirdPlace = false
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
     * ['legs_per_tie' => 2, 'reseed_each_round' => false, 'grand_final_reset' => true, 'third_place' => true].
     *
     * Every key is optional and takes the constructor's default when it is
     * left out: data stored before an option existed is read with that
     * option at its default, so an array without `third_place` is a bracket
     * without a third-place tie. A key this class does not have is ignored.
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

        $thirdPlace = $config['third_place'] ?? false;
        if (!is_bool($thirdPlace)) {
            throw new InvalidConfigurationException(
                'third_place must be a boolean',
                ['third_place' => $thirdPlace],
                reason: InvalidConfigurationReason::WrongValueType
            );
        }

        return new self($legsPerTie, $reseedEachRound, $grandFinalReset, $thirdPlace);
    }

    /**
     * The plain-data form fromArray() accepts: the first three keys,
     * always, and `third_place` when the option is on.
     *
     * The key is left out when the option is off, so the form of options
     * without a third-place tie is the form it was before the option
     * existed, key for key.
     *
     * @return array{legs_per_tie: int, reseed_each_round: bool, grand_final_reset: bool, third_place?: true}
     */
    public function toArray(): array
    {
        $data = [
            'legs_per_tie' => $this->legsPerTie,
            'reseed_each_round' => $this->reseedEachRound,
            'grand_final_reset' => $this->grandFinalReset,
        ];

        if ($this->thirdPlace) {
            $data['third_place'] = true;
        }

        return $data;
    }
}
