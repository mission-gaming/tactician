<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Repack;

/**
 * Why a movable event could not be placed.
 *
 * The backing strings are stable identifiers for serialization and
 * caller-side dispatch.
 *
 * @api
 */
enum UnplacedReason: string
{
    /**
     * One of the event's participants needs more positions than the grid
     * has free for it (its event count exceeds its free positions once
     * pins are respected). The accompanying CapacityExceeded violation
     * names the participant and the shortfall. An event between two such
     * participants counts towards both shortfalls and names one of them.
     */
    case ParticipantOverCapacity = 'participant_over_capacity';

    /**
     * No position on the whole grid had capacity left with both
     * participants free.
     */
    case NoSlotAvailable = 'no_slot_available';
}
