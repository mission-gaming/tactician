# Progress: Tactician

*Detailed phase history lives in docs/ROADMAP.md and the git log; this file
tracks the current shape only, to avoid duplicating what rots.*

## What Works
- **Round robin** (`RoundRobinScheduler`): circle method with round-parity home/away alternation, first-class bye tracking, multi-leg generation via leg strategies (mirrored/repeated/shuffled), and bounded retry over rotated orderings when constraints reject a schedule
- **Swiss** (`SwissPairingEngine`): a `StageEngineInterface` engine — standings-aware Monrad pairing from the recorded `StageState`, repeat avoidance (reads pairings, so result-free rounds count), bye rotation credited as wins, home/away balancing, withdrawals via `withoutParticipant()`, `plannedRounds` for length-aware constraints and completion, optional randomization within equal-ranking groups; `SwissScheduler` preset for whole-schedule random non-repeat pairing
- **Elimination** (`SingleEliminationEngine`, `DoubleEliminationEngine`): `StageEngineInterface` presets — positional fold seeding, byes to top positions, round labels, fixed or re-seeded paths, one- or two-legged ties (`EliminationOptions`, `TieDecision`), losers bracket with rematch deferral, grand final with optional reset; conflicting/duplicate/round-less results rejected
- **Pools and progression**: `PoolDistributor` (serpentine by position, result splitting), pooled `StageOutcome::combining()`, `RankRangeSelector`/`MatchOutcomeSelector` selectors (config-constructible), `CompositionValidator` for ahead-of-time telescoping, `RoundRobinPlan::findUnplayedPairings()` guarding qualification — the retired `GroupStageEngine` recomposed from primitives
- **Results and standings**: `Result` DTO, `StandingsCalculator` with configurable `PointsSystem` and pluggable tiebreakers (wins, Buchholz, Sonneborn–Berger); deterministic tie ordering (seed, then natural label)
- **Constraints**: no-repeat pairings (leg-scoped), rest periods, seed protection, consecutive roles, role balance, metadata rules, custom predicates — all with loud diagnostic failure
- **Stage plans** (`src/Stage/`): `StagePlan`/`PairwisePlan` with `RoundRobinPlan` and `SwissPlan` — the algorithm declares shape once (rounds, legs, expected events, integrity rules); `SchedulingContext`, validation, diagnostics, and `SeedProtectionConstraint` read the plan instead of inferring; leg strategies contribute facts via `LegPlanContribution`
- **Stage engine model** (`src/Stage/`): serializable `StageState` (toArray/fromArray/JSON, pairings + results + byes, withdrawals first-class), unified `RoundPairing` (round number, nullable label, events, byes — used by Swiss and both elimination engines), `StageEngineInterface` (getPlan/pairNextRound/isComplete/getOutcome), and `StageOutcome` (standings, results, byes, final round; no trophy vocabulary); `Result` gained toArray/fromArray
- **Typed options** (`SchedulerOptions`): `RoundRobinOptions` (legs + strategy) and `SwissOptions` (rounds), config-constructible with stable strategy identifiers; `SchedulerInterface` is `schedule(participants, ?options)` + `getPlan(participants, ?options)` — the legs/rounds overload and `validateConstraints()` are gone
- **Ranking strategies** (`RankingStrategy`): standings ordered by a pluggable primary value; `WinDrawLossRanking` (named presets `threeOneZero`/`oneHalfZero`, `fromArray()`) replaces `PointsSystem`; `StandingEntry::getRankingValue()` alongside the W/D/L record
- **Quality** (`src/Quality/`): lower-is-better metrics (role balance/streaks, rest spread, pairing spacing), weighted `ScheduleScorer` with per-metric reports, deterministic seeded best-of-N `ScheduleOptimizer` (whole-schedule generators only)
- **Timelines** (`src/Timeline/`): declarative per-stage slot model (`TimelineDefinition`, config-constructible, wall-clock arithmetic in the stage timezone, UTC kickoffs out) with deterministic assignment (`TimelineAssigner`) over whole schedules or engine round pairings; serializable `ScheduledEvent`/`ScheduledSchedule` decorations; time-aware `TimelineRule`s (`MinimumRestRule`, `BlackoutRule`) failing assignment loudly post-assignment; named resources for concurrent kickoffs per slot
- **Serialization**: `toArray()`/`fromArray()` on all DTOs; `Schedule` JSON round-tripping
- **Quality gates**: ~620 Pest tests including property/invariant suites, PHPStan level 8, Rector, CS-Fixer, auto-validated examples (`tests/Feature/ExamplesTest.php` + `composer examples` in CI)

## What's Left to Build
See docs/ROADMAP.md:
- **Phase 4 remainder** (demand-gated only): cross-stage clash validation and per-resource availability windows — design note in `docs/design/timeline-assignment.md`
- **Phase 5 (underway)**: backtracking generation and quality metrics/best-of-N optimization shipped; remaining: framework integration examples, advanced diagnostics

## Known Issues / Limitations
- Greedy generation defaults: constraint sets that fail under every rotated ordering throw unless `backtracking: true` is set (the opt-in search closes the false-negative gap; later legs still derive from leg 1 without cross-leg search)
- `RoleBalanceConstraint` floors: limit 3 (even fields) / 4 (odd fields) with the built-in generator
- Perfect cross-group knockout pairing is guaranteed only for power-of-two group counts

## Status
- **Last Updated**: 2026-07-03
