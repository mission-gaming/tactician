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
  release checklist in `docs/RELEASING.md`.
- Golden-output tests (`tests/Feature/GoldenOutputTest.php`) that pin generated
  schedules, bracket pairings, repack assignments, and the JSON wire shapes
  against text fixtures in `tests/Fixtures/golden/`, captured from 0.2.0. The
  development-only `composer golden-update` script regenerates them.

### Changed

- The repack scenario test fixture is now a synthetic instance.
- The CI workflow runs with least-privilege permissions and pinned actions, and
  Dependabot keeps the actions up to date.

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

- **Round robin**: single and multi-leg schedules, with bounded rotation
  retries and optional deterministic backtracking over round decompositions.
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
- **Immutable DTOs**: readonly value objects with `toArray()`/`fromArray()`;
  `Schedule` round-trips JSON.

[Unreleased]: https://github.com/mission-gaming/tactician/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/mission-gaming/tactician/compare/v0.1.1...v0.2.0
[0.1.1]: https://github.com/mission-gaming/tactician/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/mission-gaming/tactician/releases/tag/v0.1.0
