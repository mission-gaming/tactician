<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\LegStrategies;

/**
 * Facts a leg strategy contributes to round-robin plan construction.
 *
 * Strategies contribute by returning this immutable value — never by
 * computing schedule shape themselves. All round-robin arithmetic (rounds
 * per leg, event counts) lives in RoundRobinPlan, so a strategy cannot
 * state a shape that differs from the plan's. The first two booleans are
 * carried onto the plan as given, for whoever reads the plan; the library
 * acts on neither and does not check them against what the strategy's
 * generateEventForLeg() does.
 *
 * The third, $rolesArePreferences, is the one the scheduler acts on. It
 * says what the role of a later-leg event is to the strategy:
 *
 * - false, the default: a rule. The event is put to the constraints as
 *   the strategy returned it, and a rejection is final for that event.
 *   Mirrored and repeated legs are rules: an event with another role
 *   would not be mirrored, or repeated;
 * - true: a preference. When the constraints reject the event, the
 *   scheduler puts the same event with its two participants reversed to
 *   them, and searches the roles of the other later-leg events when both
 *   are rejected (RoundRobinScheduler states the bounds). A strategy that
 *   draws its roles says true: the reverse is a role it could have drawn.
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
     * @param bool $rolesArePreferences Whether the scheduler may reverse a later-leg event of the
     *                                  strategy that the constraints reject
     */
    public function __construct(
        public bool $rolesMirrorAcrossLegs,
        public bool $requiresRandomization,
        public array $unsatisfiableReasons = [],
        public array $warnings = [],
        public bool $rolesArePreferences = false
    ) {}
}
