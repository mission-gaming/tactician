<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Standings;

use MissionGaming\Tactician\DTO\Participant;
use Override;

/**
 * Breaks ties by the sum of all opponents' points (Buchholz system).
 *
 * Rewards having faced stronger opposition; commonly used in Swiss events.
 */
readonly class BuchholzTiebreaker implements TiebreakerInterface
{
    #[Override]
    public function getName(): string
    {
        return 'buchholz';
    }

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
                    $sum += $opponentEntry->getPoints();
                }
            }
        }

        return $sum;
    }
}
