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
| 0.2.2 | 2026-10-09 | The additions under [0.2.2](#022), and fixes to output that was broken |
| 0.2.3 | 2026-10-13 | No additions: three fixes to repack output that was broken ([placement fixes](design/schedule-repack.md#placement-fixes-023)) |

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
- Results, standings, and tiebreakers: a win/draw/loss ranking (3/1/0 and 1/½/0 presets, with the value of a bye as the caller's choice) with wins, score-difference, scores-for, Buchholz and Sonneborn–Berger tiebreakers, any of them over the events between the tied participants only, shared ranks, and a chain built from plain data
- JSON round-tripping for schedules, events, and participants

### Phase 3: Algorithm-neutral core (0.1.0)
- `StagePlan`: the shape of a stage declared by the algorithm and read by validation, diagnostics and constraints
- Typed per-algorithm options, constructible from plain data, and `RankingStrategy` with `WinDrawLossRanking`
- One results-driven interface for the engines (`StageEngineInterface`) with a serializable `StageState`, `RoundPairing` and `StageOutcome`
- Progression, pools, and brackets as compositions: `ProgressionSelector` with `RankRangeSelector`/`BestOfRankSelector`/`MatchOutcomeSelector`, `CompositionValidator`, `PoolDistributor`, pooled `StageOutcome::combining()`, and two-legged ties decided app-side through `tie_winner` result metadata

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
- **The roles of the first leg are searched with backtracking only.** Without it they are the role assignment's. Two repeated or mirrored legs whose roles break a role constraint where the legs meet therefore fail without `backtracking: true`, although other first-leg roles satisfy it; the later legs of a shuffled schedule are searched in every case, because their roles are draws ([design note](design/backtracking-generation.md#roles-across-legs)).
- **Role searches are bounded.** A search of roles stops after 20,000 rejected roles for a layout, and a backtracking search after 200,000 steps; a failure says when a bound stopped it. With a constraint set that holds a closure, a metadata rule or a class of your own, the search goes back one event at a time, and the later legs of a shuffled schedule under a limit of two same-role events in a row were found for every seed measured up to 24 participants and not for every seed from 30 up. Where only the first leg can repair a schedule, a backtracking search found two to four repeated legs of up to 24 participants under that limit. Under a limit on the role totals (`RoleBalanceConstraint`, 2 or 3 apart) with the default roles it found two repeated legs up to 16 participants and stopped at its budget from 20 up, and three repeated legs under a limit of 3 up to 10 participants; `BalancedRoleAssignment` satisfies those limits without a search ([design note](design/backtracking-generation.md#bounds)).
- **A role assignment does not see the constraints.** A first leg whose roles a backtracking search chose is returned only when the role assignment keeps it; `BalancedRoleAssignment` can therefore still fail a single leg with a pinned role although a balanced leg that satisfies the constraint exists ([design note](design/role-assignment.md)).
- **The default roles are bounded, not balanced.** With `RoundParityRoleAssignment` a participant's two role counts are up to 3 apart in a single leg of a field of even size and up to 4 apart in a field of odd size. A `RoleBalanceConstraint` limit at those values holds for a single leg and for any number of mirrored legs, which alternate; it fails for repeated legs, and can fail with a `Randomizer` over several legs ([usage guide](USAGE.md#role-based-constraints)). With the opt-in `BalancedRoleAssignment` a single leg needs 1 for a field of even size and 2 for a field of odd size.
- **Balanced roles are a property of one leg.** `BalancedRoleAssignment` balances every leg on its own; what the whole schedule adds up to depends on the leg strategy, which keeps its meaning. Mirrored legs alternate, so in a field of even size they are balanced overall for an even number of legs and one apart for an odd number; repeated legs are as many apart as there are legs. Nothing is promised about same-role streaks where two legs meet ([design note](design/role-assignment.md)).
- **A scheduler with a `Randomizer` shuffles the first leg only.** The later legs are laid out from the participant order as given, so with a seeded scheduler they are not the first leg mirrored or repeated pairing by pairing.
- **A scheduler that holds a `Randomizer` is not repeatable call by call.** Its second call continues the random sequence. A `ShuffledLegStrategy` that holds one continues its sequence in the same way; built from an integer seed, in code or as `strategy_seed` in plain data, it draws the same roles on every call ([usage guide](USAGE.md#deterministic-randomization)).
- **Round robin has no stage engine.** A round-robin stage is a whole schedule; its `StageOutcome`, for a selector or a pooled outcome, is built by the caller from a standings table and the results, and nothing serializes a round-robin stage in progress.

**Constraints**

- **Purity is assumed and not enforced.** A constraint that keeps state, reads a random source or throws gets results that depend on how often and in which order the library asks ([usage guide](USAGE.md#what-a-constraint-may-rely-on)).
- **Constraints have no plain-data form.** Options, selectors, timelines and grids are constructible from arrays; a `ConstraintSet` is built in code. So are tiebreakers and quality metrics.
- **Suggestions in a failure report are generic.** The attribution in the report is probed; the list of suggestions after it is chosen by constraint class.

**Stages, standings and brackets**

- **The entrants a bracket puts out in one round share their places.** The table of an elimination outcome is the bracket placement. A single elimination bracket decides places 3 and 4 only with the opt-in third-place tie ([usage guide](USAGE.md#a-third-place-tie)): without it the two who went out in the semifinals are ordered by their records and, where those are level, by list position, and `getTiedSets()` reports them ([usage guide](USAGE.md#who-won-the-bracket)). There is no tie for the places below fourth, and the third-place tie has the number of legs of every other tie of the bracket.
- **A bye is credited only where the ranking strategy says what it is worth.** A Swiss stage, and a re-seeded bracket that has a bye, credit each bye with the value the ranking strategy states (`RankingStrategyWithByeValue`; `WinDrawLossRanking` values a bye as a win unless it is given another value). Under a `RankingStrategy` that does not implement that interface such a stage is refused (`IncompatibleOptions`): implement it to lift the refusal. A tiebreaker that reads opponents' ranking values (Buchholz, Sonneborn–Berger) leaves byes out by default, as it did before a bye could be valued, and counts them when it is given a `ByeTreatment` ([usage guide](USAGE.md#byes-in-a-table)).
- **A tiebreaker between the tied participants is asked once for each place in the chain.** `BetweenTiedParticipants` computes its measure for the participants level when its turn comes, and does not apply the same measure again to a subset its values leave level: the next tiebreaker of the chain is asked about that subset. A competition that re-applies one measure until nothing changes gives it further places in the chain with a `TiedParticipantsTiebreaker` of its own under another name ([usage guide](USAGE.md#between-the-tied-participants)).
- **A table that stands alone has no plain-data form for a ranking strategy or a tiebreaker of the application.** `StandingsCalculator::fromArray()` and `TiebreakerChain::fromIdentifiers()` build the library's `WinDrawLossRanking` and the library's tiebreakers; there is no registry an application adds an identifier of its own to, and an engine's options, constraints and randomizer still have no plain-data form, so a stage cannot be stored whole ([usage guide](USAGE.md#a-chain-from-plain-data)).
- **An awarded result names one winner or none.** It counts for the participant it names and against the other, or as a draw for both. A `Result` cannot say that an event is lost by both sides, so an event awarded against both is not something the table can count. The library ships no tiebreaker computed between the tied participants only, so the choice whether an awarded result counts in one applies to a tiebreaker of the application's that implements `TiedParticipantsTiebreaker` ([usage guide](USAGE.md#awarded-results)).
- **An adjustment belongs to no round.** A stage state keeps what was adjusted and not when. A Swiss stage pairs each round once, from the totals as they stand, so that does not matter there. A re-seeded bracket replays its rounds on every call and ranks the survivors of every round with all the adjustments recorded, which is short of the rule its results follow ([ADR 0001](adr/0001-reseeded-brackets-rank-from-earlier-rounds.md): rank from what was known when the round was paired). An adjustment recorded after its second round was paired, one meant for the next round included, can change the replay of a round already played, and the engine then refuses the state (`RecordedRoundMismatch`). Record the adjustments of a re-seeded bracket before its second round is paired, and apply a later one to the table of the outcome with `withAdjustments()`. Recording when an adjustment takes effect would lift the limit. No verb removes an adjustment; the opposite amount takes one back ([usage guide](USAGE.md#adjustments)).
- **A pre-drawn Swiss schedule of a field of odd size is not a uniform draw.** A bye counts as a win in the pairing order, so in a results-free `SwissScheduler` schedule the participants who have had a bye are ordered first and paired first; at the largest round counts the engine can also give a participant a second bye where the round has no pairing otherwise ([usage guide](USAGE.md#swiss-tournaments)).
- **A rank selector sees a table and nothing else.** It refuses a pool with no result and a selection that level entries leave undecided; it cannot know that a pool with some results has events still to play. Its per-group order keeps a pool's entrants apart in a bracket of exactly the selected entrants and means nothing in particular elsewhere ([usage guide](USAGE.md#selections-that-results-do-not-decide)).
- **The best of a rank across pools are not placed for a bracket.** `BestOfRankSelector` lists them best first. Appended to a per-group selection (the top two of every pool, then the two best of those placed third) the list can pair an entrant with one of its own pool in the first round: where each of them has to go depends on which pools they come from, which the fixed order of a per-group selection cannot express, and `CompositionValidator` does not know how many pools a stage has, so it cannot say up front whether a bracket can keep them apart. An application that needs it orders the combined list itself. The selector counts the entries of the pools' tables and not their events: pools of one size that played different numbers of events are compared without a method unless one is chosen. A comparison per event played divides a tiebreaker value once, by the entrant's own events, which does not put a Buchholz or Sonneborn–Berger value of a larger pool on one footing with that of a smaller one; and the figures compared are those of the results and the adjustments, without a bye that a pool's own table credits ([usage guide](USAGE.md#the-best-of-a-rank-across-pools), [ADR 0006](adr/0006-pools-of-unequal-size-are-compared-by-a-method-the-caller-names.md)).
- **Level means equal, to the last bit, in a table.** A standings table and `getTiedSets()` compare ranking values exactly, so a scale that floats cannot hold (0.1 for a draw) can separate two entries that the same results in another order would leave level. The Swiss engine allows for that rounding when it pairs; the table of its outcome does not ([usage guide](USAGE.md#tied-sets)).
- **A withdrawn participant loses its place in the entrant list.** `StageState::withoutParticipant()` takes it out of the active list, so in the table of the stage it stands after the active participants it is level with, and withdrawn participants who are level with each other are in the order of their IDs ([ADR 0004](adr/0004-position-decides-where-results-do-not.md)).
- **`StandingsCalculator` recognises an event by its round, participants and tie leg.** Two results whose events agree on all three are one event, and are refused, also after storage. An event has no identifier beyond that, so one table over results that share round numbers (two seasons, or two stages that each count their rounds from 1) refuses a pair that met in the same round of both, and an event with no round is still recognised by the object alone ([usage guide](USAGE.md#results-and-standings)).
- **A scheduled schedule does not keep the entrant list.** `Schedule` keeps its entrants in their order; `ScheduledSchedule` still lists its participants in the order they first appear in its events.
- **An engine fingerprint is kept by patch releases only.** The `Stage` namespace is experimental and how a stage is described is not settled, so a 0.x minor release may change the scheme of the fingerprint; the changelog then says what a stored stamp is replaced with ([usage guide](USAGE.md#recording-which-engine-pairs-a-state)).
- **Nothing picks a format, a selector or a timeline rule from plain data.** Each class has `fromArray()`; which class to build is the application's to store.
- **Events are pairwise.** `Event` accepts more than two participants, but nothing generates such an event, a `Result` cannot hold a finishing order, and standings and repack work on pairs. Events with more participants are a goal for 2.0 ([ADR 0003](adr/0003-multi-participant-events-are-a-2-0-goal.md)).
- **`Schedule` has one iteration cursor.** A loop over a schedule nested in another loop over the same object ends the outer one early ([usage guide](USAGE.md#iterating-and-counting)).

**Pot draws**

- **A pot draw is built directly and then walked, not searched.** `PotDrawScheduler` draws any even pot size, and an odd pot size with two opponents per pot. An odd pot size with four or more opponents per pot can exist and is refused (`ConfigurationNotYetSupported`). What is built (who meets whom in which round) is the start of a walk of two moves that keep every rule, and the length of the walk was chosen by measurement; roles are given last, by one rule that can draw every assignment that keeps the role counts. The result is not claimed to be uniform over every schedule the format allows: it is not proved that the walk can reach every way to meet, and the roles are drawn after the walk, so pairings that allow more assignments of roles are not drawn more often for that. Two kinds of field keep part of the shape they were built with: 6 entrants in 3 pots of 2 (every round keeps its pots), and one pot in which every member meets every other (a single round robin, with the circle method's rounds when the pot size less one is prime) ([usage guide](USAGE.md#how-a-draw-is-made-and-what-it-is-uniform-over)). Pots are cut from list order and cannot be given explicitly, and there is no input for pairs of entrants that must not meet.

**Timeline and quality**

- **An interval in hours is elapsed time.** `PT24H` and `PT168H` shift by an hour across a daylight-saving change; `P1D` and `P7D` keep the time of day, except that a kickoff in the hour the clocks skip is moved on by that hour, for the round it falls in and no later one ([usage guide](USAGE.md#timeline-assignment)).
- **The assigner checks who plays within a round only.** It refuses a round that has one participant in two events at one instant. Rest between kickoffs at different times, and a clash between two stages whose timelines overlap in time, are checked by a `MinimumRestRule` and not otherwise.
- **Some intervals have no plain-data form.** A timeline or a grid built with an interval from `DateInterval::createFromDateString()`, with one from `diff()` that has hours, minutes or seconds, or with one that has a fraction of a second or a sign, works and cannot be written by `toArray()` ([usage guide](USAGE.md#timeline-assignment)).
- **`ScheduleOptimizer` samples; it does not search.** It keeps the best of N seeded candidates and works with whole-schedule generators only ([design note](design/schedule-quality.md)).

## Deferred work

Not planned. Each item waits for a concrete need.

- **Timeline**: cross-stage clash validation and per-resource availability windows ([design note](design/timeline-assignment.md)).
- **Optimization**: search algorithms (local search, annealing) behind the existing `ScheduleScorer`.
- **Backtracking**: searched generation for other whole-schedule formats.
- **Pot draws**: keep-apart rules (pairs of entrants that must not meet) as a dedicated input, explicit pot membership, and the configurations that need a search.
- **Repack tidy-ups** with no functional gain: merging `EventUnplaced` with `UnplacedEvent` (the same three fields), and a shared base for `MovableEvent` and `PinnedEvent`. Both change public classes in a namespace the README lists as stable, so they need a breaking release.

## What is next

Planned work is tracked in the
[GitHub milestones](https://github.com/mission-gaming/tactician/milestones),
one per release. This file does not repeat them.
