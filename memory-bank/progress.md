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
- **Serialization**: `toArray()`/`fromArray()` on all DTOs; `Schedule` JSON round-tripping
- **Quality gates**: ~475 Pest tests including property/invariant suites, PHPStan level 8, Rector, CS-Fixer, auto-validated examples (`tests/Feature/ExamplesTest.php` + `composer examples` in CI)

## What's Left to Build
See docs/ROADMAP.md:
- **Phase 3**: algorithm-neutral core (`ExpectedSchedule`/`AlgorithmPlan`, plan-driven generation, unified engine interface)
- **Phase 4**: timeline assignment (time/venue slots, blackout periods)
- **Phase 5**: optimization, backtracking generation, framework integration examples

## Known Issues / Limitations
- Greedy generation: constraint sets that fail under every rotated ordering throw even when a valid schedule exists in principle (backtracking is Phase 5)
- `RoleBalanceConstraint` floors: limit 3 (even fields) / 4 (odd fields) with the built-in generator
- `planGeneration()` on leg strategies is consulted only for its satisfiability preflight; the plan itself is not yet load-bearing
- Perfect cross-group knockout pairing is guaranteed only for power-of-two group counts

## Status
- **Last Updated**: 2026-07-03
