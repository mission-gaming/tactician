<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

/**
 * The part of a bracket a round belongs to (see Bracket).
 *
 * A single elimination bracket has one side, Main. A double elimination
 * bracket has three: the winners bracket, the losers bracket, and the
 * grand final with its reset. The backing strings are the first part of
 * the ID of every node of the side (BracketNode::getId()).
 *
 * New cases may be added in a later release, so a `match` over a case
 * needs a `default` arm.
 *
 * @experimental
 */
enum BracketSide: string
{
    /** The one side of a single elimination bracket. */
    case Main = 'main';

    /** The side of a double elimination bracket that every entrant starts on. */
    case Winners = 'winners';

    /** The side of a double elimination bracket that an entrant drops to after its first loss. */
    case Losers = 'losers';

    /** The grand final of a double elimination bracket (round 1) and its reset (round 2). */
    case GrandFinal = 'grand-final';
}
