# 0011. A tie both entrants left is void

Status: accepted. Replaces, in [0007](0007-recorded-rounds-are-read-not-derived.md),
the rule that the first of two entrants to withdraw from a tie gave it to the
other.

## Context

A bracket gives a tie away when one of its entrants withdraws before a result
decides it: the other entrant goes on without the tie being played (an
uncontested tie). Where both entrants withdraw, something has to be said of
the tie. Until this record the order of the withdrawals said it: the first to
withdraw gave the tie to the other, who went on and withdrew later. An entrant
who had been given the final and withdrew afterwards was still the winner of
the bracket, and an entrant who had been given a tie and left before its next
one was placed a round further than it ever played.

That is not what a competition does. If both participants withdraw, nobody is
there to go through. The order of two withdrawals is also a fact an
application's rows rarely hold, and reading it was the reason a history that
did not say when each entrant withdrew was read otherwise than the stage was
played in 438 of 3,380 generated single elimination stages and 430 of 1,500
double elimination stages ([0012](0012-a-withdrawal-states-its-round.md) is
the other half of the answer).

## Decision

**A tie whose results do not decide it, both of whose entrants have withdrawn
before either went through it, is void.** It sends nobody on. The decision is
read in `EliminationBracketSupport::settleTie()` (both withdrawn: no winner,
both listed as going out) and `wentOnFrom()`, and walked by both engines.

**An entrant goes through an uncontested tie when its next tie is paired.**
Where the other entrant had already left when the round of the tie was paired,
that round lists the entrant among its byes, and it went through then. Where
both were in the stage when the round was paired and the other left later, the
entrant went through only if it was still in the stage when the round of its
next tie was paired: in single elimination the next round, in double
elimination the next stage of its own bracket (the next winners round for a
winners tie, the next losers round for a losers tie, the grand final for the
two bracket finals). Who was in the stage when a round was paired is read as
everywhere else: a round that names an entrant, in an event or as a bye, was
paired while it was in, whatever round its withdrawal states. Reading the
moment at the next tie, and not at the next round, means that a withdrawal
recorded before a round that does not name the entrant (a winners round, for
an entrant in the losers bracket) changes nothing for a tie the entrant was
paired in. One reading is left that a round that does not name the entrant
cannot settle: a losers round with no tie to play is not recorded, and is read
from who was in the stage when the next recorded round was paired. An entrant
who went on alone from it and withdraws while that round (a winners round) is
played is placed one stage lower when the withdrawal is recorded first; who
goes on, the rounds and the winner are the same.

**The last tie of a bracket has no next tie.** The final, the third-place tie,
the grand final and its reset are void whenever both of their entrants have
withdrawn without a result that decides them, unless the round of the tie was
paired after one of them had left (the round then lists the other among its
byes). So an entrant who had the final given to it and withdraws afterwards no
longer holds it where the final was never paired, because no round was left to
play. A tie its results decided is never void, whoever withdraws afterwards.

**What follows from a void tie:**

- The slot it would have filled is empty. The entrant who would have met its
  winner stands alone and goes on (it is listed among the byes of the round
  the engine pairs); where both ties that feed a tie are void, that tie holds
  nobody and its slot is empty in turn. An entrant who stands alone and had
  withdrawn when its round was paired goes on no more than the two of a void
  tie do: it goes out in that round, and a last tie it stands alone in has no
  winner. (Before, such an entrant went on regardless, so that a bracket
  every entrant had left could still be won.)
- Its two entrants go out in it, and share the tier of its round in the table.
  A void final leaves the first tier empty, the two finalists sharing the
  second; a void third-place tie leaves the third place empty, the two sharing
  the fourth. A void semifinal sends one of its two withdrawn entrants to the
  third-place tie, which is therefore uncontested: the loser of the other
  semifinal is third without a tie being played, and the two of the void tie
  share the fourth place.
- In double elimination a void winners tie drops nobody to the losers bracket:
  the losers slot it would have filled is empty, and its two entrants go out
  together with those the next losers round puts out. Which of two entrants
  went through a winners tie is read at the next winners round, and the other
  is the one who dropped; the engine reads the bracket a second time when it
  finds such an entrant, because the losers bracket that the dropper enters
  comes first in the walk.
- The outcome says it: the settled tie of a void last tie has no advancer and
  both entrants among the eliminated (`Stage\SettledTie`), and
  `MatchOutcomeSelector` names nobody in winners mode and both in losers mode.
- The order of two withdrawals decides nothing. A state keeps it as given.

A tie is pairwise, and so is this rule ([0003](0003-multi-participant-events-are-a-2-0-goal.md)).

## Consequences

- A history whose rounds are recorded when they are paired, and whose
  withdrawals state their rounds, is read as it was played, measured against a model that knows when everything happened (see
  the pull request that introduced this record for the figures).
- A bracket can end with nobody in its first tier. Every selector that reads
  ranks reads a table whose first tier holds two withdrawn finalists, level:
  a rank selection of the first place is a tied set there.
- An entrant who withdraws after the end of a bracket whose last tie it was
  given without playing takes the title back with it. A withdrawal from the
  next stage of a tournament belongs to that stage's state, not to a finished
  one.
- In single elimination with a third-place tie, the entrant who takes the
  third place from a tie the other entrant has left is listed among the byes
  of the last round, so that the round names it (the rule above reads a name
  as a fact).
- `tests/Feature/StageTimesTest.php` ("a tie both of whose entrants have
  withdrawn") holds the cases, and `tests/Feature/EliminationInvariantsTest.php`
  the sweeps: a withdrawal takes back nothing a result decided, and a bracket
  is read the same whether a round or the withdrawals during it are recorded
  first (in double elimination, of the entrants the round names; the case
  above where it does not is pinned in `StageTimesTest`, "a round that is
  recorded after it was paired").
