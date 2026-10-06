<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Validation;

use MissionGaming\Tactician\DTO\Participant;

/**
 * Collects and organizes constraint violations during scheduling.
 *
 * The round-robin scheduler records one violation for each constraint that
 * rejects a candidate event, and empties its collector before each
 * ordering it tries: after a failure the collector holds the rejections of
 * the last ordering tried, not of every one, and after a schedule was
 * returned it is empty.
 *
 * @experimental
 */
class ConstraintViolationCollector
{
    /** @var array<ConstraintViolation> */
    private array $violations = [];

    /**
     * Append a violation. Nothing is deduplicated.
     */
    public function recordViolation(ConstraintViolation $violation): void
    {
        $this->violations[] = $violation;
    }

    /**
     * Every violation, in the order it was recorded.
     *
     * @return array<ConstraintViolation>
     */
    public function getViolations(): array
    {
        return $this->violations;
    }

    /**
     * The violations grouped by the name of the constraint, each group in
     * the order recorded. Constraints that share a name share a group.
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
     * The violations grouped by the ID of each affected participant, each
     * group in the order recorded. A violation that affects two
     * participants is in both groups, so the groups add up to more than
     * getViolationCount().
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

    /**
     * Whether any violation has been recorded.
     */
    public function hasViolations(): bool
    {
        return count($this->violations) > 0;
    }

    /**
     * How many violations have been recorded: rejections, not distinct
     * events, since an event that two constraints reject is recorded twice.
     */
    public function getViolationCount(): int
    {
        return count($this->violations);
    }

    /**
     * How many violations each constraint has, keyed by the constraint's
     * name. A constraint with none has no entry.
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
     * The 1-based numbers of the rounds a violation was recorded for, each
     * once, in the order first recorded and not sorted. Violations without a
     * round (null or 0) contribute nothing. The keys are not consecutive.
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

        // Round 0 is left out, as it was when array_filter() ran without a
        // callback and dropped every falsy value.
        return array_unique(array_filter($rounds, static fn(int $round): bool => $round !== 0));
    }
}
