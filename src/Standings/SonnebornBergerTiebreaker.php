<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Standings;

use MissionGaming\Tactician\DTO\Participant;
use Override;

/**
 * Breaks ties by the ranking values of defeated opponents plus half those of
 * drawn opponents (Sonneborn-Berger system).
 *
 * Rewards beating strong opposition rather than merely facing it.
 *
 * @experimental
 */
readonly class SonnebornBergerTiebreaker implements TiebreakerInterface
{
    /**
     * Always 'sonneborn-berger': the key of this tiebreaker's value in
     * StandingEntry::getTiebreakers().
     */
    #[Override]
    public function getName(): string
    {
        return 'sonneborn-berger';
    }

    /**
     * The sum, over every result whose event the participant is in, of the
     * ranking value of each other participant of that event: in full when
     * the participant won it, halved when it was drawn, and not at all when
     * the participant lost it.
     *
     * The ranking value is the opponent's final one in the table. An
     * opponent beaten twice is counted twice. A bye and an event without a
     * result add nothing, and so does an opponent that has no entry.
     */
    #[Override]
    public function calculate(Participant $participant, array $results, array $entries): float
    {
        $sum = 0.0;

        foreach ($results as $result) {
            $event = $result->getEvent();
            if (!$event->hasParticipant($participant)) {
                continue;
            }

            $factor = match (true) {
                $result->isDraw() => 0.5,
                $result->isWinFor($participant) => 1.0,
                default => 0.0,
            };

            if ($factor === 0.0) {
                continue;
            }

            foreach ($event->getParticipants() as $opponent) {
                if ($opponent->getId() === $participant->getId()) {
                    continue;
                }

                $opponentEntry = $entries[$opponent->getId()] ?? null;
                if ($opponentEntry !== null) {
                    $sum += $factor * $opponentEntry->getRankingValue();
                }
            }
        }

        return $sum;
    }
}
