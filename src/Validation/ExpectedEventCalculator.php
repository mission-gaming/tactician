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
     * @param array<Participant> $participants
     * @param array<string, mixed> $algorithmSpecificParams
     */
    public function calculateExpectedEvents(
        array $participants,
        int $legs = 1,
        array $algorithmSpecificParams = []
    ): int;

    /**
     * Get the algorithm name this calculator supports.
     */
    public function getAlgorithmName(): string;
}
