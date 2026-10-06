<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\RoleAssignment;

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
 * This is the default of `RoundRobinOptions`, and it is what the library
 * did before role assignments existed.
 *
 * @experimental
 */
final readonly class RoundParityRoleAssignment implements RoleAssignmentInterface
{
    /**
     * The rounds exactly as given: every seating keeps the roles the
     * generator proposed.
     */
    #[Override]
    public function assignRoles(array $rounds): array
    {
        return $rounds;
    }
}
