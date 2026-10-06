<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Constraints;

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\InvalidInputException;
use MissionGaming\Tactician\Scheduling\SchedulingContext;

/**
 * Limits how far a participant's home and away totals may drift apart.
 *
 * The first participant in an event is treated as home, the second as away.
 * The imbalance is evaluated on the running totals as the schedule is
 * generated, over every leg generated so far, so like all constraints it is
 * subject to the greedy generator: tight limits may reject orderings that a
 * completed schedule would balance out.
 *
 * Which limits RoundRobinScheduler can satisfy depends on the legs and the
 * options. With the default round-parity roles and no randomizer, the
 * running imbalance of a single leg never exceeds 3 in a field of even size
 * and 4 in a field of odd size (the bye shifts one participant's parity).
 * A limit at or above that bound is therefore satisfied by a single leg,
 * and by two or three mirrored legs: the second leg undoes the first, so
 * two mirrored legs end with every participant balanced, and the third
 * drifts as far the other way.
 *
 * The same limit is not satisfied in general:
 *
 * - by the repeated leg strategy at two or more legs, where every leg adds
 *   the drift of the first (up to 6 and 8 after two legs);
 * - by four or more mirrored legs: every leg after the first is the reverse
 *   of the first, so the fourth leg drifts twice as far as a single one;
 * - by the shuffled leg strategy, which draws the roles of later legs;
 * - by a multi-leg schedule from a scheduler that was given a randomizer.
 *
 * `BalancedRoleAssignment` gives each participant role counts at most 1
 * apart within every leg, and is the option to reach for when balance
 * matters. This constraint counts over all legs, and under the repeated
 * leg strategy the differences of the legs add up.
 *
 * @experimental
 */
readonly class RoleBalanceConstraint implements ConstraintInterface
{
    /**
     * @param int $maxImbalance The largest difference allowed between a participant's number of
     *                          events as first-named and as second-named
     * @param string $name The name the constraint is reported under
     *
     * @throws InvalidInputException When the allowed imbalance is below 1
     */
    public function __construct(
        private int $maxImbalance,
        private string $name = 'Role Balance Constraint'
    ) {
        if ($maxImbalance < 1) {
            throw new InvalidInputException('Max imbalance must be at least 1');
        }
    }

    /**
     * False when, with the candidate counted, either of its participants
     * has role totals further apart than the limit.
     *
     * The totals are taken over all the context's events of the
     * participant, in every leg, with participants matched by ID. Only
     * events of exactly two participants have these roles: a candidate of
     * any other size is accepted, and such events in the context are not
     * counted.
     */
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

    /**
     * The name given to the constructor, or the one homeAway() built.
     */
    #[\Override]
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * The same constraint under the name `Home/Away balance limit (N)`,
     * which states the limit in failure reports.
     *
     * @throws InvalidInputException When the allowed imbalance is below 1
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
