<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Standings;

use MissionGaming\Tactician\DTO\Participant;
use Override;

/**
 * Breaks ties by number of wins.
 */
readonly class WinsTiebreaker implements TiebreakerInterface
{
    #[Override]
    public function getName(): string
    {
        return 'wins';
    }

    #[Override]
    public function calculate(Participant $participant, array $results, array $entries): float
    {
        $entry = $entries[$participant->getId()] ?? null;

        return $entry === null ? 0.0 : (float) $entry->getWins();
    }
}
