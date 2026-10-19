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
- **Schedule**: Iterator/Countable collection of events with metadata support and JSON serialization. It keeps its entrants (`getEntrants()`): the list a generator was given, in that order, which is the seeding where position decides, or the participants in the order they first appear when a schedule is built from events alone. The list is the `participants` of the serialized form, so the order survives a round trip
- **Result**: Immutable outcome of an event (winner or draw, optional per-participant scores). It is of an event that was played unless it carries an `Award`
- **Award**: The mark of an awarded result: the event was not played and the application gave it this outcome. An optional label and reason, opaque to the library; it names no participant, because the result does

`Participant`, `Event`, `Round`, `Result` and `Award` are `readonly` classes.
`Schedule` is not: it implements `Iterator` and keeps the cursor of the
iteration in itself. Its events cannot be changed (`addEvent()` returns a new
schedule), but two loops over one `Schedule` object share the cursor, so a
loop nested inside another loop over the same object ends the outer one
early; iterate `getEvents()` in the inner loop.

All DTOs support `toArray()`/`fromArray()` (`Result::fromArray()` also takes
the participants by ID, because a result refers to them); `Schedule`
additionally implements `JsonSerializable` with `toJson()`/`fromJson()`
round-tripping.

Every plain-data form in the library, these and the configuration and
stage forms below, is read and written under two rules that one internal
class holds (`Serialization\PlainData`). An array a `toArray()` returns
starts with `format_version`, written once at the top and not on the forms
nested in it; a `fromArray()` reads an array without the key as version 1,
the form 0.2.x wrote, and refuses a version it does not know
([ADR 0005](adr/0005-serialized-forms-carry-a-format-version.md)). And a
`fromArray()` refuses a key its form does not have.

### Stage Model
The **stage** is Tactician's unit of work — participants and typed options
in, a schedule (or round-by-round pairings) out, and a uniform outcome when
play completes. The `src/Stage/` family:
- **StagePlan**: The algorithm's declaration of one stage's shape — identifier (the backing value of a `Stage\Algorithm` case for the library's plans), total rounds, legs, rounds per leg, expected event count, and format-specific `validateIntegrity()`. Nullability is meaningful: null legs means the concept does not apply (Swiss); null totals mean unknowable up front. Plans never fabricate shape facts.
- **PairwisePlan**: Capability interface for round-robin-family plans that can guarantee pairwise meeting counts (`getExpectedMeetings()`)
- **RoundRobinPlan**: Knows everything up front — bye-aware rounds per leg (n-1 even, n odd), event counts, meeting multiplicities, and the leg strategy's contribution facts. Validation, diagnostics and constraints read the shape from it. The generators read the leg count, the events per leg and the rounds per leg from it and lay the rounds out themselves (see [How a round robin is generated](#how-a-round-robin-is-generated)); nothing in the library reads the contribution facts it carries (`rolesMirrorAcrossLegs()`, `requiresRandomization()`, `getWarnings()`), which are there for a caller.
- **SwissPlan**: Knows rounds and per-round event counts; legs are null, and an open-ended (engine-driven) stage may have null totals
- **PotDrawPlan**: Knows everything about the shape up front (pots × opponents per pot rounds, half the field in events per round) and which pot every entrant is in, from list position. It refuses the numbers that cannot form a pot draw, each with its own reason. Not a `PairwisePlan`: which pairs meet depends on the seed. Its `validateIntegrity()` checks the format, not only the counts: every entrant once per round, the exact number of opponents from every pot, no rematch, role balance, and exactly two participants per event.
- **StageState**: The serializable (`toArray()`/`fromArray()`/JSON) state of a results-driven stage between rounds — the entrant list, who has withdrawn, recorded pairings, and results. Records the *pairings* played, not just results, so repeat avoidance survives result-free rounds; a stored state is recorded again through `withRoundPlayed()` when it is read, so `fromArray()` refuses a history the verbs refuse, with the same reason. `fromHistory()` is the second way in, for an application that holds the rounds and results in its own rows and stores no state: it puts the rounds and results in the order the verbs would have recorded them, records them through the same private path as `fromArray()`, and checks three hard rules more that only rows can break (round numbers 1, 2, 3; every participant an entrant; nobody in a round twice), so the state it returns is the state the verbs build ([ADR 0007](adr/0007-recorded-rounds-are-read-not-derived.md)). Withdrawals are a first-class verb (`withoutParticipant()`): once a round is recorded the participant keeps its place in the entrant list (`getEntrants()`, stored as `entrants` only when someone has withdrawn), which is what lets a bracket give a tie away without being drawn again; before that it is out of the draw, and the first round says whether it was in it after all. A withdrawal and an adjustment are each recorded with the number of the last round recorded at that moment, 0 before the first (`getWithdrawals()`, a `Withdrawal` each, in the order they happened; `Adjustment::getAfterRound()`), so that an engine reads a recorded round from what was known when it was paired; a round is recorded when it is paired (`withRoundPaired()`, its results added with `withAdditionalResults()`) so that what happens while it is played comes after it, and a history states the round of every withdrawal and every adjustment once it holds a round ([ADR 0012](adr/0012-a-withdrawal-states-its-round.md)). A bracket tie both of whose entrants have withdrawn before either went through it is void and sends nobody on ([ADR 0011](adr/0011-a-tie-both-entrants-left-is-void.md)); a last tie goes to the entrant still in when the other left, and a bracket is read as it stood when it was over, so that a withdrawal after that takes nothing back ([ADR 0013](adr/0013-a-finished-bracket-keeps-its-winner.md)). A result of the last recorded round is corrected with `withResultReplaced()`, adjustments to ranking values are recorded with `withAdjustment()` (serialized only when there is one) and are in every table an engine builds from the state, and an optional engine fingerprint (`withEngineFingerprint()`, serialized only when set) lets an engine refuse a state another engine or configuration paired, with an error that says where the two differ.
- **PairKey** (`@internal`): The one place that turns participant IDs into an order-independent lookup key, for every pairing map in plans, engines, diagnostics and metrics. The order is total (PHP's string comparison, with a byte-order tiebreak for IDs it calls equal, such as `'01'` and `'1'`) and the encoding escapes `|` and `\` inside an ID, so no two pairings share a key. Keys are never stored or serialized.
- **RoundPairing**: One value object for every format's round — round number, optional label ('semifinal', 'losers round 2'; null for Swiss; as typed data, a `RoundLabel` of a `RoundLabelKind`, by `getRoundLabel()`: the engines give it from the round's place in the bracket, and a label stored as text is read back by `RoundLabel::fromString()`), events, and byes (a schedule lists its byes by round with `Schedule::getByesByRound()`, a stage state with `StageState::getByesByRound()`), and a mark for a round that was paired, on request, while a result of an earlier round was missing (serialized only when set)
- **StageEngineInterface**: The one results-driven contract — `getPlan()`, `pairNextRound()`, `isComplete()`, `getOutcome()` — behind one driver loop for every format
- **HistoryReadingEngine**: The capability interface of an engine that can be asked how it reads a stage in progress, implemented by the three engines and kept out of `StageEngineInterface` so that adding it broke no implementer: `getReadiness()` (a `PairingReadiness`: ready, awaiting results with the events, or complete), `getRecordedRounds()` (a `RecordedRound` for each: the round as recorded, the engine's own derivation, whether the two differ, the events it awaits and those a withdrawal settled) and `getStandings()` (the table so far). None of the three throws for a round that is not finished
- **PairingReadiness / RecordedRound**: The two answers above, as values. A recorded round that the engine would have paired otherwise is a *manual* round: reported, never refused
- **BracketViewEngine / Bracket**: The capability interface of the two elimination engines that can show a stage as a bracket (`getBracket()`), kept out of `StageEngineInterface` like `HistoryReadingEngine`. A `Bracket` is every round of every side (`BracketSide`: main; winners, losers, grand final), the rounds still to come included; a `BracketRound` holds its `BracketNode`s (a tie at its place, ID `<side>:<round>:<position>`, with a `BracketNodeStatus`, the advancer and the recorded events), and a node its `BracketSlot`s (an entrant, or a `SlotPlaceholder` naming the node it waits for or why it holds nobody). The view is built from the same walk as the other readers (`BracketReading` keeps what the walk went through when asked, and the internal `Scheduling\BracketViewBuilder` shapes the rounds after the one the walk stopped at), so it cannot disagree with them; it is never stored. The round labels are a rendering of it (`RoundLabel::ofBracketRound()`, which `BracketRound::getRoundLabel()` gives), which the engines call to label the rounds they pair
- **FingerprintedEngine**: The capability interface of an engine that gives a fingerprint (`getFingerprint()`), implemented by the three engines and kept out of `StageEngineInterface` so that adding it broke no implementer. Its docblock is the contract: an opaque string compared for equality, standing for the format and the options that shape which rounds exist or how they are paired; an option at its default is left out, so an engine that gains an option keeps every stamp already stored, and the default of such an option does not change within a scheme. The scheme follows the rule for experimental API: a patch release keeps every fingerprint, and a 0.x minor release may change the scheme with a migration note in the changelog ([usage guide](USAGE.md#recording-which-engine-pairs-a-state)).
- **EngineFingerprint** (`@internal`): The one place that writes and reads the library's fingerprints. An engine names its format and the version of the rules that pair its configuration (`of($format, $rules)`), and states each round-shaping option with its default (`with()`); the standings rules of an engine that pairs from the table go through `withStandingsRules()`. It writes `tactician:v<rules>:` (a prefix that keeps the library's strings apart from an application's, and the version of the rules), the format, and the options that are not at their default, ordered by name and percent-encoded. The version is per configuration and is raised in a minor release only for the configurations that release pairs differently, so a change to one engine leaves the stamps of the others valid ([ADR 0015](adr/0015-an-engine-fingerprint-versions-the-rules-of-each-configuration.md)); in 0.3.0 Swiss and a re-seeded single elimination bracket are at 2, a fixed path and double elimination at 1, the strings 0.2.x wrote. `differences()` turns two fingerprints into the statements the mismatch error carries. The strings are stored with states, so they are pinned in `tests/Unit/Stage/StageStateEngineFingerprintTest.php` and do not change in a patch release; their spelling is no contract for callers.
- **StageOutcome**: The uniform completion product: standings, results, bye counts, the structural final round and the entrant list of the stage, and for a bracket the last tie as its engine settled it (`getSettledTies()`, a `SettledTie`: who went on and who did not, also where a finalist withdrew and no result says so); pooled stages combine into one outcome carrying the pool structure (`StageOutcome::combining()`). Deliberately no champion/winner vocabulary. The standings are the table of the stage: for a Swiss stage the table its rounds were paired from, with a bye credited with what the ranking strategy says it is worth (a win by default); for an elimination stage the bracket placement, in which the round an entrant went out in is a tier that no record crosses, so rank 1 is the participant who advanced from the last tie ([Who won the bracket](USAGE.md#who-won-the-bracket)). An outcome given an entrant list puts the level entries of its table in the order of that list.
- **EliminationPlan**: Bracket shape — single elimination knows rounds (log2 of bracket size) and events ((n-1) × legsPerTie, and one tie more where the bracket has a third-place tie, `hasThirdPlaceTie()`); double elimination reports null (the grand final may reset). `getLegsPerTie()` is a tie-structure fact; `getLegs()` stays null.
- **BracketTie**: Which tie of a bracket's last round an event belongs to, where the round holds two: `Final` or `ThirdPlace`. The single elimination engine writes the backing string under `bracket_tie` in the metadata of those events, so that it is stored with them; `BracketTie::of()` reads it back. The round label is the same for both ties and does not tell them apart. `MatchOutcomeSelector` leaves a tie marked `ThirdPlace` out of its selection, and a single elimination engine without the option refuses a state that holds one
- **PoolDistributor**: Serpentine distribution of participants into pools by list position, plus per-pool result splitting — the generic primitive behind group stages
- **BracketFold** (`@internal`): The fold that places an entrant list into a knockout bracket (bracket size, and which list position stands in which slot), in one place for the elimination engines and for the per-group order of a rank selection
- **CrossPoolOrder** (`@internal`): The order in which a per-group rank selection lists the pools, rank by rank. The first rank is in pool order; each later rank is given the order, among all of them, in which entrants of one pool can first meet as late as possible in a bracket folded from the whole list (a minimum-cost assignment per rank, by the Hungarian method). For two ranks per pool a pool's first and second are in opposite halves, for any number of pools
- **ProgressionSelector**: The hand-off between stages — `RankRangeSelector` (rank slices: overall or per-pool blocks) and `MatchOutcomeSelector` (final-round winners/losers, from the ties an engine settled where the outcome carries them and from the recorded results otherwise, tie-aware). A `RankRangeSelector` refuses a selection that results do not decide (a table with no result, level entries across a boundary of the selected ranks, level entries inside them) with an `UndecidedSelectionException`, unless the selector was told to accept ties inside the selection or the table order as it stands, or was given a decision that settles the tie (`withDecisions()`): the level entries in the order the application decided, applied to the table the selection reads before a rank is read from it. `selection()` returns what `select()` returns as a `RankSelection`, which says which selected places a decision settled. `BestOfRankSelector` takes the best few of the entrants that hold one rank in their pools (the two best of those placed third): the holders come from each pool's own table and are compared by the figures of the combined table, and an undecided comparison is refused the same way, and settled the same way: a decision that names holders of the rank in more than one pool orders the group of holders the comparison leaves level. Entrants of pools of unequal size are compared by a `PoolComparison` the caller names (raw totals, per event played, or without the results against the lowest-placed entrants of the larger pools, which recalculates the table with the calculator the outcome carries); with none named, that selector and an overall `RankRangeSelector` refuse such pools. Each selector has `fromArray()`/`toArray()` with stable identifiers; the interface declares neither, and nothing picks a selector class from plain data, so an application that stores selectors stores which class it is. Optional machinery: any ordered list is a valid stage entry.
- **CompositionValidator / StageTransition**: Ahead-of-time telescoping validation of a declared multi-stage chain, counting each transition by the yield its selector declares (`ProgressionSelector::getYield()`, a `SelectionYield`: fixed, per pool, the winners or losers of the last round, or dependent on the outcome)
- **TieDecision**: Shared resolution of elimination ties, the one place the engines and `MatchOutcomeSelector` derive who advances — the event's winner, or more leg wins, advances; a tie that finishes level (a drawn single event, or a level two-legged aggregate) is the application's call, recorded as the `DTO\Decider` of one of its results (the participants who advance, as a list, with an opaque label and reason) and read only then; a decider on a tie the results decide is refused. The `tie_winner` result metadata that carried the decision before 0.3.0 is deprecated and still read where a level tie has no decider; the reading itself is in the internal `TieResolution`, which also tells its callers that the key was read, so that each raises the deprecation once for a call. The bracket engines count a decided level single-leg event as a win for the advancer in the standings they compute (re-seeding and the outcome's table); the recorded result stays a draw

### Scheduling System
- **SchedulerInterface**: Contract for whole-schedule generators — participants and typed options in, a validated schedule out; `getPlan()` exposes the stage plan for a configuration, failing with diagnostics before any event exists
- **SchedulerOptions**: Typed per-algorithm options, one type per scheduler — no overloaded scalars. Config-constructible (`fromArray()`/`toArray()`) with stable identifiers; `fromArray()` refuses a key the options do not have, as every `fromArray()` in the library does (`Serialization\PlainData`, internal, is the one check).
- **RoundRobinOptions / SwissOptions**: Legs, leg strategy, backtracking and role assignment for round robin; rounds and the optional seed of the draw for Swiss (retiring the old "legs means rounds here" overload)
- **RoleAssignmentInterface** (`src/RoleAssignment/`): Which participant of each round-robin pairing is first-named. The generator hands over one leg with the roles it proposes, and a `RoleAssignmentContext` (which leg of how many, and the seatings the constraints have fixed), and takes the leg back with seatings reversed; the scheduler refuses an answer that changes anything else. `rule()` states what the assignment holds a first leg to, as a constraint on one event, or null: with a rule the scheduler searches the roles of the first leg within it when the constraints reject the ones given. `BalancedRoleAssignment` ends every leg with each participant's two role counts at most 1 apart (field of even size) or equal (field of odd size): a closed rule for a circle layout, a chain-reversing repair for any other, and the same repair around fixed seatings; its rule is `LegBalanceRule` (`@internal`). Named, it is strict: balanced or refused. `RoundParityRoleAssignment` (the default up to 0.2.x) returns the proposal and states no rule. `BalancedWherePossibleRoleAssignment` (the default) answers and states its rule as the balanced one does; the scheduler knows the class, and where it finds no schedule with balanced roles it generates the schedule again as under round parity and writes the reason into the schedule's metadata (`RoleFallback`, with its `METADATA_KEY` and `of()`). See `docs/design/role-assignment.md`.
- **PotDrawOptions**: Pots, opponents per pot and the seed of the draw. The seed is an option and not a `Randomizer`, so a draw is repeatable from plain data.
- **BacktrackingRoundRobinGenerator** (`@internal`): Opt-in search over the round decompositions the circle method cannot reach (`RoundRobinOptions(backtracking: true)`): per-round perfect matchings built seat by seat under the constraints, both orientations tried, dead ends backtracking across rounds. Deterministic, step-bounded, loud about budget-exhausted vs proven-unsatisfiable. Every first leg it reaches is handed to the scheduler to complete, and one that cannot be completed is a dead end.
- **LegRoleSearch** (`@internal`): Searches roles with the pairings and rounds held: the roles of the later legs of a leg strategy that states them as preferences, in every layout, and with backtracking the roles of the first leg too, which mirrored and repeated legs derive from. Tries the role given first and the reverse second, goes straight back to an event of the same participants where the constraints read no others (`ConstraintPurity::readsOwnEventsOnly()`) and one event at a time otherwise, and is bounded by a count of rejected roles; a search a bound stopped says so.
- **RoundRobinScheduler**: Circle method algorithm with integrated multi-leg generation, roles proposed by round parity and decided by the configured role assignment, first-class bye tracking, and bounded retry over rotated participant orderings when constraints reject a schedule. Builds its `RoundRobinPlan` first; [How a round robin is generated](#how-a-round-robin-is-generated) says what it reads from it.
- **SwissScheduler**: Whole-schedule Swiss preset — drives SwissPairingEngine, in results-free mode, through the stage driver loop recording no results, so in a field of even size every participant stays level and a round is a non-repeat pairing of the field: in a random order with a source of draws (the seed of its options, or a `Randomizer` it holds, and not both), and without one in list order. In a field of odd size a bye counts as a win in the pairing order, so the participants who have had one are ordered ahead of the rest and are paired first. It refuses more rounds than participants minus one, and without constraints it generates every round count it accepts: a draw that reaches a round with no pairing is drawn again from the same sequence (up to twenty draws), and after that, or with nothing to draw from, the rounds are built by the rotation that lays out a round robin
- **PotDrawScheduler**: Whole-schedule pot draw — a pot-constrained partial round robin, drawn up front and unrelated to Swiss pairing. Built by direct construction, with no search: within-pot rounds from a 1-factorisation of the pot, cross-pot rounds from cyclic shifts between two pots, scheduled by a 1-factorisation of the pots themselves; pots of odd size (two opponents per pot) pair up as partners that share four rounds. What is built is who meets whom in which round, and it is then the start of a random walk of two moves, each of which keeps every rule of the format or changes nothing: the round exchange (two rounds trade the alternating cycle through one entrant, which moves events between rounds and changes none) and the opponent exchange (two members of one pot, in one round against opponents of one pot, exchange opponents when that makes no rematch). The walk has a length fixed by the configuration, so nothing is searched or retried. Roles are given last, by one rule: every entrant puts its events in twos (against one pot as far as they go) and is first in one event of a two; the twos join the events into trails, and a coin for each trail decides its roles. The class docblock holds the argument for why every round is a perfect matching, the proof that each move keeps the rules, the fields a move cannot change, the measurements behind the length of the walk, and why the roles are balanced. It takes no constraints and no `Randomizer`: each call seeds its own from the options, so it keeps no state between calls. Pairwise by nature.
- **SchedulingContext**: What a constraint is given beside the candidate event: the participants, the events generated so far, the current leg and the stage plan (`getPlan()`). Immutable; `withEvents()` returns a new context. Its lookups by participant, pairing, round and leg read an index (`EventIndex`, below). The leg accessors answer for a round robin; for a format without legs `getCurrentLeg()` is 1 and `getEventsForLeg(1)` is every event

### Results-Driven Engines
Formats whose later rounds depend on results cannot be generated whole; these
engines resolve tournament state on every call:
- **SwissPairingEngine**: A `StageEngineInterface` implementation — standings-aware Monrad pairing from the recorded `StageState`, with repeat avoidance, bye rotation (a bye is credited with what the ranking strategy says it is worth, a win by default, in the table the engine builds, which is both the pairing order of later rounds and the outcome's table), list position between participants no result separates, home/away balancing, withdrawal handling, constraint support, and optional randomization within score groups (equal ranking values, and win/draw/loss totals apart only by the rounding of a float sum), from a `Randomizer` or from a seed; a seed gives each round a `Randomizer` of its own built from the seed and the round number (`Xoshiro256StarStar`, one `jump()` per earlier round), so a round depends on nothing drawn before it and a state rebuilt from its history pairs as the one built round by round (issue #57, rule 4). It waits for the results of a recorded round: `pairNextRound()` refuses with an `UnresolvedRoundException` while an event has none, and `pairNextRoundOnIncompleteResults()` pairs early on request and marks the round. Results-free mode (`resultsFree: true`, part of the fingerprint when on) is the engine that waits for nothing, which the whole-schedule preset drives
- **SwissRoundSearch** (internal): the search that pairs one round for the engine. It returns what a plain depth-first search over opponents in pairing order returns. With no constraints, or with a constraint set `ConstraintPurity` knows, it skips the branches that provably hold no complete pairing: a set of unpaired participants with no perfect matching among the pairs still open to it (`PerfectMatching`, Edmonds' algorithm). A round with no dead end is paired without that test; a search that is still running after a fixed number of candidates is started again with it. Under any other constraint set it is the plain search and nothing else. The class comment gives the proof, and `tests/Unit/Scheduling/SwissRoundSearchTest.php` holds it to the unpruned search on histories of four and of six participants
- **ConstraintPurity** (internal): says whether a constraint set holds only constraints that cannot tell how often, or in which order, they are asked: a `ConstraintSet` itself, holding objects of exactly `NoRepeatPairings`, `MinimumRestPeriodsConstraint`, `RoleBalanceConstraint` and `SeedProtectionConstraint`. Nothing states that a constraint is a predicate, so a constraint that runs code of the caller's may keep state or throw. The two shortcuts that ask constraints differently (the Swiss search above, and the round-robin retry loop, which builds no failure analysis for an ordering it goes on from) are taken only for a set this class knows
- **EventIndex** (internal): the lookup maps behind `SchedulingContext`'s queries by participant, pairing, round and leg
- **SingleEliminationEngine**: `StageEngineInterface` preset — position-folded brackets with byes to top positions, round labels, fixed or re-seeded paths, and one- or two-legged ties (`EliminationOptions`). A re-seeded round folds the survivors in the order of a table of the earlier rounds in which a bye is credited as the ranking strategy values one (as a tie won, by default), level survivors in list order. A recorded round is read as recorded: each of its ties is settled from its results, the entrants who advance are the slots of the next round (a tie stands where the earlier of its two entrants stood), and a round that pairs the entrants otherwise than the engine would have is reported as manual, where it used to be refused (`RecordedRoundMismatch`, which now names only a third-place tie recorded for an engine that plays none). A tie an entrant has withdrawn from is not played and sends the other entrant on. With `EliminationOptions(thirdPlace: true)` the last round holds a second tie, between the two entrants who went out in the round before it: it is resolved, recorded and decided as every tie is, and it splits the tier of those two entrants in the outcome's table, so the bracket decides places 1 to 4 ([A third-place tie](USAGE.md#a-third-place-tie))
- **DoubleEliminationEngine**: `StageEngineInterface` preset — winners/losers routes with dropper rematch deferral, grand final, and optional bracket reset. Both elimination engines walk their bracket from the recorded `StageState` themselves (the shared part is the internal trait `EliminationBracketSupport`, which reads one round, recorded or not, and the internal `BracketReading`, the working record of one walk); the library has no public primitive that pairs one knockout round. A stage of a double elimination bracket that is recorded with other pairs is read as recorded where it leaves as many places as the stage has, and is refused otherwise (`RecordedRoundMismatch`), because the later stages take their entrants by place
- All engines emit **RoundPairing** values and finish as a **StageOutcome** (see the stage model); group stages are compositions (PoolDistributor + per-pool stages + selectors), not an engine

### Timeline System
Maps generated schedules onto dates and times — the mechanism only; slot
patterns come from application config, and persistence/notification
policy stays app-side:
- **TimelineDefinition**: The declarative per-stage slot model (zoned start, round interval, slots per round, slot interval, named resources), config-constructible with ISO 8601 durations. Every slot time is computed from the start, never from the slot before it (round N is the start plus N−1 round intervals as one step; `IntervalArithmetic`, internal, does the arithmetic for the timeline and the session grid alike), in the stage's timezone: days, weeks, months and years keep the wall-clock time across a daylight-saving change (`P7D` from 19:00 is 19:00 a week later) and months keep the day of the month or, where the month is too short, its last day (`P1M` from 31 January is the end of February, then 31 March), while hours, minutes and seconds are elapsed time (`PT168H` lands an hour off after the change). The last slot of a round must be before the next round's first; the constructor checks the first two rounds. Slot times emit in UTC. Resources (venue/pitch/court/board — generic by design) give each slot concurrent capacity, one event per resource.
- **ZonedTime** (`@internal`): The one parser of a configured datetime, shared by the timeline, the blackout windows and the session grid. The declared timezone is authoritative, and the string must state its date in full and mean what it writes: PHP's parser would resolve `tomorrow`, `+1 week`, the empty string or a missing date against the clock, and would read a date that does not exist, a weekday name that is not the weekday of the date or a second timezone to another instant than the one written, so those are rejected before PHP sees them (the check is `DateTimeString`, internal, which reads the string with `date_parse()` and never the clock; `ScheduledEvent::fromArray()` applies it to a kickoff). A date without a time of day is midnight. The usage guide states the accepted form.
- **TimelineAssigner**: Deterministic slot filling over `Schedule::getEventsByRound()` (whole schedules) or a `RoundPairing` (results-driven stages). Loud validation: round overflow, round-less events, a round whose slots run up to or past the first slot of the next round that is played (`assign()` compares the rounds its schedule holds, each with the next one it holds; `assignRound()` its round with the one before it), and a round that puts one participant in two events at one instant (two resources of one slot) fail with diagnostics. `assignRound()` applies the assigner's rules to the round it is given, as `assign()` applies them to the whole schedule. Without a rule that is all it checks: rest between kickoffs at different times is a `MinimumRestRule`'s to judge.
- **ScheduledEvent / ScheduledSchedule**: Decorations wrapping untouched events with their UTC kickoffs; serializable, so re-assignment is cheap and platforms persist assigned times. A scheduled schedule carries the entrant list of the schedule assigned, so it gives the same byes (`getByesByRound()`).
- **TimelineRule**: Time-aware validation over assigned kickoffs — `MinimumRestRule` (absolute rest between a participant's consecutive kickoffs, UTC-compared) and `BlackoutRule` (half-open windows, config-constructible). Deterministic assignment cannot route around a violated rule, so the assigner fails loudly with every violation; rules also validate standalone against accumulated round-by-round timelines. Deliberately distinct from generation constraints.

### Repack System
Repairs an existing schedule onto a declared grid — generation invents
events, repacking never does; the events are fixed inputs and the only
free variable is where each lands. See `docs/design/schedule-repack.md`
for the algorithm and decisions:
- **SessionGrid**: The declarative position model — an explicit ordered list of zoned session starts (irregular by design, unlike `TimelineDefinition`'s cadence), a slot interval, per-session slot counts (overridable), and per-slot concurrency capacity. Sessions may not overlap: a grid is rejected at construction when the last slot of a session is at or after the start of the next. Config-constructible; kickoffs emit in UTC. Two variations, both opt-in: a shape-only grid (`SessionGrid::shapeOnly()`) has the positions and no instants, for a caller that keeps its own times, and every accessor that would return a time throws on it; a null capacity is unbounded, so that only participants limit what shares a slot. `ordinalOf()` and `positionOf()` convert between a position and its index in grid order (and, on an instant-based grid, from an instant to its position).
- **MovableEvent / PinnedEvent**: Fixed-identity inputs keyed by opaque caller ids; the movable set is a multigraph. Pins hold their position for both participants, consume capacity, and may name participants absent from the movable set.
- **ScheduleRepacker**: The participants and events are first put in an order made from the request and not from their ids (`Repack\Internal\StructuralOrder`), and the events split into rounds laid on the grid around the pins (`Repack\Internal\RoundColouring`), which is the order every phase takes them in. Then three deterministic, step-budgeted phases — per-session loads first (capacity- and pin-aware, weighted consolidation vs early fill, with interval-parity repair), then per-session packing by exact search over gap-free run placements (cheapest late-start first, perfect matching per slot), then bounded greedy fallback with Kempe-chain repair. When an event is still unplaced after that, a last placement step (`Repack\Internal\LeftoverRecolourer`, with a step budget of its own) moves placed events across the grid along alternating paths and exchanges, to place what the phases left over. Contiguity comes ahead of the weights: the rounds as laid out are returned where they place more events than the planned schedule, or as many with fewer gap slots, and a schedule with a gap is searched for a placement with fewer (`Repack\Internal\GapSearch`). Where the result still places fewer events than a bound on what any placement holds, or has a gap, the events are planned and packed once more in the order of their participants, on the steps the budgets have left, and that third schedule is returned when it is better by events placed and then gap slots. Properness and pin immobility are never traded; contiguity is satisfied or reported.
- **RepackOutcome**: The deliberate deviation from the loud-failure rule — infeasibility returns a schedule plus itemised structured violations (`ParticipantDoubleBooked` (audited, unreachable), `EventUnplaced`, `ContiguityBroken`, `LateStart`, `CapacityExceeded`) and an exactly-reconciling unplaced list, because an operator repairing a live season needs the compromises, not an exception. `RepackOptions(throwOnViolations: true)` opts back into throwing (`RepackViolationsException` carries the outcome). The outcome has one typed accessor per violation kind, says whether the step budget stopped a search (`isBudgetExhausted()`), and has a `fingerprint()`: a versioned SHA-256 over a canonical encoding of its three lists, the same on every PHP version and platform, for detecting that a recomputed plan differs from a previewed one. It also carries the figures outcomes are ranked by (unplaced events, gap slots, lone-event sessions, idle sessions, moved events), counted for every outcome by `Repack\Internal\PlacementMetrics`.
- **Repack objectives**: A `MovableEvent` may state its current position, and `RepackOptions(objectives: ...)` names figures to keep low in order (`RepackObjective`: moved events, lone-event sessions, idle sessions). They are opt-in and rank after events placed and gap slots: the schedule of the request with no objective is always made first, and nothing made after it is returned unless it places as many events with no more gap slots. Under the fewest moved events the current positions decide where `Repack\Internal\StructuralOrder` would leave it to an id (between participants the request does not tell apart, and between events of the same two), so that the outcome does not follow the ids; the schedule made first is then the one with no objective up to a symmetry of the request. For the fewest moved events the repacker holds the events that can stay where they are and repacks the rest around them, lets held events move again through the last placement step and the gap search where that costs an event or a gap slot, and lets go of them in up to three attempts; `Repack\Internal\ObjectivePolish` then moves single events where that serves an objective and costs nothing that outranks it. All of it spends what the two step budgets have left.
- **ShortestGridFinder**: The fewest sessions on which a repack places every event, for a grid the caller gives as a function of its number of sessions. It tries the numbers in ascending order, one repack each, skips those that counting rules out, and returns a `ShortestGridResult` that says what happened at every smaller number; it does not bisect, because placing everything is not known to be monotone in the number of sessions.

### Quality System
Graded measurement and selection over valid schedules — constraints stay
hard filters; metrics measure what remains:
- **QualityMetric**: One convention for every metric — lower is better, zero is ideal (metrics measure defects). Built-ins: `RoleBalanceMetric`, `RoleStreakMetric`, `RestSpreadMetric`, `PairingSpacingMetric`. Each leaves out the events it has no reading for: the three that read rounds skip an event without a round (`RoleBalanceMetric` counts it), and the three that read roles or pairs skip an event that does not have exactly two participants (`RestSpreadMetric` counts every participant of it).
- **ScheduleScorer**: Weighted composition with per-metric reports, so a chosen schedule is explainable. Which metrics and weights is application policy.
- **ScheduleOptimizer**: Deterministic best-of-N sampling — a master randomizer derives a child seed per sample for a caller-supplied generator callable; failed samples are counted, not fatal. Whole-schedule generators only (engines pair from results that do not exist yet).

### Standings System
- **StandingsCalculator**: Ordered league tables from results with a pluggable ranking strategy. `calculate()` gives a table that stands alone: entries that results leave level are ordered by seed and then by ID, in an order that is total for every set of IDs, so the table does not depend on the order of the participants array. `calculateWith()` calculates a table with its byes and its adjustments in one call: each bye is credited with what the ranking strategy says a bye is worth (`RankingStrategyWithByeValue`), and it is the call the engines make, because a tiebreaker that reads byes and one computed between the tied participants need both before they are asked. `withByesCredited()` and `withAdjustments()` apply byes and adjustments to a table that exists, keep its tiebreaker values, and refuse a calculator whose tiebreakers depend on them; either also refuses a table whose decisions the change leaves without a tied set to match (`Stale`). A calculator of the library's own classes has a plain-data form (`fromArray()`/`toArray()`: a ranking, a tiebreaker chain, an awarded result policy)
- **AwardedResultPolicy**: The two choices a calculator has for an awarded result (`Result::isAwarded()`), as plain data: whether its scores count in the score figures, and whether a tiebreaker computed between the tied participants only is handed it. Both default to yes, so a table without awarded results is the same under every policy. An awarded result is otherwise an ordinary result of the table
- **TiedParticipantsTiebreaker**: A marker interface over `TiebreakerInterface` for a tiebreaker computed between the tied participants only. The calculator asks the tiebreakers in order and, for one of this kind, forms the tied sets itself (the participants level on the ranking value and on every tiebreaker before the run it is in; tiebreakers of this kind that follow one another are a run and share the tied sets of the first, so that together they are one small table of the events among the level participants) and hands it the results between the participants of a set and the entries those results give, so the value depends on the set and on nothing else. **BetweenTiedParticipants** wraps any tiebreaker as the measure. It is also how the calculator knows where the second choice of the awarded result policy applies
- **RankingStrategyWithByeValue**: The optional interface by which a ranking strategy says what a bye is worth; `WinDrawLossRanking` implements it (the value of a win unless another is given, `bye` in its plain data). Without it a table with a bye to credit is refused
- **TiebreakerWithByeTreatment / ByeTreatment**: The optional interface by which a tiebreaker says how it treats a bye (not at all, as an opponent worth nothing, as an opponent with the participant's own ranking value), and is handed entries that carry the byes. `BuchholzTiebreaker` and `SonnebornBergerTiebreaker` implement it, at `Uncounted` by default
- **TiebreakerChain**: Builds the library's tiebreakers from a list of identifiers (the names the tiebreakers give themselves) and writes one
- **Adjustment**: An amount of ranking value for one participant that comes from no event (a participant ID, a signed amount, an optional label and reason the library never reads, and, on a stage, the round it was made after, which a table calculated on its own does not read). It is on the scale of the ranking strategy, so every strategy accepts it. An entry keeps it apart from what was earned; tiebreaker values are calculated before it is applied, so a tiebreaker over opponents' ranking values uses what an opponent earned
- **EntryOrder** (`@internal`): The comparisons a table is ordered by (the figures of two entries, and two IDs), shared by the calculator, the crediting of byes and the Swiss engine
- **RankingStrategy**: Computes the primary ranking value ordering the table — ordering is the contract, points are one means of producing it
- **WinDrawLossRanking**: The first implementation; sport conventions as named constructors (`threeOneZero()`, `oneHalfZero()`), config-constructible via `fromArray()`
- **TiebreakerInterface**: Pluggable tiebreakers — **WinsTiebreaker**, **ScoreDifferenceTiebreaker**, **ScoresForTiebreaker**, **BuchholzTiebreaker**, **SonnebornBergerTiebreaker**, and **RankingValueTiebreaker** as a measure between the tied participants
- **Standings / StandingEntry**: Immutable table and per-participant line. A table may have tiers, a criterion before the records (the round an entrant went out of a bracket in); `withTiedSetsOrderedBy()` puts the entries of every tied set in the order of an entrant list and moves nothing else. `withDecisions()` puts a tied set in an order the application decided outside the results (a `DTO\Decider`: participant IDs, best first, with an opaque label and reason) and carries the decision as the mark of those positions (`getDecisionFor()`, `getDecisions()`); a decision orders one whole tied set, so it never moves an entry past one it is not level with, and anything else is refused with a `RejectedDecisionException` ([ADR 0008](adr/0008-a-decision-orders-level-entries-and-never-a-result.md)). `getRank()` and `getRanks()` give the rank the entries of an undecided tied set share, where `getPosition()` counts every entry; the entries of a set a decision ordered have their positions as their ranks. An entry says how many byes it has (`getByes()`), how many of its results were awarded (`getAwarded()`), and what its results are worth, what was earned with the byes and what was adjusted (`getRankingValueOfResults()`, `getEarnedRankingValue()`, `getAdjustment()`; `getRankingValue()` is the last two together and is what orders the table)
- **TiedSet**: A group of adjacent entries that no result orders (the same tier, and level on every figure), with the positions it spans. `Standings::getTiedSets()` derives the sets from the entries on each call with `StandingEntry::isLevelWith()`, which compares what the calculator compares before the fallback (ranking value, each tiebreaker value, score difference, scores-for) with the same exact comparison, so the sets cannot disagree with the order of the table. Reporting only: the order of the entries is unchanged. A set that a decision settled is still reported, with the decision (`getDecision()`)
- **DecidedOrder** (`@internal`): Applies decisions to an order given as groups of level entries, for a table and for a comparison across pools alike, so that both accept and reject the same decisions

### Leg Strategies
Legs belong to round robin. No other format has them: `SwissPlan`,
`EliminationPlan` and `PotDrawPlan` report `getLegs()` as null, and the
timeline, repack, quality and standings components have no leg concept.
- **LegStrategyInterface**: Two methods. `planLegs()` returns a `LegPlanContribution`; `generateEventForLeg()` is handed the two participants of one seating of a leg and returns the event for it, which is where a strategy decides the roles of that leg
- **MirroredLegStrategy**: Every even-numbered leg has the two participants of each seating in the other order, and every odd-numbered leg has them as they are laid out, so the legs alternate
- **RepeatedLegStrategy**: Every leg has the seatings as they are laid out
- **ShuffledLegStrategy**: Every leg after the first has each seating in a random order, drawn from the integer seed or from the `Randomizer` it was given, and it is refused without either. With a seed its draws start again from that seed whenever `planLegs()` is called, which the scheduler does at the start of every `schedule()` call, so the roles are the same on every call
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
- **DiagnosticReport**: The analysis of one failed generation: expected, generated and missing event counts, missing pairings, blocked pairings, per-constraint attribution and suggestions, each as a list of strings for a person and as typed data by participant ID (`MissingPairing`, `BlockedPairing`, `ConstraintRejection`, `PairingAtCapacity`) for an application to render
- **SchedulingDiagnostics** (`@internal`): Builds that report by probing — each missing pairing is put to the whole constraint set and to each constraint, in every candidate round and both orientations, against the events that were generated. Yields blocked pairings (culprits named), per-constraint rejection counts, and structural-fullness notes; attached to round-robin generation failures via `IncompleteScheduleException::getAnalysis()`. It is evidence about the schedule that was built, not a proof that no schedule exists; only the backtracking search states that, when it exhausts its search space. See [diagnostics-attribution.md](design/diagnostics-attribution.md)

`IncompleteScheduleException::getDiagnosticReport()` renders the analysis and
then adds general suggestions that it picks by the class of the constraints
that recorded violations. Those are generic advice, not findings.

### Exception Hierarchy
- **TacticianException**: Marker interface (extends `\Throwable`, adds no method) implemented by every exception the library throws on purpose, so one catch clause covers the library. `tests/Feature/ExceptionMarkerTest.php` fails for a `throw` in `src/` of anything else
- **SchedulingException**: Abstract base class of the scheduling failures (extends `\Exception`); each carries a diagnostic report. It is not the base of every exception: the five classes at the bottom of this list are outside it
  - **InvalidConfigurationException**: A configuration that cannot work. Carries a reason (`getReason()`, an `InvalidConfigurationReason` case set at every site that builds one; `tests/Feature/ConfigurationErrorReasonsTest.php` reads the source to check it), the values involved (`getContext()`), and the requirements of the failing component
    - **PinConflictException**: One participant pinned in two events at one position of a repack request; carries the two event IDs
    - **UndecidedSelectionException**: A rank selection that results do not decide; carries the IDs of the level participants, the places they hold between them and the pool
    - **UnresolvedRoundException**: The next round of a results-driven stage asked for while an event of a recorded round awaits its result; carries the events and the round. Its reason is `RoundPartiallyResolved`, the one the elimination engines gave before the class existed
  - **IncompleteScheduleException**: Schedule incomplete due to constraint conflicts
  - **NoValidPairingException**: No complete Swiss pairing exists for a round
  - **RepackViolationsException**: A repack outcome with violations, raised only when the caller asks for an exception
- **InvalidInputException** (extends `\InvalidArgumentException`): A rejected argument, or malformed data given to `fromArray()`/`fromJson()`
- **RejectedDecisionException** (extends `\InvalidArgumentException`): A decision that does not order one whole set of level entries of a table or of a comparison across pools; carries a `RejectedDecisionReason`, the decision, the IDs at fault and, from a selector, the pool or the rank. Unchecked, as rejected input is: a table without decisions puts no checked exception on the code that builds it
- **JsonConversionException** (extends `\JsonException`): JSON that cannot be read or written; wraps the PHP exception, keeping its message and code
- **InvariantViolationException** (extends `\LogicException`): A state the library's own logic rules out — a defect, not a caller mistake
- **UnavailableValueException** (extends `\LogicException`): A value asked of an object that does not hold it (a time from a shape-only `SessionGrid`, the kickoff of an assignment made on one, the capacity of an unbounded grid as a number) — a caller mistake that the object's `has...()` method would have prevented; not a configuration error and not a library defect. Unchecked on purpose, so a method that throws it puts no checked exception on callers that never hold such an object

One more class carries the marker and is not in the list:
`Repack\Internal\BudgetExhausted` (extends `\RuntimeException`,
`@internal`), which the repacker throws and catches itself when its step
budget runs out. It never reaches a caller.

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

**Position is authoritative wherever an entrant list exists.** Bracket
folding, pool distribution and pot membership take entrants from their
position in the list they are given. Where results do not decide an order,
the same position decides it: the order a Swiss round is paired from and
who has its bye, the order the survivors of a re-seeded elimination round
are folded in, the table an engine builds for a stage, and what a
`RankRangeSelector` returns when it is told to accept an undecided order.
A decision (`Standings::withDecisions()`, or one given to a rank selector)
is the one thing that comes before position: it orders a tied set of a
table that is shown or selected from, and no engine reads one.
None of them reads a label or `Participant::getSeed()`, and none reads the
spelling of an ID to order two entrants that have a position. The entrant
list of an engine is the participant list of its `StageState`. For a Swiss
stage that is the active participants as started, then those who have
withdrawn, who have no position in a table any more and follow in the order
of their IDs. A bracket reads `StageState::getEntrants()`, in which an
entrant who withdrew from the live bracket keeps the place it was folded
from, so that a withdrawal moves nobody.
`StageOutcome` carries the list so that a selector reads the order the table
was put in. `Standings::withTiedSetsOrderedBy()` is the one place the rule
is applied to a table.

The seed attribute is read in two places: `SeedProtectionConstraint`, and
the last fallback of a table that `StandingsCalculator::calculate()`
builds on its own, with no entrant list (after ranking value, tiebreakers,
score difference and scores-for, and before the ID). That fallback is
there so that a standalone table does not depend on the order of the array
it was given. `Standings::getTiedSets()` reports the entries that no result
orders, whichever of the two decided between them
([ADR 0004](adr/0004-position-decides-where-results-do-not.md)).

### How a round robin is generated

1. **Options and plan.** The scheduler checks the participants (at least
   two, unique IDs), asks the leg strategy for its `LegPlanContribution`,
   and builds the `RoundRobinPlan`. An invalid configuration fails here,
   before any event exists.
2. **Layout.** A leg is laid out by the circle method: the first seat is
   fixed, the others rotate, and seat *i* meets seat *n − 1 − i*. A field
   of odd size gets a bye seat. The roles proposed alternate with the
   parity of the round within the leg. With a `Randomizer` the participant
   order is shuffled first. The first leg is the one leg that is laid out:
   every later leg has its seatings, in the same rounds of the leg.
3. **Roles.** The configured `RoleAssignmentInterface` is handed the leg
   and returns it with seatings unchanged or reversed; the scheduler
   refuses any other answer.
4. **Legs after the first.** The leg strategy turns each seating of the
   first leg's layout into the event of that leg.
5. **Constraints.** Each event is put to the constraint set against a
   context holding the events accepted so far. A rejected event is dropped
   and recorded as a violation; generation of the leg goes on, and a leg
   that ends short is a failure. One thing is tried in place of a rejected
   event: where the leg strategy states its roles as preferences (the
   shuffled legs), `LegRoleSearch` gives the event the other role, and
   searches the roles of the later legs when both are rejected, within a
   bound of rejected roles.
6. **Retry.** With a constraint set, a failed attempt is repeated with the
   participant list rotated by one, up to min(participants, 25) attempts in
   all. Without one there is a single attempt. Only the last attempt's
   violations and failure analysis are reported.
7. **Roles of the first leg, within the role assignment's rule**, if every
   rotation failed and the role assignment states a rule (the two balanced
   ones do): the layouts are gone through again. In each, the roles are put to
   the constraints in order; a seating whose event a constraint of unknown
   reach rejects is reversed and fixed, and the role assignment is asked for
   roles around the fixed seatings. Where that does not end with a
   schedule, `LegRoleSearch` searches the roles of the first leg, each
   choice held to the rule, with the later legs following by the leg
   strategy. One allowance of rejected roles and of events put to the
   constraints serves all the layouts.
8. **Backtracking**, if `RoundRobinOptions(backtracking: true)` and nothing
   before it found a schedule: `BacktrackingRoundRobinGenerator` searches
   the round decompositions of the first leg, and the later legs are derived
   from each leg it finds through the leg strategy. The roles of all legs of
   each first leg are searched: within the rule of the role assignment as in
   step 7, or, where it states none, freely by `LegRoleSearch`, with the
   role assignment asked whether it keeps the leg. A first leg that no
   roles complete sends the generator on to the next. See
   [backtracking-generation.md](design/backtracking-generation.md).
9. **Validation.** The finished schedule is checked against the plan (see
   [Validation & Diagnostics](#validation--diagnostics)) and returned with
   the plan's shape and the byes in its metadata.

Steps 2 to 8 are what the scheduler does under one role assignment, held to
it. Under the default, `BalancedWherePossibleRoleAssignment`, it does them
up to three times in a call, and what it returns is the first schedule with
balanced roles, or failing that the schedule of the roles the generator
proposes, marked (`RoleFallback` in the metadata). The order depends on
what the role search can know about the constraints
(`ConstraintPurity::readsOwnEventsOnly()`):

- **The library's own constraint classes in a `ConstraintSet`, or no
  constraints.** Steps 2 to 8 with the balanced roles; if they fail, steps 2
  to 8 with the roles the generator proposes, whose failure is the failure
  of the call.
- **Anything else** (a closure, a metadata rule, a class of the caller's, a
  subclass). The balanced roles as given in the first layout; then steps 2
  to 8 with the roles the generator proposes; then steps 2 to 8 with the
  balanced roles. With a schedule in hand that last run is held to 4,000
  events put to the constraints and makes a backtracking search only where
  the schedule in hand came from one: the fall-back is not made to wait for
  a search that can take seconds. Within the 4,000 it is the run of
  `BalancedRoleAssignment`, the search that goes back one event at a time
  included.

Each run starts where a call starts: the shuffles a `Randomizer` gave the
call are read again from the first, and a leg strategy that draws is asked
for its plan again. No failure of a balanced run reaches the caller, so none
is analysed. [role-assignment.md](design/role-assignment.md#the-default) has
the reasons and what was measured.

The plan and the generators state the round-robin arithmetic separately.
The scheduler reads the number of legs, the events per leg and the rounds
per leg (for the round numbers of later legs) from the plan, and the circle
layout derives the rounds of a leg from the seat count itself; the
backtracking generator reads the rounds per leg from the plan. What keeps
the two in agreement is step 9, which fails a schedule that does not match
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
│      state = state->withRoundPaired(pairing) │◄── recorded when it is drawn
│      results = playRound(pairing)            │    ...played application-side...
│      state = state->withAdditionalResults(…) │◄── state serializes between steps
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
An engine keeps no record of the stage: every call reads the rounds
played, the standings and the bracket from the `StageState` it is given.
**The recorded rounds are facts.** An engine reads them as recorded and
derives only the round that does not exist yet; it never pairs a recorded
round again, and a recorded round it would have paired otherwise is reported
as manual and not refused
([ADR 0007](adr/0007-recorded-rounds-are-read-not-derived.md)). A recorded
round that still awaits a result, or the decider of a bracket tie that
finished level, is the normal condition of a stage being played: reading it
(`isComplete()`, `getOutcome()`, and the three methods of
`HistoryReadingEngine`) throws nothing, and only `pairNextRound()` refuses,
with the events it is waiting for or the tie that is undecided. The
state is what an application stores between rounds, or builds on each
request from its own rows (`StageState::fromHistory()`); the engine is rebuilt
from the application's configuration (an
[engine fingerprint](USAGE.md#recording-which-engine-pairs-a-state) on the
state detects a mismatch). A `Randomizer` given to a Swiss engine is not
part of the state.

### Namespace dependencies

Which namespace of `src/` refers to classes of which other, from the code
(docblocks not counted, so a namespace whose classes are named only in the
type of a docblock, as `DTO\Participant` is in `RoleAssignment`, is not
listed). Namespaces are relative to `MissionGaming\Tactician`.

| Namespace | Refers to |
|-----------|-----------|
| `DTO` | `Exceptions` |
| `Standings` | `DTO`, `Exceptions` |
| `RoleAssignment` | `Exceptions` |
| `Stage` | `DTO`, `Exceptions`, `Standings` |
| `Timeline` | `DTO`, `Exceptions`, `Stage` |
| `Quality` | `DTO`, `Exceptions`, `Stage` |
| `Repack` | `DTO`, `Exceptions`, `Timeline`, `Repack\Internal` |
| `Repack\Internal` | `Exceptions`, `Repack` |
| `Constraints` | `DTO`, `Exceptions`, `Scheduling` |
| `LegStrategies` | `Constraints`, `DTO`, `Scheduling` |
| `Validation` | `Constraints`, `DTO`, `Exceptions`, `Stage` |
| `Diagnostics` | `Constraints`, `DTO`, `Scheduling`, `Stage` |
| `Scheduling` | `Constraints`, `DTO`, `Diagnostics`, `Exceptions`, `LegStrategies`, `RoleAssignment`, `Stage`, `Standings`, `Validation` |
| `Exceptions` | `Diagnostics`, `Repack`, `Stage`, `Validation` |

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
  options, `SwissScheduler` from the seed in its options when they have
  one, `SwissPairingEngine` one per round from the seed it is given in
  place of a randomizer and the round number, and `ShuffledLegStrategy`
  from the seed it is given in place of a randomizer). Nothing in `src/`
  builds an unseeded one.
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
  string identifiers, and so have a win/draw/loss ranking, the library's
  tiebreakers (`TiebreakerChain`) and a standings calculator built of
  them. Constraints, quality metrics, a `Randomizer` and a ranking
  strategy or a tiebreaker of the application have no plain-data form; an
  application builds those in code.
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
- **Constraints**: within one attempt every candidate event is put to the constraint set once, and a set stops at the first constraint that rejects; a rejected event is then put to each constraint once more to record which rejected it. What a constraint costs depends on what it reads from the context
- **Indexed context**: `SchedulingContext` answers "which events hold this participant, this pair, this round, this leg" from an index of its event list (`EventIndex`), built on first use and extended, not rebuilt, by `withEvents()`. A lookup costs the events it returns. Before the index every lookup scanned the whole schedule, so one check cost as much as the schedule was long: `NoRepeatPairings` over two legs took 0.12 s at 32 participants and 2.1 s at 64, and takes 0.009 s and 0.07 s now. `tests/Unit/Scheduling/EventIndexTest.php` holds every lookup to a scan. A constraint that calls `getExistingEvents()` and scans the list itself does not benefit
- **Role alternation**: the balanced roles (the default gives them wherever it does not fall back, which without constraints is everywhere) keep the running role imbalance of a participant within one leg at 1 for a field of even size and 2 for a field of odd size; the round-parity roles the generator proposes bound it at 3 and 4, whatever the field size (both checked for 2 to 30 participants)

### Failure paths
- **Bounded retries**: a constrained round robin that cannot be completed costs up to min(participants, 25) generation attempts
- **Failure analysis once**: the analysis probes every missing pairing in every round and costs far more than an attempt. It is built for the ordering whose exception is thrown, and not for the ones the retry loop goes on from, when the constraints are ones `ConstraintPurity` knows; for a set that runs code of the caller's it is still built for every ordering (see [diagnostics-attribution.md](design/diagnostics-attribution.md))
- **Step budgets**: the backtracking search and the repacker stop after a fixed number of search steps. A budget bounds steps, not time; what a step costs is in [backtracking-generation.md](design/backtracking-generation.md) and under [The Step Budget](USAGE.md#the-step-budget)
- **Swiss rounds**: a round that has no dead end is paired by a plain depth-first search. See `SwissRoundSearch` above for when the pruned search takes over
