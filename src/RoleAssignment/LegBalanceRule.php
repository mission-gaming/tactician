<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\RoleAssignment;

use MissionGaming\Tactician\Constraints\ConstraintInterface;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\SchedulingContext;
use Override;

/**
 * The rule `BalancedRoleAssignment` holds a leg of a round robin to, put as
 * a question about one event: may this event join the events of its leg
 * without making a balanced leg impossible?
 *
 * In a leg of a round robin a participant plays one event against every
 * other participant: one fewer than the field has participants. A leg is
 * balanced when each participant's two role counts are at most 1 apart,
 * which is the same as saying that it has neither role more often than half
 * its events, rounded up. So an event is refused exactly when it would give
 * one of its two participants a role once more than that. A leg in which no
 * event was refused is balanced when it is complete, and a balanced leg has
 * no event this rule refuses, in whatever order its events are put.
 *
 * The verdict reads the events of the candidate's own two participants in
 * the candidate's leg and nothing else, and the class keeps no state.
 *
 * @internal Not public API: `BalancedRoleAssignment::rule()` returns it for
 *           the round-robin scheduler's search of roles.
 */
final readonly class LegBalanceRule implements ConstraintInterface
{
    /**
     * False when the candidate would name one of its participants first,
     * or second, more often in its leg than half the events of a leg
     * rounded up. An event that does not have exactly two participants is
     * accepted, and such events in the context are not counted.
     */
    #[Override]
    public function isSatisfied(Event $event, SchedulingContext $context): bool
    {
        $participants = $event->getParticipants();
        if (count($participants) !== 2) {
            return true;
        }

        // Each participant plays n - 1 events in a leg, and half of that
        // rounded up is half of n rounded down
        $most = intdiv(count($context->getParticipants()), 2);
        $roundsPerLeg = $context->getPlan()->getRoundsPerLeg() ?? 0;
        $round = $event->getRound()?->getNumber();
        $leg = $roundsPerLeg > 0 && $round !== null ? intdiv($round - 1, $roundsPerLeg) : null;

        return $this->timesInRole($participants[0], 0, $leg, $roundsPerLeg, $context) < $most
            && $this->timesInRole($participants[1], 1, $leg, $roundsPerLeg, $context) < $most;
    }

    /**
     * Always 'Balanced roles in a leg'.
     */
    #[Override]
    public function getName(): string
    {
        return 'Balanced roles in a leg';
    }

    /**
     * How many of the context's events of the leg name the participant in
     * this position (0 for first-named). Every event counts when the leg is
     * not known.
     */
    private function timesInRole(Participant $participant, int $position, ?int $leg, int $roundsPerLeg, SchedulingContext $context): int
    {
        $times = 0;
        foreach ($context->getEventsForParticipant($participant) as $existing) {
            $pair = $existing->getParticipants();
            if (count($pair) !== 2 || $pair[$position]->getId() !== $participant->getId()) {
                continue;
            }

            if ($leg !== null) {
                $round = $existing->getRound()?->getNumber();
                if ($round === null || intdiv($round - 1, $roundsPerLeg) !== $leg) {
                    continue;
                }
            }

            ++$times;
        }

        return $times;
    }
}
