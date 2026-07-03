<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Stage\StagePlan;

interface SchedulerInterface
{
    /**
     * Generate a schedule for the given participants.
     *
     * @param array<Participant> $participants Tournament participants
     * @param int $participantsPerEvent Number of participants per event (future-proofing for N-participant events)
     * @param int $legs Number of legs - how many times each participant meets each other
     *                  participant. Algorithms without a legs concept (e.g. Swiss)
     *                  interpret this as their round count; that overload is slated to be
     *                  resolved by typed per-algorithm options (Phase 3 milestone 2).
     * @param mixed $options Algorithm-specific scheduling options
     *
     * @throws \MissionGaming\Tactician\Exceptions\InvalidConfigurationException When configuration is invalid
     * @throws \MissionGaming\Tactician\Exceptions\IncompleteScheduleException When constraints prevent complete schedule generation
     * @throws \MissionGaming\Tactician\Exceptions\ImpossibleConstraintsException When constraints are mathematically impossible
     */
    public function schedule(
        array $participants,
        int $participantsPerEvent = 2,
        int $legs = 1,
        mixed $options = null
    ): Schedule;

    /**
     * Validate constraints before scheduling begins.
     * This can throw ImpossibleConstraintsException for mathematically impossible constraints.
     *
     * @param array<Participant> $participants
     * @param int $legs Number of legs (see schedule() for the Swiss interpretation)
     * @throws \MissionGaming\Tactician\Exceptions\ImpossibleConstraintsException
     * @throws \MissionGaming\Tactician\Exceptions\InvalidConfigurationException
     */
    public function validateConstraints(array $participants, int $legs): void;

    /**
     * Build the stage plan for the given configuration: the algorithm's
     * declaration of rounds, legs, and expected event counts. Fails with
     * the same diagnostics as schedule() when the configuration is
     * unsatisfiable, before any event exists.
     *
     * @param array<Participant> $participants
     * @param int $legs Number of legs (see schedule() for the Swiss interpretation)
     * @throws \MissionGaming\Tactician\Exceptions\InvalidConfigurationException
     */
    public function getPlan(
        array $participants,
        int $legs,
        int $participantsPerEvent = 2
    ): StagePlan;
}
