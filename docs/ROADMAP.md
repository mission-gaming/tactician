# Roadmap

## Phase 1: Round Robin Core ✅
- Round-robin scheduler with circle method algorithm and balanced home/away roles (round-parity alternation)
- Comprehensive DTO system with modern PHP features
- Flexible constraint system with builder pattern
- Advanced constraint types (rest periods, seed protection, consecutive roles, role balance, metadata-based)
- Schedule validation system preventing incomplete tournaments
- Retry-capable generation: alternative participant orderings are tried automatically when constraints reject a schedule
- Multi-leg tournament support with strategy patterns (mirrored, repeated, shuffled) and first-class byes
- Exception handling with diagnostic capabilities
- PHPStan level 8 compliance with zero errors
- Full test suite covering edge cases and mathematical correctness

## Phase 2: Additional Algorithms ✅
- Swiss pairing engine: standings-aware Monrad pairing with repeat avoidance, bye rotation (credited as wins), home/away balancing, and withdrawal handling
- Single and double elimination brackets: fold seeding, byes, stage names, losers bracket, and optional grand-final reset
- Group stages: serpentine-seeded groups, per-group standings, and cross-group knockout qualification
- Results, standings, and tiebreakers: configurable points systems (football/chess presets) with wins, Buchholz, and Sonneborn–Berger tiebreakers
- Schedule serialization: JSON round-tripping for schedules, events, and participants
- Enhanced validation for the new tournament formats (pairing integrity, duplicate/conflicting result rejection, group-play completeness)

## Phase 3: Algorithm-Neutral Core ✅
- ✅ `StagePlan` abstraction: expected events, rounds, and completeness rules supplied by the algorithm rather than inferred by generic services (milestone 1: context, validation, diagnostics, and constraints consume the plan; the event calculators and validation-context classes are removed, and leg strategies contribute facts via `LegPlanContribution` instead of the ornamental `GenerationPlan`)
- ✅ Typed per-algorithm options objects, dissolving the legs/rounds parameter overload (milestone 2: config-constructible `RoundRobinOptions`/`SwissOptions` with stable strategy identifiers; `validateConstraints()`/`getExpectedEventCount()` folded into `getPlan()`), plus `PointsSystem` generalized to `RankingStrategy` with `WinDrawLossRanking`
- ✅ Unify the incremental engines behind a shared results-driven interface with a serializable `StageState` carrier (milestone 3: `StageState`/`RoundPairing`/`StageEngineInterface`/`StageOutcome`, Swiss conforming, `SwissScheduler` preset; milestone 4: the elimination engines rebuilt as `StageEngineInterface` presets with positional fold seeding, fixed or re-seeded paths, and two-legged ties)
- ✅ Progression, pools, and brackets as compositions (milestone 4): `ProgressionSelector` with `RankRangeSelector`/`MatchOutcomeSelector`, ahead-of-time `CompositionValidator`, `PoolDistributor` + pooled `StageOutcome::combining()` retiring `GroupStageEngine`, and `EliminationOptions(legsPerTie: 2)` ties decided app-side via `tie_winner` result metadata

**Design (implemented): [docs/design/phase-3-algorithm-neutral-core.md](design/phase-3-algorithm-neutral-core.md)**

## Phase 4: Timeline Assignment 🔄
- ✅ Slot-based time assignment: round-aligned (all of a round's events together) and staggered kickoffs from one declarative slot model (`TimelineDefinition` + `TimelineAssigner` + serializable `ScheduledEvent`/`ScheduledSchedule`; deterministic filling, DST-safe wall-clock arithmetic, UTC out, loud validation, engine bridge via `assignRound()`)
- ✅ Time-aware validation (double-booking, hour-based rest, blackout periods) with the library's diagnostic character (`TimelineRule` with `MinimumRestRule`/`BlackoutRule`, validated post-assignment — deterministic assignment reports violations loudly rather than routing around them)
- Mechanism only — slot patterns in, timestamped events out; config parsing, persistence, and notification policy stay application-side
- ✅ Venue/resource modelling (named resources on the timeline: concurrent kickoffs per slot, deterministic resource assignment carried on each scheduled event; per-resource availability windows remain future work, gated on a consumer needing them)

**Design (first cut implemented): [docs/design/timeline-assignment.md](design/timeline-assignment.md)**

## Phase 5: Advanced Features 🔄
- ✅ Schedule optimization algorithms and quality metrics (`src/Quality/`: lower-is-better `QualityMetric` built-ins for role balance, streaks, rest rhythm, and repeat spacing; weighted `ScheduleScorer` with per-metric reports; deterministic best-of-N `ScheduleOptimizer` — design note in `docs/design/schedule-quality.md`)
- ✅ Backtracking generation for constraint configurations the greedy generator cannot satisfy (opt-in `RoundRobinOptions(backtracking: true)`: deterministic, step-bounded search over round decompositions; greedy always runs first — design note in `docs/design/backtracking-generation.md`)
- Integration examples with popular frameworks
- ✅ Advanced diagnostic reporting and constraint suggestion systems (constraint attribution by probing: blocked pairings with culprits named, per-constraint rejection counts, structural-fullness notes — attached to every generation failure via `IncompleteScheduleException::getAnalysis()`; design note in `docs/design/diagnostics-attribution.md`)

## Use Cases

- **Gaming Tournaments**: Esports, board games, card games
- **Sports Leagues**: Round-robin leagues, swiss tournaments
- **Academic Competitions**: Debate tournaments, quiz bowls
- **Corporate Events**: Team building competitions, hackathons
