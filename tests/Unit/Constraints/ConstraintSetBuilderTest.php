<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\CallableConstraint;
use MissionGaming\Tactician\Constraints\ConstraintInterface;
use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Constraints\ConstraintSetBuilder;
use MissionGaming\Tactician\Constraints\NoRepeatPairings;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\SchedulingContext;

describe('ConstraintSetBuilder', function (): void {
    beforeEach(function (): void {
        $this->builder = new ConstraintSetBuilder();
        $this->participant1 = new Participant('p1', 'Alice');
        $this->participant2 = new Participant('p2', 'Bob');
        $this->event = new Event([$this->participant1, $this->participant2]);
        $this->context = new SchedulingContext([$this->participant1, $this->participant2]);
    });

    it('creates empty constraint set', function (): void {
        $constraintSet = $this->builder->build();

        expect($constraintSet)->toBeInstanceOf(ConstraintSet::class);
        expect($constraintSet->isEmpty())->toBeTrue();
        expect($constraintSet->count())->toBe(0);
    });

    it('adds constraints via add method', function (): void {
        $constraint = $this->createMock(ConstraintInterface::class);

        $constraintSet = $this->builder
            ->add($constraint)
            ->build();

        expect($constraintSet->count())->toBe(1);
        expect($constraintSet->getConstraints()[0])->toBe($constraint);
    });

    it('maintains builder chain immutability', function (): void {
        $constraint1 = $this->createMock(ConstraintInterface::class);
        $constraint2 = $this->createMock(ConstraintInterface::class);

        $builder1 = $this->builder->add($constraint1);
        $builder2 = $builder1->add($constraint2);

        expect($builder1)->toBe($builder2); // Same instance
        expect($builder1->build()->count())->toBe(2); // Both constraints present
    });

    it('adds no repeat pairings constraint', function (): void {
        $constraintSet = $this->builder
            ->noRepeatPairings()
            ->build();

        expect($constraintSet->count())->toBe(1);
        expect($constraintSet->getConstraints()[0])->toBeInstanceOf(NoRepeatPairings::class);
    });

    it('adds custom constraint with default name', function (): void {
        $predicate = fn ($event, $context) => true;

        $constraintSet = $this->builder
            ->custom($predicate)
            ->build();

        expect($constraintSet->count())->toBe(1);
        $constraint = $constraintSet->getConstraints()[0];
        expect($constraint)->toBeInstanceOf(CallableConstraint::class);
        expect($constraint->getName())->toBe('Custom Constraint');
    });

    it('adds custom constraint with specified name', function (): void {
        $predicate = fn ($event, $context) => true;
        $customName = 'My Special Constraint';

        $constraintSet = $this->builder
            ->custom($predicate, $customName)
            ->build();

        expect($constraintSet->count())->toBe(1);
        $constraint = $constraintSet->getConstraints()[0];
        expect($constraint)->toBeInstanceOf(CallableConstraint::class);
        expect($constraint->getName())->toBe($customName);
    });

    it('chains multiple different constraint methods', function (): void {
        $constraintSet = $this->builder
            ->noRepeatPairings()
            ->custom(fn ($event, $context) => true, 'Test Constraint')
            ->build();

        expect($constraintSet->count())->toBe(2);
        expect($constraintSet->getConstraints()[0])->toBeInstanceOf(NoRepeatPairings::class);
        expect($constraintSet->getConstraints()[1])->toBeInstanceOf(CallableConstraint::class);
    });

    it('chains multiple add method calls', function (): void {
        $constraint1 = $this->createMock(ConstraintInterface::class);
        $constraint2 = $this->createMock(ConstraintInterface::class);
        $constraint3 = $this->createMock(ConstraintInterface::class);

        $constraintSet = $this->builder
            ->add($constraint1)
            ->add($constraint2)
            ->add($constraint3)
            ->build();

        expect($constraintSet->count())->toBe(3);
        expect($constraintSet->getConstraints())->toBe([$constraint1, $constraint2, $constraint3]);
    });

    it('chains mixed method types in any order', function (): void {
        $customConstraint = $this->createMock(ConstraintInterface::class);

        $constraintSet = $this->builder
            ->custom(fn ($event, $context) => false, 'First Custom')
            ->add($customConstraint)
            ->noRepeatPairings()
            ->custom(fn ($event, $context) => true, 'Second Custom')
            ->build();

        expect($constraintSet->count())->toBe(4);
        expect($constraintSet->getConstraints()[0])->toBeInstanceOf(CallableConstraint::class);
        expect($constraintSet->getConstraints()[1])->toBe($customConstraint);
        expect($constraintSet->getConstraints()[2])->toBeInstanceOf(NoRepeatPairings::class);
        expect($constraintSet->getConstraints()[3])->toBeInstanceOf(CallableConstraint::class);
    });

    it('builds functional constraint set that validates correctly', function (): void {
        $constraintSet = $this->builder
            ->custom(fn ($event, $context) => true, 'Always True')
            ->custom(fn ($event, $context) => count($event->getParticipants()) >= 2, 'Min Participants')
            ->build();

        expect($constraintSet->isSatisfied($this->event, $this->context))->toBeTrue();
    });

    it('builds constraint set that properly fails validation', function (): void {
        $constraintSet = $this->builder
            ->custom(fn ($event, $context) => true, 'Always True')
            ->custom(fn ($event, $context) => false, 'Always False')
            ->build();

        expect($constraintSet->isSatisfied($this->event, $this->context))->toBeFalse();
    });

    it('allows multiple builds from same builder', function (): void {
        $this->builder->noRepeatPairings();

        $constraintSet1 = $this->builder->build();
        $constraintSet2 = $this->builder->build();

        expect($constraintSet1)->not->toBe($constraintSet2); // Different instances
        expect($constraintSet1->count())->toBe($constraintSet2->count()); // Same content
        expect($constraintSet1->count())->toBe(1);
    });

    it('allows adding constraints after initial build', function (): void {
        $this->builder->noRepeatPairings();
        $constraintSet1 = $this->builder->build();

        $this->builder->custom(fn ($event, $context) => true);
        $constraintSet2 = $this->builder->build();

        expect($constraintSet1->count())->toBe(1);
        expect($constraintSet2->count())->toBe(2);
    });
});
