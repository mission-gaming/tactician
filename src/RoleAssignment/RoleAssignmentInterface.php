<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\RoleAssignment;

use MissionGaming\Tactician\Constraints\ConstraintInterface;
use MissionGaming\Tactician\DTO\Participant;

/**
 * Strategy for which participant of a round-robin pairing is first-named.
 *
 * The role of a participant in an event is its position: first-named or
 * second-named (home and away in a football league, white and black on a
 * chess board). A role assignment decides the roles of one leg at a time.
 * It never decides who meets whom or in which round: that is the
 * generator's job, and the scheduler rejects an answer that changes it.
 *
 * The scheduler asks once for every leg of a layout: the first leg as the
 * circle method lays it out, and each later leg, which has the layout of
 * the first. A leg strategy then derives the roles of a later leg from the
 * answer for it. It asks once more for a first leg that the backtracking
 * search found. When constraints reject a schedule the scheduler lays the
 * legs out again from a rotated participant order and asks again for each,
 * so one call to `schedule()` can ask about more legs than the schedule
 * has: the answers of a rejected attempt are thrown away.
 *
 * A role assignment and the constraints meet in two ways, and both are in
 * the two methods here:
 *
 * - `assignRoles()` is told, in its context, which leg it is deciding and
 *   which seatings the constraints have fixed. Constraints are not handed
 *   over: the scheduler puts the events to them and reports what it found.
 * - `rule()` states what the assignment holds a leg to, as a question the
 *   scheduler can ask about one event. With a rule, the scheduler looks
 *   for other roles of the first leg when the constraints reject the ones
 *   `assignRoles()` gave, within the rule and nowhere else, so that the
 *   schedule it returns is still one this assignment would have given.
 *   Without one, the roles `assignRoles()` gives the first leg stand, and
 *   a constraint they break fails the schedule (unless
 *   `RoundRobinOptions(backtracking: true)` is set: the search then
 *   chooses the roles of the first leg and returns a leg only when
 *   `assignRoles()` returns it unchanged).
 *
 * @experimental
 */
interface RoleAssignmentInterface
{
    /**
     * Decide the roles of one leg.
     *
     * `$rounds` holds the leg's rounds in order (index 0 is the leg's first
     * round). A round is a list of seatings, and a seating is two seats in
     * the roles the generator proposes: the participant at index 0 is
     * first-named. The generators propose roles that alternate with round
     * parity. In a field of odd size one seat of one seating per round is
     * null: that seating is the round's bye, it produces no event, and it
     * is listed so that an implementation can read the whole layout. The
     * backtracking search lists no bye seatings, and neither does a leg
     * the scheduler asks about again after a search of roles.
     *
     * The return value has the same rounds and the same seatings in the same
     * order. Each seating is either unchanged or reversed.
     *
     * `$context` says which leg of how many the rounds are, and which
     * seatings are fixed (`RoleAssignmentContext::isFixed()`): the
     * constraints rejected each of those the other way round, and the
     * scheduler hands it over as it has to stay. An implementation that
     * promises something about a whole leg returns the fixed seatings as
     * given and decides the others around them. One that decides every
     * seating on its own need not read the context at all. With fixed
     * seatings the rounds are the assignment's own earlier answer for the
     * leg, with the fixed seatings reversed.
     *
     * An implementation is deterministic: a new instance that is asked the
     * same questions in the same order gives the same answers. The built-in
     * ones go further and give the same answer for the same rounds and
     * context every time, which is what makes a mirrored leg the mirror of
     * the first. One that needs randomness takes a seeded
     * `Random\Randomizer` through its own constructor, and with it gives up
     * that second property: its legs are each decided on their own.
     *
     * An implementation that returns a leg unchanged when it is asked about
     * its own answer for that leg (without fixed seatings) can be searched
     * around: that is how the scheduler tells a leg the assignment keeps
     * from one it would change.
     *
     * @param list<list<array{0: Participant|null, 1: Participant|null}>> $rounds
     * @return list<list<array{0: Participant|null, 1: Participant|null}>>
     */
    public function assignRoles(array $rounds, RoleAssignmentContext $context): array;

    /**
     * What this assignment holds the first leg to, as a constraint on one
     * event, or null when it states no such rule.
     *
     * The scheduler puts an event of the first leg to the rule with a
     * context that holds the events of the leg placed before it, and takes
     * `false` to mean: no leg that holds these events and this one is a leg
     * `assignRoles()` would return unchanged. A leg whose every event the
     * rule accepted must be such a leg; the scheduler checks, and does not
     * return one that `assignRoles()` would change. The rule answers from
     * the event and the context alone, as every constraint does, and it is
     * asked about the first leg only: a later leg follows from the first
     * through the leg strategy.
     *
     * Return null to keep the roles `assignRoles()` gives as they are: the
     * scheduler then searches the roles of the first leg only with
     * `RoundRobinOptions(backtracking: true)`.
     */
    public function rule(): ?ConstraintInterface;
}
