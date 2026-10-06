# Roadmap

All five planned phases have shipped, and schedule repacking was added in
0.2.0. The sections below record what each phase delivered; release contents
are in the [changelog](../CHANGELOG.md). For what comes next, see
[What is next](#what-is-next).

## Phase 1: Round Robin Core ✅
- Round-robin scheduler with circle method algorithm and home/away roles that alternate by round (the split is bounded, not equal: see [Known limitations](#known-limitations))
- Comprehensive DTO system with modern PHP features
- Flexible constraint system with builder pattern
- Advanced constraint types (rest periods, seed protection, consecutive roles, role balance, metadata-based)
- Schedule validation system preventing incomplete tournaments
- Retry-capable generation: alternative participant orderings are tried automatically when constraints reject a schedule
- Multi-leg tournament support with strategy patterns (mirrored, repeated, shuffled) and first-class byes
- Exception handling with diagnostic capabilities
- PHPStan level 9 compliance with zero errors
- Full test suite covering edge cases and mathematical correctness

## Phase 2: Additional Algorithms ✅
- Swiss pairing engine: standings-aware Monrad pairing with repeat avoidance, bye rotation (credited as wins), home/away balancing, and withdrawal handling
- Single and double elimination brackets: fold seeding, byes, stage names, losers bracket, and optional grand-final reset
- Group stages: serpentine-seeded groups, per-group standings, and cross-group knockout qualification
- Results, standings, and tiebreakers: configurable win/draw/loss points (3/1/0 and 1/½/0 presets) with wins, Buchholz, and Sonneborn–Berger tiebreakers
- Schedule serialization: JSON round-tripping for schedules, events, and participants
- Enhanced validation for the new tournament formats (pairing integrity, duplicate/conflicting result rejection, group-play completeness)

## Phase 3: Algorithm-Neutral Core ✅
- ✅ `StagePlan` abstraction: expected events, rounds, and completeness rules supplied by the algorithm rather than inferred by generic services (milestone 1: context, validation, diagnostics, and constraints consume the plan; the event calculators and validation-context classes are removed, and leg strategies contribute facts via `LegPlanContribution` instead of the ornamental `GenerationPlan`)
- ✅ Typed per-algorithm options objects, dissolving the legs/rounds parameter overload (milestone 2: config-constructible `RoundRobinOptions`/`SwissOptions` with stable strategy identifiers; `validateConstraints()`/`getExpectedEventCount()` folded into `getPlan()`), plus `PointsSystem` generalized to `RankingStrategy` with `WinDrawLossRanking`
- ✅ Unify the incremental engines behind a shared results-driven interface with a serializable `StageState` carrier (milestone 3: `StageState`/`RoundPairing`/`StageEngineInterface`/`StageOutcome`, Swiss conforming, `SwissScheduler` preset; milestone 4: the elimination engines rebuilt as `StageEngineInterface` presets with positional fold seeding, fixed or re-seeded paths, and two-legged ties)
- ✅ Progression, pools, and brackets as compositions (milestone 4): `ProgressionSelector` with `RankRangeSelector`/`MatchOutcomeSelector`, ahead-of-time `CompositionValidator`, `PoolDistributor` + pooled `StageOutcome::combining()` retiring `GroupStageEngine`, and `EliminationOptions(legsPerTie: 2)` ties decided app-side via `tie_winner` result metadata

**Design (implemented): [docs/design/phase-3-algorithm-neutral-core.md](design/phase-3-algorithm-neutral-core.md)**

## Phase 4: Timeline Assignment ✅
- ✅ Slot-based time assignment: round-aligned (all of a round's events together) and staggered kickoffs from one declarative slot model (`TimelineDefinition` + `TimelineAssigner` + serializable `ScheduledEvent`/`ScheduledSchedule`; deterministic filling, DST-safe wall-clock arithmetic, UTC out, loud validation, engine bridge via `assignRound()`)
- ✅ Time-aware validation (double-booking, hour-based rest, blackout periods) with the library's diagnostic character (`TimelineRule` with `MinimumRestRule`/`BlackoutRule`, validated post-assignment — deterministic assignment reports violations loudly rather than routing around them)
- Scope: mechanism only — slot patterns in, timestamped events out; config parsing, persistence, and notification policy stay application-side
- ✅ Venue/resource modelling (named resources on the timeline: concurrent kickoffs per slot, deterministic resource assignment carried on each scheduled event; per-resource availability windows are deferred, see [Deferred work](#deferred-work))

**Design (first cut implemented): [docs/design/timeline-assignment.md](design/timeline-assignment.md)**

## Phase 5: Advanced Features ✅
- ✅ Schedule optimization algorithms and quality metrics (`src/Quality/`: lower-is-better `QualityMetric` built-ins for role balance, streaks, rest rhythm, and repeat spacing; weighted `ScheduleScorer` with per-metric reports; deterministic best-of-N `ScheduleOptimizer` — design note in `docs/design/schedule-quality.md`)
- ✅ Backtracking generation for constraint configurations the greedy generator cannot satisfy (opt-in `RoundRobinOptions(backtracking: true)`: deterministic, step-bounded search over round decompositions; greedy always runs first — design note in `docs/design/backtracking-generation.md`)
- ✅ Integration examples with popular frameworks (Symfony and Laravel guides in `docs/integrations/` — every Tactician-touching snippet harness-executed; the framework-free request-cycle pattern runnable as example 18)
- ✅ Advanced diagnostic reporting and constraint suggestion systems (constraint attribution by probing: blocked pairings with culprits named, per-constraint rejection counts, structural-fullness notes — attached to every generation failure via `IncompleteScheduleException::getAnalysis()`; design note in `docs/design/diagnostics-attribution.md`)

## Schedule Repacking ✅

Added after the five phases and released in 0.2.0.

- ✅ Schedule repair onto an irregular grid (`src/Repack/`): `ScheduleRepacker` assigns existing movable events to `(session, slot)` positions on a declarative `SessionGrid`, around pinned events that may not move, with no participant double-booked and each participant's events within a session back to back where possible
- ✅ Infeasibility reported as data: `RepackOutcome` carries itemised violations instead of throwing — the one sanctioned exception to the loud-failure rule; `RepackOptions(throwOnViolations: true)` opts back into throwing

- ✅ API additions in 0.2.2: a shape-only `SessionGrid` for callers with no instants, with `ordinalOf()` and `positionOf()` lookups; unbounded slot capacity (`capacityPerSlot: null`); a typed accessor on `RepackOutcome` per violation kind; `isBudgetExhausted()`; and `fingerprint()`, a versioned hash of the outcome that is the same on every PHP version and platform

**Design (implemented): [docs/design/schedule-repack.md](design/schedule-repack.md)**

## Pot Draws ✅

Added after the five phases.

- ✅ Pot draw (`PotDrawScheduler`, `PotDrawOptions`, `PotDrawPlan`): pairings drawn up front from seeded pots, with every entrant meeting a fixed number of opponents from every pot, balanced roles, and plan-driven validation of the pot rules. Experimental.

## Known limitations

- **Greedy generation is the default.** A constraint set that every rotated ordering fails throws `IncompleteScheduleException` even when a schedule exists, unless `RoundRobinOptions(backtracking: true)` is set.
- **Backtracking searches the first leg only.** Later legs derive from it through the leg strategy; a later leg the constraints reject fails the attempt, because the search does not cross leg boundaries ([design note](design/backtracking-generation.md)).
- **`RoleBalanceConstraint` has a floor with the built-in generator.** `RoundRobinScheduler` bounds the running home/away imbalance at 3 for even field sizes and 4 for odd ones, so only limits at or above those values are always satisfiable.
- **A pot draw is built directly, not searched.** `PotDrawScheduler` draws any even pot size, and an odd pot size with two opponents per pot. An odd pot size with four or more opponents per pot can exist and is refused (`ConfigurationNotYetSupported`). The seed chooses among the schedules the construction can reach, which are not all the schedules the format allows: who meets whom is drawn evenly and the events are mixed across the rounds, but the pairings between two pots always follow one pattern, and the mixing is a fixed amount of work that leaves the smallest fields as they were built ([usage guide](USAGE.md#the-seed-and-determinism)). Pots are cut from list order and cannot be given explicitly, and there is no input for pairs of entrants that must not meet.
- **The last fallback of the standings order compares ids as numbers.** After ranking value, tiebreakers, score difference, score for, seed and label, `StandingsCalculator` orders two entries with PHP's string comparison of their ids: `'9'` before `'10'`, and two ids that are equal as numbers (`'01'` and `'1'`) in input order. Every other use of an id compares it as an exact string. Changing this fallback reorders tables that are correct today, so it waits for a minor release.
- **Events are pairwise.** `Event` accepts more than two participants, but nothing generates such an event, a `Result` cannot hold a finishing order, and standings and repack work on pairs. Events with more participants are a goal for 2.0 ([ADR 0003](adr/0003-multi-participant-events-are-a-2-0-goal.md)).
- **`ScheduleOptimizer` samples; it does not search.** It keeps the best of N seeded candidates and works with whole-schedule generators only ([design note](design/schedule-quality.md)).

## Deferred work

Not planned. Each item waits for a concrete need.

- **Timeline**: cross-stage clash validation and per-resource availability windows ([design note](design/timeline-assignment.md)).
- **Optimization**: search algorithms (local search, annealing) behind the existing `ScheduleScorer`.
- **Backtracking**: search across leg boundaries, and searched generation for other whole-schedule formats.
- **Pot draws**: keep-apart rules (pairs of entrants that must not meet) as a dedicated input, explicit pot membership, and the configurations that need a search.
- **Repack tidy-ups** with no functional gain: merging `EventUnplaced` with `UnplacedEvent` (the same three fields), and a shared base for `MovableEvent` and `PinnedEvent`. Both change public classes in a namespace the README lists as stable, so they need a breaking release.

## What is next

Planned work is tracked in the
[GitHub milestones](https://github.com/mission-gaming/tactician/milestones),
one per release. This file does not repeat them.

## Use Cases

- **Gaming Tournaments**: Esports, board games, card games
- **Sports Leagues**: Round-robin leagues, swiss tournaments
- **Academic Competitions**: Debate tournaments, quiz bowls
- **Corporate Events**: Team building competitions, hackathons
