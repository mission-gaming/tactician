<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Constraints\MinimumRestPeriodsConstraint;
use MissionGaming\Tactician\Constraints\NoRepeatPairings;
use MissionGaming\Tactician\Constraints\RoleBalanceConstraint;
use MissionGaming\Tactician\Constraints\SeedProtectionConstraint;

/**
 * Says whether a constraint set may be asked a question fewer times, more
 * times, or in another order than it used to be, with nobody able to tell.
 *
 * Nothing states that a constraint is a predicate. A constraint of the
 * caller's may count its calls, read a randomizer, or throw for an event it
 * cannot judge, and then how often and in which order it is asked decides
 * what the caller gets back. Two shortcuts ask differently: the round-robin
 * retry loop builds no failure analysis for an ordering it goes on from, and
 * the Swiss round search skips branches that hold no pairing. Both are taken
 * only for a set this class knows; for any other set the constraints are
 * asked exactly as they were before those shortcuts existed.
 *
 * A set is known when it is a ConstraintSet itself (a subclass may override
 * isSatisfied(), getConstraints() or count()) and holds only objects of
 * exactly the classes in KNOWN (a subclass may override isSatisfied()). Each
 * of those classes:
 *
 * - runs no code of the caller's. That is what keeps out
 *   ConsecutiveRoleConstraint, MetadataConstraint and CallableConstraint,
 *   which hold a callable they were given, and every class this library did
 *   not write;
 * - keeps no state, so the same event and context give the same verdict
 *   however often they are asked;
 * - reads, besides the candidate event, the plan and the participant list,
 *   only context events that one of the candidate's own participants takes
 *   part in (getEventsForParticipant(), getEventsBetween()). The verdict on
 *   a pair is therefore the same whatever events of other participants the
 *   context holds, which is what the Swiss round search relies on:
 *   the round's other pairings involve neither participant of the pair.
 *   `tests/Unit/Scheduling/SwissRoundSearchTest.php` checks this for each
 *   class.
 *
 * Adding a class to KNOWN is a claim that all three hold for it.
 *
 * @internal Not public API.
 */
final class ConstraintPurity
{
    /** @var list<class-string> */
    public const array KNOWN = [
        NoRepeatPairings::class,
        MinimumRestPeriodsConstraint::class,
        RoleBalanceConstraint::class,
        SeedProtectionConstraint::class,
    ];

    /**
     * Whether the set is known in the sense of the class comment. No set at
     * all is known: there is nothing to ask.
     */
    public static function isKnown(?ConstraintSet $constraints): bool
    {
        if ($constraints === null) {
            return true;
        }

        if ($constraints::class !== ConstraintSet::class) {
            return false;
        }

        foreach ($constraints->getConstraints() as $constraint) {
            if (!in_array($constraint::class, self::KNOWN, true)) {
                return false;
            }
        }

        return true;
    }
}
