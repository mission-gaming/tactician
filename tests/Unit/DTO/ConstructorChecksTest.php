<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\InvalidInputException;
use MissionGaming\Tactician\Tests\Support\AwkwardIds;

// The value objects used to take two things no reader could do anything
// with: a participant whose ID is the empty string, and an event that lists
// one participant twice. `fromArray()` already refused the first; the
// constructors refused neither. Both are refused where the object is made.

describe('a participant ID', function (): void {
    it('cannot be the empty string', function (): void {
        expect(fn(): Participant => new Participant('', 'Nobody'))
            ->toThrow(InvalidInputException::class, 'A participant ID cannot be empty');
    });

    it('is still an InvalidArgumentException to code that catches that', function (): void {
        expect(fn(): Participant => new Participant('', 'Nobody'))->toThrow(InvalidArgumentException::class);
    });

    // Only the empty string is empty. An ID is a name and nothing else, so
    // one that PHP would call falsy, blank or zero is an ID like any other.
    it('may be any other string', function (array $ids): void {
        foreach ($ids as $id) {
            $participant = new Participant($id, 'Label');

            expect($participant->getId())->toBe($id)
                ->and($participant->withSeed(3)->getId())->toBe($id)
                ->and(Participant::fromArray($participant->toArray())->getId())->toBe($id);
        }
    })->with(AwkwardIds::dataset());

    it('is refused from plain data with a message that names the ID', function (): void {
        expect(fn(): Participant => Participant::fromArray(['id' => '', 'label' => 'Nobody']))
            ->toThrow(InvalidInputException::class, 'Participant data requires a non-empty string id, and a string label when it has one');
    });
});

describe('the participants of an event', function (): void {
    it('cannot be one participant twice', function (): void {
        $alice = new Participant('a', 'Alice');

        expect(fn(): Event => new Event([$alice, $alice]))->toThrow(
            InvalidInputException::class,
            'An event cannot list participant a twice: a participant is not paired with itself'
        );
    });

    // Two objects with one ID are one participant, whatever else differs
    it('cannot be two objects with one ID', function (): void {
        $alice = new Participant('a', 'Alice', 1);
        $again = new Participant('a', 'Alice of another label', 2, ['club' => 'North']);

        expect(fn(): Event => new Event([$alice, $again], new Round(1)))
            ->toThrow(InvalidInputException::class, 'An event cannot list participant a twice');
    });

    it('cannot repeat a participant among more than two', function (): void {
        $alice = new Participant('a', 'Alice');
        $bob = new Participant('b', 'Bob');
        $carol = new Participant('c', 'Carol');

        expect(fn(): Event => new Event([$alice, $bob, $alice]))
            ->toThrow(InvalidInputException::class, 'An event cannot list participant a twice')
            ->and((new Event([$alice, $bob, $carol]))->getParticipantCount())->toBe(3);
    });

    // IDs are compared as strings. Two that PHP reads as the same number,
    // or that differ only around a separator, are two participants.
    it('may be any two IDs that differ as strings, and never one ID twice', function (array $ids): void {
        $field = AwkwardIds::participants($ids);

        foreach ($field as $first) {
            foreach ($field as $second) {
                if ($first->getId() === $second->getId()) {
                    expect(fn(): Event => new Event([$first, new Participant($second->getId(), 'A copy')]))
                        ->toThrow(InvalidInputException::class, 'twice');

                    continue;
                }

                expect((new Event([$first, $second]))->getParticipants())->toBe([$first, $second]);
            }
        }
    })->with(AwkwardIds::dataset());

    it('are refused from plain data when an ID is listed twice', function (): void {
        $registry = ['a' => new Participant('a', 'Alice'), 'b' => new Participant('b', 'Bob')];
        $event = ['participants' => ['a', 'a'], 'round' => null, 'metadata' => []];

        expect(fn(): Event => Event::fromArray($event, $registry))
            ->toThrow(InvalidInputException::class, 'An event cannot list participant a twice')
            ->and(fn(): Schedule => Schedule::fromArray([
                'participants' => [['id' => 'a', 'label' => 'Alice'], ['id' => 'b', 'label' => 'Bob']],
                'events' => [$event],
            ]))->toThrow(InvalidInputException::class, 'An event cannot list participant a twice');
    });

    it('still need to be two at least', function (): void {
        expect(fn(): Event => new Event([new Participant('a', 'Alice')]))
            ->toThrow(InvalidInputException::class, 'An event must have at least 2 participants');
    });
});
