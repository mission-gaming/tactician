<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\ConstraintInterface;
use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Constraints\ConstraintSetBuilder;
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

    it('handles multiple constraints', function (): void {
        $constraint1 = $this->createMock(ConstraintInterface::class);
        $constraint2 = $this->createMock(ConstraintInterface::class);
        $constraintSet = new ConstraintSet([$constraint1, $constraint2]);

        expect($constraintSet->count())->toBe(2);
        expect($constraintSet->isEmpty())->toBeFalse();
        expect($constraintSet->getConstraints())->toBe([$constraint1, $constraint2]);
    });

    it('validates event against all constraints successfully', function (): void {
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

    it('fails fast when first constraint fails', function (): void {
        $constraint1 = $this->createMock(ConstraintInterface::class);
        $constraint1->expects($this->once())
                   ->method('isSatisfied')
                   ->with($this->event, $this->context)
                   ->willReturn(false);

        $constraint2 = $this->createMock(ConstraintInterface::class);
        $constraint2->expects($this->never())
                   ->method('isSatisfied');

        $constraintSet = new ConstraintSet([$constraint1, $constraint2]);

        expect($constraintSet->isSatisfied($this->event, $this->context))->toBeFalse();
    });

    it('passes validation with empty constraint set', function (): void {
        $constraintSet = new ConstraintSet();

        expect($constraintSet->isSatisfied($this->event, $this->context))->toBeTrue();
    });

    it('creates builder instance', function (): void {
        $builder = ConstraintSet::create();

        expect($builder)->toBeInstanceOf(ConstraintSetBuilder::class);
    });

    it('maintains independence between builder instances', function (): void {
        $builder1 = ConstraintSet::create();
        $builder2 = ConstraintSet::create();

        expect($builder1)->not->toBe($builder2);
    });

    it('maintains constraints array integrity', function (): void {
        $constraint = $this->createMock(ConstraintInterface::class);
        $originalConstraints = [$constraint];
        $constraintSet = new ConstraintSet($originalConstraints);

        // Modify the original array
        $originalConstraints[] = $this->createMock(ConstraintInterface::class);

        // ConstraintSet should maintain its original state
        expect($constraintSet->count())->toBe(1);
        expect($constraintSet->getConstraints())->toHaveCount(1);
    });

    it('returns defensive copy of constraints array', function (): void {
        $constraint = $this->createMock(ConstraintInterface::class);
        $constraintSet = new ConstraintSet([$constraint]);

        $retrieved = $constraintSet->getConstraints();
        $retrieved[] = $this->createMock(ConstraintInterface::class);

        // Original constraint set should be unchanged
        expect($constraintSet->count())->toBe(1);
    });
});
