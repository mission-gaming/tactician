# 0021. Everyone in a round of a stage state is an entrant

Status: accepted; amends point 8 of
[0007](0007-recorded-rounds-are-read-not-derived.md), as
[0018](0018-the-rounds-of-a-stage-state-are-numbered-1-2-3.md) and
[0020](0020-nobody-is-in-a-round-of-a-stage-state-twice.md) did, and
replaces the sentence of 0020 that kept this rule to `fromHistory()`

## Context

A stage state can be built in three ways: verb by verb
(`StageState::withRoundPlayed()`, and `withRoundPaired()`, which calls it),
from a history of the application's rows (`fromHistory()`), and from the
array `toArray()` wrote (`fromArray()`, which records the stored rounds again
through `withRoundPlayed()`).

Point 8 of 0007 made "every participant an entrant" a rule of
`fromHistory()` alone, and 0020 kept it there when it brought "nobody twice
in a round" to the verbs. So the verbs recorded a round drawn by hand that
named a participant the stage was not started with, in an event or among
the byes, and `fromHistory()` refused the same round with
`ParticipantNotAnEntrant`. No engine pairs such a round.

Who is an entrant depends on withdrawals, and the two ways in did not agree
on one case either. An entrant who withdraws before the first round is
recorded is out of the draw until that round names it (`withoutParticipant()`):
the first round says whether it was in the draw. One the first round does
not name withdrew before the draw and is no entrant, and the verbs drop it
from the entrant list. `fromHistory()` checked every round against the list
it was given, so it accepted a later round that named such a participant,
given with a `Withdrawal` after round 0, and built a state whose rounds named
somebody outside its entrant list.

Stored data adds a case that no rule can tell apart. 0.2.x kept no entrant
list: it stored a participant who had withdrawn by the rounds that name it
and left it out of the active list. A participant who never entered, drawn
by hand into a round, is stored the same way. A state read from such data
and written again by this release still holds the participant with no
place, also next to an `entrants` key written for a later withdrawal.

## Decision

**Everyone in a round of a stage state is an entrant.** The verbs refuse a
round that names a participant who is not one, in an event or among the
byes, with the exception, reason, message and context `fromHistory()` gives:
`InvalidConfigurationException`, `ParticipantNotAnEntrant`, "The history
names participant X (in an event), who is not one of the entrants", with
`participant`, `role` (`in an event` or `with a bye`) and `round` in the
context. The check is made of each participant as it is met, in the one
check 0020 introduced, so a round that breaks this rule and that of 0020 is
refused for whichever comes first in it, by every way in.

**The entrants of a round are the entrant list and the participants the
first round names.** For a state the verbs build from `start()`, and for one
`fromHistory()` builds, that is the entrant list: an entrant who withdraws
from the live stage keeps its place and may be named by a later round (a
league pairs its fixtures to the end), one who withdraws before the first
round is an entrant for that round, and one the first round does not name
is no entrant from then on. `fromHistory()` now refuses a later round, or a
result, that names an entrant whose `Withdrawal` is after round 0 and whom
round 1 does not name, as the verbs do; it no longer builds a state whose
rounds name somebody outside its entrant list.

**A stored state with such a round is refused on load, from its second
round on.** `fromArray()` and `fromJson()` refuse a round after the first
that names a participant who is neither in the stored entrant list (the
active list where the data has none) nor named by the first round, whatever
the format version, so including data 0.2.x wrote without one. A
participant the first stored round names is read as an entrant who withdrew
and whose place is not known, which is how this release already read 0.2.x
data with a withdrawal (0007, Consequences). The data cannot say more: a
reader that refused the first round as well would refuse every state 0.2.x
stored after a withdrawal.

The stage state form takes no new format version, for the reason 0018
gives: no key is added, removed or renamed, and no value takes another
meaning. What changes is which values are accepted. 0.2.x documented that
its verbs did not check this, so this is a deliberate break, and the
changelog lists it as one.

After this record the one rule `fromHistory()` holds a state to that the
verbs do not is one result for an event (`DuplicateResult`): the verbs
accept a second result, and `withResultReplaced()` repairs it (0007, point
8). The other checks `fromHistory()` makes of rows (an entrant in every
result, withdrawal and adjustment, and the round each withdrawal and
adjustment happened after) concern what the verbs record by themselves.

## Consequences

- An application that records the rounds an engine pairs is not affected.
  A sweep of every engine (Swiss, seeded Swiss, single elimination with two
  legs, re-seeding or a third-place tie, double elimination with one or two
  legs, the round-robin engine with one or two legs), 2 to 16 entrants,
  three seeds, with no withdrawal, a withdrawal before the draw, and
  withdrawals from the live stage, each round recorded with
  `withRoundPaired()` and read again from JSON, refused none of 8,214
  rounds in 1,350 stages.
- An application that draws a round by hand with a participant who never
  entered has that call refused now, and a state it stored that way no
  longer loads where a round after the first names the participant. It is
  rebuilt with the participant among the entrants, through `start()` or
  `fromHistory()`, or without it.
- A state 0.2.x stored from rounds drawn by hand, in which an entrant sat
  out round 1 without a bye listed, played a later round and then withdrew,
  no longer loads: only that later round names it, and the data cannot tell
  it from a participant who never entered. No engine pairs such a round 1;
  each names every entrant in the draw, in an event or among the byes.
- A history that names, after round 1, an entrant it says withdrew before
  the draw is refused by `fromHistory()` now.
- No line of `tests/Fixtures/stored-by-0.2/` holds such a round: the one
  case with a withdrawal (`state single elim withdrawal`) names the
  withdrawn participant in its first round, and is read as before. No
  stored case joins the list of those no longer read.
- `tests/Feature/StageHistoryTest.php` changes each round every engine pairs
  to name a participant who never entered, or one who withdrew before the
  draw, and shows that `withRoundPlayed()`, `withRoundPaired()`,
  `fromArray()` and `fromJson()` refuse exactly where `fromHistory()` does,
  with the same reason, message and context, bar the first round read from
  storage, which is read as described above.
