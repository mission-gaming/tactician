<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Exceptions;

/**
 * Thrown when a repack request pins one participant in two events at the
 * same session and slot.
 *
 * A participant plays one event at a time, so the two pins contradict each
 * other and no repack can be built over them: one of the two events has to
 * be moved or unpinned by the caller. `getEventIds()` says which two.
 *
 * The request checks the capacity of a slot before it checks participants.
 * Two events pinned at one position of a grid whose slots hold one event
 * (the default) are therefore reported as
 * {@see InvalidConfigurationReason::PinCapacityExceeded}, by a plain
 * InvalidConfigurationException that carries the position and no event ID,
 * whether or not they share a participant. This exception is raised where
 * the slot has room for both events and a participant is in both.
 *
 * It is an {@see InvalidConfigurationException}, so a catch clause written
 * for that class still matches. Its reason is always
 * {@see InvalidConfigurationReason::PinConflict}, and its context holds the
 * same four values as the accessors, under the keys `participant`,
 * `session`, `slot` and `event_ids`.
 */
final class PinConflictException extends InvalidConfigurationException
{
    /** @var array{string, string} */
    private readonly array $eventIds;

    /**
     * @param string $participantId The ID of the participant pinned twice
     * @param int $session The 0-based session index of the position
     * @param int $slot The 0-based slot index of the position
     * @param string $firstEventId The ID of the pinned event that took the position first, in
     *                             the order of the request
     * @param string $secondEventId The ID of the pinned event that collided with it
     */
    public function __construct(
        private readonly string $participantId,
        private readonly int $session,
        private readonly int $slot,
        string $firstEventId,
        string $secondEventId
    ) {
        $this->eventIds = [$firstEventId, $secondEventId];

        parent::__construct(
            'A participant is pinned twice at one position',
            [
                'participant' => $participantId,
                'session' => $session,
                'slot' => $slot,
                'event_ids' => $this->eventIds,
            ],
            reason: InvalidConfigurationReason::PinConflict
        );
    }

    /**
     * The IDs of the two pinned events that share the participant and the
     * position, in the order the request lists them.
     *
     * @return array{string, string}
     */
    public function getEventIds(): array
    {
        return $this->eventIds;
    }

    /**
     * The ID of the participant pinned twice.
     */
    public function getParticipantId(): string
    {
        return $this->participantId;
    }

    /**
     * The 0-based session index of the position both events are pinned at.
     */
    public function getSession(): int
    {
        return $this->session;
    }

    /**
     * The 0-based slot index, within the session, of the position both
     * events are pinned at.
     */
    public function getSlot(): int
    {
        return $this->slot;
    }
}
