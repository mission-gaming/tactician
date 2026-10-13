<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Standings;

use MissionGaming\Tactician\DTO\Participant;
use Override;

/**
 * The ranking value of the participant's results as a tiebreaker value.
 *
 * On its own it separates nobody: entries reach a tiebreaker because their
 * ranking values are equal. It is the measure to wrap in
 * BetweenTiedParticipants, where the entries are those of the events
 * between the tied participants only: the ranking value each of them took
 * from the others it is level with.
 *
 * @experimental
 */
final readonly class RankingValueTiebreaker implements TiebreakerInterface
{
    /**
     * Always 'ranking-value': the key of this tiebreaker's value in
     * StandingEntry::getTiebreakers().
     */
    #[Override]
    public function getName(): string
    {
        return 'ranking-value';
    }

    /**
     * The ranking value of the results on the participant's entry
     * (StandingEntry::getRankingValueOfResults()): no bye and no
     * adjustment is in it. 0.0 for a participant that has no entry. The
     * results are not read.
     */
    #[Override]
    public function calculate(Participant $participant, array $results, array $entries): float
    {
        $entry = $entries[$participant->getId()] ?? null;

        return $entry?->getRankingValueOfResults() ?? 0.0;
    }
}
