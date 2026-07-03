# Active Context: Tactician

## Current Work Focus
- **Roadmap Phases 1 and 2 are complete** (see docs/ROADMAP.md): round robin core plus Swiss pairing, single/double elimination brackets, group stages, standings/tiebreakers, and JSON serialization all shipped with full CI (Pest, PHPStan level 8, Rector, CS-Fixer, example smoke-runs)
- A high-effort code review of the feature work surfaced 10 confirmed defects; all were fixed. Notable: Swiss withdrawal support, round-parity home/away role alternation in the round-robin generator, conflicting/round-less elimination result rejection, and group-play completeness checks before knockout qualification
- Documentation was audited end-to-end: README, ROADMAP, ARCHITECTURE, USAGE, CONTRIBUTING, and BACKGROUND all match the shipped code, and every docs/example snippet has been executed

## Next Steps
- **Phase 3 (algorithm-neutral core) is complete** — all five milestones of `docs/design/phase-3-algorithm-neutral-core.md` shipped: StagePlan, typed options + `RankingStrategy`, the stage engine model, compositions (pools/selectors/validator/elimination presets/two-legged ties), and the sweep.
- **Phase 4 (timeline assignment) is underway** — the first cut is implemented: `src/Timeline/` with `TimelineDefinition` (declarative per-stage slot model, config-constructible, DST-safe wall-clock arithmetic, UTC out), `TimelineAssigner` (deterministic filling over `Schedule::getEventsByRound()` and `RoundPairing`), and serializable `ScheduledEvent`/`ScheduledSchedule` decorations. One event per slot in this cut.
- **Next within Phase 4**: time-aware constraints (hour-based rest, blackout windows — needs constraints to see assigned times), then the open questions: cross-stage clash validation (❓ in the design note) and venue/resource modelling with slot capacity.
- Backtracking generation for constraint configurations the greedy generator cannot satisfy (known limitation, recorded in ROADMAP Phase 5)

## Active Decisions and Considerations
- All results-driven engines conform to `StageEngineInterface`. **Position is authoritative** for stage entry: brackets fold and pools deal by list position, never by carried seed attributes. StageState records pairings (not just results), which powers results-free scheduling and repeat avoidance.
- Two-legged ties: legs carry `tie_leg` event metadata; a level aggregate must be decided app-side via `tie_winner` metadata on a leg result (`TieDecision`). Reseed mode ranks strictly from results of earlier rounds so bracket replay is stable (property tests caught this).
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
