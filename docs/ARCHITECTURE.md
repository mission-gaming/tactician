# Architecture

This document says which components exist and how they fit together. How to
use them is in the [usage guide](USAGE.md); why a subsystem is designed as it
is, in the notes under [`design/`](design/); what has shipped and what is
limited, in the [roadmap](ROADMAP.md).

## Core Components

### Data Transfer Objects (DTOs)
- **Participant**: Immutable participant representation with ID, label, seed, and metadata
- **Event**: Immutable match/event representation with participants, round, and metadata
- **Round**: Immutable round representation with number and metadata
- **Schedule**: Iterator/Countable collection of events with metadata support and JSON serialization
- **Result**: Immutable outcome of a played event (winner or draw, optional per-participant scores)

`Participant`, `Event`, `Round` and `Result` are `readonly` classes.
`Schedule` is not: it implements `Iterator` and keeps the cursor of the
iteration in itself. Its events cannot be changed (`addEvent()` returns a new
schedule), but two loops over one `Schedule` object share the cursor, so a
loop nested inside another loop over the same object ends the outer one
early; iterate `getEvents()` in the inner loop.

All DTOs support `toArray()`/`fromArray()` (`Result::fromArray()` also takes
the participants by ID, because a result refers to them); `Schedule`
additionally implements `JsonSerializable` with `toJson()`/`fromJson()`
round-tripping.

### Stage Model
The **stage** is Tactician's unit of work — participants and typed options
in, a schedule (or round-by-round pairings) out, and a uniform outcome when
play completes. The `src/Stage/` family:
- **StagePlan**: The algorithm's declaration of one stage's shape — identifier, total rounds, legs, rounds per leg, expected event count, and format-specific `validateIntegrity()`. Nullability is meaningful: null legs means the concept does not apply (Swiss); null totals mean unknowable up front. Plans never fabricate shape facts.
- **PairwisePlan**: Capability interface for round-robin-family plans that can guarantee pairwise meeting counts (`getExpectedMeetings()`)
- **RoundRobinPlan**: Knows everything up front — bye-aware rounds per leg (n-1 even, n odd), event counts, meeting multiplicities, and the leg strategy's contribution facts. Validation, diagnostics and constraints read the shape from it. The generators read the leg count, the events per leg and the rounds per leg from it and lay the rounds out themselves (see [How a round robin is generated](#how-a-round-robin-is-generated)); nothing in the library reads the contribution facts it carries (`rolesMirrorAcrossLegs()`, `requiresRandomization()`, `getWarnings()`), which are there for a caller.
- **SwissPlan**: Knows rounds and per-round event counts; legs are null, and an open-ended (engine-driven) stage may have null totals
- **PotDrawPlan**: Knows everything about the shape up front (pots × opponents per pot rounds, half the field in events per round) and which pot every entrant is in, from list position. It refuses the numbers that cannot form a pot draw, each with its own reason. Not a `PairwisePlan`: which pairs meet depends on the seed. Its `validateIntegrity()` checks the format, not only the counts: every entrant once per round, the exact number of opponents from every pot, no rematch, role balance, and exactly two participants per event.
- **StageState**: The serializable (`toArray()`/`fromArray()`/JSON) state of a results-driven stage between rounds — active participants, recorded pairings, and results. Records the *pairings* played, not just results, so repeat avoidance survives result-free rounds; withdrawals are a first-class verb (`withoutParticipant()`), a result of the last recorded round is corrected with `withResultReplaced()`, and an optional engine fingerprint (`withEngineFingerprint()`, serialized only when set) lets an engine refuse a state another engine or configuration paired, with an error that says where the two differ.
- **PairKey** (`@internal`): The one place that turns participant IDs into an order-independent lookup key, for every pairing map in plans, engines, diagnostics and metrics. The order is total (PHP's string comparison, with a byte-order tiebreak for IDs it calls equal, such as `'01'` and `'1'`) and the encoding escapes `|` and `\` inside an ID, so no two pairings share a key. Keys are never stored or serialized.
- **RoundPairing**: One value object for every format's round — round number, optional label ('semifinal', 'losers round 2'; null for Swiss), events, and byes
- **StageEngineInterface**: The one results-driven contract — `getPlan()`, `pairNextRound()`, `isComplete()`, `getOutcome()` — behind one driver loop for every format
- **FingerprintedEngine**: The capability interface of an engine that gives a fingerprint (`getFingerprint()`), implemented by the three engines and kept out of `StageEngineInterface` so that adding it broke no implementer. Its docblock is the contract: an opaque string compared for equality, standing for the format and the options that shape which rounds exist or how they are paired; an option at its default is left out, so an engine that gains an option keeps every stamp already stored; the default of such an option never changes afterwards.
- **EngineFingerprint** (`@internal`): The one place that writes and reads the library's fingerprints. An engine names its format and states each round-shaping option with its default (`with()`); the standings rules of an engine that pairs from the table go through `withStandingsRules()`. It writes `tactician:v1:` (a prefix that keeps the library's strings apart from an application's, and the version of the encoding), the format, and the options that are not at their default, ordered by name and percent-encoded. `differences()` turns two fingerprints into the statements the mismatch error carries. The strings are stored with states, so they are pinned in `tests/Unit/Stage/StageStateEngineFingerprintTest.php`; their spelling is no contract for callers.
- **StageOutcome**: The uniform completion product: standings, results, bye counts, and the structural final round; pooled stages combine into one outcome carrying the pool structure (`StageOutcome::combining()`). Deliberately no champion/winner vocabulary. The standings are a win/loss table of the whole stage and rank 1 of it is not always the participant who won the last tie (a double-elimination title holder can have a worse record than the participant it beat in the reset, a two-legged final decided by a tie decision leaves the two finalists level in the table, and so can a bye, which is no win in a bracket's table): who won an elimination stage is `MatchOutcomeSelector::winners()` over the outcome. The usage guide has both cases ([Who won the bracket](USAGE.md#who-won-the-bracket)).
- **EliminationPlan**: Bracket shape — single elimination knows rounds (log2 of bracket size) and events ((n-1) × legsPerTie); double elimination reports null (the grand final may reset). `getLegsPerTie()` is a tie-structure fact; `getLegs()` stays null.
- **PoolDistributor**: Serpentine distribution of participants into pools by list position, plus per-pool result splitting — the generic primitive behind group stages
- **ProgressionSelector**: The hand-off between stages — `RankRangeSelector` (rank slices: overall or per-pool blocks) and `MatchOutcomeSelector` (final-round winners/losers from recorded results, tie-aware). Each of the two has `fromArray()`/`toArray()` with a stable `mode` identifier; the interface declares neither, and nothing picks a selector class from plain data, so an application that stores selectors stores which class it is. Optional machinery: any ordered list is a valid stage entry.
- **CompositionValidator / StageTransition**: Ahead-of-time telescoping validation of a declared multi-stage chain, using selector cardinalities and knockout arithmetic
- **TieDecision**: Shared resolution of elimination ties, the one place the engines and `MatchOutcomeSelector` derive who advances — the event's winner, or more leg wins, advances; a tie that finishes level (a drawn single event, or a level two-legged aggregate) is the application's call, recorded as `tie_winner` result metadata and read only then. The bracket engines count a decided level single-leg event as a win for the advancer in the standings they compute (re-seeding and the outcome's table); the recorded result stays a draw

### Scheduling System
- **SchedulerInterface**: Contract for whole-schedule generators — participants and typed options in, a validated schedule out; `getPlan()` exposes the stage plan for a configuration, failing with diagnostics before any event exists
- **SchedulerOptions**: Typed per-algorithm options, one type per scheduler — no overloaded scalars. Config-constructible (`fromArray()`/`toArray()`) with stable identifiers.
- **RoundRobinOptions / SwissOptions**: Legs, leg strategy, backtracking and role assignment for round robin; rounds for Swiss (retiring the old "legs means rounds here" overload)
- **RoleAssignmentInterface** (`src/RoleAssignment/`): Which participant of each round-robin pairing is first-named. The generator hands over one leg with the roles it proposes and takes it back with seatings reversed; the scheduler refuses an answer that changes anything else. `RoundParityRoleAssignment` (the default) returns the proposal. `BalancedRoleAssignment` ends every leg with each participant's two role counts at most 1 apart (field of even size) or equal (field of odd size): a closed rule for a circle layout, a chain-reversing repair for any other. See `docs/design/role-assignment.md`.
- **PotDrawOptions**: Pots, opponents per pot and the seed of the draw. The seed is an option and not a `Randomizer`, so a draw is repeatable from plain data; `fromArray()` refuses unknown keys.
- **BacktrackingRoundRobinGenerator** (`@internal`): Opt-in search over the round decompositions the circle method cannot reach (`RoundRobinOptions(backtracking: true)`): per-round perfect matchings built seat by seat under the constraints, both orientations tried, dead ends backtracking across rounds. Deterministic, step-bounded, loud about budget-exhausted vs proven-unsatisfiable.
- **RoundRobinScheduler**: Circle method algorithm with integrated multi-leg generation, roles proposed by round parity and decided by the configured role assignment, first-class bye tracking, and bounded retry over rotated participant orderings when constraints reject a schedule. Builds its `RoundRobinPlan` first; [How a round robin is generated](#how-a-round-robin-is-generated) says what it reads from it.
- **SwissScheduler**: Whole-schedule Swiss preset — drives SwissPairingEngine through the stage driver loop recording no results, so every participant stays level and a round is a non-repeat pairing of the field: in a random order with a `Randomizer`, and without one in the order of a table with no results, which is the standings fallback (seed, label, ID) and not list position. It refuses more rounds than participants minus one
- **PotDrawScheduler**: Whole-schedule pot draw — a pot-constrained partial round robin, drawn up front and unrelated to Swiss pairing. Built by direct construction, with no search: within-pot rounds from a 1-factorisation of the pot, cross-pot rounds from cyclic shifts between two pots, scheduled by a 1-factorisation of the pots themselves; pots of odd size (two opponents per pot) pair up as partners that share four rounds. Roles are assigned inside the construction. The rounds are then mixed: pairs of rounds trade events along their alternating cycles, which moves events between rounds and changes none. The class docblock holds the argument for why every round is a perfect matching. It takes no constraints and no `Randomizer`: each call seeds its own from the options, so it keeps no state between calls. Pairwise by nature.
- **SchedulingContext**: What a constraint is given beside the candidate event: the participants, the events generated so far, the current leg and the stage plan (`getPlan()`). Immutable; `withEvents()` returns a new context. Its lookups by participant, pairing, round and leg read an index (`EventIndex`, below). The leg accessors answer for a round robin; for a format without legs `getCurrentLeg()` is 1 and `getEventsForLeg(1)` is every event

### Results-Driven Engines
Formats whose later rounds depend on results cannot be generated whole; these
engines resolve tournament state on every call:
- **SwissPairingEngine**: A `StageEngineInterface` implementation — standings-aware Monrad pairing from the recorded `StageState`, with repeat avoidance, bye rotation (byes credited as wins), home/away balancing, withdrawal handling, constraint support, and optional randomization within score groups (equal ranking values, and win/draw/loss totals apart only by the rounding of a float sum)
- **SwissRoundSearch** (internal): the search that pairs one round for the engine. It returns what a plain depth-first search over opponents in pairing order returns. With no constraints, or with a constraint set `ConstraintPurity` knows, it skips the branches that provably hold no complete pairing: a set of unpaired participants with no perfect matching among the pairs still open to it (`PerfectMatching`, Edmonds' algorithm). A round with no dead end is paired without that test; a search that is still running after a fixed number of candidates is started again with it. Under any other constraint set it is the plain search and nothing else. The class comment gives the proof, and `tests/Unit/Scheduling/SwissRoundSearchTest.php` holds it to the unpruned search on histories of four and of six participants
- **ConstraintPurity** (internal): says whether a constraint set holds only constraints that cannot tell how often, or in which order, they are asked: a `ConstraintSet` itself, holding objects of exactly `NoRepeatPairings`, `MinimumRestPeriodsConstraint`, `RoleBalanceConstraint` and `SeedProtectionConstraint`. Nothing states that a constraint is a predicate, so a constraint that runs code of the caller's may keep state or throw. The two shortcuts that ask constraints differently (the Swiss search above, and the round-robin retry loop, which builds no failure analysis for an ordering it goes on from) are taken only for a set this class knows
- **EventIndex** (internal): the lookup maps behind `SchedulingContext`'s queries by participant, pairing, round and leg
- **SingleEliminationEngine**: `StageEngineInterface` preset — position-folded brackets with byes to top positions, round labels, fixed or re-seeded paths, and one- or two-legged ties (`EliminationOptions`)
- **DoubleEliminationEngine**: `StageEngineInterface` preset — winners/losers routes with dropper rematch deferral, grand final, and optional bracket reset. Both elimination engines walk their bracket from the recorded `StageState` themselves (the shared part is the internal trait `EliminationBracketSupport`); the library has no public primitive that pairs one knockout round
- All engines emit **RoundPairing** values and finish as a **StageOutcome** (see the stage model); group stages are compositions (PoolDistributor + per-pool stages + selectors), not an engine

### Timeline System
Maps generated schedules onto dates and times — the mechanism only; slot
patterns come from application config, and persistence/notification
policy stays app-side:
- **TimelineDefinition**: The declarative per-stage slot model (zoned start, round interval, slots per round, slot interval, named resources), config-constructible with ISO 8601 durations. An interval is added in the stage's timezone as PHP adds a `DateInterval`: days, weeks, months and years keep the wall-clock time across a daylight-saving change (`P7D` from 19:00 is 19:00 a week later), while hours, minutes and seconds are elapsed time (`PT168H` lands an hour off after the change). Slot times emit in UTC. Resources (venue/pitch/court/board — generic by design) give each slot concurrent capacity, one event per resource.
- **ZonedTime** (`@internal`): The one parser of a configured datetime, shared by the timeline, the blackout windows and the session grid. The declared timezone is authoritative, and the string must state its date in full and mean what it writes: PHP's parser would resolve `tomorrow`, `+1 week`, the empty string or a missing date against the clock, and would read a date that does not exist, a weekday name that is not the weekday of the date or a second timezone to another instant than the one written, so those are rejected before PHP sees them (the check is `DateTimeString`, internal, which reads the string with `date_parse()` and never the clock; `ScheduledEvent::fromArray()` applies it to a kickoff). A date without a time of day is midnight. The usage guide states the accepted form.
- **TimelineAssigner**: Deterministic slot filling over `Schedule::getEventsByRound()` (whole schedules) or a `RoundPairing` (results-driven stages). Loud validation: round overflow and round-less events fail with diagnostics. Without a rule that is all it checks: it does not look at who is in an event, so a schedule that puts one participant in two events of a round gets both at one time on two resources. A `MinimumRestRule` of any positive rest rejects that.
- **ScheduledEvent / ScheduledSchedule**: Decorations wrapping untouched events with their UTC kickoffs; serializable, so re-assignment is cheap and platforms persist assigned times.
- **TimelineRule**: Time-aware validation over assigned kickoffs — `MinimumRestRule` (absolute rest between a participant's consecutive kickoffs, UTC-compared) and `BlackoutRule` (half-open windows, config-constructible). Deterministic assignment cannot route around a violated rule, so the assigner fails loudly with every violation; rules also validate standalone against accumulated round-by-round timelines. Deliberately distinct from generation constraints.

### Repack System
Repairs an existing schedule onto a declared grid — generation invents
events, repacking never does; the events are fixed inputs and the only
free variable is where each lands. See `docs/design/schedule-repack.md`
for the algorithm and decisions:
- **SessionGrid**: The declarative position model — an explicit ordered list of zoned session starts (irregular by design, unlike `TimelineDefinition`'s cadence), a slot interval, per-session slot counts (overridable), and per-slot concurrency capacity. Config-constructible; kickoffs emit in UTC. Two variations, both opt-in: a shape-only grid (`SessionGrid::shapeOnly()`) has the positions and no instants, for a caller that keeps its own times, and every accessor that would return a time throws on it; a null capacity is unbounded, so that only participants limit what shares a slot. `ordinalOf()` and `positionOf()` convert between a position and its index in grid order (and, on an instant-based grid, from an instant to its position).
- **MovableEvent / PinnedEvent**: Fixed-identity inputs keyed by opaque caller ids; the movable set is a multigraph. Pins hold their position for both participants, consume capacity, and may name participants absent from the movable set.
- **ScheduleRepacker**: Three deterministic, step-budgeted phases — per-session loads first (capacity- and pin-aware, weighted consolidation vs early fill, with interval-parity repair), then per-session packing by exact search over gap-free run placements (cheapest late-start first, perfect matching per slot), then bounded greedy fallback with Kempe-chain repair. Properness and pin immobility are never traded; contiguity is satisfied or reported.
- **RepackOutcome**: The deliberate deviation from the loud-failure rule — infeasibility returns a schedule plus itemised structured violations (`ParticipantDoubleBooked` (audited, unreachable), `EventUnplaced`, `ContiguityBroken`, `LateStart`, `CapacityExceeded`) and an exactly-reconciling unplaced list, because an operator repairing a live season needs the compromises, not an exception. `RepackOptions(throwOnViolations: true)` opts back into throwing (`RepackViolationsException` carries the outcome). The outcome has one typed accessor per violation kind, says whether the step budget stopped a search (`isBudgetExhausted()`), and has a `fingerprint()`: a versioned SHA-256 over a canonical encoding of its three lists, the same on every PHP version and platform, for detecting that a recomputed plan differs from a previewed one.

### Quality System
Graded measurement and selection over valid schedules — constraints stay
hard filters; metrics measure what remains:
- **QualityMetric**: One convention for every metric — lower is better, zero is ideal (metrics measure defects). Built-ins: `RoleBalanceMetric`, `RoleStreakMetric`, `RestSpreadMetric`, `PairingSpacingMetric`. Each leaves out the events it has no reading for: the three that read rounds skip an event without a round (`RoleBalanceMetric` counts it), and the three that read roles or pairs skip an event that does not have exactly two participants (`RestSpreadMetric` counts every participant of it).
- **ScheduleScorer**: Weighted composition with per-metric reports, so a chosen schedule is explainable. Which metrics and weights is application policy.
- **ScheduleOptimizer**: Deterministic best-of-N sampling — a master randomizer derives a child seed per sample for a caller-supplied generator callable; failed samples are counted, not fatal. Whole-schedule generators only (engines pair from results that do not exist yet).

### Standings System
- **StandingsCalculator**: Ordered league tables from results with a pluggable ranking strategy
- **RankingStrategy**: Computes the primary ranking value ordering the table — ordering is the contract, points are one means of producing it
- **WinDrawLossRanking**: The first implementation; sport conventions as named constructors (`threeOneZero()`, `oneHalfZero()`), config-constructible via `fromArray()`
- **TiebreakerInterface**: Pluggable tiebreakers — **WinsTiebreaker**, **BuchholzTiebreaker**, **SonnebornBergerTiebreaker**
- **Standings / StandingEntry**: Immutable table and per-participant line
- **TiedSet**: A group of adjacent entries that only the final fallback orders, with the positions it spans. `Standings::getTiedSets()` derives the sets from the entries on each call with `StandingEntry::isLevelWith()`, which compares what the calculator compares before the fallback (ranking value, each tiebreaker value, score difference, scores-for) with the same exact comparison, so the sets cannot disagree with the order of the table. Reporting only: the order of the entries is unchanged

### Leg Strategies
Legs belong to round robin. No other format has them: `SwissPlan`,
`EliminationPlan` and `PotDrawPlan` report `getLegs()` as null, and the
timeline, repack, quality and standings components have no leg concept.
- **LegStrategyInterface**: Two methods. `planLegs()` returns a `LegPlanContribution`; `generateEventForLeg()` is handed the two participants of one seating of a leg and returns the event for it, which is where a strategy decides the roles of that leg
- **MirroredLegStrategy**: Every leg after the first has the two participants of each seating in the other order
- **RepeatedLegStrategy**: Every leg has the seatings as they are laid out
- **ShuffledLegStrategy**: Every leg after the first has each seating in a random order, from the `Randomizer` it was given or from an unseeded one of its own
- **LegPlanContribution**: What a strategy states about itself (role mirroring, randomization, unsatisfiable reasons, warnings). A non-empty list of unsatisfiable reasons fails plan construction with an `InvalidConfigurationException` (`UnsatisfiableLegStrategy`); none of the three built-in strategies returns one, so this is an extension point for a strategy of the caller's. The three built-in strategies do not read the `ConstraintSet` that `planLegs()` receives

### Constraint System
A constraint is a hard filter on one candidate event. There are no soft
preferences; a graded property is a [quality metric](#quality-system).
- **ConstraintInterface**: `isSatisfied(Event, SchedulingContext): bool` and `getName()`. The name is the key the violation collector and the diagnostics group by
- **ConstraintSet / ConstraintSetBuilder**: The constraints one generation runs under, built with `ConstraintSet::create()->...->build()`. A set asks its constraints in the order they were added and stops at the first that rejects
- **NoRepeatPairings**: Rejects a pairing that already has an event in the current leg (anywhere in the schedule with `acrossLegs: true`)
- **MinimumRestPeriodsConstraint**: A minimum number of rounds between two meetings of the same pair. It counts rounds, not time, and it is about the pair, not about a participant's rest: in a single leg, where no pair meets twice, it rejects nothing. Rest measured in time is `MinimumRestRule` in the timeline system
- **SeedProtectionConstraint**: Keeps the top seeds (by the seed attribute) from meeting each other in the first part of the stage, a fraction of the plan's total rounds; satisfied when the plan does not know its total rounds
- **ConsecutiveRoleConstraint**: Limits how many events in a row a participant has in the same role. `homeAway()` and `position()` both read the role as the index of the participant in the event, so they are the same rule under two names for an event of two participants; the constructor takes a role extractor of the caller's
- **RoleBalanceConstraint**: Bounds the difference between a participant's first-named and second-named totals, checked on the running totals as the schedule is built
- **MetadataConstraint**: Rules over one metadata key of the participants of an event (`requireSameValue()`, `requireDifferentValues()`, `requireAdjacentValues()`, `maxUniqueValues()`), or a validator of the caller's
- **CallableConstraint**: A predicate of the caller's (`ConstraintSetBuilder::custom()`)

Neither constraint that reads time-like words reads time: no constraint sees
a kickoff, because generation happens before any time is assigned.

**What the library assumes of a constraint.** Nothing in the type system
states that a constraint is a pure predicate, and the generators treat it as
one: an event that is rejected is put to each constraint again to record
which one rejected it, a retry or a backtracking search puts the same
pairing to the constraints many times, and a failure analysis asks about
pairings and rounds the generation never tried. The usage guide states what
a constraint of the caller's may rely on
([Custom Constraints](USAGE.md#custom-constraints)). Two shortcuts that ask
fewer questions are taken only for the constraints the library can vouch
for; see `ConstraintPurity` under
[Results-Driven Engines](#results-driven-engines).

### Validation & Diagnostics
- **ScheduleValidator** (`@internal`) and the trait **ValidatesScheduleCompleteness** (`@internal`): Every whole-schedule generator validates the schedule it built before returning it: the event count against the plan's expected count (skipped when the plan cannot know it), then the plan's `validateIntegrity()`. A mismatch is an `IncompleteScheduleException`. This check is what holds a generator to its plan; it runs once, after generation
- **ConstraintViolationCollector / ConstraintViolation**: The rejections of the last ordering the round-robin scheduler tried, each with the constraint, the event, the participants and the round. Reached through `getViolationCollector()` on the scheduler and on `IncompleteScheduleException`. The Swiss engine records no rejections: a `NoValidPairingException` and the collector of `SwissScheduler` carry none
- **DiagnosticReport**: The analysis of one failed generation: expected, generated and missing event counts, missing pairings, blocked pairings, per-constraint attribution and suggestions, each as a list of strings
- **SchedulingDiagnostics** (`@internal`): Builds that report by probing — each missing pairing is put to the whole constraint set and to each constraint, in every candidate round and both orientations, against the events that were generated. Yields blocked pairings (culprits named), per-constraint rejection counts, and structural-fullness notes; attached to round-robin generation failures via `IncompleteScheduleException::getAnalysis()`. It is evidence about the schedule that was built, not a proof that no schedule exists; only the backtracking search states that, when it exhausts its search space. See [diagnostics-attribution.md](design/diagnostics-attribution.md)

`IncompleteScheduleException::getDiagnosticReport()` renders the analysis and
then adds general suggestions that it picks by the class of the constraints
that recorded violations. Those are generic advice, not findings.

### Exception Hierarchy
- **TacticianException**: Marker interface (extends `\Throwable`, adds no method) implemented by every exception the library throws on purpose, so one catch clause covers the library. `tests/Feature/ExceptionMarkerTest.php` fails for a `throw` in `src/` of anything else
- **SchedulingException**: Abstract base class of the scheduling failures (extends `\Exception`); each carries a diagnostic report. It is not the base of every exception: the four classes at the bottom of this list are outside it
  - **InvalidConfigurationException**: A configuration that cannot work. Carries a reason (`getReason()`, an `InvalidConfigurationReason` case set at every site that builds one; `tests/Feature/ConfigurationErrorReasonsTest.php` reads the source to check it), the values involved (`getContext()`), and the requirements of the failing component
    - **PinConflictException**: One participant pinned in two events at one position of a repack request; carries the two event IDs
  - **IncompleteScheduleException**: Schedule incomplete due to constraint conflicts
  - **NoValidPairingException**: No complete Swiss pairing exists for a round
  - **RepackViolationsException**: A repack outcome with violations, raised only when the caller asks for an exception
- **InvalidInputException** (extends `\InvalidArgumentException`): A rejected argument, or malformed data given to `fromArray()`/`fromJson()`
- **JsonConversionException** (extends `\JsonException`): JSON that cannot be read or written; wraps the PHP exception, keeping its message and code
- **InvariantViolationException** (extends `\LogicException`): A state the library's own logic rules out — a defect, not a caller mistake
- **UnavailableValueException** (extends `\LogicException`): A value asked of an object that does not hold it (a time from a shape-only `SessionGrid`, the kickoff of an assignment made on one, the capacity of an unbounded grid as a number) — a caller mistake that the object's `has...()` method would have prevented; not a configuration error and not a library defect. Unchecked on purpose, so a method that throws it puts no checked exception on callers that never hold such an object

Each class keeps the PHP parent type its throw sites had before the marker
existed, so a catch clause written against that type still matches. What the
marker does not cover (exceptions from caller-supplied code, the random
source, PHP's `\Error` family) is listed in
[`USAGE.md`](USAGE.md#what-the-marker-does-not-cover).

### Stability Annotations
Every class, interface, trait and enum in `src/` carries exactly one of
`@api`, `@experimental` and `@internal` in its docblock. The README's
[Versioning and stability](../README.md#versioning-and-stability) section
says what each means and which namespaces are stable;
`tests/Feature/StabilityAnnotationsTest.php` holds the annotations to it.
The components marked `@internal` above can change in any release.

## Two Generation Models

- **Whole-schedule generators** implement `SchedulerInterface`:
  `RoundRobinScheduler`, `SwissScheduler` and `PotDrawScheduler`. They take
  participants and options and return a complete, validated `Schedule`.
- **Results-driven engines** implement `StageEngineInterface`:
  `SwissPairingEngine`, `SingleEliminationEngine` and
  `DoubleEliminationEngine`. A later round depends on results, so an engine
  pairs one round at a time from a `StageState`.

Neither interface refers to the other, and no class implements both.
`SwissScheduler` is a scheduler that drives the Swiss engine. Round robin
has no engine: a round-robin stage that feeds another stage is turned into a
`StageOutcome` by the caller, from a `StandingsCalculator` table and the
results (the usage guide shows it under
[Pools, Progression, and Multi-Stage Tournaments](USAGE.md#pools-progression-and-multi-stage-tournaments)).

**Position is authoritative where a stage places entrants.** Bracket
folding, pool distribution and pot membership take entrants from their
position in the list they are given and never read
`Participant::getSeed()`. The seed attribute is read in two places:
`SeedProtectionConstraint`, and the last fallback of the standings order
(after ranking value, tiebreakers, score difference and scores-for, before
label and ID). Through that fallback it reaches whatever is ordered by a
table whose entries are level: the first round of a Swiss stage, which is
paired from a table with no results and so in seed, label and ID order and
not in list order; the survivors of a re-seeded elimination round; and a
`RankRangeSelector` slice.
`Standings::getTiedSets()` reports where that is
([ADR 0002](adr/0002-standings-order-is-total.md)).

### How a round robin is generated

1. **Options and plan.** The scheduler checks the participants (at least
   two, unique IDs), asks the leg strategy for its `LegPlanContribution`,
   and builds the `RoundRobinPlan`. An invalid configuration fails here,
   before any event exists.
2. **Layout.** A leg is laid out by the circle method: the first seat is
   fixed, the others rotate, and seat *i* meets seat *n − 1 − i*. A field
   of odd size gets a bye seat. The roles proposed alternate with the
   parity of the round within the leg. With a `Randomizer` the participant
   order of the first leg is shuffled first; later legs are laid out from
   the order as given.
3. **Roles.** The configured `RoleAssignmentInterface` is handed the leg
   and returns it with seatings unchanged or reversed; the scheduler
   refuses any other answer.
4. **Legs after the first.** The leg strategy turns each seating of the
   layout into the event of that leg.
5. **Constraints.** Each event is put to the constraint set against a
   context holding the events accepted so far. A rejected event is dropped
   and recorded as a violation; generation of the leg goes on, and a leg
   that ends short is a failure.
6. **Retry.** With a constraint set, a failed attempt is repeated with the
   participant list rotated by one, up to min(participants, 25) attempts in
   all. Without one there is a single attempt. Only the last attempt's
   violations and failure analysis are reported.
7. **Backtracking**, if `RoundRobinOptions(backtracking: true)` and every
   rotation failed: `BacktrackingRoundRobinGenerator` searches the round
   decompositions of the first leg, the role assignment is asked about the
   leg it found, and the later legs are derived from that leg through the
   leg strategy. See [backtracking-generation.md](design/backtracking-generation.md).
8. **Validation.** The finished schedule is checked against the plan (see
   [Validation & Diagnostics](#validation--diagnostics)) and returned with
   the plan's shape and the byes in its metadata.

The plan and the generators state the round-robin arithmetic separately.
The scheduler reads the number of legs, the events per leg and the rounds
per leg (for the round numbers of later legs) from the plan, and the circle
layout derives the rounds of a leg from the seat count itself; the
backtracking generator reads the rounds per leg from the plan. What keeps
the two in agreement is step 8, which fails a schedule that does not match
its plan, and the completeness property tests
(`tests/Feature/ScheduleCompletenessTest.php`). It is a check, not a
construction that rules a disagreement out.

### The results-driven loop

```
StageState::start(participants)
        │
        ▼
┌──────────────────────────────────────────────┐
│  while (!$engine->isComplete($state))        │
│      pairing = $engine->pairNextRound(state) │──► RoundPairing (events, byes, label)
│      results = playRound(pairing)            │    ...played application-side...
│      state = state->withRoundPlayed(...)     │◄── state serializes between rounds
└──────────────────────────────────────────────┘
        │
        ▼
$engine->getOutcome($state)  ──►  StageOutcome (standings, results, byes, final round)
        │
        ▼
ProgressionSelector::select($outcome)  ──►  ordered entrants of the next stage
```

The diagram is a sketch of the loop, not runnable code; the usage guide has
the executed version under [Swiss Tournaments](USAGE.md#swiss-tournaments).
An engine keeps no record of the stage: every call derives the rounds
played, the standings and the bracket from the `StageState` it is given. The
state is what an application stores between rounds; the engine is rebuilt
from the application's configuration (an
[engine fingerprint](USAGE.md#recording-which-engine-pairs-a-state) on the
state detects a mismatch). A `Randomizer` given to a Swiss engine is not
part of the state.

### Namespace dependencies

Which namespace of `src/` refers to classes of which other, from the code
(docblocks not counted). Namespaces are relative to
`MissionGaming\Tactician`.

| Namespace | Refers to |
|-----------|-----------|
| `DTO` | `Exceptions` |
| `Standings` | `DTO`, `Exceptions` |
| `RoleAssignment` | `DTO`, `Exceptions` |
| `Stage` | `DTO`, `Exceptions`, `Standings` |
| `Timeline` | `DTO`, `Exceptions`, `Stage` |
| `Quality` | `DTO`, `Exceptions`, `Stage` |
| `Repack` | `DTO`, `Exceptions`, `Timeline`, `Repack\Internal` |
| `Repack\Internal` | `DTO`, `Exceptions`, `Repack` |
| `Constraints` | `DTO`, `Exceptions`, `Scheduling` |
| `LegStrategies` | `Constraints`, `DTO`, `Scheduling` |
| `Validation` | `Constraints`, `DTO`, `Exceptions`, `Stage` |
| `Diagnostics` | `Constraints`, `DTO`, `Scheduling`, `Stage` |
| `Scheduling` | `Constraints`, `DTO`, `Diagnostics`, `Exceptions`, `LegStrategies`, `RoleAssignment`, `Stage`, `Standings`, `Validation` |
| `Exceptions` | `DTO`, `Diagnostics`, `Repack`, `Stage`, `Validation` |

`Standings`, `Timeline`, `Quality` and `Repack` can be used without a
scheduler. `Scheduling` is the hub. `Constraints`, `LegStrategies` and
`Diagnostics` each refer to `Scheduling` and are referred to by it (a
constraint and a leg strategy are given a `SchedulingContext`), and
`Exceptions` refers to the namespaces whose objects an exception carries.
Repack has its own event types (`MovableEvent`, `PinnedEvent`,
`SlotAssignment`) and does not take a `Schedule` or an `Event`; the caller
maps to and from them.

## Design Principles

- **Loud failure.** A whole-schedule generator returns a complete schedule
  that matches its plan, or throws; it never returns a partial schedule.
  Repack is the one deliberate exception and returns its compromises as
  data ([schedule-repack.md](design/schedule-repack.md)).
- **Determinism.** `src/` never asks for the current time and calls no
  global random function. Randomness comes from a `Random\Randomizer` the
  caller passes in (`PotDrawScheduler` builds its own from the seed in its
  options; `ShuffledLegStrategy` builds an unseeded one when given none).
  A `Randomizer` has state: a scheduler that holds one continues its
  sequence on every call, so the same seed gives the same schedule on a
  fresh scheduler and a different one on the second call of the same
  scheduler.
- **Immutable values.** The value objects are `readonly` classes and a
  change returns a new instance. `Schedule` is the exception described
  under [Data Transfer Objects](#data-transfer-objects-dtos). The
  schedulers are not value objects: `RoundRobinScheduler` and
  `SwissScheduler` keep the violations of their last run, readable through
  `getViolationCollector()`.
- **Plain-data construction.** Options, selectors, timeline and grid
  definitions and timeline rules have `fromArray()`/`toArray()` with stable
  string identifiers. Constraints, tiebreakers, quality metrics and a
  `Randomizer` have no plain-data form; an application builds those in
  code.
- **Mechanism, not policy.** The library pairs, orders, assigns and
  reports. Which slots exist, how a level tie is decided, which metrics
  matter and what is stored are the application's.
- **Vocabulary.** Participants, roles, legs and rounds, as the
  [glossary](USAGE.md#terminology) defines them. Some API names carry a
  sport's words for a neutral concept (`homeAway()` for the two roles,
  `getKickoff()` for an assigned time); they name no behaviour that
  depends on the sport.

## Performance Considerations

The figures below were measured on one machine (PHP 8.4, no OPcache, one
core) and are there to show orders of magnitude. `composer bench` runs the
benchmark suite (`tests/Benchmark/`) on the machine at hand, and the CI job
`Benchmarks` compares a pull request with its base.

### Memory
- **A schedule is held in memory.** Generation builds every event before it returns, and `Schedule` stores them in an array. Nothing is loaded lazily and the library uses no generators; iterating a `Schedule` is a convenience, not a memory saving
- **Memory grows with the number of events**, which for a round robin is quadratic in the number of participants: n(n−1)/2 events per leg. A 200-participant, two-leg round robin is 39,800 events and peaks at just under 30 MB

### Generation
- **Unconstrained round robin**: the circle method does work proportional to the number of events. The 200-participant, two-leg case above generates in about a tenth of a second
- **Constraints**: every candidate event is put to the constraint set once, and a set stops at the first constraint that rejects. What a constraint costs depends on what it reads from the context
- **Indexed context**: `SchedulingContext` answers "which events hold this participant, this pair, this round, this leg" from an index of its event list (`EventIndex`), built on first use and extended, not rebuilt, by `withEvents()`. A lookup costs the events it returns. Before the index every lookup scanned the whole schedule, so one check cost as much as the schedule was long: `NoRepeatPairings` over two legs took 0.12 s at 32 participants and 2.1 s at 64, and takes 0.009 s and 0.07 s now. `tests/Unit/Scheduling/EventIndexTest.php` holds every lookup to a scan. A constraint that calls `getExistingEvents()` and scans the list itself does not benefit
- **Role alternation**: round-parity roles bound the running role imbalance of a participant within one leg at 3 for a field of even size and 4 for a field of odd size, whatever the field size (checked for 2 to 30 participants)

### Failure paths
- **Bounded retries**: a constrained round robin that cannot be completed costs up to min(participants, 25) generation attempts
- **Failure analysis once**: the analysis probes every missing pairing in every round and costs far more than an attempt. It is built for the ordering whose exception is thrown, and not for the ones the retry loop goes on from, when the constraints are ones `ConstraintPurity` knows; for a set that runs code of the caller's it is still built for every ordering (see [diagnostics-attribution.md](design/diagnostics-attribution.md))
- **Step budgets**: the backtracking search and the repacker stop after a fixed number of search steps. A budget bounds steps, not time; what a step costs is in [backtracking-generation.md](design/backtracking-generation.md) and under [The Step Budget](USAGE.md#the-step-budget)
- **Swiss rounds**: a round that has no dead end is paired by a plain depth-first search. See `SwissRoundSearch` above for when the pruned search takes over
