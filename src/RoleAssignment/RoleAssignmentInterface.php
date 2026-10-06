<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\RoleAssignment;

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
 * The scheduler asks once for every leg that the circle method lays out,
 * before it checks any constraint, and once for a first leg that the
 * backtracking search found. A leg strategy then derives the roles of the
 * legs after the first from the answer. When constraints reject a schedule
 * the scheduler lays the legs out again from a rotated participant order
 * and asks again for each, so one call to `schedule()` can ask about more
 * legs than the schedule has: the answers of a rejected attempt are thrown
 * away. An implementation gets the rounds and nothing else. It is not told
 * which leg it is deciding or what the constraints are.
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
     * backtracking search lists no bye seatings.
     *
     * The return value has the same rounds and the same seatings in the same
     * order. Each seating is either unchanged or reversed.
     *
     * An implementation is deterministic: a new instance that is asked the
     * same questions in the same order gives the same answers. The built-in
     * ones go further and give the same answer for the same rounds every
     * time, which is what makes a mirrored leg the mirror of the first. One
     * that needs randomness takes a seeded `Random\Randomizer` through its
     * own constructor, and with it gives up that second property: its legs
     * are each decided on their own.
     *
     * @param list<list<array{0: Participant|null, 1: Participant|null}>> $rounds
     * @return list<list<array{0: Participant|null, 1: Participant|null}>>
     */
    public function assignRoles(array $rounds): array;
}
