<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Standings;

use MissionGaming\Tactician\DTO\Participant;
use Override;

/**
 * Breaks ties by the sum of all opponents' ranking values (Buchholz system).
 *
 * Rewards having faced stronger opposition; commonly used in Swiss events.
 * This is the plain sum: no opponent is left out (no "median" or "cut"
 * variant) and a bye adds nothing.
 *
 * @experimental
 */
readonly class BuchholzTiebreaker implements TiebreakerInterface
{
    /**
     * Always 'buchholz': the key of this tiebreaker's value in
     * StandingEntry::getTiebreakers().
     */
    #[Override]
    public function getName(): string
    {
        return 'buchholz';
    }

    /**
     * The sum, over every result whose event the participant is in, of the
     * ranking value of each other participant of that event.
     *
     * The ranking value is the opponent's final one in the table, whatever
     * the result between the two was. An opponent met twice is counted
     * twice. A bye and an event without a result add nothing, and so does
     * an opponent that has no entry.
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

            foreach ($event->getParticipants() as $opponent) {
                if ($opponent->getId() === $participant->getId()) {
                    continue;
                }

                $opponentEntry = $entries[$opponent->getId()] ?? null;
                if ($opponentEntry !== null) {
                    $sum += $opponentEntry->getRankingValue();
                }
            }
        }

        return $sum;
    }
}
