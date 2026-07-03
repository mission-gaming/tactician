<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Constraints;

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\SchedulingContext;

/**
 * Limits how far a participant's home and away totals may drift apart.
 *
 * The first participant in an event is treated as home, the second as away.
 * The imbalance is evaluated on the running totals as the schedule is
 * generated, so like all constraints it is subject to the greedy generator:
 * tight limits may reject orderings that a completed schedule would balance
 * out. RoundRobinScheduler alternates roles with round parity, bounding the
 * running imbalance at 3 for even field sizes and 4 for odd ones (the bye
 * shifts one player's parity), so limits at or above those bounds are always
 * satisfiable with the built-in generator; mirrored multi-leg schedules
 * additionally end perfectly balanced.
 */
readonly class RoleBalanceConstraint implements ConstraintInterface
{
    public function __construct(
        private int $maxImbalance,
        private string $name = 'Role Balance Constraint'
    ) {
        if ($maxImbalance < 1) {
            throw new \InvalidArgumentException('Max imbalance must be at least 1');
        }
    }

    #[\Override]
    public function isSatisfied(Event $event, SchedulingContext $context): bool
    {
        $participants = $event->getParticipants();
        if (count($participants) !== 2) {
            return true; // Home/away roles only apply to two-participant events
        }

        return $this->imbalanceAfterEvent($participants[0], true, $context) <= $this->maxImbalance
            && $this->imbalanceAfterEvent($participants[1], false, $context) <= $this->maxImbalance;
    }

    #[\Override]
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Factory method for a home/away balance constraint.
     */
    public static function homeAway(int $maxImbalance): self
    {
        return new self($maxImbalance, "Home/Away balance limit ({$maxImbalance})");
    }

    /**
     * Calculate the participant's home/away imbalance including the new event.
     */
    private function imbalanceAfterEvent(Participant $participant, bool $asHome, SchedulingContext $context): int
    {
        $home = $asHome ? 1 : 0;
        $away = $asHome ? 0 : 1;

        foreach ($context->getEventsForParticipant($participant) as $existingEvent) {
            $existingParticipants = $existingEvent->getParticipants();
            if (count($existingParticipants) !== 2) {
                continue;
            }

            if ($existingParticipants[0]->getId() === $participant->getId()) {
                ++$home;
            } else {
                ++$away;
            }
        }

        return abs($home - $away);
    }
}
