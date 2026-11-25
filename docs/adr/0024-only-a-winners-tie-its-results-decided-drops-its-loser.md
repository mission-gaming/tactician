# 0024. Only a winners tie its results decided drops its loser

Status: accepted. Narrows, in
[0011](0011-a-tie-both-entrants-left-is-void.md), what it says of the
dropper of a winners tie in double elimination.

## Context

In double elimination the loser of a winners tie drops to the losers
bracket. Where the loser had withdrawn, the engine read it two ways
(issue #182, found in the review of #178):

- A winners tie given away while the loser was still in the stage
  (uncontested: the loser withdrew, the other entrant was in): the loser
  dropped, and the losers tie it would have played was shown as uncontested,
  the other entrant of it going on by walkover.
- The same tie read as void, because both of its entrants had withdrawn,
  and one of them found afterwards to have gone through it after all
  ([0011](0011-a-tie-both-entrants-left-is-void.md): it was still in the
  stage when its next winners tie was paired): the loser did not drop, and
  that losers tie was shown as a bye.

So a withdrawal by the other entrant, recorded after a later winners round
was paired, changed how an earlier losers tie looked, and whether the
entrant who went on in it was credited with a bye. Who went on, the rounds
paired and the placings were the same either way.

The walk cannot read the first way in both cases: the losers round a
dropper joins is read before the winners round that says whether a void
tie had a winner after all, so a rule that drops the loser of every tie
that sent somebody on would have to read the bracket out of its order.

## Decision

**An entrant drops to the losers bracket only by losing a winners tie its
results decided.** The loser of a winners tie given away by its withdrawal
(uncontested), the two of a void tie, and an entrant who stood alone in a
winners tie after withdrawing drop nobody: the losers slot they would have
filled is empty, and they go out with those the next losers round puts out,
which is where a dropper who had withdrawn went out before. An entrant who
lost a winners tie by its results drops whatever it does later, and a
losers tie it has withdrawn from is uncontested, as before.

That reads one fact, the results of the winners tie, and the time of no
withdrawal: an entrant who withdrew before its winners tie was decided did
not reach the losers bracket, whatever the other entrant does afterwards.
The losers tie is shown the same at every moment, a bye for the entrant who
stands alone in it, and the winners tie a void tie or an uncontested one as
[0011](0011-a-tie-both-entrants-left-is-void.md) says, with nobody in the
losers slot it feeds. The rule is read in `DoubleEliminationEngine::resolveStage()`,
and the bracket view projects the rounds it has not reached the same way: the
slot a winners tie of that kind feeds holds nobody.

**What changes.** The pairing of every round is the same: the entrant
alone in the losers tie was listed among the byes of the round already, as
the other entrant of a tie an entrant has withdrawn from, and now is as an
entrant who stands alone. So are the placings, the outcome's settled ties
and the fingerprint
([0015](0015-an-engine-fingerprint-versions-the-rules-of-each-configuration.md)).
The bracket view shows that losers tie as a bye where it showed it as
uncontested, and the table counts a bye for its entrant
(`StageOutcome::getByes()`), which a ranking strategy with a bye value
credits ([0014](0014-a-bracket-table-is-one-table-of-its-tiers-and-byes.md)):
a walkover is credited with nothing.

## Consequences

- The view of a recorded round of a double elimination bracket never
  changes once a later round is recorded, other than slots of rounds still
  to come being filled; `tests/Feature/EliminationInvariantsTest.php`
  holds that property for every engine configuration, and
  `tests/Feature/WithdrawnDropperTest.php` pins the case of the issue.
- A dropper who lost its winners tie by its results and withdraws before
  its losers tie still gives that tie away (uncontested), as before.
- A competition that wants the walkover credited where the loser withdrew
  before its winners tie records an awarded result for that tie.
