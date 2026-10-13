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
  contract to say how a value scales, which is a decision of its own. It
  was not taken with the tiebreakers 0.3.0 added: a score difference and
  scores-for add up over the entrant's own events, as wins do, and are
  exact under `PerEvent`; a tiebreaker between the tied participants
  (`BetweenTiedParticipants`) adds up over the events inside a tied set
  of the combined table, in which entrants of different pools have not
  met; it says little across pools under any method, and the caller
  leaves it out of the calculator that orders the combined table, as with
  the two above.
- The combined table credits no bye. A bye credited in a pool's own table
  decides who holds the rank there and is not among the figures the holders
  are compared by. That holds whatever the ranking strategy says a bye is
  worth ([0009](0009-a-bye-is-worth-what-the-ranking-strategy-says.md)):
  the entries of the combined table have no bye, so a tiebreaker that
  reads byes finds none there. Crediting them would need `PerEvent` to say
  whether a bye is an event played, which is not decided.
- An adjustment (`Standings\Adjustment`) is among them, under every method:
  the ranking value compared is the total a table is ordered by, what was
  earned plus the adjustment, which `StageOutcome::combining()` carries
  over from the pools' tables. `PerEvent` divides that total, the
  adjustment with what was earned. An adjustment is not earned per event,
  and dividing it is a choice: it keeps the adjustment at the weight it has
  in a table (three points taken away weigh what three points not won
  weigh) and keeps the three methods in agreement wherever the compared
  entrants have played the same number of events, which adding it whole to
  a figure per event would not. An entrant that has played no event has
  nothing to divide by and is compared by its adjustment as it stands.
  `DiscardLowest` applies the adjustment of every entrant that is kept to
  the table it calculates again, whole: an adjustment comes from no event,
  so no result that is left out takes any of it away.
- An awarded result is a result under every method: it is one of the
  events played that `PerEvent` divides by, and `DiscardLowest` keeps it or
  leaves it out with the other results of the two entrants.
- The list a best-of-rank selection returns is ordered best first and is
  not placed for a bracket: appended to a per-group selection it can pair
  two entrants of one pool in the first round. Placing them depends on
  which pools the selected entrants come from, and validating it up front
  needs the number of pools, which a `StageTransition` does not declare.
  [`docs/ROADMAP.md`](../ROADMAP.md) lists it as a known limitation.
- `tests/Feature/BestOfRankSelectionTest.php` holds each method to an
  oracle that adds the records up itself and compares fractions by
  cross-multiplication, over generated pools.
