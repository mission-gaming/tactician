<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Tests\Support;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Standings\TiedParticipantsTiebreaker;
use Override;

/**
 * A tiebreaker computed between the tied participants only, for the tests
 * of awarded results: the number of wins a participant has over the
 * participants whose ranking value in the table equals its own.
 *
 * It knows nothing of awarded results. Whether it is handed them is the
 * choice of the standings calculator (AwardedResultPolicy), which is what
 * the tests that use it are about. The library ships no tiebreaker of this
 * kind yet.
 */
final readonly class WinsBetweenTheTied implements TiedParticipantsTiebreaker
{
    public const string NAME = 'wins-between-the-tied';

    #[Override]
    public function getName(): string
    {
        return self::NAME;
    }

    #[Override]
    public function calculate(Participant $participant, array $results, array $entries): float
    {
        $own = $entries[$participant->getId()] ?? null;
        if ($own === null) {
            return 0.0;
        }

        $wins = 0.0;
        foreach ($results as $result) {
            if (!$result->isWinFor($participant)) {
                continue;
            }

            foreach ($result->getEvent()->getParticipants() as $opponent) {
                $entry = $entries[$opponent->getId()] ?? null;
                if ($opponent->getId() !== $participant->getId()
                    && $entry !== null
                    && $entry->getRankingValue() === $own->getRankingValue()
                ) {
                    ++$wins;
                }
            }
        }

        return $wins;
    }
}
