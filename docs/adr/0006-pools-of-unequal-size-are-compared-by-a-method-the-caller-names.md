# 0006. Pools of unequal size are compared by a method the caller names

Status: accepted

## Context

Two selections compare entrants of different pools: the best few of the
entrants that hold one rank in their pools (`BestOfRankSelector`, "the two
best of those placed third"), and a slice of the combined table of a pooled
outcome (`RankRangeSelector::overall()`).

In pools of one size, played to the end, every compared entrant has the
same number of events and the figures of the combined table can be compared
as they are. In pools of unequal size they cannot: 4 points from 3 events
against 3 points from 2 has no answer that holds for every competition.
Competitions use at least three rules (the totals as they are, the totals
per event, or the totals without the results against the lowest-placed
entrants of the larger pools), and each can select a different entrant.
Until this decision `overall()` compared the totals as they are and did not
say so.

## Decision

**No comparison method is a default. Across pools of unequal size a
selection is refused until the caller names one.** The refusal is an
`InvalidConfigurationException` with the reason `PoolComparisonRequired`
and the pool sizes in its context; accepting an undecided order
(`withTableOrderAccepted()`) is not naming a method and does not lift it.
The method is a `PoolComparison`, given to `comparedBy()` or as the key
`comparison` of the selector's plain data:

- `RawTotals`: the figures as the combined table has them.
- `PerEvent`: every figure divided by the events the entrant has played. A
  tiebreaker value is divided like the others, once, so that the three
  methods agree wherever the compared entrants have played the same number
  of events.
- `DiscardLowest`: in every pool larger than the smallest, the entrants
  placed below the size of the smallest pool are left out with every result
  they are in, and the table is calculated again. For that the outcome
  carries the calculator its table was built with
  (`StageOutcome::getCalculator()`, which `StageOutcome::combining()`
  sets); an outcome without one is refused that method. `overall()` does
  not take it, because the entrants left out would have no place in the
  table that is left.

**Pools are unequal when their tables do not have the same number of
entries.** The number of events played is not the test. An outcome says how
many entries a pool has; it does not say how many events a pool was meant
to play, and a test on events played would refuse every table in which an
entrant sat a round out or an event is still to be played, which is a
question of completeness that the selectors leave to the caller (a rank
selector sees a table and nothing else, as the usage guide says of every
rank selection).

**Who holds a rank is read from the pool's own table, and the holders are
compared by the combined table.** A method that was chosen is applied to
pools of one size too. Where the comparison leaves holders level, the
selection is refused or accepted by the tie policy of the rank selectors,
and an accepted order is the order of the entrant list, as
[0004](0004-position-decides-where-results-do-not.md) requires.

`Stage\CrossPoolRanking` implements the comparison for both selectors and
`Stage\SelectionTies` the tie policy; both are internal.

## Consequences

- `RankRangeSelector::overall()` on pools of unequal size throws where it
  used to select. `comparedBy(PoolComparison::RawTotals)` selects what it
  selected before.
- Pools of one size whose entrants played different numbers of events (a
  pool with events to play, a bye, a withdrawal, pools of different leg
  counts) are compared with no method unless one is chosen. The caller
  checks completeness first, or chooses `PerEvent`.
- `PerEvent` is exact for a tiebreaker that adds something up over the
  entrant's own events (`WinsTiebreaker`). It is not for one that adds up
  the ranking values of opponents (`BuchholzTiebreaker`,
  `SonnebornBergerTiebreaker`): in a larger pool those values were
  themselves collected over more events, so the entrant of the larger pool
  stays ahead after the division. A tiebreaker that is a ratio is divided
  as well. A tiebreaker does not say how its value grows, so the selector
  cannot do better; the caller leaves such a tiebreaker out of the
  calculator that orders the combined table, or uses `DiscardLowest`, which
  calculates every tiebreaker again. Changing this needs the tiebreaker
  contract to say how a value scales, which is a decision of its own.
- The combined table is calculated from results alone. A bye credited in a
  pool's own table decides who holds the rank there and is not among the
  figures the holders are compared by.
- The list a best-of-rank selection returns is ordered best first and is
  not placed for a bracket: appended to a per-group selection it can pair
  two entrants of one pool in the first round. Placing them depends on
  which pools the selected entrants come from, and validating it up front
  needs the number of pools, which a `StageTransition` does not declare.
  [`docs/ROADMAP.md`](../ROADMAP.md) lists it as a known limitation.
- `tests/Feature/BestOfRankSelectionTest.php` holds each method to an
  oracle that adds the records up itself and compares fractions by
  cross-multiplication, over generated pools.
