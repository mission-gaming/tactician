# 0003. Multi-participant events are a goal for 2.0

Status: accepted

## Context

Tactician is meant to be independent of any game, game mode or sport. It
should schedule a football league, a chess tournament, a racing series or a
battle-royale ladder alike, knowing only the data it needs to operate and
nothing about how an application produced that data. The design principles
of the
[algorithm-neutral core](../design/phase-3-algorithm-neutral-core.md#design-principles-new-in-this-revision)
already say so: roles are positional, and nothing forecloses events with more
than two participants. That note left open when such events arrive and what
pairwise code does with one in the meantime. This record decides both.

Today the principle holds for the vocabulary and for some of the types, and
not for what the library can do:

- `DTO\Event` accepts two or more participants.
- `DTO\Result` accepts a result on any event and keys its scores by
  participant ID, but it records one winner or a draw. It cannot hold a
  finishing order.
- Nothing generates an event with more than two participants.
  `RoundRobinScheduler`, the Swiss engine and the elimination engines pair
  participants, the leg strategies return no event for anything but a pair,
  and `RoundRobinPlan`, `SwissPlan` and `EliminationPlan` report any other
  event as an integrity violation.
- `Repack\MovableEvent` and `Repack\PinnedEvent` take exactly two
  participants in their constructors.
- `StandingsCalculator` accepts a result on an event with three participants
  and answers as if it were pairwise: the winner gets a win and every other
  participant a loss, so second place and last place are the same record, and
  each participant's score against is the sum of all the others' scores.
- The quality metrics that read pairs or roles and `RoleBalanceConstraint`
  skip such an event. The timeline and the constraints that compare
  participants or their metadata work on any number.

A race or a lobby is one event with many participants that produces a
finishing order. Supporting it is a redesign of the result and standings
model. Doing it before 1.0 would hold up the pairwise formats that are in
use. Ignoring it until then would let work done now freeze assumptions that
make it harder.

## Decision

Multi-participant events are a goal for 2.0. Through 1.x the library
generates, records, ranks and repacks pairwise events only, and says so.

Until 2.0, new and changed code must not make that goal harder than it needs
to be:

- A new public type does not assume exactly two sides where a list of
  participants or a map keyed by participant ID is equally simple.
  `Result::getScores()` is the model: it is keyed by participant ID and has no
  notion of a first and a second side.
- Outside a component that is pairwise by nature, no new name encodes a pair
  (first and second, winner and loser) or one sport's roles (home and away)
  where a neutral name exists.
- A new component that is pairwise by nature says so in its name or its
  contract, as `Stage\PairwisePlan` does, and refuses an event with more than
  two participants through the failure channel its contract already has: an
  exception that implements `Exceptions\TacticianException`, or an itemised
  violation where the contract reports instead of throwing (a plan's
  `validateIntegrity()`, a `Repack\RepackOutcome`). It never answers as if
  the event were pairwise, and it does not leave the event out without saying
  so.
- Nothing is added only for the 2.0 goal: no interface, type parameter or
  option that has no use today, and no measurable cost in performance.

The test for a change is what 2.0 would need there. An addition or a rename
passes. A redesign fails.

## Consequences

- The pairwise formats reach 1.0 without waiting for a second result model.
- Reviews of new public types ask the test above. A type that fails it needs
  a reason recorded beside it.
- Existing components that neither handle nor refuse an event with more than
  two participants are brought into line before 1.0 by refusing the event,
  not by supporting it. `StandingsCalculator` is the one that returns a
  pairwise answer. The quality metrics that read pairs or roles,
  `RoleBalanceConstraint` and the leg strategies leave the event out.
  Refusing changes what an accepted input returns, so it is a breaking change
  with a changelog note and is not made as part of an unrelated change. It
  also replaces the skipping that the
  [schedule quality note](../design/schedule-quality.md#settled-decisions)
  settled on, and that note is corrected with it.
- Existing names that encode one sport's roles are not renamed one at a time.
  They are settled together at 1.0.
- The rule does not ask for speculative abstraction. Where meeting it would
  cost real complexity or speed now, the pairwise form is kept and 2.0 pays
  for the change.
