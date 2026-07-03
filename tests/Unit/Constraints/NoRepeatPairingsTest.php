<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Constraints\NoRepeatPairings;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

describe('NoRepeatPairings', function (): void {
    beforeEach(function (): void {
        $this->alice = new Participant('p1', 'Alice');
        $this->bob = new Participant('p2', 'Bob');
        $this->carol = new Participant('p3', 'Carol');
        $this->dave = new Participant('p4', 'Dave');
        $this->participants = [$this->alice, $this->bob, $this->carol, $this->dave];
    });

    it('allows a first meeting', function (): void {
        $constraint = new NoRepeatPairings();
        $context = roundRobinContext($this->participants, []);

        expect($constraint->isSatisfied(new Event([$this->alice, $this->bob], new Round(1)), $context))->toBeTrue();
    });

    it('rejects a repeat pairing within the same leg', function (): void {
        $constraint = new NoRepeatPairings();
        $context = roundRobinContext($this->participants, [
            new Event([$this->alice, $this->bob], new Round(1)),
        ]);

        expect($constraint->isSatisfied(new Event([$this->bob, $this->alice], new Round(2)), $context))->toBeFalse();
    });

    it('allows the same pairing to repeat in a later leg', function (): void {
        $constraint = new NoRepeatPairings();

        // Leg 1 (rounds 1-3) is complete; we are generating leg 2
        $legOneEvents = [
            new Event([$this->alice, $this->bob], new Round(1)),
            new Event([$this->carol, $this->dave], new Round(1)),
            new Event([$this->alice, $this->carol], new Round(2)),
            new Event([$this->bob, $this->dave], new Round(2)),
            new Event([$this->alice, $this->dave], new Round(3)),
            new Event([$this->bob, $this->carol], new Round(3)),
        ];
        $context = roundRobinContext(
            $this->participants,
            $legOneEvents,
            legs: 2,
            currentLeg: 2
        );

        // The same pairing in leg 2 is allowed...
        expect($constraint->isSatisfied(new Event([$this->bob, $this->alice], new Round(4)), $context))->toBeTrue();

        // ...but a repeat within leg 2 is still rejected
        $context = $context->withEvents([new Event([$this->bob, $this->alice], new Round(4))]);
        expect($constraint->isSatisfied(new Event([$this->alice, $this->bob], new Round(6)), $context))->toBeFalse();
    });

    // Regression: the constraint previously checked all events across legs,
    // which made every multi-leg schedule impossible (leg 2 repeats every
    // leg-1 pairing by design) - leg 2 generated zero events.
    it('permits complete multi-leg schedules', function (): void {
        $constraints = ConstraintSet::create()->noRepeatPairings()->build();
        $schedule = (new RoundRobinScheduler($constraints))->schedule($this->participants, new RoundRobinOptions(legs: 2));

        expect(count($schedule))->toBe(12);

        $pairingCounts = [];
        foreach ($schedule as $event) {
            $ids = array_map(fn (Participant $p) => $p->getId(), $event->getParticipants());
            sort($ids);
            $key = implode('-', $ids);
            $pairingCounts[$key] = ($pairingCounts[$key] ?? 0) + 1;
        }

        // Every pairing appears exactly once per leg
        expect($pairingCounts)->toHaveCount(6);
        foreach ($pairingCounts as $count) {
            expect($count)->toBe(2);
        }
    });

    it('forbids repeats in any leg with the across-legs variant', function (): void {
        $constraint = new NoRepeatPairings(acrossLegs: true);

        $context = roundRobinContext(
            $this->participants,
            [new Event([$this->alice, $this->bob], new Round(1))],
            legs: 2,
            currentLeg: 2
        );

        expect($constraint->isSatisfied(new Event([$this->bob, $this->alice], new Round(4)), $context))->toBeFalse();
    });

    it('exposes a descriptive name', function (): void {
        expect((new NoRepeatPairings())->getName())->toBe('No Repeat Pairings');
    });

    // A degenerate event pairing a participant with themselves can never
    // count as a repeat meeting
    it('ignores self-pairings when checking repeats', function (): void {
        $constraint = new NoRepeatPairings();
        $context = roundRobinContext($this->participants, [
            new Event([$this->alice, $this->alice], new Round(1)),
        ]);

        expect($constraint->isSatisfied(new Event([$this->alice, $this->alice], new Round(2)), $context))
            ->toBeTrue();
    });
});
