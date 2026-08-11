<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Repack;

use MissionGaming\Tactician\DTO\Participant;
use Override;

/**
 * A participant's occupied slots within a session have an interior gap.
 *
 * Reported only for sessions where the participant has at least one
 * movable assignment — pinned slots count toward the occupancy pattern,
 * but a purely-pinned session is historical fact the repack cannot
 * influence. The magnitude is the number of empty interior slots.
 */
final readonly class ContiguityBroken implements RepackViolation
{
    /**
     * @param int $gapSlots How many interior slots are empty between the
     *                      participant's first and last occupied slot
     * @param array<int> $occupiedSlots The slots the participant occupies in the
     *                                  session (pins included), ascending
     */
    public function __construct(
        private Participant $participant,
        private int $session,
        private int $gapSlots,
        private array $occupiedSlots
    ) {
    }

    #[Override]
    public function getKind(): ViolationKind
    {
        return ViolationKind::ContiguityBroken;
    }

    public function getParticipant(): Participant
    {
        return $this->participant;
    }

    public function getSession(): int
    {
        return $this->session;
    }

    public function getGapSlots(): int
    {
        return $this->gapSlots;
    }

    /**
     * @return array<int>
     */
    public function getOccupiedSlots(): array
    {
        return $this->occupiedSlots;
    }

    /**
     * @return array{kind: string, participant: string, session: int, gap_slots: int, occupied_slots: array<int>}
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'kind' => $this->getKind()->value,
            'participant' => $this->participant->getId(),
            'session' => $this->session,
            'gap_slots' => $this->gapSlots,
            'occupied_slots' => $this->occupiedSlots,
        ];
    }
}
