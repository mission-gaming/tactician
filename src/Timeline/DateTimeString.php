<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Timeline;

use DateTimeImmutable;

/**
 * Decides whether a datetime string states an instant by itself.
 *
 * PHP's date parser accepts far more than an instant: `now`, `tomorrow`,
 * `+1 week` and the empty string are resolved against the current time, and
 * a string that leaves a part out (`20:00`, `August 1`) has that part filled
 * in from the clock. A schedule built from such a string differs from one run
 * to the next, which the library does not allow: it never asks for the
 * current time. The places that read a datetime from plain data therefore
 * ask this class first, and hand the string to PHP only when the answer
 * cannot depend on when the question is asked.
 *
 * The check reads the string with `date_parse()`, which reports what the
 * string says and never consults the clock.
 *
 * @internal Not public API; it carries no compatibility guarantee
 */
final class DateTimeString
{
    /**
     * True when the string states a complete, absolute date and time: the
     * year, the month, the day, the hour and the minute are all written in
     * it, the date and the time exist, and nothing in it moves the instant
     * away from the one written.
     *
     * A string is complete without seconds: PHP reads them as zero, not from
     * the clock. A timezone or offset in the string does not matter here.
     *
     * Rejected, among others:
     *
     * - the empty string, `now`, `tomorrow`, `+1 week`, `next monday 20:00`
     *   (relative to the clock);
     * - `2026-08-01` (no time of day), `20:00` (no date), `August 1 20:00`
     *   (no year);
     * - `2026-08-01 20:00 +1 week`, `first day of 2026-08-01 20:00` (a
     *   relative part on top of an absolute one);
     * - `2026-02-30 20:00`, `2026-08-01 24:00`, `2026-08-01 23:59:60` (a
     *   date or a time that does not exist, which PHP rolls over into the
     *   next one);
     * - a weekday name that contradicts the date (`Mon, 01 Aug 2026
     *   19:00:00 +0000` is a Saturday, and PHP moves it to the Monday after);
     * - anything PHP cannot parse at all.
     *
     * Three notations that PHP's parser reports as a base date and an offset
     * from it are absolute and accepted: a Unix timestamp (`@1785610800`),
     * an ISO 8601 week date (`2026-W31-6T19:00`) and an ISO 8601 ordinal
     * date (`2026-213T19:00`).
     */
    public static function isAbsolute(string $value): bool
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

        // A part the string does not state is `false`. PHP takes a missing
        // date part, and the time of a string that has no date, from the
        // clock; the time of a string that has a date and no time is
        // midnight, which the string did not say either.
        if (!is_int($year) || !is_int($month) || !is_int($day) || !is_int($hour) || !is_int($minute) || !is_int($second)) {
            return false;
        }

        if ($hour > 23 || $minute > 59 || $second > 59) {
            return false;
        }

        // An ordinal date (the 213th day of 2026) is reported as day 213 of
        // month 1, with a warning that the date is invalid. Nothing else
        // gives a day above 31.
        $ordinal = $month === 1 && $day > 31;
        if ($ordinal) {
            if ($day > self::daysInYear($year)) {
                return false;
            }
        } elseif ($month < 1 || $month > 12 || $day < 1 || $day > self::daysInMonth($year, $month)) {
            return false;
        }

        // The warnings are a date or a time that does not exist, both ruled
        // out above, and a second timezone.
        if ($parsed['warning_count'] > ($ordinal ? 1 : 0)) {
            return false;
        }

        $relative = $parsed['relative'] ?? null;
        if (!is_array($relative)) {
            return true;
        }

        // A Unix timestamp is reported as 1970-01-01 00:00:00 and a relative
        // number of seconds. The pattern covers the whole string, so nothing
        // else can be in it.
        if (preg_match('/^\s*@-?\d+(?:\.\d+)?\s*$/', $value) === 1) {
            return true;
        }

        if (($relative['weekdays'] ?? 0) !== 0
            || ($relative['first_day_of_month'] ?? false) !== false
            || ($relative['last_day_of_month'] ?? false) !== false
        ) {
            return false;
        }

        $weekday = $relative['weekday'] ?? null;

        // An ISO 8601 week date is reported as 1 January of its year and a
        // relative number of days. Those days are part of the notation only
        // when they are exactly the distance to the week and day written.
        $notationDays = 0;
        if (preg_match('/^\s*\d{4}-?W(\d{2})(?:-?(\d))?/i', $value, $week) === 1) {
            if ($weekday !== null) {
                return false;
            }

            $firstOfJanuary = self::calendarDate($year, 1, 1);
            $notationDays = (int) $firstOfJanuary
                ->diff($firstOfJanuary->setISODate($year, (int) $week[1], isset($week[2]) ? (int) $week[2] : 1))
                ->format('%r%a');
        }

        if (($relative['year'] ?? null) !== 0
            || ($relative['month'] ?? null) !== 0
            || ($relative['day'] ?? null) !== $notationDays
            || ($relative['hour'] ?? null) !== 0
            || ($relative['minute'] ?? null) !== 0
            || ($relative['second'] ?? null) !== 0
        ) {
            return false;
        }

        // A weekday name is reported as a relative part: PHP moves the date
        // forward to that weekday. Next to a date that falls on it, it moves
        // nothing (the form of RFC 2822, `Sat, 01 Aug 2026 19:00:00 +0000`).
        if ($weekday !== null) {
            return is_int($weekday) && $weekday % 7 === (int) self::calendarDate($year, $month, $day)->format('w');
        }

        return true;
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
