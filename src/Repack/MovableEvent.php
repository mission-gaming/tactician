<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Repack;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;

/**
 * An existing event the repack may move: a stable caller-supplied
 * identity and its two participants.
 *
 * The id is opaque — the library never invents one, never parses it, and
 * never orders results by anything else. Two movable events may carry the
 * same participant pairing (the input is a multigraph); only the id is
 * unique.
 */
final readonly class MovableEvent
{
    /**
     * @param string $id Caller-supplied stable identifier, unique across the request
     *
     * @throws InvalidConfigurationException When the id is empty or the participants are not distinct
     */
    public function __construct(
        private string $id,
        private Participant $participantA,
        private Participant $participantB
    ) {
        if ($id === '') {
            throw new InvalidConfigurationException(
                'A movable event needs a non-empty id',
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
}
