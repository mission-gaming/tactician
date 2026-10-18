# 0012. A withdrawal states its round

Status: accepted. Narrows, in [0007](0007-recorded-rounds-are-read-not-derived.md),
what an absent round means for a withdrawal.

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
- The adjustments are not changed by this record: an adjustment that states
  no round is still accepted and read as 0007 describes.
- `tests/Feature/StageTimesTest.php` ("a withdrawal says after which round it
  happened") holds the rules, and the sweep "reads a bracket the same whether a
  round or the withdrawals during it are recorded first" in
  `tests/Feature/EliminationInvariantsTest.php` now withdraws entrants while
  the first round is played as well.
