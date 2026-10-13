<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Standings;

/**
 * The comparisons a standings table is ordered by, in one place, so that the
 * calculator, a table re-ordered after byes are credited and the reading of
 * which entries are level cannot disagree.
 *
 * @internal Not public API: how the order of a table is stated is in the
 *           docblock of StandingsCalculator.
 */
final class EntryOrder
{
    /**
     * Order two entries by what their results say, best first: the ranking
     * value, each named tiebreaker in order, the score difference and
     * scores-for, all higher first. Zero when none of them separates the
     * two, which is when the entries are level.
     *
     * @param array<string> $tiebreakerNames The tiebreakers to compare, in the order they apply
     * @param bool $withRankingValue False to leave the ranking value out, for entries whose
     *                               ranking values are already held to be the same
     */
    public static function compareFigures(
        StandingEntry $first,
        StandingEntry $second,
        array $tiebreakerNames,
        bool $withRankingValue = true
    ): int {
        $comparison = $withRankingValue ? $second->getRankingValue() <=> $first->getRankingValue() : 0;
        if ($comparison !== 0) {
            return $comparison;
        }

        foreach ($tiebreakerNames as $name) {
            $comparison = ($second->getTiebreakerValue($name) ?? 0.0) <=> ($first->getTiebreakerValue($name) ?? 0.0);
            if ($comparison !== 0) {
                return $comparison;
            }
        }

        $comparison = $second->getScoreDifference() <=> $first->getScoreDifference();
        if ($comparison !== 0) {
            return $comparison;
        }

        return $second->getScoreFor() <=> $first->getScoreFor();
    }

    /**
     * Order two participant IDs. The order is total and transitive for every
     * set of strings, which PHP's own comparison of two strings is not: it
     * reads numeric strings as numbers, so `'01'` equals `'1'`, and `'2'` is
     * below `'10'`, `'10'` below `'1a'` and `'1a'` below `'2'`.
     *
     * An ID written as a whole number without a sign or a leading zero
     * (`'0'`, `'7'`, `'10'`) comes before every other ID, and two such IDs
     * are in the order of their values, however many digits they have. Every
     * other ID is compared byte by byte. So `'9'` is before `'10'`, `'10'`
     * before `'01'` (not written as a whole number is written), and `'p10'`
     * before `'p2'`. Zero only for identical IDs.
     */
    public static function compareIds(string $first, string $second): int
    {
        $firstIsNumber = self::isWholeNumber($first);
        $secondIsNumber = self::isWholeNumber($second);

        if ($firstIsNumber !== $secondIsNumber) {
            return $firstIsNumber ? -1 : 1;
        }

        if ($firstIsNumber && strlen($first) !== strlen($second)) {
            return strlen($first) <=> strlen($second);
        }

        return strcmp($first, $second) <=> 0;
    }

    private static function isWholeNumber(string $id): bool
    {
        return $id === '0' || ($id !== '' && $id[0] !== '0' && ctype_digit($id));
    }
}
