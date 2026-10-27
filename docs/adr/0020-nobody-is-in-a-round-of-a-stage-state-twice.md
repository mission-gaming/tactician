# 0020. Nobody is in a round of a stage state twice

Status: accepted; amends point 8 of
[0007](0007-recorded-rounds-are-read-not-derived.md), as
[0018](0018-the-rounds-of-a-stage-state-are-numbered-1-2-3.md) did for the
round numbers

## Context

A stage state can be built in three ways: verb by verb
(`StageState::withRoundPlayed()`, and `withRoundPaired()`, which calls it),
from a history of the application's rows (`fromHistory()`), and from the
array `toArray()` wrote (`fromArray()`, which records the stored rounds again
through `withRoundPlayed()`).

Point 8 of 0007 made "nobody twice in a round" a rule of `fromHistory()`
alone: the verbs and `fromArray()` kept accepting a round that held a
participant twice, because data stored by earlier releases could hold one.
`fromHistory()` refuses with `ParticipantTwiceInRound` a participant in two
events against different opponents, in an event and among the byes, or in
one event listed twice. The two legs of a tie, which differ in their tie
leg, are one appearance.

0018 brought the round numbers under one check and said that every state the
verbs build is one `fromHistory()` builds. For this rule it was not so. The
verbs accepted a hand-drawn round that held one event twice: `a v b` twice,
`a v b` and `b v a`, or an event with no tie leg beside the same pair marked
as leg 1. A result names its event by round, pair in either order and leg
(`Stage\EventKey`), so the two events are one event to everything that reads
the state: an engine and the bracket view showed one result on both. No
engine pairs such a round. Only a pairing drawn by hand produces one.

## Decision

**A participant is in a round of a stage state once.** The verbs refuse a
round that holds a participant twice with the exception, reason and message
`fromHistory()` gives: `InvalidConfigurationException`,
`ParticipantTwiceInRound`, "Participant X is in round N twice", with
`round` and `participant` in the context. They refuse exactly what
`fromHistory()` refuses: the three cases above, the same pair in either
order with the same tie leg counting as one event, and the legs of one tie
as one appearance.

The three ways in share one check (a private method of `StageState`):

- the verbs call it once the round number is checked;
- `fromHistory()` calls it for each round, together with its check that
  every participant is an entrant, so that a round that breaks both rules is
  refused for whichever comes first in it;
- `fromArray()` reaches it through the verb, because it records the stored
  rounds again.

**A stored state with such a round is refused on load.** `fromArray()` and
`fromJson()` refuse it with `ParticipantTwiceInRound`, whatever the format
version, so including data 0.2.x wrote without one. This replaces what the
last sentence of point 8 of 0007 says of this rule. What that point keeps
accepting is unchanged: a pairing whose participants are no longer active,
and two results of one event. A participant who is not an entrant is still
checked by `fromHistory()` only.

The stage state form takes no new format version, for the reason 0018
gives: no key is added, removed or renamed, and no value takes another
meaning. What changes is which values are accepted. 0.2.x documented that
its verbs did not check this, so this is a deliberate break, and the
changelog lists it as one.

## Consequences

- An application that records the rounds an engine pairs is not affected.
  A sweep of every engine (Swiss, seeded Swiss, single elimination with two
  legs, re-seeding or a third-place tie, double elimination with one or two
  legs, the round-robin engine with one or two legs), 2 to 16 entrants,
  with and without withdrawals, each round recorded with `withRoundPaired()`
  and read again from JSON, refused none of 5,321 rounds.
- An application that draws a round by hand with a participant in it twice
  has that call refused now, and a state it stored that way no longer loads.
  It is rebuilt from the round as it was meant, through the verbs or
  `fromHistory()`.
- No line of `tests/Fixtures/stored-by-0.2/` holds such a round, so no
  stored case joins the list of those no longer read.
- `tests/Feature/StageHistoryTest.php` changes each round every engine pairs
  in the ways above and shows that `withRoundPlayed()`, `withRoundPaired()`,
  `fromArray()` and `fromJson()` refuse a change exactly where
  `fromHistory()` does, with the same reason, message and context, and
  accept a further leg of a tie as `fromHistory()` does.
