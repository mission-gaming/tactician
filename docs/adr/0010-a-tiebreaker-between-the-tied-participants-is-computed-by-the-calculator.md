# 0010. A tiebreaker between the tied participants is computed by the calculator, once for each place in the chain

Status: accepted

## Context

Many competitions separate level participants by the events they played
against each other before any figure of the whole table (a sport calls it
head-to-head). The library had no such tiebreaker. It had a marker
interface for one, `Standings\TiedParticipantsTiebreaker`, added with
awarded results so that one choice of a competition could be applied to
that kind: whether an awarded result counts in it. A tiebreaker that
implemented the marker was handed every result and the entries of the whole
table, and had to work out for itself who was tied.

That is where such a tiebreaker goes wrong. Who is tied depends on its place
in the chain (the participants level on the ranking value and on everything
before it), on the byes and the adjustments in the ranking value, and, once
a measure has split a set of four into one, two and one, on whether the
measure is taken again over the two. A tiebreaker that sees one participant
at a time cannot know any of it, and one that guesses depends on the order
of its input.

## Decision

**The calculator forms the tied sets and hands the tiebreaker one set at a
time.** `StandingsCalculator` asks the tiebreakers in the order they were
given. When the turn of a `TiedParticipantsTiebreaker` comes, the
participants are level in groups: the same ranking value (the total, with
byes and adjustments) and the same value for every tiebreaker before it. For
each group the tiebreaker is asked about each participant with the results
of the events whose participants are all in the group, and with the entries
the calculator's ranking strategy and awarded result policy give for the
group from those results alone. The marker keeps its name and adds no
method; what it promises the implementer changed.

**The measure is any tiebreaker.** `Standings\BetweenTiedParticipants`
wraps one and passes on what it is handed, so the wins, the score
difference, the scores-for and the ranking value
(`RankingValueTiebreaker`) of the events between the tied participants are
the same four classes that measure a whole table. Its name is
`between-tied:` and the name of the measure.

**A tiebreaker is asked once for each place it has in the chain.** Where
its values split a tied set into subsets, a subset that is still level is
the tied set of the next tiebreaker. The same tiebreaker is not asked again
about it. Competitions differ on this point: some apply their measures
again to the participants still level, some do not, and the ones that do
differ on where they start again. One pass in chain order is the rule that
needs no further choice, and a competition that wants a measure taken again
gives it a further place in the chain.

**The table is calculated in one call when it has such a tiebreaker.** Who
is tied depends on the byes and the adjustments, so
`StandingsCalculator::calculateWith()` takes them with the results
([0009](0009-a-bye-is-worth-what-the-ranking-strategy-says.md)), and
`withByesCredited()` and `withAdjustments()` refuse to change a table of a
calculator that has one.

## Consequences

- The value depends on the tied set and on nothing else: it is the same for
  every order of the participants and of the results. The order of a table
  stays total, with the fallback it had
  ([0004](0004-position-decides-where-results-do-not.md)).
- A tiebreaker of an application that implemented the marker as it was
  first written (reading the whole table) is handed less than it was: the
  results and the entries of a tied set. The marker was added in the same
  unreleased version, so no release shipped the earlier contract.
- Several tiebreakers of this kind in a row are not one small table of the
  participants level on the ranking value. The second is computed over the
  participants the first left level, so where the first separates some of a
  set of three or more, the second does not read the events against the
  ones it separated. Many written rules do: they rank everyone level on
  points by the points, then the score difference, then the scores-for of
  the events among all of them, and only then look elsewhere. Three
  participants on 4 points, A 0-0 B, A 5-0 C and B 1-0 C: one small table
  puts A above B on score difference (+5 against +1); here A and B are
  level on the points they took from each other, the score difference
  between the two of them is 0 for both, and the next tiebreaker of the
  chain decides. The two rules agree for a set of two, and for a set the
  first measure leaves whole or separates completely. Giving a run of such
  tiebreakers one tied set (the participants level when the first of the
  run is asked) would express the small table; it was not chosen here, and
  `docs/ROADMAP.md` lists the difference as a known limitation.
- A participant level with nobody is a tied set of one, and gets the value
  its measure gives for no result. The value is shown on the entry and
  orders nothing.
- Two participants who have not met have no event between them and stay
  level on every measure.
- The tied sets are read from the whole table, without regard to tiers: in
  the table of an elimination outcome two entrants in different tiers who
  have the same ranking value are one tied set for such a tiebreaker,
  although the tiers order them first. The value orders entrants inside a
  tier only, as every figure does, but it is not a value of the tier: an
  event against an entrant of another tier that has the same ranking value
  counts in it, and can place one entrant of a tier above another who is
  level with it on everything inside the tier. A double elimination
  bracket has such events (two entrants who met and went out in different
  rounds with the same record). Forming the sets inside a tier needs the
  calculator to be given the tiers before it asks the tiebreakers, which
  it is not; `docs/ROADMAP.md` lists this as a known limitation.
- The same measure cannot be in a chain twice under one name: a value is
  kept on an entry, and compared, under the name of its tiebreaker, and a
  tiebreaker of this kind has another value at every place. A calculator
  whose chain holds one and names any tiebreaker twice is refused when it
  is built (`InvalidInputException`); a chain without one is not checked,
  as before. Re-applying a measure to a subset takes a tiebreaker of the
  application's with a name of its own; `docs/ROADMAP.md` lists that as a
  known limitation.
- `tests/Unit/Standings/BetweenTiedParticipantsTest.php` pins the cases a
  calculation over the whole table gets wrong (three participants level on
  the measure among themselves, a set that splits into subsets), and
  `tests/Feature/ByesAndTiebreakersInvariantsTest.php` holds every measure
  to an oracle over generated cases, in every order of the input.
