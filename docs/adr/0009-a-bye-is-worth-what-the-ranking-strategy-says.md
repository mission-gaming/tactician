# 0009. A bye is worth what the ranking strategy says

Status: accepted; amended by [0014](0014-a-bracket-table-is-one-table-of-its-tiers-and-byes.md),
which credits the byes of a bracket in its placement table too, and in a
bracket credits a bye with nothing, and refuses nothing, under a strategy
without a bye value, and by [0019](0019-a-swiss-bye-under-a-strategy-without-a-bye-value-is-worth-nothing.md),
which does the same in a Swiss stage; narrowed by [0017](0017-a-league-rest-earns-nothing.md),
under which the round a participant of a league rests is counted and
credited with nothing; amended by
[0022](0022-classes-are-final-and-the-interfaces-are-the-extension-points.md),
under which no subclass of `StandingsCalculator` is calculated its own way

## Context

A bye is a round a participant sat out. It is no result, so a ranking
strategy is never asked about it, and yet a stage has to place the
participant who had one: a Swiss stage pairs the next round from the table,
and a re-seeded bracket folds its second round from one.

Through 0.2.x the Swiss engine counted a bye as a win when it ordered a
round and as nothing in the table of the outcome. 0.3.0 first made the two
one table ([0004](0004-position-decides-where-results-do-not.md)) and
credited a bye as a win in it. That needed the value of a win, which only
`WinDrawLossRanking` has, so every other `RankingStrategy` was refused a
stage with a bye. And "a win" is one competition's rule: others score a bye
as a draw, as nothing, or as an amount of their own.

Two tiebreakers read the ranking values of opponents (`BuchholzTiebreaker`,
`SonnebornBergerTiebreaker`). A bye has no opponent, and they said nothing
about it: the participant's own bye added nothing, and an opponent's value
was read without the credit of its byes, because the tiebreakers were asked
before the credit was applied.

## Decision

**The ranking strategy states the value of a bye.** A strategy that
implements `Standings\RankingStrategyWithByeValue` returns, from
`getByeValue()`, the ranking value one bye adds. The interface is optional:
adding a method to `RankingStrategy` would have broken every strategy an
application has written. `WinDrawLossRanking` implements it. Its value of a
bye is the value of a win unless another is given (`withByeValue()`, a
fourth constructor argument, or `bye` in its plain data: a number, or
`'win'`, `'draw'` or `'loss'`), so every table calculated before is
calculated the same.

**A bye is a figure of its own on an entry.** `StandingEntry::getByes()`
counts the byes, also where a bye is worth nothing. Played, wins, draws and
losses count results only. The ranking value is shown in its parts:
`getRankingValueOfResults()`, `getEarnedRankingValue()` (the first plus the
value of the byes) and `getRankingValue()` (the second plus the adjustment).

**One call calculates the table of a stage.**
`StandingsCalculator::calculateWith()` takes the results, the byes and the
adjustments together. The engines calculate every table with it, from what
the stage state records, and read that one table for the order a round is
paired from and for the outcome. The value of a bye is therefore the same in
both, whatever it is.

**A refusal remains only where no value is known.** A table with a bye to
credit under a strategy that does not implement the interface is refused
(`IncompatibleOptions`), by the calculator, by the Swiss engine and, before
a round is paired, by a re-seeded bracket whose field is not a power of two.
Nothing else is refused: a strategy that implements the interface is
credited whatever its scale.

**A tiebreaker computed from opponents says how it treats a bye.** The
optional interface `Standings\TiebreakerWithByeTreatment` returns a
`ByeTreatment`, and a tiebreaker that implements it is handed entries that
carry the byes and their credit. The cases answer two questions at once,
what the participant's own bye adds and which ranking value is read for an
opponent:

| Treatment | Own bye | An opponent's ranking value |
| --- | --- | --- |
| `Uncounted` | adds nothing | the value of its results |
| `Zero` | an opponent worth nothing | what it earned, byes included |
| `OwnRankingValue` | an opponent with the participant's own earned value | what it earned, byes included |

The library's two tiebreakers default to `Uncounted`, which is what they
computed before. No treatment reads an opponent's adjustment: an adjustment
comes from no event and says nothing about how strong an opponent was to
meet.

**What changes the table is in the engine fingerprint, at its default
nowhere.** The value of a bye of a `WinDrawLossRanking` is stated
(`bye-value`) when it is not the value of a win, and the treatment of a
tiebreaker (`tiebreaker-byes`) when it is not `Uncounted`. Every stamp
written before is unchanged.

## Consequences

- With the defaults no table, pairing or fingerprint changes. The golden
  fixtures did not move.
- `Uncounted` is a default kept for compatibility, not the treatment a
  competition that credits byes is likely to want: such a table should name
  `Zero` or `OwnRankingValue`. Making one of them the default is an output
  change for every Swiss table with a bye and a tiebreaker of this kind,
  and is left for a release that chooses to make it.
- Byes and adjustments can no longer always be applied to a table after it
  was calculated. `withByesCredited()` and `withAdjustments()` keep the
  tiebreaker values of the table they are given; with a tiebreaker whose
  value depends on the byes (a treatment other than `Uncounted`) or on who
  is level ([0010](0010-a-tiebreaker-between-the-tied-participants-is-computed-by-the-calculator.md))
  they refuse, and `calculateWith()` is the call.
- A subclass of `StandingsCalculator` was calculated through its own
  `calculate()`, `withByesCredited()` and `withAdjustments()`, as it was
  before `calculateWith()` existed, so one that ordered a table by rules of
  its own was still asked. No longer: the class is final from 0.3.0
  ([0022](0022-classes-are-final-and-the-interfaces-are-the-extension-points.md)).
- `OwnRankingValue` counts a bye in full in Sonneborn-Berger, as an
  opponent beaten, whatever the strategy says a bye is worth. A competition
  that values a bye as a draw and wants half of it there has no case for
  that; it would be a fourth.
- The combined table of a pooled outcome still credits no bye
  ([0006](0006-pools-of-unequal-size-are-compared-by-a-method-the-caller-names.md)):
  `StageOutcome::combining()` calculates it from the results and the
  adjustments. Crediting the byes there would raise the question 0006
  leaves open, what a comparison per event played divides by, and is not
  decided here.
- `tests/Feature/ByesAndTiebreakersInvariantsTest.php` holds the table to a
  calculation of its own over generated cases, and
  `tests/Feature/ByeValueInStagesTest.php` holds the pairing order and the
  outcome of a Swiss stage to one table for every value of a bye.
