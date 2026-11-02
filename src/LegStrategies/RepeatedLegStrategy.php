<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\LegStrategies;

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Scheduling\SchedulingContext;
use Override;

/**
 * Strategy that keeps the roles of each pairing as the leg has them, in
 * every leg.
 *
 * Every leg is then the first leg again: the same pairings in the same
 * rounds of the leg, with the same roles, so a participant that is
 * first-named against an opponent is first-named in all their meetings.
 * That holds for a scheduler with a randomizer too, whose first leg is laid
 * out from the shuffled order (up to 0.2.x its later legs were laid out
 * from the order as given, and were not the first leg repeated).
 *
 * The roles are a rule: an event of a later leg in the other role would
 * not be a repeat, so the scheduler never reverses one to satisfy a
 * constraint. What a constraint on roles can change is the first leg,
 * which every later leg repeats. Its roles are the role assignment's. The
 * scheduler searches them within the rule the role assignment states (the
 * two balanced role assignments state one, the default among them: the
 * first leg stays balanced), and freely with
 * `RoundRobinOptions(backtracking: true)`; under a role assignment that
 * states none, and without backtracking, roles that break a constraint
 * when they are repeated fail the schedule. The default, which is balanced
 * where possible, goes on from a search that finds nothing to the roles
 * the generator proposes, and fails only where those fail too.
 *
 * @api
 */
final readonly class RepeatedLegStrategy implements LegStrategyInterface
{
    /**
     * States that roles do not mirror across legs and that no randomizer
     * is needed. No configuration is unsatisfiable for this strategy, and
     * it reads none of its arguments.
     */
    #[Override]
    public function planLegs(
        array $participants,
        int $legs,
        ConstraintSet $constraints
    ): LegPlanContribution {
        return new LegPlanContribution(
            rolesMirrorAcrossLegs: false,
            requiresRandomization: false
        );
    }

    /**
     * The pairing in the given round and the given order, whatever the
     * leg. Deterministic, and the context is not read.
     *
     * @return Event|null Null when not given exactly two participants
     */
    #[Override]
    public function generateEventForLeg(
        array $participants,
        int $leg,
        int $round,
        SchedulingContext $context
    ): ?Event {
        if (count($participants) !== 2) {
            return null; // Repeated strategy only works with 2 participants
        }

        // For repeated strategy, always use the same order regardless of leg
        return new Event($participants, new Round($round));
    }
}
