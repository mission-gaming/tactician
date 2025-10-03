<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Ordering;

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use Override;

/**
 * Balances participant positions based on their historical event positions.
 *
 * This orderer tracks how many times each participant has been in the "home"
 * (first) position versus "away" (second) position and orders them to minimize
 * imbalance. The participant who has been "home" fewer times gets the "home" spot.
 *
 * This is particularly valuable for Swiss tournaments where home/away balance
 * across multiple rounds is important, but it works equally well for round-robin.
 *
 * In case of a tie (both participants have same home count), the original array
 * order is preserved for determinism.
 */
readonly class BalancedParticipantOrderer implements ParticipantOrderer
{
    /**
     * Order participants to balance home/away distribution.
     */
    #[Override]
    public function order(array $participants, EventOrderingContext $context): array
    {
        $participants = array_values($participants);

        if (count($participants) !== 2) {
            return $participants; // Only works for 2-participant events
        }

        $participant1 = $participants[0];
        $participant2 = $participants[1];

        // Count how many times each participant has been "home" (first position)
        $homeCount1 = $this->getHomeCount($participant1, $context);
        $homeCount2 = $this->getHomeCount($participant2, $context);

        // Participant with fewer "home" appearances goes first
        if ($homeCount1 < $homeCount2) {
            return [$participant1, $participant2];
        } elseif ($homeCount2 < $homeCount1) {
            return [$participant2, $participant1];
        }

        // Tie: preserve original order for determinism
        return [$participant1, $participant2];
    }

    /**
     * Count how many times a participant has been in the "home" (first) position.
     */
    private function getHomeCount(Participant $participant, EventOrderingContext $context): int
    {
        $events = $context->schedulingContext->getEventsForParticipant($participant);
        $homeCount = 0;

        foreach ($events as $event) {
            $eventParticipants = $event->getParticipants();
            if (empty($eventParticipants)) {
                continue;
            }

            // Check if this participant was in first position
            if ($eventParticipants[0]->getId() === $participant->getId()) {
                ++$homeCount;
            }
        }

        return $homeCount;
    }
}
