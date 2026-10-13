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
 * Whether this run collects line coverage: it was started with one of the
 * `--coverage` options, as `composer test-coverage` starts it.
 *
 * The two sweeps against the oracle that run every strategy (without
 * backtracking, and with it under the round-parity role assignment) do not
 * run then. Under Xdebug's coverage driver the scheduler takes about twenty
 * times what it takes without one, and the two took 67 s and 50 s of the
 * 236 s of this file, where they take 3.3 s and 2.8 s in a plain run. They
 * were compared, line by line, with a coverage run of the whole suite
 * without this file: they reach no line of `src/` that the tests which
 * still run under the driver do not reach (the balanced sweep and the field
 * of thirteen reach the 17 lines of RoundRobinScheduler that nothing else
 * does). Every job without a coverage driver runs them, which is every job
 * of the gate but the one that measures coverage.
 */
function roleSweepIsUnderCoverage(): bool
{
    $arguments = $_SERVER['argv'] ?? [];
    if (!is_array($arguments)) {
        return false;
    }

    foreach ($arguments as $argument) {
        if (is_string($argument) && str_starts_with($argument, '--coverage')) {
            return true;
        }
    }

    return false;
}

const ROLE_SWEEP_NOT_UNDER_COVERAGE = 'Not run under a coverage driver: it covers no line of its own there and takes about a minute. roleSweepIsUnderCoverage() has the measurements.';

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
        // "p2 is first-named whenever it meets p1", in every leg: the rule
        // of issue #51 that the balanced roles break in the first leg
        // already, although other balanced roles keep it
        'p2 first-named against p1 in every leg' => [
            ConstraintSet::create()->custom(
                static function (Event $event): bool {
                    $pair = $event->getParticipants();

                    return !($pair[0]->getId() === 'p1' && $pair[1]->getId() === 'p2');
                },
                'Pinned role'
            )->build(),
            RoleScheduleOracle::pinnedRule('p2', 'p1'),
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
 * Whether mirrored legs cannot keep the pinned role of the sweep, whatever
 * the first leg and its roles: the second leg has the meeting of p1 and p2
 * in the roles of the first leg reversed and the third has it in the roles
 * of the first leg, so p1 is first-named in one of the two only, and the
 * rule asks for both. The role pinned in every leg is broken by two
 * mirrored legs already: the second has the meeting reversed.
 *
 * @param 'mirrored'|'repeated'|'shuffled' $strategy
 */
function roleSweepMirroredLegsBreakThePin(string $ruleName, string $strategy, int $legs): bool
{
    if ($strategy !== 'mirrored') {
        return false;
    }

    return ($legs >= 3 && str_contains($ruleName, 'p1 first-named against p2'))
        || ($legs >= 2 && str_contains($ruleName, 'in every leg'));
}

/**
 * Whether some way to play the first leg, with some roles, has later legs
 * under the leg strategy that keep the rule: what a backtracking search may
 * return.
 *
 * The oracle answers, by going through every leg and every choice of roles.
 * One answer is not asked of it in a field of more than four: where
 * roleSweepMirroredLegsBreakThePin() says that no schedule exists. The
 * oracle only finds that out for each of the 2^10 choices of roles of each
 * of the 720 ways five participants play a leg, one at a time, since the
 * pinned role is broken in the later legs only. It was run for those cases
 * as well and agreed: 14.2 s and 2.8 s for the two rules at three legs,
 * 13.2 s and 2.9 s at four, which was 33 of the 38 seconds the sweep of
 * five then took. With four participants it is still asked, and the sweep
 * holds its answer to the argument.
 *
 * @param list<string> $ids
 * @param 'mirrored'|'repeated'|'shuffled' $strategy
 * @param Closure(list<array{int, string, string}>): bool $breaks
 */
function roleSweepSomeLegHasRoles(array $ids, int $roundsPerLeg, int $legs, string $strategy, string $ruleName, Closure $breaks): bool
{
    if (count($ids) > 4 && roleSweepMirroredLegsBreakThePin($ruleName, $strategy, $legs)) {
        return false;
    }

    foreach (RoleScheduleOracle::everyLeg($ids) as $leg) {
        if (RoleScheduleOracle::rolesExist($leg, $roundsPerLeg, $legs, $strategy, true, $breaks)) {
            return true;
        }
    }

    return false;
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
    // The pairings and their rounds are the circle method's, for each of
    // the participant orders the scheduler tries. What may be searched in
    // such a layout depends on the role assignment:
    //
    // - round parity states no rule, so the roles of the first leg are the
    //   ones it gives and are not searched. A schedule exists when that
    //   first leg keeps the rule and the later legs have roles that keep
    //   it: the one set of roles a mirrored or repeated leg has, or any
    //   roles for a shuffled leg;
    // - balanced, the default, states its rule, so the roles of the first
    //   leg are searched within it. A schedule exists when some choice of
    //   roles for the first leg leaves it balanced and keeps the rule, with
    //   later legs that keep it (issue #51: a balanced leg that satisfies
    //   the constraints is found, not only the one the circle rule gives).
    //
    // Three to five participants run every case. Six and seven run the
    // shuffled legs only, over two and three legs, under round parity: a
    // field that size is there for the search of the later legs, which has
    // events between the two it goes back and forth between. The whole
    // sweep of six and seven under round parity (the three strategies, two
    // to four legs) passed as well, in 2.0 s and 5.9 s where these cases
    // take 0.2 s and 1.1 s; almost all of it is the scheduler describing
    // its refusals. Under the balanced role assignment six pass too (17 s,
    // most of it the oracle going through the 2^15 choices of roles of a
    // first leg for each case that has no schedule).
    it('finds a schedule exactly when one exists', function (int $size): void {
        $field = roleSweepField($size);
        $roundsPerLeg = $size % 2 === 0 ? $size - 1 : $size;
        $everyCase = $size <= 5;
        $wrong = [];
        $found = 0;
        $refused = 0;

        foreach (roleSweepRoleAssignments() as $roleName => $roleAssignment) {
            $firstLegIsSearched = $roleName === 'balanced';
            if ($firstLegIsSearched && !$everyCase) {
                continue;
            }

            // The first leg of every participant order the scheduler tries
            $firstLegs = [];
            for ($rotation = 0; $rotation < $size; ++$rotation) {
                $rotated = [...array_slice($field, $rotation), ...array_slice($field, 0, $rotation)];
                $firstLegs[] = RoleScheduleOracle::read(
                    (new RoundRobinScheduler())->schedule($rotated, new RoundRobinOptions(roleAssignment: $roleAssignment))
                );
            }

            for ($legs = 2; $legs <= ($everyCase ? 4 : 3); ++$legs) {
                foreach (roleSweepRules($roundsPerLeg, $legs) as $ruleName => [$constraints, $breaks]) {
                    foreach (roleSweepStrategies() as $strategyName => [$strategy, $makeStrategy]) {
                        if (!$everyCase && $strategy !== 'shuffled') {
                            continue;
                        }

                        $case = "{$size} participants, {$legs} legs, {$strategyName}, {$roleName}, {$ruleName}";

                        // Under the balanced role assignment every event
                        // of the first leg may be reversed, as long as the
                        // leg ends balanced
                        $whole = $firstLegIsSearched
                            ? RoleScheduleOracle::every($breaks, RoleScheduleOracle::balancedFirstLegRule($size, $roundsPerLeg))
                            : $breaks;

                        $exists = false;
                        foreach ($firstLegs as $firstLeg) {
                            if (RoleScheduleOracle::rolesExist($firstLeg, $roundsPerLeg, $legs, $strategy, $firstLegIsSearched, $whole)) {
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
                        foreach (roleSweepFaults($schedule, $field, $legs, $strategy, $whole) as $fault) {
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
    })->with([3, 4, 5, 6, 7])->skip(roleSweepIsUnderCoverage(), ROLE_SWEEP_NOT_UNDER_COVERAGE);

    // The same question for a single leg, which the sweep above leaves out
    // (its rules are about what happens where two legs meet).
    it('finds a single leg exactly when a balanced one exists, with the default options', function (int $size): void {
        $field = roleSweepField($size);
        $roundsPerLeg = $size % 2 === 0 ? $size - 1 : $size;
        $balanced = RoleScheduleOracle::balancedFirstLegRule($size, $roundsPerLeg);
        $wrong = [];
        $found = 0;
        $refused = 0;

        $firstLegs = [];
        for ($rotation = 0; $rotation < $size; ++$rotation) {
            $rotated = [...array_slice($field, $rotation), ...array_slice($field, 0, $rotation)];
            $firstLegs[] = RoleScheduleOracle::read((new RoundRobinScheduler())->schedule($rotated));
        }

        foreach (roleSweepRules($roundsPerLeg, 2) as $ruleName => [$constraints, $breaks]) {
            $case = "{$size} participants, {$ruleName}";
            $whole = RoleScheduleOracle::every($breaks, $balanced);

            $exists = false;
            foreach ($firstLegs as $firstLeg) {
                if (RoleScheduleOracle::rolesExist($firstLeg, $roundsPerLeg, 1, 'mirrored', true, $whole)) {
                    $exists = true;
                    break;
                }
            }

            try {
                $schedule = (new RoundRobinScheduler($constraints))->schedule($field);
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
            foreach (roleSweepFaults($schedule, $field, 1, 'mirrored', $whole) as $fault) {
                $wrong[] = "{$case}: {$fault}";
            }
        }

        expect($wrong)->toBe([])->and($found)->toBeGreaterThan(0)->and($refused)->toBeGreaterThan(0);
    })->with([4, 5, 6, 7, 8]);
});

describe('roles over several legs, with backtracking', function (): void {
    // The search chooses the first leg: how its pairings fall into rounds,
    // and its roles. A schedule exists when some leg, with some roles, has
    // later legs under the leg strategy that keep the rule. With the
    // round-parity role assignment, which keeps the roles it is handed,
    // that is everything the scheduler may return. With the balanced role
    // assignment, the default, the first leg must also be balanced, and
    // that is the whole of the difference: a schedule exists when some leg
    // has roles that leave it balanced and keep the rule in every leg, and
    // the scheduler returns one exactly then (issue #51). Until it searched
    // the roles within the rule of the role assignment it missed 20 of the
    // satisfiable cases of a sweep of 2,988, all of three participants with
    // a pinned role: it balanced the first leg it found, had that rejected,
    // and stopped.
    //
    // Four and five participants run here, four over two to four legs and
    // five over two and three. Five over four legs hold as well, with no
    // case stopped by a bound: every case of four legs had the answer of
    // the same case of three, and the two cases with no schedule under a
    // closure cost the scheduler 1.9 s more (the sweep of five took 38 s
    // with them and with the oracle asked everything; see
    // roleSweepSomeLegHasRoles()). Six hold too, with no case stopped by a
    // bound, and take more than five minutes (five and six participants
    // both have 720 ways to play a leg, and both sides go through them for
    // every case that has no schedule); add 6 to the list to run them.
    it('finds a schedule exactly when one exists, or says that a bound stopped it', function (int $size, string $roleName): void {
        $field = roleSweepField($size);
        $ids = array_map(static fn(Participant $participant): string => $participant->getId(), $field);
        $roundsPerLeg = $size % 2 === 0 ? $size - 1 : $size;
        $roleAssignment = roleSweepRoleAssignments()[$roleName];
        $wrong = [];
        $found = 0;
        $proven = 0;
        $stopped = [];

        for ($legs = 1; $legs <= ($size <= 4 ? 4 : 3); ++$legs) {
            foreach (roleSweepRules($roundsPerLeg, max(2, $legs)) as $ruleName => [$constraints, $breaks]) {
                foreach (roleSweepStrategies() as $strategyName => [$strategy, $makeStrategy]) {
                    if ($legs === 1 && $strategyName !== 'mirrored') {
                        // One leg has no leg strategy to tell apart
                        continue;
                    }

                    $case = "{$size} participants, {$legs} legs, {$strategyName}, {$roleName}, {$ruleName}";
                    $whole = $roleName === 'balanced'
                        ? RoleScheduleOracle::every($breaks, RoleScheduleOracle::balancedFirstLegRule($size, $roundsPerLeg))
                        : $breaks;

                    $exists = roleSweepSomeLegHasRoles($ids, $roundsPerLeg, $legs, $strategy, $ruleName, $whole);
                    if ($exists && roleSweepMirroredLegsBreakThePin($ruleName, $strategy, $legs)) {
                        $wrong[] = "{$case}: the oracle finds a schedule, and mirrored legs cannot keep the pinned role";
                    }

                    $options = new RoundRobinOptions($legs, $makeStrategy(), true, $roleAssignment);
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
                    foreach (roleSweepFaults($schedule, $field, $legs, $strategy, $whole) as $fault) {
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
    })
        ->with([3, 4, 5])
        ->with(['round parity', 'balanced'])
        ->skip(roleSweepIsUnderCoverage(), ROLE_SWEEP_NOT_UNDER_COVERAGE);

    // A schedule that only the search completes is still a function of the
    // seed: the draws are the roles the search tries first. Under round
    // parity such schedules are common; under the balanced role assignment
    // the roles of the first leg are searched without backtracking, so few
    // are left for it, and the test holds whichever there are.
    it('gives the same schedule for the same seed when only the search completes it', function (int $size): void {
        $field = roleSweepField($size);
        $roundsPerLeg = $size % 2 === 0 ? $size - 1 : $size;
        $searched = 0;

        foreach (roleSweepRoleAssignments() as $roleName => $roleAssignment) {
            for ($legs = 2; $legs <= 3; ++$legs) {
                foreach (roleSweepRules($roundsPerLeg, $legs) as $ruleName => [$constraints]) {
                    foreach ([1, 2, 3] as $seed) {
                        $schedules = [];
                        foreach ([false, true, true] as $backtracking) {
                            try {
                                $schedules[] = (new RoundRobinScheduler($constraints))
                                    ->schedule($field, new RoundRobinOptions($legs, new ShuffledLegStrategy(seed: $seed), $backtracking, $roleAssignment))
                                    ->toJson();
                            } catch (IncompleteScheduleException) {
                                $schedules[] = null;
                            }
                        }

                        $case = "{$size} participants, {$legs} legs, seed {$seed}, {$roleName}, {$ruleName}";
                        expect($schedules[2])->toBe($schedules[1], $case);
                        if ($schedules[0] === null && $schedules[1] !== null) {
                            ++$searched;
                        } elseif ($schedules[0] !== null) {
                            // What succeeds without backtracking is not
                            // changed by it
                            expect($schedules[1])->toBe($schedules[0], $case);
                        }
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
    // still record.
    //
    // Twenty are not run. With twenty the generator finds no first leg at
    // all within its budget, and the roles of that layout are searched
    // after it has spent it, which is the other of the two places the
    // scheduler searches them from. The case passed ([20, true], the
    // layout of the circle method with roles that keep the limit) and took
    // 4.9 s, all of it the 200,000 steps of the budget, which no smaller
    // field spends under this limit without finding a first leg. That the layout is searched
    // from that place is held by the test of twelve participants in
    // tests/Unit/Scheduling/RoundRobinRoleSearchTest.php, which counts the
    // first legs tried; that a schedule found from it is returned is not
    // held by a test. Add [20, true] to the list to run it.
    //
    // The role assignment is round parity, which states no rule: its roles
    // are searched with backtracking only. (The balanced roles of the
    // default keep this limit over two repeated legs as they are.)
    it('repeats a first leg whose roles the search chose', function (int $size, bool $keepsTheCircleLayout): void {
        $field = roleSweepField($size);
        $constraints = ConstraintSet::create()->add(ConsecutiveRoleConstraint::homeAway(2))->build();
        $options = static fn(bool $backtracking): RoundRobinOptions => new RoundRobinOptions(
            2,
            new RepeatedLegStrategy(),
            $backtracking,
            new RoundParityRoleAssignment()
        );

        // The round-parity roles, played twice, break the limit, and the
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
    })->with([[8, false], [10, true], [13, true]]);

    // The home-and-away league: two mirrored legs and a limit on how often
    // a role repeats, with the options as they come. The balanced roles the
    // circle rule gives break the limit where the two legs meet (three in a
    // row in a field of even size, four in some fields of odd size), which
    // the round-parity roles of 0.2.x did not in a field of even size. So
    // the default must find other balanced roles for the first leg, without
    // backtracking, or a league that 0.2.x scheduled would fail under it
    // (issue #51). It finds them in the layout of the circle method, which
    // is the one it searches: the pairings and their rounds are those of
    // the schedule without the constraint, and only roles differ.
    it('mirrors a balanced first leg whose roles the scheduler searched, with the default options', function (
        int $size,
        int $limit,
        int $legs
    ): void {
        $field = roleSweepField($size);
        $roundsPerLeg = $size % 2 === 0 ? $size - 1 : $size;
        $constraints = ConstraintSet::create()->add(ConsecutiveRoleConstraint::homeAway($limit))->build();
        $options = new RoundRobinOptions($legs);

        $unconstrained = (new RoundRobinScheduler())->schedule($field, $options);
        expect(RoleCounts::worstStreak($unconstrained))->toBeGreaterThan($limit);

        $schedule = (new RoundRobinScheduler($constraints))->schedule($field, $options);

        expect(roleSweepFaults($schedule, $field, $legs, 'mirrored', RoleScheduleOracle::streakRule($limit)))->toBe([])
            ->and(RoleCounts::structure($schedule))->toBe(RoleCounts::structure($unconstrained))
            ->and($schedule->getMetadata())->toBe($unconstrained->getMetadata())
            ->and(RoleCounts::worstEndImbalance(RoleCounts::leg($schedule, 1, $roundsPerLeg)))->toBe($size % 2 === 0 ? 1 : 0)
            // The whole schedule: level over an even number of mirrored
            // legs, and as one leg leaves it over an odd number
            ->and(RoleCounts::worstEndImbalance($schedule))->toBe($legs % 2 === 0 ? 0 : ($size % 2 === 0 ? 1 : 0));
    })->with([
        'six, two in a row' => [6, 2, 2],
        'eight, two in a row' => [8, 2, 2],
        'twelve, two in a row' => [12, 2, 2],
        'twenty, two in a row' => [20, 2, 2],
        'twelve, two in a row, four legs' => [12, 2, 4],
        'nine, three in a row' => [9, 3, 2],
        'thirteen, three in a row' => [13, 3, 2],
        'thirteen, three in a row, three legs' => [13, 3, 3],
    ]);

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
