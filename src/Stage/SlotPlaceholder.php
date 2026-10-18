<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

/**
 * What stands in a slot of a bracket that holds no entrant (see
 * BracketSlot::getPlaceholder()).
 *
 * The first three are slots that are still to be filled; the others will
 * hold nobody. The backing strings are stable identifiers. New cases may be
 * added in a later release, so a `match` over a case needs a `default` arm.
 *
 * @experimental
 */
enum SlotPlaceholder: string
{
    /** The entrant who goes on from the node the slot is filled from, once it is settled. */
    case AdvancerOf = 'advancer-of';

    /** The entrant who does not go on from the node the slot is filled from, once it is settled. */
    case LoserOf = 'loser-of';

    /**
     * One of the entrants who go on from the nodes of the round before,
     * placed by re-seeding once that round is settled (a re-seeded single
     * elimination bracket, EliminationOptions::$reseedEachRound).
     */
    case Reseeded = 'reseeded';

    /**
     * Nobody, and nobody ever will be: the entry fold gives the slot no
     * entrant, or the node it is filled from had nobody to drop (the
     * dropper of a bye in a double elimination bracket).
     */
    case Bye = 'bye';

    /**
     * Nobody, because the node the slot is filled from sent nobody on: it
     * is void (both of its entrants withdrew before either went through,
     * ADR 0011) or empty.
     */
    case Void = 'void';

    /** Nobody, because the node is not played (BracketNodeStatus::NotPlayed). */
    case NotPlayed = 'not-played';

    /**
     * Whether the slot is still to be filled: false where it will hold
     * nobody.
     */
    public function isPending(): bool
    {
        return match ($this) {
            self::AdvancerOf, self::LoserOf, self::Reseeded => true,
            self::Bye, self::Void, self::NotPlayed => false,
        };
    }
}
