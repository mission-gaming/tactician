<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Standings;

use MissionGaming\Tactician\DTO\Participant;
use Override;

/**
 * Breaks ties by score difference: scores-for minus scores-against.
 *
 * The calculator compares the score difference of level entries anyway,
 * after every configured tiebreaker. Naming it in the chain gives it a
 * place of the caller's choice (before the number of wins, say), and shows
 * the value on the entry. Wrapped in BetweenTiedParticipants it is the
 * score difference of the events between the tied participants only.
 *
 * @experimental
 */
final readonly class ScoreDifferenceTiebreaker implements TiebreakerInterface
{
    /**
     * Always 'score-difference': the key of this tiebreaker's value in
     * StandingEntry::getTiebreakers().
     */
    #[Override]
    public function getName(): string
    {
        return 'score-difference';
    }

    /**
     * The score difference on the participant's entry
     * (StandingEntry::getScoreDifference()); 0.0 for a participant that
     * has no entry. The results are not read, so the scores of an awarded
     * result count as the calculator's awarded result policy has them.
     */
    #[Override]
    public function calculate(Participant $participant, array $results, array $entries): float
    {
        $entry = $entries[$participant->getId()] ?? null;

        return $entry?->getScoreDifference() ?? 0.0;
    }
}
