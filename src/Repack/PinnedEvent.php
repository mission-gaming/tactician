<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Repack;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;

/**
 * An event already sitting on the grid that must not move.
 *
 * A pinned event occupies its (session, slot) position for both its
 * participants — it consumes slot capacity and blocks that position for
 * every other event either participant appears in — and is never assigned
 * a new position. Pins may name participants that appear nowhere in the
 * movable set (a withdrawn participant's played fixtures still block
 * their opponents' positions).
 *
 * Which events are pinned is domain policy about historical provenance
 * and stays entirely on the caller's side.
 *
 * @api
 */
final readonly class PinnedEvent
{
    /**
     * @param string $id Caller-supplied stable identifier, unique across the request
     * @param int $session 0-based session index on the grid
     * @param int $slot 0-based slot index within the session
     *
     * @throws InvalidConfigurationException When the id is empty, the participants are
     *                                       not distinct, or an index is negative
     */
    public function __construct(
        private string $id,
        private Participant $participantA,
        private Participant $participantB,
        private int $session,
        private int $slot
    ) {
        if ($id === '') {
            throw new InvalidConfigurationException(
                'A pinned event needs a non-empty id',
                ['participant_a' => $participantA->getId(), 'participant_b' => $participantB->getId()],
                reason: InvalidConfigurationReason::EmptyEventId
            );
        }

        if ($participantA->getId() === $participantB->getId()) {
            throw new InvalidConfigurationException(
                'An event needs two distinct participants',
                ['event_id' => $id, 'participant' => $participantA->getId()],
                reason: InvalidConfigurationReason::IdenticalParticipants
            );
        }

        if ($session < 0 || $slot < 0) {
            throw new InvalidConfigurationException(
                'Pinned positions are 0-based session and slot indexes',
                ['event_id' => $id, 'session' => $session, 'slot' => $slot],
                reason: InvalidConfigurationReason::PositionOutOfRange
            );
        }
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getParticipantA(): Participant
    {
        return $this->participantA;
    }

    public function getParticipantB(): Participant
    {
        return $this->participantB;
    }

    /**
     * @return array{Participant, Participant}
     */
    public function getParticipants(): array
    {
        return [$this->participantA, $this->participantB];
    }

    public function getSession(): int
    {
        return $this->session;
    }

    public function getSlot(): int
    {
        return $this->slot;
    }
}
