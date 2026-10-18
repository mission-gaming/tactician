<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

/**
 * Where a slot of a bracket is filled from (see BracketSlot).
 *
 * The backing strings are stable identifiers. New cases may be added in a
 * later release, so a `match` over a case needs a `default` arm.
 *
 * @experimental
 */
enum SlotSource: string
{
    /**
     * The entry fold: the slot holds the entrant at a position of the
     * entrant list (BracketSlot::getDrawPosition()), or nobody where the
     * position is beyond the field, which is a bye.
     */
    case Draw = 'draw';

    /** The entrant who goes on from a node before it (BracketSlot::getSourceNodes()). */
    case AdvancerOf = 'advancer-of';

    /**
     * The entrant who does not go on from a node before it: the entrant
     * who drops to the losers bracket, who plays for third place, or who
     * plays the grand final reset as the winners champion.
     */
    case LoserOf = 'loser-of';

    /**
     * A round drawn by hand named the entrant in a node the bracket has no
     * slot of the round for: the entrant was not among the slots the round
     * was entered with, or its slot partner was paired elsewhere.
     */
    case Recorded = 'recorded';
}
