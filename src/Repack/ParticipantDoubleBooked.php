<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Repack;

use MissionGaming\Tactician\DTO\Participant;
use Override;

/**
 * A participant occupies one (session, slot) position twice.
 *
 * Should be unreachable: the repacker never assigns a position either
 * participant already occupies, counting pinned events. The final audit
 * still checks for it — if this violation ever appears, the algorithm is
 * wrong, and the invariant tests assert its absence.
 *
 * @api
 */
final readonly class ParticipantDoubleBooked implements RepackViolation
{
    /**
     * @param int $session 0-based session index
     * @param int $slot 0-based slot index within the session
     * @param array<string> $eventIds The ids of the colliding events, pinned or
     *                                movable, in ascending byte order
     */
    public function __construct(
        private Participant $participant,
        private int $session,
        private int $slot,
        private array $eventIds
    ) {}

    /**
     * Always ViolationKind::ParticipantDoubleBooked.
     */
    #[Override]
    public function getKind(): ViolationKind
    {
        return ViolationKind::ParticipantDoubleBooked;
    }

    /**
     * The participant that is at one position twice.
     */
    public function getParticipant(): Participant
    {
        return $this->participant;
    }

    /**
     * The 0-based session index of the position.
     */
    public function getSession(): int
    {
        return $this->session;
    }

    /**
     * The 0-based slot index of the position within its session.
     */
    public function getSlot(): int
    {
        return $this->slot;
    }

    /**
     * The ids of the events that put the participant at the position, two
     * or more, in ascending byte order.
     *
     * @return array<string>
     */
    public function getEventIds(): array
    {
        return $this->eventIds;
    }

    /**
     * Serialize to plain data; the participant by its ID.
     *
     * @return array{kind: string, participant: string, session: int, slot: int, event_ids: array<string>}
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'kind' => $this->getKind()->value,
            'participant' => $this->participant->getId(),
            'session' => $this->session,
            'slot' => $this->slot,
            'event_ids' => $this->eventIds,
        ];
    }
}
