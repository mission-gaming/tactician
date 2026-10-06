<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Constraints;

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\Scheduling\SchedulingContext;

/**
 * A hard rule on a single event: generation keeps a candidate event only
 * when every constraint it runs under is satisfied.
 *
 * What a constraint of your own has to do to behave predictably:
 *
 * - Answer from the event and the context alone. The question is whether
 *   one candidate event may join the events the context holds, and the same
 *   event with the same context should get the same answer every time.
 * - Expect to be asked more often than the schedule has events, and about
 *   events that are never scheduled. A rejected event is put to each
 *   constraint of the set again, to record which one rejected it. When an
 *   ordering fails, the round-robin scheduler starts again from a rotated
 *   participant order. The backtracking search and the Swiss round search
 *   try pairings and undo them. The analysis of a failure asks about every
 *   missing pairing in every round, in both role orders.
 * - Do not depend on how often or in which order the questions come.
 *   Neither is part of the contract, and either can change in any release.
 *
 * A constraint that keeps state between calls, reads a random source or
 * throws is not refused, and what it gets back then depends on how the
 * library happened to ask. No shortcut is taken for it. Two internal shortcuts
 * ask fewer questions: the round-robin scheduler builds no failure analysis
 * for an ordering it goes on from, and the Swiss round search skips
 * branches that hold no complete pairing. Both are taken only for a
 * `ConstraintSet` (not a subclass) that holds nothing but
 * `NoRepeatPairings`, `MinimumRestPeriodsConstraint`,
 * `RoleBalanceConstraint` and `SeedProtectionConstraint` objects (not
 * subclasses), which run no code of the caller's and keep no state. Any
 * other set, one that holds a `CallableConstraint`, a `MetadataConstraint`,
 * a `ConsecutiveRoleConstraint` or a class of your own included, is asked
 * every question it was asked before those shortcuts existed, so what such
 * a constraint sees and what the caller gets back did not change when they
 * were added. This paragraph describes the present implementation; the
 * list above is what a constraint may rely on.
 *
 * The library does not catch what a constraint of your own throws: the
 * exception leaves the `schedule()` or `pairNextRound()` call that asked.
 * The exception to that is a constraint that throws one of the library's
 * own `IncompleteScheduleException` or `NoValidPairingException`: a
 * scheduler takes it for a failure of its own.
 *
 * @experimental
 */
interface ConstraintInterface
{
    /**
     * Whether the candidate event may join the events the context holds.
     *
     * The candidate is not among the context's events. During generation
     * those are the events accepted before it: the earlier rounds, of every
     * leg generated so far in a round robin (`getCurrentLeg()` is the leg
     * of the candidate), of the recorded rounds in a Swiss stage. The other
     * events of the candidate's own round are among them in the Swiss and
     * backtracking searches, which add each pairing as they make it, and
     * not in the circle-method round robin, which adds a round when it is
     * complete. The analysis of a failure asks against everything that was
     * generated, later rounds included. The candidate's round, when it has
     * one, is the 1-based round it would be played in.
     *
     * @return bool True to accept the event, false to reject it
     */
    public function isSatisfied(Event $event, SchedulingContext $context): bool;

    /**
     * The name the constraint is reported under.
     *
     * Recorded violations and the failure analysis are grouped by this
     * name, so two constraints of one set that share a name are reported as
     * one. It should be the same on every call.
     */
    public function getName(): string;
}
