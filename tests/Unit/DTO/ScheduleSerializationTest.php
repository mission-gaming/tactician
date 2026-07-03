<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

describe('Schedule serialization', function (): void {
    beforeEach(function (): void {
        $this->alice = new Participant('p1', 'Alice', 1, ['club' => 'North']);
        $this->bob = new Participant('p2', 'Bob', 2);
        $this->carol = new Participant('p3', 'Carol');

        $this->schedule = new Schedule([
            new Event([$this->alice, $this->bob], new Round(1, ['stage' => 'group'])),
            new Event([$this->bob, $this->carol], new Round(2)),
            new Event([$this->alice, $this->carol]),
        ], ['algorithm' => 'round-robin', 'legs' => 1]);
    });

    it('round-trips through toArray and fromArray', function (): void {
        $restored = Schedule::fromArray($this->schedule->toArray());

        expect(count($restored))->toBe(3);
        expect($restored->getMetadata())->toBe($this->schedule->getMetadata());

        $originalEvents = $this->schedule->getEvents();
        $restoredEvents = $restored->getEvents();
        foreach ($originalEvents as $index => $original) {
            $restoredEvent = $restoredEvents[$index];

            $originalIds = array_map(fn (Participant $p) => $p->getId(), $original->getParticipants());
            $restoredIds = array_map(fn (Participant $p) => $p->getId(), $restoredEvent->getParticipants());
            expect($restoredIds)->toBe($originalIds);

            expect($restoredEvent->getRound()?->getNumber())->toBe($original->getRound()?->getNumber());
            expect($restoredEvent->getRound()?->getMetadata())->toBe($original->getRound()?->getMetadata() ?? null);
        }
    });

    it('preserves participant details across the round trip', function (): void {
        $restored = Schedule::fromArray($this->schedule->toArray());

        $restoredAlice = $restored->getEvents()[0]->getParticipants()[0];
        expect($restoredAlice->getId())->toBe('p1');
        expect($restoredAlice->getLabel())->toBe('Alice');
        expect($restoredAlice->getSeed())->toBe(1);
        expect($restoredAlice->getMetadataValue('club'))->toBe('North');
    });

    it('shares participant instances between events after restoring', function (): void {
        $restored = Schedule::fromArray($this->schedule->toArray());
        $events = $restored->getEvents();

        // Bob appears in events 0 and 1 and must be the same instance
        expect($events[0]->getParticipants()[1])->toBe($events[1]->getParticipants()[0]);
    });

    it('round-trips a generated schedule through JSON', function (): void {
        $participants = [
            new Participant('p1', 'Alice'),
            new Participant('p2', 'Bob'),
            new Participant('p3', 'Carol'),
            new Participant('p4', 'Dave'),
            new Participant('p5', 'Eve'),
        ];
        $schedule = (new RoundRobinScheduler())->schedule($participants, new RoundRobinOptions(legs: 2));

        $restored = Schedule::fromJson($schedule->toJson());

        expect(count($restored))->toBe(count($schedule));
        expect($restored->getMetadataValue('algorithm'))->toBe('round-robin');
        expect($restored->getMetadataValue('legs'))->toBe(2);

        $originalPairings = [];
        foreach ($schedule as $event) {
            $round = $event->getRound()?->getNumber();
            $ids = array_map(fn (Participant $p) => $p->getId(), $event->getParticipants());
            $originalPairings[] = $round . ':' . implode('-', $ids);
        }
        $restoredPairings = [];
        foreach ($restored as $event) {
            $round = $event->getRound()?->getNumber();
            $ids = array_map(fn (Participant $p) => $p->getId(), $event->getParticipants());
            $restoredPairings[] = $round . ':' . implode('-', $ids);
        }

        expect($restoredPairings)->toBe($originalPairings);
    });

    it('rejects malformed participant data', function (): void {
        expect(fn () => Schedule::fromArray(['participants' => [['label' => 'No ID']], 'events' => []]))
            ->toThrow(InvalidArgumentException::class);
    });

    it('rejects events referencing unknown participants', function (): void {
        $data = [
            'participants' => [['id' => 'p1', 'label' => 'Alice', 'seed' => null, 'metadata' => []]],
            'events' => [['participants' => ['p1', 'p9'], 'round' => null, 'metadata' => []]],
            'metadata' => [],
        ];

        expect(fn () => Schedule::fromArray($data))
            ->toThrow(InvalidArgumentException::class, 'unknown participant');
    });

    it('rejects invalid JSON', function (): void {
        expect(fn () => Schedule::fromJson('{not json'))
            ->toThrow(JsonException::class);
    });
});
