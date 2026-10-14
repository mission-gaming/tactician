<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\RoleAssignment;

use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\InvalidInputException;

/**
 * Why a round robin generated under `BalancedWherePossibleRoleAssignment`
 * (the default of `RoundRobinOptions`) does not have balanced roles.
 *
 * `RoundRobinScheduler` writes the backing string of a case into the
 * metadata of such a schedule, under `RoleFallback::METADATA_KEY`. The key
 * is absent from every other schedule: one whose roles are balanced, and
 * one generated under a role assignment the caller named, which never
 * falls back. The metadata of a schedule is part of its plain data, so the
 * mark survives `Schedule::toArray()` and `fromArray()`; read it with
 * `RoleFallback::of()`.
 *
 * A schedule that carries the mark is complete and satisfies every
 * constraint. Its pairings, rounds and roles are those
 * `RoundParityRoleAssignment` gives for the same participants, options,
 * constraints and randomizer.
 *
 * The mark says that the scheduler fell back, not what the roles turned
 * out to be. With `RoundRobinOptions(backtracking: true)` the roles of a
 * fall-back are those a backtracking search chose, and they can happen to
 * be balanced: such a schedule carries the mark all the same, because it is
 * not the one the search for balanced roles would have returned.
 *
 * The cases say what became of the search for balanced roles, and with it
 * what naming `BalancedRoleAssignment` would do for the same input. More
 * cases may be added in a later release, so a `match` over a case needs a
 * `default` arm.
 *
 * @experimental
 */
enum RoleFallback: string
{
    /**
     * The metadata key of a schedule that the mark is written under.
     */
    public const string METADATA_KEY = 'role_fallback';

    /**
     * Every choice of balanced roles the scheduler searches was tried, and
     * none completes the schedule: in each layout of the circle method
     * that was tried, and with `RoundRobinOptions(backtracking: true)` in
     * every way to play the first leg. `BalancedRoleAssignment` named
     * fails for the same input.
     */
    case NoBalancedRoles = 'no_balanced_roles';

    /**
     * The search for balanced roles stopped at one of its bounds (the
     * rejected roles or the events put to the constraints of a role
     * search, or the step budget of a backtracking search) before it had
     * tried every choice. Balanced roles the constraints accept may exist
     * beyond it. `BalancedRoleAssignment` named stops at the same bound
     * and fails.
     */
    case SearchStoppedAtBound = 'search_stopped_at_bound';

    /**
     * The constraints rejected the balanced roles the scheduler put to
     * them, and with a schedule of the generator's roles already in hand
     * it did not search the others through. That happens only under
     * constraints the search of roles cannot reason about exactly: a
     * closure, a metadata rule, a class of the caller's, a subclass of one
     * of the library's, or any of them in a set with the library's own.
     *
     * With that schedule in hand the search for balanced roles is the one
     * `BalancedRoleAssignment` has, held to 4,000 events put to the
     * constraints, a backtracking search included: the balanced roles as
     * given in the rotated participant orders, in each of those layouts
     * the balanced roles around every seating the constraints fixed, and
     * the search that goes back one event at a time, which is what a
     * constraint of unknown reach needs. This case says that the 4,000
     * events were spent before the search had an answer, or, with
     * `RoundRobinOptions(backtracking: true)`, that no backtracking search
     * was made for balanced roles: one is made only where the schedule in
     * hand came from a backtracking search itself.
     *
     * So this case does not say that no balanced roles exist.
     * `BalancedRoleAssignment` named searches to its full bounds, which
     * can take seconds, and returns a balanced schedule or fails.
     */
    case BalancedRolesRejected = 'balanced_roles_rejected';

    /**
     * The mark of a schedule, or null when it carries none: its roles are
     * balanced, or it was not generated under
     * `BalancedWherePossibleRoleAssignment`.
     *
     * @throws InvalidInputException When the metadata holds a value under the key that is not the
     *                               backing string of a case, as a schedule stored by a later
     *                               release may
     */
    public static function of(Schedule $schedule): ?self
    {
        if (!$schedule->hasMetadata(self::METADATA_KEY)) {
            return null;
        }

        $value = $schedule->getMetadataValue(self::METADATA_KEY);
        $reason = is_string($value) ? self::tryFrom($value) : null;

        if ($reason === null) {
            throw new InvalidInputException(sprintf(
                "The schedule's '%s' metadata must be one of: %s",
                self::METADATA_KEY,
                implode(', ', array_map(static fn(self $case): string => $case->value, self::cases()))
            ));
        }

        return $reason;
    }
}
