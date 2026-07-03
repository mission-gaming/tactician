<?php

declare(strict_types=1);

use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Timeline\TimelineDefinition;

describe('TimelineDefinition', function (): void {
    it('computes round-aligned slot times a round interval apart', function (): void {
        $timeline = new TimelineDefinition(
            new DateTimeImmutable('2026-08-01 19:00', new DateTimeZone('UTC')),
            new DateInterval('P7D')
        );

        expect($timeline->getSlotsPerRound())->toBe(1);
        expect($timeline->getSlotTime(1)->format('Y-m-d H:i'))->toBe('2026-08-01 19:00');
        expect($timeline->getSlotTime(2)->format('Y-m-d H:i'))->toBe('2026-08-08 19:00');

        // Round numbers are absolute offsets: round 5 lands four intervals
        // out whether or not earlier rounds exist in the schedule
        expect($timeline->getSlotTime(5)->format('Y-m-d H:i'))->toBe('2026-08-29 19:00');
    });

    it('computes staggered slot times within a round', function (): void {
        $timeline = new TimelineDefinition(
            new DateTimeImmutable('2026-08-01 18:00', new DateTimeZone('UTC')),
            new DateInterval('P7D'),
            slotsPerRound: 3,
            slotInterval: new DateInterval('PT1H')
        );

        expect($timeline->getSlotTime(1, 0)->format('H:i'))->toBe('18:00');
        expect($timeline->getSlotTime(1, 1)->format('H:i'))->toBe('19:00');
        expect($timeline->getSlotTime(1, 2)->format('H:i'))->toBe('20:00');
        expect($timeline->getSlotTime(2, 1)->format('Y-m-d H:i'))->toBe('2026-08-08 19:00');
    });

    // Interval arithmetic is wall-clock in the definition's timezone: a
    // weekly 19:00 London kickoff stays 19:00 locally across the March
    // DST transition, so its UTC hour shifts
    it('keeps wall-clock times across DST and emits UTC', function (): void {
        $timeline = new TimelineDefinition(
            new DateTimeImmutable('2026-03-22 19:00', new DateTimeZone('Europe/London')),
            new DateInterval('P7D')
        );

        $beforeDst = $timeline->getSlotTime(1); // GMT: 19:00 London = 19:00 UTC
        $afterDst = $timeline->getSlotTime(2);  // BST: 19:00 London = 18:00 UTC

        expect($beforeDst->getTimezone()->getName())->toBe('UTC');
        expect($beforeDst->format('Y-m-d H:i'))->toBe('2026-03-22 19:00');
        expect($afterDst->format('Y-m-d H:i'))->toBe('2026-03-29 18:00');
    });

    it('rejects invalid slot configurations', function (): void {
        $start = new DateTimeImmutable('2026-08-01 19:00', new DateTimeZone('UTC'));
        $week = new DateInterval('P7D');

        expect(fn () => new TimelineDefinition($start, $week, slotsPerRound: 0))
            ->toThrow(InvalidConfigurationException::class, 'at least 1 slot');
        expect(fn () => new TimelineDefinition($start, $week, slotsPerRound: 3))
            ->toThrow(InvalidConfigurationException::class, 'slot interval');
        expect(fn () => new TimelineDefinition($start, new DateInterval('PT0S')))
            ->toThrow(InvalidConfigurationException::class, 'move time forward');
        expect(fn () => new TimelineDefinition($start, $week, 2, new DateInterval('PT0S')))
            ->toThrow(InvalidConfigurationException::class, 'move time forward');
    });

    it('rejects out-of-range rounds and slots', function (): void {
        $timeline = new TimelineDefinition(
            new DateTimeImmutable('2026-08-01 19:00', new DateTimeZone('UTC')),
            new DateInterval('P7D'),
            slotsPerRound: 2,
            slotInterval: new DateInterval('PT1H')
        );

        expect(fn () => $timeline->getSlotTime(0))
            ->toThrow(InvalidConfigurationException::class, '1-based');
        expect(fn () => $timeline->getSlotTime(1, 2))
            ->toThrow(InvalidConfigurationException::class, 'out of range');
        expect(fn () => $timeline->getSlotTime(1, -1))
            ->toThrow(InvalidConfigurationException::class, 'out of range');
    });

    it('round-trips through plain configuration data', function (): void {
        $config = [
            'start' => '2026-08-01 18:00:00',
            'timezone' => 'Europe/London',
            'round_interval' => 'P7D',
            'slots_per_round' => 3,
            'slot_interval' => 'PT1H',
        ];

        $timeline = TimelineDefinition::fromArray($config);

        expect($timeline->toArray())->toBe($config);
        expect($timeline->getSlotTime(1, 1)->format('Y-m-d H:i'))->toBe('2026-08-01 18:00'); // 19:00 BST = 18:00 UTC
    });

    it('serializes round-aligned timelines without a slot interval', function (): void {
        $timeline = TimelineDefinition::fromArray([
            'start' => '2026-08-01 19:00:00',
            'timezone' => 'UTC',
            'round_interval' => 'P1W', // normalized to days
        ]);

        expect($timeline->toArray())->toBe([
            'start' => '2026-08-01 19:00:00',
            'timezone' => 'UTC',
            'round_interval' => 'P7D',
            'slots_per_round' => 1,
        ]);
    });

    it('exposes its configuration through accessors', function (): void {
        $start = new DateTimeImmutable('2026-08-01 18:00', new DateTimeZone('Europe/London'));
        $roundInterval = new DateInterval('P7D');
        $slotInterval = new DateInterval('PT1H');
        $timeline = new TimelineDefinition($start, $roundInterval, 3, $slotInterval);

        expect($timeline->getStart())->toBe($start);
        expect($timeline->getRoundInterval())->toBe($roundInterval);
        expect($timeline->getSlotsPerRound())->toBe(3);
        expect($timeline->getSlotInterval())->toBe($slotInterval);

        expect((new TimelineDefinition($start, $roundInterval))->getSlotInterval())->toBeNull();
    });

    it('serializes every duration component canonically', function (): void {
        $timeline = TimelineDefinition::fromArray([
            'start' => '2026-08-01 18:00:00',
            'timezone' => 'UTC',
            'round_interval' => 'P1Y2M3DT4H5M6S',
        ]);

        expect($timeline->toArray()['round_interval'])->toBe('P1Y2M3DT4H5M6S');
    });

    it('rejects malformed configuration', function (): void {
        $valid = [
            'start' => '2026-08-01 19:00:00',
            'timezone' => 'UTC',
            'round_interval' => 'P7D',
        ];

        expect(fn () => TimelineDefinition::fromArray(['round_interval' => 'P7D']))
            ->toThrow(InvalidConfigurationException::class, 'timezone');
        expect(fn () => TimelineDefinition::fromArray([...$valid, 'timezone' => 'Neverland/Nowhere']))
            ->toThrow(InvalidConfigurationException::class, 'not parseable');
        expect(fn () => TimelineDefinition::fromArray([...$valid, 'round_interval' => 'a week']))
            ->toThrow(InvalidConfigurationException::class, 'ISO 8601');
        expect(fn () => TimelineDefinition::fromArray([...$valid, 'round_interval' => 7]))
            ->toThrow(InvalidConfigurationException::class, 'ISO 8601');
        expect(fn () => TimelineDefinition::fromArray([...$valid, 'slots_per_round' => 'three']))
            ->toThrow(InvalidConfigurationException::class, 'integer');
    });

    // The declared timezone field is authoritative for wall-clock
    // arithmetic; an embedded zone in the start string would silently
    // win during parsing and undermine the DST guarantee
    it('rejects start strings carrying their own timezone', function (): void {
        $base = ['round_interval' => 'P7D', 'timezone' => 'Europe/London'];

        expect(fn () => TimelineDefinition::fromArray([...$base, 'start' => '2026-08-01T19:00:00Z']))
            ->toThrow(InvalidConfigurationException::class, 'carries its own timezone');
        expect(fn () => TimelineDefinition::fromArray([...$base, 'start' => '2026-08-01 19:00:00 +02:00']))
            ->toThrow(InvalidConfigurationException::class, 'carries its own timezone');
        expect(fn () => TimelineDefinition::fromArray([...$base, 'start' => '2026-08-01 19:00:00 America/New_York']))
            ->toThrow(InvalidConfigurationException::class, 'carries its own timezone');

        // A redundant-but-consistent embedded zone is fine
        $consistent = TimelineDefinition::fromArray([...$base, 'start' => '2026-08-01 19:00:00 Europe/London']);
        expect($consistent->getSlotTime(1)->format('H:i'))->toBe('18:00'); // BST -> UTC
    });
});
