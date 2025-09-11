<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Constraints;

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\SchedulingContext;

/**
 * Prevents participants from having too many consecutive events in the same role.
 */
readonly class ConsecutiveRoleConstraint implements ConstraintInterface
{
    public function __construct(
        private int $maxConsecutive,
        private mixed $roleExtractor,
        private string $name = 'Consecutive Role Constraint'
    ) {
        if ($maxConsecutive < 1) {
            throw new \InvalidArgumentException('Max consecutive must be at least 1');
        }
        if (!is_callable($roleExtractor)) {
            throw new \InvalidArgumentException('Role extractor must be callable');
        }
    }

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

        // Extract roles for this participant
        $roles = array_map(fn (Event $event) => ($this->roleExtractor)($event, $participant), $allEvents);

        return !$this->hasConsecutiveRoles($roles, $this->maxConsecutive);
    }

    /**
     * Check if there are more than maxConsecutive same roles in a row.
     *
     * @param array<mixed> $roles
     */
    private function hasConsecutiveRoles(array $roles, int $maxConsecutive): bool
    {
        if (empty($roles)) {
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
     * Factory method for home/away role constraint.
     */
    public static function homeAway(int $maxConsecutive): self
    {
        return new self(
            $maxConsecutive,
            fn (Event $event, Participant $participant) => array_search($participant, $event->getParticipants(), true) === 0 ? 'home' : 'away',
            "Home/Away consecutive limit ({$maxConsecutive})"
        );
    }

    /**
     * Factory method for first/second position constraint.
     */
    public static function position(int $maxConsecutive): self
    {
        return new self(
            $maxConsecutive,
            fn (Event $event, Participant $participant) => array_search($participant, $event->getParticipants(), true),
            "Position consecutive limit ({$maxConsecutive})"
        );
    }
}
