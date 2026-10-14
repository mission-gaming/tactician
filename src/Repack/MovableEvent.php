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
 *
 * An event may also say where it is now: a current position, the session
 * and the slot it holds on the grid of the request before the repack. It
 * is a statement of fact and never a demand: the repacker is free to put
 * the event elsewhere, and reports an event it placed anywhere but there
 * as moved ({@see RepackOutcome::getMovedEventIds()}). It changes where
 * events go only when the options ask for the fewest moved events
 * ({@see RepackObjective::LeastChurn}); without that objective a request
 * with current positions is repacked exactly as the same request without
 * them. An event that states none (a new event, or one whose earlier time
 * the caller has no index for on this grid) is never counted as moved.
 *
 * @api
 */
final readonly class MovableEvent
{
    /**
     * Which participant is A and which is B is kept and changes nothing in
     * the repack.
     *
     * The current position is given whole or not at all: a session and a
     * slot, both 0-based indexes into the grid of the request, as a pinned
     * event has them. It need not be a position the grid has, and need not
     * be free: an event whose position the grid does not have, or that a
     * pinned event leaves no room at, cannot stay where it is, and counts
     * as moved wherever it is placed. Nothing checks the current positions
     * of a request against each other either; where two events cannot both
     * stay (one participant in both, or more events than the capacity of
     * the slot), at most one does.
     *
     * @param string $id Caller-supplied stable identifier, unique across the request
     *                   (the request checks that, not this constructor)
     * @param int|null $currentSession 0-based index of the session the event is in now,
     *                                 or null when it states no current position
     * @param int|null $currentSlot 0-based index of its slot within that session, or null
     *
     * @throws InvalidConfigurationException When the id is empty, the two participants have
     *                                       the same ID, only one of the two indexes of the
     *                                       current position is given, or one is negative
     */
    public function __construct(
        private string $id,
        private Participant $participantA,
        private Participant $participantB,
        private ?int $currentSession = null,
        private ?int $currentSlot = null
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

        if (($currentSession === null) !== ($currentSlot === null)) {
            throw new InvalidConfigurationException(
                'A current position is a session and a slot: give both or neither',
                ['event_id' => $id, 'current_session' => $currentSession, 'current_slot' => $currentSlot],
                reason: InvalidConfigurationReason::IncompatibleOptions
            );
        }

        if (($currentSession ?? 0) < 0 || ($currentSlot ?? 0) < 0) {
            throw new InvalidConfigurationException(
                'Current positions are 0-based session and slot indexes',
                ['event_id' => $id, 'current_session' => $currentSession, 'current_slot' => $currentSlot],
                reason: InvalidConfigurationReason::PositionOutOfRange
            );
        }
    }

    /**
     * The caller-supplied id, as given.
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * The participant given first.
     */
    public function getParticipantA(): Participant
    {
        return $this->participantA;
    }

    /**
     * The participant given second.
     */
    public function getParticipantB(): Participant
    {
        return $this->participantB;
    }

    /**
     * Both participants: A, then B.
     *
     * @return array{Participant, Participant}
     */
    public function getParticipants(): array
    {
        return [$this->participantA, $this->participantB];
    }

    /**
     * Whether the event says where it is now.
     */
    public function hasCurrentPosition(): bool
    {
        return $this->currentSession !== null;
    }

    /**
     * The 0-based index of the session the event is in now, or null when
     * it states no current position.
     */
    public function getCurrentSession(): ?int
    {
        return $this->currentSession;
    }

    /**
     * The 0-based index of the slot the event is at now, within its
     * current session, or null when it states no current position.
     */
    public function getCurrentSlot(): ?int
    {
        return $this->currentSlot;
    }
}
