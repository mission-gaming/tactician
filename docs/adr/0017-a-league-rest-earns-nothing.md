# 0017. A league rest earns nothing

Status: accepted; narrows [0009](0009-a-bye-is-worth-what-the-ranking-strategy-says.md)
for the round-robin engine

## Context

`Scheduling\RoundRobinEngine` holds a league as a stage state. In a field
of odd size one participant sits each round out, and the round lists it
among its byes, as the scheduler's schedule does (`Schedule::getByesByRound()`).
The first draft of the engine credited each such round with what the ranking
strategy says a bye is worth ([0009](0009-a-bye-is-worth-what-the-ranking-strategy-says.md)):
a win under the default `WinDrawLossRanking`.

Over a whole round robin every participant sits out as many rounds as the
league has legs, so the credit adds the same amount to everyone and changes
no final order. In the middle of a season it does not: a participant that
has sat a round out shows the points of a win it has not played for, ahead
of participants with the same results who have not yet rested. Leagues do
not publish tables like that. A league that scores 3, 1 and 0 shows the
points of the games played, and the rest is a gap in its fixture list.

A bye in the sense of 0009 is something else. In a Swiss stage or a
bracket, a bye stands for a round the participant would have played and
was let through without an opponent: a tie it went on from, or a pairing
the field could not give it. The format credits it because it decides who
meets whom from the table, and the participant must be placed as though it
had played. A league's fixtures are drawn once, from the entrant list;
nothing is paired from its table, and the round a participant rests is one
it was never drawn to play.

## Decision

**A round sat out in a league counts as a bye and is worth nothing.** The
round still lists the participant among its byes, the state records it
(`StageState::getByeCounts()`), and the participant's entry counts it
(`StandingEntry::getByes()`), but the engine calculates every table, the
outcome's included, with `StandingsCalculator::calculateWith(...,
creditsPerBye: 0)`: each bye is counted and credited with nothing, whatever
the ranking strategy says a bye is worth. A tiebreaker that reads byes
(`TiebreakerWithByeTreatment`) is handed entries that carry the bye and no
credit for it. A ranking strategy that does not say what a bye is worth is
therefore not refused a league with a rest.

The engine keeps the credit in one place, so that a later option could give
a rest a value; no such option exists, and none is added until a competition
needs one. It would be part of the fingerprint when it is not at its
default, nothing, by the rule of [0015](0015-an-engine-fingerprint-versions-the-rules-of-each-configuration.md)
for a new option.

0009 stands for every engine that pairs from its table or places a bye in a
bracket: the Swiss engine and the two elimination engines.

## Consequences

- The table of a league at any point shows the points of its results and
  adjustments, and the number of rounds each participant has rested; a
  league's mid-season table matches what a league publishes. The final
  table of a single or double round robin of odd size has the order the
  results give, as before.
- The bye value of a `WinDrawLossRanking` (`withByeValue()`) has no effect
  on a league. A calculator built with one gives the same league table as
  one without.
- The fingerprint does not change: nothing is paired from the table, and the
  standings calculator is not part of the round-robin engine's fingerprint.
- `tests/Feature/RoundRobinEngineTest.php` holds the table of fields of odd
  size over one and two legs, after every round and at the end, to a table
  computed by hand that gives a rest nothing, under four ranking strategies:
  a bye worth a win, a bye worth a draw, 1/½/0, and one that says nothing of
  byes.
