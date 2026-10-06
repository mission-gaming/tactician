<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Timeline;

use DateTimeImmutable;
use DateTimeZone;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use Throwable;

/**
 * Parses configuration times against an authoritative declared timezone.
 *
 * A time is a string that states its date in full: the year, the month and
 * the day, as in `2026-08-01 19:00` or `2026-08-01T19:00:00`. The time of
 * day is optional and defaults to midnight (`2026-11-09` is
 * `2026-11-09 00:00:00`), and so are the seconds. A string that is relative
 * to the current time (`now`, `tomorrow`, `+1 week`, the empty string), or
 * that leaves the date or its year out (`20:00`, `August 1 20:00`), is
 * rejected: PHP would resolve it against the clock, and the library never
 * asks for the current time. So is a string that does not mean what it
 * writes: a date or a time that does not exist (`2026-02-30 19:00`,
 * `2026-08-01 24:00`), which PHP would roll over into the next one, a
 * weekday name that is not the weekday of the date, and a second timezone
 * that is not the first ({@see DateTimeString::statesAnInstant()} has the
 * full rule).
 *
 * PHP honours a timezone or offset embedded in a datetime string and
 * silently ignores the DateTimeZone argument, which would let
 * configuration contradict itself. Everywhere the timeline system accepts
 * plain-data times, the declared timezone field is authoritative and an
 * embedded zone that contradicts it is rejected loudly.
 */
final readonly class ZonedTime
{
    /**
     * @param string $field The configuration key, for diagnostics
     *
     * @throws InvalidConfigurationException When the values are malformed (a timezone PHP rejects
     *                                       outright, such as one holding a NUL byte, included),
     *                                       the string does not state an instant by itself (see
     *                                       the class description), or it embeds a
     *                                       contradictory timezone
     */
    public static function parse(mixed $value, mixed $timezoneValue, string $field): DateTimeImmutable
    {
        if (!is_string($value) || !is_string($timezoneValue)) {
            throw new InvalidConfigurationException(
                "{$field} requires a datetime string and a timezone string",
                [$field => $value, 'timezone' => $timezoneValue],
                reason: InvalidConfigurationReason::WrongValueType
            );
        }

        // A string that is relative to the current time, or leaves a part of
        // the date out, is never handed to PHP: PHP would answer from the
        // clock. A string PHP cannot parse is handed over, for PHP's error.
        $statesInstant = DateTimeString::statesAnInstant($value);
        $timezone = null;
        $time = null;
        $previous = null;

        try {
            $timezone = new DateTimeZone($timezoneValue);
            if ($statesInstant || DateTimeString::isMalformed($value)) {
                $time = new DateTimeImmutable($value, $timezone);
            }
        } catch (Throwable $exception) {
            // Not `Exception`: DateTimeZone rejects a name that holds a NUL
            // byte with a ValueError, which is an Error and would pass an
            // `Exception` clause by. It is one more unparseable timezone.
            // The block holds the two constructors and nothing else that
            // can throw.
            $previous = $exception;
        }

        // A timezone or offset embedded in the string overrides the
        // declared zone during parsing; the declared field is authoritative.
        // A string that was not handed to PHP is asked for its timezone
        // without PHP building anything from it: a string with both faults
        // is reported for its timezone, as it was before the other fault
        // was looked for.
        if ($timezone !== null && $previous === null) {
            $embedded = $time !== null ? $time->getTimezone()->getName() : DateTimeString::timezoneName($value);
            if ($embedded !== null && $embedded !== $timezone->getName()) {
                throw new InvalidConfigurationException(
                    "The {$field} string carries its own timezone; declare the zone only via the 'timezone' field",
                    [
                        $field => $value,
                        'timezone' => $timezoneValue,
                        'embedded_timezone' => $embedded,
                    ],
                    reason: InvalidConfigurationReason::TimezoneMismatch
                );
            }
        }

        if (!$statesInstant || $timezone === null || $time === null) {
            throw new InvalidConfigurationException(
                "{$field} or its timezone is not parseable",
                [$field => $value, 'timezone' => $timezoneValue],
                '',
                0,
                $previous,
                reason: InvalidConfigurationReason::UnparseableTime
            );
        }

        return $time;
    }
}
