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

    it('creates an empty constraint set', function (): void {
        $constraintSet = new ConstraintSet();

        expect($constraintSet->isEmpty())->toBeTrue();
        expect($constraintSet->count())->toBe(0);
        expect($constraintSet->getConstraints())->toBe([]);
    });

    it('creates constraint set with constraints', function (): void {
        $constraint = $this->createMock(ConstraintInterface::class);
        $constraintSet = new ConstraintSet([$constraint]);

        expect($constraintSet->isEmpty())->toBeFalse();
        expect($constraintSet->count())->toBe(1);
        expect($constraintSet->getConstraints())->toBe([$constraint]);
    });

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

    it('creates empty constraint set', function (): void {
        $constraintSet = ConstraintSet::create()->build();

        expect($constraintSet->isEmpty())->toBeTrue();
    });

    it('adds no repeat pairings constraint', function (): void {
        $constraintSet = ConstraintSet::create()
            ->noRepeatPairings()
            ->build();

        expect($constraintSet->count())->toBe(1);
        expect($constraintSet->getConstraints()[0])->toBeInstanceOf(\MissionGaming\Tactician\Constraints\NoRepeatPairings::class);
    });

    it('adds custom constraint', function (): void {
        $constraintSet = ConstraintSet::create()
            ->custom(fn ($event, $context) => true, 'Test Constraint')
            ->build();

        expect($constraintSet->count())->toBe(1);
        expect($constraintSet->isSatisfied($this->event, $this->context))->toBeTrue();
    });

    it('chains multiple constraints', function (): void {
        $constraintSet = ConstraintSet::create()
            ->noRepeatPairings()
            ->custom(fn ($event, $context) => true)
            ->build();

        expect($constraintSet->count())->toBe(2);
    });

    it('custom constraint with false predicate fails validation', function (): void {
        $constraintSet = ConstraintSet::create()
            ->custom(fn ($event, $context) => false, 'Always Fail')
            ->build();

        expect($constraintSet->isSatisfied($this->event, $this->context))->toBeFalse();
    });
});
