# Progress: Tactician

*Detailed phase history lives in docs/ROADMAP.md and the git log; this file
tracks the current shape only, to avoid duplicating what rots.*

## What Works
- **Round robin** (`RoundRobinScheduler`): circle method with round-parity home/away alternation, first-class bye tracking, multi-leg generation via leg strategies (mirrored/repeated/shuffled), and bounded retry over rotated orderings when constraints reject a schedule
- **Swiss** (`SwissPairingEngine`): standings-aware Monrad pairing with repeat avoidance, bye rotation credited as wins, home/away balancing, withdrawal handling, and `plannedRounds` for length-aware constraints; `SimpleSwissScheduler` for whole-schedule random non-repeat pairing
- **Elimination** (`SingleEliminationEngine`, `DoubleEliminationEngine`): fold seeding, byes to top seeds, stage names, losers bracket with rematch deferral, grand final with optional reset; conflicting/duplicate/round-less results rejected
- **Group stages** (`GroupStageEngine`): serpentine-seeded groups, per-group standings, completeness-checked knockout qualification with cross-group reseeding
- **Results and standings**: `Result` DTO, `StandingsCalculator` with configurable `PointsSystem` and pluggable tiebreakers (wins, Buchholz, Sonneborn–Berger); deterministic tie ordering (seed, then natural label)
- **Constraints**: no-repeat pairings (leg-scoped), rest periods, seed protection, consecutive roles, role balance, metadata rules, custom predicates — all with loud diagnostic failure
- **Stage plans** (`src/Stage/`): `StagePlan`/`PairwisePlan` with `RoundRobinPlan` and `SwissPlan` — the algorithm declares shape once (rounds, legs, expected events, integrity rules); `SchedulingContext`, validation, diagnostics, and `SeedProtectionConstraint` read the plan instead of inferring; leg strategies contribute facts via `LegPlanContribution`
- **Typed options** (`SchedulerOptions`): `RoundRobinOptions` (legs + strategy) and `SwissOptions` (rounds), config-constructible with stable strategy identifiers; `SchedulerInterface` is `schedule(participants, ?options)` + `getPlan(participants, ?options)` — the legs/rounds overload and `validateConstraints()` are gone
- **Ranking strategies** (`RankingStrategy`): standings ordered by a pluggable primary value; `WinDrawLossRanking` (named presets `threeOneZero`/`oneHalfZero`, `fromArray()`) replaces `PointsSystem`; `StandingEntry::getRankingValue()` alongside the W/D/L record
- **Serialization**: `toArray()`/`fromArray()` on all DTOs; `Schedule` JSON round-tripping
- **Quality gates**: ~490 Pest tests including property/invariant suites, PHPStan level 8, Rector, CS-Fixer, auto-validated examples (`tests/Feature/ExamplesTest.php` + `composer examples` in CI)

## What's Left to Build
See docs/ROADMAP.md:
- **Phase 3** (milestones 1-2 of 5 done — see the design doc): unified engine interface with serializable `StageState` (M3), progression selectors/pools/bracket presets/two-legged ties (M4), sweep (M5)
- **Phase 4**: timeline assignment (time/venue slots, blackout periods)
- **Phase 5**: optimization, backtracking generation, framework integration examples

## Known Issues / Limitations
- Greedy generation: constraint sets that fail under every rotated ordering throw even when a valid schedule exists in principle (backtracking is Phase 5)
- `RoleBalanceConstraint` floors: limit 3 (even fields) / 4 (odd fields) with the built-in generator
- Perfect cross-group knockout pairing is guaranteed only for power-of-two group counts

## Status
- **Last Updated**: 2026-07-03
