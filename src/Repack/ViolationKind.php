<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Repack;

/**
 * The kinds of compromise a repack outcome can carry.
 *
 * The backing strings are stable identifiers for serialization and
 * caller-side dispatch; the caller renders and translates its own prose.
 *
 * @api
 */
enum ViolationKind: string
{
    /**
     * A participant occupies one (session, slot) position twice. Should be
     * unreachable — the repacker never assigns a conflicting position, and
     * the final audit exists to prove it. If this ever occurs, the
     * algorithm is wrong.
     */
    case ParticipantDoubleBooked = 'participant_double_booked';

    /** A movable event could not be placed on any position at all. */
    case EventUnplaced = 'event_unplaced';

    /** A participant's occupied slots within a session have an interior gap. */
    case ContiguityBroken = 'contiguity_broken';

    /** A participant's first event in a session is not the session's first slot. */
    case LateStart = 'late_start';

    /** More events need positions than exist — for a participant, a session, or the grid. */
    case CapacityExceeded = 'capacity_exceeded';
}
