# Active Context: Tactician

## Current Work Focus
- **Roadmap Phases 1 and 2 are complete** (see docs/ROADMAP.md): round robin core plus Swiss pairing, single/double elimination brackets, group stages, standings/tiebreakers, and JSON serialization all shipped with full CI (Pest, PHPStan level 8, Rector, CS-Fixer, example smoke-runs)
- A high-effort code review of the feature work surfaced 10 confirmed defects; all were fixed. Notable: Swiss withdrawal support, round-parity home/away role alternation in the round-robin generator, conflicting/round-less elimination result rejection, and group-play completeness checks before knockout qualification
- Documentation was audited end-to-end: README, ROADMAP, ARCHITECTURE, USAGE, CONTRIBUTING, and BACKGROUND all match the shipped code, and every docs/example snippet has been executed

## Next Steps
- **Phase 3 (docs/ROADMAP.md)**: the algorithm-neutral core — `ExpectedSchedule`/`AlgorithmPlan` abstraction, plan-driven generation via `LegStrategyInterface::planGeneration()` (currently consulted only for its satisfiability preflight), and a unified interface for the results-driven engines. This reshapes public APIs and needs a dedicated design pass before implementation.
- Backtracking generation for constraint configurations the greedy generator cannot satisfy (known limitation, recorded in ROADMAP Phase 5)

## Active Decisions and Considerations
- Two generation models deliberately coexist: whole-schedule generators (round robin, simple Swiss) and results-driven engines (Swiss pairing, brackets, groups). Do not force them into one interface ahead of the Phase 3 design pass.
- Constraints are hard filters with loud, diagnostic failure; soft/preference constraints are intentionally unsupported.
- The greedy generator retries bounded rotated orderings when constraints reject a schedule; configurations that fail every rotation throw `IncompleteScheduleException` even when satisfiable in principle.
- `NoRepeatPairings` scopes to the current leg by default (`acrossLegs: true` for the strict variant) — multi-leg tournaments repeat pairings per leg by design.

## Learnings and Project Insights
- **Documentation and examples rot into bugs here.** Three examples shipped fatal errors from stale APIs, and a wrong constructor sample in ARCHITECTURE.md matched an actual shipped bug. Countermeasures now in place: `tests/Feature/ExamplesTest.php` auto-validates every example, `composer ci` smoke-runs them, and the rule (AGENTS.md) is to execute every doc snippet before committing it.
- Tests that pin observed behavior rather than intent can entrench bugs — a test once asserted the broken cross-leg `NoRepeatPairings` semantics as correct.
- Property/invariant tests (elimination match counts and loss counts, round-robin pairing multiplicity) catch what example-pinning tests miss; prefer extending `tests/Feature/EliminationInvariantsTest.php` and `tests/Feature/ScheduleCompletenessTest.php` when touching generation logic.
- Immutable-context copying inside generation loops caused an O(events²) blowup once; batch context updates per round, not per event.

## Status
- **Last Updated**: 2026-07-03
