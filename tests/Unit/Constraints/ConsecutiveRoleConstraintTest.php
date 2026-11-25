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

    // With more than two participants in an event the two factories differ:
    // homeAway() gives every participant after the first-named the one role
    // `away`, and position() gives each position a role of its own. Carol is
    // third-named and then second-named, which is a streak of `away` and no
    // streak of positions; nobody else repeats a position.
    it('gives every later position one role under homeAway() and each its own under position()', function (): void {
        $carol = new Participant('p3', 'Carol');
        $participants = [$this->alice, $this->bob, $carol];
        $context = roundRobinContext($participants, [
            new Event([$this->alice, $this->bob, $carol], new Round(1)),
        ]);
        $candidate = new Event([$this->bob, $carol, $this->alice], new Round(2));

        expect(ConsecutiveRoleConstraint::homeAway(1)->isSatisfied($candidate, $context))->toBeFalse()
            ->and(ConsecutiveRoleConstraint::position(1)->isSatisfied($candidate, $context))->toBeTrue();
    });

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

    // The class docblock: a streak is counted over the participant's events
    // in round order, the candidate included, an event without a round as
    // round 0; events of one round keep their order, the candidate after
    // the history. The extractor is asked about every one of them, in that
    // order, on every question. The histories are listed in any order and
    // with rounds that tie, and the candidate may come before them.
    it('counts streaks in round order and asks the extractor about every event in it', function (): void {
        $randomizer = new Random\Randomizer(new Random\Engine\Xoshiro256StarStar(254));
        $field = [new Participant('a', 'A'), new Participant('b', 'B'), new Participant('c', 'C')];
        $sides = static fn(): array => $randomizer->getInt(0, 1) === 0 ? [$field[0], $field[1]] : [$field[1], $field[0]];
        $round = static fn(): ?Round => match ($n = $randomizer->getInt(0, 6)) {
            0 => null,
            default => new Round($n),
        };

        $rejected = 0;
        for ($case = 0; $case < 500; ++$case) {
            $history = [];
            for ($e = $randomizer->getInt(0, 7); $e > 0; --$e) {
                $history[] = $randomizer->getInt(0, 4) === 0
                    ? new Event([$field[0], $field[2]], $round())
                    : new Event($sides(), $round());
            }
            if ($randomizer->getInt(0, 1) === 0) {
                usort($history, fn(Event $x, Event $y): int => ($x->getRound()?->getNumber() ?? 0) <=> ($y->getRound()?->getNumber() ?? 0));
            }
            $candidate = new Event($sides(), $round());
            $limit = $randomizer->getInt(1, 3);

            $asked = [];
            $constraint = new ConsecutiveRoleConstraint(
                $limit,
                static function (Event $event, Participant $participant) use (&$asked): string {
                    $asked[] = [$event, $participant->getId()];

                    return $event->getParticipants()[0]->getId() === $participant->getId() ? 'home' : 'away';
                }
            );
            $verdict = $constraint->isSatisfied($candidate, roundRobinContext($field, $history));

            // The rule, stated as a stable sort of each participant's events
            $expectedAsked = [];
            $expectedVerdict = true;
            foreach ($candidate->getParticipants() as $participant) {
                $own = array_values(array_filter($history, fn(Event $event): bool => $event->hasParticipant($participant)));
                $own[] = $candidate;
                usort($own, fn(Event $x, Event $y): int => ($x->getRound()?->getNumber() ?? 0) <=> ($y->getRound()?->getNumber() ?? 0));
                $run = 0;
                $previous = null;
                foreach ($own as $event) {
                    $expectedAsked[] = [$event, $participant->getId()];
                    $role = $event->getParticipants()[0]->getId() === $participant->getId() ? 'home' : 'away';
                    $run = $role === $previous ? $run + 1 : 1;
                    $previous = $role;
                    if ($run > $limit) {
                        $expectedVerdict = false;
                    }
                }
                if (!$expectedVerdict) {
                    break;
                }
            }

            expect($verdict)->toBe($expectedVerdict, "case {$case}")
                ->and($asked)->toBe($expectedAsked, "case {$case}");
            if (!$verdict) {
                ++$rejected;
            }
        }

        expect($rejected)->toBeGreaterThan(100)->toBeLessThan(400);
    });

    // SchedulingContext accepts events under any keys and keeps them, so a
    // participant's history can come with string keys, in round order and
    // so not re-sorted. The streak is the same as for the same events in a
    // list.
    it('counts the streak of a history the context holds under string keys', function (): void {
        $carol = new Participant('p3', 'Carol');
        $dave = new Participant('p4', 'Dave');
        $field = [$this->alice, $this->bob, $carol, $dave];
        $history = [
            'first' => new Event([$this->alice, $this->bob], new Round(1)),
            'second' => new Event([$this->alice, $carol], new Round(2)),
        ];
        $candidate = new Event([$this->alice, $dave], new Round(3));
        $constraint = ConsecutiveRoleConstraint::homeAway(2);

        expect($constraint->isSatisfied($candidate, roundRobinContext($field, $history)))->toBeFalse()
            ->and($constraint->isSatisfied($candidate, roundRobinContext($field, array_values($history))))->toBeFalse()
            ->and(ConsecutiveRoleConstraint::homeAway(3)->isSatisfied($candidate, roundRobinContext($field, $history)))->toBeTrue();
    });

    // An event with no round sorts as round 0, before every numbered round
    it('is satisfied when the history is empty and the event has no round', function (): void {
        $constraint = new ConsecutiveRoleConstraint(1, 'consecutiveRoleProbe');

        expect($constraint->isSatisfied(new Event([$this->alice, $this->bob]), roundRobinContext($this->participants, [])))
            ->toBeTrue();
    });
});
