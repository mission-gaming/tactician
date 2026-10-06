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
use MissionGaming\Tactician\Scheduling\ConstraintPurity;
use MissionGaming\Tactician\Scheduling\SchedulingContext;

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
});
