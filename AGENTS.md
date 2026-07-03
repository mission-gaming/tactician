# Tactician — Agent Guide

Tournament scheduling library for PHP 8.3+: round robin (single and
multi-leg), Swiss pairing, single/double elimination, group stages, and
standings with tiebreakers. No production dependencies.

## Commands

- `composer test` — Pest suite (includes automatic example validation)
- `composer ci` — normalize check + PHPStan (level 8) + Rector + CS-Fixer + tests + example smoke-run; must be green before any commit
- `composer cs-fixer-fix` / `composer rector-fix` — auto-fix style and modernization findings
- `composer examples` — smoke-run every script in `examples/`
- `vendor/bin/pest tests/Unit/Scheduling/RoundRobinSchedulerTest.php` — run a single test file

## Architecture

See `docs/ARCHITECTURE.md` for the full picture. Orientation:

- `src/DTO/` — immutable readonly value objects (`Participant`, `Event`, `Round`, `Schedule`, `Result`); all support `toArray()`/`fromArray()`, and `Schedule` round-trips JSON
- `src/Stage/` — stage plans (`StagePlan`, `PairwisePlan`, `RoundRobinPlan`, `SwissPlan`): the algorithm's up-front declaration of a stage's shape, consumed by context, validation, diagnostics, and constraints — nothing infers shape from round-robin formulas
- `src/Scheduling/` — whole-schedule generators (`RoundRobinScheduler`, `SimpleSwissScheduler`) and results-driven engines (`SwissPairingEngine`, `SingleEliminationEngine`, `DoubleEliminationEngine`, `GroupStageEngine`); `SchedulingContext` carries the stage plan
- `src/Constraints/` — `ConstraintSet` builder plus the constraint implementations
- `src/Standings/` — `StandingsCalculator`, `PointsSystem`, pluggable tiebreakers
- `src/Validation/`, `src/Diagnostics/`, `src/Exceptions/` — plan-driven completeness validation and diagnostic failure reporting

Two generation models coexist: schedulers produce complete schedules up
front; engines resolve tournament state from recorded results on every call
(Swiss rounds and bracket progression cannot exist before results). Record
results against the events the engines produce — round numbers are 1-based,
continuous across legs, and assigned by the engine.

## Terminology and verbiage

The glossary in `docs/USAGE.md` ("Terminology") is canonical. In particular:
say **participant(s)**, never "team(s)", in library code, API naming, and
documentation prose — participants can be players, clubs, squads, or anything
that competes (domain-specific sample data in examples may naturally use
teams). **Legs** is the default term for the number of times each participant
meets each other participant; **rounds** are the 1-based, cross-leg-continuous
sets of concurrent events. Swiss has rounds but no legs.

## Rules

- Every PHP file declares `strict_types=1`; DTOs are readonly; PHPStan level 8 with zero errors is mandatory.
- **Every feature ships fully documented** — a human or an LLM must be able to understand and use it without reading the source: usage docs with executed snippets, contracts in docblocks (not restated signatures), and glossary entries for new terms.
- **Every feature and path carries automated tests** unless a genuine technical or harness limitation prevents it; record the reason next to the gap so absence is distinguishable from oversight. The engines have property/invariant tests (`tests/Feature/EliminationInvariantsTest.php`, `tests/Feature/ScheduleCompletenessTest.php`) — extend those when touching generation logic rather than only pinning single examples.
- **No example ships without validation**: `tests/Feature/ExamplesTest.php` auto-runs every script in `examples/` under full error reporting. If you add an example, it is covered automatically and must pass. Adding runnable examples for valuable new capabilities is encouraged (optional).
- **Execute documentation snippets before committing them.** Stale, never-run docs and examples have caused real bugs in this repo (a wrong constructor sample in the architecture docs matched an actual shipped bug). If a README/docs snippet changes, run it.
- Never commit directly to `main` — branch and open a PR. Prefer small, single-purpose commits with descriptive messages.
- Constraints are hard filters evaluated during greedy generation; a complete round robin needs every pair to meet, so a constraint that forbids some pairing fails generation loudly (`IncompleteScheduleException` with diagnostics) rather than silently dropping matches. The scheduler retries bounded rotated orderings before giving up.

## Memory bank

`memory-bank/` holds session context following the Cline memory-bank
convention (see `.clinerules`). Read `projectbrief.md` and
`activeContext.md` for orientation; update `activeContext.md` and
`progress.md` after significant work. Keep them lean — link to `docs/` and
`docs/ROADMAP.md` rather than duplicating them, because duplicated detail
rots (every stale-doc bug in this repo came from exactly that).
