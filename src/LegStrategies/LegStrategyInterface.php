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
 * A strategy also says what its role decision is worth when a constraint
 * rejects the event (LegPlanContribution::$rolesArePreferences): a rule,
 * which the scheduler never changes, or a preference, which it tries
 * first and may reverse.
 *
 * @api
 */
interface LegStrategyInterface
{
    /**
     * Contribute strategy facts to round-robin plan construction.
     *
     * The scheduler calls it at the start of every `schedule()` call,
     * before any event is generated, and builds the plan from that answer.
     * Returning unsatisfiable reasons fails plan construction with those
     * reasons as diagnostics: an `InvalidConfigurationException` with the
     * reason `UnsatisfiableLegStrategy`.
     *
     * A strategy that says it needs randomization
     * (`LegPlanContribution::$requiresRandomization`) is asked again within
     * the call, with the same arguments, each time the default role
     * assignment starts the generation over with other roles: once before
     * the roles the generator proposes are tried, and where those are tried
     * before the search for balanced roles, once more before that search.
     * It is the point at which a strategy that draws from a seed starts its
     * draws again, so that each run draws what a call draws. The plan of
     * the call stays the one built from the first answer.
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
     * How often it is asked depends on what the strategy says of its
     * roles. A strategy of preferences is asked once for each event of
     * each layout the scheduler tries, in the order of the events, and not
     * again while the roles of that layout are searched: the event it
     * returned is the role tried first, and the scheduler builds the
     * reverse itself, with the round and the metadata of the event
     * returned. A strategy of rules is asked once for each event without
     * backtracking; with backtracking it is asked again whenever the
     * search goes back past the event and comes forward to it, with the
     * pairing in the roles its first-leg event then has. Every rotated
     * participant order, and every first leg a backtracking search finds,
     * is a layout of its own and asks again. A strategy that keeps state
     * between calls therefore gets what its own bookkeeping makes of that.
     *
     * Returning null creates no event for the pairing. A complete round
     * robin needs every pairing in every leg, so the leg is then short and
     * generation fails with an `IncompleteScheduleException`, without a
     * search for a first leg the strategy would return one for.
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
