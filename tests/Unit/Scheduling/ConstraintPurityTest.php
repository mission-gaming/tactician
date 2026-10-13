<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\CallableConstraint;
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
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\RoleAssignment\BalancedRoleAssignment;
use MissionGaming\Tactician\RoleAssignment\RoundParityRoleAssignment;
use MissionGaming\Tactician\Scheduling\ConstraintPurity;
use MissionGaming\Tactician\Scheduling\SchedulingContext;
use MissionGaming\Tactician\Stage\RoundRobinPlan;

final readonly class PurityTestConstraintSet extends ConstraintSet {}

final readonly class PurityTestRoleBalance extends RoleBalanceConstraint {}

final class PurityTestConstraint implements ConstraintInterface
{
    #[Override]
    public function isSatisfied(Event $event, SchedulingContext $context): bool
    {
        return true;
    }

    #[Override]
    public function getName(): string
    {
        return 'A constraint of the caller\'s';
    }
}

describe('ConstraintPurity', function (): void {
    it('knows no constraints at all, an empty set, and sets of the listed classes', function (?ConstraintSet $constraints): void {
        expect(ConstraintPurity::isKnown($constraints))->toBeTrue();
    })->with([
        'no set' => [null],
        'an empty set' => [fn(): ConstraintSet => new ConstraintSet([])],
        'no repeat pairings' => [fn(): ConstraintSet => new ConstraintSet([new NoRepeatPairings()])],
        'no repeat pairings from the builder' => [fn(): ConstraintSet => ConstraintSet::create()->noRepeatPairings(true)->build()],
        'minimum rest' => [fn(): ConstraintSet => new ConstraintSet([new MinimumRestPeriodsConstraint(2)])],
        'role balance' => [fn(): ConstraintSet => new ConstraintSet([RoleBalanceConstraint::homeAway(1)])],
        'seed protection' => [fn(): ConstraintSet => new ConstraintSet([new SeedProtectionConstraint(2, 0.5)])],
        'all four' => [fn(): ConstraintSet => new ConstraintSet([
            new NoRepeatPairings(),
            new MinimumRestPeriodsConstraint(1),
            RoleBalanceConstraint::homeAway(2),
            new SeedProtectionConstraint(2, 0.5),
        ])],
    ]);

    it('does not know a set that could run code of the caller\'s', function (ConstraintSet $constraints): void {
        expect(ConstraintPurity::isKnown($constraints))->toBeFalse();
    })->with([
        'a callable' => [fn(): ConstraintSet => new ConstraintSet([new CallableConstraint(static fn(): bool => true, 'Yes')])],
        'a callable from the builder' => [fn(): ConstraintSet => ConstraintSet::create()->custom(static fn(): bool => true)->build()],
        'a role extractor, even a built-in one' => [fn(): ConstraintSet => new ConstraintSet([ConsecutiveRoleConstraint::homeAway(2)])],
        'a metadata validator, even a built-in one' => [fn(): ConstraintSet => new ConstraintSet([MetadataConstraint::requireSameValue('group')])],
        'a class of the caller\'s' => [fn(): ConstraintSet => new ConstraintSet([new PurityTestConstraint()])],
        'a subclass of a listed class' => [fn(): ConstraintSet => new ConstraintSet([new PurityTestRoleBalance(1)])],
        'a subclass of the set' => [fn(): ConstraintSet => new PurityTestConstraintSet([new NoRepeatPairings()])],
        'a listed class beside a callable' => [fn(): ConstraintSet => new ConstraintSet([
            new NoRepeatPairings(),
            new CallableConstraint(static fn(): bool => true, 'Yes'),
        ])],
    ]);

    it('lists only classes that can hold no code of the caller\'s', function (): void {
        // A listed class is one whose verdict cannot depend on how often it
        // was asked. A constructor that takes anything but a number, a
        // string or a flag could be handed a callable or an object with
        // behaviour of its own, and then that no longer holds.
        $checked = 0;
        foreach (ConstraintPurity::KNOWN as $class) {
            $reflection = new ReflectionClass($class);
            expect($reflection->implementsInterface(ConstraintInterface::class))->toBeTrue();

            foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
                $type = $parameter->getType();
                expect($type)->toBeInstanceOf(ReflectionNamedType::class);
                expect($type instanceof ReflectionNamedType ? $type->getName() : null)->toBeIn(['int', 'float', 'string', 'bool']);
                ++$checked;
            }
        }

        expect($checked)->toBeGreaterThanOrEqual(count(ConstraintPurity::KNOWN));
    });

    // A role search goes straight back to an event of the same participants
    // only for a set that reads no others. That is every known set, and a
    // set that also holds the consecutive-role class.
    it('says a set reads the events of the candidate\'s own participants only when it holds nothing else', function (): void {
        $readsOwn = [
            'no set' => null,
            'an empty set' => new ConstraintSet([]),
            'the four known classes' => new ConstraintSet([
                new NoRepeatPairings(),
                new MinimumRestPeriodsConstraint(1),
                RoleBalanceConstraint::homeAway(2),
                new SeedProtectionConstraint(2, 0.5),
            ]),
            'a consecutive-role limit' => new ConstraintSet([ConsecutiveRoleConstraint::homeAway(2)]),
            'a consecutive-role limit beside a known class' => new ConstraintSet([
                ConsecutiveRoleConstraint::position(3),
                RoleBalanceConstraint::homeAway(2),
            ]),
        ];
        foreach ($readsOwn as $name => $constraints) {
            expect(ConstraintPurity::readsOwnEventsOnly($constraints))->toBeTrue($name);
        }

        $mayReadMore = [
            'a callable' => new ConstraintSet([new CallableConstraint(static fn(): bool => true, 'Yes')]),
            'a metadata validator' => new ConstraintSet([MetadataConstraint::requireSameValue('group')]),
            'a class of the caller\'s' => new ConstraintSet([new PurityTestConstraint()]),
            'a subclass of a known class' => new ConstraintSet([new PurityTestRoleBalance(1)]),
            'a subclass of the set' => new PurityTestConstraintSet([ConsecutiveRoleConstraint::homeAway(2)]),
            'a consecutive-role limit beside a callable' => new ConstraintSet([
                ConsecutiveRoleConstraint::homeAway(2),
                new CallableConstraint(static fn(): bool => true, 'Yes'),
            ]),
        ];
        foreach ($mayReadMore as $name => $constraints) {
            expect(ConstraintPurity::readsOwnEventsOnly($constraints))->toBeFalse($name);
        }
    });

    // The claim itself, for the one class the method adds to the known
    // ones: whatever the other participants play, in whatever roles, the
    // verdict on an event of two participants is the same.
    it('gets the same verdict from a consecutive-role limit whatever the other participants play', function (): void {
        $field = [];
        for ($number = 1; $number <= 6; ++$number) {
            $field[] = new Participant("p{$number}", "P{$number}");
        }
        $plan = new RoundRobinPlan($field, 2);
        $limit = ConsecutiveRoleConstraint::homeAway(2);
        $verdicts = [];

        // p1 has been first-named twice, so a third time is one too many;
        // p2 was second-named and then first-named
        $own = [
            new Event([$field[0], $field[2]], new Round(1)),
            new Event([$field[3], $field[1]], new Round(1)),
            new Event([$field[0], $field[3]], new Round(2)),
            new Event([$field[1], $field[2]], new Round(2)),
        ];
        $others = [
            [],
            [new Event([$field[4], $field[5]], new Round(1)), new Event([$field[4], $field[5]], new Round(2))],
            [new Event([$field[5], $field[4]], new Round(1)), new Event([$field[5], $field[4]], new Round(2)), new Event([$field[4], $field[5]], new Round(3))],
        ];

        foreach ($others as $index => $otherEvents) {
            $context = new SchedulingContext($field, $plan, [...$own, ...$otherEvents], 1);
            $verdicts[$index] = [
                $limit->isSatisfied(new Event([$field[0], $field[1]], new Round(3)), $context),
                $limit->isSatisfied(new Event([$field[1], $field[0]], new Round(3)), $context),
                $limit->isSatisfied(new Event([$field[2], $field[3]], new Round(3)), $context),
            ];
        }

        expect($verdicts[0])->toBe([false, true, true])
            ->and($verdicts[1])->toBe($verdicts[0])
            ->and($verdicts[2])->toBe($verdicts[0]);
    });

    // One constraint at a time: what a search of first-leg roles asks of a
    // set that holds both kinds, to tell a rejection that only the two
    // participants' own events can lift from one that anything may.
    it('says of one constraint whether it reads the own events only', function (): void {
        $readsOwn = [
            new NoRepeatPairings(),
            new MinimumRestPeriodsConstraint(1),
            RoleBalanceConstraint::homeAway(2),
            new SeedProtectionConstraint(2, 0.5),
            ConsecutiveRoleConstraint::homeAway(2),
            ConsecutiveRoleConstraint::position(3),
        ];
        foreach ($readsOwn as $constraint) {
            expect(ConstraintPurity::constraintReadsOwnEventsOnly($constraint))->toBeTrue($constraint::class);
        }

        $mayReadMore = [
            new CallableConstraint(static fn(): bool => true, 'Yes'),
            MetadataConstraint::requireSameValue('group'),
            new PurityTestConstraint(),
            new PurityTestRoleBalance(1),
            // The rule of a role assignment is not a constraint of a set
            (new BalancedRoleAssignment())->rule(),
        ];
        foreach ($mayReadMore as $constraint) {
            expect(ConstraintPurity::constraintReadsOwnEventsOnly($constraint))->toBeFalse($constraint::class);
        }
    });

    // A constraint that reads no position rejects a pairing in a round
    // whatever the roles, so a search of roles has nothing to find where one
    // rejects: the scheduler asks this before it starts one.
    it('says of one constraint whether it reads no position of an event', function (): void {
        foreach ([new NoRepeatPairings(), new NoRepeatPairings(acrossLegs: true), new MinimumRestPeriodsConstraint(1), new SeedProtectionConstraint(2, 0.5)] as $constraint) {
            expect(ConstraintPurity::constraintIgnoresRoles($constraint))->toBeTrue($constraint::class);
        }

        $mayReadRoles = [
            RoleBalanceConstraint::homeAway(2),
            ConsecutiveRoleConstraint::homeAway(2),
            new CallableConstraint(static fn(): bool => true, 'Yes'),
            MetadataConstraint::requireSameValue('group'),
            new PurityTestConstraint(),
            (new BalancedRoleAssignment())->rule(),
        ];
        foreach ($mayReadRoles as $constraint) {
            expect(ConstraintPurity::constraintIgnoresRoles($constraint))->toBeFalse($constraint::class);
        }

        // Every class that ignores roles is a known class
        expect(array_diff(ConstraintPurity::IGNORES_ROLES, ConstraintPurity::KNOWN))->toBe([]);
    });

    // The claim behind the list, held for each class on it: every pairing
    // of six participants, put to the constraint in each round of two legs
    // after a growing part of a schedule, gets the verdict it gets with its
    // two participants the other way round, and with every event before it
    // the other way round. Both verdicts occur for every constraint, so the
    // comparison is not of a constant.
    it('gets the same verdict from a constraint that ignores roles whichever way round the events are', function (): void {
        $field = [];
        for ($i = 1; $i <= 6; ++$i) {
            $field[] = new Participant("p{$i}", "P{$i}", $i);
        }
        $plan = new RoundRobinPlan($field, 2, true);
        $reversed = static fn(Event $event): Event => new Event(array_reverse($event->getParticipants()), $event->getRound());

        // A first leg by hand (the circle method's rounds), and its mirror
        $rounds = [
            [[0, 5], [1, 4], [2, 3]],
            [[5, 3], [4, 2], [0, 1]],
            [[1, 5], [2, 0], [3, 4]],
            [[5, 4], [0, 3], [1, 2]],
            [[2, 5], [3, 1], [4, 0]],
        ];
        $schedule = [];
        foreach ([0, 1] as $leg) {
            foreach ($rounds as $index => $pairs) {
                foreach ($pairs as [$one, $other]) {
                    $pair = $leg === 0 ? [$field[$one], $field[$other]] : [$field[$other], $field[$one]];
                    $schedule[] = new Event($pair, new Round($leg * 5 + $index + 1));
                }
            }
        }

        $constraints = [
            'no repeat in a leg' => new NoRepeatPairings(),
            'no repeat at all' => new NoRepeatPairings(acrossLegs: true),
            'six rounds of rest' => new MinimumRestPeriodsConstraint(6),
            'seed protection' => new SeedProtectionConstraint(2, 0.5),
        ];

        foreach ($constraints as $name => $constraint) {
            $verdicts = [];
            foreach ([0, 9, 15, 21, 30] as $played) {
                $before = array_slice($schedule, 0, $played);
                $leg = $played < 15 ? 1 : 2;
                $asPlayed = new SchedulingContext($field, $plan, $before, $leg);
                $theOtherWayRound = new SchedulingContext($field, $plan, array_map($reversed, $before), $leg);

                foreach ([intdiv($played, 3) + 1, 10] as $round) {
                    for ($one = 0; $one < 6; ++$one) {
                        for ($other = $one + 1; $other < 6; ++$other) {
                            $candidate = new Event([$field[$one], $field[$other]], new Round($round));
                            $verdict = $constraint->isSatisfied($candidate, $asPlayed);
                            $verdicts[(int) $verdict] = true;

                            expect($constraint->isSatisfied($reversed($candidate), $asPlayed))->toBe($verdict, "{$name}, the candidate reversed")
                                ->and($constraint->isSatisfied($candidate, $theOtherWayRound))->toBe($verdict, "{$name}, the context reversed")
                                ->and($constraint->isSatisfied($reversed($candidate), $theOtherWayRound))->toBe($verdict, "{$name}, both reversed");
                        }
                    }
                }
            }

            expect($verdicts)->toHaveCount(2, "{$name} gives both verdicts here");
        }
    });

    // The rule a role assignment states for the first leg: none reads
    // nothing, the balanced one counts the roles of the two participants,
    // and a rule of the caller's says nothing about what it reads.
    it('knows the rule of the balanced role assignment, and no other', function (): void {
        expect(ConstraintPurity::ruleReadsOwnEventsOnly(null))->toBeTrue()
            ->and(ConstraintPurity::ruleReadsOwnEventsOnly((new RoundParityRoleAssignment())->rule()))->toBeTrue()
            ->and(ConstraintPurity::ruleReadsOwnEventsOnly((new BalancedRoleAssignment())->rule()))->toBeTrue()
            ->and(ConstraintPurity::ruleReadsOwnEventsOnly(new PurityTestConstraint()))->toBeFalse()
            // A constraint that reads the own events only is still not a
            // rule the library wrote for a first leg
            ->and(ConstraintPurity::ruleReadsOwnEventsOnly(RoleBalanceConstraint::homeAway(1)))->toBeFalse();
    });
});
