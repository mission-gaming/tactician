<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Timeline;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;

/**
 * Parses configuration times against an authoritative declared timezone.
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
     * @throws InvalidConfigurationException When the values are malformed or the
     *                                       string embeds a contradictory timezone
     */
    public static function parse(mixed $value, mixed $timezoneValue, string $field): DateTimeImmutable
    {
        if (!is_string($value) || !is_string($timezoneValue)) {
            throw new InvalidConfigurationException(
                "{$field} requires a datetime string and a timezone string",
                [$field => $value, 'timezone' => $timezoneValue]
            );
        }

        try {
            $timezone = new DateTimeZone($timezoneValue);
            $time = new DateTimeImmutable($value, $timezone);
        } catch (Exception $exception) {
            throw new InvalidConfigurationException(
                "{$field} or its timezone is not parseable",
                [$field => $value, 'timezone' => $timezoneValue],
                '',
                0,
                $exception
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
                ]
            );
        }

        return $time;
    }
}
