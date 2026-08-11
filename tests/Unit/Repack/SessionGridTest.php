<?php

declare(strict_types=1);

use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Repack\SessionGrid;

describe('SessionGrid', function (): void {
    it('emits slot times in UTC from wall-clock arithmetic in the declared zone', function (): void {
        $grid = SessionGrid::fromArray([
            'sessions' => ['2026-08-12 20:15', '2026-12-02 20:15'],
            'timezone' => 'Europe/London',
            'slot_interval' => 'PT25M',
            'slots_per_session' => 4,
        ]);

        // August is BST (UTC+1), December is GMT (UTC+0): the wall clock
        // stays 20:15 in London and the UTC emission shifts
        expect($grid->getSlotTime(0, 0)->format('Y-m-d H:i'))->toBe('2026-08-12 19:15');
        expect($grid->getSlotTime(0, 3)->format('Y-m-d H:i'))->toBe('2026-08-12 20:30');
        expect($grid->getSlotTime(1, 0)->format('Y-m-d H:i'))->toBe('2026-12-02 20:15');
        expect($grid->getSlotTime(0, 0)->getTimezone()->getName())->toBe('UTC');
    });

    it('applies per-session slot count overrides', function (): void {
        $grid = new SessionGrid(
            [
                new DateTimeImmutable('2026-08-12 20:00', new DateTimeZone('UTC')),
                new DateTimeImmutable('2026-08-19 20:00', new DateTimeZone('UTC')),
            ],
            new DateInterval('PT30M'),
            4,
            [1 => 6]
        );

        expect($grid->getSessionCount())->toBe(2);
        expect($grid->getSlotCount(0))->toBe(4);
        expect($grid->getSlotCount(1))->toBe(6);
        expect($grid->getPositionCount())->toBe(10);
        expect($grid->hasPosition(0, 3))->toBeTrue();
        expect($grid->hasPosition(0, 4))->toBeFalse();
        expect($grid->hasPosition(1, 5))->toBeTrue();
        expect($grid->hasPosition(2, 0))->toBeFalse();
    });

    it('round-trips through plain configuration data', function (): void {
        $config = [
            'sessions' => ['2026-08-12 20:15:00', '2026-08-19 20:15:00'],
            'timezone' => 'Europe/London',
            'slot_interval' => 'PT25M',
            'slots_per_session' => 4,
            'capacity_per_slot' => 7,
            'slots_per_session_overrides' => [1 => 6],
        ];

        $roundTripped = SessionGrid::fromArray(SessionGrid::fromArray($config)->toArray());
        expect($roundTripped->toArray())->toBe(SessionGrid::fromArray($config)->toArray());
        expect($roundTripped->getCapacityPerSlot())->toBe(7);
        expect($roundTripped->getSlotCount(1))->toBe(6);
    });

    it('rejects an empty session list', function (): void {
        new SessionGrid([], new DateInterval('PT25M'));
    })->throws(InvalidConfigurationException::class);

    it('rejects session starts out of order', function (): void {
        new SessionGrid(
            [
                new DateTimeImmutable('2026-08-19 20:00', new DateTimeZone('UTC')),
                new DateTimeImmutable('2026-08-12 20:00', new DateTimeZone('UTC')),
            ],
            new DateInterval('PT25M')
        );
    })->throws(InvalidConfigurationException::class);

    it('rejects session starts in different timezones', function (): void {
        new SessionGrid(
            [
                new DateTimeImmutable('2026-08-12 20:00', new DateTimeZone('UTC')),
                new DateTimeImmutable('2026-08-19 20:00', new DateTimeZone('Europe/London')),
            ],
            new DateInterval('PT25M')
        );
    })->throws(InvalidConfigurationException::class);

    it('rejects a slot interval that does not move time forward', function (): void {
        new SessionGrid(
            [new DateTimeImmutable('2026-08-12 20:00', new DateTimeZone('UTC'))],
            new DateInterval('PT0S'),
            2
        );
    })->throws(InvalidConfigurationException::class);

    it('rejects overrides for sessions that do not exist', function (): void {
        new SessionGrid(
            [new DateTimeImmutable('2026-08-12 20:00', new DateTimeZone('UTC'))],
            new DateInterval('PT25M'),
            4,
            [3 => 6]
        );
    })->throws(InvalidConfigurationException::class);

    it('rejects positions off the grid when asked for a slot time', function (): void {
        $grid = new SessionGrid(
            [new DateTimeImmutable('2026-08-12 20:00', new DateTimeZone('UTC'))],
            new DateInterval('PT25M'),
            4
        );
        $grid->getSlotTime(0, 4);
    })->throws(InvalidConfigurationException::class);

    it('rejects a session string carrying its own timezone', function (): void {
        SessionGrid::fromArray([
            'sessions' => ['2026-08-12T20:15:00+05:00'],
            'timezone' => 'Europe/London',
            'slot_interval' => 'PT25M',
        ]);
    })->throws(InvalidConfigurationException::class);
});
