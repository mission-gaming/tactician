<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Constraints;

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\InvalidInputException;
use MissionGaming\Tactician\Scheduling\SchedulingContext;

/**
 * Ensures minimum number of rounds between repeat meetings of the same participants.
 *
 * @experimental
 */
readonly class MinimumRestPeriodsConstraint implements ConstraintInterface
{
    /**
     * @throws InvalidInputException When the minimum is below 1
     */
    public function __construct(private int $minRounds)
    {
        if ($minRounds < 1) {
            throw new InvalidInputException('Minimum rest periods must be at least 1');
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

        // A context of exactly this class returns the pair's meetings from
        // its index. A subclass may answer getExistingEvents() its own way,
        // so for one the events it returns are scanned as before.
        $meetings = $context::class === SchedulingContext::class
            ? $context->getEventsBetween($participant1, $participant2)
            : array_filter(
                $context->getExistingEvents(),
                static fn(Event $existingEvent): bool => $existingEvent->hasParticipant($participant1)
                    && $existingEvent->hasParticipant($participant2)
            );

        foreach ($meetings as $meeting) {
            $roundNumber = $meeting->getRound()?->getNumber();
            if ($roundNumber !== null && ($lastRound === null || $roundNumber > $lastRound)) {
                $lastRound = $roundNumber;
            }
        }

        return $lastRound;
    }
}
