<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Validation;

use MissionGaming\Tactician\Constraints\ConstraintInterface;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;

/**
 * One rejection of a candidate event by one constraint during scheduling.
 *
 * A record of what generation tried and gave up: the event was a
 * candidate, and it was not added to the schedule.
 *
 * @experimental
 */
readonly class ConstraintViolation
{
    /**
     * @param ConstraintInterface $constraint The constraint that rejected the event
     * @param Event $rejectedEvent The candidate event it rejected
     * @param string $reason What happened, as text for a report
     * @param array<Participant> $affectedParticipants The participants the rejection concerns; the
     *                                                 schedulers pass those of the rejected event
     * @param int|null $roundNumber The 1-based round the event was a candidate for. Null and 0
     *                              both mean no round: the schedulers record 0 for an event
     *                              without one
     */
    public function __construct(
        public ConstraintInterface $constraint,
        public Event $rejectedEvent,
        public string $reason,
        public array $affectedParticipants,
        public ?int $roundNumber = null
    ) {}

    /**
     * The name of the constraint, read from it at the time of the call.
     */
    public function getConstraintName(): string
    {
        return $this->constraint->getName();
    }

    /**
     * One line of text for a report: the constraint's name, the round when
     * there is one, the reason, and the labels of the affected
     * participants. The wording is for people and is not stable.
     */
    public function getDescription(): string
    {
        $round = $this->roundNumber !== null && $this->roundNumber !== 0 ? " in round {$this->roundNumber}" : '';
        $participantLabels = array_map(fn(Participant $p) => $p->getLabel(), $this->affectedParticipants);
        $participantList = implode(', ', $participantLabels);

        return "Constraint '{$this->getConstraintName()}' violated{$round}: {$this->reason} (Participants: {$participantList})";
    }
}
