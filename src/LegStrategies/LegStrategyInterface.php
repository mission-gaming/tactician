<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\LegStrategies;

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\SchedulingContext;

/**
 * Interface for leg strategies that support integrated multi-leg generation.
 *
 * This new interface replaces the post-processing approach with integrated
 * generation, allowing strategies to participate in real-time constraint
 * validation and all-or-nothing schedule generation.
 */
interface LegStrategyInterface
{
    /**
     * Plan the generation strategy for a multi-leg tournament.
     *
     * This method allows the strategy to analyze the tournament parameters
     * and create a plan for how events will be generated across all legs.
     *
     * @param array<Participant> $participants All tournament participants
     * @param int $totalLegs Total number of legs in the tournament
     * @param int $participantsPerEvent Number of participants per event
     * @param ConstraintSet $constraints Tournament constraints
     *
     * @return GenerationPlan The plan for generating events across all legs
     */
    public function planGeneration(
        array $participants,
        int $totalLegs,
        int $participantsPerEvent,
        ConstraintSet $constraints
    ): GenerationPlan;

    /**
     * Generate a specific event for a given leg and round.
     *
     * This method is called during the integrated generation process to
     * create events with full tournament context visibility.
     *
     * @param array<Participant> $participants Participants for this event
     * @param int $leg Current leg being generated (1-based)
     * @param int $round Current round being generated (1-based, continuous across legs)
     * @param SchedulingContext $context Complete tournament context
     *
     * @return Event|null The generated event, or null if no event should be created
     */
    public function generateEventForLeg(
        array $participants,
        int $leg,
        int $round,
        SchedulingContext $context
    ): ?Event;

    /**
     * Check if the strategy can satisfy the given constraints.
     *
     * This method performs early validation to determine if the strategy
     * can generate a complete tournament schedule given the constraints.
     *
     * @param array<Participant> $participants Tournament participants
     * @param int $legs Number of legs
     * @param int $participantsPerEvent Participants per event
     * @param ConstraintSet $constraints Tournament constraints
     *
     * @return ConstraintSatisfiabilityReport Analysis of constraint satisfiability
     */
    public function canSatisfyConstraints(
        array $participants,
        int $legs,
        int $participantsPerEvent,
        ConstraintSet $constraints
    ): ConstraintSatisfiabilityReport;
}
