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
 *
 * The declared timezone is a region identifier from the timezone database
 * (`Europe/London`, `America/New_York`, and `UTC`) or an explicit offset
 * (`+01:00`, `-05:00`). An abbreviation (`BST`, `EST`, `CET`, and `GMT`
 * too) is refused: PHP reads one as a fixed offset all year (`BST` is
 * `+01:00` in January as well), so a caller who means a place would get
 * kickoffs an hour out for half the year and no error. A caller who does
 * mean a fixed offset writes it as one. The names of UTC itself are not
 * refused, in any case: `Z`, the designator ISO 8601 and every kickoff the
 * library writes end with, `UTC` and `UCT`, because they name no place
 * and no clock change. The rule is ADR 0023.
 *
 * @internal Not public API: the parser behind the `fromArray()` factories that
 *           read a configured datetime. It carries no compatibility guarantee.
 */
final readonly class ZonedTime
{
    /**
     * The abbreviations that name UTC itself, in upper case: they name no
     * place and no clock change, and `Z` is what every kickoff the library
     * writes ends with. `GMT` is not one of them, being the winter time of
     * a place.
     */
    private const array NAMES_OF_UTC = ['Z', 'UTC', 'UCT'];

    /**
     * @param string $field The configuration key, for diagnostics
     *
     * @throws InvalidConfigurationException When the values are malformed (a timezone PHP rejects
     *                                       outright, such as one holding a NUL byte, included),
     *                                       the timezone is an abbreviation (reason
     *                                       `TimezoneAbbreviation`, before the string is looked
     *                                       at), the string does not state an instant by itself
     *                                       (see the class description), or it embeds a
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

        if ($timezone !== null && self::isAbbreviation($timezone)) {
            throw new InvalidConfigurationException(
                "The timezone '{$timezoneValue}' is an abbreviation, which PHP reads as the fixed offset "
                    . self::offsetOf($timezone) . " all year, with no clock changes; declare a region identifier (such as 'Europe/London') "
                    . "for a place, or the offset itself ('" . self::offsetOf($timezone) . "') for a fixed offset",
                [
                    $field => $value,
                    'timezone' => $timezoneValue,
                    'fixed_offset' => self::offsetOf($timezone),
                ],
                reason: InvalidConfigurationReason::TimezoneAbbreviation
            );
        }

        // A timezone or offset embedded in the string overrides the
        // declared zone during parsing; the declared field is authoritative.
        // A string that was not handed to PHP is asked for its timezone
        // without PHP building anything from it: a string with both faults
        // is reported for its timezone, as it was before the other fault
        // was looked for.
        if ($timezone !== null && $previous === null) {
            $embedded = $time?->getTimezone()->getName() ?? DateTimeString::timezoneName($value);
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

    /**
     * Write a time as parse() reads it: local time in the timezone the
     * object is in, to the second (`2026-08-01 19:00:00`), with the
     * fraction of a second after it when there is one
     * (`2026-08-01 19:00:00.250000`). The timezone is not written; the
     * caller writes its name beside the time.
     */
    public static function write(DateTimeImmutable $time): string
    {
        return $time->format($time->format('u') === '000000' ? 'Y-m-d H:i:s' : 'Y-m-d H:i:s.u');
    }

    /**
     * Write the name of a time's timezone as parse() reads it back: a region
     * identifier or an offset, as the object holds it. A time held in a
     * timezone abbreviation (built in code as
     * `new DateTimeZone('BST')`, or parsed from a string that ends in one)
     * is refused, because parse() refuses the name it would write; it is
     * never written as another name, not even its own offset, which would
     * read back as the same instants and state a zone the caller did not
     * write.
     *
     * @param string $key The configuration key the name is written under, for the error
     *
     * @throws InvalidConfigurationException When the timezone is an abbreviation (reason
     *                                       `NotSerializable`)
     */
    public static function writeTimezone(DateTimeImmutable $time, string $key): string
    {
        $timezone = $time->getTimezone();
        if (self::isAbbreviation($timezone)) {
            throw new InvalidConfigurationException(
                "{$key} cannot be written: the timezone '{$timezone->getName()}' is an abbreviation, which plain data does not accept; "
                    . "build the time in a region identifier (such as 'Europe/London') or in the offset itself ('"
                    . self::offsetOf($timezone) . "')",
                [$key => $timezone->getName(), 'fixed_offset' => self::offsetOf($timezone)],
                reason: InvalidConfigurationReason::NotSerializable
            );
        }

        return $timezone->getName();
    }

    /**
     * Whether PHP holds the timezone as an abbreviation (its type 2), and
     * not as a region identifier (type 3) or an offset (type 1), other than
     * a name of UTC itself.
     */
    private static function isAbbreviation(DateTimeZone $timezone): bool
    {
        return ($timezone->__serialize()['timezone_type'] ?? null) === 2
            && !in_array(strtoupper($timezone->getName()), self::NAMES_OF_UTC, true);
    }

    /**
     * The fixed offset an abbreviation stands for, as `+01:00`.
     */
    private static function offsetOf(DateTimeZone $timezone): string
    {
        return new DateTimeImmutable('@0')->setTimezone($timezone)->format('P');
    }
}
