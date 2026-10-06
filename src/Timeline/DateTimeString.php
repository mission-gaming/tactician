<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Timeline;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Decides whether a datetime string states an instant by itself.
 *
 * PHP's date parser accepts far more than an instant: `now`, `tomorrow`,
 * `+1 week` and the empty string are resolved against the current time, and
 * a string that leaves the date or a part of it out (`20:00`, `August 1`)
 * has that part filled in from the clock. A schedule built from such a
 * string differs from one run to the next, which the library does not allow:
 * it never asks for the current time. The parser also reads a date or a time
 * that does not exist (`2026-02-30`, `24:00`) as the one after it, without a
 * word. The places that read a datetime from plain data therefore ask this
 * class first, and hand the string to PHP only when the answer cannot depend
 * on when the question is asked and is the instant the string writes.
 *
 * The check reads the string with `date_parse()`, which reports what the
 * string says and never consults the clock.
 *
 * @internal Not public API; it carries no compatibility guarantee
 */
final class DateTimeString
{
    /**
     * A weekday name as PHP's parser knows it, which reads `saturdays` as
     * `saturday`. The `mon` of `month` is not one.
     */
    private const string WEEKDAY_NAME = '/(?<![a-z])(?:sun(?:day)?|mon(?:day)?|tue(?:sday)?|wed(?:nesday)?|thu(?:rsday)?|fri(?:day)?|sat(?:urday)?)s?(?![a-z])/i';

    /**
     * What makes the weekday name before it a weekday of another week
     * (`monday next week`), and not a statement about the date.
     */
    private const string A_WEEK_AFTER_A_WEEKDAY = '/^[ \t]+(?:next|last|previous|this)[ \t]+week/i';

    private const array WEEKDAY_NUMBERS = ['sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6];

    /**
     * True when the string gives the same instant whenever it is read, and
     * that instant is the one written in it.
     *
     * The year, the month and the day must be stated, which is what keeps
     * the clock out: PHP fills in a missing date part, and the time of a
     * string that has no date, from the current time. The time of day may be
     * left out, and is then midnight: PHP reads `2026-11-09` as
     * `2026-11-09 00:00:00`, from the string and not from the clock. So are
     * the seconds of a time without them, and the day of a month written
     * without one (`August 2026` is 1 August).
     *
     * Rejected:
     *
     * - the empty string, `now`, `tomorrow`, `+1 week`, `next monday 20:00`,
     *   `20:00`, `August 1 20:00`: no date, or a date without its year, so
     *   the answer would come from the clock;
     * - `2026-02-30 20:00`, `2026-08-01 24:00`, `2026-08-01 23:59:60`, the
     *   366th day of a year that has 365: a date or a time that does not
     *   exist, which PHP rolls over into the next one;
     * - a weekday name that is not the weekday of the date (`Mon, 01 Aug
     *   2026 19:00:00 +0000` is a Saturday, and PHP moves it to the Monday
     *   after);
     * - a second timezone that is not the first one (`2026-08-01 19:00
     *   +01:00 +05:00`: PHP reads the first and ignores the second);
     * - anything PHP cannot parse at all.
     *
     * Accepted, because the instant is fixed by the string and is the one
     * PHP documents for it, however unusual the notation: an offset from a
     * stated date (`2026-08-01 20:00 +1 week`, `first monday of August 2026
     * 19:00`, `2026-08-01 noon`), a Unix timestamp (`@1785610800`), an ISO
     * 8601 week date (`2026-W31-6T19:00`) or ordinal date
     * (`2026-213T19:00`), a weekday name next to the date that falls on it
     * (RFC 2822), and a timezone written twice (`+0000 (UTC)`).
     */
    public static function statesAnInstant(string $value): bool
    {
        $parsed = date_parse($value);
        if ($parsed['error_count'] > 0) {
            return false;
        }

        $year = $parsed['year'];
        $month = $parsed['month'];
        $day = $parsed['day'];
        $hour = $parsed['hour'];
        $minute = $parsed['minute'];
        $second = $parsed['second'];

        // A part the string does not state is `false`, and PHP takes a
        // missing part of the date from the clock. A day the parser fills in
        // itself (the first, for a month without a day) is reported as 1.
        if (!is_int($year) || !is_int($month) || !is_int($day)) {
            return false;
        }

        // With a date and no time of day, PHP sets the time to midnight. A
        // time is otherwise reported whole (the parser zeroes what a time
        // leaves out), and must exist: PHP rolls hour 24 and second 60 over.
        $midnightByDefault = $hour === false && $minute === false && $second === false;
        $timeThatExists = is_int($hour) && is_int($minute) && is_int($second) && $hour <= 23 && $minute <= 59 && $second <= 59;
        if (!$midnightByDefault && !$timeThatExists) {
            return false;
        }

        // An ordinal date (the 213th day of 2026) is reported as day 213 of
        // month 1, with a warning that the date is invalid. Nothing else
        // gives a day above 31 without an error.
        $ordinal = $month === 1 && $day > 31;
        if ($ordinal) {
            if ($day > self::daysInYear($year)) {
                return false;
            }
        } elseif ($month < 1 || $month > 12 || $day < 1 || $day > self::daysInMonth($year, $month)) {
            return false;
        }

        // The warnings are a date or a time that does not exist, both ruled
        // out above, and one for each timezone after the first.
        if ($parsed['warning_count'] > ($ordinal ? 1 : 0) && !self::repeatsOneTimezone($value, $ordinal)) {
            return false;
        }

        // What is left is a stated date with, possibly, something that moves
        // it: an offset (`+1 week`, `tomorrow`, the seconds of a Unix
        // timestamp, the days of a week date) or a weekday. Each moves it
        // from the date written and by what is written, so the result is
        // fixed. Only a weekday named as a fact about the date can be wrong.
        $relative = $parsed['relative'] ?? null;
        if (!is_array($relative) || !isset($relative['weekday'])) {
            return true;
        }

        return self::namesNoOtherWeekday($value, (int) self::calendarDate($year, $month, $day)->format('w'));
    }

    /**
     * True when PHP's parser rejects the string, so that building a
     * `DateTimeImmutable` from it throws. The empty string is not malformed
     * in this sense: PHP reads it as `now`.
     */
    public static function isMalformed(string $value): bool
    {
        return $value !== '' && date_parse($value)['error_count'] > 0;
    }

    /**
     * The name of the timezone the string carries, as
     * `DateTimeImmutable::getTimezone()->getName()` gives it for a datetime
     * built from the string: an offset (`+01:00`), an abbreviation (`BST`)
     * or an identifier (`Europe/London`). Null when the string carries none.
     *
     * For a string that is not handed to PHP, so that a timezone that
     * contradicts the declared one is still reported as that.
     */
    public static function timezoneName(string $value): ?string
    {
        $parsed = date_parse($value);
        $offset = $parsed['zone'] ?? null;
        $abbreviation = $parsed['tz_abbr'] ?? null;
        $identifier = $parsed['tz_id'] ?? null;

        return match ($parsed['zone_type'] ?? 0) {
            1 => is_int($offset) ? self::offsetName($offset) : null,
            2 => is_string($abbreviation) ? $abbreviation : null,
            3 => is_string($identifier) ? $identifier : null,
            default => null,
        };
    }

    /**
     * An offset in seconds the way PHP names it: `+01:00`, and `+01:00:30`
     * for one that is not a whole number of minutes.
     */
    private static function offsetName(int $offset): string
    {
        $seconds = abs($offset);
        $name = sprintf('%s%02d:%02d', $offset < 0 ? '-' : '+', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));

        return $seconds % 60 === 0 ? $name : sprintf('%s:%02d', $name, $seconds % 60);
    }

    /**
     * True when every weekday the string names as a fact is the given one,
     * the weekday of the date written.
     *
     * PHP reads a weekday name as "move forward to that weekday", which
     * moves nothing when the date falls on it (the form of RFC 2822,
     * `Sat, 01 Aug 2026 19:00:00 +0000`) and silently gives another date
     * when it does not. A name that belongs to an instruction (`next
     * monday`, `first monday of`, `+2 monday`, `12 monday`, `monday next
     * week`) says where to move and is not a statement about the date, so
     * it is not compared.
     *
     * The parser is asked which of the two a name is. A name that states a
     * fact can be taken out of the string, and the string then reads the
     * same but for the weekday. A name that is part of an instruction
     * cannot: `next` alone is an error, and the `+2` of `+2 monday` alone
     * is a timezone. That holds for an offset written without a colon in
     * front of a name too: PHP reads the `+0100 Sat` of
     * `19:00:00 +0100 Sat` as a hundred Saturdays on, which is an
     * instruction, and it is accepted as one.
     */
    private static function namesNoOtherWeekday(string $value, int $weekdayOfDate): bool
    {
        preg_match_all(self::WEEKDAY_NAME, $value, $names, PREG_OFFSET_CAPTURE);

        $reading = self::readingBesidesTheWeekday($value);
        foreach ($names[0] as [$name, $offset]) {
            $after = substr($value, $offset + strlen($name));
            if (preg_match(self::A_WEEK_AFTER_A_WEEKDAY, $after) === 1) {
                continue;
            }

            if (self::readingBesidesTheWeekday(substr($value, 0, $offset) . ' ' . $after) !== $reading) {
                continue;
            }

            if (self::WEEKDAY_NUMBERS[strtolower(substr($name, 0, 3))] !== $weekdayOfDate) {
                return false;
            }
        }

        return true;
    }

    /**
     * What PHP's parser reads from a string, leaving out the weekday it
     * moves to and the time of day (a weekday name after a time sets the
     * time back to midnight), and the positions of the warnings and errors.
     *
     * @return array<string, mixed>
     */
    private static function readingBesidesTheWeekday(string $value): array
    {
        $parsed = date_parse($value);

        $relative = $parsed['relative'] ?? [];
        if (is_array($relative)) {
            unset($relative['weekday']);
            $parsed['relative'] = array_filter($relative, fn(mixed $part): bool => $part !== 0 && $part !== false);
        }

        unset($parsed['warnings'], $parsed['errors'], $parsed['hour'], $parsed['minute'], $parsed['second'], $parsed['fraction']);

        return $parsed;
    }

    /**
     * True when the timezones of a string that has more than one all give
     * the instant PHP reads from it.
     *
     * PHP uses the first timezone of a string and ignores the others with a
     * warning. That is harmless when they agree (`UTC UTC`, or the
     * `+0000 (UTC)` of an email date) and a contradiction when they do not.
     * A timezone is looked at when it is a word of its own; the rest of the
     * string, which may carry one more (`19:00:00+01:00`), is read without
     * them. A string whose timezones cannot be told apart this way is not
     * vouched for.
     *
     * Called for a string whose date is stated, so nothing here is read
     * from the clock.
     */
    private static function repeatsOneTimezone(string $value, bool $ordinal): bool
    {
        $timezones = [];
        $rest = [];
        $words = preg_split('/\s+/', trim($value));
        foreach ($words === false ? [] : $words as $word) {
            if (self::isTimezoneAlone($word)) {
                $timezones[] = $word;
            } else {
                $rest[] = $word;
            }
        }

        $remainder = implode(' ', $rest);
        $parsedRemainder = date_parse($remainder);
        if ($parsedRemainder['error_count'] > 0 || $parsedRemainder['warning_count'] > ($ordinal ? 1 : 0)) {
            return false;
        }

        $utc = new DateTimeZone('UTC');
        $instant = date_create_immutable($value, $utc);
        if ($instant === false) {
            // Not reached: the parser reported no error for the string
            return false;
        }

        // The local time PHP read, read again with each timezone in turn
        $written = $instant->format('Y-m-d\TH:i:s.u');
        $readings = isset($parsedRemainder['zone_type']) ? [date_create_immutable($remainder, $utc)] : [];
        foreach ($timezones as $timezone) {
            $readings[] = date_create_immutable("{$written} {$timezone}", $utc);
        }

        foreach ($readings as $reading) {
            if ($reading === false || $reading->format('U.u') !== $instant->format('U.u')) {
                return false;
            }
        }

        return true;
    }

    /**
     * True for a word that is a timezone and nothing else: an offset, an
     * abbreviation or an identifier, in parentheses or not.
     */
    private static function isTimezoneAlone(string $word): bool
    {
        $parsed = date_parse($word);

        return $parsed['error_count'] === 0
            && $parsed['warning_count'] === 0
            && isset($parsed['zone_type'])
            && $parsed['year'] === false
            && $parsed['month'] === false
            && $parsed['day'] === false
            && $parsed['hour'] === false
            && !isset($parsed['relative']);
    }

    /**
     * A calendar date at midnight UTC, built from numbers and not from the
     * clock. A day beyond the end of the month counts on into the next, which
     * is how an ordinal date is resolved.
     */
    private static function calendarDate(int $year, int $month, int $day): DateTimeImmutable
    {
        return (new DateTimeImmutable('@0'))->setDate($year, $month, $day);
    }

    private static function daysInYear(int $year): int
    {
        return self::isLeapYear($year) ? 366 : 365;
    }

    private static function daysInMonth(int $year, int $month): int
    {
        return match ($month) {
            2 => self::isLeapYear($year) ? 29 : 28,
            4, 6, 9, 11 => 30,
            default => 31,
        };
    }

    /**
     * The Gregorian rule, applied to every year the way PHP applies it
     * (year 0 and the years before it included).
     */
    private static function isLeapYear(int $year): bool
    {
        return $year % 4 === 0 && ($year % 100 !== 0 || $year % 400 === 0);
    }
}
