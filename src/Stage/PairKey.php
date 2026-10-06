<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

/**
 * The one place that turns participant ids into an order-independent key.
 *
 * A pairing is the same pairing whichever participant is named first, so
 * every map of pairings is keyed by the ids in a fixed order, joined by
 * `|`. Two properties make that key safe for any id string:
 *
 * - **The order is total.** Ids are compared as PHP compares two strings
 *   (`<=>`), and two different ids that compare equal there — numeric
 *   strings of the same value, such as `'01'` and `'1'`, or `'1e3'` and
 *   `'1000'` — are then ordered byte by byte. Two different ids therefore
 *   never tie, and a pair gives the same key in either order.
 * - **The encoding has no collisions.** A `|` inside an id is written
 *   `\|` and a `\` is written `\\`, so the separator between ids is the
 *   only unescaped `|` and different id lists never share a key.
 *
 * Both rules leave an id list alone when it needs neither: for ids that
 * contain no `|` and no `\`, and of which no two are numerically equal,
 * the key is byte for byte `sort($ids); implode('|', $ids)`. That
 * includes the order of plain decimal ids: `'9'` sorts before `'10'`.
 *
 * The comparison is PHP's own for two ids, so it is total on every pair.
 * On three or more ids that mix numeric and non-numeric strings PHP's
 * comparison is not transitive (`'2' < '10' < '1a' < '2'`), and `sort()`
 * gave such a list an order that depended on the order the ids were given
 * in: one event named two ways had two keys. order() sorts a longer list
 * from byte order first, so the same ids give the same key however they
 * are listed. For a list PHP's comparison orders consistently, which is
 * every list `sort()` gave one key, the key is still the one `sort()` gave.
 *
 * @internal Not public API: the format of a key carries no compatibility
 *           guarantee, and no key is stored or serialized.
 */
final class PairKey
{
    private const string SEPARATOR = '|';

    private const string ESCAPE = '\\';

    /**
     * The key of the given ids, whatever order they are given in.
     */
    public static function of(string ...$ids): string
    {
        // Nearly every key is a pair, and the searches build one per
        // candidate: order the two directly instead of sorting a list.
        if (count($ids) === 2 && isset($ids[0], $ids[1])) {
            [$first, $second] = $ids;
            if (self::compare($first, $second) > 0) {
                [$first, $second] = [$second, $first];
            }

            $key = $first . self::SEPARATOR . $second;

            // Exactly one separator and no escape character: neither id
            // needs escaping, so this is the key already.
            return substr_count($key, self::SEPARATOR) === 1 && !str_contains($key, self::ESCAPE)
                ? $key
                : self::escape($first) . self::SEPARATOR . self::escape($second);
        }

        return self::join(self::order(array_values($ids)));
    }

    /**
     * The ids in key order.
     *
     * @param array<string> $ids
     * @return list<string>
     */
    public static function order(array $ids): array
    {
        $ordered = array_values($ids);

        // PHP's comparison is not transitive over three or more ids (see
        // the class comment), and a sort by a comparison that is not
        // transitive gives a result that depends on where it starts. Start
        // from byte order, which is total, so that the same ids give the
        // same order however they were listed. Where the comparison is
        // transitive there is one sorted order, and this changes nothing.
        if (count($ordered) > 2) {
            sort($ordered, SORT_STRING);
        }

        usort($ordered, self::compare(...));

        return $ordered;
    }

    /**
     * Join ids that are already in key order.
     *
     * @param array<string> $orderedIds
     */
    public static function join(array $orderedIds): string
    {
        return implode(self::SEPARATOR, array_map(self::escape(...), $orderedIds));
    }

    /**
     * PHP's string comparison, with a byte-order tiebreak for two different
     * ids it calls equal. Zero only for identical ids.
     */
    public static function compare(string $first, string $second): int
    {
        $comparison = $first <=> $second;

        return $comparison !== 0 ? $comparison : strcmp($first, $second) <=> 0;
    }

    private static function escape(string $id): string
    {
        if (strpbrk($id, self::SEPARATOR . self::ESCAPE) === false) {
            return $id;
        }

        return strtr($id, [
            self::ESCAPE => self::ESCAPE . self::ESCAPE,
            self::SEPARATOR => self::ESCAPE . self::SEPARATOR,
        ]);
    }
}
