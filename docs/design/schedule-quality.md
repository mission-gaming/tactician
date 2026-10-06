# Design note: Schedule Quality and Optimization

**Status: IMPLEMENTED** in 0.1.0 (Phase 5 of the roadmap): metrics and
best-of-N sampling. Search algorithms behind the scorer are
[deferred](../ROADMAP.md#deferred-work).

## Position

Constraints are hard filters: a schedule either satisfies them or fails
generation. But many properties worth caring about are graded, not
binary — how evenly roles alternate, how uniformly repeat meetings are
spaced across legs, how regular each participant's appearance rhythm is.
Two valid schedules can differ a lot on these, and the greedy generator
picks whichever its rotation order happens to produce first.

**Quality metrics** measure those graded properties; **optimization**
generates several valid candidates and keeps the best-scoring one. The
split keeps the library's mechanism/policy line: metrics are mechanism,
which metrics matter (and their weights) is application policy.

## Settled decisions

- **Lower is better, zero is ideal** — one convention for every metric
  (`QualityMetric::measure(Schedule): float`), so weighted composition
  needs no per-metric direction flags. Metrics measure defects.
- **Built-ins ship for the properties the library already names**: role
  imbalance (`RoleBalanceMetric`), broken role alternation
  (`RoleStreakMetric`), irregular appearance rhythm (`RestSpreadMetric`),
  and uneven repeat spacing across legs (`PairingSpacingMetric`). The
  three that read roles or pairs skip an event that does not have exactly
  two participants rather than guessing roles for it, and the three that
  read rounds skip an event without a round. `RestSpreadMetric` reads
  neither roles nor pairs and counts every participant of an event;
  `RoleBalanceMetric` reads no rounds and counts a round-less event.
- **`ScheduleScorer` composes metrics with weights** and reports
  per-metric values alongside the weighted score, so a chosen schedule is
  explainable, not just "best".
- **Optimization is best-of-N sampling, not search.** The generators are
  already deterministic-given-a-randomizer; `ScheduleOptimizer` derives a
  child seed per sample from one master `Randomizer`, calls a
  caller-supplied `callable(Randomizer): Schedule`, scores each candidate,
  and keeps the winner (ties break to the earliest sample). Same master
  seed on a fresh master randomizer, same result (the optimizer draws
  from the master on every call, so a second `optimize()` on the same
  object samples other seeds). Smarter algorithms (local search,
  annealing) could sit behind the same scorer without changing any metric.
- **Failed samples are skipped, not fatal** — a sample whose generation
  throws `IncompleteScheduleException` (e.g. a shuffled order no rotation
  can fix) is recorded in the result's sample accounting; only zero valid
  candidates is an error. The result says how many samples produced
  schedules.
- **Whole-schedule generators only.** Results-driven engines (Swiss,
  elimination) cannot be optimized up front — their rounds depend on
  results that do not exist yet. Their quality levers stay where they
  are: pairing rules and options.
