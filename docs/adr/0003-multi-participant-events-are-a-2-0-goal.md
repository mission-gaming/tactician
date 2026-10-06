# 0003. Multi-participant events are a goal for 2.0

Status: accepted

## Context

Tactician is meant to be independent of any game, game mode or sport. It
should schedule a football league, a chess tournament, a racing series or a
battle-royale ladder alike, knowing only the data it needs to operate and
nothing about how an application produced that data.

Today that holds for the vocabulary and not for the shape of a contest.
`DTO\Event` accepts two or more participants, but every plan, leg strategy
and engine, `DTO\Result` (one winner, or a draw), `Standings` and `Repack`
assume exactly two. A race or a lobby is one event with many participants
that produces a finishing order. Nothing generates such events, and
`StandingsCalculator` returns meaningless tallies when it is given one.

Supporting them is a redesign of the result and standings model. Doing it
before 1.0 would hold up the pairwise formats that are in use. Ignoring it
until then would let work done now freeze assumptions that make it harder.

## Decision

Multi-participant events are a goal for 2.0. Through 1.x the library supports
pairwise events only, and says so.

Until 2.0, new and changed code must not make that goal harder than it needs
to be:

- A new public type does not assume exactly two sides where a list of
  participants or a map keyed by participant ID is equally simple.
  `Result::getScores()` is the model: it is keyed by participant ID and has no
  notion of a first and a second side.
- No new name encodes a pair or one sport's roles (first and second, home and
  away, winner and loser) where a neutral name exists.
- A component that is pairwise by nature says so in its name or its contract,
  as `Stage\PairwisePlan` does, and rejects an event with more than two
  participants with a typed error. It never returns a wrong answer for one.
- Nothing is added only for the 2.0 goal: no interface, type parameter or
  option that has no use today, and no measurable cost in performance.

The test for a change is whether 2.0 would need an addition or a rename
there, or a redesign.

## Consequences

- The pairwise formats reach 1.0 without waiting for a second result model.
- Reviews of new public types ask the test above. A type that fails it needs
  a reason recorded beside it.
- Existing code that returns a wrong answer for an event with more than two
  participants (`StandingsCalculator` is the known case) is a defect to fix
  before 1.0 by rejecting the event, not by supporting it.
- Existing names that encode one sport's roles are not renamed one at a time.
  They are settled together at 1.0.
- The rule does not ask for speculative abstraction. Where meeting it would
  cost real complexity or speed now, the pairwise form is kept and 2.0 pays
  for the change.
