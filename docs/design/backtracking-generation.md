# Design note: Backtracking Generation

**Status: IMPLEMENTED — first cut (round robin)** — ROADMAP Phase 5's
answer to the repository's longest-recorded known limitation: greedy
circle-method generation retries bounded rotated orderings when
constraints reject a schedule, and throws `IncompleteScheduleException`
even when a valid schedule exists in principle. The circle method fixes
which pairings share a round purely by list order, so only n of the many
possible round decompositions are ever tried.

## Position

Backtracking search treats round-robin construction as what it is — a
constraint-satisfaction problem over perfect matchings:

- A leg is a decomposition of all pairings into rounds; each round is a
  perfect matching of the field (odd fields include a bye seat).
- The search builds rounds in order. Within a round it picks the lowest
  unmatched seat and tries each unused opponent, and for each pairing
  tries both orientations (role-parity-preferred first, so unconstrained
  searches reproduce the greedy generator's balanced roles); constraints
  are checked against the full context exactly as during greedy
  generation. Dead ends backtrack — first within the round, then into
  earlier rounds.

## Settled decisions

- **Opt-in, not a silent fallback.** `RoundRobinOptions(backtracking:
  true)` (config key `'backtracking'`). Greedy stays the default: it is
  fast, it fails after at most min(participants, 25) orderings and one
  failure analysis, and most configurations never need more. Enabling backtracking changes one thing — configurations the
  rotations cannot satisfy get an exhaustive (budgeted) search before the
  loud failure.
- **Greedy first, always.** With backtracking enabled the scheduler still
  runs the rotation retries first; the search only runs when they fail.
  Satisfiable-by-rotation configurations pay nothing.
- **A fixed step budget** (200,000 pairing attempts) bounds the
  exponential worst case. Exhausting it fails loudly with a
  budget-exhausted diagnostic, distinct from the search space being
  exhausted (genuinely unsatisfiable). The budget is deliberately not
  configurable in this cut.
- **The budget counts attempts, not time.** An attempt is one candidate
  event and one evaluation of the constraints, so what the budget costs
  depends on the constraints. Measured (PHP 8.4, no OPcache, one core):
  spending all of it takes 0.5 to 0.8 seconds with a constraint that
  answers in constant time, at 8 participants and at 24, and 1.8 seconds
  with `RoleBalanceConstraint::homeAway(2)` at 20 participants. The search
  keeps one path's state and changes it in place: it does not copy the
  placed events for every attempt, and a constraint that reads the history
  reads it through the context's index (see
  [ARCHITECTURE.md](../ARCHITECTURE.md#performance-considerations)).
  Before that the same role balance search took 18 seconds. The order of
  attempts and the count of steps are pinned against the search as first
  written (`tests/Unit/Scheduling/BacktrackingSearchOrderTest.php`).
- **Deterministic.** Seat order, opponent order, and orientation order
  are fixed (the optional `Randomizer` shuffles the initial field order,
  matching greedy). The same inputs always produce the same schedule or
  the same failure.
- **Multi-leg scope**: leg 1 is searched; later legs derive from leg 1's
  *actual* rounds through the leg strategy (the greedy path re-derives
  from circle order, which a backtracked leg 1 no longer has). A later
  leg rejected by constraints fails the attempt loudly — the search does
  not (yet) backtrack across leg boundaries. Recorded as a limitation.
- **Round robin only.** Swiss pairing already backtracks per round; the
  elimination presets have no search space. Extending the searched
  generation to other whole-schedule formats is future work if any grow
  one.
