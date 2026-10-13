<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Standings;

/**
 * A tiebreaker that reads byes, and says how it treats them.
 *
 * The interface is optional, and it changes what the standings calculator
 * hands the tiebreaker. Every other tiebreaker is handed the entries of the
 * results alone, in which no bye is counted. A tiebreaker of this kind is
 * handed the entries with the byes of the table on them: for every
 * participant StandingEntry::getByes() is the number of its byes,
 * getEarnedRankingValue() includes their credit, and
 * getRankingValueOfResults() is the value without it. No entry it is
 * handed carries an adjustment or a tiebreaker value.
 *
 * getByeTreatment() says which of those figures the tiebreaker reads. The
 * calculator needs to know for two reasons. A treatment other than
 * ByeTreatment::Uncounted makes the value depend on the byes, so the
 * calculator refuses to credit byes to a table after the fact
 * (StandingsCalculator::withByesCredited()) and asks for them up front
 * (calculateWith()). And an engine that pairs from the table states the
 * treatment in its fingerprint, because it changes the pairing order.
 *
 * Under ByeTreatment::Uncounted a tiebreaker reads no bye, and its value is
 * the same whether the entries it is handed carry the byes or not: where
 * byes are credited to a table that exists, it is not asked again. In a
 * table without byes no treatment changes anything.
 *
 * @experimental
 */
interface TiebreakerWithByeTreatment extends TiebreakerInterface
{
    /**
     * How this tiebreaker treats a bye. The same on every call.
     */
    public function getByeTreatment(): ByeTreatment;
}
