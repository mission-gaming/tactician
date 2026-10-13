<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\ConsecutiveRoleConstraint;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;

function consecutiveRoleProbe(Event $event, Participant $participant): string
{
    return $event->getParticipants()[0] === $participant ? 'home' : 'away';
}

final class ConsecutiveRoleProbe
{
    public static function role(Event $event, Participant $participant): string
    {
        return consecutiveRoleProbe($event, $participant);
    }

    public function roleOf(Event $event, Participant $participant): string
    {
        return consecutiveRoleProbe($event, $participant);
    }

    public function __invoke(Event $event, Participant $participant): string
    {
        return consecutiveRoleProbe($event, $participant);
    }
}

describe('ConsecutiveRoleConstraint', function (): void {
    beforeEach(function (): void {
        $this->alice = new Participant('p1', 'Alice');
        $this->bob = new Participant('p2', 'Bob');
        $this->participants = [$this->alice, $this->bob];
    });

    it('rejects invalid construction', function (): void {
        expect(fn() => ConsecutiveRoleConstraint::homeAway(0))
            ->toThrow(InvalidArgumentException::class, 'at least 1');
        expect(fn() => new ConsecutiveRoleConstraint(2, 'not callable'))
            ->toThrow(InvalidArgumentException::class, 'callable');
    });

    it('is satisfied for participants with no history', function (): void {
        $constraint = ConsecutiveRoleConstraint::homeAway(1);
        $context = roundRobinContext($this->participants, []);

        expect($constraint->isSatisfied(new Event([$this->alice, $this->bob], new Round(1)), $context))
            ->toBeTrue();
    });

    // A participant is who its ID says it is. The events of the history
    // hold copies of Alice (a schedule read back from storage): she was
    // first-named in both, so a third event as first-named breaks a limit
    // of two, and an event as second-named does not.
    it('finds a participant in an event by ID, not as the same object', function (string $rule): void {
        $aliceAsStored = Participant::fromArray($this->alice->toArray());
        $bobAsStored = Participant::fromArray($this->bob->toArray());
        $constraint = $rule === 'home and away'
            ? ConsecutiveRoleConstraint::homeAway(2)
            : ConsecutiveRoleConstraint::position(2);
        $context = roundRobinContext($this->participants, [
            new Event([$aliceAsStored, $bobAsStored], new Round(1)),
            new Event([$aliceAsStored, $bobAsStored], new Round(2)),
        ]);

        expect($aliceAsStored)->not->toBe($this->alice)
            ->and($constraint->isSatisfied(new Event([$this->alice, $this->bob], new Round(3)), $context))->toBeFalse()
            ->and($constraint->isSatisfied(new Event([$aliceAsStored, $bobAsStored], new Round(3)), $context))->toBeFalse()
            ->and($constraint->isSatisfied(new Event([$this->bob, $this->alice], new Round(3)), $context))->toBeTrue();
    })->with([['home and away'], ['position']]);

    // Extracted roles are compared by identity, so an extractor that
    // yields the same value (even null) for consecutive events counts as
    // a streak - custom extractors must return distinct role values
    it('treats identical extracted values as a consecutive streak', function (): void {
        $constraint = new ConsecutiveRoleConstraint(1, fn() => null, 'Constant Role');
        $context = roundRobinContext($this->participants, [
            new Event([$this->alice, $this->bob], new Round(1)),
        ]);

        expect($constraint->isSatisfied(new Event([$this->alice, $this->bob], new Round(2)), $context))
            ->toBeFalse();
    });

    // The constructor takes anything PHP can call, not only a closure, and
    // the extractor is called in that form for every event. Each form below
    // gives Alice the first position twice, so a third time breaks the limit
    // and the second position does not.
    it('calls a role extractor given in any callable form', function (mixed $extractor): void {
        $constraint = new ConsecutiveRoleConstraint(2, $extractor);
        $context = roundRobinContext($this->participants, [
            new Event([$this->alice, $this->bob], new Round(1)),
            new Event([$this->alice, $this->bob], new Round(2)),
        ]);

        expect($constraint->isSatisfied(new Event([$this->alice, $this->bob], new Round(3)), $context))->toBeFalse()
            ->and($constraint->isSatisfied(new Event([$this->bob, $this->alice], new Round(3)), $context))->toBeTrue();
    })->with([
        // Pest calls a closure it finds in a dataset, so each returns the callable.
        'a closure' => [fn(): Closure => fn(Event $event, Participant $participant): string => $event->getParticipants()[0] === $participant ? 'home' : 'away'],
        'a first-class callable' => [fn(): Closure => consecutiveRoleProbe(...)],
        'a function name' => [fn(): string => 'consecutiveRoleProbe'],
        'a static method as a string' => [fn(): string => 'ConsecutiveRoleProbe::role'],
        // Built from the string: Rector rewrites a literal [class, method]
        // pair as a first-class callable, which is the form above.
        'a static method as an array' => [fn(): array => explode('::', 'ConsecutiveRoleProbe::role')],
        'a method of an object' => [fn(): array => [new ConsecutiveRoleProbe(), 'roleOf']],
        'an invokable object' => [fn(): ConsecutiveRoleProbe => new ConsecutiveRoleProbe()],
    ]);

    it('rejects what PHP cannot call as a role extractor', function (mixed $extractor): void {
        expect(fn() => new ConsecutiveRoleConstraint(2, $extractor))
            ->toThrow(InvalidArgumentException::class, 'Role extractor must be callable');
    })->with([
        'null' => [null],
        'a number' => [5],
        'a function that does not exist' => ['consecutiveRoleProbeThatIsNotThere'],
        'an instance method named statically' => [fn(): array => [ConsecutiveRoleProbe::class, 'roleOf']],
        'a method that does not exist' => [fn(): array => [new ConsecutiveRoleProbe(), 'missing']],
    ]);

    // An event with no round sorts as round 0, before every numbered round
    it('is satisfied when the history is empty and the event has no round', function (): void {
        $constraint = new ConsecutiveRoleConstraint(1, 'consecutiveRoleProbe');

        expect($constraint->isSatisfied(new Event([$this->alice, $this->bob]), roundRobinContext($this->participants, [])))
            ->toBeTrue();
    });
});
