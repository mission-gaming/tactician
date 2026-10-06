<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Tests\Support;

use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Timeline\ZonedTime;

/**
 * Datetime strings for the tests of what plain-data configuration accepts.
 *
 * Two corpora. The absolute one is generated from numbers, so each string
 * comes with the fields that were written into it and a test can compare
 * what was parsed with what was written, without asking PHP what the string
 * means. The other one holds strings that are relative to the current time
 * or leave a part of the instant out.
 *
 * The class is loaded in a child process too (under another default
 * timezone), which is why the corpora live here and not in a test file.
 */
final class DatetimeCorpus
{
    /**
     * Ways of writing one instant that state every field, keyed by a name.
     * Each is a `sprintf()` pattern over year, month, day, hour, minute,
     * second. A pattern that leaves the seconds out is used for instants
     * whose seconds are zero only.
     *
     * @var array<string, array{pattern: string, seconds: bool}>
     */
    private const array LAYOUTS = [
        'a space, with seconds' => ['pattern' => '%1$04d-%2$02d-%3$02d %4$02d:%5$02d:%6$02d', 'seconds' => true],
        'a space, without seconds' => ['pattern' => '%1$04d-%2$02d-%3$02d %4$02d:%5$02d', 'seconds' => false],
        'a T, with seconds' => ['pattern' => '%1$04d-%2$02d-%3$02dT%4$02d:%5$02d:%6$02d', 'seconds' => true],
        'a T, without seconds' => ['pattern' => '%1$04d-%2$02d-%3$02dT%4$02d:%5$02d', 'seconds' => false],
        'fractional seconds' => ['pattern' => '%1$04d-%2$02d-%3$02dT%4$02d:%5$02d:%6$02d.000000', 'seconds' => true],
        'compact ISO 8601' => ['pattern' => '%1$04d%2$02d%3$02dT%4$02d%5$02d%6$02d', 'seconds' => true],
        'slashes' => ['pattern' => '%1$04d/%2$02d/%3$02d %4$02d:%5$02d:%6$02d', 'seconds' => true],
        'no leading zeros' => ['pattern' => '%1$d-%2$d-%3$d %4$d:%5$02d:%6$02d', 'seconds' => true],
        'the time first' => ['pattern' => '%4$02d:%5$02d:%6$02d %1$04d-%2$02d-%3$02d', 'seconds' => true],
        'surrounding spaces' => ['pattern' => ' %1$04d-%2$02d-%3$02d %4$02d:%5$02d:%6$02d ', 'seconds' => true],
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
                        foreach (self::LAYOUTS as $layout => ['pattern' => $pattern, 'seconds' => $seconds]) {
                            if (!$seconds && $second !== 0) {
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
     * Strings that do not state a complete, absolute date and time: relative
     * to the current time, or with a part left out, or both.
     *
     * @return list<string>
     */
    public static function relativeAndPartial(): array
    {
        $corpus = [
            // The reproduction of the report this guards
            '', 'now', 'tomorrow', '+1 week', 'next monday 20:00',
            // Nothing but white space
            ' ', "\t", "\n",
            // Keywords alone
            'today', 'yesterday', 'midnight', 'noon', 'NOW', 'Tomorrow',
            // A date without a time of day
            '2026-08-01', '2026/08/01', '20260801', '1 August 2026', 'August 2026', '2026-08',
            '2026-W31-6', '2026-213', '01.08.2026',
            // A time of day without a date
            '20:00', '20:00:00', 'T20:00', '8pm', '20:00:00.5', '20:00 UTC',
            // A date without its year, a year alone
            'August 1 20:00', '1 August 20:00', 'Aug 1 20:00:00', '2026',
            // A weekday and nothing that fixes the week
            'monday', 'monday 20:00', 'saturday this week', 'sat 20:00',
            // Relative to the clock
            'first day of next month', 'last day of this month 20:00', 'first day of january', 'this week',
            'next year', 'last friday', 'tomorrow noon', 'yesterday 20:00', 'today 20:00', 'noon tomorrow',
            'third wednesday of next month', '2 weekdays', 'ago',
        ];

        foreach (['second', 'sec', 'minute', 'min', 'hour', 'day', 'week', 'fortnight', 'month', 'year', 'weekday'] as $unit) {
            foreach (['+1 %s', '-1 %s', '+2 %ss', '3 %ss', '3 %ss ago', 'next %s', 'last %s', '20:00 +1 %s', '+1 %s 20:00'] as $pattern) {
                $corpus[] = sprintf($pattern, $unit);
            }
        }

        // A relative part on top of a complete date and time: the base is
        // stated, and the instant is still not the one written.
        foreach ([
            '+1 week', '-1 day', '+2 hours', '+30 minutes', '+1 sec', 'tomorrow', 'yesterday', 'next year', 'next month',
            'last week', 'monday', 'next monday', 'first day of next month', 'last day of this month', '3 weekdays',
            'this week', '1 fortnight ago',
        ] as $relative) {
            $corpus[] = "2026-08-01 20:00:00 {$relative}";
            $corpus[] = "2026-08-01T20:00 {$relative}";
        }
        $corpus[] = 'first day of 2026-08-01 20:00';
        $corpus[] = 'last day of february 2026 10:00';

        return $corpus;
    }

    /**
     * What `ZonedTime::parse()` makes of every string of both corpora in
     * the given timezone: the instant in UTC, or the reason it was rejected.
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
            ...self::relativeAndPartial(),
        ];

        $verdicts = [];
        foreach ($values as $value) {
            try {
                $verdicts[$value] = ZonedTime::parse($value, $timezone, 'start')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
            } catch (InvalidConfigurationException $exception) {
                $verdicts[$value] = 'rejected: ' . ($exception->getReason()->value ?? 'no reason');
            }
        }

        return $verdicts;
    }
}
