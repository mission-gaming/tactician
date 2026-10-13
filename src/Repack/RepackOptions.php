<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Repack;

use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Serialization\PlainData;

/**
 * Options for schedule repacking.
 *
 * The consolidation and early-fill weights make the trade between the two
 * soft objectives explicit rather than emergent: consolidation prefers
 * concentrating a participant's events into fewer sessions; early-fill
 * prefers landing events in the earliest sessions so the season ends as
 * soon as the event list allows. The two pull against each other — a
 * higher consolidation weight accepts a later finish to give participants
 * fuller sessions, and consolidation deliberately dominates by default.
 *
 * The step budget bounds the searches that choose sessions and pack
 * them (session-load improvement, per-session packing, repair swaps) in
 * elementary search steps — deterministic and reproducible, unlike
 * wall-clock limits. The search of one session may spend a quarter of
 * what is left when it starts, the table it needs first included, which
 * costs steps only when a session has eight slots or more. The steps after
 * the packing (the last placement step, which runs only when an event is
 * still unplaced, and the search for fewer gap slots, which runs only
 * when a gap is left) get a budget of their own of the same size, so a
 * repack spends at most twice the budget. Where the schedule that comes
 * of this places fewer events than could be placed, or has a gap, the
 * events are planned and packed once more in another order, on what the
 * two budgets have left and no more. When a budget runs out, the
 * repacker reports what is left rather than pretending, and the outcome
 * says so ({@see RepackOutcome::isBudgetExhausted()}).
 *
 * The budget governs the work that is not counted in steps as well.
 * Laying the events out before any session is chosen, the last placement
 * step, the search for fewer gap slots and the search for the events two
 * over-capacity participants share each stop at a limit on their own
 * work. At the default budget and above those limits are fixed, so a
 * larger budget never changes what they do. Below the default each is
 * the same share of its limit as the budget is of the default (a tenth
 * of the default budget, a tenth of each limit), so a smaller budget
 * shortens every search of a repack in proportion; a search cut short
 * at such a smaller limit counts as one the budget stopped. What no
 * budget shortens is the work that is not a search and grows with the
 * request (reading it, the passes over the events, one look at every
 * position for every event in the layout and the last placement step,
 * the audit), ordering the participants, which is bounded by a limit of
 * its own of about a tenth of a second and is left whole so that the
 * ids of a request decide no more at a small budget than at a large
 * one, and the planner's scoring of a session of sixteen slots or more,
 * which is not counted in steps (it is bounded instead: a session of
 * more than twenty slots is not planned that way). The usage guide
 * ("The Step Budget") has the measured times.
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
     * @param int $stepBudget Elementary search steps shared by the planning and
     *                        the packing, and as many again for the steps
     *                        after the packing; below the default it also
     *                        shortens, in proportion, the searches that
     *                        count their own work
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
     * Every key is optional and defaults to the value shown, which is the
     * constructor's default; a key given as null is read as not given. A
     * key the method does not know is refused, so that a misspelt option is
     * not silently read as its default.
     *
     * @param array<string, mixed> $config
     *
     * @throws InvalidConfigurationException When the format version is not one this release reads
     *                                       (reason `UnsupportedFormatVersion`), a key is unknown
     *                                       (`UnknownOptionKey`),
     *                                       a value is of the wrong type, or is one the
     *                                       constructor rejects
     */
    public static function fromArray(array $config): self
    {
        PlainData::checkOptions(
            $config,
            ['consolidation_weight', 'early_fill_weight', 'step_budget', 'throw_on_violations'],
            'repack options'
        );

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
     * Serialize back to the plain configuration data fromArray() accepts:
     * `format_version`, the version of this form (1), then all four
     * options, always present.
     *
     * @return array{format_version: int, consolidation_weight: int, early_fill_weight: int, step_budget: int, throw_on_violations: bool}
     */
    public function toArray(): array
    {
        return [
            PlainData::VERSION_KEY => PlainData::VERSION,
            'consolidation_weight' => $this->consolidationWeight,
            'early_fill_weight' => $this->earlyFillWeight,
            'step_budget' => $this->stepBudget,
            'throw_on_violations' => $this->throwOnViolations,
        ];
    }
}
