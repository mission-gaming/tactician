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
 *
 * @experimental
 */
interface SchedulerInterface
{
    /**
     * Generate the complete schedule for the given participants.
     *
     * All or nothing: the schedule returned has every event the plan
     * expects, and a schedule that cannot be completed is an exception,
     * never a shorter schedule. Round numbers are 1-based.
     *
     * @param array<Participant> $participants Tournament participants, with unique IDs
     * @param SchedulerOptions|null $options This scheduler's options type, or null for its defaults
     *
     * @throws \MissionGaming\Tactician\Exceptions\InvalidConfigurationException When the configuration (or options type) is invalid
     * @throws \MissionGaming\Tactician\Exceptions\IncompleteScheduleException When constraints prevent complete schedule generation
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
