<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\InvalidInputException;
use MissionGaming\Tactician\Scheduling\PotDrawOptions;
use MissionGaming\Tactician\Scheduling\PotDrawScheduler;
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Scheduling\SwissOptions;
use MissionGaming\Tactician\Scheduling\SwissScheduler;
use MissionGaming\Tactician\Tests\Support\AwkwardIds;
use Random\Engine\Mt19937;
use Random\Randomizer;

// A schedule did not know the list it was generated from. Serialized, it
// listed its participants in the order they first appear in the events, so
// five entrants p1..p5 came back as p2, p5, p3, p4, p1 and the seeding, which
// is the order of the list, was gone. A schedule now keeps its entrants in
// their order, and the order survives toArray()/fromArray() and JSON.

/**
 * The IDs of the participants, in order.
 *
 * @param array<Participant> $participants
 *
 * @return list<string>
 */
function idsOf(array $participants): array
{
    return array_values(array_map(static fn(Participant $participant): string => $participant->getId(), $participants));
}

/**
 * Entrants p1, p2, ... in that order.
 *
 * @return list<Participant>
 */
function entrantField(int $count): array
{
    $field = [];
    for ($i = 1; $i <= $count; ++$i) {
        $field[] = new Participant("p{$i}", "Participant {$i}", $i);
    }

    return $field;
}

describe('the entrants of a generated schedule', function (): void {
    it('are the participants the round-robin scheduler was given, in their order', function (int $size, ?int $seed, int $legs): void {
        $field = entrantField($size);
        $scheduler = new RoundRobinScheduler(null, $seed === null ? null : new Randomizer(new Mt19937($seed)));

        $schedule = $scheduler->schedule($field, new RoundRobinOptions(legs: $legs));

        expect($schedule->getEntrants())->toBe($field);
    })->with([2, 3, 4, 5, 8, 11])->with([null, 7, 2026])->with([1, 2]);

    it('are the participants the Swiss scheduler was given, in their order', function (int $size): void {
        $field = entrantField($size);

        $schedule = (new SwissScheduler())->schedule($field, new SwissOptions(rounds: 2));

        expect($schedule->getEntrants())->toBe($field);
    })->with([4, 5, 8, 9]);

    it('are the participants the pot draw scheduler was given, in their order', function (): void {
        $field = entrantField(8);

        $schedule = (new PotDrawScheduler())->schedule($field, new PotDrawOptions(pots: 2, opponentsPerPot: 2, seed: 11));

        expect($schedule->getEntrants())->toBe($field);
    });

    // The defect as it was reported: the order of first appearance is
    // another order than the one the schedule was generated from
    it('come back in their order from JSON, which is not the order the events name them in', function (): void {
        $field = entrantField(5);
        $schedule = (new RoundRobinScheduler())->schedule($field);

        $firstAppearance = [];
        foreach ($schedule->getEvents() as $event) {
            foreach ($event->getParticipants() as $participant) {
                $firstAppearance[$participant->getId()] = true;
            }
        }

        $restored = Schedule::fromJson($schedule->toJson());

        expect(array_keys($firstAppearance))->not->toBe(['p1', 'p2', 'p3', 'p4', 'p5'])
            ->and(idsOf($restored->getEntrants()))->toBe(['p1', 'p2', 'p3', 'p4', 'p5'])
            ->and(array_column($schedule->toArray()['participants'], 'id'))->toBe(['p1', 'p2', 'p3', 'p4', 'p5'])
            ->and($restored)->toEqual($schedule)
            ->and($restored->toJson())->toBe($schedule->toJson());
    });

    it('come back in their order whatever the IDs and whatever the order', function (array $ids): void {
        $field = AwkwardIds::participants($ids);
        $random = new Randomizer(new Mt19937(53));

        for ($i = 0; $i < 20; ++$i) {
            $order = $random->shuffleArray($field);
            $schedule = (new RoundRobinScheduler())->schedule($order);

            $restored = Schedule::fromArray($schedule->toArray());
            $stored = Schedule::fromJson($schedule->toJson());

            expect(idsOf($schedule->getEntrants()))->toBe(idsOf($order))
                ->and(idsOf($restored->getEntrants()))->toBe(idsOf($order))
                ->and(idsOf($stored->getEntrants()))->toBe(idsOf($order));
        }
    })->with(AwkwardIds::dataset());

    it('share their objects with the events of a restored schedule', function (): void {
        $restored = Schedule::fromJson((new RoundRobinScheduler())->schedule(entrantField(4))->toJson());
        $byId = [];
        foreach ($restored->getEntrants() as $entrant) {
            $byId[$entrant->getId()] = $entrant;
        }

        foreach ($restored->getEvents() as $event) {
            foreach ($event->getParticipants() as $participant) {
                expect($participant)->toBe($byId[$participant->getId()]);
            }
        }
    });
});

describe('the entrants of a schedule built by hand', function (): void {
    beforeEach(function (): void {
        $this->alice = new Participant('a', 'Alice');
        $this->bob = new Participant('b', 'Bob');
        $this->carol = new Participant('c', 'Carol');
        $this->dan = new Participant('d', 'Dan');
    });

    it('are read from the events, in the order of first appearance, when none are given', function (): void {
        $schedule = new Schedule([
            new Event([$this->carol, $this->alice], new Round(1)),
            new Event([$this->bob, $this->carol], new Round(2)),
        ]);

        expect($schedule->getEntrants())->toBe([$this->carol, $this->alice, $this->bob])
            ->and((new Schedule())->getEntrants())->toBe([]);
    });

    it('are the list given, in its order, with an entrant who has no event', function (): void {
        $schedule = new Schedule(
            [new Event([$this->carol, $this->alice], new Round(1))],
            ['stage' => 'groups'],
            [$this->alice, $this->bob, $this->carol, $this->dan]
        );

        $restored = Schedule::fromJson($schedule->toJson());

        expect($schedule->getEntrants())->toBe([$this->alice, $this->bob, $this->carol, $this->dan])
            ->and(array_column($schedule->toArray()['participants'], 'id'))->toBe(['a', 'b', 'c', 'd'])
            ->and(idsOf($restored->getEntrants()))->toBe(['a', 'b', 'c', 'd'])
            ->and($restored)->toEqual($schedule);
    });

    it('are a list whatever keys the array given has', function (): void {
        $schedule = new Schedule([], [], ['second' => $this->bob, 'first' => $this->alice]);

        expect($schedule->getEntrants())->toBe([$this->bob, $this->alice]);
    });

    it('cannot hold one ID twice', function (): void {
        expect(fn(): Schedule => new Schedule([], [], [$this->alice, new Participant('a', 'Alice again')]))
            ->toThrow(InvalidInputException::class, 'A schedule cannot list entrant a twice');
    });

    it('must hold every participant of every event', function (): void {
        expect(fn(): Schedule => new Schedule(
            [new Event([$this->alice, $this->dan], new Round(1))],
            [],
            [$this->alice, $this->bob]
        ))->toThrow(InvalidInputException::class, 'An event of the schedule has participant d, who is not among its entrants');
    });

    // Participants are matched by ID. The list is the entrants' objects.
    it('are the objects of the list where an event holds another object with an entrant\'s ID', function (): void {
        $renamed = new Participant('a', 'Alice, renamed');
        $schedule = new Schedule([new Event([$renamed, $this->bob])], [], [$this->alice, $this->bob]);

        expect($schedule->getEntrants())->toBe([$this->alice, $this->bob])
            ->and($schedule->toArray()['participants'][0]['label'])->toBe('Alice');
    });

    it('stay as they are when an event is added, and a newcomer follows them', function (): void {
        $schedule = new Schedule([new Event([$this->bob, $this->alice])], ['k' => 'v'], [$this->alice, $this->bob, $this->carol]);

        $same = $schedule->addEvent(new Event([$this->carol, $this->bob]));
        $more = $schedule->addEvent(new Event([$this->dan, $this->alice]));

        expect($same->getEntrants())->toBe([$this->alice, $this->bob, $this->carol])
            ->and($more->getEntrants())->toBe([$this->alice, $this->bob, $this->carol, $this->dan])
            ->and($more->getMetadata())->toBe(['k' => 'v'])
            ->and($schedule->getEntrants())->toBe([$this->alice, $this->bob, $this->carol])
            ->and((new Schedule())->addEvent(new Event([$this->dan, $this->alice]))->getEntrants())->toBe([$this->dan, $this->alice]);
    });
});

describe('the participants of serialized schedule data', function (): void {
    it('are the entrants, in the order of the data', function (): void {
        $schedule = Schedule::fromArray([
            'participants' => [
                ['id' => 'c', 'label' => 'Carol'],
                ['id' => 'a', 'label' => 'Alice'],
                ['id' => 'b', 'label' => 'Bob'],
            ],
            'events' => [['participants' => ['a', 'b'], 'round' => ['number' => 1, 'metadata' => []], 'metadata' => []]],
        ]);

        expect(idsOf($schedule->getEntrants()))->toBe(['c', 'a', 'b']);
    });

    it('cannot list one ID twice', function (): void {
        expect(fn(): Schedule => Schedule::fromArray([
            'participants' => [['id' => 'a', 'label' => 'Alice'], ['id' => 'a', 'label' => 'Alice again']],
        ]))->toThrow(InvalidInputException::class, 'Schedule data lists participant a twice');
    });
});
