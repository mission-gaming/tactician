<?php

declare(strict_types=1);

use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Repack\SessionGrid;

/**
 * The reason of the configuration error a call ends in.
 *
 * @param Closure(): mixed $call
 */
function gridErrorReason(Closure $call): ?InvalidConfigurationReason
{
    try {
        $call();
    } catch (InvalidConfigurationException $e) {
        return $e->getReason();
    }

    throw new LogicException('The call was expected to throw an InvalidConfigurationException and did not.');
}

/**
 * An instant-based grid of two sessions, three slots then four.
 *
 * @throws InvalidConfigurationException
 * @throws Exception
 */
function instantGrid(?int $capacityPerSlot = 1): SessionGrid
{
    return new SessionGrid(
        [
            new DateTimeImmutable('2026-08-12 20:00', new DateTimeZone('Europe/London')),
            new DateTimeImmutable('2026-08-19 20:00', new DateTimeZone('Europe/London')),
        ],
        new DateInterval('PT30M'),
        3,
        [1 => 4],
        $capacityPerSlot
    );
}

describe('A shape-only SessionGrid', function (): void {
    it('has the positions of its shape and says it has no instants', function (): void {
        $grid = SessionGrid::shapeOnly(2, 3, [1 => 4], 5);

        expect($grid->hasInstants())->toBeFalse();
        expect($grid->getSessionCount())->toBe(2);
        expect($grid->getSlotCount(0))->toBe(3);
        expect($grid->getSlotCount(1))->toBe(4);
        expect($grid->getPositionCount())->toBe(7);
        expect($grid->hasPosition(1, 3))->toBeTrue();
        expect($grid->hasPosition(0, 3))->toBeFalse();
        expect($grid->hasPosition(2, 0))->toBeFalse();
        expect($grid->getCapacityPerSlot())->toBe(5);
    });

    it('defaults to one slot per session and a capacity of 1, as the constructor does', function (): void {
        $grid = SessionGrid::shapeOnly(3);

        expect($grid->getPositionCount())->toBe(3);
        expect($grid->getCapacityPerSlot())->toBe(1);
    });

    it('says an instant-based grid has instants', function (): void {
        expect(instantGrid()->hasInstants())->toBeTrue();
    });

    it('refuses every accessor that returns a time, with a reason of its own', function (Closure $ask): void {
        $grid = SessionGrid::shapeOnly(2, 3);

        expect(gridErrorReason(fn() => $ask($grid)))->toBe(InvalidConfigurationReason::GridWithoutInstants);
        expect(fn() => $ask($grid))->toThrow(InvalidConfigurationException::class, 'A shape-only grid has no instants');
    })->with([
        'getSessionStart()' => [fn(SessionGrid $grid) => $grid->getSessionStart(0)],
        'getSlotInterval()' => [fn(SessionGrid $grid) => $grid->getSlotInterval()],
        'getSlotTime()' => [fn(SessionGrid $grid) => $grid->getSlotTime(0, 0)],
        'positionOf() given an instant' => [fn(SessionGrid $grid) => $grid->positionOf(new DateTimeImmutable('2026-08-12 19:00', new DateTimeZone('UTC')))],
        // The grid is asked for a time before the position is looked at
        'getSlotTime() off the grid' => [fn(SessionGrid $grid) => $grid->getSlotTime(9, 9)],
    ]);

    it('rejects a shape with no session, no slot or no capacity', function (Closure $build, InvalidConfigurationReason $reason): void {
        expect(gridErrorReason($build))->toBe($reason);
    })->with([
        'zero sessions' => [fn() => SessionGrid::shapeOnly(0), InvalidConfigurationReason::ValueOutOfRange],
        'negative sessions' => [fn() => SessionGrid::shapeOnly(-2), InvalidConfigurationReason::ValueOutOfRange],
        'zero slots' => [fn() => SessionGrid::shapeOnly(2, 0), InvalidConfigurationReason::ValueOutOfRange],
        'zero capacity' => [fn() => SessionGrid::shapeOnly(2, 2, [], 0), InvalidConfigurationReason::ValueOutOfRange],
        'an override for a session it does not have' => [fn() => SessionGrid::shapeOnly(2, 2, [2 => 3]), InvalidConfigurationReason::PositionOutOfRange],
        'an override below one slot' => [fn() => SessionGrid::shapeOnly(2, 2, [1 => 0]), InvalidConfigurationReason::ValueOutOfRange],
    ]);

    it('rejects the two halves of the constructor mixed', function (): void {
        // A count with an interval, and session starts without one
        expect(gridErrorReason(fn() => new SessionGrid(2, new DateInterval('PT30M'))))
            ->toBe(InvalidConfigurationReason::IncompatibleOptions);
        expect(gridErrorReason(fn() => new SessionGrid([new DateTimeImmutable('2026-08-12 20:00', new DateTimeZone('UTC'))], null)))
            ->toBe(InvalidConfigurationReason::IncompatibleOptions);
    });

    it('round-trips through plain data with session_count in place of the times', function (): void {
        $grid = SessionGrid::shapeOnly(2, 3, [1 => 4], 5);

        expect($grid->toArray())->toBe([
            'session_count' => 2,
            'slots_per_session' => 3,
            'capacity_per_slot' => 5,
            'slots_per_session_overrides' => [1 => 4],
        ]);

        $again = SessionGrid::fromArray($grid->toArray());
        expect($again->hasInstants())->toBeFalse();
        expect($again->toArray())->toBe($grid->toArray());
    });

    it('survives JSON, where the override keys become strings', function (): void {
        $grid = SessionGrid::shapeOnly(3, 2, [2 => 5], null);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) json_encode($grid->toArray()), true);

        expect(SessionGrid::fromArray($decoded)->toArray())->toBe($grid->toArray());
    });

    it('rejects malformed shape-only data', function (array $config, InvalidConfigurationReason $reason): void {
        expect(gridErrorReason(fn() => SessionGrid::fromArray($config)))->toBe($reason);
    })->with([
        'a count that is not an integer' => [['session_count' => '2'], InvalidConfigurationReason::WrongValueType],
        'a null count' => [['session_count' => null], InvalidConfigurationReason::WrongValueType],
        'a count of zero' => [['session_count' => 0], InvalidConfigurationReason::ValueOutOfRange],
        'a timezone' => [['session_count' => 2, 'timezone' => 'UTC'], InvalidConfigurationReason::IncompatibleOptions],
        'a slot interval' => [['session_count' => 2, 'slot_interval' => 'PT30M'], InvalidConfigurationReason::IncompatibleOptions],
        'slots that are not an integer' => [['session_count' => 2, 'slots_per_session' => '3'], InvalidConfigurationReason::WrongValueType],
        'a capacity word it does not know' => [['session_count' => 2, 'capacity_per_slot' => 'infinite'], InvalidConfigurationReason::WrongValueType],
    ]);

    it('reads data with sessions as instant-based whatever else it carries', function (): void {
        // session_count has always been an unknown key next to sessions,
        // and unknown keys have always been ignored
        $grid = SessionGrid::fromArray([
            'sessions' => ['2026-08-12 20:00'],
            'timezone' => 'UTC',
            'slot_interval' => 'PT30M',
            'session_count' => 9,
        ]);

        expect($grid->hasInstants())->toBeTrue();
        expect($grid->getSessionCount())->toBe(1);
    });

    it('still rejects data with neither sessions nor a count, as it always has', function (): void {
        expect(fn() => SessionGrid::fromArray(['timezone' => 'UTC', 'slot_interval' => 'PT30M']))
            ->toThrow(InvalidConfigurationException::class, 'sessions must be a non-empty list of datetime strings');
        expect(fn() => SessionGrid::fromArray(['sessions' => null, 'session_count' => 2]))
            ->toThrow(InvalidConfigurationException::class, 'sessions must be a non-empty list of datetime strings');
    });
});

describe('SessionGrid positions', function (): void {
    it('numbers positions in grid order and finds them again', function (SessionGrid $grid): void {
        $expected = [[0, 0], [0, 1], [0, 2], [1, 0], [1, 1], [1, 2], [1, 3]];

        foreach ($expected as $ordinal => [$session, $slot]) {
            expect($grid->ordinalOf($session, $slot))->toBe($ordinal);
            expect($grid->positionOf($ordinal))->toBe(['session' => $session, 'slot' => $slot]);
        }
        expect($grid->positionOf(count($expected)))->toBeNull();
        expect($grid->positionOf(-1))->toBeNull();
    })->with([
        'shape-only' => [fn() => SessionGrid::shapeOnly(2, 3, [1 => 4])],
        'instant-based' => [fn() => instantGrid()],
    ]);

    it('rejects the ordinal of a position that is not on the grid', function (int $session, int $slot): void {
        $grid = SessionGrid::shapeOnly(2, 3, [1 => 4]);

        expect(gridErrorReason(fn() => $grid->ordinalOf($session, $slot)))->toBe(InvalidConfigurationReason::PositionOutOfRange);
        expect(fn() => $grid->ordinalOf($session, $slot))->toThrow(InvalidConfigurationException::class, 'The position is not on this grid');
    })->with([[0, 3], [2, 0], [-1, 0], [0, -1], [1, 4]]);

    it('finds the position of every slot time, and of no other instant', function (): void {
        $grid = instantGrid();

        for ($session = 0; $session < $grid->getSessionCount(); ++$session) {
            for ($slot = 0; $slot < $grid->getSlotCount($session); ++$slot) {
                expect($grid->positionOf($grid->getSlotTime($session, $slot)))->toBe(['session' => $session, 'slot' => $slot]);
            }
        }

        // 20:00 in London on 12 August is 19:00 UTC; a minute later is no slot
        expect($grid->positionOf(new DateTimeImmutable('2026-08-12 19:00:00', new DateTimeZone('UTC'))))->toBe(['session' => 0, 'slot' => 0]);
        expect($grid->positionOf(new DateTimeImmutable('2026-08-12 19:01:00', new DateTimeZone('UTC'))))->toBeNull();
        // The session has three slots: a fourth interval on is off the grid
        expect($grid->positionOf(new DateTimeImmutable('2026-08-12 20:30:00', new DateTimeZone('UTC'))))->toBeNull();
    });

    it('compares instants, not the timezone they are written in', function (): void {
        $grid = instantGrid();

        // The second slot of the first session, written in three zones
        expect($grid->positionOf(new DateTimeImmutable('2026-08-12 20:30', new DateTimeZone('Europe/London'))))->toBe(['session' => 0, 'slot' => 1]);
        expect($grid->positionOf(new DateTimeImmutable('2026-08-12 19:30', new DateTimeZone('UTC'))))->toBe(['session' => 0, 'slot' => 1]);
        expect($grid->positionOf(new DateTimeImmutable('2026-08-13 04:30', new DateTimeZone('Asia/Tokyo'))))->toBe(['session' => 0, 'slot' => 1]);
    });

    it('returns the first position in grid order when two share an instant', function (): void {
        // The first session runs three hours on; the second starts one hour in
        $grid = new SessionGrid(
            [
                new DateTimeImmutable('2026-08-12 18:00', new DateTimeZone('UTC')),
                new DateTimeImmutable('2026-08-12 19:00', new DateTimeZone('UTC')),
            ],
            new DateInterval('PT1H'),
            3
        );

        expect($grid->positionOf(new DateTimeImmutable('2026-08-12 19:00', new DateTimeZone('UTC'))))->toBe(['session' => 0, 'slot' => 1]);
        expect($grid->positionOf(new DateTimeImmutable('2026-08-12 21:00', new DateTimeZone('UTC'))))->toBe(['session' => 1, 'slot' => 2]);
    });
});

describe('SessionGrid capacity', function (): void {
    it('keeps the default of 1', function (): void {
        $grid = new SessionGrid([new DateTimeImmutable('2026-08-12 20:00', new DateTimeZone('UTC'))], new DateInterval('PT30M'));

        expect($grid->getCapacityPerSlot())->toBe(1);
        expect($grid->getCapacityLimit())->toBe(1);
        expect($grid->hasUnboundedCapacity())->toBeFalse();
    });

    it('takes null as unbounded', function (SessionGrid $grid): void {
        expect($grid->hasUnboundedCapacity())->toBeTrue();
        expect($grid->getCapacityLimit())->toBeNull();
        expect(gridErrorReason(fn() => $grid->getCapacityPerSlot()))->toBe(InvalidConfigurationReason::UnboundedCapacity);
        expect(fn() => $grid->getCapacityPerSlot())->toThrow(InvalidConfigurationException::class, 'The capacity of this grid is unbounded');
    })->with([
        'instant-based' => [fn() => instantGrid(null)],
        'shape-only' => [fn() => SessionGrid::shapeOnly(2, 3, [], null)],
        'from plain data' => [fn() => SessionGrid::fromArray(['session_count' => 2, 'capacity_per_slot' => SessionGrid::UNBOUNDED])],
    ]);

    it('writes an unbounded capacity as a word and reads it back', function (): void {
        $grid = instantGrid(null);

        expect($grid->toArray()['capacity_per_slot'])->toBe('unbounded');
        expect(SessionGrid::fromArray($grid->toArray())->hasUnboundedCapacity())->toBeTrue();
        expect(SessionGrid::fromArray($grid->toArray())->toArray())->toBe($grid->toArray());
    });

    it('still reads a missing or null capacity as 1', function (array $extra): void {
        $grid = SessionGrid::fromArray([
            'sessions' => ['2026-08-12 20:00'],
            'timezone' => 'UTC',
            'slot_interval' => 'PT30M',
            ...$extra,
        ]);

        expect($grid->getCapacityPerSlot())->toBe(1);
    })->with([
        'missing' => [[]],
        'null' => [['capacity_per_slot' => null]],
    ]);

    it('writes an instant-based grid of finite capacity exactly as before', function (): void {
        expect(instantGrid(7)->toArray())->toBe([
            'sessions' => ['2026-08-12 20:00:00', '2026-08-19 20:00:00'],
            'timezone' => 'Europe/London',
            'slot_interval' => 'PT30M',
            'slots_per_session' => 3,
            'capacity_per_slot' => 7,
            'slots_per_session_overrides' => [1 => 4],
        ]);
    });
});
