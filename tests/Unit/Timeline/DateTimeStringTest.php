<?php

declare(strict_types=1);

use MissionGaming\Tactician\Timeline\DateTimeString;

describe('DateTimeString::isAbsolute()', function (): void {
    it('accepts a string that states a complete date and time', function (string $value): void {
        expect(DateTimeString::isAbsolute($value))->toBeTrue();
    })->with([
        'a space, with seconds' => ['2026-08-01 19:00:00'],
        'a space, without seconds' => ['2026-08-01 19:00'],
        'a T, with seconds' => ['2026-08-01T19:00:00'],
        'a T, without seconds' => ['2026-08-01T19:00'],
        'fractional seconds' => ['2026-08-01T19:00:00.123456'],
        'Z' => ['2026-08-01T19:00:00Z'],
        'an offset' => ['2026-08-01T19:00:00+05:00'],
        'an offset without a colon' => ['2026-08-01T19:00:00+0500'],
        'a zone name' => ['2026-08-01 19:00:00 Europe/London'],
        'a zone abbreviation' => ['2026-08-01 19:00:00 BST'],
        'midnight written out' => ['2026-08-01 00:00'],
        'the last second of a day' => ['2026-08-01 23:59:59'],
        '29 February of a leap year' => ['2024-02-29 10:00'],
        '29 February 2000' => ['2000-02-29 10:00'],
        'year zero, which PHP counts as a leap year' => ['0000-02-29 10:00'],
        'a year before year zero' => ['-2026-08-01 19:00'],
        'compact ISO 8601' => ['20260801T190000'],
        'compact ISO 8601 without seconds' => ['20260801T1900'],
        'an hour and no minutes after a T' => ['2026-08-01T19'],
        'a twelve-hour time' => ['2026-08-01 7pm'],
        'a month name' => ['1 August 2026 19:00'],
        'slashes' => ['2026/08/01 19:00'],
        'the time first' => ['19:00 2026-08-01'],
        'surrounding white space' => [" 2026-08-01\t19:00 "],
        // Words that set the time of day of a date the string states. PHP
        // reads them from the string, not from the clock.
        'noon on a date' => ['2026-08-01 noon'],
        'midnight on a date' => ['2026-08-01 midnight'],
        // A weekday name next to the date that falls on it
        'a weekday that is the weekday of the date' => ['Saturday 2026-08-01 19:00'],
        'RFC 2822' => ['Sat, 01 Aug 2026 19:00:00 +0000'],
        'RFC 2822 on a Sunday' => ['Sun, 02 Aug 2026 19:00:00 +0000'],
        'RFC 850' => ['Saturday, 01-Aug-26 19:00:00 UTC'],
        // PHP reads a weekday name as "midnight of that day", so this one
        // states a time of day in PHP's reading and takes nothing from the
        // clock.
        'a weekday name and its date, which PHP reads as midnight' => ['Sat, 01 Aug 2026'],
        // Notations PHP's parser reports as a base date and an offset
        'a Unix timestamp' => ['@1785610800'],
        'a Unix timestamp of zero' => ['@0'],
        'a negative Unix timestamp' => ['@-5'],
        'a Unix timestamp with a fraction' => ['@1785610800.5'],
        'an ISO 8601 week date' => ['2026-W31-6T19:00'],
        'a compact ISO 8601 week date' => ['2026W316T19:00'],
        'a week date without a day, which is the Monday' => ['2026-W31T19:00'],
        'the first week, which starts in December' => ['2026-W01-1T19:00'],
        'week 53' => ['2026-W53-7T19:00'],
        'an ISO 8601 ordinal date' => ['2026-213T19:00'],
        'a compact ordinal date' => ['2026213T190000'],
        'an ordinal date in January' => ['2026-015T19:00'],
        'the last ordinal day of a year' => ['2026-365T19:00'],
        'the last ordinal day of a leap year' => ['2024-366T19:00'],
    ]);

    it('rejects a string that does not', function (string $value): void {
        expect(DateTimeString::isAbsolute($value))->toBeFalse();
    })->with([
        // Relative to the clock
        'the empty string' => [''],
        'white space' => [' '],
        'now' => ['now'],
        'today' => ['today'],
        'tomorrow' => ['tomorrow'],
        'a relative offset' => ['+1 week'],
        'a relative weekday with a time' => ['next monday 20:00'],
        'a weekday alone' => ['saturday'],
        'the first day of next month' => ['first day of next month'],
        // A part left out
        'a date without a time of day' => ['2026-08-01'],
        'a month name date without a time of day' => ['1 August 2026'],
        'a week date without a time of day' => ['2026-W31-6'],
        'an ordinal date without a time of day' => ['2026-213'],
        'a time of day without a date' => ['20:00'],
        'a time of day with seconds and no date' => ['20:00:00'],
        'a date without its year' => ['August 1 20:00'],
        'four digits, which PHP reads as a time of day' => ['2026'],
        // A relative part on top of a complete date and time
        'an offset in weeks' => ['2026-08-01 20:00 +1 week'],
        'an offset in seconds' => ['2026-08-01 20:00 +1 sec'],
        'tomorrow after a date' => ['2026-08-01 20:00 tomorrow'],
        'next year after a date' => ['2026-08-01 20:00 next year'],
        'weekdays after a date' => ['2026-08-01 20:00 3 weekdays'],
        'first day of, before a date' => ['first day of 2026-08-01 20:00'],
        'last day of a month' => ['last day of february 2026 10:00'],
        'an offset after a Unix timestamp' => ['@1785610800 +1 day'],
        'an offset after a week date' => ['2026-W31-6T19:00 +1 day'],
        'a weekday name after a week date' => ['2026-W31-6T19:00 sat'],
        // A weekday that is not the weekday of the date: PHP moves the date
        'RFC 2822 with the wrong weekday' => ['Mon, 01 Aug 2026 19:00:00 +0000'],
        'the wrong weekday before an ISO date' => ['Sunday 2026-08-01 19:00'],
        'this week, which moves to its Monday' => ['2026-08-01 19:00 this week'],
        // A date or a time that does not exist, which PHP rolls over
        '30 February' => ['2026-02-30 10:00'],
        '29 February of a year that is not a leap year' => ['2026-02-29 10:00'],
        '29 February 1900' => ['1900-02-29 10:00'],
        '31 April' => ['2026-04-31 10:00'],
        'month zero' => ['2026-00-10 10:00'],
        'day zero' => ['2026-08-00 10:00'],
        'the zero date' => ['0000-00-00 00:00:00'],
        'hour 24' => ['2026-08-01 24:00'],
        'second 60' => ['2026-08-01 23:59:60'],
        'ordinal day 366 of a year that is not a leap year' => ['2026-366T19:00'],
        // A second timezone, which PHP accepts with a warning
        'two zones' => ['2026-08-01 19:00 UTC UTC'],
        // What PHP cannot parse at all
        'words' => ['half past never'],
        'month 13' => ['2026-13-01 10:00'],
        'minute 60' => ['2026-08-01 19:60'],
        'a comma before the fraction' => ['2026-08-01 19:00:00,5'],
    ]);

    // The rule adds nothing of its own to the instant: whatever it accepts
    // is read by PHP, and PHP reads each of these to the instant stated.
    it('accepts only what PHP reads to the instant written', function (string $value, string $instant): void {
        expect(DateTimeString::isAbsolute($value))->toBeTrue()
            ->and((new DateTimeImmutable($value, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP'))->toBe($instant);
    })->with([
        ['2026-08-01 19:00', '2026-08-01T19:00:00.000000+00:00'],
        ['2026-08-01T19', '2026-08-01T19:00:00.000000+00:00'],
        ['2026-08-01 noon', '2026-08-01T12:00:00.000000+00:00'],
        ['Sat, 01 Aug 2026', '2026-08-01T00:00:00.000000+00:00'],
        ['Sat, 01 Aug 2026 19:00:00 +0000', '2026-08-01T19:00:00.000000+00:00'],
        ['2026-W31-6T19:00', '2026-08-01T19:00:00.000000+00:00'],
        ['2026-W31T19:00', '2026-07-27T19:00:00.000000+00:00'],
        ['2026-W53-7T19:00', '2027-01-03T19:00:00.000000+00:00'],
        ['2026-213T19:00', '2026-08-01T19:00:00.000000+00:00'],
        ['2026-015T19:00', '2026-01-15T19:00:00.000000+00:00'],
        ['@1785610800', '2026-08-01T19:00:00.000000+00:00'],
        ['-2026-08-01 19:00', '-2026-08-01T19:00:00.000000+00:00'],
    ]);

    // Every ISO week of a spread of years, in both notations and for every
    // day: the days PHP adds to 1 January are the notation's own, and are
    // told apart from days a relative part adds.
    it('accepts every ISO 8601 week date and rejects it with a day added', function (): void {
        $wrong = [];
        foreach ([1999, 2000, 2004, 2015, 2020, 2021, 2024, 2026, 2027, 2032] as $year) {
            for ($week = 1; $week <= 53; ++$week) {
                for ($day = 1; $day <= 7; ++$day) {
                    foreach (['%04d-W%02d-%dT19:00', '%04dW%02d%dT19:00'] as $pattern) {
                        $value = sprintf($pattern, $year, $week, $day);
                        if (!DateTimeString::isAbsolute($value)) {
                            $wrong[] = "{$value} was rejected";
                        }
                        if (DateTimeString::isAbsolute("{$value} +1 day")) {
                            $wrong[] = "{$value} +1 day was accepted";
                        }
                    }
                }
            }
        }

        expect($wrong)->toBe([]);
    });
});

describe('DateTimeString::isMalformed()', function (): void {
    it('is true for what the DateTimeImmutable constructor throws on', function (string $value): void {
        expect(DateTimeString::isMalformed($value))->toBeTrue()
            ->and(fn() => new DateTimeImmutable($value))->toThrow(Exception::class);
    })->with([
        'words' => ['half past never'],
        'month 13' => ['2026-13-01 10:00'],
        'a comma before the fraction' => ['2026-08-01 19:00:00,5'],
    ]);

    // PHP reads these without an error, some of them from the clock. None is
    // built here: the point is that they are not handed to PHP for an error.
    it('is false for what PHP accepts', function (string $value): void {
        expect(DateTimeString::isMalformed($value))->toBeFalse();
    })->with([
        'the empty string, which PHP reads as now' => [''],
        'now' => ['now'],
        'a relative offset' => ['+1 week'],
        'a date without a time' => ['2026-08-01'],
        'a complete date and time' => ['2026-08-01 19:00'],
        'a date PHP rolls over' => ['2026-02-30 10:00'],
    ]);
});
