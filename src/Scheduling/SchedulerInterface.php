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
     * @param array<Participant> $participants
     */
    public function schedule(array $participants): Schedule;

    /**
     * Get the expected event calculator for this scheduling algorithm.
     */
    public function getExpectedEventCalculator(): ExpectedEventCalculator;

    /**
     * Validate constraints before scheduling begins.
     * This can throw ImpossibleConstraintsException for mathematically impossible constraints.
     *
     * @param array<Participant> $participants
     * @throws \MissionGaming\Tactician\Exceptions\ImpossibleConstraintsException
     * @throws \MissionGaming\Tactician\Exceptions\InvalidConfigurationException
     */
    public function validateConstraints(array $participants, int $legs): void;

    /**
     * Get the expected number of events for a complete schedule.
     *
     * @param array<Participant> $participants
     */
    public function getExpectedEventCount(array $participants, int $legs): int;
}
