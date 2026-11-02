<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\ConsecutiveRoleConstraint;
use MissionGaming\Tactician\Constraints\ConstraintInterface;
use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Constraints\MetadataConstraint;
use MissionGaming\Tactician\Constraints\MinimumRestPeriodsConstraint;
use MissionGaming\Tactician\Constraints\NoRepeatPairings;
use MissionGaming\Tactician\Constraints\RoleBalanceConstraint;
use MissionGaming\Tactician\Constraints\SeedProtectionConstraint;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\LegStrategies\LegPlanContribution;
use MissionGaming\Tactician\LegStrategies\LegStrategyInterface;
use MissionGaming\Tactician\LegStrategies\MirroredLegStrategy;
use MissionGaming\Tactician\LegStrategies\RepeatedLegStrategy;
use MissionGaming\Tactician\LegStrategies\ShuffledLegStrategy;
use MissionGaming\Tactician\RoleAssignment\BalancedRoleAssignment;
use MissionGaming\Tactician\RoleAssignment\RoleAssignmentInterface;
use MissionGaming\Tactician\RoleAssignment\RoleFallback;
use MissionGaming\Tactician\RoleAssignment\RoundParityRoleAssignment;
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Scheduling\SchedulingContext;
use MissionGaming\Tactician\Tests\Support\RoleCounts;
use MissionGaming\Tactician\Tests\Support\RoleScheduleOracle;
use MissionGaming\Tactician\Tests\Support\RoundRobinAudit;
use Random\Engine\Mt19937;
use Random\Randomizer;

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
 * A leg strategy of the caller's that hands every call to a shuffled leg
 * strategy, and so changes nothing. The scheduler cannot know that a clone
 * of it would draw from the seed again, so it clones only the library's
 * class. (Until 0.3.0 this was a subclass of ShuffledLegStrategy, which is
 * final now.).
 */
final readonly class RoleSweepShuffledLegsOfTheCallers implements LegStrategyInterface
{
    private ShuffledLegStrategy $shuffled;

    /**
     * @throws InvalidConfigurationException Never: a seed is given
     */
    public function __construct(int $seed)
    {
        $this->shuffled = new ShuffledLegStrategy(seed: $seed);
    }

    #[Override]
    public function planLegs(array $participants, int $legs, ConstraintSet $constraints): LegPlanContribution
    {
        return $this->shuffled->planLegs($participants, $legs, $constraints);
    }

    #[Override]
    public function generateEventForLeg(array $participants, int $leg, int $round, SchedulingContext $context): ?Event
    {
        return $this->shuffled->generateEventForLeg($participants, $leg, $round, $context);
    }
}

/**
 * A constraint class of the caller's that answers as the limit it is given
 * answers.
 */
final readonly class RoleSweepLimitOfTheCallers implements ConstraintInterface
{
    public function __construct(private ConstraintInterface $limit) {}

    #[Override]
    public function isSatisfied(Event $event, SchedulingContext $context): bool
    {
        return $this->limit->isSatisfied($event, $context);
    }

    #[Override]
    public function getName(): string
    {
        return 'A limit of the caller\'s';
    }
}

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

    return array_any($arguments, fn($argument) => is_string($argument) && str_starts_with($argument, '--coverage'));
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
    // - balanced states its rule, so the roles of the first
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
                    new RoundRobinScheduler()->schedule($rotated, new RoundRobinOptions(roleAssignment: $roleAssignment))
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
                        $exists = array_any($firstLegs, fn($firstLeg) => RoleScheduleOracle::rolesExist($firstLeg, $roundsPerLeg, $legs, $strategy, $firstLegIsSearched, $whole));

                        $options = new RoundRobinOptions($legs, $makeStrategy(), false, $roleAssignment);
                        try {
                            $schedule = new RoundRobinScheduler($constraints)->schedule($field, $options);
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
    // (its rules are about what happens where two legs meet). The balanced
    // role assignment is named: the default returns the same schedule
    // wherever this one returns one, and where it does not the default
    // falls back to the roles of round parity if they keep the rule (the
    // sweeps of the default, at the end of this file).
    it('finds a single leg exactly when a balanced one exists, under the balanced role assignment', function (int $size): void {
        $field = roleSweepField($size);
        $roundsPerLeg = $size % 2 === 0 ? $size - 1 : $size;
        $balanced = RoleScheduleOracle::balancedFirstLegRule($size, $roundsPerLeg);
        $wrong = [];
        $found = 0;
        $refused = 0;

        $firstLegs = [];
        for ($rotation = 0; $rotation < $size; ++$rotation) {
            $rotated = [...array_slice($field, $rotation), ...array_slice($field, 0, $rotation)];
            $firstLegs[] = RoleScheduleOracle::read(new RoundRobinScheduler()->schedule($rotated));
        }

        foreach (roleSweepRules($roundsPerLeg, 2) as $ruleName => [$constraints, $breaks]) {
            $case = "{$size} participants, {$ruleName}";
            $whole = RoleScheduleOracle::every($breaks, $balanced);
            $exists = array_any($firstLegs, fn($firstLeg) => RoleScheduleOracle::rolesExist($firstLeg, $roundsPerLeg, 1, 'mirrored', true, $whole));

            try {
                $schedule = new RoundRobinScheduler($constraints)->schedule($field, new RoundRobinOptions(roleAssignment: new BalancedRoleAssignment()));
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
    // assignment the first leg must also be balanced, and
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
                        $schedule = new RoundRobinScheduler($constraints)->schedule($field, $options);
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
                                $schedules[] = new RoundRobinScheduler($constraints)
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
        $twice = new RoundRobinScheduler()->schedule($field, $options(false));
        expect(RoleCounts::worstStreak($twice))->toBeGreaterThan(2)
            ->and(fn() => new RoundRobinScheduler($constraints)->schedule($field, $options(false)))
            ->toThrow(IncompleteScheduleException::class);

        $schedule = new RoundRobinScheduler($constraints)->schedule($field, $options(true));

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

        $unconstrained = new RoundRobinScheduler()->schedule($field, $options);
        expect(RoleCounts::worstStreak($unconstrained))->toBeGreaterThan($limit);

        $schedule = new RoundRobinScheduler($constraints)->schedule($field, $options);

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
        $unconstrained = new RoundRobinScheduler()->schedule($field, new RoundRobinOptions($legs, new ShuffledLegStrategy(seed: 7)));

        $schedule = new RoundRobinScheduler($constraints)->schedule($field, new RoundRobinOptions($legs, new ShuffledLegStrategy(seed: 7)));

        expect(roleSweepFaults($schedule, $field, $legs, 'shuffled', RoleScheduleOracle::streakRule(2)))->toBe([])
            ->and(RoleCounts::structure($schedule))->toBe(RoleCounts::structure($unconstrained))
            ->and(RoleCounts::worstStreak($unconstrained))->toBeGreaterThan(2);
    })->with([[10, 2], [12, 4], [16, 3], [20, 2], [24, 4]]);
});

/**
 * A schedule as plain data, without the mark of a fall-back: what a
 * schedule of the default that fell back has in common with the schedule
 * the round-parity role assignment gives.
 *
 * @return array<string, mixed>
 */
function roleSweepWithoutTheMark(Schedule $schedule): array
{
    $data = $schedule->toArray();
    $metadata = $data['metadata'] ?? [];
    assert(is_array($metadata));
    unset($metadata[RoleFallback::METADATA_KEY]);
    $data['metadata'] = $metadata;

    return $data;
}

/**
 * What one configuration gives under a role assignment: the schedule, or
 * the message of the failure.
 *
 * @param list<Participant> $field
 *
 * @throws InvalidConfigurationException Never: the fields and options of the sweeps are valid
 */
function roleSweepOutcome(
    ConstraintSet $constraints,
    array $field,
    int $legs,
    LegStrategyInterface $strategy,
    bool $backtracking,
    ?RoleAssignmentInterface $roleAssignment,
    ?int $seed = null
): Schedule|string {
    try {
        return new RoundRobinScheduler($constraints, $seed === null ? null : new Randomizer(new Mt19937($seed)))
            ->schedule($field, new RoundRobinOptions($legs, $strategy, $backtracking, $roleAssignment));
    } catch (IncompleteScheduleException $failure) {
        return $failure->getMessage();
    }
}

/**
 * One question put to a constraint, as text: the event as named, its
 * round, and how many events its context holds. Two runs that ask the same
 * questions in the same order give the same list.
 */
function roleSweepQuestion(Event $event, SchedulingContext $context): string
{
    return implode(' v ', array_map(static fn(Participant $participant): string => $participant->getId(), $event->getParticipants()))
        . ' in ' . ($event->getRound()?->getNumber() ?? 0)
        . ' after ' . count($context->getExistingEvents());
}

/**
 * The questions a constraint was asked under the default, split around the
 * run of round parity the default makes: those asked before it (the
 * balanced roles as given in the first layout) and those asked after it
 * (the search for balanced roles with the outcome of round parity in hand).
 * The run of round parity is found as the questions
 * RoundParityRoleAssignment, named, asks, in their order, since the default
 * makes that run as it is made named. Null where they are not there.
 *
 * @param list<string> $underTheDefault
 * @param list<string> $underRoundParity
 * @return array{list<string>, list<string>}|null
 */
function roleSweepAroundRoundParity(array $underTheDefault, array $underRoundParity): ?array
{
    $length = count($underRoundParity);
    $first = $underRoundParity[0] ?? null;
    for ($start = 0; $start + $length <= count($underTheDefault); ++$start) {
        if ($underTheDefault[$start] === $first && array_slice($underTheDefault, $start, $length) === $underRoundParity) {
            return [array_slice($underTheDefault, 0, $start), array_slice($underTheDefault, $start + $length)];
        }
    }

    return null;
}

/**
 * The two participants who meet in the given round of a schedule's first
 * layout, as a constraint reads a pairing: whichever is named first. Read
 * from the schedule round parity gives without constraints, which has one
 * layout, the first a call with constraints lays out: the participants in
 * the order given, or in the first shuffle of a randomizer seeded the same.
 *
 * @param list<Participant> $field
 * @return list<string>
 *
 * @throws IncompleteScheduleException Never: the schedule it reads has no constraints
 * @throws InvalidConfigurationException Never: the fields of the sweeps are valid
 */
function roleSweepPairingOfTheFirstLayout(array $field, int $legs, LegStrategyInterface $strategy, int $round, string $participant, ?int $seed): array
{
    $schedule = new RoundRobinScheduler(null, $seed === null ? null : new Randomizer(new Mt19937($seed)))
        ->schedule($field, new RoundRobinOptions($legs, $strategy, false, new RoundParityRoleAssignment()));
    foreach (RoleScheduleOracle::read($schedule->getEvents()) as [$eventRound, $first, $second]) {
        if ($eventRound === $round && in_array($participant, [$first, $second], true)) {
            $pairing = [$first, $second];
            sort($pairing);

            return $pairing;
        }
    }

    return [];
}

/**
 * A rule that the roles the generator proposes keep and no balanced leg
 * does, as the constraint set and as the oracle reads it; null where there
 * is none of this kind.
 *
 * The participant that the round-parity roles of the field as given leave
 * furthest out of balance has, against every opponent in the first leg, the
 * role those roles give it. It is a closure, so the scheduler cannot know
 * what it reads. The later legs are left free, so that every leg strategy
 * can still complete the schedule.
 *
 * @param list<Participant> $field
 * @return array{ConstraintSet, Closure(list<array{int, string, string}>): bool}|null
 *
 * @throws IncompleteScheduleException Never: the schedule it reads has no constraints
 * @throws InvalidConfigurationException Never: the fields of the sweeps are valid
 */
function roleSweepRuleThatUnbalances(array $field, int $roundsPerLeg): ?array
{
    $proposed = new RoundRobinScheduler()->schedule($field, new RoundRobinOptions(roleAssignment: new RoundParityRoleAssignment()));
    $differences = RoleCounts::differences($proposed);
    $furthest = RoleCounts::largest($differences);
    if ($furthest < 2) {
        return null;
    }

    $pinned = (string) array_search($furthest, array_map(abs(...), $differences), true);
    /** @var array<string, bool> $firstNamedAgainst opponent ID => whether the pinned participant is first-named */
    $firstNamedAgainst = [];
    foreach (RoleScheduleOracle::read($proposed->getEvents()) as [, $first, $second]) {
        if ($first === $pinned) {
            $firstNamedAgainst[$second] = true;
        } elseif ($second === $pinned) {
            $firstNamedAgainst[$first] = false;
        }
    }

    $breaks = static function (array $events) use ($pinned, $firstNamedAgainst, $roundsPerLeg): bool {
        foreach ($events as [$round, $first, $second]) {
            if ($round > $roundsPerLeg) {
                continue;
            }
            if (($first === $pinned && !$firstNamedAgainst[$second]) || ($second === $pinned && $firstNamedAgainst[$first])) {
                return true;
            }
        }

        return false;
    };

    return [
        ConstraintSet::create()->custom(
            static fn(Event $event): bool => !$breaks(RoleScheduleOracle::read([$event])),
            'The roles round parity gives one participant'
        )->build(),
        $breaks,
    ];
}

/**
 * The rules of the sweeps of the default: those of the other sweeps, and
 * the one that leaves no balanced leg where the field has it.
 *
 * @param list<Participant> $field
 * @return array<string, array{ConstraintSet, Closure(list<array{int, string, string}>): bool}>
 *
 * @throws IncompleteScheduleException Never: see roleSweepRuleThatUnbalances()
 * @throws InvalidConfigurationException Never: see roleSweepRuleThatUnbalances()
 */
function roleSweepRulesOfTheDefault(array $field, int $roundsPerLeg, int $legs): array
{
    $rules = roleSweepRules($roundsPerLeg, $legs);
    $unbalancing = roleSweepRuleThatUnbalances($field, $roundsPerLeg);
    if ($unbalancing !== null) {
        $rules['the roles round parity gives one participant, in the first leg'] = $unbalancing;
    }

    return $rules;
}

/**
 * The reasons a fall-back under a rule of the sweep may carry, in fields
 * small enough that no bound of a search is near.
 *
 * Without backtracking the search goes through every choice of balanced
 * roles before the fall-back, whatever the rule is made of, so the reason
 * is that none exists: for a rule made of the library's own constraints
 * before round parity is tried, and for a rule that is a closure after it,
 * with the schedule of round parity in hand and the search held to 4,000
 * events put to the rule, which these fields are far inside.
 *
 * With backtracking a rule that is a closure has one thing left out with a
 * schedule in hand: the backtracking search for a balanced first leg, where
 * a rotated order gave that schedule. The reason then is that the
 * constraints rejected the balanced roles, which does not say that none
 * exist. A rule that holds a closure and one of the library's limits can
 * end on either.
 *
 * @return list<RoleFallback>
 */
function roleSweepReasonsOfAFallBack(string $ruleName, bool $backtracking): array
{
    $hasAClosure = str_contains($ruleName, 'first-named') || str_contains($ruleName, 'round parity gives');
    $hasALimitOfTheLibrary = str_contains($ruleName, 'streak') || str_contains($ruleName, 'balance');

    return match (true) {
        !$backtracking => [RoleFallback::NoBalancedRoles],
        $hasAClosure && $hasALimitOfTheLibrary => [RoleFallback::NoBalancedRoles, RoleFallback::BalancedRolesRejected],
        $hasAClosure => [RoleFallback::BalancedRolesRejected],
        default => [RoleFallback::NoBalancedRoles],
    };
}

// The default of the options is balanced where possible
// (BalancedWherePossibleRoleAssignment): the balanced roles where the
// scheduler finds a schedule with them, and otherwise the roles the
// generator proposes, which are those of round parity, with a mark on the
// schedule (RoleFallback). What that promises is held here against the
// oracle, which knows nothing of the scheduler:
//
// - never worse than round parity: where a schedule exists with the roles
//   the generator proposes, the default returns a schedule;
// - balanced whenever possible: where balanced roles exist in a layout the
//   scheduler tries (with backtracking: in any way to play the first leg),
//   the schedule has them and carries no mark. That is the promise within
//   the bounds of the searches, and fields this small are inside them;
// - marked exactly when the first leg is not balanced, with the reason;
// - the schedule BalancedRoleAssignment gives when it is balanced, and the
//   one RoundParityRoleAssignment gives when it is not, byte for byte
//   apart from the mark; and the failure of round parity when it fails.
describe('the default, balanced where possible', function (): void {
    // Three to five participants, one to four legs, the rules and
    // strategies of the sweeps above, without backtracking. The layouts are
    // the rotated orders of the circle method.
    it('completes wherever the roles the generator proposes do, and is balanced wherever a layout has balanced roles', function (int $size): void {
        $field = roleSweepField($size);
        $roundsPerLeg = $size % 2 === 0 ? $size - 1 : $size;
        $balancedLeg = RoleScheduleOracle::balancedFirstLegRule($size, $roundsPerLeg);
        $wrong = [];
        $counts = ['balanced' => 0, 'fell back' => 0, 'refused' => 0];

        // The first leg of every participant order the scheduler tries, in
        // the roles the generator proposes
        $proposed = [];
        for ($rotation = 0; $rotation < $size; ++$rotation) {
            $rotated = [...array_slice($field, $rotation), ...array_slice($field, 0, $rotation)];
            $proposed[] = RoleScheduleOracle::read(
                new RoundRobinScheduler()->schedule($rotated, new RoundRobinOptions(roleAssignment: new RoundParityRoleAssignment()))
            );
        }

        for ($legs = 1; $legs <= 4; ++$legs) {
            foreach (roleSweepRulesOfTheDefault($field, $roundsPerLeg, max(2, $legs)) as $ruleName => [$constraints, $breaks]) {
                foreach (roleSweepStrategies() as $strategyName => [$strategy, $makeStrategy]) {
                    if ($legs === 1 && $strategyName !== 'mirrored') {
                        // One leg has no leg strategy to tell apart
                        continue;
                    }

                    $case = "{$size} participants, {$legs} legs, {$strategyName}, {$ruleName}";
                    $whole = RoleScheduleOracle::every($breaks, $balancedLeg);
                    $existsAsProposed = false;
                    $existsBalanced = false;
                    foreach ($proposed as $firstLeg) {
                        $existsAsProposed = $existsAsProposed || RoleScheduleOracle::rolesExist($firstLeg, $roundsPerLeg, $legs, $strategy, false, $breaks);
                        $existsBalanced = $existsBalanced || RoleScheduleOracle::rolesExist($firstLeg, $roundsPerLeg, $legs, $strategy, true, $whole);
                    }

                    $schedule = roleSweepOutcome($constraints, $field, $legs, $makeStrategy(), false, null);
                    $underRoundParity = roleSweepOutcome($constraints, $field, $legs, $makeStrategy(), false, new RoundParityRoleAssignment());

                    if (is_string($schedule)) {
                        ++$counts['refused'];
                        if ($existsAsProposed) {
                            $wrong[] = "{$case}: a schedule exists with the roles the generator proposes, and the default said: {$schedule}";
                        }
                        if ($existsBalanced) {
                            $wrong[] = "{$case}: a balanced schedule exists, and the default said: {$schedule}";
                        }
                        if ($schedule !== $underRoundParity) {
                            $wrong[] = "{$case}: the failure is not the one round parity gives";
                        }

                        continue;
                    }

                    foreach (roleSweepFaults($schedule, $field, $legs, $strategy, $breaks) as $fault) {
                        $wrong[] = "{$case}: {$fault}";
                    }

                    $mark = RoleFallback::of($schedule);
                    $firstLegIsBalanced = !$balancedLeg(RoleScheduleOracle::read($schedule->getEvents()));
                    $underBalanced = roleSweepOutcome($constraints, $field, $legs, $makeStrategy(), false, new BalancedRoleAssignment());

                    if ($mark === null) {
                        ++$counts['balanced'];
                        if (!$firstLegIsBalanced) {
                            $wrong[] = "{$case}: the first leg is not balanced, and the schedule has no mark";
                        }
                        if (!$underBalanced instanceof Schedule || $underBalanced->toArray() !== $schedule->toArray()) {
                            $wrong[] = "{$case}: not the schedule the balanced role assignment gives";
                        }

                        continue;
                    }

                    ++$counts['fell back'];
                    if ($firstLegIsBalanced) {
                        $wrong[] = "{$case}: the first leg is balanced, and the schedule is marked {$mark->value}";
                    }
                    if ($existsBalanced) {
                        $wrong[] = "{$case}: a layout has balanced roles, and the schedule fell back ({$mark->value})";
                    }
                    if (!in_array($mark, roleSweepReasonsOfAFallBack($ruleName, false), true)) {
                        $wrong[] = "{$case}: marked {$mark->value}";
                    }
                    if ($underBalanced instanceof Schedule) {
                        $wrong[] = "{$case}: the balanced role assignment gives a schedule, and the default fell back";
                    }
                    if (!$underRoundParity instanceof Schedule || $underRoundParity->toArray() !== roleSweepWithoutTheMark($schedule)) {
                        $wrong[] = "{$case}: not the schedule round parity gives";
                    }
                }
            }
        }

        expect($wrong)->toBe([])
            // The sweep means nothing unless it holds all three answers
            ->and($counts['balanced'])->toBeGreaterThan(0)
            ->and($counts['fell back'])->toBeGreaterThan(0)
            ->and($counts['refused'])->toBeGreaterThan(0);
    })->with([3, 4, 5])->skip(roleSweepIsUnderCoverage(), ROLE_SWEEP_NOT_UNDER_COVERAGE);

    // With backtracking the roles the generator proposes are whatever the
    // search chooses, so the default completes wherever any schedule
    // exists; and it is balanced wherever some way to play the first leg
    // has balanced roles that keep the rule in every leg.
    it('completes wherever a schedule exists and is balanced wherever a balanced one does, with backtracking', function (int $size): void {
        $field = roleSweepField($size);
        $ids = array_map(static fn(Participant $participant): string => $participant->getId(), $field);
        $roundsPerLeg = $size % 2 === 0 ? $size - 1 : $size;
        $balancedLeg = RoleScheduleOracle::balancedFirstLegRule($size, $roundsPerLeg);
        $wrong = [];
        $counts = ['balanced' => 0, 'fell back' => 0, 'refused' => 0];

        for ($legs = 1; $legs <= 3; ++$legs) {
            foreach (roleSweepRulesOfTheDefault($field, $roundsPerLeg, max(2, $legs)) as $ruleName => [$constraints, $breaks]) {
                foreach (roleSweepStrategies() as $strategyName => [$strategy, $makeStrategy]) {
                    if ($legs === 1 && $strategyName !== 'mirrored') {
                        continue;
                    }

                    $case = "{$size} participants, {$legs} legs, {$strategyName}, {$ruleName}";
                    $whole = RoleScheduleOracle::every($breaks, $balancedLeg);
                    $exists = roleSweepSomeLegHasRoles($ids, $roundsPerLeg, $legs, $strategy, $ruleName, $breaks);
                    $existsBalanced = $exists && roleSweepSomeLegHasRoles($ids, $roundsPerLeg, $legs, $strategy, $ruleName, $whole);

                    $schedule = roleSweepOutcome($constraints, $field, $legs, $makeStrategy(), true, null);

                    if (is_string($schedule)) {
                        ++$counts['refused'];
                        if ($exists) {
                            $wrong[] = "{$case}: a schedule exists, and the default said: {$schedule}";
                        }
                        if ($schedule !== roleSweepOutcome($constraints, $field, $legs, $makeStrategy(), true, new RoundParityRoleAssignment())) {
                            $wrong[] = "{$case}: the failure is not the one round parity gives";
                        }

                        continue;
                    }

                    foreach (roleSweepFaults($schedule, $field, $legs, $strategy, $breaks) as $fault) {
                        $wrong[] = "{$case}: {$fault}";
                    }

                    $mark = RoleFallback::of($schedule);
                    $firstLegIsBalanced = !$balancedLeg(RoleScheduleOracle::read($schedule->getEvents()));
                    ++$counts[$mark === null ? 'balanced' : 'fell back'];

                    if (($mark === null) !== $firstLegIsBalanced) {
                        $wrong[] = "{$case}: the first leg is " . ($firstLegIsBalanced ? '' : 'not ') . 'balanced, and the mark is ' . ($mark->value ?? 'absent');
                    }
                    if ($existsBalanced && $mark !== null) {
                        $wrong[] = "{$case}: a balanced schedule exists, and the schedule fell back ({$mark->value})";
                    }
                    if ($mark !== null && !in_array($mark, roleSweepReasonsOfAFallBack($ruleName, true), true)) {
                        $wrong[] = "{$case}: marked {$mark->value}";
                    }
                }
            }
        }

        expect($wrong)->toBe([])
            ->and($counts['balanced'])->toBeGreaterThan(0)
            ->and($counts['fell back'])->toBeGreaterThan(0)
            ->and($counts['refused'])->toBeGreaterThan(0);
    })->with([3, 4])->skip(roleSweepIsUnderCoverage(), ROLE_SWEEP_NOT_UNDER_COVERAGE);

    // The same under a randomizer, where the oracle has no layouts to read:
    // the default is held to the two role assignments it is made of, each
    // with a randomizer seeded the same. Whatever the seed and the rule,
    //
    // - where round parity gives a schedule, so does the default;
    // - a schedule without a mark is the one the balanced role assignment
    //   gives, byte for byte;
    // - a schedule with a mark is the one round parity gives, byte for byte
    //   apart from the mark;
    // - a failure is the failure of round parity.
    //
    // Three to five participants, one to three legs, three seeds. Six
    // passed too, in 19 s where these take 5 s (most of it the failures of
    // round parity, which are analysed for every rotated order under a
    // closure, once for round parity named and once inside the default).
    it('is the schedule of one of the two role assignments under a randomizer, and fails only where round parity does', function (): void {
        $wrong = [];
        $counts = ['balanced' => 0, 'fell back' => 0, 'refused' => 0];

        foreach ([[3, 3], [4, 3], [5, 3]] as [$size, $mostLegs]) {
            $field = roleSweepField($size);
            $roundsPerLeg = $size % 2 === 0 ? $size - 1 : $size;

            for ($legs = 1; $legs <= $mostLegs; ++$legs) {
                foreach (roleSweepRulesOfTheDefault($field, $roundsPerLeg, max(2, $legs)) as $ruleName => [$constraints]) {
                    foreach (roleSweepStrategies() as $strategyName => [, $makeStrategy]) {
                        if ($legs === 1 && $strategyName !== 'mirrored') {
                            continue;
                        }

                        foreach ([1, 2, 3] as $seed) {
                            $case = "{$size} participants, {$legs} legs, {$strategyName}, seed {$seed}, {$ruleName}";
                            $schedule = roleSweepOutcome($constraints, $field, $legs, $makeStrategy(), false, null, $seed);
                            $underRoundParity = roleSweepOutcome($constraints, $field, $legs, $makeStrategy(), false, new RoundParityRoleAssignment(), $seed);

                            if (is_string($schedule)) {
                                ++$counts['refused'];
                                if ($schedule !== $underRoundParity) {
                                    $wrong[] = "{$case}: the default failed, and not as round parity does";
                                }

                                continue;
                            }

                            if (RoleFallback::of($schedule) === null) {
                                ++$counts['balanced'];
                                $underBalanced = roleSweepOutcome($constraints, $field, $legs, $makeStrategy(), false, new BalancedRoleAssignment(), $seed);
                                if (!$underBalanced instanceof Schedule || $underBalanced->toArray() !== $schedule->toArray()) {
                                    $wrong[] = "{$case}: no mark, and not the schedule the balanced role assignment gives";
                                }

                                continue;
                            }

                            ++$counts['fell back'];
                            if (!$underRoundParity instanceof Schedule || $underRoundParity->toArray() !== roleSweepWithoutTheMark($schedule)) {
                                $wrong[] = "{$case}: marked, and not the schedule round parity gives";
                            }
                        }
                    }
                }
            }
        }

        expect($wrong)->toBe([])
            ->and($counts['balanced'])->toBeGreaterThan(0)
            ->and($counts['fell back'])->toBeGreaterThan(0)
            ->and($counts['refused'])->toBeGreaterThan(0);
    });

    // The case the review of the default found. A limit of two events in a
    // row in the same role, over two mirrored legs, written as a closure:
    // the caller's own constraint, which does what
    // ConsecutiveRoleConstraint::homeAway(2) does. The roles of round parity
    // keep it in every field of even size from six up, so these leagues
    // completed up to 0.2.x. The balanced roles break it where the two legs
    // meet, and the search for other balanced roles cannot know what a
    // closure reads: while the default was the strict balanced role
    // assignment it went back one event at a time and failed at its bound,
    // after seconds, for 12 participants and more.
    //
    // Now the schedule is the one of round parity, marked. With it in hand
    // the same search is made, one event at a time, and held to 4,000
    // events put to the constraints, which is not enough to find balanced
    // roles in these fields (it is in a field of eight, below).
    //
    // The cost is counted in what the closure is asked, not in seconds,
    // and the bound on it is added up from what the scheduler does, not
    // read off a run:
    //
    // - the roles of round parity: one question for each event of the
    //   schedule, which is asserted;
    // - the balanced roles as given in the first layout, before round
    //   parity is tried: at most the events of the schedule again, and
    //   the event it ends on once more, for the record;
    // - the search for balanced roles with the schedule in hand. The
    //   bound is looked at before a rotated order and not within one: at
    //   most 3,999 when the last order is begun, which can add the events
    //   of the schedule. A search of roles after the rotated orders has
    //   what is left of the 4,000 and no more;
    // - the event each rotated order ends on, once more for the record,
    //   which is not counted: as many as there are participants here.
    //
    // Together: three times the events of the schedule, 4,000, and the
    // number of participants.
    //
    // Measured: 3,414 questions for 12 participants, 3,959 for 16 and
    // 4,593 for 20, against bounds of 4,408, 4,736 and 5,160 (round parity
    // alone asks 132, 240 and 380). A search that ran on would pass the
    // bound here at once: with the allowance of the strict role assignment
    // in the place of the 4,000, the closure was asked 37,800, 41,760 and
    // 49,692 times before the fall-back.
    it('completes a league under a limit written as a closure with the roles of round parity, marked, within the bound of a search with a schedule in hand', function (int $size): void {
        $field = roleSweepField($size);
        $asked = 0;
        $limit = ConsecutiveRoleConstraint::homeAway(2);
        $asAClosure = ConstraintSet::create()->custom(
            static function (Event $event, SchedulingContext $context) use ($limit, &$asked): bool {
                ++$asked;

                return $limit->isSatisfied($event, $context);
            },
            'Two in a row, as a closure'
        )->build();

        $underRoundParity = new RoundRobinScheduler($asAClosure)->schedule($field, new RoundRobinOptions(2, null, false, new RoundParityRoleAssignment()));
        $askedUnderRoundParity = $asked;

        $asked = 0;
        $schedule = new RoundRobinScheduler($asAClosure)->schedule($field, new RoundRobinOptions(2));

        expect(RoleFallback::of($schedule))->toBe(RoleFallback::BalancedRolesRejected)
            ->and(roleSweepWithoutTheMark($schedule))->toBe($underRoundParity->toArray())
            ->and(roleSweepFaults($schedule, $field, 2, 'mirrored', RoleScheduleOracle::streakRule(2)))->toBe([])
            // One event of the schedule, one question, under round parity
            ->and($askedUnderRoundParity)->toBe($size * ($size - 1))
            ->and($asked)->toBeLessThanOrEqual(4_000 + 3 * $askedUnderRoundParity + $size)
            // Nothing ended at the first rejection: three passes over the
            // schedule were all the default asked while a rejection that
            // turned on earlier events ended everything. This does not show
            // that the roles were searched, since the rotated orders alone
            // ask more than that. The fields of eight below show it, which
            // nothing but that search balances.
            ->and($asked)->toBeGreaterThan(3 * $askedUnderRoundParity);

        // The same limit as the library's own constraint, which the search
        // reasons about exactly: balanced roles are found, as they were
        $own = new RoundRobinScheduler(ConstraintSet::create()->add($limit)->build())->schedule($field, new RoundRobinOptions(2));
        $roundsPerLeg = $size - 1;

        expect(RoleFallback::of($own))->toBeNull()
            ->and(RoleCounts::worstEndImbalance(RoleCounts::leg($own, 1, $roundsPerLeg)))->toBe(1)
            ->and(roleSweepFaults($own, $field, 2, 'mirrored', RoleScheduleOracle::streakRule(2)))->toBe([]);
    })->with([12, 16, 20]);

    // A constraint may rely on what every generation gives it with an
    // event of a later leg: the first meeting of the pair. This one says
    // so and throws without it. Every question the default puts to a
    // constraint is one a generation asks, an event with the events laid
    // out before it, in the search for balanced roles with a schedule in
    // hand as everywhere else: so nothing is thrown, and the league
    // completes. In a field of eight the search finds balanced roles that
    // keep the limit, and the schedule is the one the strict role
    // assignment gives; in a field of twelve it spends its 4,000 events
    // first, and the schedule is the one of round parity, marked.
    //
    // The constraint counts the times it was asked that way as well as
    // throwing, so that the test does not rest on the exception reaching
    // it: a scheduler that asked and caught what was thrown, as the
    // default did while it put a rejected event to the constraint against
    // an empty context, completes the league too.
    it('asks a constraint about an event of a later leg only with the first leg before it', function (int $size, ?RoleFallback $reason): void {
        $field = roleSweepField($size);
        $limit = ConsecutiveRoleConstraint::homeAway(2);
        $askedWithoutTheFirstMeeting = 0;
        $constraints = ConstraintSet::create()->custom(
            static function (Event $event, SchedulingContext $context) use ($limit, &$askedWithoutTheFirstMeeting): bool {
                if (!$limit->isSatisfied($event, $context)) {
                    return false;
                }
                if ($context->getCurrentLeg() === 1) {
                    return true;
                }

                [$first, $second] = $event->getParticipants();
                $meetings = array_values($context->getEventsBetween($first, $second));
                if ($meetings === []) {
                    ++$askedWithoutTheFirstMeeting;

                    throw new LogicException('A return meeting was put to the constraint without the first meeting.');
                }

                return $meetings[0]->getParticipants()[0]->getId() === $second->getId();
            },
            'Two in a row, and the return meeting the other way round'
        )->build();

        $underRoundParity = new RoundRobinScheduler($constraints)->schedule($field, new RoundRobinOptions(2, null, false, new RoundParityRoleAssignment()));
        $schedule = new RoundRobinScheduler($constraints)->schedule($field, new RoundRobinOptions(2));

        expect(RoleFallback::of($schedule))->toBe($reason)
            ->and($askedWithoutTheFirstMeeting)->toBe(0)
            ->and(roleSweepFaults($schedule, $field, 2, 'mirrored', RoleScheduleOracle::streakRule(2)))->toBe([]);

        if ($reason === null) {
            $underBalanced = new RoundRobinScheduler($constraints)->schedule($field, new RoundRobinOptions(2, null, false, new BalancedRoleAssignment()));

            expect($schedule->toArray())->toBe($underBalanced->toArray())
                ->and(RoleCounts::worstEndImbalance(RoleCounts::leg($schedule, 1, $size - 1)))->toBe(1);

            return;
        }

        expect(roleSweepWithoutTheMark($schedule))->toBe($underRoundParity->toArray());
    })->with([
        'eight participants: balanced' => [8, null],
        'twelve participants: the bound is reached' => [12, RoleFallback::BalancedRolesRejected],
    ]);

    // A limit of two in a row over two mirrored legs that the search of
    // roles cannot see into, in each of the forms a caller can give it: a
    // closure and a class of the caller's. (Until 0.3.0 a subclass of the
    // library's limit and a subclass of ConstraintSet were two more; both
    // classes are final now.) The roles of round parity keep it, so that
    // schedule is in hand when balanced roles are looked for, and the
    // search goes back one event at a time.
    //
    // Eight participants: the search finds balanced roles that keep the
    // limit inside the 4,000 events it has with a schedule in hand, and
    // the schedule is the one the strict role assignment gives, byte for
    // byte, without a mark. (While that search ended at the first event
    // such a constraint rejected for what came before it, each of these
    // was the schedule of round parity, marked.)
    //
    // Ten participants: the strict role assignment finds balanced roles
    // with its full bounds, and they are further than 4,000 events away.
    // The schedule is the one of round parity, and its mark says that the
    // balanced roles were rejected, not that none exist.
    //
    // One field of each kind here. The sweep this was taken from had 2 to
    // 20 participants (and 16 to 40 over two to four legs), one to four
    // legs, the three leg strategies, with and without a randomizer, and
    // 31 sets of constraints of a caller's; docs/design/role-assignment.md
    // has what it found.
    it('balances with a schedule in hand where the search finds balanced roles inside its bound, and falls back where it does not', function (
        Closure $makeConstraints,
        int $size,
        ?RoleFallback $reason
    ): void {
        $field = roleSweepField($size);
        $constraints = $makeConstraints();
        assert($constraints instanceof ConstraintSet);
        $outcome = static fn(?RoleAssignmentInterface $roleAssignment): Schedule => new RoundRobinScheduler($constraints)
            ->schedule($field, new RoundRobinOptions(2, null, false, $roleAssignment));

        $schedule = $outcome(null);
        $underRoundParity = $outcome(new RoundParityRoleAssignment());
        $underBalanced = $outcome(new BalancedRoleAssignment());

        expect(RoleFallback::of($schedule))->toBe($reason)
            ->and(roleSweepFaults($schedule, $field, 2, 'mirrored', RoleScheduleOracle::streakRule(2)))->toBe([])
            // Both role assignments have a schedule, and not the same one
            ->and(RoleCounts::worstEndImbalance(RoleCounts::leg($underBalanced, 1, $size - 1)))->toBe(1)
            ->and(RoleCounts::worstEndImbalance(RoleCounts::leg($underRoundParity, 1, $size - 1)))->toBe(3);

        if ($reason === null) {
            expect($schedule->toArray())->toBe($underBalanced->toArray());

            return;
        }

        expect(roleSweepWithoutTheMark($schedule))->toBe($underRoundParity->toArray());
    })->with([
        'a closure' => [static fn(): ConstraintSet => ConstraintSet::create()->custom(
            static fn(Event $event, SchedulingContext $context): bool => ConsecutiveRoleConstraint::homeAway(2)->isSatisfied($event, $context),
            'Two in a row, as a closure'
        )->build()],
        'a class of the caller\'s' => [static fn(): ConstraintSet => ConstraintSet::create()
            ->add(new RoleSweepLimitOfTheCallers(ConsecutiveRoleConstraint::homeAway(2)))
            ->build()],
    ])->with([
        'eight participants: balanced' => [8, null],
        'ten participants: the bound is reached' => [10, RoleFallback::BalancedRolesRejected],
    ]);

    // The library's limit beside a metadata rule, in a ConstraintSet. The
    // set is one the search cannot reason about as a whole, so round
    // parity is tried first and its schedule is in hand; but each
    // rejection is the limit's, and for those the search goes straight
    // back, as it does for the limit alone. It was never ended early, and
    // what decides here is the bound alone: sixteen participants are
    // balanced inside the 4,000 events, twenty are not, and the strict
    // role assignment finds balanced roles for both.
    it('balances under the library\'s limit beside a metadata rule as far as the bound of a search with a schedule in hand goes', function (
        int $size,
        ?RoleFallback $reason
    ): void {
        $field = [];
        for ($number = 1; $number <= $size; ++$number) {
            $field[] = new Participant("p{$number}", "P{$number}", null, ['club' => $number]);
        }
        $constraints = ConstraintSet::create()
            ->add(ConsecutiveRoleConstraint::homeAway(2))
            ->add(MetadataConstraint::requireDifferentValues('club'))
            ->build();
        $outcome = static fn(?RoleAssignmentInterface $roleAssignment): Schedule => new RoundRobinScheduler($constraints)
            ->schedule($field, new RoundRobinOptions(2, null, false, $roleAssignment));

        $schedule = $outcome(null);

        expect(RoleFallback::of($schedule))->toBe($reason)
            ->and(roleSweepFaults($schedule, $field, 2, 'mirrored', RoleScheduleOracle::streakRule(2)))->toBe([]);

        if ($reason === null) {
            expect($schedule->toArray())->toBe($outcome(new BalancedRoleAssignment())->toArray())
                ->and(RoleCounts::worstEndImbalance(RoleCounts::leg($schedule, 1, $size - 1)))->toBe(1);

            return;
        }

        expect(roleSweepWithoutTheMark($schedule))->toBe($outcome(new RoundParityRoleAssignment())->toArray())
            ->and(RoleCounts::worstEndImbalance(RoleCounts::leg($outcome(new BalancedRoleAssignment()), 1, $size - 1)))->toBe(1);
    })->with([
        'sixteen participants: balanced' => [16, null],
        'twenty participants: the bound is reached' => [20, RoleFallback::BalancedRolesRejected],
    ]);

    // A caller who names the balanced role assignment has asked for
    // balanced roles and nothing else: the same league is refused, as it
    // was, and the failure says that a bound stopped the search.
    it('still refuses that league under the balanced role assignment, named', function (): void {
        $limit = ConsecutiveRoleConstraint::homeAway(2);
        $asAClosure = ConstraintSet::create()->custom(
            static fn(Event $event, SchedulingContext $context): bool => $limit->isSatisfied($event, $context),
            'Two in a row, as a closure'
        )->build();

        expect(roleSweepOutcome($asAClosure, roleSweepField(12), 2, new MirroredLegStrategy(), false, new BalancedRoleAssignment()))
            ->toBeString()
            ->toContain('the search stopped at a bound');
    });

    // A role pinned so that no balanced schedule exists at all: round
    // parity names p3 second in all three of its events in a field of four,
    // and the rule asks for exactly that. Up to 0.2.x this completed; under
    // the strict balanced default it failed. The events are those of 0.2.x,
    // stated here and not asked of the library.
    it('completes a schedule whose pinned roles leave no balanced leg with the roles of round parity, marked', function (): void {
        $field = roleSweepField(4);
        $constraints = ConstraintSet::create()->custom(
            static fn(Event $event): bool => $event->getParticipants()[0]->getId() !== 'p3',
            'p3 is second-named'
        )->build();

        $schedule = new RoundRobinScheduler($constraints)->schedule($field);

        expect(RoleScheduleOracle::read($schedule->getEvents()))->toBe([
            [1, 'p1', 'p4'], [1, 'p2', 'p3'],
            [2, 'p2', 'p1'], [2, 'p4', 'p3'],
            [3, 'p1', 'p3'], [3, 'p4', 'p2'],
        ])
            // The rule is a closure: with the schedule of round parity in
            // hand, every choice of balanced roles in the layouts was put
            // to it, well inside the 4,000 events, and none was accepted
            ->and(RoleFallback::of($schedule))->toBe(RoleFallback::NoBalancedRoles)
            ->and($schedule->getMetadataValue(RoleFallback::METADATA_KEY))->toBe('no_balanced_roles')
            // The mark is plain data and comes back from it
            ->and(RoleFallback::of(Schedule::fromArray($schedule->toArray())))->toBe(RoleFallback::NoBalancedRoles)
            ->and(roleSweepOutcome($constraints, $field, 1, new MirroredLegStrategy(), false, new BalancedRoleAssignment()))->toBeString();
    });

    // Roles pinned as a closure, and many of them: every pairing of some
    // of the participants is held to the way round that round parity has
    // it, so round parity completes at once and the schedule is in hand.
    // The pass that fixes seatings then solves such a rule exactly, one
    // pass over the events for each seating fixed, and with a schedule in
    // hand the whole is held to 4,000 events put to the constraints.
    //
    // Few pinned pairings are solved within that, and the schedule is the
    // balanced one the strict role assignment gives. Many are not: the
    // bound ends the passes, the schedule is the one of round parity with
    // the mark that says the balanced roles were rejected and not that
    // none exist, and the strict role assignment, which has its full
    // bounds, does find balanced roles there. What the closure is asked is
    // counted: round parity's events, the balanced roles of the first
    // layout, the bound, and the rotated order that was under way when it
    // was reached, with what is asked once more about a rejected event.
    it('holds the search for balanced roles to its bound of events once a schedule is in hand', function (
        int $size,
        int $pinnedParticipants,
        ?RoleFallback $reason
    ): void {
        $field = roleSweepField($size);
        $roundParity = new RoundRobinOptions(1, null, false, new RoundParityRoleAssignment());
        $pinned = [];
        foreach (RoleScheduleOracle::read(new RoundRobinScheduler()->schedule($field, $roundParity)->getEvents()) as [, $first, $second]) {
            if ((int) substr($first, 1) <= $pinnedParticipants || (int) substr($second, 1) <= $pinnedParticipants) {
                $pinned[$first . ' v ' . $second] = true;
            }
        }

        $asked = 0;
        $constraints = ConstraintSet::create()->custom(
            static function (Event $event) use (&$asked, $pinned): bool {
                ++$asked;
                [$first, $second] = $event->getParticipants();

                return !isset($pinned[$second->getId() . ' v ' . $first->getId()]);
            },
            'Pairings the way round that round parity has them'
        )->build();

        $underRoundParity = new RoundRobinScheduler($constraints)->schedule($field, $roundParity);
        $askedUnderRoundParity = $asked;
        $asked = 0;
        $schedule = new RoundRobinScheduler($constraints)->schedule($field);
        $askedUnderTheDefault = $asked;
        $strict = roleSweepOutcome($constraints, $field, 1, new MirroredLegStrategy(), false, new BalancedRoleAssignment());

        $eventsOfALeg = intdiv($size * ($size - 1), 2);
        expect($askedUnderRoundParity)->toBe($eventsOfALeg)
            ->and(RoleFallback::of($schedule))->toBe($reason)
            // Balanced roles exist in both, and the strict role assignment finds them
            ->and($strict)->toBeInstanceOf(Schedule::class)
            ->and($askedUnderTheDefault)->toBeLessThan(
                $askedUnderRoundParity + $eventsOfALeg + 4_000 + 2 * $eventsOfALeg
            );

        if ($reason === null) {
            expect($schedule->toArray())->toBe($strict instanceof Schedule ? $strict->toArray() : null)
                ->and(RoleCounts::worstEndImbalance($schedule->getEvents()))->toBeLessThanOrEqual(1);

            return;
        }

        expect(roleSweepWithoutTheMark($schedule))->toBe($underRoundParity->toArray());
    })->with([
        'few pinned pairings: balanced within the bound' => [12, 4, null],
        'many pinned pairings: the bound ends the passes' => [20, 6, RoleFallback::BalancedRolesRejected],
    ]);

    // The bound of 4,000 events with a schedule in hand holds the search of
    // the later legs of shuffled legs as it holds every other search for
    // balanced roles. Two shuffled legs of ten participants under a rule
    // written as a closure that the later legs cannot keep after a balanced
    // first leg: an event of the last round is accepted only once some
    // participant ended the first leg more than one apart in its roles.
    // Round parity leaves participants three apart, so its schedule keeps
    // the rule; the search of the later legs after a balanced first leg
    // goes through their roles one event at a time and stops at its bound
    // of rejected roles, far past 4,000 events.
    //
    // It runs in the first layout before round parity is tried, with no
    // schedule in hand and its own bound, as BalancedRoleAssignment named
    // runs it. After round parity the first rotated order of the search for
    // balanced roles is that same layout, and would end where it ended:
    // past the 4,000 events. Nothing more is put to the constraints.
    //
    // With a second rule, which keeps the pairing that the first layout has
    // in the first round of the second leg out of that round, the first
    // layout fails at once and round parity completes in the second. The
    // search for balanced roles then goes on from the second rotated order,
    // whose later legs it searches in the 4,000 events less what the first
    // layout spent. The questions counted are the closure's, so the events
    // the search puts to it and the rejected events of each layout it asks
    // about once more, for the record, are counted alike: two for the event
    // each of the two layouts ends on.
    //
    // Under a strategy of the caller's that shuffles the same way, which the
    // scheduler does not clone,
    // the first layout is laid out again after round parity, and its later
    // legs are searched within the 4,000 events and not to their own bound.
    it('holds the search of shuffled later legs to the bound of events once a schedule is in hand', function (bool $theFirstLayoutFailsAtOnce, bool $aStrategyOfTheCallers = false): void {
        $size = 10;
        $legs = 2;
        $roundsPerLeg = $size - 1;
        $field = roleSweepField($size);
        $strategy = static fn(): LegStrategyInterface => $aStrategyOfTheCallers
            ? new RoleSweepShuffledLegsOfTheCallers(seed: 1)
            : new ShuffledLegStrategy(seed: 1);
        $keptOut = $theFirstLayoutFailsAtOnce
            ? roleSweepPairingOfTheFirstLayout($field, $legs, $strategy(), $roundsPerLeg + 1, 'p1', null)
            : [];
        /** @var list<string> $questions */
        $questions = [];
        $constraints = ConstraintSet::create()->custom(
            static function (Event $event, SchedulingContext $context) use (&$questions, $roundsPerLeg, $legs, $keptOut): bool {
                $questions[] = roleSweepQuestion($event, $context);
                $round = $event->getRound()?->getNumber();
                $pairing = array_map(static fn(Participant $participant): string => $participant->getId(), $event->getParticipants());
                sort($pairing);
                if ($round === $roundsPerLeg + 1 && $pairing === $keptOut) {
                    return false;
                }
                if ($round !== $roundsPerLeg * $legs) {
                    return true;
                }

                $differences = [];
                foreach ($context->getExistingEvents() as $existing) {
                    if (($existing->getRound()?->getNumber() ?? 0) > $roundsPerLeg) {
                        continue;
                    }
                    [$first, $second] = $existing->getParticipants();
                    $differences[$first->getId()] = ($differences[$first->getId()] ?? 0) + 1;
                    $differences[$second->getId()] = ($differences[$second->getId()] ?? 0) - 1;
                }

                return array_filter($differences, static fn(int $difference): bool => abs($difference) > 1) !== [];
            },
            'The last round once the first leg is out of balance'
        )->build();

        $underRoundParity = roleSweepOutcome($constraints, $field, $legs, $strategy(), false, new RoundParityRoleAssignment());
        $askedUnderRoundParity = $questions;
        $questions = [];
        $schedule = roleSweepOutcome($constraints, $field, $legs, $strategy(), false, null);
        $around = roleSweepAroundRoundParity($questions, $askedUnderRoundParity);

        assert($underRoundParity instanceof Schedule && $schedule instanceof Schedule && $around !== null);
        [$before, $after] = $around;
        expect(RoleFallback::of($schedule))->toBe(RoleFallback::BalancedRolesRejected)
            ->and(roleSweepWithoutTheMark($schedule))->toBe($underRoundParity->toArray());

        if ($aStrategyOfTheCallers) {
            expect(count($before))->toBeGreaterThan(4_000)
                // The first layout again, from its first question
                ->and(array_slice($after, 0, 100))->toBe(array_slice($before, 0, 100))
                // and its later legs searched within the 4,000 events
                ->and(count($after))->toBeGreaterThan(3_000)
                ->and(count($after))->toBeLessThanOrEqual(4_000 + 2);

            return;
        }

        if (!$theFirstLayoutFailsAtOnce) {
            expect(count($before))->toBeGreaterThan(4_000)
                ->and($after)->toBe([]);

            return;
        }

        expect($keptOut)->toHaveCount(2)
            ->and(count($before))->toBeLessThan(100)
            ->and(count($before) + count($after))->toBeLessThanOrEqual(4_000 + 2 + 2)
            // The search went into the later legs of the second rotated order
            ->and(count($after))->toBeGreaterThan(3_000)
            // and did not lay the first layout out again
            ->and(array_slice($after, 0, count($before)))->not->toBe($before);
    })->with([
        'the first layout spends the bound' => [false],
        'the first layout fails at once' => [true],
        'the first layout spends the bound, under a strategy of the caller\'s' => [false, true],
    ]);

    // The first rotated order of the search for balanced roles with round
    // parity's outcome in hand is the layout the balanced roles as given
    // were put to before round parity was tried, and it is not laid out
    // again. The later rotated orders still have the shuffles of the
    // randomizer and the draws of the leg strategy that they have under
    // BalancedRoleAssignment, named: here the first layout fails in the
    // first round of the second leg, after the draws of the events before,
    // and the second rotated order completes with balanced roles, the same
    // schedule as the strict role assignment's.
    it('goes on from the second rotated order after round parity, with the draws BalancedRoleAssignment has there', function (
        Closure $makeStrategy,
        int $legs,
        ?int $seed
    ): void {
        $size = 8;
        $roundsPerLeg = $size - 1;
        $field = roleSweepField($size);
        $keptOut = roleSweepPairingOfTheFirstLayout($field, $legs, $makeStrategy(), $roundsPerLeg + 1, 'p1', $seed);
        /** @var list<string> $questions */
        $questions = [];
        $constraints = ConstraintSet::create()->custom(
            static function (Event $event, SchedulingContext $context) use (&$questions, $roundsPerLeg, $keptOut): bool {
                $questions[] = roleSweepQuestion($event, $context);
                $pairing = array_map(static fn(Participant $participant): string => $participant->getId(), $event->getParticipants());
                sort($pairing);

                return $event->getRound()?->getNumber() !== $roundsPerLeg + 1 || $pairing !== $keptOut;
            },
            'One pairing kept out of the first round of the second leg'
        )->build();

        roleSweepOutcome($constraints, $field, $legs, $makeStrategy(), false, new RoundParityRoleAssignment(), $seed);
        $askedUnderRoundParity = $questions;
        $questions = [];
        $schedule = roleSweepOutcome($constraints, $field, $legs, $makeStrategy(), false, null, $seed);
        $around = roleSweepAroundRoundParity($questions, $askedUnderRoundParity);
        $strict = roleSweepOutcome($constraints, $field, $legs, $makeStrategy(), false, new BalancedRoleAssignment(), $seed);

        assert($schedule instanceof Schedule && $strict instanceof Schedule && $around !== null);
        [$before, $after] = $around;
        expect($keptOut)->toHaveCount(2)
            ->and(RoleFallback::of($schedule))->toBeNull()
            ->and($schedule->toArray())->toBe($strict->toArray())
            ->and($before)->not->toBe([])
            ->and(array_slice($after, 0, count($before)))->not->toBe($before);
    })->with([
        'two shuffled legs from a seed' => [static fn(): ShuffledLegStrategy => new ShuffledLegStrategy(seed: 3), 2, null],
        'three shuffled legs from a seed, with a randomizer' => [static fn(): ShuffledLegStrategy => new ShuffledLegStrategy(seed: 8), 3, 11],
        'two mirrored legs, with a randomizer' => [static fn(): MirroredLegStrategy => new MirroredLegStrategy(), 2, 5],
        'two repeated legs' => [static fn(): RepeatedLegStrategy => new RepeatedLegStrategy(), 2, null],
    ]);

    // The same where round parity fails too, and the search for balanced
    // roles that follows has its full bounds: three participants, two legs,
    // the third never first-named (a closure) and the two top seeds kept
    // apart in the first round (SeedProtectionConstraint). The first layout
    // seats the top seeds together in its second round, which the seed rule
    // rejects whatever the roles. Neither the rotated orders after round
    // parity nor the search of first-leg roles that follows them lays that
    // layout out again: its first question is never asked after round
    // parity's. The call fails as round parity fails.
    it('lays the first layout out once before round parity and not again after it, where round parity fails', function (Closure $makeStrategy): void {
        $field = [new Participant('p1', 'P1', 1), new Participant('p2', 'P2', 2), new Participant('p3', 'P3', 3)];
        /** @var list<string> $questions */
        $questions = [];
        $constraints = ConstraintSet::create()->custom(
            static function (Event $event, SchedulingContext $context) use (&$questions): bool {
                $questions[] = roleSweepQuestion($event, $context);

                return $event->getParticipants()[0]->getId() !== 'p3';
            },
            'p3 is never first-named'
        )->add(new SeedProtectionConstraint(2, 0.5))->build();

        $underRoundParity = roleSweepOutcome($constraints, $field, 2, $makeStrategy(), false, new RoundParityRoleAssignment());
        $askedUnderRoundParity = $questions;
        $questions = [];
        $underTheDefault = roleSweepOutcome($constraints, $field, 2, $makeStrategy(), false, null);
        $around = roleSweepAroundRoundParity($questions, $askedUnderRoundParity);

        assert($around !== null);
        [$before, $after] = $around;
        expect($underTheDefault)->toBeString()->toBe($underRoundParity)
            ->and($before)->not->toBe([])
            ->and($after)->not->toBe([])
            ->and($after)->not->toContain($before[0]);
    })->with([
        'mirrored legs' => [static fn(): MirroredLegStrategy => new MirroredLegStrategy()],
        'repeated legs' => [static fn(): RepeatedLegStrategy => new RepeatedLegStrategy()],
        'shuffled legs from a seed' => [static fn(): ShuffledLegStrategy => new ShuffledLegStrategy(seed: 2)],
    ]);

    // The smallest field. A leg of two participants is one event, and it
    // is balanced whichever of them is first-named, so a schedule of two
    // never carries the mark: where the rule names one of them second, the
    // balanced roles as given are rejected in the first layout, the roles
    // of round parity complete in the second, and the search for balanced
    // roles that follows comes to the same schedule and returns it without
    // a mark. Mirrored legs name each of the two first in turn, which the
    // rule refuses with any roles: the failure is the one of round parity.
    it('never marks a schedule of two participants, whose every leg is balanced', function (string $neverFirst): void {
        $field = roleSweepField(2);
        $constraints = ConstraintSet::create()->custom(
            static fn(Event $event): bool => $event->getParticipants()[0]->getId() !== $neverFirst,
            "{$neverFirst} is second-named"
        )->build();
        $other = $neverFirst === 'p1' ? 'p2' : 'p1';
        $completed = 0;

        foreach ([1, 2, 3] as $legs) {
            foreach ([new MirroredLegStrategy(), new RepeatedLegStrategy(), new ShuffledLegStrategy(seed: 4)] as $strategy) {
                $underTheDefault = roleSweepOutcome($constraints, $field, $legs, $strategy, false, null);
                $underRoundParity = roleSweepOutcome($constraints, $field, $legs, $strategy, false, new RoundParityRoleAssignment());

                if ($legs > 1 && $strategy instanceof MirroredLegStrategy) {
                    expect($underTheDefault)->toBeString()->toBe($underRoundParity);

                    continue;
                }

                assert($underTheDefault instanceof Schedule && $underRoundParity instanceof Schedule);
                expect(RoleFallback::of($underTheDefault))->toBeNull()
                    ->and($underTheDefault->toArray())->toBe($underRoundParity->toArray())
                    ->and(RoleScheduleOracle::read($underTheDefault->getEvents()))->toBe(array_map(
                        static fn(int $round): array => [$round, $other, $neverFirst],
                        range(1, $legs)
                    ));
                ++$completed;
            }
        }

        expect($completed)->toBe(7);
    })->with(['p1', 'p2']);

    // The two reasons of a fall-back under constraints the search of roles
    // reasons about exactly: every choice was tried, or a bound stopped the
    // search. No configuration of the library's built-in role limits was
    // found that falls back at all (the sweeps recorded in
    // docs/design/role-assignment.md), so the rule here is made for the
    // purpose from one of the library's classes: a ConsecutiveRoleConstraint
    // whose extractor gives every other participant the same "role" in each
    // of its events but the one against a chosen participant in which that
    // participant has the role round parity gives it. With a limit of one
    // fewer than a participant plays, that says: the chosen participant has
    // its round-parity role against everyone. It is the participant round
    // parity leaves 3 apart, so round parity keeps the rule and no balanced
    // leg does.
    //
    // With four participants the search goes through every balanced leg,
    // with and without backtracking. With six it meets each rejection at an
    // opponent's last event, far from the event that matters, and stops at
    // its bound: 0.8 s before the fall-back, which is what the order of the
    // attempts costs under such constraints and is not hidden.
    it('says whether every choice was tried or a bound stopped the search, under a constraint of the library\'s own classes', function (
        int $size,
        bool $backtracking,
        RoleFallback $reason,
        string $whatTheStrictRoleAssignmentSays
    ): void {
        $field = roleSweepField($size);
        $underRoundParity = new RoundRobinScheduler()->schedule($field, new RoundRobinOptions(roleAssignment: new RoundParityRoleAssignment()));
        $differences = RoleCounts::differences($underRoundParity);
        $chosen = (string) array_search(3, array_map(abs(...), $differences), true);
        /** @var array<string, bool> $chosenIsFirstAgainst */
        $chosenIsFirstAgainst = [];
        foreach (RoleScheduleOracle::read($underRoundParity->getEvents()) as [, $first, $second]) {
            if ($first === $chosen) {
                $chosenIsFirstAgainst[$second] = true;
            } elseif ($second === $chosen) {
                $chosenIsFirstAgainst[$first] = false;
            }
        }

        $constraints = ConstraintSet::create()->add(new ConsecutiveRoleConstraint(
            $size - 2,
            static function (Event $event, Participant $participant) use ($chosen, $chosenIsFirstAgainst): string {
                [$first, $second] = $event->getParticipants();
                $round = (string) $event->getRound()?->getNumber();
                if ($participant->getId() === $chosen) {
                    return "the chosen participant, round {$round}";
                }
                if ($first->getId() !== $chosen && $second->getId() !== $chosen) {
                    return 'elsewhere';
                }

                return $chosenIsFirstAgainst[$participant->getId()] === ($first->getId() === $chosen)
                    ? "as round parity has it, round {$round}"
                    : 'elsewhere';
            },
            'The roles round parity gives one participant'
        ))->build();

        expect(count($chosenIsFirstAgainst))->toBe($size - 1);

        $schedule = new RoundRobinScheduler($constraints)->schedule($field, new RoundRobinOptions(backtracking: $backtracking));

        expect(RoleFallback::of($schedule))->toBe($reason)
            ->and(roleSweepWithoutTheMark($schedule))->toBe($underRoundParity->toArray())
            ->and(roleSweepOutcome($constraints, $field, 1, new MirroredLegStrategy(), $backtracking, new BalancedRoleAssignment()))
            ->toBeString()
            ->toContain($whatTheStrictRoleAssignmentSays);
    })->with([
        'four participants: every choice tried' => [4, false, RoleFallback::NoBalancedRoles, 'no choice of them has a schedule the constraints accept'],
        'four participants, with backtracking: every choice tried' => [4, true, RoleFallback::NoBalancedRoles, 'so the configuration is unsatisfiable with this leg strategy and this role assignment'],
        'six participants: a bound' => [6, false, RoleFallback::SearchStoppedAtBound, 'the search stopped at a bound'],
    ]);

    // The same kind of rule over several legs that are drawn. In a field
    // of five round parity names the last participant second in three of
    // its four events of a leg, and the rule holds it to those roles
    // against every opponent, in every leg: no balanced first leg keeps
    // it, in any layout, and the search goes through all of them before
    // round parity is tried. The later legs are then the leg strategy's
    // draws, searched where the rule rejects them, and the schedule is the
    // one RoundParityRoleAssignment gives for that seed of the strategy,
    // the drawn roles included; the strict role assignment fails.
    it('falls back to the schedule of round parity under shuffled legs drawn from a seed, where every choice of balanced roles was tried', function (
        int $legs,
        int $strategySeed
    ): void {
        $field = roleSweepField(5);
        $chosen = 'p5';
        $roundParity = new RoundParityRoleAssignment();
        /** @var array<string, bool> $chosenIsFirstAgainst */
        $chosenIsFirstAgainst = [];
        foreach (RoleScheduleOracle::read(new RoundRobinScheduler()->schedule($field, new RoundRobinOptions(1, null, false, $roundParity))->getEvents()) as [, $first, $second]) {
            if ($first === $chosen) {
                $chosenIsFirstAgainst[$second] = true;
            } elseif ($second === $chosen) {
                $chosenIsFirstAgainst[$first] = false;
            }
        }

        $constraints = ConstraintSet::create()->add(new ConsecutiveRoleConstraint(
            3,
            static function (Event $event, Participant $participant) use ($chosen, $chosenIsFirstAgainst): string {
                [$first, $second] = $event->getParticipants();
                $round = (string) $event->getRound()?->getNumber();
                if ($participant->getId() === $chosen) {
                    return "the chosen participant, round {$round}";
                }
                if ($first->getId() !== $chosen && $second->getId() !== $chosen) {
                    return 'elsewhere';
                }

                return $chosenIsFirstAgainst[$participant->getId()] === ($first->getId() === $chosen)
                    ? "as round parity has it, round {$round}"
                    : 'elsewhere';
            },
            'The roles round parity gives one participant'
        ))->build();
        $options = static fn(?RoleAssignmentInterface $roleAssignment): RoundRobinOptions => new RoundRobinOptions(
            $legs,
            new ShuffledLegStrategy(seed: $strategySeed),
            false,
            $roleAssignment
        );

        $underRoundParity = new RoundRobinScheduler($constraints)->schedule($field, $options($roundParity));
        $schedule = new RoundRobinScheduler($constraints)->schedule($field, $options(null));

        expect($chosenIsFirstAgainst)->toHaveCount(4)
            ->and(array_filter($chosenIsFirstAgainst))->toHaveCount(1)
            ->and(RoleFallback::of($schedule))->toBe(RoleFallback::NoBalancedRoles)
            ->and(roleSweepWithoutTheMark($schedule))->toBe($underRoundParity->toArray())
            ->and(roleSweepOutcome($constraints, $field, $legs, new ShuffledLegStrategy(seed: $strategySeed), false, new BalancedRoleAssignment()))
            ->toBeString()
            ->toContain('no choice of them has a schedule the constraints accept');
    })->with([
        'two legs' => [2, 1],
        'three legs' => [3, 5],
        'two legs, other draws' => [2, 9],
    ]);

    // A failure of the default is the failure of round parity in every
    // part: the message, the rejections recorded and the analysis. Two legs
    // in which no pair may meet twice fail in every rotated order whatever
    // the roles, which the balanced run finds out first; the round-parity
    // run is then spared those layouts but the last, whose failure is the
    // one reported.
    it('fails as round parity fails where no roles help, with its message, rejections and analysis', function (?int $seed): void {
        $field = roleSweepField(8);
        $failure = static function (?RoleAssignmentInterface $roleAssignment) use ($field, $seed): IncompleteScheduleException {
            try {
                new RoundRobinScheduler(
                    ConstraintSet::create()->add(new NoRepeatPairings(acrossLegs: true))->build(),
                    $seed === null ? null : new Randomizer(new Mt19937($seed))
                )->schedule($field, new RoundRobinOptions(2, null, false, $roleAssignment));
            } catch (IncompleteScheduleException $exception) {
                return $exception;
            }

            throw new LogicException('The schedule was generated.');
        };

        $underTheDefault = $failure(null);
        $underRoundParity = $failure(new RoundParityRoleAssignment());

        expect($underTheDefault->getMessage())->toBe($underRoundParity->getMessage())
            ->and($underTheDefault->getMessage())->toBe('Failed to generate complete schedule for leg 2. Generated 0 events, expected 28. Constraints may be preventing complete schedule generation.')
            ->and($underTheDefault->getActualEventCount())->toBe($underRoundParity->getActualEventCount())
            ->and($underTheDefault->getViolationCollector()->getViolationCountsByConstraint())->toBe($underRoundParity->getViolationCollector()->getViolationCountsByConstraint())
            ->and($underTheDefault->getAnalysis()?->toString())->toBe($underRoundParity->getAnalysis()?->toString())
            ->and($underTheDefault->getAnalysis())->not->toBeNull()
            ->and($underTheDefault->getPrevious())->toBeNull();

        // The events that were rejected are those of the same layout: under
        // a randomizer, the last shuffle round parity comes to
        $rejected = static fn(IncompleteScheduleException $exception): array => array_map(
            static fn($violation): string => $violation->roundNumber . ': ' . implode(' v ', array_map(
                static fn(Participant $participant): string => $participant->getId(),
                $violation->affectedParticipants
            )),
            $exception->getViolationCollector()->getViolations()
        );

        expect($rejected($underTheDefault))->toBe($rejected($underRoundParity))
            ->and($rejected($underTheDefault))->not->toBe([]);
    })->with([[null], [3]]);

    // The same under a leg strategy that draws from a seed. A pair that
    // may not meet again within more rounds than a leg has fails the
    // second leg in every rotated order, whatever the roles, and the roles
    // the strategy proposes there are its draws: round parity, named, lays
    // every rotated order out, so the last one comes to the draws that the
    // orders before it left. The round-parity run of the default has to
    // come to the same ones, so here it is spared no layout: the events
    // rejected in the failure are named the same way round, in the same
    // order, as round parity names them.
    it('fails as round parity fails under shuffled legs drawn from a seed, down to the roles of the rejected events', function (?int $seed): void {
        $compared = 0;

        foreach ([3, 4, 5, 6] as $size) {
            foreach ([1, 2, 3, 4, 5, 6] as $strategySeed) {
                $field = roleSweepField($size);
                $failure = static function (?RoleAssignmentInterface $roleAssignment) use ($field, $size, $seed, $strategySeed): IncompleteScheduleException {
                    try {
                        new RoundRobinScheduler(
                            ConstraintSet::create()->add(new MinimumRestPeriodsConstraint($size + 1))->build(),
                            $seed === null ? null : new Randomizer(new Mt19937($seed))
                        )->schedule($field, new RoundRobinOptions(2, new ShuffledLegStrategy(seed: $strategySeed), false, $roleAssignment));
                    } catch (IncompleteScheduleException $exception) {
                        return $exception;
                    }

                    throw new LogicException('The schedule was generated.');
                };
                $rejected = static fn(IncompleteScheduleException $exception): array => array_map(
                    static fn($violation): string => $violation->roundNumber . ': ' . implode(' v ', array_map(
                        static fn(Participant $participant): string => $participant->getId(),
                        $violation->rejectedEvent->getParticipants()
                    )),
                    $exception->getViolationCollector()->getViolations()
                );

                $underTheDefault = $failure(null);
                $underRoundParity = $failure(new RoundParityRoleAssignment());

                expect($rejected($underTheDefault))->toBe($rejected($underRoundParity), "{$size} participants, strategy seed {$strategySeed}")
                    ->and($rejected($underTheDefault))->not->toBe([])
                    ->and($underTheDefault->getMessage())->toBe($underRoundParity->getMessage())
                    ->and($underTheDefault->getActualEventCount())->toBe($underRoundParity->getActualEventCount())
                    ->and($underTheDefault->getAnalysis()?->toString())->toBe($underRoundParity->getAnalysis()?->toString());
                ++$compared;
            }
        }

        expect($compared)->toBe(24);
    })->with([[null], [3]]);

    // Same input, same schedule: from two schedulers, from one scheduler
    // asked twice, after a call that failed, from one options object (and
    // with it one leg strategy object) shared by two schedulers, and from
    // two randomizers seeded the same. The default runs the generation up
    // to three times in a call, and each run has to start where a call
    // starts: the shuffles of the randomizer are read again from the first,
    // and a leg strategy that draws from a seed starts its draws again.
    it('gives the same schedule for the same input, however often it is asked and whatever it was asked before', function (
        Closure $makeConstraints,
        int $size,
        Closure $makeOptions,
        ?RoleFallback $mark,
        Closure $failingCall
    ): void {
        $field = roleSweepField($size);
        $options = $makeOptions();
        assert($options instanceof RoundRobinOptions);
        $scheduler = static function (?int $seed = null) use ($makeConstraints): RoundRobinScheduler {
            $constraints = $makeConstraints();
            assert($constraints instanceof ConstraintSet);

            return new RoundRobinScheduler($constraints, $seed === null ? null : new Randomizer(new Mt19937($seed)));
        };

        $first = $scheduler();
        $schedule = $first->schedule($field, $options);
        $json = $schedule->toJson();

        expect(RoleFallback::of($schedule))->toBe($mark)
            // Asked again
            ->and($first->schedule($field, $options)->toJson())->toBe($json)
            // Another scheduler, the same options object
            ->and($scheduler()->schedule($field, $options)->toJson())->toBe($json)
            // Another scheduler, options made anew
            ->and($scheduler()->schedule($field, $makeOptions())->toJson())->toBe($json);

        // After a call that failed in the middle of its searches
        $failed = false;
        try {
            $failingCall($first, $options);
        } catch (IncompleteScheduleException) {
            $failed = true;
        }
        expect($failed)->toBeTrue()
            ->and($first->schedule($field, $options)->toJson())->toBe($json);

        // Under a randomizer: two schedulers seeded the same give the same
        // two answers, one after the other (a shuffle may leave the rule no
        // schedule, and then the answer is the failure), and a schedule is
        // what the role assignment it ended on gives from that seed
        $answer = static function (RoundRobinScheduler $scheduler, RoundRobinOptions $options) use ($field): Schedule|string {
            try {
                return $scheduler->schedule($field, $options);
            } catch (IncompleteScheduleException $failure) {
                return $failure->getMessage();
            }
        };
        $asText = static fn(Schedule|string $answer): string => $answer instanceof Schedule ? $answer->toJson() : $answer;
        $schedulesUnderASeed = 0;

        foreach ([5, 11, 12] as $seed) {
            $one = $scheduler($seed);
            $other = $scheduler($seed);
            $seeded = $answer($one, $options);

            expect($asText($answer($other, $options)))->toBe($asText($seeded))
                ->and($asText($answer($other, $options)))->toBe($asText($answer($one, $options)));

            if (!$seeded instanceof Schedule) {
                continue;
            }

            ++$schedulesUnderASeed;
            $named = RoleFallback::of($seeded) === null ? new BalancedRoleAssignment() : new RoundParityRoleAssignment();
            $underTheOneItEndedOn = $scheduler($seed)->schedule(
                $field,
                new RoundRobinOptions($options->legs, $options->strategy, $options->backtracking, $named)
            );
            expect(roleSweepWithoutTheMark($seeded))->toBe($underTheOneItEndedOn->toArray());
        }

        expect($schedulesUnderASeed)->toBeGreaterThan(0);
    })->with([
        'balanced roles that a search with a schedule in hand found' => [
            static fn(): ConstraintSet => ConstraintSet::create()->custom(
                static fn(Event $event, SchedulingContext $context): bool => ConsecutiveRoleConstraint::homeAway(2)->isSatisfied($event, $context),
                'Two in a row, as a closure'
            )->build(),
            8,
            static fn(): RoundRobinOptions => new RoundRobinOptions(2),
            null,
            // Four participants have no two mirrored legs within the limit, with any roles
            static fn(RoundRobinScheduler $scheduler, RoundRobinOptions $options): Schedule => $scheduler->schedule(roleSweepField(4), $options),
        ],
        'a fall-back where that search spent its events' => [
            static fn(): ConstraintSet => ConstraintSet::create()->custom(
                static fn(Event $event, SchedulingContext $context): bool => ConsecutiveRoleConstraint::homeAway(2)->isSatisfied($event, $context),
                'Two in a row, as a closure'
            )->build(),
            12,
            static fn(): RoundRobinOptions => new RoundRobinOptions(2),
            RoleFallback::BalancedRolesRejected,
            static fn(RoundRobinScheduler $scheduler, RoundRobinOptions $options): Schedule => $scheduler->schedule(roleSweepField(4), $options),
        ],
        'balanced roles that a search found' => [
            static fn(): ConstraintSet => ConstraintSet::create()->add(ConsecutiveRoleConstraint::homeAway(2))->build(),
            8,
            static fn(): RoundRobinOptions => new RoundRobinOptions(2),
            null,
            static fn(RoundRobinScheduler $scheduler, RoundRobinOptions $options): Schedule => $scheduler->schedule(roleSweepField(4), $options),
        ],
        'a fall-back under shuffled legs drawn from a seed' => [
            static fn(): ConstraintSet => ConstraintSet::create()->custom(
                static fn(Event $event): bool => ($event->getRound()?->getNumber() ?? 0) > 3 || $event->getParticipants()[0]->getId() !== 'p3',
                'p3 is second-named in the first leg'
            )->build(),
            4,
            static fn(): RoundRobinOptions => new RoundRobinOptions(3, new ShuffledLegStrategy(seed: 9)),
            RoleFallback::NoBalancedRoles,
            // No balanced leg keeps the rule: the strict role assignment fails after its search
            static fn(RoundRobinScheduler $scheduler, RoundRobinOptions $options): Schedule => $scheduler->schedule(
                roleSweepField(4),
                new RoundRobinOptions($options->legs, $options->strategy, false, new BalancedRoleAssignment())
            ),
        ],
    ]);
});
