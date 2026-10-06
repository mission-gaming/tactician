<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Repack;

use DateTimeImmutable;

/**
 * One movable event's assigned position: (session, slot) on the grid,
 * decorated with the position's UTC kickoff.
 */
final readonly class SlotAssignment
{
    /**
     * @param string $eventId The movable event's caller-supplied id
     * @param int $session 0-based session index on the grid
     * @param int $slot 0-based slot index within the session
     * @param DateTimeImmutable $kickoff The position's kickoff time, in UTC
     */
    public function __construct(
        private string $eventId,
        private int $session,
        private int $slot,
        private DateTimeImmutable $kickoff
    ) {}

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function getSession(): int
    {
        return $this->session;
    }

    public function getSlot(): int
    {
        return $this->slot;
    }

    public function getKickoff(): DateTimeImmutable
    {
        return $this->kickoff;
    }

    /**
     * @return array{event_id: string, session: int, slot: int, kickoff: string}
     */
    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'session' => $this->session,
            'slot' => $this->slot,
            'kickoff' => $this->kickoff->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
