<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\MinimumRestPeriodsConstraint;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Scheduling\SchedulingContext;

describe('MinimumRestPeriodsConstraint', function (): void {
    beforeEach(function (): void {
        $this->alice = new Participant('p1', 'Alice');
        $this->bob = new Participant('p2', 'Bob');
        $this->carol = new Participant('p3', 'Carol');
        $this->participants = [$this->alice, $this->bob, $this->carol];
    });

    it('rejects a minimum below 1', function (): void {
        expect(fn () => new MinimumRestPeriodsConstraint(0))
            ->toThrow(InvalidArgumentException::class);
    });

    it('allows a first meeting', function (): void {
        $constraint = new MinimumRestPeriodsConstraint(3);
        $context = new SchedulingContext($this->participants, []);

        $event = new Event([$this->alice, $this->bob], new Round(1));

        expect($constraint->isSatisfied($event, $context))->toBeTrue();
    });

    it('rejects a repeat meeting inside the rest window', function (): void {
        $constraint = new MinimumRestPeriodsConstraint(3);
        $context = new SchedulingContext($this->participants, [
            new Event([$this->alice, $this->bob], new Round(1)),
        ]);

        // Round difference of 2 is less than the required 3
        $event = new Event([$this->alice, $this->bob], new Round(3));

        expect($constraint->isSatisfied($event, $context))->toBeFalse();
    });

    it('allows a repeat meeting once the rest window has passed', function (): void {
        $constraint = new MinimumRestPeriodsConstraint(3);
        $context = new SchedulingContext($this->participants, [
            new Event([$this->alice, $this->bob], new Round(1)),
        ]);

        // Round difference of exactly 3 satisfies a 3-round minimum
        $event = new Event([$this->alice, $this->bob], new Round(4));

        expect($constraint->isSatisfied($event, $context))->toBeTrue();
    });

    it('measures rest from the latest meeting, not the first', function (): void {
        $constraint = new MinimumRestPeriodsConstraint(3);
        $context = new SchedulingContext($this->participants, [
            new Event([$this->alice, $this->bob], new Round(1)),
            new Event([$this->alice, $this->bob], new Round(5)),
        ]);

        $event = new Event([$this->alice, $this->bob], new Round(6));

        expect($constraint->isSatisfied($event, $context))->toBeFalse();
    });

    it('ignores meetings between other participants', function (): void {
        $constraint = new MinimumRestPeriodsConstraint(3);
        $context = new SchedulingContext($this->participants, [
            new Event([$this->alice, $this->carol], new Round(2)),
        ]);

        $event = new Event([$this->alice, $this->bob], new Round(3));

        expect($constraint->isSatisfied($event, $context))->toBeTrue();
    });

    it('exposes a descriptive name', function (): void {
        expect((new MinimumRestPeriodsConstraint(2))->getName())->toBe('Minimum Rest Periods (2 rounds)');
    });
});
