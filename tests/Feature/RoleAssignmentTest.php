<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\ConsecutiveRoleConstraint;
use MissionGaming\Tactician\Constraints\ConstraintInterface;
use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Constraints\RoleBalanceConstraint;
use MissionGaming\Tactician\Constraints\SeedProtectionConstraint;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\LegStrategies\LegStrategyInterface;
use MissionGaming\Tactician\LegStrategies\MirroredLegStrategy;
use MissionGaming\Tactician\LegStrategies\RepeatedLegStrategy;
use MissionGaming\Tactician\LegStrategies\ShuffledLegStrategy;
use MissionGaming\Tactician\Quality\RoleBalanceMetric;
use MissionGaming\Tactician\Quality\RoleStreakMetric;
use MissionGaming\Tactician\RoleAssignment\BalancedRoleAssignment;
use MissionGaming\Tactician\RoleAssignment\BalancedWherePossibleRoleAssignment;
use MissionGaming\Tactician\RoleAssignment\RoleAssignmentContext;
use MissionGaming\Tactician\RoleAssignment\RoleAssignmentInterface;
use MissionGaming\Tactician\RoleAssignment\RoleFallback;
use MissionGaming\Tactician\RoleAssignment\RoundParityRoleAssignment;
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Tests\Support\RoleCounts;
use PHPUnit\Framework\Assert;
use Random\Engine\Mt19937;
use Random\Randomizer;

// What these tests hold the role assignments to.
//
// The default (round parity) is pinned as it is in
// tests/Feature/DefaultRoleAssignmentTest.php, so a change to the default
// cannot pass unnoticed.
//
// The balanced assignment is held to bounds that are counted in the events
// it produced (tests/Support/RoleCounts.php), never taken from the library's
// own counters. For n = 2..30:
//
// - One leg: every participant ends at most 1 apart in a field of even size
//   (it plays n - 1 events, an odd number, so 1 is the least possible) and
//   exactly 0 apart in a field of odd size. The pairings and their rounds
//   are the ones the default produces.
// - Legs 1..6, mirrored and repeated: every leg on its own meets the bound
//   of one leg. The whole schedule follows from what the leg strategy does
//   with the roles of leg 1, which the role assignment does not alter.
// - Shuffled: leg 1 meets the bound. The later legs are random by contract.
//
// The backtracking search is driven in two ways: by a fixture placement that
// no rotation satisfies (fields of 4 to 6), and by fixed roles that neither
// the default nor the balanced roles of any rotation satisfy (fields of 8, 9
// and 12).

/**
 * @return list<Participant>
 */
function roleField(int $count): array
{
    $participants = [];
    for ($i = 1; $i <= $count; ++$i) {
        $participants[] = new Participant("p{$i}", "Participant {$i}", $i);
    }

    return $participants;
}

/**
 * @throws UnexpectedValueException When the name is not one of the three built-in strategies
 * @throws InvalidConfigurationException Never: the shuffled strategy is given a randomizer
 */
function roleLegStrategy(string $name): LegStrategyInterface
{
    return match ($name) {
        'mirrored' => new MirroredLegStrategy(),
        'repeated' => new RepeatedLegStrategy(),
        'shuffled' => new ShuffledLegStrategy(new Randomizer(new Mt19937(2024))),
        default => throw new UnexpectedValueException($name),
    };
}

/**
 * Every structural rule of a round robin, checked on the events: each
 * pairing is played once in every leg, nobody plays twice in a round, and in
 * a field of odd size the one participant a round leaves out is the one the
 * `byes` metadata names.
 *
 * The rules are checked in plain PHP and asserted once, so that a sweep over
 * hundreds of schedules does not spend its time counting assertions.
 *
 * @throws PHPUnit\Framework\ExpectationFailedException When a rule is broken
 */
function assertRoundRobinStructure(Schedule $schedule, int $size, int $legs, string $label = ''): void
{
    $roundsPerLeg = $size % 2 === 0 ? $size - 1 : $size;
    $pairings = intdiv($size * ($size - 1), 2);
    $faults = [];

    if (count($schedule) !== $pairings * $legs) {
        $faults[] = count($schedule) . ' events, expected ' . $pairings * $legs;
    }

    $meetings = [];
    $playing = [];
    foreach ($schedule as $event) {
        $participants = $event->getParticipants();
        if (count($participants) !== 2 || $participants[0]->getId() === $participants[1]->getId()) {
            $faults[] = 'an event is not a pairing of two participants';

            continue;
        }

        $ids = [$participants[0]->getId(), $participants[1]->getId()];
        $round = $event->getRound()?->getNumber() ?? 0;
        if ($round < 1 || $round > $roundsPerLeg * $legs) {
            $faults[] = "round {$round} is outside the schedule";
        }

        $leg = intdiv($round - 1, $roundsPerLeg) + 1;
        $pairing = strcmp($ids[0], $ids[1]) < 0 ? "{$ids[0]} + {$ids[1]}" : "{$ids[1]} + {$ids[0]}";
        if (isset($meetings[$leg][$pairing])) {
            $faults[] = "{$pairing} is played twice in leg {$leg}";
        }
        $meetings[$leg][$pairing] = true;

        foreach ($ids as $id) {
            if (isset($playing[$round][$id])) {
                $faults[] = "{$id} plays twice in round {$round}";
            }
            $playing[$round][$id] = true;
        }
    }

    for ($leg = 1; $leg <= $legs; ++$leg) {
        if (count($meetings[$leg] ?? []) !== $pairings) {
            $faults[] = "leg {$leg} does not hold every pairing";
        }
    }

    $byes = $schedule->getMetadataValue('byes');
    if (!is_array($byes)) {
        $faults[] = 'the byes metadata is not an array';
    } elseif ($size % 2 === 0) {
        if ($byes !== []) {
            $faults[] = 'a field of even size has byes';
        }
    } else {
        if (array_keys($byes) !== range(1, $roundsPerLeg * $legs)) {
            $faults[] = 'the byes metadata does not name one participant for every round';
        }
        foreach ($byes as $round => $resting) {
            if (count($playing[$round] ?? []) !== $size - 1 || !is_string($resting) || isset($playing[$round][$resting])) {
                $faults[] = "the bye of round {$round} is not the one participant the round leaves out";
            }
        }
    }

    Assert::assertSame([], $faults, $label);
}

/**
 * The fixture placement that no rotation of the circle method satisfies and
 * the backtracking search does: each `a + b` pairing may only be played in
 * the leg round it maps to.
 *
 * @param array<string, int> $roundByPairing
 * @return Closure(Event): bool
 */
function rolePlacementConstraint(array $roundByPairing): Closure
{
    return static function (Event $event) use ($roundByPairing): bool {
        $structure = RoleCounts::structure([$event])[0];
        [$round, $pairing] = explode(': ', $structure);

        return !isset($roundByPairing[$pairing]) || $roundByPairing[$pairing] === (int) $round;
    };
}

/**
 * Roles fixed for some pairings: `'p2|p7'` means that p2 is first-named
 * when it meets p7. A rule about roles only, so it holds in whichever round
 * the pairing is played.
 *
 * @param array<string> $firstNamed
 */
function roleFixedRolesConstraint(array $firstNamed): ConstraintSet
{
    return ConstraintSet::create()->custom(
        static function (Event $event) use ($firstNamed): bool {
            [$first, $second] = $event->getParticipants();

            return !in_array($second->getId() . '|' . $first->getId(), $firstNamed, true);
        },
        'Fixed Roles'
    )->build();
}

describe('the default role assignment', function (): void {
    // Balanced where possible since 0.3.0 (issue #51). Without constraints
    // that is balanced, and the schedule of the balanced role assignment
    // byte for byte, with no mark. Four participants tell balanced roles
    // from round parity: round parity names p3 second in all three of its
    // events.
    it('gives the balanced roles, for options that name no role assignment', function (): void {
        $named = RoundRobinOptions::fromArray(['role_assignment' => 'balanced']);
        $default = RoundRobinOptions::fromArray(['role_assignment' => 'balanced_where_possible']);

        foreach ([null, new RoundRobinOptions(), RoundRobinOptions::fromArray([]), RoundRobinOptions::fromArray(['legs' => 1]), $default, $named] as $options) {
            $schedule = (new RoundRobinScheduler())->schedule(roleField(4), $options);

            expect(RoleCounts::worstEndImbalance($schedule))->toBe(1)
                ->and($schedule->toJson())->toBe((new RoundRobinScheduler())->schedule(roleField(4), $named)->toJson())
                ->and(RoleFallback::of($schedule))->toBeNull()
                ->and($schedule->hasMetadata(RoleFallback::METADATA_KEY))->toBeFalse();
        }

        expect((new RoundRobinOptions())->roleAssignment)->toBeInstanceOf(BalancedWherePossibleRoleAssignment::class);
    });

    it('leaves the round-parity roles to options that name them', function (): void {
        foreach ([RoundRobinOptions::fromArray(['role_assignment' => 'round_parity']), new RoundRobinOptions(roleAssignment: new RoundParityRoleAssignment())] as $options) {
            $schedule = (new RoundRobinScheduler())->schedule(roleField(4), $options);

            expect(RoleCounts::differences($schedule)['p3'])->toBe(-3);
        }
    });
});

describe('the balanced role assignment', function (): void {
    // No constraint is set, so the backtracking search never runs here and
    // the option is left out: the search is driven by the constrained cases
    // further down.
    it('meets the bound of every leg and of the whole schedule', function (
        string $strategyName,
        bool $seeded
    ): void {
        for ($size = 2; $size <= 30; ++$size) {
            for ($legs = 1; $legs <= 6; ++$legs) {
                $label = "{$strategyName} n={$size} legs={$legs}";
                $randomizer = $seeded ? new Randomizer(new Mt19937(1000 * $size + $legs)) : null;

                $schedule = (new RoundRobinScheduler(null, $randomizer))->schedule(
                    roleField($size),
                    new RoundRobinOptions($legs, roleLegStrategy($strategyName), false, new BalancedRoleAssignment())
                );

                assertRoundRobinStructure($schedule, $size, $legs, $label);

                $events = $schedule->getEvents();
                $roundsPerLeg = $size % 2 === 0 ? $size - 1 : $size;
                // The least a leg allows: n - 1 events each, odd in an even field
                $legBound = $size % 2 === 0 ? 1 : 0;

                $legsHeldToTheBound = $strategyName === 'shuffled' ? 1 : $legs;
                for ($leg = 1; $leg <= $legsHeldToTheBound; ++$leg) {
                    $differences = RoleCounts::differences(RoleCounts::leg($events, $leg, $roundsPerLeg));

                    // Every participant, and every one of them exactly at the bound
                    Assert::assertSame(
                        array_fill(0, $size, $legBound),
                        array_map(abs(...), array_values($differences)),
                        "{$label}, leg {$leg}"
                    );
                }

                if ($strategyName === 'shuffled') {
                    continue;
                }

                $worst = RoleCounts::worstEndImbalance($events);

                if ($size % 2 === 1) {
                    // Every leg is exactly balanced, so their sum is
                    Assert::assertSame(0, $worst, $label);

                    continue;
                }

                if ($seeded) {
                    // The scheduler shuffles the first leg only, so the legs
                    // are balanced one by one and can add up. The mirrored
                    // legs after the first alternate, so they add up to
                    // nothing where there is an even number of them and to
                    // one leg's worth otherwise
                    $bound = $strategyName === 'mirrored' ? 2 - $legs % 2 : $legs;
                    Assert::assertLessThanOrEqual($bound, $worst, $label);

                    continue;
                }

                // Mirrored: the odd-numbered legs one way, the even-numbered
                // legs the other way, so each pairing is split evenly over
                // an even number of legs and one apart over an odd number.
                // Repeated: the same roles in every leg.
                $pairingSplit = $strategyName === 'mirrored' ? $legs % 2 : $legs;

                Assert::assertSame($pairingSplit, $worst, $label);
                Assert::assertSame([$pairingSplit], array_values(array_unique(RoleCounts::pairingSplits($events))), $label);
            }
        }
    })
        ->with([['mirrored'], ['repeated'], ['shuffled']])
        ->with([[false], [true]]);

    it('changes nothing but roles in a single leg, and no participant drifts further or repeats a role longer', function (
        ?int $seed
    ): void {
        $roleBalance = new RoleBalanceMetric();
        $roleStreaks = new RoleStreakMetric();

        for ($size = 2; $size <= 30; ++$size) {
            $randomizer = static fn(): ?Randomizer => $seed === null ? null : new Randomizer(new Mt19937($seed + $size));

            $roundParity = (new RoundRobinScheduler(null, $randomizer()))->schedule(
                roleField($size),
                new RoundRobinOptions(roleAssignment: new RoundParityRoleAssignment())
            );
            $balanced = (new RoundRobinScheduler(null, $randomizer()))->schedule(
                roleField($size),
                new RoundRobinOptions(roleAssignment: new BalancedRoleAssignment())
            );

            // The same pairings in the same rounds in the same order, the
            // same byes: only who is first-named differs
            Assert::assertSame(RoleCounts::structure($roundParity), RoleCounts::structure($balanced), "n={$size}");
            Assert::assertSame($roundParity->getMetadata(), $balanced->getMetadata(), "n={$size}");
            if ($size >= 3) {
                // The comparison is between two role assignments, not of a schedule with itself
                Assert::assertNotEquals($roundParity->toArray(), $balanced->toArray(), "n={$size}: the two schedules compared are one");
            }

            // While the leg is played no participant is further apart than
            // 1 (even field) or 2 (odd field, where the bye shifts a parity)
            Assert::assertLessThanOrEqual($size % 2 === 0 ? 1 : 2, RoleCounts::worstRunningImbalance($balanced), "n={$size}");

            // No role three times in a row, and never a longer run than round parity
            Assert::assertLessThanOrEqual(2, RoleCounts::worstStreak($balanced), "n={$size}");
            Assert::assertLessThanOrEqual(RoleCounts::worstStreak($roundParity), RoleCounts::worstStreak($balanced), "n={$size}");

            // The quality metrics, which are lower-is-better, agree
            Assert::assertLessThanOrEqual($roleBalance->measure($roundParity), $roleBalance->measure($balanced), "n={$size}");
            Assert::assertLessThanOrEqual($roleStreaks->measure($roundParity), $roleStreaks->measure($balanced), "n={$size}");
        }
    })->with([[null], [7], [20260]]);

    // Stated for RoleBalanceMetric only. RoleStreakMetric is not included:
    // two mirrored legs repeat a role across the leg boundary, and there the
    // balanced assignment scores up to 0.4 worse than round parity.
    it('scores at least as well as round parity on role balance over several legs', function (string $strategyName): void {
        $roleBalance = new RoleBalanceMetric();

        for ($size = 2; $size <= 30; ++$size) {
            for ($legs = 1; $legs <= 6; ++$legs) {
                $roundParity = (new RoundRobinScheduler())->schedule(
                    roleField($size),
                    new RoundRobinOptions($legs, roleLegStrategy($strategyName), false, new RoundParityRoleAssignment())
                );
                $balanced = (new RoundRobinScheduler())->schedule(
                    roleField($size),
                    new RoundRobinOptions($legs, roleLegStrategy($strategyName), false, new BalancedRoleAssignment())
                );

                Assert::assertLessThanOrEqual(
                    $roleBalance->measure($roundParity),
                    $roleBalance->measure($balanced),
                    "{$strategyName} n={$size} legs={$legs}"
                );
            }
        }
    })->with([['mirrored'], ['repeated']]);

    it('gives the same schedule for the same input and seed', function (): void {
        $generate = static fn(): array => (new RoundRobinScheduler(null, new Randomizer(new Mt19937(99))))->schedule(
            roleField(9),
            new RoundRobinOptions(3, new MirroredLegStrategy(), false, new BalancedRoleAssignment())
        )->toArray();

        expect($generate())->toBe($generate());
    });

    it('builds from plain configuration data', function (): void {
        $schedule = (new RoundRobinScheduler())->schedule(
            roleField(4),
            RoundRobinOptions::fromArray(['role_assignment' => 'balanced'])
        );

        expect(RoleCounts::worstEndImbalance($schedule))->toBe(1);
    });
});

describe('role assignments and constraints', function (): void {
    // Constraints stay hard filters: they see every event with the roles
    // the role assignment gave it.
    it('satisfies a limit of two same-role events in a row for every field size', function (): void {
        $constraints = ConstraintSet::create()->add(ConsecutiveRoleConstraint::position(2))->build();

        for ($size = 2; $size <= 30; ++$size) {
            $schedule = (new RoundRobinScheduler($constraints))->schedule(
                roleField($size),
                new RoundRobinOptions(roleAssignment: new BalancedRoleAssignment())
            );

            assertRoundRobinStructure($schedule, $size, 1);
            Assert::assertLessThanOrEqual(2, RoleCounts::worstStreak($schedule), "n={$size}");
        }

        // Round parity cannot: four participants put one of them in the same
        // role three times in a row under every rotation
        expect(fn() => (new RoundRobinScheduler($constraints))->schedule(
            roleField(4),
            new RoundRobinOptions(roleAssignment: new RoundParityRoleAssignment())
        ))->toThrow(IncompleteScheduleException::class);
    });

    it('satisfies a running balance limit of 1 in an even field and of 2 in an odd one', function (): void {
        for ($size = 2; $size <= 30; ++$size) {
            $limit = $size % 2 === 0 ? 1 : 2;
            $constraints = ConstraintSet::create()->add(new RoleBalanceConstraint($limit))->build();

            $schedule = (new RoundRobinScheduler($constraints))->schedule(
                roleField($size),
                new RoundRobinOptions(roleAssignment: new BalancedRoleAssignment())
            );

            assertRoundRobinStructure($schedule, $size, 1, "n={$size}");
            Assert::assertLessThanOrEqual($limit, RoleCounts::worstRunningImbalance($schedule), "n={$size}");
        }

        // Round parity cannot: its running difference reaches 3 with four participants
        $constraints = ConstraintSet::create()->add(new RoleBalanceConstraint(1))->build();

        expect(fn() => (new RoundRobinScheduler($constraints))->schedule(
            roleField(4),
            new RoundRobinOptions(roleAssignment: new RoundParityRoleAssignment())
        ))->toThrow(IncompleteScheduleException::class);
    });

    it('fails loudly when a role constraint rejects the balanced roles', function (bool $backtracking): void {
        // Strict alternation: no schedule of six participants has it, since
        // two participants that both want the same role must still meet
        $constraints = ConstraintSet::create()->add(ConsecutiveRoleConstraint::position(1))->build();
        $scheduler = new RoundRobinScheduler($constraints);

        try {
            $scheduler->schedule(
                roleField(6),
                new RoundRobinOptions(backtracking: $backtracking, roleAssignment: new BalancedRoleAssignment())
            );
            Assert::fail('The schedule was generated.');
        } catch (IncompleteScheduleException $exception) {
            expect($exception->getViolationCollector()->hasViolations())->toBeTrue();
            expect(array_keys($exception->getViolationCollector()->getViolationCountsByConstraint()))
                ->toBe(['Position consecutive limit (1)']);
        }
    })->with([[false], [true]]);

    it('keeps its bounds on a schedule that needed a rotated retry', function (): void {
        // Seed protection keeps seeds 1 and 2 apart for the first quarter of
        // 10 rounds; the participant order as given pairs them in round 2
        $constraints = ConstraintSet::create()->add(new SeedProtectionConstraint(2, 0.25))->build();
        $options = new RoundRobinOptions(legs: 2, roleAssignment: new BalancedRoleAssignment());

        $schedule = (new RoundRobinScheduler($constraints))->schedule(roleField(6), $options);

        assertRoundRobinStructure($schedule, 6, 2);
        expect(RoleCounts::structure($schedule))
            ->not->toBe(RoleCounts::structure((new RoundRobinScheduler())->schedule(roleField(6), $options)));

        foreach ($schedule as $event) {
            $seeds = array_map(static fn(Participant $participant): ?int => $participant->getSeed(), $event->getParticipants());
            sort($seeds);
            if ($seeds === [1, 2]) {
                expect($event->getRound()?->getNumber())->toBeGreaterThan(2);
            }
        }

        expect(RoleCounts::worstEndImbalance(RoleCounts::leg($schedule, 1, 5)))->toBe(1);
        expect(RoleCounts::worstEndImbalance($schedule))->toBe(0);
    });

    it('balances a first leg that only the backtracking search finds', function (int $size, array $placement): void {
        $constraints = ConstraintSet::create()->custom(rolePlacementConstraint($placement), 'Fixture Placement')->build();

        // The premise: no rotation satisfies the placement
        expect(fn() => (new RoundRobinScheduler($constraints))->schedule(
            roleField($size),
            new RoundRobinOptions(roleAssignment: new BalancedRoleAssignment())
        ))->toThrow(IncompleteScheduleException::class);

        // Round parity keeps the roles the search chose
        $searched = (new RoundRobinScheduler($constraints))->schedule(
            roleField($size),
            new RoundRobinOptions(backtracking: true, roleAssignment: new RoundParityRoleAssignment())
        );
        $balanced = (new RoundRobinScheduler($constraints))->schedule(
            roleField($size),
            new RoundRobinOptions(backtracking: true, roleAssignment: new BalancedRoleAssignment())
        );

        assertRoundRobinStructure($balanced, $size, 1);
        expect(RoleCounts::structure($balanced))->toBe(RoleCounts::structure($searched));
        expect($balanced->getMetadata())->toBe($searched->getMetadata());

        // The roles the search chose are out of balance, or this proves nothing
        expect(RoleCounts::worstEndImbalance($searched))->toBeGreaterThan($size % 2 === 0 ? 1 : 0);
        foreach (RoleCounts::differences($balanced) as $difference) {
            expect(abs($difference))->toBe($size % 2 === 0 ? 1 : 0);
        }
    })->with([
        'four participants' => [4, ['p1 + p3' => 1, 'p1 + p2' => 2, 'p1 + p4' => 3]],
        'five participants, with byes' => [5, ['p1 + p2' => 5, 'p1 + p3' => 4]],
        'six participants' => [6, ['p1 + p2' => 1, 'p1 + p3' => 2, 'p1 + p4' => 3, 'p1 + p6' => 4, 'p1 + p5' => 5]],
    ]);

    // Fixed roles that the roles of no rotation satisfy as either role
    // assignment gives them, in fields larger than the placement cases above
    // reach. Round parity states no rule, so its roles are searched with
    // backtracking only, and the search returns a leg far from balanced.
    // The balanced role assignment needs no backtracking: the scheduler
    // fixes the seatings the rule rejects, and the role assignment balances
    // the leg around them, in the layout of the circle method.
    it('balances a larger field around the roles a constraint fixes, in the layout of the circle method', function (
        int $size,
        array $firstNamed,
        bool $backtracking
    ): void {
        $constraints = roleFixedRolesConstraint($firstNamed);
        $parity = static fn(bool $backtracking): RoundRobinOptions => new RoundRobinOptions(
            backtracking: $backtracking,
            roleAssignment: new RoundParityRoleAssignment()
        );

        // The premises: the balanced roles as the circle rule gives them
        // break the rule, and so do the round-parity roles of every rotation
        $unconstrained = (new RoundRobinScheduler())->schedule(roleField($size));
        $unconstrainedRoles = array_map(
            static fn(Event $event): string => $event->getParticipants()[0]->getId() . '|' . $event->getParticipants()[1]->getId(),
            $unconstrained->getEvents()
        );
        expect(array_values(array_intersect($firstNamed, $unconstrainedRoles)))->not->toBe($firstNamed)
            ->and(fn() => (new RoundRobinScheduler($constraints))->schedule(roleField($size), $parity(false)))
            ->toThrow(IncompleteScheduleException::class);

        $searched = (new RoundRobinScheduler($constraints))->schedule(roleField($size), $parity(true));
        $balanced = (new RoundRobinScheduler($constraints))->schedule(
            roleField($size),
            new RoundRobinOptions(backtracking: $backtracking)
        );

        assertRoundRobinStructure($balanced, $size, 1);
        expect(RoleCounts::structure($balanced))->toBe(RoleCounts::structure($unconstrained));
        expect($balanced->getMetadata())->toBe($unconstrained->getMetadata());

        // The roles the search chose are out of balance, or this proves nothing
        expect(RoleCounts::worstEndImbalance($searched))->toBeGreaterThan(1);
        foreach (RoleCounts::differences($balanced) as $difference) {
            expect(abs($difference))->toBe($size % 2 === 0 ? 1 : 0);
        }

        // Every fixed role is as the rule has it
        $roles = array_map(
            static fn(Event $event): string => $event->getParticipants()[0]->getId() . '|' . $event->getParticipants()[1]->getId(),
            $balanced->getEvents()
        );
        expect(array_values(array_intersect($firstNamed, $roles)))->toBe($firstNamed);
    })->with([
        'eight participants' => [8, ['p2|p7', 'p2|p3', 'p8|p1', 'p7|p6']],
        'nine participants, with byes' => [9, ['p6|p8', 'p3|p6', 'p8|p2', 'p4|p9']],
        'twelve participants' => [12, ['p10|p12', 'p7|p1', 'p3|p6']],
    ])->with([[false], [true]]);

    // Up to 0.2.x a role assignment did not know the constraints, and the
    // scheduler did not look for other balanced roles: this rule failed
    // under the balanced role assignment, with and without backtracking,
    // although these balanced roles, stated by hand, keep it:
    //
    //   p2-p1  p4-p1  p1-p3  p3-p2  p2-p4  p3-p4
    //
    // (p1 and p4 one more second-named, p2 and p3 one more first-named).
    // Now the scheduler fixes the seatings the rule rejects and the role
    // assignment balances the leg around them (issue #51). The schedule has
    // the pairings and rounds of the schedule without the rule: only roles
    // were looked for.
    it('finds the balanced roles that fixed roles leave', function (bool $backtracking, ?RoleAssignmentInterface $roleAssignment): void {
        $constraints = roleFixedRolesConstraint(['p2|p1', 'p4|p1']);
        $options = new RoundRobinOptions(backtracking: $backtracking, roleAssignment: $roleAssignment);

        $schedule = (new RoundRobinScheduler($constraints))->schedule(roleField(4), $options);

        assertRoundRobinStructure($schedule, 4, 1);
        expect(RoleCounts::structure($schedule))->toBe(RoleCounts::structure((new RoundRobinScheduler())->schedule(roleField(4), $options)))
            ->and(RoleCounts::worstEndImbalance($schedule))->toBe(1);

        $roles = array_map(
            static fn(Event $event): string => $event->getParticipants()[0]->getId() . '|' . $event->getParticipants()[1]->getId(),
            $schedule->getEvents()
        );
        expect($roles)->toContain('p2|p1')->toContain('p4|p1');
    })
        ->with([[false], [true]])
        ->with([
            'the default' => [null],
            'the balanced role assignment, named' => [new BalancedRoleAssignment()],
        ]);

    // The exhaustive case of issue #51. Four participants have six
    // pairings, and a rule about roles can leave each one free or fix it
    // either way round: 3^6 = 729 rules. For every one of them the
    // scheduler, under the balanced role assignment, returns a schedule
    // exactly when a balanced leg exists that keeps the rule, and a failure
    // names the rule. (The default returns the same schedule in each of
    // those cases, and does not fail in the others where the roles of
    // round parity keep the rule: the test after this one.)
    //
    // "A balanced leg exists" is counted here and not asked of the library:
    // each of the 2^6 ways to name one participant of every pairing first,
    // kept when it has no pairing the wrong way round and leaves every
    // participant one apart (each plays three events, so that is the least
    // there is). Whether one exists does not depend on the rounds, since
    // the rule does not read them.
    //
    // Before the scheduler searched the roles within the rule of the role
    // assignment, 143 of the 729 failed under the balanced role assignment
    // although a balanced leg exists.
    it('finds a balanced leg for each of the 729 ways to fix roles of four participants that leaves one', function (bool $backtracking): void {
        $field = roleField(4);
        $pairings = [['p1', 'p2'], ['p1', 'p3'], ['p1', 'p4'], ['p2', 'p3'], ['p2', 'p4'], ['p3', 'p4']];
        $wrong = [];
        $found = 0;
        $refused = 0;

        for ($code = 0; $code < 729; ++$code) {
            $firstNamed = [];
            $digits = $code;
            foreach ($pairings as [$one, $other]) {
                $choice = $digits % 3;
                $digits = intdiv($digits, 3);
                if ($choice === 1) {
                    $firstNamed[] = "{$one}|{$other}";
                } elseif ($choice === 2) {
                    $firstNamed[] = "{$other}|{$one}";
                }
            }
            $case = $firstNamed === [] ? 'no role fixed' : implode(', ', $firstNamed);

            $exists = false;
            for ($roles = 0; $roles < 64 && !$exists; ++$roles) {
                $difference = [];
                $kept = true;
                foreach ($pairings as $bit => [$one, $other]) {
                    [$first, $second] = (($roles >> $bit) & 1) === 1 ? [$other, $one] : [$one, $other];
                    if (in_array("{$second}|{$first}", $firstNamed, true)) {
                        $kept = false;
                        break;
                    }
                    $difference[$first] = ($difference[$first] ?? 0) + 1;
                    $difference[$second] = ($difference[$second] ?? 0) - 1;
                }
                $exists = $kept && RoleCounts::largest($difference) === 1;
            }

            try {
                $schedule = (new RoundRobinScheduler(roleFixedRolesConstraint($firstNamed)))
                    ->schedule($field, new RoundRobinOptions(backtracking: $backtracking, roleAssignment: new BalancedRoleAssignment()));
            } catch (IncompleteScheduleException $failure) {
                ++$refused;
                if ($exists) {
                    $wrong[] = "{$case}: a balanced leg exists, and the scheduler said: {$failure->getMessage()}";
                } elseif (array_keys($failure->getViolationCollector()->getViolationCountsByConstraint()) !== ['Fixed Roles']) {
                    $wrong[] = "{$case}: the failure does not name the rule";
                }

                continue;
            }

            ++$found;
            if (!$exists) {
                $wrong[] = "{$case}: no balanced leg exists, and the scheduler returned a schedule";
            }
            if (RoleCounts::worstEndImbalance($schedule) !== 1) {
                $wrong[] = "{$case}: the leg is not balanced";
            }
            foreach ($schedule as $event) {
                [$first, $second] = $event->getParticipants();
                if (in_array($second->getId() . '|' . $first->getId(), $firstNamed, true)) {
                    $wrong[] = "{$case}: {$first->getId()} is first-named against {$second->getId()}";
                }
            }
            assertRoundRobinStructure($schedule, 4, 1, $case);
        }

        expect($wrong)->toBe([])
            ->and($found + $refused)->toBe(729)
            ->and($found)->toBeGreaterThan(0)
            ->and($refused)->toBeGreaterThan(0);
    })->with([[false], [true]]);

    // The same 729 rules with the options as they come: the default,
    // which is balanced where possible. Three things can happen, and the
    // rule decides which:
    //
    // - a balanced leg keeps the rule: the schedule is the one the balanced
    //   role assignment gives, byte for byte, and carries no mark;
    // - none does, and the roles the generator proposes keep it in some
    //   rotated order (with backtracking: in some way to play the leg, which
    //   for a rule about roles alone is any rule that does not fix a pairing
    //   both ways round, and no rule here does): the schedule is the one
    //   round parity gives, marked. The rule is a closure, so round parity
    //   is tried before the search for balanced roles, and with its
    //   schedule in hand that search is held to 4,000 events put to the
    //   rule, which a field of four is far inside: it goes through every
    //   choice of balanced roles, and the mark says that none exists. One
    //   thing is left out with a schedule in hand, under backtracking: the
    //   backtracking search for a balanced leg, where a rotated order gave
    //   that schedule and no search. There the mark says that the
    //   constraints rejected the balanced roles, and no more. These
    //   completed up to 0.2.x, and failed while the default was the strict
    //   balanced role assignment;
    // - neither: the failure of round parity, which names the rule.
    //
    // "A balanced leg exists" is counted as in the test before this one.
    // What round parity gives is asked of the scheduler with that role
    // assignment named, which tests/Feature/DefaultRoleAssignmentTest.php
    // and the golden fixtures pin to what it gave up to 0.2.x.
    it('balances each of the 729 rules that leaves a balanced leg and falls back, marked, for the others that round parity keeps', function (bool $backtracking): void {
        $field = roleField(4);
        $pairings = [['p1', 'p2'], ['p1', 'p3'], ['p1', 'p4'], ['p2', 'p3'], ['p2', 'p4'], ['p3', 'p4']];
        $wrong = [];
        $counts = ['balanced' => 0, 'fell back' => 0, 'refused' => 0];

        for ($code = 0; $code < 729; ++$code) {
            $firstNamed = [];
            $digits = $code;
            foreach ($pairings as [$one, $other]) {
                $choice = $digits % 3;
                $digits = intdiv($digits, 3);
                if ($choice === 1) {
                    $firstNamed[] = "{$one}|{$other}";
                } elseif ($choice === 2) {
                    $firstNamed[] = "{$other}|{$one}";
                }
            }
            $case = $firstNamed === [] ? 'no role fixed' : implode(', ', $firstNamed);

            $exists = false;
            for ($roles = 0; $roles < 64 && !$exists; ++$roles) {
                $difference = [];
                $kept = true;
                foreach ($pairings as $bit => [$one, $other]) {
                    [$first, $second] = (($roles >> $bit) & 1) === 1 ? [$other, $one] : [$one, $other];
                    if (in_array("{$second}|{$first}", $firstNamed, true)) {
                        $kept = false;
                        break;
                    }
                    $difference[$first] = ($difference[$first] ?? 0) + 1;
                    $difference[$second] = ($difference[$second] ?? 0) - 1;
                }
                $exists = $kept && RoleCounts::largest($difference) === 1;
            }

            $outcome = static function (?RoleAssignmentInterface $roleAssignment) use ($firstNamed, $field, $backtracking): Schedule|IncompleteScheduleException {
                try {
                    return (new RoundRobinScheduler(roleFixedRolesConstraint($firstNamed)))
                        ->schedule($field, new RoundRobinOptions(backtracking: $backtracking, roleAssignment: $roleAssignment));
                } catch (IncompleteScheduleException $failure) {
                    return $failure;
                }
            };
            $schedule = $outcome(null);
            $underRoundParity = $outcome(new RoundParityRoleAssignment());

            if ($schedule instanceof IncompleteScheduleException) {
                ++$counts['refused'];
                if ($exists) {
                    $wrong[] = "{$case}: a balanced leg exists, and the scheduler said: {$schedule->getMessage()}";
                }
                if (!$underRoundParity instanceof IncompleteScheduleException || $underRoundParity->getMessage() !== $schedule->getMessage()) {
                    $wrong[] = "{$case}: the failure is not the one round parity gives";
                }
                if (array_keys($schedule->getViolationCollector()->getViolationCountsByConstraint()) !== ['Fixed Roles']) {
                    $wrong[] = "{$case}: the failure does not name the rule";
                }

                continue;
            }

            foreach ($schedule as $event) {
                [$first, $second] = $event->getParticipants();
                if (in_array($second->getId() . '|' . $first->getId(), $firstNamed, true)) {
                    $wrong[] = "{$case}: {$first->getId()} is first-named against {$second->getId()}";
                }
            }
            assertRoundRobinStructure($schedule, 4, 1, $case);

            $mark = RoleFallback::of($schedule);
            $balanced = RoleCounts::worstEndImbalance($schedule) === 1;

            if ($exists) {
                ++$counts['balanced'];
                $underBalanced = $outcome(new BalancedRoleAssignment());
                if ($mark !== null || !$balanced) {
                    $wrong[] = "{$case}: a balanced leg exists, and the schedule " . ($balanced ? 'is marked' : 'is not balanced');
                }
                if (!$underBalanced instanceof Schedule || $underBalanced->toArray() !== $schedule->toArray()) {
                    $wrong[] = "{$case}: not the schedule the balanced role assignment gives";
                }

                continue;
            }

            ++$counts['fell back'];
            if ($balanced) {
                $wrong[] = "{$case}: no balanced leg exists, and the schedule is balanced";
            }
            $aRotatedOrderGaveTheSchedule = true;
            try {
                (new RoundRobinScheduler(roleFixedRolesConstraint($firstNamed)))
                    ->schedule($field, new RoundRobinOptions(roleAssignment: new RoundParityRoleAssignment()));
            } catch (IncompleteScheduleException) {
                $aRotatedOrderGaveTheSchedule = false;
            }
            $expected = $backtracking && $aRotatedOrderGaveTheSchedule
                ? RoleFallback::BalancedRolesRejected
                : RoleFallback::NoBalancedRoles;
            if ($mark !== $expected) {
                $wrong[] = "{$case}: no balanced leg exists, and the mark is " . ($mark->value ?? 'absent') . ", not {$expected->value}";
            }
            $withoutTheMark = $schedule->toArray();
            unset($withoutTheMark['metadata'][RoleFallback::METADATA_KEY]);
            if (!$underRoundParity instanceof Schedule || $underRoundParity->toArray() !== $withoutTheMark) {
                $wrong[] = "{$case}: not the schedule round parity gives";
            }
        }

        expect($wrong)->toBe([])
            ->and(array_sum($counts))->toBe(729)
            ->and($counts['balanced'])->toBeGreaterThan(0)
            ->and($counts['fell back'])->toBeGreaterThan(0)
            // Without backtracking some rules are kept by no rotated order
            // under either role assignment. With it the search chooses the
            // roles, and no rule here fixes a pairing both ways round
            ->and($counts['refused'] === 0)->toBe($backtracking);
    })->with([[false], [true]]);

    it('derives the later legs from the balanced first leg of a search', function (): void {
        $placement = rolePlacementConstraint(['p1 + p3' => 1, 'p1 + p2' => 2, 'p1 + p4' => 3]);
        // Hold the placement in leg 1 only, so that the mirrored leg passes
        $constraints = ConstraintSet::create()->custom(
            static fn(Event $event): bool => ($event->getRound()?->getNumber() ?? 0) > 3 || $placement($event),
            'Fixture Placement'
        )->build();

        $schedule = (new RoundRobinScheduler($constraints))->schedule(
            roleField(4),
            new RoundRobinOptions(legs: 2, backtracking: true, roleAssignment: new BalancedRoleAssignment())
        );

        assertRoundRobinStructure($schedule, 4, 2);
        expect(RoleCounts::worstEndImbalance(RoleCounts::leg($schedule, 1, 3)))->toBe(1);
        expect(array_values(array_unique(RoleCounts::pairingSplits($schedule))))->toBe([0]);
        expect(RoleCounts::worstEndImbalance($schedule))->toBe(0);
    });

    // A constraint that no balanced leg satisfies is a failure under the
    // balanced role assignment, and the failure says what was shown: with
    // backtracking every way to play the first leg was gone through, with
    // every choice of roles the role assignment keeps, so no schedule
    // exists under it. Up to 0.2.x the search stopped at the first leg it
    // found, balanced it and reported the one event the constraints then
    // rejected. The default does not fail: it returns the schedule of the
    // search, as round parity does, and marks it. That schedule came from a
    // backtracking search, so one is made for a balanced first leg too,
    // within the 4,000 events of a search with a schedule in hand: it goes
    // through the same ways to play the leg, and the mark says what the
    // strict role assignment's failure says.
    it('says that no balanced schedule exists when a constraint leaves none, and falls back under the default', function (): void {
        $placement = rolePlacementConstraint(['p1 + p2' => 1, 'p1 + p3' => 2, 'p1 + p4' => 3]);
        $constraints = ConstraintSet::create()
            ->custom($placement, 'Fixture Placement')
            ->custom(
                static fn(Event $event): bool => $event->getParticipants()[1]->getId() !== 'p1',
                'Participant 1 is first-named'
            )
            ->build();

        // Round parity keeps the roles the search chose, which satisfy
        // both: participant 1 is first-named three times
        $searched = (new RoundRobinScheduler($constraints))->schedule(
            roleField(4),
            new RoundRobinOptions(backtracking: true, roleAssignment: new RoundParityRoleAssignment())
        );
        expect(RoleCounts::differences($searched)['p1'])->toBe(3);

        // No balanced leg can: the constraint itself puts participant 1 three apart
        try {
            (new RoundRobinScheduler($constraints))->schedule(
                roleField(4),
                new RoundRobinOptions(backtracking: true, roleAssignment: new BalancedRoleAssignment())
            );
            Assert::fail('The schedule was generated.');
        } catch (IncompleteScheduleException $exception) {
            expect($exception->getMessage())
                ->toStartWith('Backtracking search exhausted the search space: ')
                ->toContain('whatever roles ' . BalancedRoleAssignment::class . ' keeps for the first leg')
                ->toEndWith('so the configuration is unsatisfiable with this leg strategy and this role assignment.');
            expect(array_keys($exception->getViolationCollector()->getViolationCountsByConstraint()))
                ->toContain('Participant 1 is first-named');
            expect($exception->getPrevious())->toBeInstanceOf(IncompleteScheduleException::class);
        }

        $scheduler = new RoundRobinScheduler($constraints);
        $default = $scheduler->schedule(roleField(4), new RoundRobinOptions(backtracking: true));

        expect(RoleFallback::of($default))->toBe(RoleFallback::NoBalancedRoles)
            ->and($default->getEvents())->toEqual($searched->getEvents())
            ->and($default->getMetadata())->toBe($searched->getMetadata() + [RoleFallback::METADATA_KEY => 'no_balanced_roles'])
            // A schedule was returned: the rejections of the attempts that
            // came before it are not left behind as diagnostics
            ->and($scheduler->getViolationCollector()->hasViolations())->toBeFalse();
    });
});

describe('a custom role assignment', function (): void {
    it('decides the roles of every leg', function (): void {
        $reversing = new class implements RoleAssignmentInterface {
            #[Override]
            public function assignRoles(array $rounds, RoleAssignmentContext $context): array
            {
                return array_map(
                    static fn(array $seatings): array => array_map(
                        static fn(array $seating): array => [$seating[1], $seating[0]],
                        $seatings
                    ),
                    $rounds
                );
            }

            #[Override]
            public function rule(): ?ConstraintInterface
            {
                return null;
            }
        };

        foreach ([4, 5] as $size) {
            // The roles the generator proposes, which the assignment reverses
            $default = (new RoundRobinScheduler())->schedule(
                roleField($size),
                new RoundRobinOptions(legs: 2, roleAssignment: new RoundParityRoleAssignment())
            );
            $reversed = (new RoundRobinScheduler())->schedule(
                roleField($size),
                new RoundRobinOptions(legs: 2, roleAssignment: $reversing)
            );

            assertRoundRobinStructure($reversed, $size, 2);
            expect(RoleCounts::structure($reversed))->toBe(RoleCounts::structure($default));

            $ids = static fn(Schedule $schedule): array => array_map(
                static fn(Event $event): array => [$event->getParticipants()[0]->getId(), $event->getParticipants()[1]->getId()],
                $schedule->getEvents()
            );
            expect($ids($reversed))->toBe(array_map(array_reverse(...), $ids($default)));
        }
    });

    it('is refused when it returns anything but the seatings it was given', function (Closure $answer): void {
        $broken = new readonly class ($answer) implements RoleAssignmentInterface {
            /**
             * @param Closure(array<mixed>): array<mixed> $answer
             */
            public function __construct(private Closure $answer) {}

            #[Override]
            public function assignRoles(array $rounds, RoleAssignmentContext $context): array
            {
                /** @var list<list<array{0: Participant|null, 1: Participant|null}>> */
                return ($this->answer)($rounds);
            }

            #[Override]
            public function rule(): ?ConstraintInterface
            {
                return null;
            }
        };

        try {
            (new RoundRobinScheduler())->schedule(roleField(4), new RoundRobinOptions(roleAssignment: $broken));
            Assert::fail('The schedule was generated.');
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getReason())->toBe(InvalidConfigurationReason::InvalidRoleAssignment);
            expect($exception->getContext())->toHaveKey('role_assignment');
        }
    })->with([
        'a round is missing' => [static fn(array $rounds): array => array_slice($rounds, 1)],
        'a seating is missing' => [static fn(array $rounds): array => [[$rounds[0][0]], $rounds[1], $rounds[2]]],
        'two seatings change places' => [static fn(array $rounds): array => [array_reverse($rounds[0]), $rounds[1], $rounds[2]]],
        'a pairing moves to another round' => [static fn(array $rounds): array => [$rounds[1], $rounds[0], $rounds[2]]],
        'two participants change partners' => [static fn(array $rounds): array => [
            [[$rounds[0][0][0], $rounds[0][1][0]], [$rounds[0][0][1], $rounds[0][1][1]]],
            $rounds[1],
            $rounds[2],
        ]],
        'nothing at all' => [static fn(): array => []],
        'an extra round' => [static fn(array $rounds): array => [...$rounds, $rounds[0]]],
        'an extra seating' => [static fn(array $rounds): array => [[...$rounds[0], $rounds[0][0]], $rounds[1], $rounds[2]]],
        'rounds numbered from 1' => [static fn(array $rounds): array => [1 => $rounds[0], 2 => $rounds[1], 3 => $rounds[2]]],
        'a round that is not a list of seatings' => [static fn(array $rounds): array => ['round 1', $rounds[1], $rounds[2]]],
        'a seating that is not a pair of seats' => [static fn(array $rounds): array => [['p1 v p4', $rounds[0][1]], $rounds[1], $rounds[2]]],
        'a seating with a third seat' => [static fn(array $rounds): array => [
            [[...$rounds[0][0], $rounds[0][1][0]], $rounds[0][1]],
            $rounds[1],
            $rounds[2],
        ]],
        'a seating with an empty seat' => [static fn(array $rounds): array => [
            [[$rounds[0][0][0], null], $rounds[0][1]],
            $rounds[1],
            $rounds[2],
        ]],
        'a participant seated against itself' => [static fn(array $rounds): array => [
            [[$rounds[0][0][0], $rounds[0][0][0]], $rounds[0][1]],
            $rounds[1],
            $rounds[2],
        ]],
        'a participant that is not in the field' => [static fn(array $rounds): array => [
            [[new Participant('p9', 'Participant 9'), $rounds[0][0][1]], $rounds[0][1]],
            $rounds[1],
            $rounds[2],
        ]],
        // Equal to the participant it replaces, and not the same object
        'a copy of a participant' => [static fn(array $rounds): array => [
            [[new Participant('p1', 'Participant 1', 1), $rounds[0][0][1]], $rounds[0][1]],
            $rounds[1],
            $rounds[2],
        ]],
        'ids in place of participants' => [static fn(array $rounds): array => array_map(
            static fn(array $seatings): array => array_map(
                static fn(array $seating): array => [$seating[0]?->getId(), $seating[1]?->getId()],
                $seatings
            ),
            $rounds
        )],
    ]);

    // A searched leg goes to the role assignment as well, and its answer is
    // held to the same rule.
    it('is refused for a wrong answer about a leg the backtracking search found', function (): void {
        $constraints = ConstraintSet::create()
            ->custom(rolePlacementConstraint(['p1 + p3' => 1, 'p1 + p2' => 2, 'p1 + p4' => 3]), 'Fixture Placement')
            ->build();
        $dropsARound = new class implements RoleAssignmentInterface {
            #[Override]
            public function assignRoles(array $rounds, RoleAssignmentContext $context): array
            {
                // The circle layouts of the failed attempts pass; the searched
                // leg, which lists rounds of the same size, loses its last round
                return count($rounds[0]) === 2 && $rounds[0][0][0]?->getId() === 'p1' && $rounds[0][0][1]?->getId() === 'p3'
                    ? array_slice($rounds, 0, 2)
                    : $rounds;
            }

            #[Override]
            public function rule(): ?ConstraintInterface
            {
                return null;
            }
        };

        try {
            (new RoundRobinScheduler($constraints))->schedule(
                roleField(4),
                new RoundRobinOptions(backtracking: true, roleAssignment: $dropsARound)
            );
            Assert::fail('The schedule was generated.');
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getReason())->toBe(InvalidConfigurationReason::InvalidRoleAssignment);
        }
    });

    // A role assignment that leaves a searched leg alone gets the schedule
    // of the search, byes included: nothing is replayed or rebuilt.
    it('leaves a searched leg as the search found it when it changes no role', function (int $size, array $placement): void {
        $constraints = ConstraintSet::create()->custom(rolePlacementConstraint($placement), 'Fixture Placement')->build();
        $keepsEveryRole = new class implements RoleAssignmentInterface {
            #[Override]
            public function assignRoles(array $rounds, RoleAssignmentContext $context): array
            {
                return $rounds;
            }

            #[Override]
            public function rule(): ?ConstraintInterface
            {
                return null;
            }
        };

        $searched = (new RoundRobinScheduler($constraints))->schedule(
            roleField($size),
            new RoundRobinOptions(backtracking: true, roleAssignment: new RoundParityRoleAssignment())
        );
        $kept = (new RoundRobinScheduler($constraints))->schedule(
            roleField($size),
            new RoundRobinOptions(backtracking: true, roleAssignment: $keepsEveryRole)
        );

        expect($kept->toArray())->toBe($searched->toArray());
    })->with([
        'four participants' => [4, ['p1 + p3' => 1, 'p1 + p2' => 2, 'p1 + p4' => 3]],
        'five participants, with byes' => [5, ['p1 + p2' => 5, 'p1 + p3' => 4]],
    ]);

    // What a role assignment is told about the leg it decides: which leg
    // of how many, for every leg of the schedule, and nothing fixed where no
    // constraint rejected a seating.
    it('is told which leg of how many it decides', function (?int $seed): void {
        $recording = new class implements RoleAssignmentInterface {
            /** @var list<string> */
            public array $asked = [];

            #[Override]
            public function assignRoles(array $rounds, RoleAssignmentContext $context): array
            {
                $this->asked[] = "leg {$context->leg} of {$context->legs}"
                    . ($context->hasFixedSeatings() || $context->isFixed(0, 0) ? ', with fixed seatings' : '')
                    // The layout of every leg is the layout of the first
                    . ': ' . ($rounds[0][0][0]?->getId() ?? '') . ' v ' . ($rounds[0][0][1]?->getId() ?? '');

                return $rounds;
            }

            #[Override]
            public function rule(): ?ConstraintInterface
            {
                return null;
            }
        };

        $schedule = (new RoundRobinScheduler(null, $seed === null ? null : new Randomizer(new Mt19937($seed))))->schedule(
            roleField(6),
            new RoundRobinOptions(legs: 3, roleAssignment: $recording)
        );
        [$first, $second] = $schedule->getEvents()[0]->getParticipants();
        $seating = "{$first->getId()} v {$second->getId()}";

        expect($recording->asked)->toBe(["leg 1 of 3: {$seating}", "leg 2 of 3: {$seating}", "leg 3 of 3: {$seating}"]);
    })->with([[null], [5]]);

    // Every leg is asked about and every answer is checked: a role
    // assignment that answers the first leg well is still refused for what
    // it does to the second.
    it('is refused for a wrong answer about a later leg', function (): void {
        $secondLegBroken = new class implements RoleAssignmentInterface {
            private int $legsAnswered = 0;

            #[Override]
            public function assignRoles(array $rounds, RoleAssignmentContext $context): array
            {
                return ++$this->legsAnswered === 2 ? array_reverse($rounds) : $rounds;
            }

            #[Override]
            public function rule(): ?ConstraintInterface
            {
                return null;
            }
        };

        expect(fn() => (new RoundRobinScheduler())->schedule(
            roleField(4),
            new RoundRobinOptions(legs: 2, roleAssignment: $secondLegBroken)
        ))->toThrow(InvalidConfigurationException::class, 'Role assignment must return the seatings it was given');
    });
});
