<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Exceptions;

/**
 * Thrown when a rank selection depends on an order that the results do not
 * decide.
 *
 * A standings table gives every entry a place of its own, also where no
 * result separates two entries. A selection that cuts such a table at a
 * rank, or seeds a next stage from the order inside it, would act on an
 * order that reflects no result. The selector refuses unless it was told
 * to go on (see `RankRangeSelector`), and this exception says what is
 * undecided: which participants are level, which places they hold between
 * them, and in which pool.
 *
 * The reason says which of three cases it is:
 *
 * - {@see InvalidConfigurationReason::UnplayedStage}: the table has no
 *   recorded result at all, so every place in it is undecided;
 * - {@see InvalidConfigurationReason::TieAcrossSelection}: the level
 *   participants hold places on both sides of a boundary of the selection,
 *   so the results do not say who is selected;
 * - {@see InvalidConfigurationReason::TieWithinSelection}: the level
 *   participants are all selected, and only the order they are selected in
 *   is undecided.
 *
 * The order can be one that no single table holds: the holders of one rank
 * in every pool, compared with each other (`BestOfRankSelector`). The
 * exception then says which rank ({@see self::getRankAcrossPools()}), the
 * places are places among those holders, best first, and the pool is null.
 *
 * So the exception says which of three orders the tie is in:
 *
 * - a tied set of one table: the pool's ({@see self::getPool()} names it),
 *   or the outcome's own when the pool is null and neither of the next two
 *   applies;
 * - the holders of one rank across pools
 *   ({@see self::getRankAcrossPools()} is that rank);
 * - the entries of a pooled outcome compared per event played
 *   ({@see self::isPerEvent()}).
 *
 * In the last two the participants are level in a comparison the selector
 * makes, and need not be a tied set of any table. Whichever it is, the
 * application can settle a tie (not an unplayed table) by its own rule and
 * give the selector that refused a decision that orders exactly the
 * participants named here (`withDecisions()` of the selector).
 *
 * It is an {@see InvalidConfigurationException}, so a catch clause written
 * for that class still matches. Its context holds the same values as the
 * accessors, under the keys `participants`, `first_place`, `last_place`,
 * `pool`, `from` and `to`, under `rank_across_pools` when the order is
 * one across pools, and under `per_event` when it is one per event played.
 *
 * @api
 */
final class UndecidedSelectionException extends InvalidConfigurationException
{
    /** @var list<string> */
    private readonly array $participantIds;

    /**
     * @param array<string> $participantIds The IDs of the level participants, in table order
     * @param int $firstPlace The first of the places they hold between them (1-based)
     * @param int $lastPlace The last of those places
     * @param string|null $pool The label of the pool whose table it is; null for the table of
     *                          the whole outcome, and for an order across pools
     * @param int $from The first selected rank
     * @param int $to The last selected rank
     * @param bool $played Whether the table has any recorded result
     * @param int|null $rankAcrossPools The rank whose holders in every pool the places are
     *                                  places among; null when they are places of one table
     * @param bool $perEvent Whether the participants are level on their figures per event
     *                       played, in a table put in that order, and not as a tied set of
     *                       the table as it stands
     */
    public function __construct(
        array $participantIds,
        private readonly int $firstPlace,
        private readonly int $lastPlace,
        private readonly ?string $pool,
        private readonly int $from,
        private readonly int $to,
        bool $played = true,
        private readonly ?int $rankAcrossPools = null,
        private readonly bool $perEvent = false
    ) {
        $this->participantIds = array_values($participantIds);

        $where = match (true) {
            $rankAcrossPools !== null => "the entrants placed {$rankAcrossPools} in their pools",
            $perEvent => 'the standings per event played',
            $pool === null => 'the standings',
            default => "pool {$pool}",
        };
        $who = implode(', ', $this->participantIds);
        $across = $firstPlace < $from || $lastPlace > $to;
        $taken = $rankAcrossPools === null ? 'ranks' : 'places';

        parent::__construct(
            match (true) {
                !$played => "No result is recorded in {$where}, so no place in it is decided"
                    . " (places {$firstPlace} to {$lastPlace}: {$who})",
                $across => "No result separates the participants in places {$firstPlace} to {$lastPlace} of {$where}"
                    . " ({$who}), and {$taken} {$from} to {$to} take only some of them",
                default => "No result separates the participants in places {$firstPlace} to {$lastPlace} of {$where}"
                    . " ({$who}), so the order they are selected in is not decided",
            },
            [
                'participants' => $this->participantIds,
                'first_place' => $firstPlace,
                'last_place' => $lastPlace,
                'pool' => $pool,
                'from' => $from,
                'to' => $to,
                ...($rankAcrossPools === null ? [] : ['rank_across_pools' => $rankAcrossPools]),
                ...($perEvent ? ['per_event' => true] : []),
            ],
            reason: match (true) {
                !$played => InvalidConfigurationReason::UnplayedStage,
                $across => InvalidConfigurationReason::TieAcrossSelection,
                default => InvalidConfigurationReason::TieWithinSelection,
            }
        );
    }

    /**
     * The IDs of the participants no result separates, in table order.
     *
     * @return list<string>
     */
    public function getParticipantIds(): array
    {
        return $this->participantIds;
    }

    /**
     * The first of the places the level participants hold between them
     * (1-based).
     */
    public function getFirstPlace(): int
    {
        return $this->firstPlace;
    }

    /**
     * The last of the places the level participants hold between them.
     */
    public function getLastPlace(): int
    {
        return $this->lastPlace;
    }

    /**
     * The label of the pool whose table holds the tie, or null when it is
     * the table of the whole outcome.
     */
    public function getPool(): ?string
    {
        return $this->pool;
    }

    /**
     * The first rank the selection asked for. For an order across pools,
     * the first place among the compared entrants that it takes, which is
     * 1.
     */
    public function getFrom(): int
    {
        return $this->from;
    }

    /**
     * The last rank the selection asked for. For an order across pools,
     * the last place among the compared entrants that it takes: how many
     * it selects.
     */
    public function getTo(): int
    {
        return $this->to;
    }

    /**
     * The rank whose holders in every pool were compared with each other,
     * when the tie is among them: the places are then places in that
     * comparison, best first, and no pool is named. Null when the tie is
     * in one table.
     */
    public function getRankAcrossPools(): ?int
    {
        return $this->rankAcrossPools;
    }

    /**
     * Whether the participants are level on their figures per event
     * played (`PoolComparison::PerEvent` in an overall selection) and not
     * as a tied set of the outcome's table: that table can place them
     * apart, so a decision for them is one for the selector to take and
     * not one the table accepts. False for a tie in one table and for a
     * tie among the holders of a rank ({@see self::getRankAcrossPools()}).
     */
    public function isPerEvent(): bool
    {
        return $this->perEvent;
    }
}
