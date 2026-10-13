<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Standings\StandingEntry;

/**
 * The order of entries that are not ranked by one table against each
 * other: the holders of one rank in every pool, or every entry of a pooled
 * outcome compared per event played.
 *
 * Entries are compared by the figures a standings table is ordered by, in
 * the same order and all higher first: the ranking value, each tiebreaker
 * value the entries carry, the score difference and scores-for. Per event
 * played, each figure is first divided by the number of events the entry
 * has played (an entry that has played none has 0 for each).
 *
 * The ranking value is the total the table is ordered by
 * (StandingEntry::getRankingValue()): what the entry earned plus its
 * adjustment. Per event played the total is divided, the adjustment with
 * it, so that the comparison per event agrees with the comparison of
 * totals wherever the entries have played the same number of events. An
 * entry that has played no event has nothing to divide by and is compared
 * by its adjustment as it stands, which is 0 for an entry without one.
 *
 * Entries that are equal on every figure are level: no result orders them.
 * They are returned as one group, in the order of the positions given,
 * which is what decides where results do not. The comparison reads nothing
 * else, so the groups are the same for every order the entries are given
 * in.
 *
 * @internal Not public API: how RankRangeSelector and BestOfRankSelector
 *           compare across pools.
 */
final class CrossPoolRanking
{
    /**
     * The entries in order, best first, as groups of level entries: a
     * group of one is an entry that results place.
     *
     * @param array<StandingEntry> $entries The entries to order, in any order
     * @param bool $perEvent Whether each figure is divided by the events the entry has played
     * @param array<string, int> $positions For every entry, by participant ID, its position where
     *                                      results decide nothing: lower first
     * @return list<list<StandingEntry>>
     * @throws InvalidConfigurationException When the entries do not carry the same tiebreakers in
     *                                       the same order, so that their values cannot be
     *                                       compared (reason `IncompatibleOutcome`)
     */
    public static function levelGroups(array $entries, bool $perEvent, array $positions): array
    {
        $names = null;
        $rows = [];
        foreach ($entries as $entry) {
            $carried = array_keys($entry->getTiebreakers());
            $names ??= $carried;
            if ($carried !== $names) {
                throw new InvalidConfigurationException(
                    'The entries compared across pools do not carry the same tiebreakers',
                    ['tiebreakers' => [$names, $carried]],
                    reason: InvalidConfigurationReason::IncompatibleOutcome
                );
            }

            $figures = [
                $entry->getRankingValue(),
                ...array_values($entry->getTiebreakers()),
                $entry->getScoreDifference(),
                $entry->getScoreFor(),
            ];
            if ($perEvent) {
                $played = $entry->getPlayed();
                $figures = array_map(
                    static fn(float $figure): float => $played > 0 ? $figure / $played : 0.0,
                    $figures
                );
                if ($played === 0) {
                    // Nothing was earned and there is nothing to divide by:
                    // the adjustment is all the ranking value there is
                    $figures[0] = $entry->getAdjustment();
                }
            }

            $rows[] = [
                'entry' => $entry,
                'figures' => $figures,
                'position' => $positions[$entry->getParticipant()->getId()] ?? PHP_INT_MAX,
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            $comparison = self::compare($a['figures'], $b['figures']);

            return $comparison !== 0 ? $comparison : $a['position'] <=> $b['position'];
        });

        $groups = [];
        $previous = null;
        foreach ($rows as $row) {
            if ($previous !== null && self::compare($previous, $row['figures']) === 0) {
                $groups[count($groups) - 1][] = $row['entry'];
            } else {
                $groups[] = [$row['entry']];
            }
            $previous = $row['figures'];
        }

        return $groups;
    }

    /**
     * The position of every participant of an outcome where results decide
     * nothing: the order of its entrant list, and after those the order of
     * its table for a participant the list does not have (every one of
     * them, for an outcome without an entrant list).
     *
     * @return array<string, int> By participant ID
     */
    public static function positionsIn(StageOutcome $outcome): array
    {
        $positions = [];
        foreach ($outcome->getEntrants() as $entrant) {
            $positions[$entrant->getId()] ??= count($positions);
        }
        foreach ($outcome->getStandings()->getEntries() as $entry) {
            $positions[$entry->getParticipant()->getId()] ??= count($positions);
        }

        return $positions;
    }

    /**
     * The number of entries of each pool's table, by pool label, in pool
     * order.
     *
     * @return array<int|string, int>
     */
    public static function poolSizes(StageOutcome $outcome): array
    {
        $sizes = [];
        foreach ($outcome->getPools() as $label => $poolOutcome) {
            $sizes[$label] = count($poolOutcome->getStandings());
        }

        return $sizes;
    }

    /**
     * The size of the smallest pool, and whether another pool is larger.
     *
     * @param array<int|string, int> $sizes As poolSizes() gives them
     * @return array{smallest: int, unequal: bool}
     */
    public static function spread(array $sizes): array
    {
        $smallest = null;
        $largest = 0;
        foreach ($sizes as $size) {
            $smallest = $smallest === null ? $size : min($smallest, $size);
            $largest = max($largest, $size);
        }

        return ['smallest' => $smallest ?? 0, 'unequal' => $smallest !== null && $largest !== $smallest];
    }

    /**
     * The refusal of pools of unequal size that no comparison method was
     * chosen for.
     *
     * @param array<int|string, int> $sizes As poolSizes() gives them
     */
    public static function comparisonRequired(array $sizes): InvalidConfigurationException
    {
        $listed = [];
        foreach ($sizes as $label => $size) {
            $listed[] = "{$label}: {$size}";
        }

        return new InvalidConfigurationException(
            'The pools are of unequal size (' . implode(', ', $listed) . '), so their entrants have played'
                . ' different numbers of events: choose how they are compared (comparedBy())',
            ['pool_sizes' => $sizes, 'known' => self::knownComparisons()],
            reason: InvalidConfigurationReason::PoolComparisonRequired
        );
    }

    /**
     * The comparison method a plain-data value names; null when it is
     * null, for none chosen.
     *
     * @param string $selector What the data configures, for the message: 'rank range'
     * @throws InvalidConfigurationException When the value is not a string (reason
     *                                       `WrongValueType`) or not a known method
     *                                       (`UnknownIdentifier`)
     */
    public static function comparisonFrom(mixed $comparison, string $selector): ?PoolComparison
    {
        if ($comparison === null) {
            return null;
        }

        if (!is_string($comparison)) {
            throw new InvalidConfigurationException(
                "The comparison of a {$selector} selector must be a string",
                ['comparison' => $comparison],
                reason: InvalidConfigurationReason::WrongValueType
            );
        }

        return PoolComparison::tryFrom($comparison) ?? throw new InvalidConfigurationException(
            "Unknown {$selector} pool comparison",
            ['comparison' => $comparison, 'known' => self::knownComparisons()],
            reason: InvalidConfigurationReason::UnknownIdentifier
        );
    }

    /**
     * @return list<string>
     */
    private static function knownComparisons(): array
    {
        return array_map(static fn(PoolComparison $comparison): string => $comparison->value, PoolComparison::cases());
    }

    /**
     * Order two lists of figures of one length, higher first: the first
     * figure that differs decides. Zero when none differs.
     *
     * @param list<float> $first
     * @param list<float> $second
     */
    private static function compare(array $first, array $second): int
    {
        foreach ($first as $index => $figure) {
            $comparison = ($second[$index] ?? 0.0) <=> $figure;
            if ($comparison !== 0) {
                return $comparison;
            }
        }

        return 0;
    }
}
