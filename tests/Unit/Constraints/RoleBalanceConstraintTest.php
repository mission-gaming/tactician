<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Constraints\RoleBalanceConstraint;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Scheduling\SchedulingContext;

describe('RoleBalanceConstraint', function (): void {
    beforeEach(function (): void {
        $this->alice = new Participant('p1', 'Alice');
        $this->bob = new Participant('p2', 'Bob');
        $this->carol = new Participant('p3', 'Carol');
        $this->dave = new Participant('p4', 'Dave');
        $this->participants = [$this->alice, $this->bob, $this->carol, $this->dave];
    });

    it('rejects a max imbalance below 1', function (): void {
        expect(fn () => new RoleBalanceConstraint(0))
            ->toThrow(InvalidArgumentException::class);
    });

    it('accepts an event that keeps roles within the imbalance limit', function (): void {
        // Alice: 1 home, 1 away so far
        $context = new SchedulingContext($this->participants, [
            new Event([$this->alice, $this->bob], new Round(1)),
            new Event([$this->carol, $this->alice], new Round(2)),
        ]);

        $constraint = RoleBalanceConstraint::homeAway(1);
        $event = new Event([$this->alice, $this->dave], new Round(3)); // 2 home, 1 away

        expect($constraint->isSatisfied($event, $context))->toBeTrue();
    });

    it('rejects an event that pushes a participant past the home limit', function (): void {
        $constraint = RoleBalanceConstraint::homeAway(2);

        // Alice: 1 home, 0 away so far -> a second home game reaches the limit
        $context = new SchedulingContext($this->participants, [
            new Event([$this->alice, $this->bob], new Round(1)),
        ]);
        $secondHome = new Event([$this->alice, $this->carol], new Round(2)); // would be 2 home, 0 away

        expect($constraint->isSatisfied($secondHome, $context))->toBeTrue();

        // Alice: 2 home, 0 away so far -> a third home game exceeds the limit
        $context = $context->withEvents([$secondHome]);
        $thirdHome = new Event([$this->alice, $this->dave], new Round(3)); // would be 3 home, 0 away

        expect($constraint->isSatisfied($thirdHome, $context))->toBeFalse();
    });

    it('rejects an event that pushes a participant past the away limit', function (): void {
        // Bob: 0 home, 2 away so far
        $context = new SchedulingContext($this->participants, [
            new Event([$this->alice, $this->bob], new Round(1)),
            new Event([$this->carol, $this->bob], new Round(2)),
        ]);

        $constraint = RoleBalanceConstraint::homeAway(2);
        $event = new Event([$this->dave, $this->bob], new Round(3)); // would be 0 home, 3 away

        expect($constraint->isSatisfied($event, $context))->toBeFalse();
    });

    it('ignores events with more than two participants', function (): void {
        $context = new SchedulingContext($this->participants, []);
        $constraint = RoleBalanceConstraint::homeAway(1);

        $event = new Event([$this->alice, $this->bob, $this->carol], new Round(1));

        expect($constraint->isSatisfied($event, $context))->toBeTrue();
    });

    it('exposes a descriptive name', function (): void {
        expect(RoleBalanceConstraint::homeAway(2)->getName())->toBe('Home/Away balance limit (2)');
    });

    it('bounds home/away drift in a generated mirrored two-leg schedule', function (): void {
        $constraints = ConstraintSet::create()
            ->add(RoleBalanceConstraint::homeAway(3))
            ->build();

        $schedule = (new RoundRobinScheduler($constraints))->schedule($this->participants, 2, 2);

        expect(count($schedule))->toBe(12);

        // The mirrored second leg reverses every pairing, so completed
        // schedules end perfectly balanced.
        $homeCounts = [];
        $awayCounts = [];
        foreach ($schedule as $event) {
            $eventParticipants = $event->getParticipants();
            $homeId = $eventParticipants[0]->getId();
            $awayId = $eventParticipants[1]->getId();
            $homeCounts[$homeId] = ($homeCounts[$homeId] ?? 0) + 1;
            $awayCounts[$awayId] = ($awayCounts[$awayId] ?? 0) + 1;
        }

        foreach ($this->participants as $participant) {
            $id = $participant->getId();
            expect($homeCounts[$id] ?? 0)->toBe($awayCounts[$id] ?? 0);
        }
    });

    // Regression: before round-parity role alternation, the circle method's
    // fixed seat was home every round of a leg and rotating players had
    // half-field-length role streaks, making any meaningful limit
    // unsatisfiable with RoundRobinScheduler.
    it('is satisfiable at the documented limits for any field size', function (int $fieldSize, int $limit): void {
        $participants = [];
        for ($i = 1; $i <= $fieldSize; ++$i) {
            $participants[] = new Participant("p{$i}", "Player {$i}");
        }

        $constraints = ConstraintSet::create()
            ->add(RoleBalanceConstraint::homeAway($limit))
            ->build();

        foreach ([1, 2] as $legs) {
            $schedule = (new RoundRobinScheduler($constraints))->schedule($participants, 2, $legs);

            $expectedEvents = intdiv($fieldSize * ($fieldSize - 1), 2) * $legs;
            expect(count($schedule))->toBe($expectedEvents);
        }
    })
        // Limit 3 for even fields; the bye shifts one parity, so odd fields need 4
        ->with([[4, 3], [6, 3], [12, 3], [5, 4], [9, 4]]);
});
