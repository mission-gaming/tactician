<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Repack;

/**
 * The product of a repack: the schedule and every compromise in it, as
 * structured data.
 *
 * Deliberately not exception-driven: an operator repairing a broken
 * season needs "here is the schedule, and here are the compromises"
 * rather than a refusal. Every movable event is either in the
 * assignments or in the unplaced list — the counts reconcile exactly.
 * The caller decides which violations are fatal
 * (RepackOptions(throwOnViolations: true) turns any violation into a
 * RepackViolationsException carrying this outcome, as a convenience).
 *
 * All three lists are deterministically ordered, so two outcomes from the
 * same input serialize byte-identically.
 */
final readonly class RepackOutcome
{
    /** @var array<string, SlotAssignment> Keyed by event id */
    private array $assignmentsById;

    /**
     * @param array<SlotAssignment> $assignments Sorted by event id
     * @param array<UnplacedEvent> $unplaced Sorted by event id
     * @param array<RepackViolation> $violations Sorted by kind, then scope
     */
    public function __construct(
        private array $assignments,
        private array $unplaced,
        private array $violations
    ) {
        $byId = [];
        foreach ($assignments as $assignment) {
            $byId[$assignment->getEventId()] = $assignment;
        }
        $this->assignmentsById = $byId;
    }

    /**
     * Every placed movable event's assignment, ordered by event id.
     *
     * Pinned events are not re-emitted — they hold their input positions
     * by contract.
     *
     * @return array<SlotAssignment>
     */
    public function getAssignments(): array
    {
        return $this->assignments;
    }

    /**
     * The assignment of one movable event, or null when it is unplaced or
     * unknown.
     */
    public function getAssignmentFor(string $eventId): ?SlotAssignment
    {
        return $this->assignmentsById[$eventId] ?? null;
    }

    /**
     * Every movable event that received no position, with reasons,
     * ordered by event id.
     *
     * @return array<UnplacedEvent>
     */
    public function getUnplaced(): array
    {
        return $this->unplaced;
    }

    /**
     * Every compromise in the returned schedule, itemised.
     *
     * @return array<RepackViolation>
     */
    public function getViolations(): array
    {
        return $this->violations;
    }

    /**
     * @return array<RepackViolation>
     */
    public function getViolationsOfKind(ViolationKind $kind): array
    {
        return array_values(array_filter(
            $this->violations,
            static fn (RepackViolation $violation): bool => $violation->getKind() === $kind
        ));
    }

    /**
     * Whether every event was placed with no compromises at all.
     */
    public function isClean(): bool
    {
        return $this->unplaced === [] && $this->violations === [];
    }

    /**
     * Serialize to plain data. Deterministic: the same input request
     * always produces the same array, whatever order its lists were in.
     *
     * @return array{assignments: array<array{event_id: string, session: int, slot: int, kickoff: string}>, unplaced: array<array{event_id: string, reason: string, participant: string|null}>, violations: array<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'assignments' => array_map(
                static fn (SlotAssignment $assignment): array => $assignment->toArray(),
                $this->assignments
            ),
            'unplaced' => array_map(
                static fn (UnplacedEvent $unplaced): array => $unplaced->toArray(),
                $this->unplaced
            ),
            'violations' => array_map(
                static fn (RepackViolation $violation): array => $violation->toArray(),
                $this->violations
            ),
        ];
    }
}
