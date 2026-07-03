<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\ConsecutiveRoleConstraint;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;

describe('ConsecutiveRoleConstraint', function (): void {
    beforeEach(function (): void {
        $this->alice = new Participant('p1', 'Alice');
        $this->bob = new Participant('p2', 'Bob');
        $this->participants = [$this->alice, $this->bob];
    });

    it('rejects invalid construction', function (): void {
        expect(fn () => ConsecutiveRoleConstraint::homeAway(0))
            ->toThrow(InvalidArgumentException::class, 'at least 1');
        expect(fn () => new ConsecutiveRoleConstraint(2, 'not callable'))
            ->toThrow(InvalidArgumentException::class, 'callable');
    });

    it('is satisfied for participants with no history', function (): void {
        $constraint = ConsecutiveRoleConstraint::homeAway(1);
        $context = roundRobinContext($this->participants, []);

        expect($constraint->isSatisfied(new Event([$this->alice, $this->bob], new Round(1)), $context))
            ->toBeTrue();
    });

    // Extracted roles are compared by identity, so an extractor that
    // yields the same value (even null) for consecutive events counts as
    // a streak - custom extractors must return distinct role values
    it('treats identical extracted values as a consecutive streak', function (): void {
        $constraint = new ConsecutiveRoleConstraint(1, fn () => null, 'Constant Role');
        $context = roundRobinContext($this->participants, [
            new Event([$this->alice, $this->bob], new Round(1)),
        ]);

        expect($constraint->isSatisfied(new Event([$this->alice, $this->bob], new Round(2)), $context))
            ->toBeFalse();
    });
});
