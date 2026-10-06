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
