<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Validation;

use MissionGaming\Tactician\Constraints\ConstraintInterface;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;

/**
 * Represents a constraint violation that occurred during scheduling.
 */
readonly class ConstraintViolation
{
    /**
     * @param array<Participant> $affectedParticipants
     */
    public function __construct(
        public ConstraintInterface $constraint,
        public Event $rejectedEvent,
        public string $reason,
        public array $affectedParticipants,
        public ?int $roundNumber = null
    ) {
    }

    public function getConstraintName(): string
    {
        return $this->constraint->getName();
    }

    /**
     * Get a human-readable description of this violation.
     */
    public function getDescription(): string
    {
        $round = $this->roundNumber ? " in round {$this->roundNumber}" : '';
        $participantLabels = array_map(fn (Participant $p) => $p->getLabel(), $this->affectedParticipants);
        $participantList = implode(', ', $participantLabels);

        return "Constraint '{$this->getConstraintName()}' violated{$round}: {$this->reason} (Participants: {$participantList})";
    }
}
