<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Standings;

use MissionGaming\Tactician\DTO\Participant;
use Override;

/**
 * Breaks ties by the points of defeated opponents plus half the points of
 * drawn opponents (Sonneborn-Berger system).
 *
 * Rewards beating strong opposition rather than merely facing it.
 */
readonly class SonnebornBergerTiebreaker implements TiebreakerInterface
{
    #[Override]
    public function getName(): string
    {
        return 'sonneborn-berger';
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
                    $sum += $factor * $opponentEntry->getPoints();
                }
            }
        }

        return $sum;
    }
}
