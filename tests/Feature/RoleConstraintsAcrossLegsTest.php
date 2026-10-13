<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\ConsecutiveRoleConstraint;
use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Constraints\RoleBalanceConstraint;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\LegStrategies\LegStrategyInterface;
use MissionGaming\Tactician\LegStrategies\MirroredLegStrategy;
use MissionGaming\Tactician\LegStrategies\RepeatedLegStrategy;
use MissionGaming\Tactician\LegStrategies\ShuffledLegStrategy;
use MissionGaming\Tactician\RoleAssignment\BalancedRoleAssignment;
use MissionGaming\Tactician\RoleAssignment\RoleAssignmentInterface;
use MissionGaming\Tactician\RoleAssignment\RoundParityRoleAssignment;
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Tests\Support\RoleCounts;
use MissionGaming\Tactician\Tests\Support\RoleScheduleOracle;
use MissionGaming\Tactician\Tests\Support\RoundRobinAudit;

// Role constraints over several legs: the scheduler finds a schedule
// whenever one exists that keeps the constraints, the contract of the leg
// strategy and the roles the role assignment decided, and says so when a
// bound stopped it from knowing.
//
// "A schedule exists" is never taken from the scheduler. It comes from
// Tests\Support\RoleScheduleOracle, which tries every choice of roles (and,
// for the backtracking search, every way to split a leg into rounds) with
// rules written as plain closures. The fields are small because the oracle
// is exhaustive; they are odd and even, because a bye changes which roles a
// streak allows.

/**
 * @return list<Participant>
 */
function roleSweepField(int $size): array
{
    $field = [];
    for ($number = 1; $number <= $size; ++$number) {
        $field[] = new Participant("p{$number}", "P{$number}");
    }

    return $field;
}

/**
 * The rules of the sweep: the constraint set the scheduler is given, and
 * the same rule as the oracle reads it.
 *
 * @return array<string, array{ConstraintSet, Closure(list<array{int, string, string}>): bool}>
 */
function roleSweepRules(int $roundsPerLeg, int $legs): array
{
    // "p1 is first-named whenever it meets p2 after the first leg": a rule
    // about one pairing, written as a closure, so that the scheduler cannot
    // know which events its verdict depends on
    $laterRounds = range($roundsPerLeg + 1, $roundsPerLeg * $legs);
    $pinned = static function (Event $event) use ($laterRounds): bool {
        $pair = $event->getParticipants();

        return !($pair[0]->getId() === 'p2' && $pair[1]->getId() === 'p1'
            && in_array($event->getRound()?->getNumber(), $laterRounds, true));
    };

    return [
        'streak 1' => [
            ConstraintSet::create()->add(ConsecutiveRoleConstraint::homeAway(1))->build(),
            RoleScheduleOracle::streakRule(1),
        ],
        'streak 2' => [
            ConstraintSet::create()->add(ConsecutiveRoleConstraint::homeAway(2))->build(),
            RoleScheduleOracle::streakRule(2),
        ],
        'streak 3' => [
            ConstraintSet::create()->add(ConsecutiveRoleConstraint::position(3))->build(),
            RoleScheduleOracle::streakRule(3),
        ],
        'balance 1' => [
            ConstraintSet::create()->add(RoleBalanceConstraint::homeAway(1))->build(),
            RoleScheduleOracle::balanceRule(1),
        ],
        'balance 2' => [
            ConstraintSet::create()->add(RoleBalanceConstraint::homeAway(2))->build(),
            RoleScheduleOracle::balanceRule(2),
        ],
        'balance 3' => [
            ConstraintSet::create()->add(RoleBalanceConstraint::homeAway(3))->build(),
            RoleScheduleOracle::balanceRule(3),
        ],
        'streak 2 and balance 2' => [
            ConstraintSet::create()
                ->add(ConsecutiveRoleConstraint::homeAway(2))
                ->add(RoleBalanceConstraint::homeAway(2))
                ->build(),
            RoleScheduleOracle::every(RoleScheduleOracle::streakRule(2), RoleScheduleOracle::balanceRule(2)),
        ],
        'p1 first-named against p2 after leg 1' => [
            ConstraintSet::create()->custom($pinned, 'Pinned role')->build(),
            RoleScheduleOracle::fixedRoleRule('p1', 'p2', $laterRounds),
        ],
        'streak 2 and p1 first-named against p2 after leg 1' => [
            ConstraintSet::create()
                ->add(ConsecutiveRoleConstraint::homeAway(2))
                ->custom($pinned, 'Pinned role')
                ->build(),
            RoleScheduleOracle::every(
                RoleScheduleOracle::streakRule(2),
                RoleScheduleOracle::fixedRoleRule('p1', 'p2', $laterRounds)
            ),
        ],
    ];
}

/**
 * @return array<string, array{'mirrored'|'repeated'|'shuffled', Closure(): LegStrategyInterface}>
 */
function roleSweepStrategies(): array
{
    return [
        'mirrored' => ['mirrored', static fn(): LegStrategyInterface => new MirroredLegStrategy()],
        'repeated' => ['repeated', static fn(): LegStrategyInterface => new RepeatedLegStrategy()],
        'shuffled, seed 1' => ['shuffled', static fn(): LegStrategyInterface => new ShuffledLegStrategy(seed: 1)],
        'shuffled, seed 2' => ['shuffled', static fn(): LegStrategyInterface => new ShuffledLegStrategy(seed: 2)],
    ];
}

/**
 * @return array<string, RoleAssignmentInterface>
 */
function roleSweepRoleAssignments(): array
{
    return ['round parity' => new RoundParityRoleAssignment(), 'balanced' => new BalancedRoleAssignment()];
}

/**
 * What is wrong with a schedule the scheduler returned: it is not a
 * complete round robin, it breaks the rule, or a later leg is not what the
 * leg strategy says it is. Read from the events alone.
 *
 * @param list<Participant> $field
 * @param 'mirrored'|'repeated'|'shuffled' $strategy
 * @param Closure(list<array{int, string, string}>): bool $breaks
 * @return list<string>
 */
function roleSweepFaults(Schedule $schedule, array $field, int $legs, string $strategy, Closure $breaks): array
{
    $faults = RoundRobinAudit::faults($schedule, $field, $legs);
    $events = RoleScheduleOracle::read($schedule->getEvents());

    if ($breaks($events)) {
        $faults[] = 'the schedule breaks the rule';
    }

    $roundsPerLeg = count($field) % 2 === 0 ? count($field) - 1 : count($field);
    $byLeg = [];
    foreach ($events as [$round, $first, $second]) {
        $leg = intdiv($round - 1, $roundsPerLeg) + 1;
        $pairing = strcmp($first, $second) < 0 ? "{$first} + {$second}" : "{$second} + {$first}";
        $byLeg[$leg][($round - ($leg - 1) * $roundsPerLeg) . ': ' . $pairing] = $first;
    }

    for ($leg = 2; $leg <= $legs; ++$leg) {
        if (array_keys($byLeg[$leg] ?? []) !== array_keys($byLeg[1] ?? [])) {
            $faults[] = "leg {$leg} does not have the pairings of leg 1 in the same rounds";

            continue;
        }

        foreach ($byLeg[$leg] as $meeting => $firstNamed) {
            $sameRoles = $firstNamed === $byLeg[1][$meeting];
            // A mirrored leg reverses the first in the even-numbered legs
            // and has its roles in the odd-numbered ones
            $mirroredWrong = $sameRoles === ($leg % 2 === 0);
            if (($strategy === 'mirrored' && $mirroredWrong) || ($strategy === 'repeated' && !$sameRoles)) {
                $faults[] = "leg {$leg}, {$meeting}: not the roles a {$strategy} leg has";
            }
        }
    }

    return $faults;
}

describe('roles over several legs, without backtracking', function (): void {
    // The roles of the first leg are the role assignment's and are not
    // searched. A schedule exists when, for one of the participant orders
    // the scheduler tries, that first leg keeps the rule and the later legs
    // have roles that keep it: the one set of roles a mirrored or repeated
    // leg has, or any roles for a shuffled leg.
    it('finds a schedule exactly when one exists', function (int $size): void {
        $field = roleSweepField($size);
        $roundsPerLeg = $size % 2 === 0 ? $size - 1 : $size;
        $wrong = [];
        $found = 0;
        $refused = 0;

        foreach (roleSweepRoleAssignments() as $roleName => $roleAssignment) {
            // The first leg of every participant order the scheduler tries
            $firstLegs = [];
            for ($rotation = 0; $rotation < $size; ++$rotation) {
                $rotated = [...array_slice($field, $rotation), ...array_slice($field, 0, $rotation)];
                $firstLegs[] = RoleScheduleOracle::read(
                    (new RoundRobinScheduler())->schedule($rotated, new RoundRobinOptions(roleAssignment: $roleAssignment))
                );
            }

            for ($legs = 2; $legs <= 4; ++$legs) {
                foreach (roleSweepRules($roundsPerLeg, $legs) as $ruleName => [$constraints, $breaks]) {
                    foreach (roleSweepStrategies() as $strategyName => [$strategy, $makeStrategy]) {
                        $case = "{$size} participants, {$legs} legs, {$strategyName}, {$roleName}, {$ruleName}";

                        $exists = false;
                        foreach ($firstLegs as $firstLeg) {
                            if (RoleScheduleOracle::rolesExist($firstLeg, $roundsPerLeg, $legs, $strategy, false, $breaks)) {
                                $exists = true;
                                break;
                            }
                        }

                        $options = new RoundRobinOptions($legs, $makeStrategy(), false, $roleAssignment);
                        try {
                            $schedule = (new RoundRobinScheduler($constraints))->schedule($field, $options);
                        } catch (IncompleteScheduleException $failure) {
                            ++$refused;
                            if ($exists) {
                                $wrong[] = "{$case}: a schedule exists, and the scheduler said: {$failure->getMessage()}";
                            }

                            continue;
                        }

                        ++$found;
                        if (!$exists) {
                            $wrong[] = "{$case}: no schedule exists, and the scheduler returned one";
                        }
                        foreach (roleSweepFaults($schedule, $field, $legs, $strategy, $breaks) as $fault) {
                            $wrong[] = "{$case}: {$fault}";
                        }
                    }
                }
            }
        }

        expect($wrong)->toBe([])
            // The sweep means nothing unless it holds both answers
            ->and($found)->toBeGreaterThan(0)
            ->and($refused)->toBeGreaterThan(0);
    })->with([3, 4, 5, 6, 7]);
});

describe('roles over several legs, with backtracking', function (): void {
    // The search chooses the first leg: how its pairings fall into rounds,
    // and its roles. A schedule exists when some leg, with some roles, has
    // later legs under the leg strategy that keep the rule. With the
    // round-parity role assignment, which keeps the roles it is handed,
    // that is everything the scheduler may return.
    //
    // Four and five participants run here. Six hold as well, with no case
    // stopped by a bound, and take more than five minutes (a field of six
    // has 720 ways to play a leg, and both sides go through them for every
    // case that has no schedule); add 6 to the list to run them.
    it('finds a schedule exactly when one exists, or says that a bound stopped it', function (int $size): void {
        $field = roleSweepField($size);
        $ids = array_map(static fn(Participant $participant): string => $participant->getId(), $field);
        $roundsPerLeg = $size % 2 === 0 ? $size - 1 : $size;
        $wrong = [];
        $found = 0;
        $proven = 0;
        $stopped = [];

        for ($legs = 2; $legs <= 4; ++$legs) {
            foreach (roleSweepRules($roundsPerLeg, $legs) as $ruleName => [$constraints, $breaks]) {
                foreach (roleSweepStrategies() as $strategyName => [$strategy, $makeStrategy]) {
                    $case = "{$size} participants, {$legs} legs, {$strategyName}, {$ruleName}";

                    $exists = false;
                    foreach (RoleScheduleOracle::everyLeg($ids) as $leg) {
                        if (RoleScheduleOracle::rolesExist($leg, $roundsPerLeg, $legs, $strategy, true, $breaks)) {
                            $exists = true;
                            break;
                        }
                    }

                    $options = new RoundRobinOptions($legs, $makeStrategy(), true);
                    try {
                        $schedule = (new RoundRobinScheduler($constraints))->schedule($field, $options);
                    } catch (IncompleteScheduleException $failure) {
                        $message = $failure->getMessage();
                        if (str_contains($message, 'unsatisfiable')) {
                            ++$proven;
                            if ($exists) {
                                $wrong[] = "{$case}: a schedule exists, and the scheduler said: {$message}";
                            }
                        } elseif (str_contains($message, 'budget') || str_contains($message, 'bound')) {
                            $stopped[] = $case;
                        } else {
                            $wrong[] = "{$case}: a failure that neither proves nor names a bound: {$message}";
                        }

                        continue;
                    }

                    ++$found;
                    if (!$exists) {
                        $wrong[] = "{$case}: no schedule exists, and the scheduler returned one";
                    }
                    foreach (roleSweepFaults($schedule, $field, $legs, $strategy, $breaks) as $fault) {
                        $wrong[] = "{$case}: {$fault}";
                    }
                }
            }
        }

        expect($wrong)->toBe([])
            // Fields this small are searched in full: no case is left
            // undecided by a bound, so the two answers above are all there are
            ->and($stopped)->toBe([])
            ->and($found)->toBeGreaterThan(0)
            ->and($proven)->toBeGreaterThan(0);
    })->with([4, 5]);

    // The balanced role assignment changes roles the search chose, so a
    // schedule of the oracle may be one it does not keep, and "exists" is
    // not the whole question (issue #51). What holds: a schedule that is
    // returned is right and its first leg is balanced, and "unsatisfiable"
    // is said only when no schedule exists under any roles.
    it('returns only balanced schedules that keep the rule under the balanced role assignment', function (int $size): void {
        $field = roleSweepField($size);
        $ids = array_map(static fn(Participant $participant): string => $participant->getId(), $field);
        $roundsPerLeg = $size % 2 === 0 ? $size - 1 : $size;
        $wrong = [];
        $found = 0;

        for ($legs = 2; $legs <= 3; ++$legs) {
            foreach (roleSweepRules($roundsPerLeg, $legs) as $ruleName => [$constraints, $breaks]) {
                foreach (roleSweepStrategies() as $strategyName => [$strategy, $makeStrategy]) {
                    $case = "{$size} participants, {$legs} legs, {$strategyName}, {$ruleName}";
                    $options = new RoundRobinOptions($legs, $makeStrategy(), true, new BalancedRoleAssignment());

                    try {
                        $schedule = (new RoundRobinScheduler($constraints))->schedule($field, $options);
                    } catch (IncompleteScheduleException $failure) {
                        if (!str_contains($failure->getMessage(), 'unsatisfiable')) {
                            continue;
                        }

                        foreach (RoleScheduleOracle::everyLeg($ids) as $leg) {
                            if (RoleScheduleOracle::rolesExist($leg, $roundsPerLeg, $legs, $strategy, true, $breaks)) {
                                $wrong[] = "{$case}: a schedule exists, and the scheduler said: {$failure->getMessage()}";
                                break;
                            }
                        }

                        continue;
                    }

                    ++$found;
                    foreach (roleSweepFaults($schedule, $field, $legs, $strategy, $breaks) as $fault) {
                        $wrong[] = "{$case}: {$fault}";
                    }
                    $legOne = RoleCounts::leg($schedule->getEvents(), 1, $roundsPerLeg);
                    if (RoleCounts::worstEndImbalance($legOne) > ($size % 2 === 0 ? 1 : 0)) {
                        $wrong[] = "{$case}: the first leg is not balanced";
                    }
                }
            }
        }

        expect($wrong)->toBe([])->and($found)->toBeGreaterThan(0);
    })->with([4, 5]);

    // A schedule that only the search completes is still a function of the
    // seed: the draws are the roles the search tries first.
    it('gives the same schedule for the same seed when only the search completes it', function (int $size): void {
        $field = roleSweepField($size);
        $roundsPerLeg = $size % 2 === 0 ? $size - 1 : $size;
        $searched = 0;

        for ($legs = 2; $legs <= 3; ++$legs) {
            foreach (roleSweepRules($roundsPerLeg, $legs) as $ruleName => [$constraints]) {
                foreach ([1, 2, 3] as $seed) {
                    $schedules = [];
                    foreach ([false, true, true] as $backtracking) {
                        try {
                            $schedules[] = (new RoundRobinScheduler($constraints))
                                ->schedule($field, new RoundRobinOptions($legs, new ShuffledLegStrategy(seed: $seed), $backtracking))
                                ->toJson();
                        } catch (IncompleteScheduleException) {
                            $schedules[] = null;
                        }
                    }

                    expect($schedules[2])->toBe($schedules[1], "{$size} participants, {$legs} legs, seed {$seed}, {$ruleName}");
                    if ($schedules[0] === null && $schedules[1] !== null) {
                        ++$searched;
                    } elseif ($schedules[0] !== null) {
                        // Greedy runs first: backtracking changes nothing
                        // where it was not needed
                        expect($schedules[1])->toBe($schedules[0]);
                    }
                }
            }
        }

        expect($searched)->toBeGreaterThan(0);
    })->with([4, 5]);
});

describe('roles over several legs of a larger field', function (): void {
    // Beyond the reach of the oracle: the schedules are checked, and the
    // counting is done on the events. With eight participants the first
    // leg the generator finds has roles that repeat. Ten and thirteen are
    // fields in which it does not reach such a leg within its budget, and
    // the search of the roles of the layout the rotations started from
    // does; thirteen have a bye in every round, which the schedule must
    // still record. With twenty the generator finds no first leg at all
    // within its budget, and the roles of that layout are searched after.
    it('repeats a first leg whose roles the search chose', function (int $size, bool $keepsTheCircleLayout): void {
        $field = roleSweepField($size);
        $constraints = ConstraintSet::create()->add(ConsecutiveRoleConstraint::homeAway(2))->build();
        $options = static fn(bool $backtracking): RoundRobinOptions => new RoundRobinOptions(2, new RepeatedLegStrategy(), $backtracking);

        // The default roles, played twice, break the limit, and the
        // scheduler says so: without backtracking it does not search the
        // roles of the first leg
        $twice = (new RoundRobinScheduler())->schedule($field, $options(false));
        expect(RoleCounts::worstStreak($twice))->toBeGreaterThan(2)
            ->and(fn() => (new RoundRobinScheduler($constraints))->schedule($field, $options(false)))
            ->toThrow(IncompleteScheduleException::class);

        $schedule = (new RoundRobinScheduler($constraints))->schedule($field, $options(true));

        expect(roleSweepFaults($schedule, $field, 2, 'repeated', RoleScheduleOracle::streakRule(2)))->toBe([])
            // The pairings and their rounds are those of the circle method
            // exactly where only roles were searched
            ->and(RoleCounts::structure($schedule) === RoleCounts::structure($twice))->toBe($keepsTheCircleLayout);
    })->with([[8, false], [10, true], [13, true], [20, true]]);

    it('shuffles the later legs of a league within a limit of two in a row', function (int $size, int $legs): void {
        $field = roleSweepField($size);
        $constraints = ConstraintSet::create()->add(ConsecutiveRoleConstraint::homeAway(2))->build();
        $unconstrained = (new RoundRobinScheduler())->schedule($field, new RoundRobinOptions($legs, new ShuffledLegStrategy(seed: 7)));

        $schedule = (new RoundRobinScheduler($constraints))->schedule($field, new RoundRobinOptions($legs, new ShuffledLegStrategy(seed: 7)));

        expect(roleSweepFaults($schedule, $field, $legs, 'shuffled', RoleScheduleOracle::streakRule(2)))->toBe([])
            ->and(RoleCounts::structure($schedule))->toBe(RoleCounts::structure($unconstrained))
            ->and(RoleCounts::worstStreak($unconstrained))->toBeGreaterThan(2);
    })->with([[10, 2], [12, 4], [16, 3], [20, 2], [24, 4]]);
});
