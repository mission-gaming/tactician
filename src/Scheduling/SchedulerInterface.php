<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Stage\StagePlan;

/**
 * Whole-schedule generator: participants and typed options in, a complete
 * validated schedule out.
 *
 * Each scheduler accepts exactly one SchedulerOptions type and rejects any
 * other loudly — there are no overloaded scalars whose meaning depends on
 * the algorithm. Null options mean the algorithm's documented defaults.
 */
interface SchedulerInterface
{
    /**
     * Generate a schedule for the given participants.
     *
     * @param array<Participant> $participants Tournament participants
     * @param SchedulerOptions|null $options This scheduler's options type, or null for its defaults
     *
     * @throws \MissionGaming\Tactician\Exceptions\InvalidConfigurationException When the configuration (or options type) is invalid
     * @throws \MissionGaming\Tactician\Exceptions\IncompleteScheduleException When constraints prevent complete schedule generation
     * @throws \MissionGaming\Tactician\Exceptions\ImpossibleConstraintsException When constraints are mathematically impossible
     */
    public function schedule(
        array $participants,
        ?SchedulerOptions $options = null
    ): Schedule;

    /**
     * Build the stage plan for the given configuration: the algorithm's
     * declaration of rounds, legs, and expected event counts. Fails with
     * the same diagnostics as schedule() when the configuration is
     * unsatisfiable, before any event exists.
     *
     * @param array<Participant> $participants
     * @param SchedulerOptions|null $options This scheduler's options type, or null for its defaults
     * @throws \MissionGaming\Tactician\Exceptions\InvalidConfigurationException
     */
    public function getPlan(
        array $participants,
        ?SchedulerOptions $options = null
    ): StagePlan;
}
