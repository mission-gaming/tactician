<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\RoleAssignment;

use MissionGaming\Tactician\Constraints\ConstraintInterface;
use Override;

/**
 * Role assignment that balances every leg where a schedule with balanced
 * roles is found, and otherwise lets the roles the generator proposes
 * stand.
 *
 * Asked about a leg, it answers exactly as `BalancedRoleAssignment` does,
 * and it states the same rule. Without constraints, and wherever the
 * constraints accept balanced roles that the scheduler finds, the schedule
 * is byte for byte the one `BalancedRoleAssignment` gives. The two differ
 * in what happens when no such schedule is found:
 * `BalancedRoleAssignment` is balanced or refused, and this one falls back.
 * `RoundRobinScheduler` then generates the schedule again with the roles
 * the generator proposes, as `RoundParityRoleAssignment` would, and marks
 * it: the schedule's metadata says that the roles are not the balanced
 * ones, and why (`RoleFallback`). So a configuration fails under this
 * role assignment only when it fails under `RoundParityRoleAssignment`
 * too, with the failure that one reports.
 *
 * The fall-back is the scheduler's, not something `assignRoles()` does:
 * the scheduler knows this class and no other way to ask for it. A role
 * assignment of the caller's that wraps this one is held to the rule it
 * states, without a fall-back.
 *
 * This is the default of `RoundRobinOptions`, and `'balanced_where_possible'`
 * in plain data.
 *
 * @experimental
 */
final readonly class BalancedWherePossibleRoleAssignment implements RoleAssignmentInterface
{
    private BalancedRoleAssignment $balanced;

    public function __construct()
    {
        $this->balanced = new BalancedRoleAssignment();
    }

    /**
     * The leg as `BalancedRoleAssignment::assignRoles()` returns it for
     * the same rounds and context.
     */
    #[Override]
    public function assignRoles(array $rounds, RoleAssignmentContext $context): array
    {
        return $this->balanced->assignRoles($rounds, $context);
    }

    /**
     * The rule of a balanced leg, as `BalancedRoleAssignment::rule()`
     * states it: the scheduler looks for other roles within it before it
     * falls back.
     */
    #[Override]
    public function rule(): ConstraintInterface
    {
        return $this->balanced->rule();
    }
}
