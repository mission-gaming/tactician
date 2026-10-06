<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Repack;

use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;

/**
 * Options for schedule repacking.
 *
 * The consolidation and early-fill weights make the trade between the two
 * soft objectives explicit rather than emergent: consolidation prefers
 * concentrating a participant's events into fewer sessions; early-fill
 * prefers landing events in the earliest sessions so the season ends as
 * soon as the event list allows. The two pull against each other — a
 * higher consolidation weight accepts a later finish to give participants
 * fuller nights, and consolidation deliberately dominates by default.
 *
 * The step budget bounds every search the repacker runs (session-load
 * improvement, per-session packing, repair swaps) in elementary search
 * steps — deterministic and reproducible, unlike wall-clock limits. When
 * the budget runs out, the repacker reports what is left rather than
 * pretending, and the outcome says so
 * ({@see RepackOutcome::isBudgetExhausted()}).
 *
 * The weights are bounded so that the objective stays an integer. The
 * repacker scores a move as at most earlyFillWeight x (sessions - 1) +
 * 2 x consolidationWeight; beyond PHP_INT_MAX PHP computes that as a
 * float, where a large weight swallows a small one and the result is no
 * longer the trade the weights state. The part that does not depend on a
 * grid is checked here: a consolidation weight above
 * {@see self::MAX_CONSOLIDATION_WEIGHT} is rejected. The part that depends
 * on the number of sessions is checked by RepackRequest, which has the
 * grid.
 *
 * @api
 */
final readonly class RepackOptions
{
    /**
     * The largest consolidation weight: half of PHP_INT_MAX, rounded down.
     * The objective counts the weight twice (once for each participant of
     * an event), and twice anything larger is not an integer. The value
     * assumes two participants to an event, which is all the repacker
     * takes today; it will be revisited when events with more participants
     * arrive (ADR 0003).
     */
    public const int MAX_CONSOLIDATION_WEIGHT = PHP_INT_MAX >> 1;

    /**
     * @param int $consolidationWeight Preference for concentrating a participant's
     *                                 events into fewer sessions (0 disables)
     * @param int $earlyFillWeight Preference for filling earliest sessions first
     *                             (0 disables)
     * @param int $stepBudget Elementary search steps shared by every phase
     * @param bool $throwOnViolations Throw RepackViolationsException instead of
     *                                returning an outcome carrying violations
     *
     * @throws InvalidConfigurationException When a weight is negative, the consolidation
     *                                       weight is above MAX_CONSOLIDATION_WEIGHT, or
     *                                       the budget is not positive
     */
    public function __construct(
        public int $consolidationWeight = 3,
        public int $earlyFillWeight = 1,
        public int $stepBudget = 200_000,
        public bool $throwOnViolations = false
    ) {
        if ($consolidationWeight < 0 || $earlyFillWeight < 0) {
            throw new InvalidConfigurationException(
                'Objective weights must be zero or positive',
                ['consolidation_weight' => $consolidationWeight, 'early_fill_weight' => $earlyFillWeight],
                reason: InvalidConfigurationReason::ValueOutOfRange
            );
        }

        if ($consolidationWeight > self::MAX_CONSOLIDATION_WEIGHT) {
            throw new InvalidConfigurationException(
                'The consolidation weight is too large to keep the objective an integer',
                ['consolidation_weight' => $consolidationWeight, 'largest' => self::MAX_CONSOLIDATION_WEIGHT],
                reason: InvalidConfigurationReason::ValueOutOfRange
            );
        }

        if ($stepBudget < 1) {
            throw new InvalidConfigurationException(
                'The step budget must be a positive integer',
                ['step_budget' => $stepBudget],
                reason: InvalidConfigurationReason::ValueOutOfRange
            );
        }
    }

    /**
     * Build from plain configuration data:
     * ['consolidation_weight' => 3, 'early_fill_weight' => 1,
     *  'step_budget' => 200000, 'throw_on_violations' => false].
     *
     * @param array<string, mixed> $config
     *
     * @throws InvalidConfigurationException When a value is malformed
     */
    public static function fromArray(array $config): self
    {
        $consolidationWeight = $config['consolidation_weight'] ?? 3;
        $earlyFillWeight = $config['early_fill_weight'] ?? 1;
        $stepBudget = $config['step_budget'] ?? 200_000;
        $throwOnViolations = $config['throw_on_violations'] ?? false;

        if (!is_int($consolidationWeight) || !is_int($earlyFillWeight) || !is_int($stepBudget)) {
            throw new InvalidConfigurationException(
                'Weights and the step budget must be integers',
                [
                    'consolidation_weight' => $consolidationWeight,
                    'early_fill_weight' => $earlyFillWeight,
                    'step_budget' => $stepBudget,
                ],
                reason: InvalidConfigurationReason::WrongValueType
            );
        }

        if (!is_bool($throwOnViolations)) {
            throw new InvalidConfigurationException(
                'throw_on_violations must be a boolean',
                ['throw_on_violations' => $throwOnViolations],
                reason: InvalidConfigurationReason::WrongValueType
            );
        }

        return new self($consolidationWeight, $earlyFillWeight, $stepBudget, $throwOnViolations);
    }

    /**
     * Serialize back to plain configuration data.
     *
     * @return array{consolidation_weight: int, early_fill_weight: int, step_budget: int, throw_on_violations: bool}
     */
    public function toArray(): array
    {
        return [
            'consolidation_weight' => $this->consolidationWeight,
            'early_fill_weight' => $this->earlyFillWeight,
            'step_budget' => $this->stepBudget,
            'throw_on_violations' => $this->throwOnViolations,
        ];
    }
}
