<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Repack;

use MissionGaming\Tactician\DTO\Participant;
use Override;

/**
 * A participant's first event in a session is not the session's first
 * slot.
 *
 * Reported only for sessions where the participant has at least one
 * movable assignment; pinned slots count toward the occupancy pattern.
 * Starting late is an objective, not a hard rule — a pin partway down a
 * session can force it (the contiguous run then builds around the pin) —
 * so the caller decides whether a given late start matters.
 *
 * @api
 */
final readonly class LateStart implements RepackViolation
{
    /**
     * @param int $session 0-based session index
     * @param int $firstSlot The first slot the participant occupies in the session
     *                       (0-based, pins included)
     */
    public function __construct(
        private Participant $participant,
        private int $session,
        private int $firstSlot
    ) {}

    /**
     * Always ViolationKind::LateStart.
     */
    #[Override]
    public function getKind(): ViolationKind
    {
        return ViolationKind::LateStart;
    }

    /**
     * The participant that starts late.
     */
    public function getParticipant(): Participant
    {
        return $this->participant;
    }

    /**
     * The 0-based index of the session it starts late in.
     */
    public function getSession(): int
    {
        return $this->session;
    }

    /**
     * The 0-based first slot the participant occupies in the session, pins
     * included; at least 1 in a violation the repacker reports, and also
     * the number of slots it sits out before its first event.
     */
    public function getFirstSlot(): int
    {
        return $this->firstSlot;
    }

    /**
     * Serialize to plain data; the participant by its ID.
     *
     * @return array{kind: string, participant: string, session: int, first_slot: int}
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'kind' => $this->getKind()->value,
            'participant' => $this->participant->getId(),
            'session' => $this->session,
            'first_slot' => $this->firstSlot,
        ];
    }
}
