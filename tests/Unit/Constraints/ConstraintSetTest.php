<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\ConstraintInterface;
use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\SchedulingContext;

describe('ConstraintSet', function (): void {
    beforeEach(function (): void {
        $this->participant1 = new Participant('p1', 'Alice');
        $this->participant2 = new Participant('p2', 'Bob');
        $this->event = new Event([$this->participant1, $this->participant2]);
        $this->context = new SchedulingContext([$this->participant1, $this->participant2]);
    });

    // Tests creating a constraint set with no constraints, which should allow all events
    // and report as empty with zero constraints
    it('creates an empty constraint set', function (): void {
        $constraintSet = new ConstraintSet();

        expect($constraintSet->isEmpty())->toBeTrue();
        expect($constraintSet->count())->toBe(0);
        expect($constraintSet->getConstraints())->toBe([]);
    });

    // Tests creating a constraint set with mock constraints, verifying the constraints
    // are properly stored and the set reports correct count and non-empty status
    it('creates constraint set with constraints', function (): void {
        $constraint = $this->createMock(ConstraintInterface::class);
        $constraintSet = new ConstraintSet([$constraint]);

        expect($constraintSet->isEmpty())->toBeFalse();
        expect($constraintSet->count())->toBe(1);
        expect($constraintSet->getConstraints())->toBe([$constraint]);
    });

    // Tests that when validating an event, all constraints in the set are checked
    // and the event passes only if ALL constraints are satisfied
    it('validates event against all constraints', function (): void {
        $constraint1 = $this->createMock(ConstraintInterface::class);
        $constraint1->expects($this->once())
                   ->method('isSatisfied')
                   ->with($this->event, $this->context)
                   ->willReturn(true);

        $constraint2 = $this->createMock(ConstraintInterface::class);
        $constraint2->expects($this->once())
                   ->method('isSatisfied')
                   ->with($this->event, $this->context)
                   ->willReturn(true);

        $constraintSet = new ConstraintSet([$constraint1, $constraint2]);

        expect($constraintSet->isSatisfied($this->event, $this->context))->toBeTrue();
    });

    // Tests that event validation fails if even one constraint in the set is not satisfied,
    // demonstrating the AND logic of constraint validation
    it('fails validation when any constraint fails', function (): void {
        $constraint1 = $this->createMock(ConstraintInterface::class);
        $constraint1->expects($this->once())
                   ->method('isSatisfied')
                   ->with($this->event, $this->context)
                   ->willReturn(true);

        $constraint2 = $this->createMock(ConstraintInterface::class);
        $constraint2->expects($this->once())
                   ->method('isSatisfied')
                   ->with($this->event, $this->context)
                   ->willReturn(false);

        $constraintSet = new ConstraintSet([$constraint1, $constraint2]);

        expect($constraintSet->isSatisfied($this->event, $this->context))->toBeFalse();
    });

    // Tests that an empty constraint set allows any event to pass validation,
    // ensuring the system works when no constraints are applied
    it('passes validation with empty constraint set', function (): void {
        $constraintSet = new ConstraintSet();

        expect($constraintSet->isSatisfied($this->event, $this->context))->toBeTrue();
    });
});

describe('ConstraintSetBuilder', function (): void {
    beforeEach(function (): void {
        $this->participant1 = new Participant('p1', 'Alice');
        $this->participant2 = new Participant('p2', 'Bob');
        $this->event = new Event([$this->participant1, $this->participant2]);
        $this->context = new SchedulingContext([$this->participant1, $this->participant2]);
    });

    // Tests the fluent builder API can create an empty constraint set
    // when no constraints are added before calling build()
    it('creates empty constraint set', function (): void {
        $constraintSet = ConstraintSet::create()->build();

        expect($constraintSet->isEmpty())->toBeTrue();
    });

    // Tests the builder's convenience method for adding the common no-repeat-pairings
    // constraint, ensuring it creates the correct constraint type
    it('adds no repeat pairings constraint', function (): void {
        $constraintSet = ConstraintSet::create()
            ->noRepeatPairings()
            ->build();

        expect($constraintSet->count())->toBe(1);
        expect($constraintSet->getConstraints()[0])->toBeInstanceOf(\MissionGaming\Tactician\Constraints\NoRepeatPairings::class);
    });

    // Tests the builder's ability to add custom constraints using a callback function,
    // providing flexibility for application-specific constraint logic
    it('adds custom constraint', function (): void {
        $constraintSet = ConstraintSet::create()
            ->custom(fn ($event, $context) => true, 'Test Constraint')
            ->build();

        expect($constraintSet->count())->toBe(1);
        expect($constraintSet->isSatisfied($this->event, $this->context))->toBeTrue();
    });

    // Tests that the fluent builder API allows chaining multiple constraint methods
    // to build complex constraint sets in a readable, declarative way
    it('chains multiple constraints', function (): void {
        $constraintSet = ConstraintSet::create()
            ->noRepeatPairings()
            ->custom(fn ($event, $context) => true)
            ->build();

        expect($constraintSet->count())->toBe(2);
    });

    // Tests that a custom constraint with a callback returning false will properly
    // cause event validation to fail, ensuring custom logic is respected
    it('custom constraint with false predicate fails validation', function (): void {
        $constraintSet = ConstraintSet::create()
            ->custom(fn ($event, $context) => false, 'Always Fail')
            ->build();

        expect($constraintSet->isSatisfied($this->event, $this->context))->toBeFalse();
    });
});
