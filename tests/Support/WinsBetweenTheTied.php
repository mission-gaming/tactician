<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Tests\Support;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Standings\TiedParticipantsTiebreaker;
use Override;

/**
 * A tiebreaker computed between the tied participants only, written against
 * the interface alone, for the tests of what the standings calculator hands
 * such a tiebreaker: the number of results it is handed that the participant
 * won.
 *
 * It counts the results itself and reads no entry, so a test that uses it
 * sees which results the calculator handed over: those between the
 * participants of a tied set, without the awarded ones when the awarded
 * result policy says so. It knows nothing of awarded results or of who is
 * tied. The library's own tiebreaker of this kind is BetweenTiedParticipants.
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
        $wins = 0.0;
        foreach ($results as $result) {
            if ($result->isWinFor($participant)) {
                ++$wins;
            }
        }

        return $wins;
    }
}
