<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Validation;

use MissionGaming\Tactician\DTO\Participant;

/**
 * Calculates expected event count for Round Robin scheduling algorithm.
 *
 * Round Robin formula: For n participants, each leg requires n*(n-1)/2 events.
 * This is because each participant must play every other participant exactly once.
 */
readonly class RoundRobinEventCalculator implements ExpectedEventCalculator
{
    #[\Override]
    public function calculateExpectedEvents(array $participants, int $legs = 1, array $algorithmSpecificParams = []): int
    {
        $participantCount = count($participants);

        if ($participantCount < 2) {
            return 0;
        }

        // Round robin formula: n*(n-1)/2 per leg
        $eventsPerLeg = intval($participantCount * ($participantCount - 1) / 2);

        return $eventsPerLeg * $legs;
    }

    #[\Override]
    public function getAlgorithmName(): string
    {
        return 'Round Robin';
    }

    public function getDescription(): string
    {
        return 'Each participant plays every other participant exactly once per leg. Formula: n*(n-1)/2 * legs';
    }
}
