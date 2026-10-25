# 0016. Qualifiers for a bracket keep their tiers

Status: accepted

## Context

A common format takes the top few of every pool and the best few of the
next rank across the pools, and hands them to a knockout: the top two of
three pools and the two best of those placed third. `RankRangeSelector`
orders a per-group selection for a bracket, and `BestOfRankSelector` lists
the best of a rank best first; one list after the other can pair two
entrants of one pool in the first round, because where the best of the next
rank have to go depends on which pools they come from. A competition draws
such a bracket from a table of the possible combinations; the library has
to give the order itself, for any number of pools, ranks and best, and say
before any pool is played when no order can keep a pool's entrants apart.

Three things could move to keep them apart: the order of the pools inside
a rank, the order of the best of the next rank, and the ranks themselves (a
runner-up seeded above a pool winner).

## Decision

**The ranks stay in tiers, and the best of the next rank stay in their
order.** `BracketQualifiersSelector` lists every pool's first selected
rank, then every pool's second, and so on, then the best of the next rank,
best first, at the last positions. A pool winner always has a better seed
than a runner-up, and of the best of the next rank the better has the
better seed. Only the order of the pools inside each rank of the per-group
selection follows from which pools the best of the next rank come from;
that order is the pool order of a draw, which no result decides.

The order inside the ranks is the one `CrossPoolOrder` gives with the best
of the next rank placed first: rank after rank, the order in which
entrants of one pool can first meet as late as possible, and of orders
equal in that, the one nearest to pool order (of several equally near, the
one the assignment returns, which depends on the same numbers alone).
Without a tail that is the
order `RankRangeSelector` has always given, so a per-group selection is
unchanged.

Nothing is lost by keeping the best of the next rank in order. Renaming the
pools turns an order that keeps the pools apart into another that does, and
every rank of the per-group selection holds every pool before and after. So
where an order keeps the pools apart with the best of the next rank in some
order, renaming their pools into each other's gives one that keeps them
apart with the best of the next rank best first.

**One pool is the one case refused.** From two pools up an order with no
first-round tie of one pool always exists, and the rank-by-rank assignment
finds it: a position has at most one first-round opponent placed before
it, so at most one pool it must avoid, and a rank fails only where every
one of its positions must avoid the same pool, which needs opponents of
that pool in as many different ranks (the best of the next rank counting
as one). The opponents of a rank's positions
are consecutive positions: they reach into at most two ranks when a pool
count of three or more makes a rank three positions or more, and into one
rank, aligned, when it is two. With one pool every qualifier is of that pool. The
selector refuses such an outcome (`PoolMatesInFirstRound`), and its yield
states a minimum of 2 pools, and of as many as the best of the next rank
come from (`SelectionYield::getMinimumPools()`), which `CompositionValidator`
checks against the pools a `StageTransition` declares (`sourcePools`).

## Consequences

- A runner-up is never seeded above a pool winner to keep the pools apart,
  and it never has to be.
- The order of the pool winners changes with the pools the best of the
  next rank come from: the first seed is not always the winner of the
  first pool. The list is a function of the numbers
  of pools and ranks and of the pools of the best of the next rank, in
  their order.
- Later rounds are kept apart as far as the rank-by-rank assignment does:
  an earlier rank is settled for good and not revisited for a later one,
  so the whole list does not always have the fewest pairs of one pool that
  can meet in the second round or later (two pools, their top two and the
  best third-placed from pool A: two such pairs where one order has one).
- Who is selected stays with the two selectors, each with its tie policy,
  comparison method and decisions; the placement settles nothing by
  decision and marks nothing. Where the two order a pool's level entries
  differently, so that both select one entrant, the selector refuses
  (`IncompatibleOptions`) rather than hand a bracket that entrant twice.
- The argument is checked by `tests/Feature/BracketQualifiersTest.php`,
  over generated pool stages and over every set and order of the pools the
  best of the next rank can come from, with the first round paired by
  `SingleEliminationEngine`.
