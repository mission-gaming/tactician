# 0008. A decision orders level entries and never a result

Status: accepted. Amends the last consequence of
[0004](0004-position-decides-where-results-do-not.md).

## Context

When every tiebreaker is level, the order of a table has to come from
outside the results. Competitions settle it by a rule of their own: a
further event, a drawing of lots, a ruling. Record 0004 left that to the
application entirely: the table reports the entries that no result
separates (`Standings::getTiedSets()`), a rank selector refuses to act on
their order (`UndecidedSelectionException`), and an application that
decided the order applied it to the entrant list it passed to the next
stage.

That left three gaps. The table an application shows could not carry the
order it had decided, so the table and the list passed on disagreed. A rank
selection could go on only by accepting the order of the entrant list,
which is not the order that was decided. And nothing recorded that a place
was settled outside the results, which is what an application has to show
and audit.

`DTO\Decider` already records a decision made outside the results for a
knockout tie: an ordered list of participant IDs with an optional label and
an optional reason that the library never reads.

## Decision

**The application supplies the order of a set of level entries as a
decision, and the library applies it and marks what it settled.** A
decision is a `DTO\Decider`: the IDs of the level participants, best first.
The library records that the order was decided and never how; the label and
the reason are the application's words.

**A decision orders one whole set of level entries and nothing else.** In a
table that set is a tied set: entries of one tier that are equal on the
ranking value, every tiebreaker, the score difference and scores-for. The
set keeps the places it spans and every other entry stays where it is, so a
decision cannot move an entry past one it is not level with and cannot
cross a tier: it never overrides a result. A decision that is anything else
is rejected whole with a `RejectedDecisionException`, whose
`RejectedDecisionReason` says which rule it broke: a participant with no
entry, a set decided twice, participants that are not level, or part of a
set.

**The whole set, also where only part of it matters.** With four entrants
level and two to go through, a decision that ordered only the two would be
enough for that selection and would leave the table with an order that
depends on what was asked of it. A decision therefore names every member of
the set. One table then has one order, the same decision serves every
selection and every display, and "part of a set" is always a mistake and
never a shorthand.

**Where decisions are applied.** `Standings::withDecisions()` applies them
to a table, which then carries them (`getDecisions()`, `getDecisionFor()`,
`TiedSet::getDecision()`). It is on the table and not on the calculator
because a decision needs nothing the calculator knows: which entries are
level is read from the table, as `getTiedSets()` reads it.
`RankRangeSelector::withDecisions()` and
`BestOfRankSelector::withDecisions()` apply them to the orders a selection
reads, before a rank is read, and `selection()` returns the selected
participants with the decision that settled each place (`RankSelection`).
`select()` returns the list as before.

**A selection across pools takes decisions for the order it compares.**
The holders of one rank in every pool, and the entries of a pooled outcome
compared per event played, can be level in a comparison that no table
holds: the combined table may have other entrants level with the holders,
and per event played it may place level entrants apart. A decision there
orders one whole group of entrants that the comparison leaves level, under
the same rules, and is given to the selector. `UndecidedSelectionException`
says which of the three orders a tie is in (`getPool()`,
`getRankAcrossPools()`, `isPerEvent()`); in each, a decision for exactly
the participants it names, given to the selector that refused, settles it.

**A decision is applied last, and a stale one is refused.** A decision is
about the entries that are level once adjustments and bye credits are
counted. `StandingsCalculator::withAdjustments()` and `withByesCredited()`
keep the decisions of a table where the change leaves every decided set as
it was, and refuse the table where it does not (reason `Stale`). Dropping
the decision would lose a recorded fact without a word, and keeping it
would show an order as decided for a set it was not made for. A decision
given again after a later result separated its participants is rejected as
not level.

**A stage state does not hold decisions, and no engine reads one.** The
engines act on an order that results leave open in two places: the pairing
of a Swiss round and the fold of a re-seeded bracket round. Both are
decided by position in the entrant list (0004), and a decision about the
final order of a table is not a statement about who should meet next.
Putting decisions in the state would also make them part of what a
re-seeded bracket replays, with the limit that adjustments have there
(they belong to no round). So a decision is supplied to a table and to a
selector, and the application keeps it beside its results as plain data.

**An unplayed table stays refused.** A decision settles a tie. A table with
no result has nothing level about it that play could not still decide, and
a selector refuses it whatever is decided, unless it was told to accept the
table as it stands.

## Consequences

- The last consequence of 0004 changes: an application that wants a
  different final order gives it to the table or to the selector as a
  decision, and no longer has to rebuild the entrant list of the next
  stage by hand. Everything else in 0004 stands. Position still decides
  wherever nothing else has: a decided set is the one thing
  `withTiedSetsOrderedBy()` leaves alone.
- With no decision supplied, every table and every selection is what it
  was.
- A decided set is still a tied set: `getTiedSets()` reports it, with its
  decision. Code that reads an empty list as "results decide every
  position" is still right; code that reads a non-empty list as "something
  is undecided" now checks `TiedSet::getDecision()`.
- The order of a table with decisions is total and does not depend on the
  order its results, participants or decisions were supplied in. A set
  takes one decision; a second one, and one that overlaps another, is
  refused.
- `RejectedDecisionException` is rejected input (it extends
  `\InvalidArgumentException`, as `InvalidInputException` does) and not an
  `InvalidConfigurationException`: a table without decisions puts no
  checked exception on the code that builds one, and a catch clause for
  configuration errors does not swallow a wrong decision.
- A rank selector's plain data has one more optional key, `decisions`, in
  version 1 of its form ([0005](0005-serialized-forms-carry-a-format-version.md)).
  A selector that is stored as configuration is given its decisions when
  it selects.
- Shared places are not expressed: a decision is a strict order of the set.
  A table that leaves two entries on one rank is a different table shape
  and a decision of its own.
- The combined table of `StageOutcome::combining()` is calculated afresh
  and carries no decision of the pools' tables: their tied sets are not its
  tied sets.
- `tests/Feature/TableDecisionsTest.php` holds a table, a per-group
  selection and every reason for a rejection to an oracle that works on
  plain rows, over generated tables with tiers and several tied sets, and
  the selections across pools to stated cases.
