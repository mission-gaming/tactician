<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Standings;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;

/**
 * A tiebreaker producing a comparable value per participant (higher is better).
 */
interface TiebreakerInterface
{
    /**
     * Get the unique name used to store and look up this tiebreaker's values.
     */
    public function getName(): string;

    /**
     * Calculate this tiebreaker's value for a participant.
     *
     * @param array<Result> $results All recorded results
     * @param array<string, StandingEntry> $entries Base standings entries keyed by participant ID
     */
    public function calculate(Participant $participant, array $results, array $entries): float;
}
