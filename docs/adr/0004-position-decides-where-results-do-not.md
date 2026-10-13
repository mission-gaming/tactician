# 0004. Position decides where results do not

Status: accepted. Supersedes [0002](0002-standings-order-is-total.md).

## Context

Standings drive decisions, not only display: Swiss pairing, bye selection,
re-seeded brackets, the table of a stage's outcome and the rank-based
progression selectors all read an order of participants. Two participants
can be level on everything results say, most often before any result
exists.

Record 0002 settled such a pair inside `StandingsCalculator`, by a fallback
that ran seed attribute, label, participant ID. That made every table
reproducible, and it let three things that are not results decide who is
paired with whom and who advances:

- A label. A list entered as Zed, Amy, Bob, Cat was paired Amy with Bob in
  the first Swiss round, because of how the participants are called.
- A seed attribute. Every stage that places entrants (bracket fold, pool
  distribution, pot membership) reads list position and never the seed
  attribute, so the same list meant one order to those stages and another
  to a Swiss stage, a re-seeded round and a rank selector.
- The spelling of an ID. The last step compared IDs as PHP compares two
  strings, which reads numeric strings as numbers. `'01'` and `'1'` were
  not ordered at all, and for IDs that mix numeric and other strings the
  comparison is not transitive (`'2'` is below `'10'`, `'10'` below `'1a'`
  and `'1a'` below `'2'`), so a table depended on the order of the array it
  was given: the one thing the fallback existed to rule out.

An entrant list is itself an order, and the caller chose it.

## Decision

**Wherever an entrant list exists and results do not decide an order, the
position in that list decides it. A label, a seed attribute or an ID never
does.** That covers:

- the order a Swiss round is paired from, and with it who has the bye
  (`SwissPairingEngine`);
- the order the survivors of a re-seeded elimination round are folded in
  (`SingleEliminationEngine`);
- the table an engine builds for a stage, which is the table of its
  outcome;
- what a rank selector returns when it is told to accept an order that
  results do not decide (`RankRangeSelector`).

The entrant list of an engine is the participant list of its `StageState`:
the active participants in the order the stage was started with, then those
who have withdrawn, in the order of their IDs (the state keeps no position
for them, and the order its recorded events name them in is not one).
`StageOutcome` carries the list
(`StageOutcome::getEntrants()`), so that a selector reads the same order the
table was put in. One method applies the rule, to any table:
`Standings::withTiedSetsOrderedBy()` reorders the entries of each tied set
by an entrant list and moves nothing that results placed.

**A table calculated on its own keeps a fixed fallback.**
`StandingsCalculator::calculate()` is given participants in any order and
no entrant list, and its result must not depend on the order of that array.
After the ranking value, the tiebreakers, score difference and scores-for
it compares:

1. seed, lower first, with unseeded participants after seeded ones;
2. participant ID, in an order that is total and transitive for every set
   of strings: an ID written as a whole number without a sign or a leading
   zero comes first, in the order of the values; every other ID follows,
   byte by byte.

The label is not compared. Everything that has an entrant list replaces
this fallback with the list.

**An order that results do not decide is not acted on silently.**
`Standings::getTiedSets()` reports it, and a `RankRangeSelector` refuses a
selection that depends on it unless the caller chose, on the selector, to
accept it.

Two consequences of "the table of a stage" belong to the same decision:

- The table of a Swiss stage counts a bye as it counts for pairing, as a
  win, so that the outcome's table is the table the rounds were paired
  from. (What a bye is worth has since become the ranking strategy's to
  say, with a win as the default:
  [0007](0007-a-bye-is-worth-what-the-ranking-strategy-says.md). That the
  two are one table is unchanged.)
- The table of an elimination stage is the bracket placement. The round an
  entrant went out in is a tier of the table that no record crosses; the
  calculator orders the entrants of one tier from their records, and
  position decides where that leaves them level.

## Consequences

- Relabelling participants, or changing their seed attributes, changes no
  pairing, no table of an engine and no selection. The property tests hold
  every engine and selector to that
  (`tests/Feature/PositionDecidesTest.php`).
- A caller who wants seeds to decide puts the list in seed order. The seed
  attribute is still read by `SeedProtectionConstraint` and by the
  fallback of a standalone table.
- The same results and the same list always give the same table, so
  everything derived from it is reproducible, as under 0002.
- A standalone table is the same for every order of its participants
  array, including the IDs for which 0002 recorded an exception.
- A withdrawn participant is no longer in the list at its old position: in
  the table of the stage it stands after the active participants it is
  level with, and two withdrawn participants who are level are in the
  order of their IDs.
- An outcome built by hand has no entrant list unless it is given one, and
  its table is then in the calculator's fallback order.
- The order is part of the output. The pairings of a stage in progress can
  differ from those an earlier release would have made, which is why the
  engine fingerprint scheme changed with this decision (`tactician:v2:`).
- An application that wants a different final order (a play-off, a drawing
  of lots) applies it to the entrant list it passes to the next stage, as
  before.
