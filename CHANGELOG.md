# Changelog

All notable changes to Tactician are recorded in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
with the 0.x allowance described in the README's
[Versioning and stability](README.md#versioning-and-stability) section.

A patch release never changes a correct output for a fixed input and seed. Where
a release changes an output that was itself broken, the entry appears under the
heading **Output change (fix)**.

## [Unreleased]

No library behavior changes: nothing under `src/` has changed since 0.2.0, and
generated output for a fixed input and seed is identical.

### Added

- This changelog, the versioning and stability policy in the README, and the
  release checklist in `docs/RELEASING.md`, with tests
  (`tests/Feature/VersioningDocumentationTest.php`) that check them against
  the repository.
- Golden-output tests (`tests/Feature/GoldenOutputTest.php`) that pin generated
  schedules, bracket pairings, repack assignments, and the JSON wire shapes
  against text fixtures in `tests/Fixtures/golden/`, captured from 0.2.0. The
  development-only `composer golden-update` script regenerates them.
- Optional, tracked settings in `.claude/` for contributors who use an AI
  coding agent: a hook that formats each PHP file the agent edits and analyses it when it is
  under `src/` or `tests/` (`tests/Feature/AgentHookTest.php` covers it), and `verify` and `release`
  commands. The directory is not part of the installed package.
- A test (`tests/Feature/DocumentationSnippetsTest.php`) that executes every
  `php` code block of `README.md` and `docs/USAGE.md`, each in a PHP process of
  its own under `E_ALL`. A block that does not parse, throws, or emits a
  warning or deprecation fails the suite, as does one that stops before its
  last line. The values and printed output the two documents state are pinned
  in the same test.
- Checked results for every example. Each script in `examples/` now computes
  a named set of results and hands it to one shared renderer
  (`examples/support/Example.php`), which shows it as text on the command line
  and as a page under a web server. The suite asserts on those results what
  each example is there to demonstrate, pins them as text in
  `tests/Fixtures/golden/examples/`, and fails for an example that has no
  checked results. A change to the pages' markup touches no fixture.
- Two examples: a double-elimination bracket with a grand-final reset
  (`examples/20-double-elimination.php`) and a standings table with a chain of
  tiebreakers (`examples/21-standings-and-tiebreakers.php`).
- A test that fails when a script in `examples/` is missing from
  `examples/README.md` or `examples/index.php`, or when either lists a script
  that does not exist.
- A time limit on each block the documentation-snippet test executes: a block
  that never returns is stopped and fails by name instead of hanging the
  suite.
- A documentation step in the release checklist (`docs/RELEASING.md`).
- Governance files: a security policy (`SECURITY.md`), code owners, a pull
  request template with a compatibility section, and issue forms for bug
  reports and feature requests.

### Changed

- `docs/CONTRIBUTING.md` describes the current checks and rules, and states
  one branch and commit convention.
- The repack scenario test fixture is now a synthetic instance.
- The CI workflow runs with least-privilege permissions and pinned actions, and
  Dependabot keeps the actions up to date.
- `AGENTS.md` is now the single guide for contributors and AI coding agents,
  corrected against the code. `docs/ROADMAP.md` marks every phase as shipped,
  lists schedule repacking, and gains sections for known limitations and
  deferred work.
- Every PHP file now declares `strict_types=1`. Six test files and the
  PHP-CS-Fixer configuration did not; a test now checks all of them.
- The CI workflow runs the test and coverage jobs for every change set. Its
  documentation-only fast path is removed: the paths filter behind it matched
  every file, so it never skipped anything, and the suite now executes
  documentation, so a documentation-only change must be tested.
- Every code block in `README.md` and `docs/USAGE.md` now runs as written:
  imports and the values a block depends on are shown, and inline value
  comments match what the code produces.
- `README.md` has one feature list instead of two. It now covers everything
  that has shipped, including schedule repacking, timeline assignment,
  schedule quality and backtracking generation, and each entry links to its
  section of the usage guide.
- Every example runs both on the command line and in a browser; there are no
  longer separate browser and command-line examples. The example pages no
  longer load a script from another host. `examples/README.md` and
  `examples/index.php` list all 21 examples.

### Removed

- The editor-specific agent rule files and the session-notes directory at the
  repository root. Their decisions that still hold are now in `AGENTS.md`,
  `docs/ROADMAP.md`, and two decision records in the new `docs/adr/`; the
  statements that no longer matched the code are gone. None of these files
  was part of the installed package.

### Fixed

- Documentation only; the library is unchanged.
  - The custom-constraint sample in `docs/USAGE.md` was a parse error (an arrow
    function with a block body).
  - The "Corporate Team Building Tournament" sample threw
    `IncompleteScheduleException`: its constraint forbade pairings a round
    robin requires. It is now a Swiss schedule, which the rule fits.
  - The "Gaming Tournament with Skill Brackets" sample passed string skill
    names to `MetadataConstraint::requireAdjacentValues()`, which ignores
    non-numeric values, so the rule it described was never applied. It now uses
    numeric tiers on a Swiss schedule, with the entrants in an order in which
    the rule changes the pairings.
  - The rest-period sample described `MinimumRestPeriodsConstraint` as rest
    between a participant's matches. The constraint spaces repeat meetings of
    the same pair, and the sample now has two legs so that it applies.
  - `README.md`, `docs/USAGE.md`, `docs/ARCHITECTURE.md` and
    `examples/README.md` claimed that iterating a schedule is lazy or
    memory-efficient. A `Schedule` holds all of its events in memory; it is
    iterable and countable.
  - `README.md` called the library "production ready" and its round-robin
    roles "balanced". The library is at 0.x with experimental namespaces, and
    the round-robin generator bounds the home/away split without making it
    equal; the README, the roadmap and the glossary now say so.
  - Examples that showed something the code does not do:
    - `examples/06-rest-periods.php` presented `MinimumRestPeriodsConstraint`
      as rest between a participant's matches, on a single-leg schedule where
      the constraint never applies. It now uses two legs and shows the gap
      between the two meetings of each pair.
    - `examples/08-custom-constraints.php` described hard constraints as
      preferences ("soft constraint", "prefer"), and its first rule covered
      rounds in which the pairing never fell. The rules it shows now change
      the schedule or fail, and the text says which.
    - `examples/10-complex-tournament.php` used `rand()` inside constraints to
      imitate a preference, so its result was not repeatable. Its constraints
      are now deterministic and each one is shown to hold.
    - `examples/09-multi-leg-home-away.php` shuffled legs with an unseeded
      randomizer and drew a league table from random scores; it now passes a
      seeded randomizer, and standings have their own example (21).
    - `examples/12-performance-patterns.php` built its sample data with
      `rand()` and read a metadata key that does not exist. It now reports
      exact counts and marks its timings as measured; the memory figures are
      gone.
    - `examples/04-basic-constraints.php` called `noRepeatPairings()` the
      constraint that makes each pair meet once; a round robin does that
      without it, and the example now says so.

## [0.2.0] - 2026-08-11

Adds schedule repacking: the library can now repair an existing schedule as
well as generate one. The release is additive; no existing API changed.

### Added

- **Schedule repacking** (`MissionGaming\Tactician\Repack`). Given movable
  events (an opaque id plus two participants), optional pinned events that may
  not move, and a declarative session grid, `ScheduleRepacker` assigns every
  movable event a `(session, slot)` position so that nobody is double-booked
  and each participant's events within a session run back to back where
  possible.
- `SessionGrid`: explicit ordered zoned session starts, a slot interval,
  per-session slot counts, and per-slot concurrency. Irregular by design,
  unlike the cadence of `TimelineDefinition`.
- `RepackOutcome`, which carries itemized violations (unplaced events, interior
  gaps, late starts, exceeded capacity) as structured data instead of throwing.
  This is the one sanctioned exception to the library's loud-failure rule.
  `RepackOptions(throwOnViolations: true)` opts back into throwing through the
  new `RepackViolationsException`.
- Seeded, step-bounded solving configured through `RepackOptions`. Kickoffs
  serialize in the timeline family's UTC format.
- Repack documentation in `docs/USAGE.md` (with glossary entries),
  `docs/ARCHITECTURE.md`, and the design log `docs/design/schedule-repack.md`,
  plus the runnable example `examples/19-repacking-a-season.php`.

The repacker shipped with the following behavior, settled in review before the
release:

- `no_slot_available` is literal: events the planner rejects re-enter the final
  free-position sweep before being reported unplaceable.
- The parity bitmask machinery is capped at 20 slots per session; wider
  sessions fall back to the greedy path instead of an exponential search.
- Violations are emitted in the documented kind-then-scope order, and
  `CapacityExceeded` has no session-scoped shape.
- The repack usage snippet, example, and tests use the PHP 8.3-compatible
  `(new ScheduleRepacker())->` form. The PHP 8.4-only `new`-chaining form was
  replaced before the release and never shipped.

## [0.1.1] - 2026-07-04

### Fixed

- The author email in `composer.json` now uses the `missiongaming.gg` domain.
  No code or API changes.

## [0.1.0] - 2026-07-04

First published release: a dependency-free tournament scheduling library for
PHP 8.3+.

### Added

- **Round robin**: single and multi-leg schedules (mirrored, repeated, and
  shuffled leg strategies), with bounded rotation retries and optional
  deterministic backtracking over round decompositions.
- **Swiss pairing**: the results-driven `SwissPairingEngine`, with a
  `SwissScheduler` preset.
- **Elimination**: single and double elimination engines configured through
  `EliminationOptions`.
- **Group stages**: pool distribution, progression selectors, and composition
  validation.
- **Standings**: `StandingsCalculator` with a configurable `PointsSystem` and
  pluggable tiebreakers.
- **Constraints**: the `ConstraintSet` builder; constraints are hard filters
  that fail loudly with diagnostics instead of silently dropping matches.
- **Timeline**: deterministic UTC kickoff assignment that decorates events
  into scheduled schedules.
- **Schedule quality**: `ScheduleScorer`, which composes weighted quality
  metrics (role balance, role streaks, rest spread, pairing spacing), and
  `ScheduleOptimizer`, which keeps the best of N seeded candidate schedules.
- **Immutable DTOs**: readonly value objects with `toArray()`/`fromArray()`;
  `Schedule` round-trips JSON.

[Unreleased]: https://github.com/mission-gaming/tactician/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/mission-gaming/tactician/compare/v0.1.1...v0.2.0
[0.1.1]: https://github.com/mission-gaming/tactician/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/mission-gaming/tactician/releases/tag/v0.1.0
