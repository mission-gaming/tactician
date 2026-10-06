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
 * In a schedule from a scheduler without a randomizer every leg is then
 * the first leg again: the same pairings in the same rounds of the leg,
 * with the same roles, so a participant that is first-named against an
 * opponent is first-named in all their meetings. A scheduler with a
 * randomizer shuffles the participant order for the first leg only, so its
 * later legs are not the first leg repeated pairing by pairing.
 *
 * @api
 */
readonly class RepeatedLegStrategy implements LegStrategyInterface
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
