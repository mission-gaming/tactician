<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Constraints;

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\InvalidInputException;
use MissionGaming\Tactician\Scheduling\SchedulingContext;

/**
 * Ensures a minimum number of rounds between repeat meetings of the same
 * pair of participants.
 *
 * Despite the name, it measures neither a participant's rest between its
 * own events nor time: it looks only at when the two participants of a
 * pairing last met each other, in round numbers. A pairing that has not met
 * before is never rejected, so in a single-leg round robin, where no pair
 * meets twice, the constraint rejects nothing. It has an effect across
 * legs: in a round robin generated without a randomizer a pair's meetings
 * in consecutive legs are exactly one leg's rounds apart, so a minimum
 * above the number of rounds in a leg fails generation.
 *
 * @experimental
 */
readonly class MinimumRestPeriodsConstraint implements ConstraintInterface
{
    /**
     * @param int $minRounds The smallest difference allowed between the round numbers of two
     *                       meetings of one pair. 1 allows consecutive rounds and forbids a
     *                       second meeting in the same round. A candidate in a round before
     *                       the pair's last meeting is rejected whatever the minimum
     *
     * @throws InvalidInputException When the minimum is below 1
     */
    public function __construct(private int $minRounds)
    {
        if ($minRounds < 1) {
            throw new InvalidInputException('Minimum rest periods must be at least 1');
        }
    }

    /**
     * False when two participants of the event last met fewer than the
     * minimum number of rounds before the event's round.
     *
     * The last meeting is the one with the highest round number among the
     * context's events that hold both participants, in any leg; events
     * without a round are not counted as meetings. A candidate without a
     * round is treated as round 0, and is therefore rejected whenever its
     * participants have met in a numbered round.
     */
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

    /**
     * `Minimum Rest Periods (N rounds)`, with the configured minimum.
     */
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
