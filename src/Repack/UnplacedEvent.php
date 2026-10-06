<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Repack;

use MissionGaming\Tactician\DTO\Participant;

/**
 * A movable event the repack could not place, with the reason.
 *
 * Every movable event is either assigned or listed here — the counts
 * reconcile exactly, nothing vanishes.
 *
 * @api
 */
final readonly class UnplacedEvent
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
     * @return array{event_id: string, reason: string, participant: string|null}
     */
    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'reason' => $this->reason->value,
            'participant' => $this->participant?->getId(),
        ];
    }
}
