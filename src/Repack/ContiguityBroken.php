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
 *
 * @api
 */
final readonly class ContiguityBroken implements RepackViolation
{
    /**
     * @param int $session 0-based session index
     * @param int $gapSlots How many interior slots are empty between the
     *                      participant's first and last occupied slot
     * @param array<int> $occupiedSlots The 0-based slots the participant occupies in
     *                                  the session (pins included), ascending
     */
    public function __construct(
        private Participant $participant,
        private int $session,
        private int $gapSlots,
        private array $occupiedSlots
    ) {}

    /**
     * Always ViolationKind::ContiguityBroken.
     */
    #[Override]
    public function getKind(): ViolationKind
    {
        return ViolationKind::ContiguityBroken;
    }

    /**
     * The participant whose slots have the gap.
     */
    public function getParticipant(): Participant
    {
        return $this->participant;
    }

    /**
     * The 0-based index of the session the gap is in.
     */
    public function getSession(): int
    {
        return $this->session;
    }

    /**
     * How many slots between the participant's first and last occupied
     * slot of the session it does not occupy; at least 1 in a violation
     * the repacker reports.
     */
    public function getGapSlots(): int
    {
        return $this->gapSlots;
    }

    /**
     * The 0-based slots the participant occupies in the session, pinned and
     * assigned together, ascending and each once.
     *
     * @return array<int>
     */
    public function getOccupiedSlots(): array
    {
        return $this->occupiedSlots;
    }

    /**
     * Serialize to plain data; the participant by its ID.
     *
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
