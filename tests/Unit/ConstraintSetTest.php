<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Constraints\ConstraintInterface;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\SchedulingContext;

describe('ConstraintSet', function () {
    beforeEach(function () {
        $this->participant1 = new Participant('p1', 'Alice');
        $this->participant2 = new Participant('p2', 'Bob');
        $this->event = new Event([$this->participant1, $this->participant2]);
        $this->context = new SchedulingContext([$this->participant1, $this->participant2]);
    });

    it('creates an empty constraint set', function () {
        $constraintSet = new ConstraintSet();

        expect($constraintSet->isEmpty())->toBeTrue();
        expect($constraintSet->count())->toBe(0);
        expect($constraintSet->getConstraints())->toBe([]);
    });

    it('creates constraint set with constraints', function () {
        $constraint = Mockery::mock(ConstraintInterface::class);
        $constraintSet = new ConstraintSet([$constraint]);

        expect($constraintSet->isEmpty())->toBeFalse();
        expect($constraintSet->count())->toBe(1);
        expect($constraintSet->getConstraints())->toBe([$constraint]);
    });

    it('validates event against all constraints', function () {
        $constraint1 = Mockery::mock(ConstraintInterface::class);
        $constraint1->shouldReceive('isSatisfied')
                   ->with($this->event, $this->context)
                   ->once()
                   ->andReturn(true);

        $constraint2 = Mockery::mock(ConstraintInterface::class);
        $constraint2->shouldReceive('isSatisfied')
                   ->with($this->event, $this->context)
                   ->once()
                   ->andReturn(true);

        $constraintSet = new ConstraintSet([$constraint1, $constraint2]);

        expect($constraintSet->isSatisfied($this->event, $this->context))->toBeTrue();
    });

    it('fails validation when any constraint fails', function () {
        $constraint1 = Mockery::mock(ConstraintInterface::class);
        $constraint1->shouldReceive('isSatisfied')
                   ->with($this->event, $this->context)
                   ->once()
                   ->andReturn(true);

        $constraint2 = Mockery::mock(ConstraintInterface::class);
        $constraint2->shouldReceive('isSatisfied')
                   ->with($this->event, $this->context)
                   ->once()
                   ->andReturn(false);

        $constraintSet = new ConstraintSet([$constraint1, $constraint2]);

        expect($constraintSet->isSatisfied($this->event, $this->context))->toBeFalse();
    });

    it('passes validation with empty constraint set', function () {
        $constraintSet = new ConstraintSet();

        expect($constraintSet->isSatisfied($this->event, $this->context))->toBeTrue();
    });
});

describe('ConstraintSetBuilder', function () {
    beforeEach(function () {
        $this->participant1 = new Participant('p1', 'Alice');
        $this->participant2 = new Participant('p2', 'Bob');
        $this->event = new Event([$this->participant1, $this->participant2]);
        $this->context = new SchedulingContext([$this->participant1, $this->participant2]);
    });

    it('creates empty constraint set', function () {
        $constraintSet = ConstraintSet::create()->build();

        expect($constraintSet->isEmpty())->toBeTrue();
    });

    it('adds no repeat pairings constraint', function () {
        $constraintSet = ConstraintSet::create()
            ->noRepeatPairings()
            ->build();

        expect($constraintSet->count())->toBe(1);
        expect($constraintSet->getConstraints()[0])->toBeInstanceOf(\MissionGaming\Tactician\Constraints\NoRepeatPairings::class);
    });

    it('adds custom constraint', function () {
        $constraintSet = ConstraintSet::create()
            ->custom(fn($event, $context) => true, 'Test Constraint')
            ->build();

        expect($constraintSet->count())->toBe(1);
        expect($constraintSet->isSatisfied($this->event, $this->context))->toBeTrue();
    });

    it('chains multiple constraints', function () {
        $constraintSet = ConstraintSet::create()
            ->noRepeatPairings()
            ->custom(fn($event, $context) => true)
            ->build();

        expect($constraintSet->count())->toBe(2);
    });

    it('custom constraint with false predicate fails validation', function () {
        $constraintSet = ConstraintSet::create()
            ->custom(fn($event, $context) => false, 'Always Fail')
            ->build();

        expect($constraintSet->isSatisfied($this->event, $this->context))->toBeFalse();
    });
});
