<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Validation\ExpectedEventCalculator;

interface SchedulerInterface
{
    /**
     * Generate a schedule for the given participants.
     *
     * @param array<Participant> $participants Tournament participants
     * @param int $participantsPerEvent Number of participants per event (future-proofing for N-participant events)
     * @param int $rounds Algorithm-specific round count or repetition count
     * @param mixed $options Algorithm-specific scheduling options
     *
     * @throws \MissionGaming\Tactician\Exceptions\InvalidConfigurationException When configuration is invalid
     * @throws \MissionGaming\Tactician\Exceptions\IncompleteScheduleException When constraints prevent complete schedule generation
     * @throws \MissionGaming\Tactician\Exceptions\ImpossibleConstraintsException When constraints are mathematically impossible
     */
    public function schedule(
        array $participants,
        int $participantsPerEvent = 2,
        int $rounds = 1,
        mixed $options = null
    ): Schedule;

    /**
     * Validate constraints before scheduling begins.
     * This can throw ImpossibleConstraintsException for mathematically impossible constraints.
     *
     * @param array<Participant> $participants
     * @throws \MissionGaming\Tactician\Exceptions\ImpossibleConstraintsException
     * @throws \MissionGaming\Tactician\Exceptions\InvalidConfigurationException
     */
    public function validateConstraints(array $participants, int $rounds): void;

    /**
     * Get the expected number of events for a complete schedule.
     *
     * @param array<Participant> $participants
     */
    public function getExpectedEventCount(
        array $participants,
        int $rounds,
        int $participantsPerEvent = 2
    ): int;

    /**
     * Get the expected event calculator for this scheduling algorithm.
     */
    public function getExpectedEventCalculator(): ExpectedEventCalculator;
}
