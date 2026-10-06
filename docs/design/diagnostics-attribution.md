# Design note: Constraint Attribution Diagnostics

**Status: IMPLEMENTED** — ROADMAP Phase 5's "advanced diagnostic
reporting and constraint suggestion systems".

## Position

When generation fails, the library already says *that* it failed and
*what* is missing (the violation collector's final-attempt rejections,
the plan-derived missing pairings). What it could not say is **which
constraint blocks which pairing where** — `SchedulingDiagnostics`
shipped with its deep-analysis methods as documented stubs, and the
diagnostics class was not wired into the failure path at all.

Attribution is answerable by probing: constraints are pure predicates
over an event and a context, so for every missing pairing we can ask
each constraint directly — "would you accept this pairing in round r,
in either orientation, given everything that was actually generated?" —
and report the answers instead of guessing from constraint names.

## Settled decisions

- **Probe, don't parse.** Attribution builds hypothetical events (both
  orientations, every candidate round) and evaluates the real
  constraints against the real partial context. No name matching, no
  per-constraint special cases; custom constraints are attributed
  exactly like built-ins.
- **Three findings, three vocabularies**:
  - *Impossible pairings* — blocked in every round and orientation,
    with the constraints that reject everywhere named as culprits.
  - *Constraint attribution* — per constraint, which missing pairings
    it rejects and in how many of the candidate rounds ("No Repeat
    Pairings rejects Alice vs Bob in 6 of 6 rounds").
  - *Structural fullness* — a pairing whose only allowed rounds are
    already full is blocked by arithmetic, not by any constraint; the
    report says so rather than blaming nothing.
- **Wired into the loud failure.** `IncompleteScheduleException`
  optionally carries a `DiagnosticReport`; the round-robin scheduler
  attaches one (built from the actual partial events) at its generation
  failure sites, and `getDiagnosticReport()` renders the attribution
  sections. Diagnostics remain independently callable.
- **Pairwise plans only, bounded cost.** Missing-pairing analysis is a
  pairwise-plan capability; the probe is
  missing pairings × rounds × orientations × (constraints + 1)
  evaluations (each candidate is put to the whole set and to each
  constraint). For 24 participants over two legs with one constraint and
  the second leg missing that is at most 276 × 46 × 2 × 2 = 50,784
  evaluations, not a few thousand: about a tenth of a second when each
  reads the context's index.
- **Once per failure.** The round-robin scheduler tries up to
  min(participants, 25) orderings and reports the last. Only that one is
  analysed: the earlier attempts' exceptions are caught and dropped, so
  nothing reads a report built for them. It used to build one per
  attempt, which was 98% of the time a failure took: 46 seconds for the
  24-participant case above, against 0.13 seconds now (PHP 8.4, no
  OPcache, one core). A schedule that succeeds on a later ordering builds
  none. `tests/Feature/FailureAnalysisCostTest.php` counts the analyses.
- **Honest scope**: probing answers "could this pairing join what was
  built?" — attribution against a *different* partial schedule could
  differ. That is the right question for the failure at hand, and the
  wording avoids claiming global unsatisfiability except when the
  search itself proved it (backtracking's exhausted-space diagnostic).
