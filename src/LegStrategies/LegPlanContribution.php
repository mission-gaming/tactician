<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\LegStrategies;

/**
 * Facts a leg strategy contributes to round-robin plan construction.
 *
 * Strategies contribute by returning this immutable value — never by
 * computing schedule shape themselves. All round-robin arithmetic (rounds
 * per leg, event counts) lives in RoundRobinPlan; keeping strategies out
 * of that math is what makes plan/generator drift impossible.
 *
 * A non-empty $unsatisfiableReasons fails plan construction loudly with
 * those reasons as diagnostics; $warnings are carried onto the plan
 * without failing it.
 */
final readonly class LegPlanContribution
{
    /**
     * @param bool $rolesMirrorAcrossLegs Whether the strategy reverses event roles in later legs
     * @param bool $requiresRandomization Whether the strategy needs a randomizer during generation
     * @param array<string> $unsatisfiableReasons Non-empty means plan construction fails with diagnostics
     * @param array<string> $warnings Non-fatal notes carried onto the plan
     */
    public function __construct(
        public bool $rolesMirrorAcrossLegs,
        public bool $requiresRandomization,
        public array $unsatisfiableReasons = [],
        public array $warnings = []
    ) {
    }
}
