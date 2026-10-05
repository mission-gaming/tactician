# Active Context: Tactician

## Releases
- **v0.2.0** (2026-08-11, tag on the PR #25 merge commit) — schedule repacking (`src/Repack/`) plus review hardening; additive, no existing APIs changed. Notes: https://github.com/mission-gaming/tactician/releases/tag/v0.2.0
- **v0.1.1** (2026-07-04) — composer.json author email fix.
- **v0.1.0** (2026-07-04) — first published release, all five roadmap phases.

## Current Work Focus
- **Documentation snippets are executed by the suite**: `tests/Feature/DocumentationSnippetsTest.php` runs every `php` block of `README.md` and `docs/USAGE.md` (rules and markers: `tests/Support/DocumentationSnippets.php`, summarised in `AGENTS.md`). The plain-text examples (13 to 19) have their output pinned as golden files. The values and output those documents state are pinned in the same test ("Documented values"). CI runs the test and coverage jobs for every change set: the docs-only fast path never skipped anything and is removed (`tests/Feature/CiConfigurationTest.php` keeps a path or job condition from coming back).
- **Governance files added**: `SECURITY.md`, `.github/CODEOWNERS`, a pull request template, and issue forms. `AGENTS.md` and `docs/CONTRIBUTING.md` state the one branch and commit convention; `tests/Feature/GovernanceFilesTest.php` checks the files.
- **Versioning policy published**: `CHANGELOG.md`, the README "Versioning and stability" section, and the release checklist in `docs/RELEASING.md`. Every change adds an `Unreleased` changelog entry; `tests/Feature/VersioningDocumentationTest.php` checks the documents against the repository.
- **Schedule repacking shipped and released in v0.2.0** (2026-08-11, PR #25, merged to `main`): `src/Repack/` repairs an existing schedule onto an irregular `SessionGrid` — no double-booking ever, per-session contiguity satisfied or reported, pins immovable. Driven by an external brief with a synthetic reference fixture (`tests/Fixtures/repack-scenario.json`); the reference instance repacks clean and the mis-pinned variant reports `CapacityExceeded` (Fallowmead, shortfall 3). **Deliberate contract deviation**: infeasibility returns a `RepackOutcome` with structured violations rather than throwing. All decisions from the overnight autonomous run are logged in `docs/design/schedule-repack.md`.
- **Pre-merge review hardening** (2026-08-11, medium `/code-review --fix`, the last 9 of PR #25's 12 commits): fixed PHP 8.4-only `new`-chaining in docs/example/tests (library must parse on 8.3), made `no_slot_available` literal (planner-rejected events re-enter the final sweep), capped parity bitmask reasoning at 20 slots per session (falls back to greedy beyond), fixed violation kind-then-scope ordering, removed `CapacityExceeded`'s never-constructed session scope, aligned kickoff serialization with the timeline family's UTC format, and added edge coverage (throw path, audit detector, DTO shapes, grid validation). Three cleanups were deliberately skipped — see Next Steps.

## Previous Work Focus
- **Roadmap Phases 1 and 2 are complete** (see docs/ROADMAP.md): round robin core plus Swiss pairing, single/double elimination brackets, group stages, standings/tiebreakers, and JSON serialization all shipped with full CI (Pest, PHPStan level 8, Rector, CS-Fixer, example smoke-runs)
- A high-effort code review of the feature work surfaced 10 confirmed defects; all were fixed. Notable: Swiss withdrawal support, round-parity home/away role alternation in the round-robin generator, conflicting/round-less elimination result rejection, and group-play completeness checks before knockout qualification
- Documentation was audited end-to-end: README, ROADMAP, ARCHITECTURE, USAGE, CONTRIBUTING, and BACKGROUND all match the shipped code, and every docs/example snippet has been executed

## Next Steps
- **All five roadmap phases are complete** (see `docs/ROADMAP.md` for the per-phase detail — this file deliberately does not duplicate it):
  - Phase 3: algorithm-neutral core (stage plans, typed options, engines, compositions).
  - Phase 4: timeline assignment (slot model, time-aware rules, named resources).
  - Phase 5: backtracking generation, quality metrics + best-of-N optimization, constraint attribution diagnostics, and framework integration guides (`docs/integrations/` — Symfony as the centrepiece, Laravel mirror, example 18 for the stateless request-cycle pattern).
- **Future work is demand-gated**: cross-stage clash validation, per-resource availability windows, smarter optimization algorithms behind the existing scorer — and downstream integration work, now that the roadmap is done and repacking is released in v0.2.0.
- Repack follow-ups deliberately deferred from the review, so intentional rather than overlooked: DTO merge/shared base and `LoadPlanner` memoization — itemised with the reason in `progress.md` ("What's Left to Build").

## Active Decisions and Considerations
- All results-driven engines conform to `StageEngineInterface`. **Position is authoritative** for stage entry: brackets fold and pools deal by list position, never by carried seed attributes. StageState records pairings (not just results), which powers results-free scheduling and repeat avoidance.
- Two-legged ties: legs carry `tie_leg` event metadata; a level aggregate must be decided app-side via `tie_winner` metadata on a leg result (`TieDecision`). Reseed mode ranks strictly from results of earlier rounds so bracket replay is stable (property tests caught this).
- Stage plans never fabricate shape facts: null legs = concept does not apply (Swiss); null totals = unknowable up front. Consumers wanting display defaults write `?? 1` at their own edge.
- Timeline assignment (Phase 4) stays per-stage and consumes `Schedule::getEventsByRound()`; nothing in Phase 3 may block it — plans carrying round structure keep that bridge intact.
- Constraints are hard filters with loud, diagnostic failure; soft/preference constraints are intentionally unsupported.
- The greedy generator retries bounded rotated orderings when constraints reject a schedule; configurations that fail every rotation throw `IncompleteScheduleException` even when satisfiable in principle.
- `NoRepeatPairings` scopes to the current leg by default (`acrossLegs: true` for the strict variant) — multi-leg tournaments repeat pairings per leg by design.
- Generated output is pinned by golden fixtures captured from `v0.2.0` (`tests/Fixtures/golden/`): a golden diff is an output change that needs a changelog entry, never a fixture to regenerate quietly (`AGENTS.md`, `composer golden-update`).

## Learnings and Project Insights
- **Documentation and examples rot into bugs here.** Three examples shipped fatal errors from stale APIs, and a wrong constructor sample in ARCHITECTURE.md matched an actual shipped bug. Countermeasures now in place: `tests/Feature/ExamplesTest.php` auto-validates every example, `composer ci` smoke-runs them, and `tests/Feature/DocumentationSnippetsTest.php` executes the README and usage-guide snippets (other docs are still run by hand).
- Tests that pin observed behavior rather than intent can entrench bugs — a test once asserted the broken cross-leg `NoRepeatPairings` semantics as correct.
- Property/invariant tests (elimination match counts and loss counts, round-robin pairing multiplicity) catch what example-pinning tests miss; prefer extending `tests/Feature/EliminationInvariantsTest.php` and `tests/Feature/ScheduleCompletenessTest.php` when touching generation logic.
- Immutable-context copying inside generation loops caused an O(events²) blowup once; batch context updates per round, not per event.

## Status
- **Last Updated**: 2026-10-05
