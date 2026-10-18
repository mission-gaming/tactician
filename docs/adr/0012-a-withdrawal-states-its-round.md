# 0012. A withdrawal states its round

Status: accepted; amended (see "Amendment: an adjustment states its round
too" below). Narrows, in [0007](0007-recorded-rounds-are-read-not-derived.md),
what an absent round means for a withdrawal and, with the amendment, for an
adjustment.

## Context

A stage state records after which round each withdrawal happened, and an
engine reads a recorded round from who was in the stage when it was paired
([0007](0007-recorded-rounds-are-read-not-derived.md), "A state says when").
A history could still give a withdrawal without its round, as a plain
participant, and the engines then read what the rounds showed: a round that
names an entrant was paired while it was in. That is enough for one
withdrawal from a tie and not for two. Measured against a model of the
bracket that knows when everything happened, a history that stated no rounds
was read otherwise than the stage was played in 438 of 3,380 single and 430
of 1,500 double elimination stages, nearly all of them a tie both entrants
left.

The verbs had a gap of their own: a state that held no round removed an
entrant who withdrew and kept no withdrawal, so an entrant who withdrew while
the first round was played, before the round was recorded, lost its place:
the first round was read as one drawn by hand for the field without it, and a
double elimination bracket could be refused.

## Decision

**Once a history holds a round, every withdrawal states its round.**
`StageState::fromHistory()` refuses a plain participant among the
withdrawals of a history with rounds (`InvalidConfigurationException`, reason
`WithdrawalWithoutRound`, with the participant and the last round in the
context). With no round supplied a plain participant is accepted: it can only
have withdrawn before the first round.

**Round 0 is the time before the first round is recorded.** A `Withdrawal`
takes 0 (it took 1 or above), and `withoutParticipant()` on a state that
holds no round records the withdrawal after round 0, keeping the entrant in
its place in the stored entrant list. Until the first round is recorded the
entrant is out of the draw: `getEntrants()`, `getWithdrawnEntrants()` and
`getAllSeenParticipants()` do not list it, so the first round is paired from
those who remain, as before. The first round says the rest when it is
recorded (`withRoundPlayed()`): an entrant it names was in the draw and
withdrew while the round was played, and keeps its place and its withdrawal
after round 0; an entrant it does not name withdrew before the draw, and is
dropped from the entrant list with its withdrawal, as if it had never
entered. A history says the same with `new Withdrawal($participant, 0)`.

**A stored entrant list carries a withdrawal for every entrant who is not
active.** `fromArray()` refuses an entrant list that names a withdrawn entrant
with no withdrawal, a withdrawal of a participant who is not an entrant, and a
withdrawal after round 0 of a participant the first recorded round does not
name. No release wrote any of these: 0.2.x wrote neither `entrants` nor
`withdrawals`, and such data still loads, with its withdrawn participants
read as before (no place, no time). The keys stay optional keys of version 1
of the stage state form: 0.3.0 is not released
([0005](0005-serialized-forms-carry-a-format-version.md)).

The undated reading of 0007 stays for that data only.

## Consequences

- Every withdrawal in a state built by the verbs or by `fromHistory()` from
  a history with rounds has its round, so the engines read who was in the
  stage when each round was paired, and the rule of
  [0011](0011-a-tie-both-entrants-left-is-void.md) can be applied.
- An entrant who withdraws while the first round is played keeps its place
  whichever is recorded first, the round or the withdrawal: the two orders are
  one history. The limit 0007 stated for the first round is gone.
- A stage state with a withdrawal before the first round is stored with the
  `entrants` and `withdrawals` keys (`after_round` 0) until the first round is
  recorded, where it was stored as a state that never held the entrant.
- The adjustments were not changed by this record as first accepted; the
  amendment below changes them the same way.
- `tests/Feature/StageTimesTest.php` ("a withdrawal says after which round it
  happened") holds the rules, and the sweep "reads a bracket the same whether a
  round or the withdrawals during it are recorded first" in
  `tests/Feature/EliminationInvariantsTest.php` now withdraws entrants while
  the first round is played as well.

## Amendment: an adjustment states its round too

**Context.** As first accepted, this record left adjustments as 0007 had
them: one without a round was accepted and read as of unknown time. The
engines then showed no derivation beside a round it could have changed, and
a re-seeded bracket refused a recorded round it could not read without the
ranking of the time (`RecordedRoundMismatch`, with `adjusted_without_round`
in the context). That refusal was wide (every round after the first that an
entrant had withdrawn before), and the reading was weaker than the one a
history with its rounds stated gets, for the same reason as for withdrawals:
rows that do not say when something happened cannot be read as it happened.

**Decision.** An adjustment states its round once there are rounds, as a
withdrawal does. `fromHistory()` refuses an adjustment without
`Adjustment::madeAfterRound()` in a history that holds a round
(`InvalidConfigurationException`, reason `AdjustmentWithoutRound`, with
`participant` and `last_round` in the context); with no round supplied it is
made after round 0. `fromArray()` refuses a stored adjustment without
`after_round` in a state that holds a round, and reads one in a state that
holds none as made after round 0. `withAdjustment()` always records the
round, as it did. So no state an engine reads holds an adjustment without
its round, and the engines lose the reading of one and the refusal that went
with it (`SingleEliminationEngine::requireNoRankingToReadBy()` and the
derivation-less rounds of a re-seeded bracket and a Swiss stage are gone).

No release wrote an adjustment without its round: 0.2.x stored no
adjustments, and the key `after_round` and the adjustments themselves are
both of 0.3.0, which is not released. Data stored by 0.2.x is read exactly as
before. A state stored by an unreleased revision with an undated adjustment
and a round is refused with a message that names the key.

**Consequences.**

- Every adjustment of a state has its round, and the engines read every
  recorded round from the adjustments made before it was paired, in every
  engine and with no exception: no derivation is withheld for an adjustment
  and no re-seeded bracket is refused for one.
- What remains of the time of an adjustment is the application's: an
  adjustment made while a round is played is recorded after that round only
  where the round was recorded when it was paired
  (`StageState::withRoundPaired()`), which the documented loop does.
- `tests/Feature/StageTimesTest.php` ("an adjustment that does not say after
  which round it was made") holds the rules.
