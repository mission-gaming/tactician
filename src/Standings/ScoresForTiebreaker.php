<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Standings;

use MissionGaming\Tactician\DTO\Participant;
use Override;

/**
 * Breaks ties by scores-for: the sum of the participant's own recorded
 * scores.
 *
 * The calculator compares scores-for of level entries anyway, last of all.
 * Naming it in the chain gives it a place of the caller's choice and shows
 * the value on the entry. Wrapped in BetweenTiedParticipants it is the
 * scores-for of the events between the tied participants only.
 *
 * @experimental
 */
final readonly class ScoresForTiebreaker implements TiebreakerInterface
{
    /**
     * Always 'scores-for': the key of this tiebreaker's value in
     * StandingEntry::getTiebreakers().
     */
    #[Override]
    public function getName(): string
    {
        return 'scores-for';
    }

    /**
     * Scores-for on the participant's entry (StandingEntry::getScoreFor());
     * 0.0 for a participant that has no entry. The results are not read,
     * so the scores of an awarded result count as the calculator's awarded
     * result policy has them.
     */
    #[Override]
    public function calculate(Participant $participant, array $results, array $entries): float
    {
        $entry = $entries[$participant->getId()] ?? null;

        return $entry?->getScoreFor() ?? 0.0;
    }
}
