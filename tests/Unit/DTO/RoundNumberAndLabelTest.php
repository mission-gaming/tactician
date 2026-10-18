<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\InvalidInputException;
use MissionGaming\Tactician\Exceptions\UnavailableValueException;
use MissionGaming\Tactician\LegStrategies\MirroredLegStrategy;
use MissionGaming\Tactician\LegStrategies\ShuffledLegStrategy;
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

describe('The round number of an event', function (): void {
    it('is there for every event of a round-robin schedule', function (int $size, RoundRobinOptions $options): void {
        $field = [];
        for ($i = 1; $i <= $size; ++$i) {
            $field[] = new Participant("p{$i}", "P{$i}");
        }
        $schedule = (new RoundRobinScheduler())->schedule($field, $options);

        foreach ($schedule as $event) {
            expect($event->hasRound())->toBeTrue()
                ->and($event->getRoundNumber())->toBe($event->getRound()?->getNumber())
                ->and($event->getRoundNumber())->toBeGreaterThanOrEqual(1)
                ->and($event->getRoundNumber())->toBeLessThanOrEqual((int) $schedule->getMetadataValue('total_rounds'));
        }
    })->with([
        'even, one leg' => [6, new RoundRobinOptions()],
        'odd, two mirrored legs' => [5, new RoundRobinOptions(legs: 2, strategy: new MirroredLegStrategy())],
        'three shuffled legs' => [4, new RoundRobinOptions(legs: 3, strategy: new ShuffledLegStrategy(seed: 7))],
    ]);

    it('is refused for an event without a round, which says so first', function (): void {
        $event = new Event([new Participant('a', 'A'), new Participant('b', 'B')]);

        expect($event->hasRound())->toBeFalse()
            ->and(fn() => $event->getRoundNumber())
            ->toThrow(UnavailableValueException::class, 'The event has no round, so it has no round number: ask hasRound() first');
    });

    it('is the number of the round given', function (): void {
        $event = new Event([new Participant('a', 'A'), new Participant('b', 'B')], new Round(4));

        expect($event->getRoundNumber())->toBe(4);
    });
});

describe('A participant without a label', function (): void {
    it('is named by its ID and says it has no label', function (): void {
        $unnamed = new Participant('team-7');
        $named = new Participant('team-8', 'Eight');

        expect($unnamed->getLabel())->toBe('team-7')
            ->and($unnamed->hasLabel())->toBeFalse()
            ->and($named->getLabel())->toBe('Eight')
            ->and($named->hasLabel())->toBeTrue()
            ->and((new Participant('x', ''))->hasLabel())->toBeTrue()
            ->and($unnamed->withSeed(3)->hasLabel())->toBeFalse();
    });

    it('is written without a label and read back without one', function (): void {
        $unnamed = new Participant('team-7', seed: 2, metadata: ['region' => 'north']);
        $data = $unnamed->toArray();
        $read = Participant::fromArray($data);

        expect($data)->toBe(['format_version' => 1, 'id' => 'team-7', 'seed' => 2, 'metadata' => ['region' => 'north']])
            ->and($read->hasLabel())->toBeFalse()
            ->and($read->toArray())->toBe($data)
            ->and(Participant::fromArray(['id' => 'x', 'label' => null])->hasLabel())->toBeFalse();
    });

    it('keeps the form of a participant with a label as it was', function (): void {
        expect((new Participant('a', 'A', 1))->toArray())
            ->toBe(['format_version' => 1, 'id' => 'a', 'label' => 'A', 'seed' => 1, 'metadata' => []]);
    });

    it('refuses a label that is neither a string nor null', function (): void {
        expect(fn() => Participant::fromArray(['id' => 'x', 'label' => 3]))
            ->toThrow(InvalidInputException::class, 'Participant label must be a string or null');
    });

    it('goes through a schedule and its JSON', function (): void {
        $field = [new Participant('a'), new Participant('b'), new Participant('c', 'Cee'), new Participant('d')];
        $schedule = (new RoundRobinScheduler())->schedule($field);
        $read = Schedule::fromJson($schedule->toJson());

        expect(array_map(fn(Participant $p): bool => $p->hasLabel(), $read->getEntrants()))->toBe([false, false, true, false])
            ->and(array_map(fn(Participant $p): string => $p->getLabel(), $read->getEntrants()))->toBe(['a', 'b', 'Cee', 'd']);
    });
});
