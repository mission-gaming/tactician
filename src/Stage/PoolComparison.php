<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

/**
 * How entrants of different pools are compared when the pools are not of
 * one size.
 *
 * In pools of unequal size the entrants have played different numbers of
 * events, and what is compared changes who is selected: 4 points from 3
 * events against 3 points from 2. No method is right for every
 * competition, so the caller chooses one and none is applied by default: a
 * selection across pools of unequal size with no method chosen is refused
 * (reason `PoolComparisonRequired`).
 *
 * Whatever the method, the figures compared are the ones a standings table
 * is ordered by, in the same order: the ranking value, each tiebreaker of
 * the calculator in turn, the score difference and scores-for, all higher
 * first. Where every compared entrant has played the same number of events
 * the three methods give the same order.
 *
 * The backing strings are the identifiers of the plain-data forms of the
 * selectors (`comparison`).
 *
 * @experimental
 */
enum PoolComparison: string
{
    /**
     * The figures as the table has them. An entrant of a larger pool has
     * had more events to collect them in.
     */
    case RawTotals = 'raw-totals';

    /**
     * Every figure divided by the number of events the entrant has played
     * (StandingEntry::getPlayed()): the ranking value, each tiebreaker
     * value, the score difference and scores-for. An entrant that has
     * played no event has 0 for each.
     *
     * A tiebreaker value is divided once, by the entrant's own events,
     * whatever it measures. That puts a value that adds up over the
     * entrant's events on one footing (WinsTiebreaker). It does not do so
     * for a value that adds up the ranking values of opponents
     * (BuchholzTiebreaker, SonnebornBergerTiebreaker): those have been
     * collected over more events in a larger pool, so the entrant of the
     * larger pool stays ahead after the division. A value that is already
     * a ratio is divided as well. Leave such a tiebreaker out of the
     * calculator that orders the combined table, or use DiscardLowest,
     * which calculates every tiebreaker again.
     */
    case PerEvent = 'per-event';

    /**
     * In every pool larger than the smallest, the entrants placed below
     * the size of the smallest pool are left out with every result they
     * are in, and the table is calculated again from what is left: with
     * pools of 4, 4 and 3, the results against the entrant placed fourth
     * in each pool of 4 are dropped. Raw totals of that table are then
     * compared, so every compared entrant of a complete round robin has
     * the same number of events.
     */
    case DiscardLowest = 'discard-lowest';
}
