<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Exceptions;

/**
 * Why a decision that orders level entries was rejected, as a value code
 * can branch on ({@see RejectedDecisionException::getReason()}).
 *
 * A decision orders one whole set of entries that results leave level, and
 * nothing else. Each case is one way of not being that. Where the decisions
 * given together fail in more than one way, the first case in the order
 * below is reported, whichever decision breaks it and whatever order they
 * were given in. (A selector that reads several tables holds the decisions
 * to them one table at a time, in the order it states.)
 *
 * The backing strings are stable identifiers for logs and for caller-side
 * dispatch. New cases may be added in any release, so a `match` over the
 * reason needs a `default` arm.
 *
 * @api
 */
enum RejectedDecisionReason: string
{
    /**
     * The decision names a participant that has no entry in the table, or
     * in any table the selection reads.
     */
    case UnknownParticipant = 'unknown_participant';

    /**
     * Two decisions name the same participant: the same set decided twice,
     * or two decisions that overlap. It is also the reason when a decision
     * is given for a set that the table already carries a decision for.
     */
    case SetAlreadyDecided = 'set_already_decided';

    /**
     * The participants the decision names are not level with each other: a
     * result, a tiebreaker or a tier separates them, or the one participant
     * it names is level with nobody. A decision never moves an entry past
     * one it is not level with. A decision made while they were level, and
     * given again after a later result separated them, is rejected for this
     * reason.
     */
    case ParticipantsNotLevel = 'participants_not_level';

    /**
     * The participants the decision names are level with each other and
     * with others it does not name: it orders only part of a set. The
     * exception lists the ones left out.
     */
    case PartOfLevelSet = 'part_of_level_set';

    /**
     * A table that carries a decision was changed (an adjustment, a bye
     * credited) so that the decision no longer orders one whole set of
     * level entries. The table is not returned with a decision that no
     * longer matches it: calculate it again and apply the decisions last.
     */
    case Stale = 'stale';
}
