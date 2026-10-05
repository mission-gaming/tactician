# Tactician — Agent Guide

Tournament scheduling library for PHP 8.3+: round robin (single and
multi-leg), Swiss pairing, single/double elimination, group stages, and
standings with tiebreakers. No production dependencies.

## Commands

- `composer test` — Pest suite (includes automatic example validation and execution of the documentation snippets)
- `composer ci` — normalize check + PHPStan (level 8) + Rector + CS-Fixer + tests + example smoke-run; must be green before any commit
- `composer cs-fixer-fix` / `composer rector-fix` — auto-fix style and modernization findings
- `composer examples` — smoke-run every script in `examples/`
- `composer golden-update` — regenerate the golden-output fixtures in `tests/Fixtures/golden/` (see Rules)
- `vendor/bin/pest tests/Unit/Scheduling/RoundRobinSchedulerTest.php` — run a single test file
- `vendor/bin/pest tests/Feature/DocumentationSnippetsTest.php` — execute every `php` block of `README.md` and `docs/USAGE.md`

## Architecture

See `docs/ARCHITECTURE.md` for the full picture. Orientation:

- `src/DTO/` — immutable readonly value objects (`Participant`, `Event`, `Round`, `Schedule`, `Result`); all support `toArray()`/`fromArray()`, and `Schedule` round-trips JSON
- `src/Stage/` — the stage model: plans (`StagePlan`/`PairwisePlan` with round-robin, Swiss, and elimination implementations — the algorithm's up-front shape declaration), the results-driven family (`StageState` serializable between rounds, `RoundPairing`, `StageEngineInterface`, `StageOutcome` with pooling), and composition (`PoolDistributor`, `ProgressionSelector` selectors, `CompositionValidator`, `TieDecision`)
- `src/Scheduling/` — whole-schedule generators (`RoundRobinScheduler`, and the `SwissScheduler` preset over the Swiss engine) and the `StageEngineInterface` engines (`SwissPairingEngine`; `SingleEliminationEngine`/`DoubleEliminationEngine` presets configured by `EliminationOptions`); `SchedulingContext` carries the stage plan. Group stages are compositions, not an engine. **Position is authoritative**: stages seed entrants from list position, never from carried seed attributes.
- `src/Timeline/` — the per-stage slot model (`TimelineDefinition`) and deterministic assignment (`TimelineAssigner`) decorating events with UTC kickoffs (`ScheduledEvent`/`ScheduledSchedule`); mechanism only — slot patterns come from app config
- `src/Repack/` — schedule repair (`ScheduleRepacker`): existing events (some pinned) assigned onto an irregular `SessionGrid` with no double-booking and per-session contiguity; **deliberately returns a `RepackOutcome` carrying itemised violations instead of throwing** — the one sanctioned exception to the loud-failure rule (see `docs/design/schedule-repack.md`)
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
- **No example ships without validation**: `tests/Feature/ExamplesTest.php` auto-runs every script in `examples/` under full error reporting. If you add an example, it is covered automatically and must pass. An example that prints plain text (13 onwards) also has its exact output pinned: add it to `GoldenCases::PLAIN_TEXT_EXAMPLES`, run `composer golden-update`, and review the new fixture in `tests/Fixtures/golden/examples/` — the test fails until you do. Adding runnable examples for valuable new capabilities is encouraged (optional).
- **A golden diff is an output change.** `tests/Feature/GoldenOutputTest.php` pins generated schedules, bracket pairings, repack assignments and the JSON wire shapes against the text fixtures in `tests/Fixtures/golden/` (baseline: `v0.2.0`; cases in `tests/Support/GoldenCases.php`). If a change makes it fail, the library now produces different output: explain that in the changelog, regenerate with `composer golden-update`, and commit the fixture diff with the change so it is reviewed. Never regenerate just to get back to green; a run with `UPDATE_GOLDEN=1` cannot fail, so the test refuses it on CI.
- **Every change needs an `Unreleased` changelog entry.** Add it to `CHANGELOG.md` in the same pull request, following the policy in the README's [Versioning and stability](README.md#versioning-and-stability) section; releases follow the checklist in [`docs/RELEASING.md`](docs/RELEASING.md).
- **Documentation snippets are executed by the suite.** `tests/Feature/DocumentationSnippetsTest.php` runs every fenced `php` block of `README.md` and `docs/USAGE.md` in a PHP process of its own under `E_ALL`: a block that does not parse, throws, or emits a warning or deprecation fails the build, including on a documentation-only pull request; so does a block that stops before its last line (`exit`, `die`, a top-level `return`) or turns PHP's error reporting down. Write blocks against the rules documented on `tests/Support/DocumentationSnippets.php`: the blocks under one level-1 or level-2 heading build on each other in order (two headings with the same text are two sections), and every class a section uses is imported with a `use` statement in the document. Three HTML-comment markers change how a block is treated: `<!-- snippet: skip reason="..." -->` before a deliberate sketch (the reason is mandatory, the block must still parse and must really be unable to run, and the set of skipped blocks is pinned in the test), `<!-- snippet: throws="Fully\Qualified\Exception" -->` before a block that must end in that exception, and a standalone `<!-- snippet: setup ... -->` comment holding hidden application-side stubs (it may not import or alias a class). Not covered, so still run by hand when you change them: `docs/ARCHITECTURE.md`, `docs/design/` and `docs/integrations/` (framework code). Stale, never-run docs and examples have caused real bugs in this repo (a wrong constructor sample in the architecture docs matched an actual shipped bug).
- **The dist archive carries only the library**: `src/`, `composer.json`, `LICENSE`, `README.md` and `CHANGELOG.md`. A new file or directory at the repository root needs an `export-ignore` rule in `.gitattributes`; `tests/Feature/DistArchiveTest.php` fails until that rule is committed (it archives `HEAD`, so uncommitted edits do not count).
- Never commit directly to `main` — branch and open a PR, filling in `.github/pull_request_template.md`. Prefer small, single-purpose commits.
- **One branch and commit convention.** Branch names are `<type>/<short-description>` in lower case with hyphens, where the type is `feature`, `fix`, `docs`, `test`, or `chore` (for example `docs/versioning-policy-and-changelog`). A commit subject is one imperative sentence that starts with a capital letter and ends with a period, with no type prefix (for example `Add the release checklist.`); the body says why when the subject does not. [`docs/CONTRIBUTING.md`](docs/CONTRIBUTING.md) states the same convention for contributors.
- Constraints are hard filters evaluated during greedy generation; a complete round robin needs every pair to meet, so a constraint that forbids some pairing fails generation loudly (`IncompleteScheduleException` with diagnostics) rather than silently dropping matches. The scheduler retries bounded rotated orderings before giving up; `RoundRobinOptions(backtracking: true)` additionally searches the round decompositions rotations cannot reach (deterministic, step-bounded).

## Memory bank

`memory-bank/` holds session context following the Cline memory-bank
convention (see `.clinerules`). Read `projectbrief.md` and
`activeContext.md` for orientation; update `activeContext.md` and
`progress.md` after significant work. Keep them lean — link to `docs/` and
`docs/ROADMAP.md` rather than duplicating them, because duplicated detail
rots (every stale-doc bug in this repo came from exactly that).
