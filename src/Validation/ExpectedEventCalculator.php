<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Validation;

use MissionGaming\Tactician\DTO\Participant;

/**
 * Interface for calculating expected events for different scheduling algorithms.
 */
interface ExpectedEventCalculator
{
    /**
     * Calculate the expected number of events for complete scheduling.
     *
     * The integer argument is algorithm-specific: round robin treats it as legs,
     * while round-based algorithms such as Simple Swiss treat it as rounds.
     *
     * @param array<Participant> $participants
     * @param array<string, mixed> $algorithmSpecificParams
     */
    public function calculateExpectedEvents(
        array $participants,
        int $scheduleUnits = 1,
        array $algorithmSpecificParams = []
    ): int;

    /**
     * Get the algorithm name this calculator supports.
     */
    public function getAlgorithmName(): string;
}
