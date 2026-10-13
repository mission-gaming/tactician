<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Timeline;

use DateInterval;
use DateTimeImmutable;

/**
 * Several steps of one interval from an anchor, as a single calendar step.
 *
 * A timeline's round N is its start plus N−1 round intervals, and a slot is
 * its round's (or its session's) first slot plus one slot interval for each
 * slot before it. Adding the interval once per step, each addition from the
 * result of the one before, lets one step's adjustment carry into every
 * later step: a month that is too short for the day, or a local time the
 * clocks skip. Here the steps are multiplied out first and added to the
 * anchor once, so a step is adjusted for itself alone.
 *
 * The rules, for an interval with years, months, days, hours, minutes and
 * seconds (an ISO 8601 duration, or what `diff()` returns):
 *
 * - Years and months move the anchor's date by whole months and keep its
 *   day of the month. Where the month reached is too short for that day,
 *   the date is that month's last day: one month from 31 January is the end
 *   of February, two months is 31 March.
 * - Days are then added to that date.
 * - The time of day is kept, and the hours, minutes and seconds are added,
 *   as PHP's `DateTimeImmutable::add()` does for that interval: elapsed
 *   time for an interval built from an ISO 8601 duration, local time for
 *   one that `diff()` returned. A local time the clocks skip is moved on by
 *   the skipped hour, as PHP moves it.
 *
 * An interval made by `DateInterval::createFromDateString()` (`next
 * weekday`, `last day of next month`) has no multiple, so it is added one
 * step at a time, as PHP adds it.
 *
 * @internal Not public API: the arithmetic behind
 *           TimelineDefinition::getSlotTime() and SessionGrid::getSlotTime(),
 *           which document what it gives.
 */
final class IntervalArithmetic
{
    /**
     * The anchor moved on by a number of steps of the interval, in the
     * anchor's timezone.
     *
     * @param int $steps How many intervals to add; 0 returns the anchor
     */
    public static function advance(DateTimeImmutable $anchor, DateInterval $interval, int $steps): DateTimeImmutable
    {
        if ($steps === 0) {
            return $anchor;
        }

        if (self::isRelative($interval)) {
            for ($i = 0; $i < $steps; ++$i) {
                $anchor = $anchor->add($interval);
            }

            return $anchor;
        }

        // PHP negates every part of an inverted interval when it adds it
        $sign = $interval->invert === 1 ? -1 : 1;
        $monthDays = self::daysToSameDayOfMonth($anchor, $sign * ($interval->y * 12 + $interval->m) * $steps);

        // A copy keeps what the interval does not show: whether PHP adds its
        // time part as elapsed time or as local time
        $scaled = clone $interval;
        $scaled->y = 0;
        $scaled->m = 0;
        $scaled->d = $interval->d * $steps + $sign * $monthDays;
        $scaled->h = $interval->h * $steps;
        $scaled->i = $interval->i * $steps;
        $scaled->s = $interval->s * $steps;
        $scaled->f = $interval->f * $steps;

        return $anchor->add($scaled);
    }

    /**
     * Whether the interval was made from a relative date string, which is
     * the one kind whose parts cannot be read and multiplied.
     */
    private static function isRelative(DateInterval $interval): bool
    {
        // Read through an array cast: the property is listed for every
        // interval and cannot be read by name
        return (((array) $interval)['from_string'] ?? false) === true;
    }

    /**
     * How many days lie between the anchor's local date and the date a
     * number of months away from it: the same day of the month, or the last
     * day of a month that does not have that day.
     */
    private static function daysToSameDayOfMonth(DateTimeImmutable $anchor, int $months): int
    {
        if ($months === 0) {
            return 0;
        }

        $year = (int) $anchor->format('Y');
        $month = (int) $anchor->format('n');
        $day = (int) $anchor->format('j');

        $monthIndex = $year * 12 + ($month - 1) + $months;
        $targetYear = (int) floor($monthIndex / 12);
        $targetMonth = $monthIndex - $targetYear * 12 + 1;
        $targetDay = min($day, self::daysInMonth($targetYear, $targetMonth));

        return self::dayNumber($targetYear, $targetMonth, $targetDay) - self::dayNumber($year, $month, $day);
    }

    private static function daysInMonth(int $year, int $month): int
    {
        if ($month === 2) {
            return ($year % 4 === 0 && $year % 100 !== 0) || $year % 400 === 0 ? 29 : 28;
        }

        return in_array($month, [4, 6, 9, 11], true) ? 30 : 31;
    }

    /**
     * A count of days that rises by one for every day of the proleptic
     * Gregorian calendar, which is the calendar PHP's dates use. Only
     * differences between two of them are used.
     */
    private static function dayNumber(int $year, int $month, int $day): int
    {
        // Count the year from 1 March, so that the leap day is its last day
        if ($month <= 2) {
            --$year;
            $month += 12;
        }

        return 365 * $year
            + (int) floor($year / 4)
            - (int) floor($year / 100)
            + (int) floor($year / 400)
            + intdiv(153 * ($month - 3) + 2, 5)
            + $day;
    }
}
