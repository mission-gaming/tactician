<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\CallableConstraint;
use MissionGaming\Tactician\Constraints\ConstraintInterface;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\SchedulingContext;

describe('CallableConstraint', function (): void {
    beforeEach(function (): void {
        $this->participant1 = new Participant('p1', 'Alice');
        $this->participant2 = new Participant('p2', 'Bob');
        $this->participant3 = new Participant('p3', 'Charlie');
        $this->event = new Event([$this->participant1, $this->participant2]);
        $this->context = new SchedulingContext([$this->participant1, $this->participant2, $this->participant3]);
    });

    it('implements ConstraintInterface', function (): void {
        $constraint = new CallableConstraint(fn ($event, $context) => true, 'Test');

        expect($constraint)->toBeInstanceOf(ConstraintInterface::class);
    });

    it('provides constraint name', function (): void {
        $name = 'My Custom Constraint';
        $constraint = new CallableConstraint(fn ($event, $context) => true, $name);

        expect($constraint->getName())->toBe($name);
    });

    it('executes predicate and returns true', function (): void {
        $predicate = fn ($event, $context) => true;
        $constraint = new CallableConstraint($predicate, 'Always True');

        $result = $constraint->isSatisfied($this->event, $this->context);

        expect($result)->toBeTrue();
    });

    it('executes predicate and returns false', function (): void {
        $predicate = fn ($event, $context) => false;
        $constraint = new CallableConstraint($predicate, 'Always False');

        $result = $constraint->isSatisfied($this->event, $this->context);

        expect($result)->toBeFalse();
    });

    it('passes correct event and context to predicate', function (): void {
        $capturedEvent = null;
        $capturedContext = null;

        $predicate = function ($event, $context) use (&$capturedEvent, &$capturedContext) {
            $capturedEvent = $event;
            $capturedContext = $context;

            return true;
        };

        $constraint = new CallableConstraint($predicate, 'Capture Args');
        $constraint->isSatisfied($this->event, $this->context);

        expect($capturedEvent)->toBe($this->event);
        expect($capturedContext)->toBe($this->context);
    });

    it('validates minimum participant count', function (): void {
        $predicate = fn ($event, $context) => count($event->getParticipants()) >= 2;
        $constraint = new CallableConstraint($predicate, 'Min 2 Participants');

        // Event with 2 participants should pass
        expect($constraint->isSatisfied($this->event, $this->context))->toBeTrue();

        // Event with 3 participants should also pass
        $threePersonEvent = new Event([$this->participant1, $this->participant2, $this->participant3]);
        expect($constraint->isSatisfied($threePersonEvent, $this->context))->toBeTrue();
    });

    it('validates participant availability in context', function (): void {
        $predicate = function ($event, $context) {
            foreach ($event->getParticipants() as $participant) {
                if (!in_array($participant, $context->getParticipants(), true)) {
                    return false;
                }
            }

            return true;
        };

        $constraint = new CallableConstraint($predicate, 'Participants Available');

        // Event with known participants should pass
        expect($constraint->isSatisfied($this->event, $this->context))->toBeTrue();

        // Event with unknown participant should fail
        $unknownParticipant = new Participant('unknown', 'Unknown');
        $unknownEvent = new Event([$unknownParticipant, $this->participant1]);
        expect($constraint->isSatisfied($unknownEvent, $this->context))->toBeFalse();
    });

    it('validates against event metadata', function (): void {
        $predicate = function ($event, $context) {
            $metadata = $event->getMetadata();

            return ($metadata['priority'] ?? 0) > 5;
        };

        $constraint = new CallableConstraint($predicate, 'High Priority Only');

        // Event with high priority should pass
        $highPriorityEvent = new Event([$this->participant1, $this->participant2], null, ['priority' => 10]);
        expect($constraint->isSatisfied($highPriorityEvent, $this->context))->toBeTrue();

        // Event with low priority should fail
        $lowPriorityEvent = new Event([$this->participant1, $this->participant2], null, ['priority' => 3]);
        expect($constraint->isSatisfied($lowPriorityEvent, $this->context))->toBeFalse();

        // Event with no priority should fail
        expect($constraint->isSatisfied($this->event, $this->context))->toBeFalse();
    });

    it('validates participant pairing logic', function (): void {
        $predicate = function ($event, $context) {
            $participants = $event->getParticipants();
            if (count($participants) !== 2) {
                return false;
            }

            // Don't allow Alice to pair with Bob specifically
            $names = array_map(fn ($p) => $p->getLabel(), $participants);

            return !(in_array('Alice', $names) && in_array('Bob', $names));
        };

        $constraint = new CallableConstraint($predicate, 'No Alice-Bob Pairing');

        // Alice-Bob pairing should fail
        expect($constraint->isSatisfied($this->event, $this->context))->toBeFalse();

        // Alice-Charlie pairing should pass
        $aliceCharlieEvent = new Event([$this->participant1, $this->participant3]);
        expect($constraint->isSatisfied($aliceCharlieEvent, $this->context))->toBeTrue();
    });

    it('handles predicate that throws exception', function (): void {
        $predicate = fn ($event, $context) => throw new RuntimeException('Predicate error');
        $constraint = new CallableConstraint($predicate, 'Throwing Predicate');

        expect(fn () => $constraint->isSatisfied($this->event, $this->context))
            ->toThrow(RuntimeException::class, 'Predicate error');
    });

    it('handles complex return values from predicate', function (): void {
        /** @var callable(\MissionGaming\Tactician\DTO\Event, \MissionGaming\Tactician\Scheduling\SchedulingContext): mixed $predicate */
        $predicate = fn ($event, $context) => 'truthy string'; // Non-boolean truthy value
        $constraint = new CallableConstraint($predicate, 'Non-Boolean Return');

        $result = $constraint->isSatisfied($this->event, $this->context);

        expect($result)->toBeTrue(); // PHP type coercion to boolean
    });

    it('handles predicate with additional parameters via closure', function (): void {
        $minimumCount = 3;
        $predicate = fn ($event, $context) => count($event->getParticipants()) >= $minimumCount;
        $constraint = new CallableConstraint($predicate, 'Closure Capture');

        expect($constraint->isSatisfied($this->event, $this->context))->toBeFalse(); // 2 < 3

        $threePersonEvent = new Event([$this->participant1, $this->participant2, $this->participant3]);
        expect($constraint->isSatisfied($threePersonEvent, $this->context))->toBeTrue(); // 3 >= 3
    });

    it('handles fast predicate execution', function (): void {
        $callCount = 0;
        $predicate = function ($event, $context) use (&$callCount) {
            ++$callCount;

            return true;
        };

        $constraint = new CallableConstraint($predicate, 'Call Counter');

        // Execute multiple times
        $constraint->isSatisfied($this->event, $this->context);
        $constraint->isSatisfied($this->event, $this->context);
        $constraint->isSatisfied($this->event, $this->context);

        expect($callCount)->toBe(3); // Ensure predicate is called each time
    });

    it('handles predicate with expensive computation', function (): void {
        $predicate = function ($event, $context) {
            // Simulate expensive computation
            $sum = 0;
            for ($i = 0; $i < 1000; ++$i) {
                $sum += $i;
            }

            return $sum > 0;
        };

        $constraint = new CallableConstraint($predicate, 'Expensive Constraint');

        $startTime = microtime(true);
        $result = $constraint->isSatisfied($this->event, $this->context);
        $endTime = microtime(true);

        expect($result)->toBeTrue();
        expect($endTime - $startTime)->toBeLessThan(1.0); // Should complete within reasonable time
    });
});
