<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Constraints;

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\InvalidInputException;
use MissionGaming\Tactician\Scheduling\SchedulingContext;

/**
 * Prevents participants from having too many consecutive events in the same role.
 *
 * A streak is counted over a participant's own events in round order, the
 * candidate included, across legs. A round the participant does not play in
 * (a bye) neither counts towards a streak nor ends one. An event without a
 * round is ordered as round 0.
 *
 * @experimental
 */
readonly class ConsecutiveRoleConstraint implements ConstraintInterface
{
    /**
     * @param int $maxConsecutive The longest run of one role a participant may have
     * @param mixed $roleExtractor A `callable(Event, Participant): mixed` that names the role the
     *                             participant has in the event. Two roles are the same when they
     *                             are identical (`===`). It is called for the candidate and for
     *                             every earlier event of the participant, on every question
     * @param string $name The name the constraint is reported under
     *
     * @throws InvalidInputException When the limit is below 1 or the role extractor is not callable
     */
    public function __construct(
        private int $maxConsecutive,
        private mixed $roleExtractor,
        private string $name = 'Consecutive Role Constraint'
    ) {
        if ($maxConsecutive < 1) {
            throw new InvalidInputException('Max consecutive must be at least 1');
        }
        if (!is_callable($roleExtractor)) {
            throw new InvalidInputException('Role extractor must be callable');
        }
    }

    /**
     * False when, with the candidate added, some participant of it has more
     * than the allowed number of consecutive events in one role.
     *
     * The whole history of each participant is read, not only the events
     * next to the candidate: a participant whose events in the context
     * already hold a longer streak has every further event rejected.
     */
    #[\Override]
    public function isSatisfied(Event $event, SchedulingContext $context): bool
    {
        foreach ($event->getParticipants() as $participant) {
            if (!$this->validateParticipantRoleHistory($participant, $event, $context)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The name given to the constructor, or the one a factory method built.
     */
    #[\Override]
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Validate that adding this event won't create too many consecutive roles for the participant.
     */
    private function validateParticipantRoleHistory(Participant $participant, Event $newEvent, SchedulingContext $context): bool
    {
        $participantEvents = $context->getEventsForParticipant($participant);

        // Add the new event to get the full sequence
        $allEvents = [...$participantEvents, $newEvent];

        // Sort by round number
        usort($allEvents, function (Event $a, Event $b) {
            $roundA = $a->getRound()?->getNumber() ?? 0;
            $roundB = $b->getRound()?->getNumber() ?? 0;

            return $roundA <=> $roundB;
        });

        // The constructor rejects an extractor that is not callable; the
        // property stays `mixed` so that the constructor accepts what it
        // always has.
        $roleExtractor = $this->roleExtractor;
        assert(is_callable($roleExtractor));

        // Extract roles for this participant
        $roles = array_map(fn(Event $event) => $roleExtractor($event, $participant), $allEvents);

        return !$this->hasConsecutiveRoles($roles, $this->maxConsecutive);
    }

    /**
     * Check if there are more than maxConsecutive same roles in a row.
     *
     * @param array<mixed> $roles
     */
    private function hasConsecutiveRoles(array $roles, int $maxConsecutive): bool
    {
        if ($roles === []) {
            // Not reached: the list always holds the role of the event
            // being checked
            return false;
        }

        $consecutiveCount = 1;
        $previousRole = $roles[0];

        for ($i = 1; $i < count($roles); ++$i) {
            if ($roles[$i] === $previousRole) {
                ++$consecutiveCount;
                if ($consecutiveCount > $maxConsecutive) {
                    return true;
                }
            } else {
                $consecutiveCount = 1;
                $previousRole = $roles[$i];
            }
        }

        return false;
    }

    /**
     * A limit on consecutive events as the first-named participant (home)
     * or as any other (away), named `Home/Away consecutive limit (N)`.
     *
     * The participant is looked up in each event as the same object, not by
     * ID, which is what the schedulers pass. A copy of a participant (one
     * rebuilt with `Participant::fromArray()`, for example) is not found in
     * the events of the original and counts as away in all of them.
     *
     * @throws InvalidInputException When the limit is below 1
     */
    public static function homeAway(int $maxConsecutive): self
    {
        return new self(
            $maxConsecutive,
            fn(Event $event, Participant $participant) => array_search($participant, $event->getParticipants(), true) === 0 ? 'home' : 'away',
            "Home/Away consecutive limit ({$maxConsecutive})"
        );
    }

    /**
     * A limit on consecutive events in the same position of the event's
     * participant list (0 for the first-named), named `Position consecutive
     * limit (N)`. For events of two participants it is the same rule as
     * {@see self::homeAway()}; with more, each position is a role of its own.
     *
     * The participant is looked up as the same object, as in homeAway().
     *
     * @throws InvalidInputException When the limit is below 1
     */
    public static function position(int $maxConsecutive): self
    {
        return new self(
            $maxConsecutive,
            fn(Event $event, Participant $participant) => array_search($participant, $event->getParticipants(), true),
            "Position consecutive limit ({$maxConsecutive})"
        );
    }
}
