<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Standings;

/**
 * A tiebreaker computed between the tied participants only: its value for a
 * participant comes from the results of that participant against the others
 * it is level with, and from no other result. A sport calls it head-to-head.
 *
 * The interface adds no method. It tells the standings calculator what kind
 * of tiebreaker this is, and the calculator does the part that depends on
 * the table, so the tiebreaker needs no code for it:
 *
 * - Who is tied. When the tiebreaker's turn in the chain comes, the
 *   participants it is asked about are level in groups: those with the same
 *   ranking value (the total, with byes and adjustments) and the same value
 *   for every tiebreaker before this one. Each such group is a tied set. A
 *   participant level with nobody is a set of one.
 * - Which results. For a participant the tiebreaker is handed the results
 *   of the events whose participants are all in its tied set, and no other.
 *   With AwardedResultPolicy::$countBetweenTiedParticipants off, the
 *   awarded ones are left out of those.
 * - Which entries. The entries it is handed are those of the tied set
 *   alone, keyed by participant ID: the table the calculator's ranking
 *   strategy and awarded result policy give for those participants from
 *   those results. So an entry's ranking value, record and scores are what
 *   the participant took from the others of its set. No bye, adjustment or
 *   tiebreaker value is on them.
 *
 * So the value is recomputed for every tied set, and it depends on nothing
 * but the set: not on the order of the participants or the results, and not
 * on a participant outside it. The calculator asks once per place in the
 * chain. Where the values split a set of four into one, two and one, the
 * two that are left are the tied set of the next tiebreaker in the chain,
 * and this one is not asked about them again. Where a competition applies
 * its measure again to those who are still level, give the chain two
 * tiebreakers of this kind with two names: a calculator refuses a chain
 * that holds one of this kind and has one name twice
 * (StandingsCalculator::__construct()), and BetweenTiedParticipants names
 * itself after its measure, so the second is a class of your own.
 *
 * BetweenTiedParticipants turns any tiebreaker that reads entries into one
 * of this kind. Implement the interface on a tiebreaker of your own to have
 * it handed the same.
 *
 * A table with a tiebreaker of this kind has to be calculated in one go:
 * who is tied depends on the byes and the adjustments, so the calculator
 * refuses to apply them to a table after the fact
 * (StandingsCalculator::withByesCredited(), withAdjustments()) and takes
 * them up front (calculateWith()).
 *
 * @experimental
 */
interface TiedParticipantsTiebreaker extends TiebreakerInterface {}
