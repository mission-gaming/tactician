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
     * @param int $demand How many positions were needed
     * @param int $capacity How many positions exist in this scope
     */
    public function __construct(
        private ?Participant $participant,
        private int $demand,
        private int $capacity
    ) {}

    #[Override]
    public function getKind(): ViolationKind
    {
        return ViolationKind::CapacityExceeded;
    }

    public function getParticipant(): ?Participant
    {
        return $this->participant;
    }

    public function getDemand(): int
    {
        return $this->demand;
    }

    public function getCapacity(): int
    {
        return $this->capacity;
    }

    /**
     * How many events cannot fit in this scope.
     */
    public function getShortfall(): int
    {
        return max(0, $this->demand - $this->capacity);
    }

    /**
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
