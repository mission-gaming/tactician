<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\LegStrategies;

/**
 * Facts a leg strategy contributes to round-robin plan construction.
 *
 * Strategies contribute by returning this immutable value — never by
 * computing schedule shape themselves. All round-robin arithmetic (rounds
 * per leg, event counts) lives in RoundRobinPlan, so a strategy cannot
 * state a shape that differs from the plan's. The two booleans are carried
 * onto the plan as given, for whoever reads the plan; the library acts on
 * neither and does not check them against what the strategy's
 * generateEventForLeg() does.
 *
 * A non-empty $unsatisfiableReasons fails plan construction loudly with
 * those reasons as diagnostics; $warnings are carried onto the plan
 * without failing it.
 *
 * @api
 */
final readonly class LegPlanContribution
{
    /**
     * Nothing is validated: the value holds what it is given.
     *
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
    ) {}
}
