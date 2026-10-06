<?php

declare(strict_types=1);

use MissionGaming\Tactician\Timeline\DateTimeString;

describe('DateTimeString::statesAnInstant()', function (): void {
    // Each string is read by PHP, without the clock, to the instant beside
    // it. The instant is written out here, not taken from the code.
    it('accepts a string whose date is stated, and PHP reads it to the instant written', function (string $value, string $instant): void {
        expect(DateTimeString::statesAnInstant($value))->toBeTrue()
            ->and((new DateTimeImmutable($value, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP'))->toBe($instant);
    })->with([
        'a space, with seconds' => ['2026-08-01 19:00:00', '2026-08-01T19:00:00.000000+00:00'],
        'a space, without seconds' => ['2026-08-01 19:00', '2026-08-01T19:00:00.000000+00:00'],
        'a T, with seconds' => ['2026-08-01T19:00:00', '2026-08-01T19:00:00.000000+00:00'],
        'a T, without seconds' => ['2026-08-01T19:00', '2026-08-01T19:00:00.000000+00:00'],
        'fractional seconds' => ['2026-08-01T19:00:00.123456', '2026-08-01T19:00:00.123456+00:00'],
        'Z' => ['2026-08-01T19:00:00Z', '2026-08-01T19:00:00.000000+00:00'],
        'an offset' => ['2026-08-01T19:00:00+05:00', '2026-08-01T19:00:00.000000+05:00'],
        'an offset without a colon' => ['2026-08-01T19:00:00+0500', '2026-08-01T19:00:00.000000+05:00'],
        'a zone name' => ['2026-08-01 19:00:00 Europe/London', '2026-08-01T19:00:00.000000+01:00'],
        'a zone abbreviation' => ['2026-08-01 19:00:00 BST', '2026-08-01T19:00:00.000000+01:00'],
        'midnight written out' => ['2026-08-01 00:00', '2026-08-01T00:00:00.000000+00:00'],
        'the last second of a day' => ['2026-08-01 23:59:59', '2026-08-01T23:59:59.000000+00:00'],
        '29 February of a leap year' => ['2024-02-29 10:00', '2024-02-29T10:00:00.000000+00:00'],
        '29 February 2000' => ['2000-02-29 10:00', '2000-02-29T10:00:00.000000+00:00'],
        'year zero, which PHP counts as a leap year' => ['0000-02-29 10:00', '0000-02-29T10:00:00.000000+00:00'],
        'a year before year zero' => ['-2026-08-01 19:00', '-2026-08-01T19:00:00.000000+00:00'],
        'compact ISO 8601' => ['20260801T190000', '2026-08-01T19:00:00.000000+00:00'],
        'compact ISO 8601 without seconds' => ['20260801T1900', '2026-08-01T19:00:00.000000+00:00'],
        'an hour and no minutes after a T' => ['2026-08-01T19', '2026-08-01T19:00:00.000000+00:00'],
        'a twelve-hour time' => ['2026-08-01 7pm', '2026-08-01T19:00:00.000000+00:00'],
        'a month name' => ['1 August 2026 19:00', '2026-08-01T19:00:00.000000+00:00'],
        'slashes' => ['2026/08/01 19:00', '2026-08-01T19:00:00.000000+00:00'],
        'the time first' => ['19:00 2026-08-01', '2026-08-01T19:00:00.000000+00:00'],
        'surrounding white space' => [" 2026-08-01\t19:00 ", '2026-08-01T19:00:00.000000+00:00'],
        'a two-digit year, which PHP reads by a fixed rule' => ['01-Aug-26 19:00', '2026-08-01T19:00:00.000000+00:00'],
        // A date without a time of day is midnight: PHP sets the time of a
        // string that has a date to zero, and does not take it from the clock
        'a date without a time of day' => ['2026-11-09', '2026-11-09T00:00:00.000000+00:00'],
        'a date with slashes and no time' => ['2026/11/09', '2026-11-09T00:00:00.000000+00:00'],
        'a compact date and no time' => ['20261109', '2026-11-09T00:00:00.000000+00:00'],
        'a month name date and no time' => ['9 November 2026', '2026-11-09T00:00:00.000000+00:00'],
        'a date and a zone and no time' => ['2026-11-09 Europe/London', '2026-11-09T00:00:00.000000+00:00'],
        'a week date and no time' => ['2026-W31-6', '2026-08-01T00:00:00.000000+00:00'],
        'an ordinal date and no time' => ['2026-213', '2026-08-01T00:00:00.000000+00:00'],
        // A month without a day is its first day, by the parser and not by
        // the clock
        'a month and a year' => ['August 2026', '2026-08-01T00:00:00.000000+00:00'],
        'a month and a year with a time' => ['August 2026 19:00', '2026-08-01T19:00:00.000000+00:00'],
        'a year and a month in digits' => ['2026-08 19:00', '2026-08-01T19:00:00.000000+00:00'],
        // Words that set the time of day of a date the string states
        'noon on a date' => ['2026-08-01 noon', '2026-08-01T12:00:00.000000+00:00'],
        'midnight on a date' => ['2026-08-01 midnight', '2026-08-01T00:00:00.000000+00:00'],
        'today next to a date, which is its midnight' => ['2026-08-01 today', '2026-08-01T00:00:00.000000+00:00'],
        // A weekday name next to the date that falls on it
        'a weekday that is the weekday of the date' => ['Saturday 2026-08-01 19:00', '2026-08-01T19:00:00.000000+00:00'],
        'RFC 2822' => ['Sat, 01 Aug 2026 19:00:00 +0000', '2026-08-01T19:00:00.000000+00:00'],
        'RFC 2822 on a Sunday' => ['Sun, 02 Aug 2026 19:00:00 +0000', '2026-08-02T19:00:00.000000+00:00'],
        'RFC 850' => ['Saturday, 01-Aug-26 19:00:00 UTC', '2026-08-01T19:00:00.000000+00:00'],
        'a weekday name and its date' => ['Sat, 01 Aug 2026', '2026-08-01T00:00:00.000000+00:00'],
        'a weekday name between its date and the time' => ['2026-08-01 Sat 19:00', '2026-08-01T19:00:00.000000+00:00'],
        'a weekday that is the weekday of an ordinal date' => ['Sat 2026-213T19:00', '2026-08-01T19:00:00.000000+00:00'],
        // Notations PHP's parser reports as a base date and an offset
        'a Unix timestamp' => ['@1785610800', '2026-08-01T19:00:00.000000+00:00'],
        'a Unix timestamp of zero' => ['@0', '1970-01-01T00:00:00.000000+00:00'],
        'a negative Unix timestamp' => ['@-5', '1969-12-31T23:59:55.000000+00:00'],
        'a Unix timestamp with a fraction' => ['@1785610800.5', '2026-08-01T19:00:00.500000+00:00'],
        'an ISO 8601 week date' => ['2026-W31-6T19:00', '2026-08-01T19:00:00.000000+00:00'],
        'a compact ISO 8601 week date' => ['2026W316T19:00', '2026-08-01T19:00:00.000000+00:00'],
        'a week date without a day, which is the Monday' => ['2026-W31T19:00', '2026-07-27T19:00:00.000000+00:00'],
        'the first week, which starts in December' => ['2026-W01-1T19:00', '2025-12-29T19:00:00.000000+00:00'],
        'week 53' => ['2026-W53-7T19:00', '2027-01-03T19:00:00.000000+00:00'],
        'an ISO 8601 ordinal date' => ['2026-213T19:00', '2026-08-01T19:00:00.000000+00:00'],
        'a compact ordinal date' => ['2026213T190000', '2026-08-01T19:00:00.000000+00:00'],
        'an ordinal date in January' => ['2026-015T19:00', '2026-01-15T19:00:00.000000+00:00'],
        'the last ordinal day of a year' => ['2026-365T19:00', '2026-12-31T19:00:00.000000+00:00'],
        'the last ordinal day of a leap year' => ['2024-366T19:00', '2024-12-31T19:00:00.000000+00:00'],
        // An offset from a stated date. Unusual in configuration, and fixed
        // by the string all the same: it is counted from the date written.
        'an offset in weeks' => ['2026-08-01 20:00 +1 week', '2026-08-08T20:00:00.000000+00:00'],
        'an offset in seconds' => ['2026-08-01 20:00 +1 sec', '2026-08-01T20:00:01.000000+00:00'],
        'an offset counted back' => ['2026-08-01 20:00 1 week ago', '2026-07-25T20:00:00.000000+00:00'],
        'an offset from a date without a time' => ['2026-08-01 +2 hours', '2026-08-01T02:00:00.000000+00:00'],
        'tomorrow after a date, which is the midnight after it' => ['2026-08-01 20:00 tomorrow', '2026-08-02T00:00:00.000000+00:00'],
        'next year after a date' => ['2026-08-01 20:00 next year', '2027-08-01T20:00:00.000000+00:00'],
        'weekdays after a date' => ['2026-08-01 20:00 +3 weekdays', '2026-08-05T20:00:00.000000+00:00'],
        'first day of, before a date' => ['first day of 2026-08-15 20:00', '2026-08-01T20:00:00.000000+00:00'],
        'the last day of a month' => ['last day of february 2026 10:00', '2026-02-28T10:00:00.000000+00:00'],
        'an offset after a Unix timestamp' => ['@1785610800 +1 day', '2026-08-02T19:00:00.000000+00:00'],
        'an offset after a week date' => ['2026-W31-6T19:00 +1 day', '2026-08-02T19:00:00.000000+00:00'],
        // A weekday that says where to move to, not what the date is
        'the next Monday after a date' => ['2026-08-01 next monday', '2026-08-03T00:00:00.000000+00:00'],
        'the Monday before a date' => ['2026-08-01 last monday', '2026-07-27T00:00:00.000000+00:00'],
        'the first Monday of a month' => ['first monday of August 2026 19:00', '2026-08-03T19:00:00.000000+00:00'],
        'the last Friday of a month' => ['last friday of August 2026 15:00', '2026-08-28T15:00:00.000000+00:00'],
        'a signed count of weekdays' => ['2026-08-01 19:00 +2 saturday', '2026-08-08T19:00:00.000000+00:00'],
        'a count of weekdays without a sign' => ['2026-08-01 19:00 12 monday', '2026-10-19T19:00:00.000000+00:00'],
        'a count of weekdays back' => ['2026-08-01 19:00 3 mondays ago', '2026-07-06T19:00:00.000000+00:00'],
        'the right weekday after a date that ends in its year' => ['1 Aug 2026 Sat', '2026-08-01T00:00:00.000000+00:00'],
        // PHP reads a signed number in front of a weekday as a count of that
        // weekday, an offset without a colon included: 100 Mondays on. It
        // did before the rule, and the string fixes it.
        'an offset without a colon in front of a weekday' => ['2026-08-01 19:00:00 +0100 Mon', '2028-06-26T19:00:00.000000+00:00'],
        'a weekday of the week after' => ['2026-08-01 monday next week', '2026-08-03T00:00:00.000000+00:00'],
        'next month, which holds no weekday' => ['Sat, 01 Aug 2026 19:00:00 next month', '2026-09-01T19:00:00.000000+00:00'],
        // A timezone written twice
        'one zone twice' => ['2026-08-01 19:00 UTC UTC', '2026-08-01T19:00:00.000000+00:00'],
        'one offset twice' => ['2026-08-01 19:00 +01:00 +01:00', '2026-08-01T19:00:00.000000+01:00'],
        'an offset and its zone in parentheses, as in an email date' => ['Sat, 01 Aug 2026 19:00:00 +0000 (UTC)', '2026-08-01T19:00:00.000000+00:00'],
        'Z and its zone' => ['2026-08-01T19:00:00Z UTC', '2026-08-01T19:00:00.000000+00:00'],
        'a zone and the offset it has on that day' => ['2026-08-01 19:00 Europe/London +01:00', '2026-08-01T19:00:00.000000+01:00'],
        'a zone and its abbreviation on that day' => ['2026-08-01 19:00 Europe/London BST', '2026-08-01T19:00:00.000000+01:00'],
        'an ordinal date with one zone twice' => ['2026-213T19:00 UTC UTC', '2026-08-01T19:00:00.000000+00:00'],
        'a Unix timestamp, which is UTC, and UTC' => ['@1785610800 UTC', '2026-08-01T19:00:00.000000+00:00'],
    ]);

    it('rejects a string that does not', function (string $value): void {
        expect(DateTimeString::statesAnInstant($value))->toBeFalse();
    })->with([
        // No date, or a date without its year: PHP would take it from the clock
        'the empty string' => [''],
        'white space' => [' '],
        'now' => ['now'],
        'today' => ['today'],
        'tomorrow' => ['tomorrow'],
        'noon' => ['noon'],
        'a relative offset' => ['+1 week'],
        'a relative weekday with a time' => ['next monday 20:00'],
        'a weekday alone' => ['saturday'],
        'the first day of next month' => ['first day of next month'],
        'the first Monday of a month without a year' => ['first monday of August 19:00'],
        'a time of day without a date' => ['20:00'],
        'a time of day with seconds and no date' => ['20:00:00'],
        'a time of day and a zone' => ['20:00 UTC'],
        'a zone alone' => ['UTC'],
        'a date without its year' => ['August 1 20:00'],
        'a day and a month' => ['1 August'],
        'four digits, which PHP reads as a time of day' => ['2026'],
        'a Unix timestamp without its @, which PHP cannot parse' => ['1785610800'],
        // A weekday named as a fact that is not the weekday of the date: PHP
        // moves the date
        'RFC 2822 with the wrong weekday' => ['Mon, 01 Aug 2026 19:00:00 +0000'],
        'the wrong weekday before an ISO date' => ['Sunday 2026-08-01 19:00'],
        'the wrong weekday after a date' => ['2026-08-01 19:00 monday'],
        'the wrong weekday between a date and the time' => ['2026-08-01 Mon 19:00'],
        'the wrong weekday after a date that ends in its year, which is no count' => ['1 Aug 2026 Mon'],
        'the wrong weekday after a date that ends in its day, which is no count' => ['2026-08-01 Mon'],
        'the wrong weekday after a date with hyphens and a month name' => ['01-Aug-2026 Sun 19:00'],
        'the wrong weekday after an offset with a colon, which is no count' => ['2026-08-01 19:00 +01:00 Mon'],
        'the wrong weekday with an offset after it' => ['Mon, 01 Aug 2026 19:00:00 +1 week'],
        'a right weekday and a wrong one' => ['Sat, 01 Aug 2026 19:00:00 mon'],
        'a weekday before a week date, which PHP counts from 1 January' => ['Sat 2026-W31-6T19:00'],
        'a weekday before a Unix timestamp, which PHP counts from 1970' => ['Sat @1785610800'],
        'the wrong weekday before an ordinal date' => ['Thu 2026-213T19:00'],
        // A date or a time that does not exist, which PHP rolls over
        '30 February' => ['2026-02-30 10:00'],
        '30 February without a time' => ['2026-02-30'],
        '29 February of a year that is not a leap year' => ['2026-02-29 10:00'],
        '29 February 1900' => ['1900-02-29 10:00'],
        '31 April' => ['2026-04-31 10:00'],
        'month zero' => ['2026-00-10 10:00'],
        'day zero' => ['2026-08-00 10:00'],
        'the zero date' => ['0000-00-00 00:00:00'],
        'hour 24' => ['2026-08-01 24:00'],
        'second 60' => ['2026-08-01 23:59:60'],
        'ordinal day 366 of a year that is not a leap year' => ['2026-366T19:00'],
        'ordinal day 366 without a time' => ['2026-366'],
        '30 February with an offset' => ['2026-02-30 10:00 +1 week'],
        '30 February with one zone twice' => ['2026-02-30 10:00 UTC UTC'],
        // A second timezone that is not the first: PHP reads the first one
        'two offsets' => ['2026-08-01 19:00 +01:00 +05:00'],
        'a zone and another offset' => ['2026-08-01 19:00 UTC +01:00'],
        'a zone and an abbreviation it does not have on that day' => ['2026-08-01 19:00 Europe/London GMT'],
        'an abbreviation and its other half of the year' => ['2026-08-01 19:00 EST EDT'],
        'three zones, the last one different' => ['2026-08-01 19:00 UTC UTC +01:00'],
        'an offset and its zone with no space between them, which cannot be told apart' => ['2026-08-01T19:00:00+0000(UTC)'],
        // The second zone is checked by reading the local time back with it,
        // and PHP does not read a year of seven digits back as it wrote it
        'two zones in a year PHP cannot read back' => ['@99999999999999 UTC'],
        // What PHP cannot parse at all
        'words' => ['half past never'],
        'month 13' => ['2026-13-01 10:00'],
        'minute 60' => ['2026-08-01 19:60'],
        'a comma before the fraction' => ['2026-08-01 19:00:00,5'],
        'two times of day' => ['2026-08-01 20:00 21:00'],
    ]);

    // Why the second kind is rejected: PHP accepts each of these without a
    // word and reads it to the instant beside it, which is not the one
    // written. The documentation states these readings.
    it('rejects what PHP reads to another instant than the one written', function (string $value, string $phpReads): void {
        expect(DateTimeString::statesAnInstant($value))->toBeFalse()
            ->and((new DateTimeImmutable($value, new DateTimeZone('UTC')))->format(DATE_ATOM))->toBe($phpReads);
    })->with([
        '30 February, read as 2 March' => ['2026-02-30 20:00', '2026-03-02T20:00:00+00:00'],
        'hour 24, read as the next day' => ['2026-08-01 24:00', '2026-08-02T00:00:00+00:00'],
        'second 60, read as the next minute' => ['2026-08-01 23:59:60', '2026-08-02T00:00:00+00:00'],
        'day 366 of 2026, read as the first day of 2027' => ['2026-366', '2027-01-01T00:00:00+00:00'],
        'a Saturday called Monday, read as the Monday after' => ['Mon, 01 Aug 2026 19:00:00 +0000', '2026-08-03T19:00:00+00:00'],
        'two offsets, read with the first' => ['2026-08-01 19:00 +01:00 +05:00', '2026-08-01T19:00:00+01:00'],
    ]);

    // Every ISO week of a spread of years, in both notations and for every
    // day: the days PHP adds to 1 January are the notation's own, so the
    // date read is the one `setISODate()` gives for the numbers written.
    it('accepts every ISO 8601 week date, which PHP reads to the day written', function (): void {
        $utc = new DateTimeZone('UTC');
        $wrong = [];
        foreach ([1999, 2000, 2004, 2015, 2020, 2021, 2024, 2026, 2027, 2032] as $year) {
            for ($week = 1; $week <= 53; ++$week) {
                for ($day = 1; $day <= 7; ++$day) {
                    $expected = (new DateTimeImmutable('@0'))->setTimezone($utc)->setISODate($year, $week, $day)->setTime(19, 0)->format('Y-m-d H:i:s');
                    foreach (['%04d-W%02d-%dT19:00', '%04dW%02d%dT19:00'] as $pattern) {
                        $value = sprintf($pattern, $year, $week, $day);
                        if (!DateTimeString::statesAnInstant($value)) {
                            $wrong[] = "{$value} was rejected";
                        } elseif ((new DateTimeImmutable($value, $utc))->format('Y-m-d H:i:s') !== $expected) {
                            $wrong[] = "{$value} was not read as {$expected}";
                        }
                    }
                }
            }
        }

        expect($wrong)->toBe([]);
    });

    // For every day of a leap year and of another: the right weekday name
    // is accepted and each of the six wrong ones is rejected, in the form of
    // RFC 2822 and after an ISO date.
    it('accepts a weekday name on the date that falls on it and on no other', function (): void {
        $names = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        $wrong = [];
        foreach ([2024, 2026] as $year) {
            $date = (new DateTimeImmutable('@0'))->setDate($year, 1, 1);
            while ((int) $date->format('Y') === $year) {
                foreach ($names as $number => $name) {
                    $right = $number === (int) $date->format('w');
                    foreach (["{$name}, {$date->format('d M Y')} 19:00:00 +0000", "{$date->format('Y-m-d')} 19:00 {$name}"] as $value) {
                        if (DateTimeString::statesAnInstant($value) !== $right) {
                            $wrong[] = $value . ($right ? ' was rejected' : ' was accepted');
                        }
                    }
                }
                $date = $date->modify('+1 day');
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

describe('DateTimeString::timezoneName()', function (): void {
    // The name is the one PHP gives the timezone of a datetime built from
    // the string, which is what the declared timezone is compared with.
    it('is the name PHP gives the timezone the string carries', function (string $value, ?string $name): void {
        expect(DateTimeString::timezoneName($value))->toBe($name);

        if ($name !== null) {
            expect((new DateTimeImmutable($value, new DateTimeZone('Asia/Tokyo')))->getTimezone()->getName())->toBe($name);
        }
    })->with([
        'no zone' => ['2026-02-30 10:00', null],
        'an offset' => ['2026-02-30 10:00 +01:00', '+01:00'],
        'an offset without a colon' => ['2026-02-30 10:00 -0530', '-05:30'],
        'an offset of zero written as negative' => ['2026-02-30 10:00 -0000', '+00:00'],
        'an offset with seconds' => ['2026-02-30 10:00 +01:00:30', '+01:00:30'],
        'an offset after GMT' => ['2026-02-30 10:00 GMT+2', '+02:00'],
        'Z' => ['2026-02-30T10:00:00Z', 'Z'],
        'an abbreviation' => ['2026-02-30 10:00 BST', 'BST'],
        'an abbreviation in lower case' => ['2026-02-30 10:00 est', 'EST'],
        'an identifier' => ['2026-02-30 10:00 Europe/London', 'Europe/London'],
        'UTC' => ['2026-02-30 10:00 UTC', 'UTC'],
        'the first of two' => ['2026-02-30 10:00 +01:00 +05:00', '+01:00'],
    ]);

    it('is null for a string with no timezone, without reading the clock', function (string $value): void {
        expect(DateTimeString::timezoneName($value))->toBeNull();
    })->with([
        'the empty string' => [''],
        'tomorrow' => ['tomorrow'],
        'a time of day' => ['20:00'],
        'words' => ['half past never'],
    ]);
});
