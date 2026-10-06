<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Repack;

use MissionGaming\Tactician\DTO\Participant;
use Override;

/**
 * A movable event received no position at all.
 *
 * Mirrors the outcome's unplaced list into the violation stream so a
 * caller dispatching on violations alone misses nothing.
 */
final readonly class EventUnplaced implements RepackViolation
{
    /**
     * @param Participant|null $participant The over-capacity participant, when the
     *                                      reason is ParticipantOverCapacity
     */
    public function __construct(
        private string $eventId,
        private UnplacedReason $reason,
        private ?Participant $participant = null
    ) {}

    #[Override]
    public function getKind(): ViolationKind
    {
        return ViolationKind::EventUnplaced;
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function getReason(): UnplacedReason
    {
        return $this->reason;
    }

    public function getParticipant(): ?Participant
    {
        return $this->participant;
    }

    /**
     * @return array{kind: string, event_id: string, reason: string, participant: string|null}
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'kind' => $this->getKind()->value,
            'event_id' => $this->eventId,
            'reason' => $this->reason->value,
            'participant' => $this->participant?->getId(),
        ];
    }
}
