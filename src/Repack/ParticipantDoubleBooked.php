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
 */
final readonly class ParticipantDoubleBooked implements RepackViolation
{
    /**
     * @param array<string> $eventIds The ids of the colliding events, sorted
     */
    public function __construct(
        private Participant $participant,
        private int $session,
        private int $slot,
        private array $eventIds
    ) {}

    #[Override]
    public function getKind(): ViolationKind
    {
        return ViolationKind::ParticipantDoubleBooked;
    }

    public function getParticipant(): Participant
    {
        return $this->participant;
    }

    public function getSession(): int
    {
        return $this->session;
    }

    public function getSlot(): int
    {
        return $this->slot;
    }

    /**
     * @return array<string>
     */
    public function getEventIds(): array
    {
        return $this->eventIds;
    }

    /**
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
