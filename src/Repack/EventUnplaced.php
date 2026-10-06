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
 *
 * @api
 */
final readonly class EventUnplaced implements RepackViolation
{
    /**
     * @param string $eventId The movable event's caller-supplied id
     * @param UnplacedReason $reason Why no position was assigned
     * @param Participant|null $participant The over-capacity participant, when the
     *                                      reason is ParticipantOverCapacity
     */
    public function __construct(
        private string $eventId,
        private UnplacedReason $reason,
        private ?Participant $participant = null
    ) {}

    /**
     * Always ViolationKind::EventUnplaced.
     */
    #[Override]
    public function getKind(): ViolationKind
    {
        return ViolationKind::EventUnplaced;
    }

    /**
     * The caller-supplied id of the movable event that has no position.
     */
    public function getEventId(): string
    {
        return $this->eventId;
    }

    /**
     * Why the event has no position.
     */
    public function getReason(): UnplacedReason
    {
        return $this->reason;
    }

    /**
     * The participant with more events than free positions, when the
     * reason is ParticipantOverCapacity; null for the repacker's other
     * reason.
     */
    public function getParticipant(): ?Participant
    {
        return $this->participant;
    }

    /**
     * Serialize to plain data; the reason as its backing string, the
     * participant by its ID or null.
     *
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
