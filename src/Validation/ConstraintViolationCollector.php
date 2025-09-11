<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Validation;

use MissionGaming\Tactician\DTO\Participant;

/**
 * Collects and organizes constraint violations during scheduling.
 */
class ConstraintViolationCollector
{
    /** @var array<ConstraintViolation> */
    private array $violations = [];

    public function recordViolation(ConstraintViolation $violation): void
    {
        $this->violations[] = $violation;
    }

    /**
     * @return array<ConstraintViolation>
     */
    public function getViolations(): array
    {
        return $this->violations;
    }

    /**
     * Get violations grouped by constraint.
     *
     * @return array<string, array<ConstraintViolation>>
     */
    public function getViolationsByConstraint(): array
    {
        $grouped = [];
        foreach ($this->violations as $violation) {
            $constraintName = $violation->getConstraintName();
            $grouped[$constraintName] ??= [];
            $grouped[$constraintName][] = $violation;
        }

        return $grouped;
    }

    /**
     * Get violations grouped by participant.
     *
     * @return array<string, array<ConstraintViolation>>
     */
    public function getViolationsByParticipant(): array
    {
        $grouped = [];
        foreach ($this->violations as $violation) {
            foreach ($violation->affectedParticipants as $participant) {
                $participantId = $participant->getId();
                $grouped[$participantId] ??= [];
                $grouped[$participantId][] = $violation;
            }
        }

        return $grouped;
    }

    public function hasViolations(): bool
    {
        return count($this->violations) > 0;
    }

    public function getViolationCount(): int
    {
        return count($this->violations);
    }

    /**
     * Get count of violations by constraint.
     *
     * @return array<string, int>
     */
    public function getViolationCountsByConstraint(): array
    {
        $counts = [];
        foreach ($this->getViolationsByConstraint() as $constraintName => $violations) {
            $counts[$constraintName] = count($violations);
        }

        return $counts;
    }

    /**
     * Get the rounds where violations occurred.
     *
     * @return array<int>
     */
    public function getAffectedRounds(): array
    {
        $rounds = [];
        foreach ($this->violations as $violation) {
            if ($violation->roundNumber !== null) {
                $rounds[] = $violation->roundNumber;
            }
        }

        return array_unique(array_filter($rounds));
    }
}
