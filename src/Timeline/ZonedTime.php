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
 * A time is a string that states a complete, absolute date and time: the
 * year, the month, the day, the hour and the minute, as in
 * `2026-08-01 19:00` or `2026-08-01T19:00:00`. Seconds are optional and
 * default to zero. A string that is relative to the current time (`now`,
 * `tomorrow`, `+1 week`, the empty string), that leaves the date or the time
 * of day out, or that names a date or a time that does not exist
 * (`2026-02-30 19:00`, `2026-08-01 24:00`) is rejected: PHP would resolve
 * the first two against the clock and roll the third over, and the library
 * never asks for the current time ({@see DateTimeString::isAbsolute()} has
 * the full rule).
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
     *                                       the string does not state a complete, absolute date
     *                                       and time, or it embeds a contradictory timezone
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
        // the instant out, is never handed to PHP: PHP would answer from the
        // clock. A string PHP cannot parse is handed over, for PHP's error.
        $statesInstant = DateTimeString::isAbsolute($value);
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

        // A timezone or offset embedded in the string overrides the
        // declared zone during parsing; the declared field is authoritative.
        if ($time->getTimezone()->getName() !== $timezone->getName()) {
            throw new InvalidConfigurationException(
                "The {$field} string carries its own timezone; declare the zone only via the 'timezone' field",
                [
                    $field => $value,
                    'timezone' => $timezoneValue,
                    'embedded_timezone' => $time->getTimezone()->getName(),
                ],
                reason: InvalidConfigurationReason::TimezoneMismatch
            );
        }

        return $time;
    }
}
