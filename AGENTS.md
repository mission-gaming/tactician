# Tactician — Agent Guide

Tournament scheduling library for PHP 8.3+: round robin (single and
multi-leg), Swiss pairing, single/double elimination, group stages, and
standings with tiebreakers, plus timeline assignment, schedule repacking and
schedule quality scoring. No production dependencies.

This file is the single guide for anyone, human or AI coding agent, working
in the repository (`CLAUDE.md` is a symbolic link to it). It states the rules
and points to `docs/` for everything else; it does not repeat what is there.

## Commands

- `composer test` — Pest suite (includes automatic example validation)
- `composer ci` — normalize check + PHPStan (level 8) + Rector + CS-Fixer + tests + example smoke-run; must be green before any commit
- `composer phpstan` / `composer rector` / `composer cs-fixer` / `composer norm` — one check of the gate on its own
- `composer cs-fixer-fix` / `composer rector-fix` / `composer norm-fix` — auto-fix style, modernization and `composer.json` ordering findings
- `composer examples` — smoke-run every script in `examples/`
- `composer golden-update` — regenerate the golden-output fixtures in `tests/Fixtures/golden/` (see Rules)
- `vendor/bin/pest tests/Unit/Scheduling/RoundRobinSchedulerTest.php` — run a single test file

## Architecture

See `docs/ARCHITECTURE.md` for the full picture. Orientation:

- `src/DTO/` — immutable value objects (`Participant`, `Event`, `Round`, `Result`, `Schedule`); all support `toArray()`/`fromArray()`, and `Schedule` round-trips JSON. The first four are `readonly` classes. `Schedule` is not: it implements `Iterator` and keeps a cursor, so only its events are readonly and `addEvent()` returns a new instance
- `src/Stage/` — the stage model: plans (`StagePlan`/`PairwisePlan` with round-robin, Swiss, and elimination implementations — the algorithm's up-front shape declaration), the results-driven family (`StageState` serializable between rounds, `RoundPairing`, `StageEngineInterface`, `StageOutcome` with pooling), and composition (`PoolDistributor`, `ProgressionSelector` selectors, `CompositionValidator`, `TieDecision`)
- `src/Scheduling/` — whole-schedule generators (`RoundRobinScheduler`, and the `SwissScheduler` preset over the Swiss engine) and the `StageEngineInterface` engines (`SwissPairingEngine`; `SingleEliminationEngine`/`DoubleEliminationEngine` presets configured by `EliminationOptions`); `SchedulingContext` carries the stage plan. Group stages are compositions, not an engine. **Position is authoritative**: stages seed entrants from list position, never from carried seed attributes.
- `src/LegStrategies/` — how the legs after the first derive from it (`LegStrategyInterface` with `MirroredLegStrategy`, `RepeatedLegStrategy`, `ShuffledLegStrategy`); each strategy states its facts to the plan through `LegPlanContribution`
- `src/Timeline/` — the per-stage slot model (`TimelineDefinition`) and deterministic assignment (`TimelineAssigner`) decorating events with UTC kickoffs (`ScheduledEvent`/`ScheduledSchedule`); mechanism only — slot patterns come from app config
- `src/Repack/` — schedule repair (`ScheduleRepacker`): existing events (some pinned) assigned onto an irregular `SessionGrid` with no double-booking and per-session contiguity; **deliberately returns a `RepackOutcome` carrying itemised violations instead of throwing** — the one sanctioned exception to the loud-failure rule (see `docs/design/schedule-repack.md`)
- `src/Constraints/` — `ConstraintSet` builder plus the constraint implementations
- `src/Standings/` — `StandingsCalculator` ordering a table by a `RankingStrategy` (`WinDrawLossRanking`, with `threeOneZero()`/`oneHalfZero()` presets), then pluggable tiebreakers (`TiebreakerInterface`: wins, Buchholz, Sonneborn–Berger)
- `src/Quality/` — lower-is-better `QualityMetric`s, the weighted `ScheduleScorer`, and `ScheduleOptimizer` (seeded best-of-N sampling over whole-schedule generators)
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

- Every PHP file in `src/` and `examples/` declares `strict_types=1`, and every new file must (six older files under `tests/` do not yet). Value objects are immutable: a change returns a new instance. PHPStan level 8 with zero errors is mandatory, and it checks exceptions: keep `@throws` accurate.
- **The code must parse and run on PHP 8.3**, the Composer floor (CI runs 8.3, 8.4 and 8.5). No syntax from a later version, in `src/`, tests, examples or documentation snippets.
- **Compatibility**: a patch release never changes a correct output for a fixed input and seed, and never changes a public signature; a 0.x minor may break, with a migration note in the changelog. The README's [Versioning and stability](README.md#versioning-and-stability) section is the policy and lists which namespaces are stable; `Repack\Internal` is not public API.
- **Output is deterministic.** `src/` never asks for the current time and uses no global random function. Randomness goes through a `Random\Randomizer` the caller passes in; with a seeded one the same input gives the same output (`ShuffledLegStrategy` is the one class that makes an unseeded `Randomizer` when it is given none). Standings order is total, so nothing derived from a table depends on input order ([ADR 0002](docs/adr/0002-standings-order-is-total.md)).
- **Every feature ships fully documented** — a human or an LLM must be able to understand and use it without reading the source: usage docs with executed snippets, contracts in docblocks (not restated signatures), and glossary entries for new terms.
- **Every feature and path carries automated tests** unless a genuine technical or harness limitation prevents it; record the reason next to the gap so absence is distinguishable from oversight. The engines have property/invariant tests (`tests/Feature/EliminationInvariantsTest.php`, `tests/Feature/ScheduleCompletenessTest.php`) — extend those when touching generation logic rather than only pinning single examples. A test states the intended behaviour; do not write the assertion from whatever the code currently returns.
- **No example ships without validation**: `tests/Feature/ExamplesTest.php` auto-runs every script in `examples/` under full error reporting. If you add an example, it is covered automatically and must pass. Adding runnable examples for valuable new capabilities is encouraged (optional).
- **A golden diff is an output change.** `tests/Feature/GoldenOutputTest.php` pins generated schedules, bracket pairings, repack assignments and the JSON wire shapes against the text fixtures in `tests/Fixtures/golden/` (baseline: `v0.2.0`; cases in `tests/Support/GoldenCases.php`). If a change makes it fail, the library now produces different output: explain that in the changelog, regenerate with `composer golden-update`, and commit the fixture diff with the change so it is reviewed. Never regenerate just to get back to green; a run with `UPDATE_GOLDEN=1` cannot fail, so the test refuses it on CI.
- **Every change needs an `Unreleased` changelog entry.** Add it to `CHANGELOG.md` in the same pull request, following the policy in the README's [Versioning and stability](README.md#versioning-and-stability) section; releases follow the checklist in [`docs/RELEASING.md`](docs/RELEASING.md).
- **Execute documentation snippets before committing them.** Stale, never-run docs and examples have caused real bugs in this repo (a wrong constructor sample in the architecture docs matched an actual shipped bug). If a README/docs snippet changes, run it.
- **The dist archive carries only the library**: `src/`, `composer.json`, `LICENSE`, `README.md` and `CHANGELOG.md`. A new file or directory at the repository root needs an `export-ignore` rule in `.gitattributes`; `tests/Feature/DistArchiveTest.php` fails until that rule is committed (it archives `HEAD`, so uncommitted edits do not count).
- Never commit directly to `main` — branch and open a PR. Prefer small, single-purpose commits with descriptive messages.
- Constraints are hard filters evaluated during greedy generation; a complete round robin needs every pair to meet, so a constraint that forbids some pairing fails generation loudly (`IncompleteScheduleException` with diagnostics) rather than silently dropping matches. The scheduler retries bounded rotated orderings before giving up; `RoundRobinOptions(backtracking: true)` additionally searches the round decompositions rotations cannot reach (deterministic, step-bounded).

## Where things are recorded

Nothing is recorded twice. A statement copied into a second file goes stale
there, and stale documentation has caused real bugs here.

- Components and how they fit: [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md)
- Why a subsystem is designed as it is: the notes in [`docs/design/`](docs/design/)
- Single decisions that no design note covers: [`docs/adr/`](docs/adr/README.md), one numbered file each (Context, Decision, Consequences)
- What has shipped, known limitations and deferred work: [`docs/ROADMAP.md`](docs/ROADMAP.md); planned work is in the GitHub milestones
- Release history: [`CHANGELOG.md`](CHANGELOG.md)

There is no session log or status file to keep up to date. If something is
worth remembering, put it in the one place above where it belongs.

## Agent tooling

`.claude/` holds optional, tracked settings for contributors who use an AI
coding agent. Nothing in the build or the test gate depends on an agent, and
none of it ships in the dist archive.

- `.claude/settings.json` allows the agent to run `composer` and `vendor/bin/` commands without asking, and registers the hook below.
- `.claude/hooks/format-and-analyse.sh` runs after the agent edits or writes a file. For a `.php` file under `src/`, `tests/` or `examples/` it runs PHP-CS-Fixer on that file, then PHPStan on it, and hands any PHPStan error back to the agent. For every other path, and when the tools are not installed, it does nothing. `tests/Feature/AgentHookTest.php` covers it. It checks one file; `composer ci` is still the gate.
- `.claude/commands/verify.md` runs `composer ci` and reports each tool's summary without fixing anything; `.claude/commands/release.md` walks [`docs/RELEASING.md`](docs/RELEASING.md) and stops before a tag is created or pushed.
- Personal settings go in `.claude/settings.local.json`, which is ignored.
