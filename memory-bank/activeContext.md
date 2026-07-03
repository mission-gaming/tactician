# Active Context: Tactician

## Current Work Focus
- **Roadmap Phases 1 and 2 are complete** (see docs/ROADMAP.md): round robin core plus Swiss pairing, single/double elimination brackets, group stages, standings/tiebreakers, and JSON serialization all shipped with full CI (Pest, PHPStan level 8, Rector, CS-Fixer, example smoke-runs)
- A high-effort code review of the feature work surfaced 10 confirmed defects; all were fixed. Notable: Swiss withdrawal support, round-parity home/away role alternation in the round-robin generator, conflicting/round-less elimination result rejection, and group-play completeness checks before knockout qualification
- Documentation was audited end-to-end: README, ROADMAP, ARCHITECTURE, USAGE, CONTRIBUTING, and BACKGROUND all match the shipped code, and every docs/example snippet has been executed

## Next Steps
- **Phase 3 implementation is underway** per the accepted design (`docs/design/phase-3-algorithm-neutral-core.md`). Milestones 1 (StagePlan), 2 (typed options + `RankingStrategy`), and 3 (engine unification: serializable `StageState`, `RoundPairing`, `StageEngineInterface`/`StageOutcome`, Swiss engine conforming, `SwissScheduler` preset replacing `SimpleSwissScheduler`) are done. Next: M4 (progression selectors, composition validator, `PoolDistributor`, `GroupStageEngine` retirement, elimination engines as presets, two-legged ties), then sweep (M5).
- Backtracking generation for constraint configurations the greedy generator cannot satisfy (known limitation, recorded in ROADMAP Phase 5)

## Active Decisions and Considerations
- The Swiss engine now conforms to `StageEngineInterface`; the elimination and group engines keep their participants-and-results signatures until M4 rebuilds them as presets over composed single-round stages. StageState records pairings (not just results), which is what makes the results-free `SwissScheduler` preset avoid repeats.
- Stage plans never fabricate shape facts: null legs = concept does not apply (Swiss); null totals = unknowable up front. Consumers wanting display defaults write `?? 1` at their own edge.
- Timeline assignment (Phase 4) stays per-stage and consumes `Schedule::getEventsByRound()`; nothing in Phase 3 may block it — plans carrying round structure keep that bridge intact.
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
