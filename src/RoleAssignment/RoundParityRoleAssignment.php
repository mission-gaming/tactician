<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\RoleAssignment;

use MissionGaming\Tactician\Constraints\ConstraintInterface;
use Override;

/**
 * Role assignment that keeps the roles the generator proposes.
 *
 * The generators alternate roles with round parity: the first seat of a
 * pairing is first-named in the odd rounds of a leg and second-named in the
 * even rounds. That bounds how far the two role counts of a participant
 * drift apart while the schedule builds, and it does not make them equal:
 * a single leg ends up to 3 out of balance in a field of even size and up
 * to 4 in a field of odd size.
 *
 * This is what the library did before role assignments existed, and it was
 * the default of `RoundRobinOptions` up to 0.2.x. Name it to keep those
 * roles: `new RoundRobinOptions(roleAssignment: new
 * RoundParityRoleAssignment())`, or `'role_assignment' => 'round_parity'`
 * in plain data.
 *
 * It states no rule, so the roles it gives the first leg are not searched
 * when a constraint rejects them: such a schedule fails, as it did, unless
 * `RoundRobinOptions(backtracking: true)` is set.
 *
 * @experimental
 */
final readonly class RoundParityRoleAssignment implements RoleAssignmentInterface
{
    /**
     * The rounds exactly as given: every seating keeps the roles the
     * generator proposed. The context is not read.
     */
    #[Override]
    public function assignRoles(array $rounds, RoleAssignmentContext $context): array
    {
        return $rounds;
    }

    /**
     * Null: every leg is one this assignment returns unchanged, so there
     * is no rule to search within, and without backtracking its roles
     * stand.
     */
    #[Override]
    public function rule(): ?ConstraintInterface
    {
        return null;
    }
}
