# 0019. A Swiss bye under a strategy without a bye value is worth nothing

Status: accepted. Amends
[0009](0009-a-bye-is-worth-what-the-ranking-strategy-says.md) (for
Swiss: a strategy without a bye value credits a bye with nothing, and is
refused nothing) and extends the rule
[0014](0014-a-bracket-table-is-one-table-of-its-tiers-and-byes.md) made
for brackets to the Swiss engine.

## Context

[0009](0009-a-bye-is-worth-what-the-ranking-strategy-says.md) made the
value of a bye the ranking strategy's to state
(`RankingStrategyWithByeValue`), and refused a table with a bye to credit
under a strategy that states none.
[0014](0014-a-bracket-table-is-one-table-of-its-tiers-and-byes.md) lifted
that refusal for brackets: a bracket under such a strategy counts each bye
and credits it with nothing. It left the Swiss engine as it was, and said
that it should follow.

The Swiss engine refused at the first round paired after a bye
(`IncompatibleOptions`), and refused the outcome of a stage whose only bye
was in its last round. A field of odd size from the start pairs round 1
and is refused round 2. A field of even size is refused after a
withdrawal makes it odd, part of the way through a stage that the
application has already published. A refusal before the first round
could not cover the second case, for the reason 0014 gives for brackets:
the field says which byes the first round has, but not who will withdraw.

## Decision

**A Swiss stage under a ranking strategy that does not say what a bye is
worth counts each bye and credits it with nothing, and is paired on.** The
engine calculates its one table with
`StandingsCalculator::calculateWith(..., creditsPerBye: 0)` under such a
strategy (`SwissPairingEngine::creditsPerBye()`), and with a credit of one
under a strategy that implements `RankingStrategyWithByeValue`, as before.
The table is the one every round is paired from and the one the outcome
carries, so the two credit a bye alike, as 0009 requires.

What follows for pairing:

- The participant who had the bye is placed by what its results earned. It
  is in the score group of those who lost the round it sat out, not of
  those who won it, as it is under a `WinDrawLossRanking` whose bye is
  worth nothing.
- The bye is still counted (`StandingEntry::getByes()`,
  `StageState::getByeCounts()`), and the bye of the next round goes to the
  lowest-placed participant among those who have had the fewest, as it
  does under every strategy. So nobody has a second bye while a
  participant who has had none can take it and leave a complete pairing.
- A tiebreaker that reads byes (`TiebreakerWithByeTreatment`) is handed
  entries that carry the bye and no credit for it, as in a bracket.

The `SwissScheduler` preset is not affected: it builds its engine with the
library's `WinDrawLossRanking`, whose bye is worth a win.

## Consequences

- No Swiss stage is refused for its byes, under any ranking strategy. A
  competition that wants a bye to count implements `getByeValue()` (one
  method), as 0014 says for brackets.
- Under a strategy with a bye value nothing changes. Measured over 10,800
  stages (fields of 3 to 20, ten seeds each, up to seven rounds, with no
  withdrawal, one or several; with and without a tiebreaker that reads
  byes; with and without a seed), 2,160 per strategy: none of the 8,640
  stages under the four strategies with a bye value pairs or ranks
  differently. Under a strategy without one, 1,706 of 2,160 were refused
  before and none is now; the 428 that were not refused are unchanged, and
  all 2,160 pair as under a `WinDrawLossRanking` with the same scale and a
  bye worth nothing. No golden fixture changed.
- The engine fingerprint does not change
  ([0015](0015-an-engine-fingerprint-versions-the-rules-of-each-configuration.md)):
  every state that holds a bye under such a strategy was refused before,
  so no state the engine paired is paired differently now.
- `getRecordedRounds()` gives a derivation for a round paired after a bye
  under such a strategy, where the refusal left it none.
- The calculator still refuses a bye to credit with a value under such a
  strategy (`calculateWith()` with `$creditsPerBye` above 0). That is a
  call that asks for a value, and nothing states one.
- `tests/Feature/SwissInvariantsTest.php` drives stages under such a
  strategy with withdrawals through the audit of
  `tests/Support/SwissStageAudit.php`, which holds every round and the
  outcome to a bye worth nothing;
  `tests/Feature/ByeValueInStagesTest.php` holds the pairing order and the
  outcome to one table under it; and
  `tests/Unit/Scheduling/SwissPairingEngineTest.php` pins a field of odd
  size and one a withdrawal makes odd.
