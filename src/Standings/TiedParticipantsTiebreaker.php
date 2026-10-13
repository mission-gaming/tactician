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
 * - Who is tied. The participants are level in groups: those with the same
 *   ranking value (the total, with byes and adjustments) and the same value
 *   for every tiebreaker before the run this one is in. Each such group is
 *   a tied set. A participant level with nobody is a set of one.
 * - What a run is. Tiebreakers of this kind that follow one another in the
 *   chain are a run, and every one of a run is asked about the same tied
 *   sets: those level when the first of the run was asked. Together they
 *   are one small table of the events among the level participants (its
 *   ranking value, then its score difference, then its scores-for, say),
 *   and the second does not lose the events against a participant the
 *   first placed apart. A tiebreaker of another kind ends the run; one of
 *   this kind after it starts a new run, with the sets level at that
 *   point.
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
 * chain, and a run is not taken again over a subset it leaves level: where
 * the values of a run split a set of four into one, two and one, the two
 * that are left go on to the next tiebreaker of another kind with the
 * values they have over the four. Where a competition takes its measures
 * again over those who are still level, give the chain a second run after
 * a tiebreaker of another kind, of tiebreakers of this kind with names of
 * their own: a calculator refuses a chain that holds one of this kind and
 * has one name twice (StandingsCalculator::__construct()), and
 * BetweenTiedParticipants names itself after its measure, so those of the
 * second run are classes of your own.
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
