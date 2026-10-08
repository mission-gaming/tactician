# Roadmap

What the library does today, release by release, what is known to be limited,
and what has been set aside. The entries of each release are in the
[changelog](../CHANGELOG.md); this file does not repeat them. Planned work is
in the [GitHub milestones](https://github.com/mission-gaming/tactician/milestones):
see [What is next](#what-is-next).

## What has shipped

| Release | Date | What it brought |
|---------|------|-----------------|
| 0.1.0 | 2026-07-04 | The first published release, with all five phases below |
| 0.1.1 | 2026-07-04 | A correction to `composer.json`; no code change |
| 0.2.0 | 2026-08-11 | [Schedule repacking](#schedule-repacking-020) |
| 0.2.1 | 2026-10-06 | No change in library behaviour: a smaller installed package, the versioning policy, executed documentation and pinned output |
| 0.2.2 | 2026-10-08 | The additions under [0.2.2](#022), and fixes to output that was broken |

The work before 0.1.0 was planned in five phases. All five were complete in
0.1.0, and the names are kept because the design notes refer to them.

### Phase 1: Round robin core (0.1.0)
- Round-robin scheduler using the circle method, with roles that alternate by round (the split is bounded, not equal: see [Known limitations](#known-limitations))
- Immutable value objects for participants, events, rounds and results, and an iterable schedule
- Constraints as hard filters, built with a fluent builder: rounds between repeat meetings, seed protection, consecutive roles, role balance, metadata rules, and custom predicates
- Validation of every generated schedule against its plan, and failure with diagnostics instead of a partial schedule
- Retry over rotated participant orderings when constraints reject a schedule
- Multi-leg schedules with leg strategies (mirrored, repeated, shuffled) and first-class byes

### Phase 2: Additional algorithms (0.1.0)
- Swiss pairing engine: standings-aware Monrad pairing with repeat avoidance, bye rotation (a bye counts as a win in the pairing order), role balancing, and withdrawals
- Single and double elimination brackets: fold seeding by list position, byes, round labels, losers bracket, and optional grand-final reset
- Group stages as a composition: serpentine pools, per-pool standings, and qualification across pools
- Results, standings, and tiebreakers: a win/draw/loss ranking (3/1/0 and 1/½/0 presets) with wins, Buchholz, and Sonneborn–Berger tiebreakers
- JSON round-tripping for schedules, events, and participants

### Phase 3: Algorithm-neutral core (0.1.0)
- `StagePlan`: the shape of a stage declared by the algorithm and read by validation, diagnostics and constraints
- Typed per-algorithm options, constructible from plain data, and `RankingStrategy` with `WinDrawLossRanking`
- One results-driven interface for the engines (`StageEngineInterface`) with a serializable `StageState`, `RoundPairing` and `StageOutcome`
- Progression, pools, and brackets as compositions: `ProgressionSelector` with `RankRangeSelector`/`MatchOutcomeSelector`, `CompositionValidator`, `PoolDistributor`, pooled `StageOutcome::combining()`, and two-legged ties decided app-side through `tie_winner` result metadata

Design note: [phase-3-algorithm-neutral-core.md](design/phase-3-algorithm-neutral-core.md)

### Phase 4: Timeline assignment (0.1.0)
- Slot-based time assignment, round-aligned or staggered, from one declarative slot model (`TimelineDefinition`, `TimelineAssigner`, serializable `ScheduledEvent`/`ScheduledSchedule`), with kickoffs in UTC and a bridge for results-driven stages (`assignRound()`)
- Time-aware validation after assignment (`TimelineRule` with `MinimumRestRule` and `BlackoutRule`)
- Named resources: concurrent kickoffs per slot, each scheduled event carrying its resource
- Scope: mechanism only. Slot patterns in, timestamped events out; config parsing, persistence, and notification policy stay application-side

Design note: [timeline-assignment.md](design/timeline-assignment.md)

### Phase 5: Advanced features (0.1.0)
- Quality metrics, a weighted `ScheduleScorer`, and best-of-N `ScheduleOptimizer` ([schedule-quality.md](design/schedule-quality.md))
- Opt-in backtracking generation for constraint sets the rotations cannot satisfy ([backtracking-generation.md](design/backtracking-generation.md))
- Constraint attribution on generation failures, by probing ([diagnostics-attribution.md](design/diagnostics-attribution.md))
- Integration guides for Symfony and Laravel in [`integrations/`](integrations/), and the framework-free request-cycle pattern as a runnable example. The test suite does not execute the guides' framework code; their library usage is quoted from runnable examples (11, 15, 19, 23 and 24) and checked by `tests/Feature/IntegrationGuidesTest.php`

### Schedule repacking (0.2.0)
- `ScheduleRepacker` assigns existing movable events to `(session, slot)` positions on a declarative `SessionGrid`, around pinned events that may not move, with no participant double-booked and each participant's events within a session back to back where possible
- Infeasibility is reported as data: `RepackOutcome` carries itemised violations instead of throwing, the one sanctioned exception to the loud-failure rule; `RepackOptions(throwOnViolations: true)` opts back into throwing

Design note: [schedule-repack.md](design/schedule-repack.md)

### 0.2.2

The changelog's `0.2.2` section has the detail and the output changes.

- **Pot draws** (`PotDrawScheduler`, `PotDrawOptions`, `PotDrawPlan`): pairings drawn up front from seeded pots. Experimental
- **Balanced role assignment** for round robin, opt-in ([role-assignment.md](design/role-assignment.md))
- **Level single-leg elimination events** recorded with a `tie_winner` decision
- **Tied sets**: `Standings::getTiedSets()` reports where only the fallback orders a table
- **Exceptions**: the `TacticianException` marker on every exception the library throws, a reason on every configuration error, and `PinConflictException`
- **Stage state**: `withResultReplaced()`, and an optional engine fingerprint that makes an engine refuse a state another engine or configuration paired
- **Repack**: shape-only grids, position ordinals, unbounded slot capacity, a typed accessor per violation kind, `isBudgetExhausted()`, and `fingerprint()`
- **Stability annotations**: `@api`, `@experimental` or `@internal` on every type, held to the README's lists by a test
- **Cost**: an index behind `SchedulingContext`'s lookups, one failure analysis per failed round robin, and a Swiss round search that skips branches with no pairing; a benchmark suite (`composer bench`)
- **Deprecations**, listed in the [usage guide](USAGE.md#deprecations)

## Known limitations

Each is a statement about `main`. The usage guide describes the behaviour
where a link is given.

**Round robin**

- **Greedy generation is the default.** A constraint set that every rotated ordering fails throws `IncompleteScheduleException` even when a schedule exists, unless `RoundRobinOptions(backtracking: true)` is set.
- **Backtracking searches the first leg only.** Later legs derive from it through the leg strategy; a later leg the constraints reject fails the attempt, because the search does not cross leg boundaries ([design note](design/backtracking-generation.md)).
- **The default roles are bounded, not balanced.** With `RoundParityRoleAssignment` a participant's two role counts are up to 3 apart in a single leg of a field of even size and up to 4 apart in a field of odd size. A `RoleBalanceConstraint` limit at those values holds for a single leg and for two or three mirrored legs; it fails for four mirrored legs and for repeated legs, and can fail with a `Randomizer` over several legs ([usage guide](USAGE.md#role-based-constraints)). With the opt-in `BalancedRoleAssignment` a single leg needs 1 for a field of even size and 2 for a field of odd size.
- **Balanced roles are a property of one leg.** `BalancedRoleAssignment` balances every leg on its own; what the whole schedule adds up to depends on the leg strategy, which keeps its meaning. Mirrored legs are balanced overall for two legs and one apart for three, not for four or more in a field of even size, and nothing is promised about same-role streaks where two legs meet ([design note](design/role-assignment.md)).
- **A scheduler with a `Randomizer` shuffles the first leg only.** The later legs are laid out from the participant order as given, so with a seeded scheduler they are not the first leg mirrored or repeated pairing by pairing.
- **A scheduler that holds a `Randomizer` is not repeatable call by call.** Its second call continues the random sequence. `RoundRobinOptions::fromArray()` builds `ShuffledLegStrategy` with no randomizer, so a shuffled schedule configured from plain data is not repeatable at all ([usage guide](USAGE.md#deterministic-randomization)).
- **Round robin has no stage engine.** A round-robin stage is a whole schedule; its `StageOutcome`, for a selector or a pooled outcome, is built by the caller from a standings table and the results, and nothing serializes a round-robin stage in progress.

**Constraints**

- **Purity is assumed and not enforced.** A constraint that keeps state, reads a random source or throws gets results that depend on how often and in which order the library asks ([usage guide](USAGE.md#what-a-constraint-may-rely-on)).
- **Constraints have no plain-data form.** Options, selectors, timelines and grids are constructible from arrays; a `ConstraintSet` is built in code. So are tiebreakers and quality metrics.
- **Two constraints match participants by object.** `SeedProtectionConstraint` and the `ConsecutiveRoleConstraint` factories do not recognise a second `Participant` object with the same ID ([usage guide](USAGE.md#participant-ids)).
- **Suggestions in a failure report are generic.** The attribution in the report is probed; the list of suggestions after it is chosen by constraint class.

**Stages, standings and brackets**

- **Rank 1 of an elimination outcome is not always the winner.** It differs for double elimination, for two-legged ties decided by `tie_winner`, and where a bye is involved; `MatchOutcomeSelector::winners()` is the winner ([usage guide](USAGE.md#who-won-the-bracket)).
- **The seed attribute reaches results-driven output through the standings fallback.** Where entries are level, the table orders them by seed, then label, then ID, and the first round of a Swiss stage, a re-seeded elimination round and a rank selector follow the table, not list position. `getTiedSets()` reports where ([ADR 0002](adr/0002-standings-order-is-total.md)).
- **A bye is a win in the Swiss pairing order and nothing in a table.** The Swiss engine orders a round as if every bye were a win; the outcome's table of a Swiss stage and of a bracket counts no bye. In a bracket re-seeded each round, an entrant who had a bye therefore ranks below the winners of that round, and in a results-free `SwissScheduler` schedule of a field of odd size the participants who have had a bye are ordered first and paired first, so the draw is not uniform ([usage guide](USAGE.md#swiss-tournaments)).
- **The last fallback of the standings order compares ids as numbers.** After ranking value, tiebreakers, score difference, score for, seed and label, `StandingsCalculator` orders two entries with PHP's string comparison of their ids: `'9'` before `'10'`, and two ids that are equal as numbers (`'01'` and `'1'`) in input order. Every other use of an id compares it as an exact string. Changing this fallback reorders tables that are correct today, so it waits for a minor release.
- **`StandingsCalculator` recognises an event by the object.** Two `Event` objects for one match are counted as two matches ([usage guide](USAGE.md#results-and-standings)).
- **An engine fingerprint is kept by patch releases only.** The `Stage` namespace is experimental and how a stage is described is not settled, so a 0.x minor release may change the scheme of the fingerprint; the changelog then says what a stored stamp is replaced with ([usage guide](USAGE.md#recording-which-engine-pairs-a-state)).
- **Nothing picks a format, a selector or a timeline rule from plain data.** Each class has `fromArray()`; which class to build is the application's to store.
- **Events are pairwise.** `Event` accepts more than two participants, but nothing generates such an event, a `Result` cannot hold a finishing order, and standings and repack work on pairs. Events with more participants are a goal for 2.0 ([ADR 0003](adr/0003-multi-participant-events-are-a-2-0-goal.md)).
- **`Schedule` has one iteration cursor.** A loop over a schedule nested in another loop over the same object ends the outer one early ([usage guide](USAGE.md#iterating-and-counting)).

**Pot draws**

- **A pot draw is built directly and then walked, not searched.** `PotDrawScheduler` draws any even pot size, and an odd pot size with two opponents per pot. An odd pot size with four or more opponents per pot can exist and is refused (`ConfigurationNotYetSupported`). What is built (who meets whom in which round) is the start of a walk of two moves that keep every rule, and the length of the walk was chosen by measurement; roles are given last, by one rule that can draw every assignment that keeps the role counts. The result is not claimed to be uniform over every schedule the format allows: it is not proved that the walk can reach every way to meet, and the roles are drawn after the walk, so pairings that allow more assignments of roles are not drawn more often for that. Two kinds of field keep part of the shape they were built with: 6 entrants in 3 pots of 2 (every round keeps its pots), and one pot in which every member meets every other (a single round robin, with the circle method's rounds when the pot size less one is prime) ([usage guide](USAGE.md#how-a-draw-is-made-and-what-it-is-uniform-over)). Pots are cut from list order and cannot be given explicitly, and there is no input for pairs of entrants that must not meet.

**Timeline and quality**

- **An interval in hours is elapsed time.** `PT24H` and `PT168H` shift by an hour across a daylight-saving change; `P1D` and `P7D` keep the time of day, except that a kickoff in the hour the clocks skip is moved and every later round keeps the moved time ([usage guide](USAGE.md#timeline-assignment)).
- **The assigner does not check who plays.** Without a `MinimumRestRule` it accepts one participant in two events at one time.
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
