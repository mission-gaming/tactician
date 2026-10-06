<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Repack\Internal;

use MissionGaming\Tactician\Exceptions\InvalidInputException;

/**
 * The canonical byte encoding behind RepackOutcome::fingerprint().
 *
 * The encoding is specified on that method, which is the contract; this
 * class is its one implementation. Every value becomes a string of bytes
 * that depends on the value alone: not on the PHP version, the platform,
 * the locale, the `precision` and `serialize_precision` settings, or the
 * order in which a map was built. No two different values share an
 * encoding, because every string states its length and every list and map
 * states its count.
 *
 * @internal
 */
final class CanonicalEncoding
{
    /**
     * Encode records as a set: each record encoded, then the encodings in
     * ascending byte order, so the order the records were given in does
     * not reach the result.
     *
     * @param array<array<mixed>> $records
     *
     * @throws InvalidInputException When a record holds a value with no canonical encoding
     */
    public static function set(array $records): string
    {
        $encoded = [];
        foreach ($records as $record) {
            $encoded[] = self::value($record);
        }
        sort($encoded, SORT_STRING);

        return 'l' . count($encoded) . ':' . implode('', $encoded) . ';';
    }

    /**
     * Encode one value: null, a boolean, an integer, a float, a string, or
     * an array of those to any depth.
     *
     * @throws InvalidInputException When the value is none of those
     */
    public static function value(mixed $value): string
    {
        if ($value === null) {
            return 'n;';
        }

        if (is_bool($value)) {
            return $value ? 'b1;' : 'b0;';
        }

        if (is_int($value)) {
            return 'i' . $value . ';';
        }

        if (is_float($value)) {
            // The eight bytes of the IEEE 754 double, most significant
            // first: no decimal formatting, so no setting can change it
            return 'f' . bin2hex(pack('E', $value)) . ';';
        }

        if (is_string($value)) {
            return 's' . strlen($value) . ':' . $value . ';';
        }

        if (!is_array($value)) {
            throw new InvalidInputException(
                'A repack outcome can be fingerprinted only when its records serialize to null, booleans, '
                . 'integers, floats, strings and arrays of them; a record holds a value of type '
                . get_debug_type($value)
            );
        }

        if (array_is_list($value)) {
            $items = '';
            foreach ($value as $item) {
                $items .= self::value($item);
            }

            return 'l' . count($value) . ':' . $items . ';';
        }

        $entries = [];
        foreach ($value as $key => $item) {
            $entries[] = self::value($key) . self::value($item);
        }
        sort($entries, SORT_STRING);

        return 'm' . count($entries) . ':' . implode('', $entries) . ';';
    }
}
