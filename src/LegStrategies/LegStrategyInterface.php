<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\LegStrategies;

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\SchedulingContext;

/**
 * Strategy for how round-robin pairings vary across legs.
 *
 * A leg strategy owns two things: the facts the round-robin plan needs
 * from it (via planLegs()), and the per-event role decision during
 * generation (via generateEventForLeg()). It never owns schedule shape —
 * rounds and event counts are RoundRobinPlan's job.
 *
 * @api
 */
interface LegStrategyInterface
{
    /**
     * Contribute strategy facts to round-robin plan construction.
     *
     * The scheduler calls it once per `schedule()` call, before any event
     * is generated. Returning unsatisfiable reasons fails plan construction
     * with those reasons as diagnostics: an `InvalidConfigurationException`
     * with the reason `UnsatisfiableLegStrategy`.
     *
     * @param array<Participant> $participants All tournament participants, as given to the scheduler
     * @param int $legs Total number of legs in the tournament (1 or more)
     * @param ConstraintSet $constraints Tournament constraints; an empty set when the scheduler has none
     */
    public function planLegs(
        array $participants,
        int $legs,
        ConstraintSet $constraints
    ): LegPlanContribution;

    /**
     * Build the event of one pairing in one leg, deciding its roles.
     *
     * The scheduler builds the events of the first leg itself and calls
     * this for every pairing of every later leg, so it is asked with a leg
     * of 2 or more. The answer is expected to be an event of the two given
     * participants in the given round; only their order is the strategy's
     * to choose. The scheduler then puts the event to the constraints.
     *
     * Returning null creates no event for the pairing. A complete round
     * robin needs every pairing in every leg, so the leg is then short and
     * generation fails with an `IncompleteScheduleException`.
     *
     * @param array<Participant> $participants The two participants of the pairing, the first-named
     *                                         at index 0, in the roles the leg has before the
     *                                         strategy acts: those the role assignment decided for
     *                                         this leg, or those of the pairing's first-leg event
     *                                         when the backtracking search found the first leg
     * @param int $leg Current leg being generated (1-based)
     * @param int $round Current round being generated (1-based, continuous across legs)
     * @param SchedulingContext $context The events of the earlier rounds, of every leg so far, with this
     *                                   leg as its current leg. After a backtracking search it also
     *                                   holds the events of this round that were made before this one
     */
    public function generateEventForLeg(
        array $participants,
        int $leg,
        int $round,
        SchedulingContext $context
    ): ?Event;
}
