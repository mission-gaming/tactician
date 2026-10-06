<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Repack;

use DateTimeImmutable;
use MissionGaming\Tactician\Exceptions\UnavailableValueException;

/**
 * One movable event's assigned position: (session, slot) on the grid,
 * decorated with the position's UTC kickoff.
 *
 * An assignment made on a shape-only grid has a position and no kickoff:
 * hasKickoff() is false, getKickoff() throws an
 * UnavailableValueException, and toArray() carries a null
 * `kickoff`. The grid has no instants, so the library states none.
 *
 * @api
 */
final readonly class SlotAssignment
{
    /**
     * @param string $eventId The movable event's caller-supplied id
     * @param int $session 0-based session index on the grid
     * @param int $slot 0-based slot index within the session
     * @param DateTimeImmutable|null $kickoff The position's kickoff time, in UTC; null
     *                                        when the grid is shape-only
     */
    public function __construct(
        private string $eventId,
        private int $session,
        private int $slot,
        private ?DateTimeImmutable $kickoff
    ) {}

    /**
     * The caller-supplied id of the movable event.
     */
    public function getEventId(): string
    {
        return $this->eventId;
    }

    /**
     * The 0-based index of the session the event was assigned to.
     */
    public function getSession(): int
    {
        return $this->session;
    }

    /**
     * The 0-based index of the slot the event was assigned to, within its
     * session.
     */
    public function getSlot(): int
    {
        return $this->slot;
    }

    /**
     * Whether the assignment carries a kickoff. False when the grid it was
     * made on is shape-only.
     */
    public function hasKickoff(): bool
    {
        return $this->kickoff instanceof DateTimeImmutable;
    }

    /**
     * The position's kickoff time, in UTC.
     *
     * @throws UnavailableValueException When the assignment was made on a
     *                                   shape-only grid and so has no kickoff.
     *                                   It is unchecked: ask hasKickoff() first
     *                                   where an assignment may come from such a
     *                                   grid
     */
    public function getKickoff(): DateTimeImmutable
    {
        if (!$this->kickoff instanceof DateTimeImmutable) {
            throw new UnavailableValueException(sprintf(
                'The assignment of event "%s" has no kickoff: its grid is shape-only. Check hasKickoff() before asking for one.',
                $this->eventId
            ));
        }

        return $this->kickoff;
    }

    /**
     * Serialize to plain data; the kickoff as an ISO 8601 UTC string to the
     * second (`2026-08-12T19:15:00Z`), or null when there is none.
     *
     * @return array{event_id: string, session: int, slot: int, kickoff: string|null}
     */
    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'session' => $this->session,
            'slot' => $this->slot,
            'kickoff' => $this->kickoff?->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
