<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\RoleAssignment;

/**
 * What the scheduler tells a role assignment about the leg it is asked to
 * decide: which leg of how many it is, and which of its seatings the
 * constraints have left one way round only.
 *
 * A seating is fixed when the scheduler put its event to the constraints and
 * they rejected it. The scheduler then reverses that seating, marks it as
 * fixed and asks the role assignment about the leg again, so that the roles
 * of the other seatings can be decided around it. A role assignment that
 * promises something about a whole leg (as `BalancedRoleAssignment` does)
 * keeps the fixed seatings as they are given and changes the others to keep
 * its promise. One that returns a fixed seating reversed, or a leg it would
 * not itself return unchanged when asked without fixed seatings, has the
 * answer set aside: the scheduler then searches for roles within the rule
 * the role assignment states (`RoleAssignmentInterface::rule()`).
 *
 * The object is plain data and is built by the scheduler. A caller builds
 * one to call `assignRoles()` itself: `new RoleAssignmentContext()` describes
 * a single leg with nothing fixed.
 *
 * @experimental
 */
final readonly class RoleAssignmentContext
{
    /** @var array<string, true> The fixed seatings, as "round:position" keys */
    private array $fixed;

    /**
     * Nothing is validated: the context holds what it is given.
     *
     * @param int $leg The leg the rounds belong to, counted from 1
     * @param int $legs How many legs the schedule has
     * @param list<array{0: int, 1: int}> $fixedSeatings The seatings to keep as given, each as the index
     *                                                   of its round in the rounds handed over and its
     *                                                   position in that round, both counted from 0
     */
    public function __construct(
        public int $leg = 1,
        public int $legs = 1,
        array $fixedSeatings = []
    ) {
        $fixed = [];
        foreach ($fixedSeatings as [$round, $position]) {
            $fixed[$round . ':' . $position] = true;
        }

        $this->fixed = $fixed;
    }

    /**
     * Whether the seating at this position of this round is to be kept as
     * given. Both are counted from 0, as the rounds handed to
     * `assignRoles()` are indexed.
     */
    public function isFixed(int $round, int $position): bool
    {
        return isset($this->fixed[$round . ':' . $position]);
    }

    /**
     * Whether any seating of the leg is fixed.
     */
    public function hasFixedSeatings(): bool
    {
        return $this->fixed !== [];
    }
}
