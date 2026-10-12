<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Repack;

use MissionGaming\Tactician\DTO\Participant;
use Override;

/**
 * More events need positions than exist.
 *
 * Scoped structurally by the nullable participant:
 * - participant set — the participant's event count exceeds its free
 *   positions across the whole grid once pins are respected (the
 *   shortfall says how many more positions — usually sessions' worth of
 *   slots — the operator must add);
 * - participant null — the grid as a whole is smaller than the event
 *   list.
 *
 * @api
 */
final readonly class CapacityExceeded implements RepackViolation
{
    /**
     * @param Participant|null $participant The participant whose events do not fit, or
     *                                      null when it is the grid that is too small
     * @param int $demand How many movable events need a place: all of the participant's,
     *                    counted before any event is dropped, or, for the grid, all
     *                    of them less the events already left out for over-capacity
     *                    participants
     * @param int $capacity How many of them the scope can take: the positions the
     *                      participant is not pinned at, or the places for an event on the
     *                      whole grid (positions times capacity per slot, less the pinned
     *                      events)
     */
    public function __construct(
        private ?Participant $participant,
        private int $demand,
        private int $capacity
    ) {}

    /**
     * Always ViolationKind::CapacityExceeded.
     */
    #[Override]
    public function getKind(): ViolationKind
    {
        return ViolationKind::CapacityExceeded;
    }

    /**
     * The participant with more movable events than free positions; null
     * when the violation is about the grid as a whole.
     */
    public function getParticipant(): ?Participant
    {
        return $this->participant;
    }

    /**
     * How many movable events needed a place in this scope.
     */
    public function getDemand(): int
    {
        return $this->demand;
    }

    /**
     * How many events the scope can take (see the constructor for what is
     * counted in each scope).
     */
    public function getCapacity(): int
    {
        return $this->capacity;
    }

    /**
     * How many events cannot fit in this scope: demand less capacity, and
     * never below 0. For a participant, the repacker leaves at least this
     * many of its events unplaced with the reason ParticipantOverCapacity,
     * each naming it or, for an event between two over-capacity
     * participants, the other one; it reports every participant whose
     * events outnumber its free positions. For the grid, at least this
     * many events are unplaced.
     */
    public function getShortfall(): int
    {
        return max(0, $this->demand - $this->capacity);
    }

    /**
     * Serialize to plain data; the participant by its ID, or null.
     *
     * @return array{kind: string, participant: string|null, demand: int, capacity: int, shortfall: int}
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'kind' => $this->getKind()->value,
            'participant' => $this->participant?->getId(),
            'demand' => $this->demand,
            'capacity' => $this->capacity,
            'shortfall' => $this->getShortfall(),
        ];
    }
}
