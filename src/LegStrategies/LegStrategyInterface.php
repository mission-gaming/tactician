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
 */
interface LegStrategyInterface
{
    /**
     * Contribute strategy facts to round-robin plan construction.
     *
     * Returning unsatisfiable reasons fails plan construction with those
     * reasons as diagnostics, before any event is generated.
     *
     * @param array<Participant> $participants All tournament participants
     * @param int $legs Total number of legs in the tournament
     * @param ConstraintSet $constraints Tournament constraints
     */
    public function planLegs(
        array $participants,
        int $legs,
        ConstraintSet $constraints
    ): LegPlanContribution;

    /**
     * Generate a specific event for a given leg and round.
     *
     * Called during generation to create events with full tournament
     * context visibility. Returning null means no event should be created
     * for this pairing.
     *
     * @param array<Participant> $participants Participants for this event
     * @param int $leg Current leg being generated (1-based)
     * @param int $round Current round being generated (1-based, continuous across legs)
     * @param SchedulingContext $context Complete tournament context
     */
    public function generateEventForLeg(
        array $participants,
        int $leg,
        int $round,
        SchedulingContext $context
    ): ?Event;
}
