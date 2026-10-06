<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Timeline\ZonedTime;

/**
 * Datetime strings for the tests of what plain-data configuration accepts.
 *
 * Four corpora. Two are accepted: the absolute one is generated from
 * numbers, so each string comes with the fields that were written into it
 * and a test can compare what was parsed with what was written, without
 * asking PHP what the string means; the other holds an offset from a stated
 * date, with the fields that offset gives from that date. Two are rejected:
 * strings that are relative to the current time or leave the date or its
 * year out, and strings that write a date, a time or a weekday that is not
 * the one PHP reads.
 *
 * The class is loaded in a child process too (under another default
 * timezone), which is why the corpora live here and not in a test file.
 */
final class DatetimeCorpus
{
    /**
     * Ways of writing one instant, keyed by a name. Each is a `sprintf()`
     * pattern over year, month, day, hour, minute, second. A pattern that
     * leaves the seconds out is used for instants whose seconds are zero
     * only, and one that leaves the time of day out for midnight only:
     * what a string leaves out of the time is zero.
     *
     * @var array<string, array{pattern: string, seconds: bool, time: bool}>
     */
    private const array LAYOUTS = [
        'a space, with seconds' => ['pattern' => '%1$04d-%2$02d-%3$02d %4$02d:%5$02d:%6$02d', 'seconds' => true, 'time' => true],
        'a space, without seconds' => ['pattern' => '%1$04d-%2$02d-%3$02d %4$02d:%5$02d', 'seconds' => false, 'time' => true],
        'a T, with seconds' => ['pattern' => '%1$04d-%2$02d-%3$02dT%4$02d:%5$02d:%6$02d', 'seconds' => true, 'time' => true],
        'a T, without seconds' => ['pattern' => '%1$04d-%2$02d-%3$02dT%4$02d:%5$02d', 'seconds' => false, 'time' => true],
        'fractional seconds' => ['pattern' => '%1$04d-%2$02d-%3$02dT%4$02d:%5$02d:%6$02d.000000', 'seconds' => true, 'time' => true],
        'compact ISO 8601' => ['pattern' => '%1$04d%2$02d%3$02dT%4$02d%5$02d%6$02d', 'seconds' => true, 'time' => true],
        'slashes' => ['pattern' => '%1$04d/%2$02d/%3$02d %4$02d:%5$02d:%6$02d', 'seconds' => true, 'time' => true],
        'no leading zeros' => ['pattern' => '%1$d-%2$d-%3$d %4$d:%5$02d:%6$02d', 'seconds' => true, 'time' => true],
        'the time first' => ['pattern' => '%4$02d:%5$02d:%6$02d %1$04d-%2$02d-%3$02d', 'seconds' => true, 'time' => true],
        'surrounding spaces' => ['pattern' => ' %1$04d-%2$02d-%3$02d %4$02d:%5$02d:%6$02d ', 'seconds' => true, 'time' => true],
        'a date and no time of day' => ['pattern' => '%1$04d-%2$02d-%3$02d', 'seconds' => false, 'time' => false],
        'a date with slashes and no time of day' => ['pattern' => '%1$04d/%2$02d/%3$02d', 'seconds' => false, 'time' => false],
        'a compact date and no time of day' => ['pattern' => '%1$04d%2$02d%3$02d', 'seconds' => false, 'time' => false],
        'day, month and year with dots and no time of day' => ['pattern' => '%3$02d.%2$02d.%1$04d', 'seconds' => false, 'time' => false],
    ];

    /**
     * Absolute datetime strings with the fields written into them.
     *
     * The dates are the first, a middle and the last day of every month of
     * a leap year and of two others; the times are spread over the day and
     * include midnight and the last second.
     *
     * @return list<array{value: string, layout: string, fields: string}> `fields` is the instant as
     *                                                                    `Y-m-d H:i:s`
     */
    public static function absolute(): array
    {
        $times = [[0, 0, 0], [0, 0, 1], [9, 5, 0], [12, 0, 0], [19, 30, 0], [23, 59, 59]];
        $lastDays = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

        $corpus = [];
        foreach ([2024, 2026, 2031] as $year) {
            for ($month = 1; $month <= 12; ++$month) {
                $last = $month === 2 && $year === 2024 ? 29 : $lastDays[$month - 1];
                foreach ([1, 15, $last] as $day) {
                    foreach ($times as [$hour, $minute, $second]) {
                        foreach (self::LAYOUTS as $layout => ['pattern' => $pattern, 'seconds' => $seconds, 'time' => $time]) {
                            if (!$seconds && $second !== 0) {
                                continue;
                            }
                            if (!$time && ($hour !== 0 || $minute !== 0)) {
                                continue;
                            }

                            $corpus[] = [
                                'value' => sprintf($pattern, $year, $month, $day, $hour, $minute, $second),
                                'layout' => $layout,
                                'fields' => sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second),
                            ];
                        }
                    }
                }
            }
        }

        return $corpus;
    }

    /**
     * An offset on top of a stated date and time, with the fields the
     * offset gives when it is counted from that date.
     *
     * The expected fields come from `DateTimeImmutable::modify()` on a
     * datetime built from numbers, so nothing in them comes from the clock:
     * a string whose offset PHP counted from the current time would not
     * parse to them.
     *
     * @return list<array{value: string, layout: string, fields: string}> `fields` is the instant as
     *                                                                    `Y-m-d H:i:s`
     */
    public static function offsetFromAStatedDate(): array
    {
        $offsets = [
            '+1 week', '-1 day', '+2 hours', '+30 minutes', '+1 sec', '+1 month', 'next year', 'next month', '+3 weekdays',
            '1 fortnight ago', 'first day of next month', 'last day of this month', 'next monday', 'last friday', 'tomorrow',
            'yesterday', 'noon', 'midnight',
        ];
        $utc = new DateTimeZone('UTC');

        $corpus = [];
        foreach ([[2026, 8, 1, 20, 0, 0], [2024, 2, 28, 9, 5, 0], [2026, 12, 31, 23, 59, 59], [2031, 1, 31, 0, 0, 0]] as [$year, $month, $day, $hour, $minute, $second]) {
            $base = (new DateTimeImmutable('@0'))->setTimezone($utc)->setDate($year, $month, $day)->setTime($hour, $minute, $second);
            foreach ($offsets as $offset) {
                $fields = $base->modify($offset)->format('Y-m-d H:i:s');
                $corpus[] = ['value' => "{$base->format('Y-m-d H:i:s')} {$offset}", 'layout' => $offset, 'fields' => $fields];
                $corpus[] = ['value' => "{$base->format('Y-m-d\TH:i:s')} {$offset}", 'layout' => $offset, 'fields' => $fields];
            }
        }

        return $corpus;
    }

    /**
     * Strings PHP would resolve against the current time: relative to it,
     * or with no date, or with a date that leaves its year out.
     *
     * @return list<string>
     */
    public static function clockDependent(): array
    {
        $corpus = [
            // The reproduction of the report this guards
            '', 'now', 'tomorrow', '+1 week', 'next monday 20:00',
            // Nothing but white space
            ' ', "\t", "\n",
            // Keywords alone
            'today', 'yesterday', 'midnight', 'noon', 'NOW', 'Tomorrow',
            // A time of day without a date
            '20:00', '20:00:00', 'T20:00', '8pm', '20:00:00.5',
            // A date without its year, a year alone
            'August 1 20:00', 'Aug 1 20:00:00', '1 August', '08/01', '2026',
            // A weekday and nothing that fixes the week
            'monday', 'monday 20:00', 'saturday this week', 'sat 20:00',
            // Relative to the clock
            'first day of next month', 'last day of this month 20:00', 'first day of january', 'this week',
            'next year', 'last friday', 'tomorrow noon', 'yesterday 20:00', 'today 20:00', 'noon tomorrow',
            'third wednesday of next month', 'first monday of August 19:00', '2 weekdays', 'ago',
        ];

        foreach (['second', 'sec', 'minute', 'min', 'hour', 'day', 'week', 'fortnight', 'month', 'year', 'weekday'] as $unit) {
            foreach (['+1 %s', '-1 %s', '+2 %ss', '3 %ss', '3 %ss ago', 'next %s', 'last %s', '20:00 +1 %s', '+1 %s 20:00'] as $pattern) {
                $corpus[] = sprintf($pattern, $unit);
            }
        }

        return $corpus;
    }

    /**
     * Strings that state a date in full and are still not the instant PHP
     * reads from them: a date or a time that does not exist, which PHP
     * rolls over, and a weekday name that is not the weekday of the date,
     * which PHP moves the date to. None carries a timezone.
     *
     * @return list<string>
     */
    public static function notTheInstantWritten(): array
    {
        return [
            '2026-02-30 20:00', '2026-02-30', '2026-02-29T10:00:00', '2026-04-31 10:00', '31.04.2026', '2026-08-00 10:00',
            '2026-00-10 10:00', '2026-08-01 24:00', '2026-08-01T24:00:00', '2026-08-01 23:59:60', '2026-366T19:00', '2026-366',
            '2026-02-30 20:00 +1 week', 'first day of 2026-02-30 20:00',
            'Mon, 01 Aug 2026 19:00:00', 'Sunday 2026-08-01 19:00', '2026-08-01 19:00 monday', '2026-08-01 Fri 19:00',
            'Friday, 01-Aug-26 19:00:00', 'Sat 2026-W31-6T19:00',
        ];
    }

    /**
     * What `ZonedTime::parse()` makes of every string of the four corpora
     * in the given timezone: the instant in UTC, or the reason it was
     * rejected.
     *
     * A test compares the verdicts of two processes that differ in their
     * default timezone, and so in what "today" is.
     *
     * @return array<string, string> Verdict by string
     */
    public static function verdicts(string $timezone): array
    {
        $values = [
            ...array_map(fn(array $entry): string => $entry['value'], self::absolute()),
            ...array_map(fn(array $entry): string => $entry['value'], self::offsetFromAStatedDate()),
            ...self::clockDependent(),
            ...self::notTheInstantWritten(),
        ];

        $verdicts = [];
        foreach ($values as $value) {
            try {
                $verdicts[$value] = ZonedTime::parse($value, $timezone, 'start')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
            } catch (InvalidConfigurationException $exception) {
                $verdicts[$value] = 'rejected: ' . ($exception->getReason()->value ?? 'no reason');
            }
        }

        return $verdicts;
    }
}
