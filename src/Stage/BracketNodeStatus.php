<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

/**
 * Where a node of a bracket stands (see BracketNode): waiting for its
 * entrants, to be paired, in play, or settled, and how it was settled.
 *
 * The backing strings are stable identifiers. New cases may be added in a
 * later release, so a `match` over a case needs a `default` arm.
 *
 * @experimental
 */
enum BracketNodeStatus: string
{
    /** A slot of the node still waits for the tie it is filled from (see SlotPlaceholder). */
    case AwaitingEntrants = 'awaiting-entrants';

    /** Both entrants are known and the node is not recorded yet: it is paired when its round is. */
    case ToBePaired = 'to-be-paired';

    /** The node is recorded and an event of it awaits a result. */
    case InPlay = 'in-play';

    /** Every result of the node is recorded, the tie is level, and it awaits its decider (see TieDecision). */
    case AwaitingDecision = 'awaiting-decision';

    /** The results decided who went on. */
    case Decided = 'decided';

    /**
     * An entrant withdrew and the other went on without the tie being
     * played to its end: the uncontested tie of the glossary.
     */
    case Uncontested = 'uncontested';

    /** One entrant stood alone in the node and went on: the other slot holds nobody. */
    case Bye = 'bye';

    /**
     * Nobody went on: both entrants withdrew before either went through
     * (a void tie, ADR 0011), or the one entrant who stood alone had
     * withdrawn.
     */
    case Void = 'void';

    /** Neither slot holds anybody, so nobody goes on. */
    case Empty = 'empty';

    /**
     * The bracket does not play the node: a grand final reset that is not
     * due, or a third-place tie that a last round drawn by hand left out.
     */
    case NotPlayed = 'not-played';

    /**
     * Whether nothing more is to happen in the node: who goes on from it,
     * if anybody, is known.
     */
    public function isSettled(): bool
    {
        return match ($this) {
            self::AwaitingEntrants, self::ToBePaired, self::InPlay, self::AwaitingDecision => false,
            self::Decided, self::Uncontested, self::Bye, self::Void, self::Empty, self::NotPlayed => true,
        };
    }
}
