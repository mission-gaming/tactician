# 0001. Re-seeded brackets rank survivors from earlier rounds only

Status: accepted

## Context

A results-driven engine keeps no state of its own. Every call resolves the
whole stage again from the recorded `StageState`, so asking for round N must
give the same pairing before any result of round N is recorded and after all
of them are.

With `EliminationOptions(reseedEachRound: true)`, `SingleEliminationEngine`
re-ranks the survivors by standings before each round after the first and
folds them again. If that ranking read every recorded result, the results of
round N would change the ranking that produced round N, and the replay would
pair the round differently from the pairing those results were recorded
against.

## Decision

The ranking that seeds round N uses only results from rounds before N.
`SingleEliminationEngine::rankByStandings()` takes the round number and drops
every result whose event is in that round or a later one before it calculates
standings. `DoubleEliminationEngine` rejects the option, so the rule has one
implementation.

## Consequences

- Replaying a re-seeded bracket is stable: a round's pairing does not move
  when its own results arrive. `tests/Feature/EliminationInvariantsTest.php`
  plays re-seeded brackets through to the end.
- A result must carry the round of the event it was recorded against. Record
  results against the events the engine produced.
- Any new re-ranking step in an engine has to follow the same rule: rank from
  what was known when the round was paired.
