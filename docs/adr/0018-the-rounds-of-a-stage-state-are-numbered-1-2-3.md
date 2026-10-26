# 0018. The rounds of a stage state are numbered 1, 2, 3

Status: accepted; amends point 8 of
[0007](0007-recorded-rounds-are-read-not-derived.md)

## Context

A stage state can be built in three ways: verb by verb
(`StageState::withRoundPlayed()`, and `withRoundPaired()`, which calls it),
from a history of the application's rows (`fromHistory()`), and from the
array `toArray()` wrote (`fromArray()`, which records the stored rounds again
through `withRoundPlayed()`).

Until this record the three disagreed on the numbers of the rounds.
`fromHistory()` required them to be 1, 2, 3 and so on. The verbs required a
round number of 1 or above that was above the last one, so they accepted
round 3 after round 1, or a first round numbered 3. `fromArray()` accepted
what the verbs accepted, and 0007 kept it that way on purpose so that data
stored by 0.2.x would keep loading.

A state with a round left out is not a state any engine can read correctly.
An engine reads the rounds as recorded and pairs the next one after the last,
so it reads the missing round as played. A Swiss stage of three planned
rounds with rounds 1 and 3 recorded reported `isComplete()` with two rounds
played, and so did a league of four under `RoundRobinEngine`. No engine pairs
such a round. Only a pairing numbered by hand produces one.

## Decision

**Every state has its rounds numbered 1, 2, 3 and so on, in the order they
were recorded.** The first round recorded is round 1, and each round after
it is the one after the last recorded (`getNextRoundNumber()`). The verbs
refuse any other round with the exception and reason `fromHistory()` gives:
`InvalidConfigurationException`, `RoundOutOfSequence`. Its context names
`last_round`, `pairing_round` and `expected_round`, and `fromHistory()` adds
`round_numbers`. A round below 1 is refused before this check, as before
(`ValueOutOfRange`).

The three ways in share one check (a private method of `StageState`):

- the verbs call it;
- `fromHistory()` calls it for each round once it has put the rounds in
  the order of their numbers;
- `fromArray()` reaches it through the verb, because it records the stored
  rounds again.

Every state the verbs build is therefore one `fromHistory()` builds.

**A stored state with a gap is refused on load.** `fromArray()` and
`fromJson()` refuse a state whose rounds leave a number out or do not start
at 1, with `RoundOutOfSequence`. This applies whatever the format version,
and so includes data 0.2.x wrote without one. This replaces the last
sentence of point 8 of 0007 for the round numbers. The other things that
point keeps accepting are unchanged: a pairing whose participants are no
longer active, and two results of one event.

The stage state form takes no new format version. Under
[0005](0005-serialized-forms-carry-a-format-version.md), a form changes when
a key is added, removed or renamed, or a value takes another type or another
meaning. None of that happens here. The keys are the same, and a round number
means what it meant. What changes is which values are accepted: a round
that leaves one out is refused. 0.2.x documented that its verbs accepted
such a round, so this is not input that was read without a check, the case
0005 names. It is a deliberate break, and a new version would not soften it:
nothing `toArray()` writes changes, so a new version would mark no
difference in the data, and a state stored by 0.2.x, which has no version,
would be read as version 1 and refused all the same. The changelog lists
the case as a breaking change.

## Consequences

- An application that recorded rounds through an engine is not affected,
  because an engine numbers each round it pairs `getNextRoundNumber()`.
- An application that numbered a round by hand, and left a number out, has
  that call refused now, and a state it stored that way no longer loads. Such
  a state was misread by every engine. It can be rebuilt with the rounds
  renumbered 1, 2, 3, through the verbs or `fromHistory()`.
- One line of `tests/Fixtures/stored-by-0.2/forms-written-by-0.2.3.jsonl`,
  the case `state swiss round gap by hand` (rounds 1 and 3), is no longer
  read. `tests/Feature/StoredByEarlierReleasesTest.php` lists it with this
  reason, and the fixture is not changed.
- `tests/Feature/StageHistoryTest.php` shows, for the Swiss engine and the
  round-robin engine, that round 3 after round 1 is refused by both verbs,
  by `fromHistory()` and by `fromArray()`, so no engine is handed the state
  it read as complete.
