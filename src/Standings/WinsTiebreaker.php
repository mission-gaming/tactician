<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Standings;

use MissionGaming\Tactician\DTO\Participant;
use Override;

/**
 * Breaks ties by number of wins.
 *
 * @experimental
 */
readonly class WinsTiebreaker implements TiebreakerInterface
{
    /**
     * Always 'wins': the key of this tiebreaker's value in
     * StandingEntry::getTiebreakers().
     */
    #[Override]
    public function getName(): string
    {
        return 'wins';
    }

    /**
     * The number of wins on the participant's entry, as a float; 0.0 for a
     * participant that has no entry. The results are not read.
     */
    #[Override]
    public function calculate(Participant $participant, array $results, array $entries): float
    {
        $entry = $entries[$participant->getId()] ?? null;

        return $entry === null ? 0.0 : (float) $entry->getWins();
    }
}
