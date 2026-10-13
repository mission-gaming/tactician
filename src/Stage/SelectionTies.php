<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Exceptions\UndecidedSelectionException;
use MissionGaming\Tactician\Standings\StandingEntry;
use MissionGaming\Tactician\Standings\Standings;

/**
 * What the rank selectors do with an order that results do not decide, in
 * one place, so that a selection from one table and a selection across
 * pools refuse and accept the same things.
 *
 * A policy is one of three identifiers, which are the values of the `ties`
 * key of a selector's plain data: refuse every undecided order (the
 * default), accept level entries that are all selected, or accept the
 * order as it stands.
 *
 * @internal Not public API: the contract is stated on RankRangeSelector and
 *           BestOfRankSelector.
 */
final class SelectionTies
{
    public const string REFUSED = 'refuse';
    public const string INSIDE_ACCEPTED = 'accept-inside-selection';
    public const string ORDER_ACCEPTED = 'accept-table-order';

    public const array POLICIES = [self::REFUSED, self::INSIDE_ACCEPTED, self::ORDER_ACCEPTED];

    /**
     * The policy a plain-data value names; the default when it is null.
     *
     * @param string $selector What the data configures, for the message: 'rank range'
     * @return 'refuse'|'accept-inside-selection'|'accept-table-order'
     * @throws InvalidConfigurationException When the value is not a known policy (reason
     *                                       `UnknownIdentifier`)
     */
    public static function policyFrom(mixed $ties, string $selector): string
    {
        $ties ??= self::REFUSED;

        return match ($ties) {
            self::REFUSED => self::REFUSED,
            self::INSIDE_ACCEPTED => self::INSIDE_ACCEPTED,
            self::ORDER_ACCEPTED => self::ORDER_ACCEPTED,
            default => throw new InvalidConfigurationException(
                "Unknown {$selector} tie policy",
                ['ties' => $ties, 'known' => self::POLICIES],
                reason: InvalidConfigurationReason::UnknownIdentifier
            ),
        };
    }

    /**
     * Refuse a table whose order at the ranks from..to results do not
     * decide, unless the policy accepts it.
     *
     * @param bool $played Whether the table has any recorded result
     * @param string|null $pool The label of the pool whose table it is; null for the table of
     *                          the whole outcome
     * @throws UndecidedSelectionException When the table is unplayed, or a tied set holds one of
     *                                     the ranks in a way the policy does not accept
     */
    public static function requireTableDecided(
        Standings $standings,
        bool $played,
        ?string $pool,
        int $from,
        int $to,
        string $ties
    ): void {
        if ($ties === self::ORDER_ACCEPTED) {
            return;
        }

        if (!$played && count($standings) > 1) {
            throw new UndecidedSelectionException(
                array_map(
                    static fn(StandingEntry $entry): string => $entry->getParticipant()->getId(),
                    $standings->getEntries()
                ),
                1,
                count($standings),
                $pool,
                $from,
                $to,
                false
            );
        }

        foreach ($standings->getTiedSets() as $tiedSet) {
            $first = $tiedSet->getFirstPosition();
            $last = $tiedSet->getLastPosition();

            if ($last < $from || $first > $to) {
                continue;
            }

            $inside = $first >= $from && $last <= $to;
            if ($inside && $ties === self::INSIDE_ACCEPTED) {
                continue;
            }

            throw new UndecidedSelectionException(
                array_map(
                    static fn(Participant $participant): string => $participant->getId(),
                    $tiedSet->getParticipants()
                ),
                $first,
                $last,
                $pool,
                $from,
                $to
            );
        }
    }

    /**
     * Refuse an order across pools in which a group of level entries holds
     * one of the places from..to, unless the policy accepts it.
     *
     * @param list<list<StandingEntry>> $groups The order as CrossPoolRanking::levelGroups() gives
     *                                          it: every group holds as many consecutive places
     *                                          as it has entries
     * @param int|null $rank The rank whose holders in each pool the order is of; null when it is
     *                       an order of every entry of the outcome's table
     * @throws UndecidedSelectionException When a group of two or more holds one of the places in
     *                                     a way the policy does not accept
     */
    public static function requireOrderDecided(array $groups, int $from, int $to, string $ties, ?int $rank): void
    {
        if ($ties === self::ORDER_ACCEPTED) {
            return;
        }

        $first = 1;
        foreach ($groups as $group) {
            $last = $first + count($group) - 1;

            if (count($group) > 1 && $last >= $from && $first <= $to) {
                $inside = $first >= $from && $last <= $to;
                if (!$inside || $ties !== self::INSIDE_ACCEPTED) {
                    throw new UndecidedSelectionException(
                        array_map(
                            static fn(StandingEntry $entry): string => $entry->getParticipant()->getId(),
                            $group
                        ),
                        $first,
                        $last,
                        null,
                        $from,
                        $to,
                        true,
                        $rank
                    );
                }
            }

            $first = $last + 1;
        }
    }
}
