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
 * Which limits RoundRobinScheduler satisfies with the roles as the role
 * assignment gives them depends on the role assignment, the legs and the
 * leg strategy, with a randomizer as without (measured for 2 to 30
 * participants and up to six legs):
 *
 * - The balanced roles, which `BalancedRoleAssignment` and the default
 *   (balanced where possible) start from: the running imbalance of
 *   a single leg never exceeds 1 in a field of even size and 2 in a field
 *   of odd size (the bye shifts one participant's parity), and that of any
 *   number of mirrored legs never exceeds 2: every even-numbered leg
 *   undoes the leg before it. Repeated legs keep 2 in a field of odd size;
 *   in a field of even size every leg adds the 1 of the first, so the
 *   limit has to be as large as the number of legs.
 * - The round-parity role assignment, the default up to 0.2.x: 3 in a
 *   field of even size and 4 in a field of odd size for a single leg and
 *   for any number of mirrored legs; repeated legs add the drift of the
 *   first with every leg (up to 6 and 8 after two legs).
 *
 * Below those figures the roles as given break the limit. The scheduler
 * then looks for others where a role is free to change: among the roles of
 * the first leg that the role assignment keeps, where it states a rule (the
 * balanced one does, so a balanced first leg that keeps the limit is found
 * where one exists in the layout, within the bounds of the search); among
 * the roles the shuffled leg strategy draws for later legs; and among all
 * roles of the first leg with `RoundRobinOptions(backtracking: true)`.
 * Where none is found the schedule fails; under the default, which is
 * balanced where possible, it fails only when the round-parity roles break
 * the limit as well, and otherwise has those roles and says so
 * (`RoleAssignment\RoleFallback`). This constraint counts over all
 * legs, so no roles keep a limit below the number of repeated legs in a
 * field of even size.
 *
 * @experimental
 */
final readonly class RoleBalanceConstraint implements ConstraintInterface
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
