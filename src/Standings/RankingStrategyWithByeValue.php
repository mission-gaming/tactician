<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Standings;

/**
 * A ranking strategy that says what a bye is worth.
 *
 * A bye is a round a participant sat out. It is no result, so rank() is
 * never asked about it. A stage that has byes (a Swiss stage in a field of
 * odd size, a re-seeded bracket whose field is not a power of two) credits
 * each one with a ranking value, and that value is the competition's
 * choice: what a win is worth, what a draw is worth, nothing, or any other
 * amount. A strategy that implements this interface states it.
 *
 * The standings calculator adds the value once for every bye it is given
 * (StandingsCalculator::calculateWith(), withByesCredited()), and
 * StandingEntry::getByes() shows the number beside the record. The engines
 * hand the calculator the byes recorded on the stage state and read one
 * table for the order a round is paired from and for the outcome, so the
 * value of a bye is the same in both.
 *
 * The interface is optional. A RankingStrategy that does not implement it
 * keeps working wherever no bye is credited; where one is, the calculator
 * refuses the table, because nothing says what a bye is worth on that
 * scale (reason `IncompatibleOptions`). WinDrawLossRanking implements it,
 * with the value of a win as its default.
 *
 * @experimental
 */
interface RankingStrategyWithByeValue extends RankingStrategy
{
    /**
     * The ranking value one bye adds for the participant who had it, on the
     * scale of rank(); higher is better, and zero credits nothing.
     *
     * The value must be a finite number, and the same on every call: the
     * order a round is paired from and the table of the outcome are
     * calculated at different times and must agree.
     */
    public function getByeValue(): float;
}
