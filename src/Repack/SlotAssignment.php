<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Repack;

use DateTimeImmutable;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;

/**
 * One movable event's assigned position: (session, slot) on the grid,
 * decorated with the position's UTC kickoff.
 *
 * An assignment made on a shape-only grid has a position and no kickoff:
 * hasKickoff() is false, getKickoff() throws, and toArray() carries a null
 * `kickoff`. The grid has no instants, so the library states none.
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
     * @throws InvalidConfigurationException When the assignment was made on a
     *                                       shape-only grid and so has no kickoff;
     *                                       the reason is GridWithoutInstants
     */
    public function getKickoff(): DateTimeImmutable
    {
        if (!$this->kickoff instanceof DateTimeImmutable) {
            throw new InvalidConfigurationException(
                'This assignment has no kickoff: its grid is shape-only',
                ['event_id' => $this->eventId, 'session' => $this->session, 'slot' => $this->slot],
                reason: InvalidConfigurationReason::GridWithoutInstants
            );
        }

        return $this->kickoff;
    }

    /**
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
