<?php

declare(strict_types=1);

use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Repack\SessionGrid;
use MissionGaming\Tactician\Timeline\BlackoutRule;
use MissionGaming\Tactician\Timeline\TimelineDefinition;
use MissionGaming\Tactician\Timeline\ZonedTime;

// The rule (ADR 0023): a timezone declared in plain data is a region
// identifier or an explicit offset. PHP reads an abbreviation (`BST`) as a
// fixed offset all year, so `'timezone' => 'BST'` was +01:00 in January as
// well, and a caller who meant London got kickoffs an hour out for half the
// year with no error. An abbreviation is refused, whichever one, bar the
// names of UTC itself (`Z`, `UTC`, `UCT`), and an object held in one is not
// written as plain data that would be refused.

/**
 * The configuration error a call failed with, or null when it did not fail.
 *
 * @param Closure(): mixed $call
 */
function timezoneRefusal(Closure $call): ?InvalidConfigurationException
{
    try {
        $call();
    } catch (InvalidConfigurationException $exception) {
        return $exception;
    }

    return null;
}

/**
 * Every name PHP accepts as a timezone and holds as an abbreviation.
 *
 * @return list<string>
 */
function timezoneAbbreviationNames(): array
{
    $names = [];
    foreach (array_keys(DateTimeZone::listAbbreviations()) as $name) {
        try {
            $timezone = new DateTimeZone((string) $name);
        } catch (Throwable) {
            continue;
        }

        if (($timezone->__serialize()['timezone_type'] ?? null) === 2) {
            $names[] = (string) $name;
        }
    }

    return $names;
}

describe('A declared timezone that is an abbreviation', function (): void {
    it('is refused with its fixed offset in the context', function (string $abbreviation, string $offset): void {
        $exception = timezoneRefusal(fn() => ZonedTime::parse('2026-08-01 19:00', $abbreviation, 'start'));

        expect($exception?->getReason())->toBe(InvalidConfigurationReason::TimezoneAbbreviation)
            ->and($exception?->getContext())->toBe([
                'start' => '2026-08-01 19:00',
                'timezone' => $abbreviation,
                'fixed_offset' => $offset,
            ])
            ->and($exception?->getMessage())->toContain("The timezone '{$abbreviation}' is an abbreviation")
            ->and($exception?->getMessage())->toContain("declare a region identifier (such as 'Europe/London')");
    })->with([
        'BST, which is not London in winter' => ['BST', '+01:00'],
        'EST' => ['EST', '-05:00'],
        'CET' => ['CET', '+01:00'],
        'CEST' => ['CEST', '+02:00'],
        'an abbreviation in lower case' => ['bst', '+01:00'],
        // An offset of zero is no excuse: GMT is London's winter time, and
        // a caller who writes it for London is an hour out all summer
        'GMT, which is not London in summer' => ['GMT', '+00:00'],
        'GMT in lower case' => ['gmt', '+00:00'],
        'WET' => ['WET', '+00:00'],
    ]);

    // Not an abbreviation of a place: `Z` is what ISO 8601, and every
    // kickoff the library writes, ends with, and 0.2.3 wrote it as the
    // timezone of a start parsed from such a string
    it('is accepted when it names UTC itself', function (string $name): void {
        $time = ZonedTime::parse('2026-01-15 19:00', $name, 'start');

        expect($time->format(DATE_ATOM))->toBe('2026-01-15T19:00:00+00:00')
            ->and(ZonedTime::parse('2026-07-15 19:00', $name, 'start')->format('P'))->toBe('+00:00');
    })->with(['Z', 'z', 'utc', 'Utc', 'UCT', 'uct']);

    it('is refused for every abbreviation PHP knows', function (): void {
        $names = timezoneAbbreviationNames();

        expect($names)->toContain('bst')->and(count($names))->toBeGreaterThan(100);

        foreach ($names as $name) {
            if (in_array(strtoupper($name), ['Z', 'UTC', 'UCT'], true)) {
                continue;
            }

            expect(timezoneRefusal(fn() => ZonedTime::parse('2026-08-01 19:00', $name, 'start'))?->getReason())
                ->toBe(InvalidConfigurationReason::TimezoneAbbreviation, $name);
        }
    });

    it('is refused before the datetime string is looked at', function (): void {
        expect(timezoneRefusal(fn() => ZonedTime::parse('tomorrow', 'BST', 'start'))?->getReason())
            ->toBe(InvalidConfigurationReason::TimezoneAbbreviation)
            ->and(timezoneRefusal(fn() => ZonedTime::parse('2026-08-01 19:00 +05:00', 'BST', 'start'))?->getReason())
            ->toBe(InvalidConfigurationReason::TimezoneAbbreviation);
    });

    it('is refused by every configuration that declares a timezone', function (Closure $config): void {
        expect(timezoneRefusal($config)?->getReason())->toBe(InvalidConfigurationReason::TimezoneAbbreviation);
    })->with([
        'a timeline definition' => [fn() => TimelineDefinition::fromArray([
            'start' => '2026-08-01 19:00:00',
            'timezone' => 'BST',
            'round_interval' => 'P7D',
        ])],
        'a session grid' => [fn() => SessionGrid::fromArray([
            'sessions' => ['2026-08-12 20:00'],
            'timezone' => 'BST',
            'slot_interval' => 'PT30M',
            'slots_per_session' => 2,
        ])],
        'a blackout window' => [fn() => BlackoutRule::fromArray(['windows' => [[
            'from' => '2026-11-09 00:00',
            'to' => '2026-11-17 00:00',
            'timezone' => 'GMT',
        ]]])],
    ]);
});

describe('A declared timezone that is a region identifier or an offset', function (): void {
    it('is accepted for every identifier of the timezone database', function (): void {
        $identifiers = DateTimeZone::listIdentifiers();

        expect(count($identifiers))->toBeGreaterThan(300);

        foreach ($identifiers as $identifier) {
            expect(ZonedTime::parse('2026-08-01 19:00', $identifier, 'start')->getTimezone()->getName())->toBe($identifier);
        }
    });

    it('is accepted as an offset', function (string $offset, string $name): void {
        expect(ZonedTime::parse('2026-08-01 19:00', $offset, 'start')->format(DATE_ATOM))->toBe('2026-08-01T19:00:00' . $name);
    })->with([
        'positive' => ['+01:00', '+01:00'],
        'negative' => ['-05:00', '-05:00'],
        'without a colon' => ['-0500', '-05:00'],
        'zero' => ['+00:00', '+00:00'],
    ]);

    // What the abbreviation got wrong: a region follows the clock changes
    it('follows the clocks of the region', function (): void {
        $winter = ZonedTime::parse('2026-01-15 19:00', 'Europe/London', 'start');
        $summer = ZonedTime::parse('2026-07-15 19:00', 'Europe/London', 'start');

        expect($winter->format('P'))->toBe('+00:00')->and($summer->format('P'))->toBe('+01:00');
    });
});

describe('Writing a time held in a timezone abbreviation', function (): void {
    it('is refused by a timeline definition, which works with it as a fixed offset', function (): void {
        $timeline = new TimelineDefinition(
            new DateTimeImmutable('2026-01-15 19:00', new DateTimeZone('BST')),
            new DateInterval('P7D')
        );

        $exception = timezoneRefusal(fn() => $timeline->toArray());

        expect($timeline->getSlotTime(1)->format(DATE_ATOM))->toBe('2026-01-15T18:00:00+00:00')
            ->and($exception?->getReason())->toBe(InvalidConfigurationReason::NotSerializable)
            ->and($exception?->getContext())->toBe(['timezone' => 'BST', 'fixed_offset' => '+01:00']);
    });

    it('is refused for a time parsed from a string that ends in one', function (): void {
        $timeline = new TimelineDefinition(new DateTimeImmutable('2026-08-01 19:00 EST'), new DateInterval('P7D'));

        expect(timezoneRefusal(fn() => $timeline->toArray())?->getReason())->toBe(InvalidConfigurationReason::NotSerializable);
    });

    it('is refused by a session grid', function (): void {
        $grid = new SessionGrid(
            [new DateTimeImmutable('2026-08-12 20:00', new DateTimeZone('CEST'))],
            new DateInterval('PT30M'),
            2
        );

        $exception = timezoneRefusal(fn() => $grid->toArray());

        expect($exception?->getReason())->toBe(InvalidConfigurationReason::NotSerializable)
            ->and($exception?->getContext())->toBe(['timezone' => 'CEST', 'fixed_offset' => '+02:00']);
    });

    it('is not refused for a region or an offset, which read back to the same slot times', function (string $timezone): void {
        $timeline = new TimelineDefinition(
            new DateTimeImmutable('2026-03-01 19:00', new DateTimeZone($timezone)),
            new DateInterval('P7D'),
            2,
            new DateInterval('PT1H')
        );

        $readBack = TimelineDefinition::fromArray($timeline->toArray());

        expect($timeline->toArray()['timezone'])->toBe($timezone);
        foreach ([1, 5, 9] as $round) {
            expect($readBack->getSlotTime($round, 1))->toEqual($timeline->getSlotTime($round, 1));
        }
    })->with(['Europe/London', 'UTC', '+01:00', 'Z']);

    it('is not refused by a session grid for a name of UTC, which reads back to the same slot times', function (string $timezone): void {
        $grid = new SessionGrid(
            [new DateTimeImmutable('2026-08-12 20:00', new DateTimeZone($timezone)), new DateTimeImmutable('2026-08-19 20:00', new DateTimeZone($timezone))],
            new DateInterval('PT30M'),
            2
        );

        $readBack = SessionGrid::fromArray($grid->toArray());

        expect($grid->toArray())->toHaveKey('timezone', $timezone)
            ->and($readBack->getSlotTime(1, 1))->toEqual($grid->getSlotTime(1, 1));
    })->with(['Z', 'UCT']);

    it('is not refused by a blackout rule, which writes its bounds in UTC', function (): void {
        $rule = new BlackoutRule([[
            'from' => new DateTimeImmutable('2026-11-09 00:00', new DateTimeZone('BST')),
            'to' => new DateTimeImmutable('2026-11-17 00:00', new DateTimeZone('BST')),
        ]]);

        expect($rule->toArray()['windows'][0])->toMatchArray([
            'from' => '2026-11-08 23:00:00',
            'timezone' => 'UTC',
        ]);
    });
});
