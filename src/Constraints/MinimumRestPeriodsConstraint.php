<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Constraints;

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\SchedulingContext;

/**
 * Ensures minimum number of rounds between repeat meetings of the same participants.
 */
readonly class MinimumRestPeriodsConstraint implements ConstraintInterface
{
    public function __construct(private int $minRounds)
    {
        if ($minRounds < 1) {
            throw new \InvalidArgumentException('Minimum rest periods must be at least 1');
        }
    }

    #[\Override]
    public function isSatisfied(Event $event, SchedulingContext $context): bool
    {
        $participants = $event->getParticipants();
        $currentRound = $event->getRound()?->getNumber() ?? 0;

        // Check each pair of participants in this event
        for ($i = 0; $i < count($participants); ++$i) {
            for ($j = $i + 1; $j < count($participants); ++$j) {
                $lastMeeting = $this->findLastMeetingRound($participants[$i], $participants[$j], $context);
                if ($lastMeeting !== null && ($currentRound - $lastMeeting) < $this->minRounds) {
                    return false;
                }
            }
        }

        return true;
    }

    #[\Override]
    public function getName(): string
    {
        return "Minimum Rest Periods ({$this->minRounds} rounds)";
    }

    /**
     * Find the last round where two participants played against each other.
     */
    private function findLastMeetingRound(Participant $participant1, Participant $participant2, SchedulingContext $context): ?int
    {
        $lastRound = null;

        foreach ($context->getExistingEvents() as $existingEvent) {
            if ($existingEvent->hasParticipant($participant1) && $existingEvent->hasParticipant($participant2)) {
                $roundNumber = $existingEvent->getRound()?->getNumber();
                if ($roundNumber !== null && ($lastRound === null || $roundNumber > $lastRound)) {
                    $lastRound = $roundNumber;
                }
            }
        }

        return $lastRound;
    }
}
