# Usage Guide

How to use Tactician, format by format. The test suite executes the `php` blocks of this guide, all but one sketch that says so, and checks the values and output they state.

## Table of Contents

- [Terminology](#terminology)
- [Basic Usage](#basic-usage)
- [Participants and Events](#participants-and-events)
- [Constraint System](#constraint-system)
- [Multi-Leg Tournaments](#multi-leg-tournaments)
- [Results and Standings](#results-and-standings)
- [Swiss Tournaments](#swiss-tournaments)
- [Pot Draws](#pot-draws)
- [Elimination Brackets](#elimination-brackets)
- [Stages Resolved from Your Own Rows](#stages-resolved-from-your-own-rows)
- [Pools, Progression, and Multi-Stage Tournaments](#pools-progression-and-multi-stage-tournaments)
- [Timeline Assignment](#timeline-assignment)
- [Schedule Repacking](#schedule-repacking)
- [Schedule Quality and Optimization](#schedule-quality-and-optimization)
- [Serialization](#serialization)
- [Framework Integration](#framework-integration)
- [Schedule Validation](#schedule-validation)
- [Error Handling](#error-handling)
- [Advanced Patterns](#advanced-patterns)
- [Real-World Examples](#real-world-examples)
- [Performance Considerations](#performance-considerations)
- [Deprecations](#deprecations)

## Terminology

Tactician uses these terms consistently across the API, documentation, and
diagnostics. Note that the library deliberately says **participant** rather
than "team" — participants can be players, clubs, squads, debate teams, or
anything else that competes.

| Term | Meaning |
|------|---------|
| **Participant** | An entity that competes. Identified by a unique string ID, with a display label, optional seed, and metadata. The ID is any string and is compared exactly: see [Participant IDs](#participant-ids). |
| **Event** | A single match/fixture between participants (usually two). Sports platforms often call this a fixture. |
| **Pairing** | The unordered combination of participants in an event — "Alice vs Bob" regardless of who is home. |
| **Round** | A set of events played at the same stage of the tournament. Round numbers are 1-based and continuous across legs (a two-leg, 4-participant round robin has rounds 1–6). |
| **Leg** | One complete cycle of pairings. The leg count is *the number of times each participant meets each other participant*: a home-and-away league is 2 legs. Swiss and elimination formats have no legs concept. |
| **Home/Away roles** | The participant order within an event: the first participant is home, the second away. The round-robin generator proposes roles that alternate with round parity, which bounds how far a participant's home and away counts can drift apart and does not make them equal. The balanced **role assignment** does, as far as the number of events allows, and the default gives its roles wherever it finds a schedule with them. |
| **Role** | The position of a participant in an event: first-named (index 0 of `getParticipants()`) or second-named (index 1). The neutral name for what a sport calls home and away, white and black, or server and receiver. |
| **Role assignment** | The rule that decides which participant of each round-robin pairing is first-named (`RoleAssignmentInterface`), one leg at a time, without changing who meets whom or when. `BalancedRoleAssignment` ends each leg with every participant's two role counts at most 1 apart in a field of even size and equal in a field of odd size, or the schedule is refused; `RoundParityRoleAssignment` (the default up to 0.2.x) alternates with round parity; `BalancedWherePossibleRoleAssignment` (the default) gives the balanced roles where the scheduler finds a schedule with them and otherwise the round-parity roles, with a **role fall-back** mark. A role assignment may state the rule it holds a leg to, and the scheduler then looks for other roles within that rule where a constraint rejects the ones it gave. See [Role Assignment](#role-assignment). |
| **Role fall-back** | What the default role assignment of a round robin does where it finds no schedule with balanced roles: the schedule is generated with the roles the generator proposes, exactly as under `RoundParityRoleAssignment`, and its metadata says so under the key `role_fallback`, with the reason (`RoleFallback`): no balanced roles exist in what was searched, a bound stopped the search, or the search was cut short because a schedule was in hand. A schedule with balanced roles has no such key. See [Balanced where possible](#balanced-where-possible). |
| **Fixed seating** | A seating is one pairing of a round as a role assignment is handed it: two seats, with the first-named participant in the first. A fixed seating is one the role assignment is told to keep as given (`RoleAssignmentContext::isFixed()`): the scheduler put its event to the constraints the other way round, they rejected it, and it asks the role assignment for the roles of the other seatings around it. See [Writing a role assignment](#writing-a-role-assignment). |
| **Bye** | A participant sitting out a round (odd participant counts). Byes are never emitted as events: a schedule lists them by round (`Schedule::getByesByRound()`, read from the events; round robin also records them in the `byes` schedule metadata), and the Swiss/elimination engines report them on the round pairing. A bye is no result. A table counts it as a figure of its own (`StandingEntry::getByes()`) and credits it with the **bye value**. See [Byes in a table](#byes-in-a-table). |
| **Bye value** | What one bye adds to the ranking value of the participant who had it, as the ranking strategy states it (`RankingStrategyWithByeValue::getByeValue()`): the value of a win, of a draw, nothing, or any other amount. `WinDrawLossRanking` values a bye as a win unless told otherwise. The same value orders the next round of a stage and stands in its final table. |
| **Bye treatment** | How a tiebreaker computed from opponents counts a bye (`ByeTreatment`): not at all (`Uncounted`, the default), as an opponent worth nothing (`Zero`), or as an opponent with the participant's own ranking value (`OwnRankingValue`); the last two read an opponent's ranking value with the credit of its byes. |
| **Seed** | A participant's ranking, as an optional attribute (`Participant::getSeed()`). Lower numbers are better; 1 is the top seed. The library reads it in two places: `SeedProtectionConstraint` takes the top seeds from it, and a standings table calculated on its own, with no entrant list, uses it in its last fallback, between scores-for and the ID (see **Tied set**). No stage reads it. Wherever an entrant list exists, the position in that list decides what results do not: bracket placement, serpentine pool distribution, pot membership, the pairing order and the byes of a Swiss stage, the survivors of a re-seeded elimination round, the table of an engine's outcome and a rank selection. Put the list in seeding order. Not the seed of a random draw (see [The seed and determinism](#the-seed-and-determinism)). |
| **Schedule** | The complete, validated collection of generated events plus metadata. |
| **Result** | The recorded outcome of an event: a winner or a draw, with optional per-participant scores. The event was played, unless the result is an **awarded result**. A result of a knockout tie that finished level may carry a **decider**. |
| **Awarded result** | A result of an event that was not played, given to it by a decision of the application (`Result::isAwarded()`; the mark is an `Award` with an optional label and reason that the library never reads). It is a result in every respect: it counts for the winner it names, is the one result of its event, and settles what a result settles (who advances from a knockout tie). Two things are the caller's choice, on the standings calculator (`AwardedResultPolicy`): whether its scores count, and whether a tiebreaker computed between the tied participants only reads it. See [Awarded results](#awarded-results). |
| **Decider** | The record of a decision made outside the results, where the results leave a knockout tie level (`DTO\Decider`, the `decider` argument of `Result`): the IDs of the participants who advance, as a list (one, for a tie between two), and an optional label and reason that the library never reads. The library records that the tie was decided and for whom, and never how. It holds no score and changes none: the result keeps the scores of the event as played. See [Recording a Level Event](#recording-a-level-event). The same type, given to a table or a rank selector, is a **decision**: its participants are then the level entries of a tied set, in the order decided. |
| **Adjustment** | An amount added to, or taken from, one participant's ranking value for a reason that is no event (`Adjustment`: a participant ID, a signed amount, an optional label and reason that the library never reads). The amount is on the scale of the ranking strategy, whatever that is: points under `WinDrawLossRanking`. A table shows it as a figure of its own beside what was earned and is ordered by the two together; a stage state carries the adjustments of its stage. See [Adjustments](#adjustments). |
| **Standings** | The ordered table computed from results by `StandingsCalculator` — ranking values, records, and tiebreakers. |
| **Ranking strategy** | The pluggable rule ordering a standings table (`RankingStrategy`): it computes each participant's primary ranking value from their results, higher is better. `WinDrawLossRanking` (points from wins/draws/losses) is the built-in implementation; placement- or score-aggregating strategies slot in without touching the calculator. |
| **Tiebreaker between the tied participants** | A tiebreaker whose value for a participant comes from the events between it and the participants it is level with, and from no other (`TiedParticipantsTiebreaker`; `BetweenTiedParticipants` wraps any measure). A sport calls it head-to-head. The calculator computes it for each tied set, and several of them in a row for the same tied sets, as one small table of the events among the level participants. See [Between the tied participants](#between-the-tied-participants). |
| **Rank** | The place the results give an entry of a table (`Standings::getRank()`): its position, or, for an entry of a **tied set** that no **decision** ordered, the first position of the set, shared by all of the set. Positions are never shared; ranks are, where neither results nor a decision decide. |
| **Tied set** | Two or more adjacent entries of a standings table that are **level**: equal on the ranking value, on every configured tiebreaker, on score difference and on scores-for (and, in the table of a bracket, out in the same round), so that no result orders them. What does is the position in the entrant list, in the table of a stage, and the final fallback (seed, then ID) in a table calculated on its own. `Standings::getTiedSets()` reports each one (`TiedSet`) with the positions it spans. The application can settle one with a **decision**. |
| **Decision** | The order of a set of level entries, settled outside the results by the application and given to the library as a `DTO\Decider`: the IDs of the level participants, best first, with an optional label and reason that the library never reads. `Standings::withDecisions()` applies it to a tied set of a table, and a rank selector takes it with `withDecisions()`; the table (`getDecisionFor()`) and the selection (`RankSelection`) mark every place it settled. It orders one whole set of level entries and never overrides a result: anything else is rejected with a reason (`RejectedDecisionException`). The library records that the order was decided and never how. See [Settling a tied set by a decision](#settling-a-tied-set-by-a-decision). |
| **Entrant list** | The ordered list of participants a stage is given: `StageState::start()` or `StageState::fromHistory()` for an engine, the list handed to a scheduler or to `PoolDistributor`, the last argument of a `StageOutcome` built by hand. Position 1 is first. Its order is authoritative: it places entrants, and it decides every order that results leave open. A label, a seed attribute or an ID never does. An entrant who withdraws once a round is recorded keeps its place in the list of a stage state (`StageState::getEntrants()`), so nothing is drawn again. |
| **Tier** | A group of entries of a table that a criterion before the records puts together (`Standings::getTiers()`). The table of an elimination outcome has tiers: the entrants who went out in the same round share one, and no record moves an entrant from one tier to another. |
| **Constraint** | A hard rule on one candidate event, evaluated during generation (rounds between repeat meetings, seed protection, role limits...). Constraints either hold or generation fails loudly with diagnostics — there are no soft preferences. A constraint sees rounds, never times; rules about time are **timeline rules**. |
| **Stage** | One phase of a multi-stage tournament (e.g. a group stage feeding a knockout) — Tactician's unit of work: participants in, a schedule or round-by-round pairings out, a `StageOutcome` when play completes. Stages compose via pools and progression selectors. |
| **Pool** | A bucket of participants (`PoolDistributor::serpentine()`): what format the bucket plays, how it is scored, and how it progresses are separate, configurable concerns. |
| **Pot** | One of the equal blocks a seeded list of entrants is cut into for a pot draw, in list order: the first block is pot 1, the next pot 2, and so on. A pot is not a pool: the members of a pool play each other and are ranked together, while a pot only limits who an entrant can draw. |
| **Pot draw** | A format in which every round is drawn before any event is played (`PotDrawScheduler`): every entrant meets the same number of opponents from every pot, its own included, with no rematch, and is in every round. Often called "Swiss", but nothing is paired from results. See [Pot Draws](#pot-draws). |
| **Progression selector** | The hand-off between stages (`ProgressionSelector`): consumes a `StageOutcome`, returns the ordered entrant list of the destination stage. Standings-based (`RankRangeSelector`, `BestOfRankSelector`) or outcome-based (`MatchOutcomeSelector`) — never winners reconstructed through points arithmetic. Optional machinery: a bare ordered list is always a valid stage entry. |
| **Best of a rank** | The best few of the entrants that hold one rank in their pools, compared with each other across the pools and listed best first (`BestOfRankSelector`): the two best of those placed third. See [The best of a rank across pools](#the-best-of-a-rank-across-pools). |
| **Pool comparison** | How entrants of pools of unequal size are compared (`PoolComparison`): by raw totals, per event played, or without the results against the lowest-placed entrants of the larger pools. The caller's choice; a selection across pools of unequal size is refused until it is made. Pools are unequal when their tables do not have the same number of entries. |
| **Tie (elimination)** | One knockout pairing, played over one event or two mirrored legs (`legsPerTie`). A tie that finishes level (a drawn single event, or two legs that do not decide) is decided by the application under its own rules, and the participant who advances is recorded as the **decider** of the event's result, or of one leg's result. Ties are not legs: brackets have no legs concept. |
| **Third-place tie** | An opt-in second tie in the last round of a single elimination bracket (`EliminationOptions(thirdPlace: true)`), between the two entrants who went out in the round before it. It decides places 3 and 4, which the bracket otherwise leaves to the records. It is in the same round as the final and has the same round label, so each event of that round carries which tie it belongs to (**bracket tie**). See [A third-place tie](#a-third-place-tie). |
| **Bracket tie** | The typed statement of which tie of a bracket's last round an event belongs to (`BracketTie`: `Final` or `ThirdPlace`), written by the engine as a string under `bracket_tie` in the event's metadata and read back with `BracketTie::of()`. Present only on the events of the last round of a bracket with a third-place tie option. |
| **Options** | The typed per-algorithm configuration object a scheduler accepts (`RoundRobinOptions`, `SwissOptions`, `PotDrawOptions`): legs mean legs, rounds mean rounds, and passing another algorithm's options fails loudly. All options are plain-data constructible (`fromArray()`/`toArray()`) with stable identifiers for config-driven platforms. |
| **Stage engine** | A results-driven pairing engine (`StageEngineInterface`): it consumes a `StageState` and produces the next `RoundPairing`, reports structural completion (`isComplete()`), and yields the `StageOutcome`. One driver loop covers every engine-based format. |
| **Stage state** | The record of a results-driven stage between rounds (`StageState`): the entrant list, who has withdrawn, the recorded pairings (with byes), results, and adjustments. It is built verb by verb and stored (`toJson()`), or built on each request from a history the application holds (`StageState::fromHistory()`). Withdrawals are `withoutParticipant()`, a result of the last recorded round is corrected with `withResultReplaced()`, and an adjustment is recorded with `withAdjustment()`. |
| **History (of a stage)** | What an application holds of a stage in its own storage: the entrants in their order, the rounds that were paired, the results recorded so far, who has withdrawn and after which round, the adjustments and after which round. A history is a set of facts. An engine reads the rounds as they are and derives only the round that does not exist yet; `StageState::fromHistory()` builds a stage state from one and refuses a history that breaks a **hard rule**. See [Stages Resolved from Your Own Rows](#stages-resolved-from-your-own-rows). |
| **Hard rule** | One of the three things `StageState::fromHistory()` requires of a history beside what recording a round always requires: round numbers 1, 2, 3 with none left out, every participant an entrant, and nobody in a round twice. A history that breaks one is refused with a reason; a round that only pairs the entrants otherwise than the engine would have is not. |
| **Manual round** | A recorded round that is not the round the engine would have paired from the rounds before it: paired by hand, or by other rules. It is read as recorded, never refused for it, and reported by `HistoryReadingEngine::getRecordedRounds()` (`RecordedRound::isManual()`) with the engine's own round beside it (the **derivation**). |
| **Unresolved event** | An event of a recorded round that still awaits its result. While there is one, the next round is refused (`UnresolvedRoundException`), the stage is not complete, and reading it throws nothing. `HistoryReadingEngine::getReadiness()` lists the events without an exception. See [Rounds That Are Not Finished](#rounds-that-are-not-finished). |
| **After round (of a withdrawal or an adjustment)** | When a withdrawal or an adjustment happened, in the rounds of its stage: the number of the last round that was recorded at that moment, 0 before the first round (`Adjustment::getAfterRound()`, `Withdrawal::getAfterRound()`). The verbs of a stage state record it without being told; a history that holds a round states it for every withdrawal and every adjustment. An engine reads a recorded round from the adjustments made, and the entrants still in the stage, before it was paired, which to a state is before it was recorded, so a round is recorded when it is paired (`withRoundPaired()`); a round that names an entrant was paired while that entrant was in the stage, whatever its withdrawal states. Only a withdrawal stored by 0.2.x has no round: its time is not known and is never read as one that suits. See [When a Withdrawal or an Adjustment Happened](#when-a-withdrawal-or-an-adjustment-happened). |
| **Settled tie** | A tie of a bracket as its engine settled it (`SettledTie`): who went on from it and who did not, also where no result says so because an entrant withdrew. The outcome of a bracket carries its last one (`StageOutcome::getSettledTies()`), and `MatchOutcomeSelector` reads it. |
| **Uncontested tie** | A tie of a bracket that is not played because one of its two entrants has withdrawn: the other entrant advances without an event, the bracket is not drawn again, and an event already paired for the tie awaits no result. Some sports call it a walkover. See [Withdrawals from a Bracket](#withdrawals-from-a-bracket). |
| **Over (of a bracket)** | The moment an elimination bracket's last ties are decided: the final and, where it has one, the third-place tie; in double elimination the grand final, or its reset where one is due. A last tie is decided by its results, or by the withdrawal of one of its entrants while the other is still in the stage, who then has it. A withdrawal after that takes nothing back: the winner and every placing stay. See [Withdrawals from a Bracket](#withdrawals-from-a-bracket). |
| **Paired round (recorded as)** | A round recorded when it is drawn, before any of its results exist (`StageState::withRoundPaired()`); its results are added as they come (`withAdditionalResults()`). Recorded so, it comes before whatever happens while it is played. See [Swiss Tournaments](#swiss-tournaments). |
| **Void tie** | A tie of a bracket both of whose entrants have withdrawn, with no result that decides it, before either went through it (before its next tie was paired). A last tie has no next tie and goes to the entrant still in when the other left, so it is void only where the times are not known (data stored by 0.2.x). A void tie sends nobody on: the slot it would have filled is empty, so the entrant who would have met its winner goes on alone, and in double elimination nobody drops from it to the losers bracket. Its two entrants go out in it. See [Withdrawals from a Bracket](#withdrawals-from-a-bracket). |
| **Results-free mode** | The mode of the Swiss engine in which rounds are recorded without results on purpose (`new SwissPairingEngine(resultsFree: true)`): no event awaits a result, and each round is a non-repeat pairing of the field. It is what the whole-schedule Swiss preset pairs in, and it is part of the engine fingerprint. Without it the engine waits for the results of a recorded round. |
| **Engine fingerprint** | An optional stamp on a stage state saying which engine pairs it (`StageState::withEngineFingerprint()`), as each engine's `getFingerprint()` gives it (`FingerprintedEngine`). An opaque string, compared for equality: it stands for the format and for the options that shape which rounds the format has or how they are paired, and an option at its default is not part of it, so an engine that gains an option still accepts the states stamped before. A patch release keeps the fingerprint of every configuration; a 0.x minor release may change the scheme ([how long it lasts](#recording-which-engine-pairs-a-state)). An engine refuses a stamped state whose fingerprint is not its own; an unstamped state is accepted by every engine. |
| **Score group** | In a Swiss stage, the participants who are level on ranking value. The engine pairs within the table order and shuffles within a score group when it has a randomizer. The ranking value it groups by includes the **bye value** for every bye a participant has had, as the table of the outcome does. Level means equal, with one allowance that is the Swiss engine's own and applies to the library's `WinDrawLossRanking` only: two of its totals that differ by no more than the rounding of a float sum are in one group, so the same results added in another order do not split a group. A standings table and `getTiedSets()` compare exactly, and so does the engine under any other ranking strategy. |
| **Stage outcome** | The uniform completion product (`StageOutcome`): standings, results, bye counts, the structural final round and the entrant list. Deliberately free of champion/winner vocabulary. The standings are the table of the whole stage: for an elimination stage the bracket placement, so rank 1 is the participant who advanced from its last tie (see [Who won the bracket](#who-won-the-bracket)). |
| **Round pairing** | One round's product from a stage engine (`RoundPairing`): round number, optional label ('semifinal'; null for Swiss), events, and byes, and a mark when the round was paired, on request, before the results of the round before it were all in. |
| **Timeline** | A stage's declarative slot model (`TimelineDefinition`): a zoned start, a round interval, and optionally several slots per round. Round-aligned scheduling is the one-slot case; staggered kickoffs are more slots. One timeline per stage. |
| **Slot** | One kickoff time within a round, holding one event per resource (one event total when no resources are declared). A round's events fill its slots deterministically: schedule order against slot time order, resource by resource within a slot. |
| **Kickoff** | The assigned time of a scheduled event, always emitted in UTC (`ScheduledEvent::getKickoff()`); the timeline adds its intervals in the stage's declared timezone (see [Timeline Assignment](#timeline-assignment) for what that means across a daylight-saving change). |
| **Resource** | A named host of concurrent events within a slot (venue, pitch, court, board...). A slot holds one event per resource; no declared resources means one anonymous resource. Each scheduled event carries its assigned resource. |
| **Quality metric** | A graded, lower-is-better measure of a valid schedule (`QualityMetric`): role balance, alternation streaks, rest rhythm, repeat spacing. Metrics measure defects — zero is ideal. Composed with weights by `ScheduleScorer`; policy (which metrics, what weights) stays application-side. |
| **Optimization** | Best-of-N sampling (`ScheduleOptimizer`): generate N candidate schedules from one master seed, score each, keep the best. Deterministic when every randomness source uses the supplied child randomizer. Whole-schedule generators only. |
| **Backtracking generation** | An opt-in round-robin search (`RoundRobinOptions(backtracking: true)`) over the round decompositions the circle method's rotations cannot reach, and over the roles of every leg. Greedy always runs first, and after it the search of first-leg roles that a role assignment's rule allows without the option; the search is deterministic and step-bounded, and failing it distinguishes a proven-unsatisfiable configuration from an exhausted budget. |
| **Role search** | What the round-robin scheduler does when a constraint rejects a role that is free to change: it gives the event the other role and, when that is rejected too, changes the roles of earlier events, with the pairings and rounds held. Free are the roles of the later legs of a leg strategy that states them as preferences (the shuffled legs), always; the roles of the first leg within the rule its role assignment states, where it states one (the two balanced role assignments do, round parity does not), once every rotated order has failed with the roles as given; and the roles of the first leg of every layout that backtracking generation finds. Bounded by a count of rejected roles and of events put to the constraints; see [Leg Strategies and Role Constraints](#leg-strategies-and-role-constraints). |
| **Timeline rule** | A time-aware rule (`TimelineRule`) validated over the assigned kickoffs — a minimum time between a participant's kickoffs (`MinimumRestRule`), blackout windows (`BlackoutRule`). Assignment is deterministic, so a violated rule fails loudly rather than being routed around; rules are not generation constraints. |
| **Session** | One match night (or day) on a repack grid: an ordered position in the grid's explicit session list, holding a fixed number of slots. Deliberately not a "round" — a round is a set of concurrent events, a session is a container of consecutive slots. |
| **Session grid** | The declarative position model repacking assigns onto (`SessionGrid`): an explicit ordered list of zoned session starts, a slot interval, a per-session slot count (overridable — final sessions often run deeper), and a per-slot concurrency capacity. Irregular by design, unlike `TimelineDefinition`'s cadence. A grid built this way is instant-based; see **Shape-only grid** for the other form. |
| **Position** | One assignable place on a session grid: a 0-based session and a 0-based slot within it. A position hosts as many concurrent events as the grid's capacity allows. |
| **Position ordinal** | A position's 0-based index when the positions of a grid are counted in grid order, session by session and slot by slot (`SessionGrid::ordinalOf()`, reversed by `positionOf()`). What an application indexes its own slot records by. |
| **Shape-only grid** | A session grid that knows which positions exist and not when they are (`SessionGrid::shapeOnly()`): a session count, the slot counts and a capacity. For an application that keeps its own times. It is repacked exactly as the instant-based grid of the same shape; its assignments carry no kickoff, and every accessor that would return a time throws an `UnavailableValueException`. |
| **Unbounded capacity** | A session grid whose slots host any number of concurrent events (`capacityPerSlot: null`; `'unbounded'` in plain data), so that the only limit is that no participant is in two events at once. Not the default, which is 1. |
| **Repack** | Repairing an existing schedule (`ScheduleRepacker`): assigning every movable event a (session, slot) position so nobody is double-booked and each participant's events within a session run back to back where possible. Returns a `RepackOutcome` carrying the schedule plus itemised violations rather than throwing. |
| **Movable event** | An existing event the repack may place (`MovableEvent`): an opaque caller-supplied stable id and two participants. The movable set is a multigraph — the same pairing may occur more than once. |
| **Pinned event** | An event already on the grid that must not move (`PinnedEvent`). It occupies its position for both participants, consumes slot capacity, and may name participants absent from the movable set. Which events are pinned is caller policy about historical provenance. |
| **Repack violation** | One structured compromise in a repack outcome (`RepackViolation`): double-booking (audited, never produced), unplaced events, interior gaps, late starts, exceeded capacity — data with participant/session/magnitude, never prose. The caller renders its own messages and decides what is fatal. |
| **Unplaced event** | A movable event the repack gave no position (`UnplacedEvent`), with the reason (`UnplacedReason`). Every movable event is either assigned or unplaced. |
| **Current position** | Where a movable event is before a repack, when the caller says so (`MovableEvent`'s last two arguments): a session and a slot of the request's grid. A statement of fact and never a demand; it need not be a position the grid has, nor a free one. An event that states none is new to the grid. |
| **Moved event** | A movable event that states a **current position** and is placed at another one, in another session or at another slot of the same session (`RepackOutcome::getMovedEventIds()`). An event with no current position is never moved, and neither is an unplaced one. How many events move is the churn of a repack. |
| **Gap slot** | A slot a participant waits through between two of its own events in one session: with events at slots 0 and 3, slots 1 and 2. `ContiguityBroken` reports them for a participant and a session, and `RepackOutcome::getGapSlotCount()` adds them up. A late start is not one. |
| **Lone-event session** | A session of a repacked grid that holds exactly one event, pinned events counted, where that event is a movable one (`RepackOutcome::getLoneEventSessions()`): the session is opened for it alone. A figure of the grid, not of a participant. |
| **Idle session** | A session a participant has no event in, between two sessions it has one in, pinned events counted (`RepackOutcome::getIdleSessionCount()`, summed over the participants that have a placed movable event). The sessions before a participant's first and after its last are not idle. |
| **Repack objective** | A figure of the outcome a repack is asked to keep low (`RepackObjective`, listed in order in `RepackOptions(objectives: ...)`): moved events, lone-event sessions, idle sessions. Objectives rank after events placed and gap slots, which they never cost, and before the two weights. None is the default. |
| **Shortest grid** | The fewest sessions on which a repack places every event (`ShortestGridFinder`), for a grid the caller describes as a function of its number of sessions. Searched one number at a time, upwards, and reported with what happened at every smaller number. |
| **Step budget** | The number of search steps a repack may spend (`RepackOptions(stepBudget: ...)`): the load planning and packing searches share one budget of that size, and the steps after the packing (the last placement step and the search for fewer gap slots) have a second one of their own, so a repack can spend up to twice that number. Steps, not time, so the result is the same on every machine. The searches that count their own work and not steps (laying the events out before any session is chosen, the last placement step, the search for fewer gap slots) have limits that are fixed at the default budget and above, and the same share of those limits as the budget is of the default below it, so a smaller budget shortens every search. `RepackOutcome::isBudgetExhausted()` says whether a budget stopped a search. |
| **Last placement step** | The part of a repack that runs only when an event is still unplaced: it moves placed events along alternating paths, and exchanges one placed event for one unplaced event where that lets a further one in, to place events the earlier phases left over. It never double-books, never moves a pin and never exceeds a slot's capacity. |
| **Outcome fingerprint** | A short string identifying what a repack outcome holds (`RepackOutcome::fingerprint()`): equal for the same assignments, unplaced events and violations, different when any differs, and the same on every PHP version and platform. For detecting that a plan computed again is not the plan that was shown. |
| **Pin conflict** | One participant pinned in two events at the same session and slot of a repack request. The request is rejected with a `PinConflictException`, which carries the IDs of the two events; the caller moves or unpins one of them. Where the two events also exceed the capacity of the slot, the request reports that instead (`PinCapacityExceeded`). |
| **Configuration error reason** | The kind of mistake behind an `InvalidConfigurationException`, as a case of the `InvalidConfigurationReason` enum (`getReason()`). It says what was wrong, not which component found it, and its backing string is a stable identifier. Code branches on the reason and never on the message text. See [Configuration Errors](#configuration-errors). |
| **Stage plan** | An algorithm's declaration of a stage's shape (`StagePlan`): stable algorithm identifier, total rounds, legs, rounds per leg, and expected event count, plus format-specific integrity validation. Built before generation; context, validation, diagnostics, and constraints read shape facts from it instead of inferring them. Null values are meaningful — legs are null where the concept does not apply (Swiss), totals are null when unknowable up front. |

## Basic Usage

### Simple Round-Robin Tournament

```php
<?php

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Constraints\ConstraintSet;

// Create participants
$participants = [
    new Participant('celtic', 'Celtic'),
    new Participant('athletic', 'Athletic Bilbao'),
    new Participant('livorno', 'AS Livorno'),
    new Participant('redstar', 'Red Star FC'),
];

// Configure constraints
$constraints = ConstraintSet::create()
    ->noRepeatPairings()
    ->build();

// Generate schedule
$scheduler = new RoundRobinScheduler($constraints);
$schedule = $scheduler->schedule($participants);

// Iterate through matches
foreach ($schedule as $event) {
    $round = $event->getRound();
    echo ($round ? "Round {$round->getNumber()}" : "No Round") . ": ";
    echo "{$event->getParticipants()[0]->getLabel()} vs {$event->getParticipants()[1]->getLabel()}\n";
}
```

### Tournament with Seeded Participants

```php
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

// Create seeded participants with metadata
$participants = [
    new Participant('celtic', 'Celtic', 1, ['city' => 'Glasgow', 'division' => 'Premier']),
    new Participant('athletic', 'Athletic Bilbao', 2, ['city' => 'Bilbao', 'division' => 'Premier']),
    new Participant('livorno', 'AS Livorno', 3, ['city' => 'Livorno', 'division' => 'Serie A']),
    new Participant('redstar', 'Red Star FC', 4, ['city' => 'Belgrade', 'division' => 'SuperLiga']),
];

$scheduler = new RoundRobinScheduler();
$schedule = $scheduler->schedule($participants);

// Access schedule metadata
echo "Algorithm: " . $schedule->getMetadataValue('algorithm') . "\n";
echo "Participant count: " . $schedule->getMetadataValue('participant_count') . "\n";
echo "Total rounds: " . $schedule->getMetadataValue('total_rounds') . "\n";
```

## Participants and Events

### Creating Participants

```php
use MissionGaming\Tactician\DTO\Participant;

// Basic participant (ID and label are required)
$participant = new Participant('player1', 'John Doe');

// Participant with seeding
$seededPlayer = new Participant('player2', 'Jane Smith', 1); // Seed 1 (top seed)

// Participant with metadata
$detailedPlayer = new Participant(
    id: 'player3',
    label: 'Team Alpha',
    seed: 2,
    metadata: [
        'skill_level' => 'Advanced',
        'region' => 'Europe',
        'equipment' => 'Standard',
        'availability' => ['monday', 'wednesday', 'friday']
    ]
);

// Access participant properties
echo $detailedPlayer->getId() . "\n";        // 'player3'
echo $detailedPlayer->getLabel() . "\n";     // 'Team Alpha'
echo $detailedPlayer->getSeed() . "\n";      // 2
echo $detailedPlayer->getMetadataValue('region', 'Unknown') . "\n"; // 'Europe'
```

### Participant IDs

An ID is any string but the empty one, and two participants are the same
participant only when their IDs are the same string. `'01'` and `'1'` are
two participants, and so are `'1e3'` and `'1000'`, although PHP compares
each pair as equal numbers. An ID may contain any character, including the
`|`, `:` and `\` that the library uses inside its own lookup keys, and may
be `'0'` or a single space; only `''` is refused, by
`new Participant('', ...)` with an `InvalidInputException`. The IDs of one
field must be unique; nothing else is required of them to generate a
schedule or to pair a stage. Writing a schedule or a stage state as JSON
also needs every ID to be valid UTF-8.

An event holds each participant once. `new Event([$celtic, $celtic])`
throws an `InvalidInputException`, and so does an event of two `Participant`
objects with one ID: a participant is not paired with itself.

```php
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

// Entry numbers as they were typed: two of them are the number one
$entries = [
    new Participant('01', 'Entry 01'),
    new Participant('1', 'Entry 1'),
    new Participant('a|b', 'Entry A and B'),
    new Participant('a', 'Entry A'),
];

$entrySchedule = (new RoundRobinScheduler())->schedule($entries);

echo count($entrySchedule) . " events\n"; // 6 events: every pair meets once
```

Two things to know when the IDs are numbers:

- PHP turns an array key such as `'42'` into the integer `42`. Where the
  library returns an array keyed by participant ID (`getByeCounts()`, the
  scores of a `Result`), cast the key back with `(string)` before you
  compare it with an ID.
- The last fallback of a standings table that stands alone orders IDs so
  that every set of IDs has one order, whatever order the participants were
  given in. An ID written as a whole number without a sign or a leading
  zero comes first, in the order of the values: `'9'` sorts before `'10'`.
  Every other ID follows, byte by byte: `'1'` is before `'01'`, and `'p10'`
  before `'p2'`. It is reached only when ranking value, tiebreakers, scores
  and seed are all equal, and never where the table belongs to an entrant
  list, whose order decides instead (see [Tied sets](#tied-sets)).

A participant is who its ID says it is. Everything in the library matches
participants by ID, the constraints included, so a second `Participant`
object with the same ID (a participant rebuilt from stored data and mixed
with the originals) is the same participant wherever it appears.

### Working with Events and Rounds

```php
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Round;

// Create a round with metadata
$round = new Round(1, ['phase' => 'group-stage', 'court' => 'A']);

// Create an event between two of the participants created above
$event = new Event(
    participants: [$participant, $seededPlayer],
    round: $round,
    metadata: ['start_time' => '10:00', 'referee' => 'John Smith']
);

// Access event properties
$participants = $event->getParticipants();
$roundNumber = $event->getRound()->getNumber();
$startTime = $event->getMetadataValue('start_time');

// Check if a participant is in the event
if ($event->hasParticipant($participant)) {
    echo "Participant is in this event\n";
}
```

## Constraint System

### Built-In Constraints

```php
use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Constraints\MinimumRestPeriodsConstraint;
use MissionGaming\Tactician\Constraints\SeedProtectionConstraint;
use MissionGaming\Tactician\Constraints\ConsecutiveRoleConstraint;
use MissionGaming\Tactician\Constraints\MetadataConstraint;

// Comprehensive constraint configuration
$constraints = ConstraintSet::create()
    ->noRepeatPairings()  // Prevent duplicate pairings within a leg
    ->add(new MinimumRestPeriodsConstraint(2))  // Two meetings of the same pair are at least 2 rounds apart
    ->add(new SeedProtectionConstraint(2, 0.4))  // Protect top 2 seeds for 40% of tournament
    ->add(ConsecutiveRoleConstraint::homeAway(3))  // Max 3 consecutive home/away games
    ->add(MetadataConstraint::requireSameValue('division'))  // Only pair within same division
    ->build();
```

> **Note:** `noRepeatPairings()` is scoped to the current leg by default, because
> multi-leg tournaments intentionally repeat every pairing once per leg. Pass
> `noRepeatPairings(acrossLegs: true)` to forbid repeats anywhere in the
> tournament — which by design makes complete multi-leg round robins
> impossible, so use it only as a hard invariant check. Also note that a
> single-leg round robin never repeats a pairing by construction, so the
> constraint is only load-bearing for generators without that structural
> guarantee.

> **Note:** `MinimumRestPeriodsConstraint` is about a pair, not about a
> participant's rest, and it counts rounds, not time: it rejects an event
> whose two participants last met fewer than the given number of rounds
> before. In a single leg no pair meets twice, so there it rejects nothing.
> Rest between a participant's own events, measured in time, is a timeline
> rule: `MinimumRestRule`, under [Time-Aware Rules](#time-aware-rules).

### Metadata-Based Constraints

```php
// Participants from the same region only
$sameRegion = MetadataConstraint::requireSameValue('region');

// Participants from different skill levels
$differentSkills = MetadataConstraint::requireDifferentValues('skill_level');

// Maximum 2 equipment types per match (this can only reject an event
// with more than two participants)
$equipmentLimit = MetadataConstraint::maxUniqueValues('equipment', 2);

// Adjacent skill levels only (skill level 3 can play 2 or 4, not 1 or 5)
$adjacentSkills = MetadataConstraint::requireAdjacentValues('skill_level');

// Combine multiple metadata constraints
$metadataConstraints = ConstraintSet::create()
    ->add($sameRegion)
    ->add($differentSkills)
    ->add($equipmentLimit)
    ->build();
```

### Custom Constraints

```php
use MissionGaming\Tactician\Constraints\ConstraintSet;

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\Scheduling\SchedulingContext;

// Custom constraint: a predicate that receives the candidate event and
// the scheduling context, and returns whether the event is allowed
$customConstraint = ConstraintSet::create()
    ->custom(
        function (Event $event, SchedulingContext $context): bool {
            $participants = $event->getParticipants();

            // Ensure participants have compatible equipment
            $equipment1 = $participants[0]->getMetadataValue('equipment');
            $equipment2 = $participants[1]->getMetadataValue('equipment');

            return $equipment1 === $equipment2;
        },
        'Compatible Equipment'
    )
    ->build();

// A custom constraint that reads the schedule built so far
$advancedConstraint = ConstraintSet::create()
    ->custom(
        function (Event $event, SchedulingContext $context): bool {
            $participants = $event->getParticipants();

            // Allow each participant at most three events in total
            $firstParticipantEvents = $context->getEventsForParticipant($participants[0]);
            $secondParticipantEvents = $context->getEventsForParticipant($participants[1]);

            return count($firstParticipantEvents) < 3 && count($secondParticipantEvents) < 3;
        },
        'Maximum Three Events'
    )
    ->build();
```

#### What a constraint may rely on

Write a constraint as a pure predicate: its answer depends on the event it
is given and on the context it is given, and on nothing that changes while
a schedule is generated. The library asks constraints in ways that only a
pure predicate answers consistently, and what it promises is narrow.

It promises that an event is in a schedule or a round pairing only if the
constraint set accepted it, against a context that holds the events
generated before it.

It does not promise how often a constraint is asked, in which order, or
that an event it is asked about ends up in the schedule:

- **A rejected event is asked about twice.** Within one attempt the
  round-robin scheduler asks about an event it accepts once. It puts an
  event the set rejected to each constraint of the set again, by itself, to
  record which of them rejected it. A set stops at the first constraint
  that rejects, so a later constraint may be asked the second time only.
- **Retries ask everything again.** A round robin that fails under one
  participant order is generated again under up to min(participants, 25)
  rotated orders, and the backtracking search, a search of roles and the
  Swiss round search put many candidate events to the constraints that they
  then discard. A backtracking search over several legs asks about the
  events of a first leg it found a second time, when it searches the roles.
- **The default role assignment generates more than once.** Under the
  default of `RoundRobinOptions` a round robin whose constraints reject the
  balanced roles is generated again with the roles the generator proposes,
  and under a constraint of your own once more with balanced roles
  ([Balanced where possible](#balanced-where-possible)). Each of those
  questions is one a generation asks, an event with the events laid out
  before it: an event of a later leg is never put to a constraint without
  the first leg.
- **A failure analysis asks about events that were never candidates**: each
  missing pairing, in every round, with either participant first-named,
  against the events of the failed attempt.
- **The context is the generation's, not the final schedule's.** It holds
  what was generated before the event, and never the event itself. For a
  round robin laid out by the circle method that is the earlier rounds: the
  other events of the round being laid out are not in it yet. The
  backtracking search and the Swiss round search add each pairing as they
  make it, so there the context also holds the pairings already made in
  the event's own round. In an attempt that is later discarded, it holds
  events that are in no schedule.

So a constraint must not count its calls, read a random source or the
clock, or remember what it was asked. One that does gets a schedule that
depends on how the library happened to ask. A constraint that throws ends
the generation with that exception, which the library does not wrap (see
[What the marker does not cover](#what-the-marker-does-not-cover)); return
`false` for an event the rule forbids, and `true` for one it has no opinion
on.

Because purity is not part of `ConstraintInterface`, the library does not
assume it where assuming it would change what a constraint that breaks the
rule gets back. Two shortcuts are taken only for a `ConstraintSet` made of
`NoRepeatPairings`, `MinimumRestPeriodsConstraint`, `RoleBalanceConstraint`
and `SeedProtectionConstraint` objects, which run no code of yours: a round
robin that cannot be completed is analysed once and not once per order
tried, and a Swiss round search skips the branches that hold no pairing.
Any other set gets the same results without the shortcuts, which on those
two failure paths takes longer: a set that holds a `CallableConstraint`, a
`MetadataConstraint` or a `ConsecutiveRoleConstraint` (each holds a
callable, also when one of its factories built it), a class or subclass of
your own, or a set that is a subclass of `ConstraintSet`.

### Role-Based Constraints

```php
use MissionGaming\Tactician\Constraints\RoleBalanceConstraint;

// At most 2 events in a row in the same role (first-named or second-named)
$homeAwayConstraint = ConsecutiveRoleConstraint::homeAway(2);

// The same rule under its neutral name: both read the role as the
// participant's index in the event
$positionConstraint = ConsecutiveRoleConstraint::position(3);

// Keep the two role totals of every participant within 3 of each other
// while the schedule is built
$balanceConstraint = RoleBalanceConstraint::homeAway(3);

// Use in scheduler
$constraints = ConstraintSet::create()
    ->add($homeAwayConstraint)
    ->add($positionConstraint)
    ->add($balanceConstraint)
    ->build();
```

`RoleBalanceConstraint` is checked on the running totals, so a limit has to
be at least what the roles of the schedule reach on the way. With the roles
as the role assignment gives them (see [Role Assignment](#role-assignment)),
for 2 to 30 participants, the running totals of a participant are at most
this far apart:

| Schedule | Balanced roles (the default, wherever it does not fall back) | Round-parity roles |
|----------|------------------------------|--------------------|
| One leg | 1 in a field of even size, 2 in a field of odd size | 3 in a field of even size, 4 in a field of odd size |
| Two to six mirrored legs | 2: every even-numbered leg undoes the leg before it | 3 and 4, as for one leg |
| Repeated legs | 2 in a field of odd size. In a field of even size, the number of legs: every leg adds the 1 of the first | Every leg adds the drift of the first: a limit of 3 (4 in a field of odd size) fails for two legs of 4 participants and of every size from 6 to 30, and for three legs of every size from 3 to 30 |

The table holds for a scheduler with a `Randomizer` as well. Its schedule is
the schedule of the shuffled order: the first leg is laid out from that
order and every later leg derives from the first, so the roles reach what
they reach without a `Randomizer`, for other participants. (Up to 0.2.x the
later legs were laid out from the order as given, and two mirrored legs
could then break the limit, depending on the seed.)

Below these figures the roles as given break the limit, and what the
scheduler does then depends on whose roles they are
([Leg Strategies and Role Constraints](#leg-strategies-and-role-constraints)).
`BalancedRoleAssignment` states the rule it holds a leg to, so the scheduler
looks for another balanced first leg that keeps the limit, and returns it
where one exists in the layout and its search finds it within its bounds;
where it finds none, the schedule fails. The default looks in the same way,
and where it finds none it returns the schedule the round-parity roles give
if they keep the limit, marked
([Balanced where possible](#balanced-where-possible)).
`RoundParityRoleAssignment` states no rule: its roles stand, and the schedule
fails with an `IncompleteScheduleException` unless `backtracking: true` lets
the search choose them. The roles a shuffled leg draws are changed under
any of the three. No roles help where the limit itself is out of reach: the constraint
counts over all legs, so three repeated legs of a field of even size, in
which every participant is one apart after each leg, break a limit of 2
whatever the roles.

## Multi-Leg Tournaments

Multi-leg tournaments allow the same participants to play multiple times with different arrangements, perfect for home/away leagues or repeated encounters.

### Available Leg Strategies

```php
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\LegStrategies\MirroredLegStrategy;
use MissionGaming\Tactician\LegStrategies\RepeatedLegStrategy;
use MissionGaming\Tactician\LegStrategies\ShuffledLegStrategy;
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

$participants = [
    new Participant('celtic', 'Celtic'),
    new Participant('athletic', 'Athletic Bilbao'),
    new Participant('livorno', 'AS Livorno'),
    new Participant('redstar', 'Red Star FC'),
];

$scheduler = new RoundRobinScheduler();

// Home and away legs (the roles of every pairing reversed in the second
// leg, and in every even-numbered leg when there are more)
$mirroredSchedule = $scheduler->schedule(
    $participants,
    new RoundRobinOptions(legs: 2, strategy: new MirroredLegStrategy())
);

// Repeated encounters (the same pairings in the same roles in every leg)
$repeatedSchedule = $scheduler->schedule(
    $participants,
    new RoundRobinOptions(legs: 3, strategy: new RepeatedLegStrategy())
);

// The same pairings in every leg, with the roles of each pairing drawn
// at random in the legs after the first. The seed names the draw
$shuffledSchedule = $scheduler->schedule(
    $participants,
    new RoundRobinOptions(legs: 2, strategy: new ShuffledLegStrategy(seed: 2026))
);
```

`MirroredLegStrategy` alternates. The odd-numbered legs have the roles of the
first leg and the even-numbered legs have them reversed, so the meetings of a
pair are split as evenly as their number allows: two each way over four legs,
and two one way and one the other over three. An even number of mirrored
legs ends with every participant first-named as often as second-named, and
an odd number ends as a single leg does. (Up to 0.2.x every leg after the
first was reversed, which split a pair 1:2 over three legs and 1:3 over
four.)

The shuffled strategy is the one that draws, so it is given what it draws
from: an integer seed, as above, or a `Random\Randomizer`
(`new ShuffledLegStrategy($randomizer)`). It is refused without either
(`InvalidConfigurationException`, reason `IncompatibleOptions`), and with
both. With a seed, the roles are the same on every call and in every
process, whatever was scheduled before. With a randomizer, the strategy
continues that randomizer's sequence on every call
([Deterministic Randomization](#deterministic-randomization)).

Options are plain-data constructible for config-driven platforms, with the
stable strategy identifiers `mirrored`, `repeated`, and `shuffled` (and
`round_parity`, `balanced` and `balanced_where_possible` for the
[role assignment](#role-assignment)):

```php
$options = RoundRobinOptions::fromArray(['legs' => 2, 'strategy' => 'mirrored']);
$options->toArray(); // ['format_version' => 1, 'legs' => 2, 'strategy' => 'mirrored', 'backtracking' => false]
```

In plain data the shuffled strategy comes with its seed, under the key
`strategy_seed`. The key is required with `shuffled` and refused with the
two strategies that draw nothing (reason `IncompatibleOptions` both ways),
so stored options never name a seed that decides nothing:

```php
$shuffledOptions = RoundRobinOptions::fromArray(['legs' => 2, 'strategy' => 'shuffled', 'strategy_seed' => 2026]);
$shuffledOptions->toArray();
// ['format_version' => 1, 'legs' => 2, 'strategy' => 'shuffled', 'backtracking' => false, 'strategy_seed' => 2026]

// The same data gives the schedule of the same seed, on every call
var_dump($scheduler->schedule($participants, $shuffledOptions)->toJson() === $shuffledSchedule->toJson()); // bool(true)
var_dump($scheduler->schedule($participants, $shuffledOptions)->toJson() === $shuffledSchedule->toJson()); // bool(true)
```

Options whose shuffled strategy holds a `Randomizer` have no plain-data
form, and `toArray()` refuses them (reason `NotSerializable`).

### Leg Strategies and Role Constraints

A constraint on roles reads every leg, and a leg that keeps it by itself can
break it where two legs meet. What the scheduler may do about that depends
on whose roles they are. A role is changed only where the contract of
whoever decided it leaves it free, so that the schedule returned is still
what was asked for.

The roles of a later leg are the leg strategy's:

| Leg strategy | Its roles in a later leg | When a constraint rejects one |
|--------------|--------------------------|-------------------------------|
| `MirroredLegStrategy` | A **rule**: the roles of the pairing's first-leg event, reversed in the even-numbered legs | The event is never given another role. Only other roles in the first leg can help |
| `RepeatedLegStrategy` | A **rule**: the roles of the pairing's first-leg event | The same |
| `ShuffledLegStrategy` | A **preference**: a draw | The event is given the other role. When that is rejected too, the roles of earlier events of the later legs are changed until every event is accepted or every choice was tried |

The roles of the first leg are the role assignment's
([Role Assignment](#role-assignment)):

| Role assignment | What it says of its roles | When a constraint rejects the schedule they give |
|-----------------|---------------------------|--------------------------------------------------|
| `BalancedWherePossibleRoleAssignment` (the default) | The **rule** of the balanced one, as a preference of the schedule: balanced wherever a balanced schedule is found | The scheduler looks for another balanced first leg, as in the next row. Where it finds none, the schedule is generated with the roles the generator proposes, as in the last row, and marked; it fails only where that fails too ([Balanced where possible](#balanced-where-possible)) |
| `BalancedRoleAssignment` | A **rule** it states: every leg is balanced, and any balanced leg will do | The scheduler looks for another balanced first leg that the constraints accept in every leg, in each layout it tried. No option is needed. Where it finds none, the schedule fails |
| `RoundParityRoleAssignment` | No rule: the roles are the ones the generator proposes | They stand, and the schedule fails, unless `backtracking: true` lets the search choose them |

So a schedule of shuffled legs under a role constraint has the roles that
were drawn wherever the constraints accept them, and others where they do
not; without constraints, or when none rejects a role, it is exactly the
draw. Nothing is drawn again, so a seed still names one schedule. A schedule
of repeated or mirrored legs follows its first leg by the rule, and the
first leg is one the role assignment keeps: under the two balanced role
assignments a balanced one, whichever the constraints leave, or, under the
default where no balanced one is found, the one the generator proposes.

```php
use MissionGaming\Tactician\Constraints\ConsecutiveRoleConstraint;
use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\RoleAssignment\RoundParityRoleAssignment;

$league = [];
foreach (['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'] as $letter) {
    $league[] = new Participant("club-{$letter}", 'Club ' . strtoupper($letter));
}

// Nobody plays more than two events in a row in the same role
$twoInARow = new RoundRobinScheduler(
    ConstraintSet::create()->add(ConsecutiveRoleConstraint::homeAway(2))->build()
);

// Shuffled legs: the draws that break the limit are changed
$shuffledLeague = $twoInARow->schedule(
    $league,
    new RoundRobinOptions(legs: 2, strategy: new ShuffledLegStrategy(seed: 2026))
);
echo count($shuffledLeague), " events\n";

// Home and away: the balanced roles the default starts from repeat a role
// three times where the two legs meet, so the scheduler looks for another
// balanced first leg, and returns the league with it
$homeAndAway = $twoInARow->schedule($league, new RoundRobinOptions(legs: 2));
echo count($homeAndAway), " events\n";

// Round parity states no rule, so its roles are not searched: played
// twice, they break the limit where the legs meet
$parityTwice = new RoundRobinOptions(
    legs: 2,
    strategy: new RepeatedLegStrategy(),
    roleAssignment: new RoundParityRoleAssignment()
);
try {
    $twoInARow->schedule($league, $parityTwice);
} catch (IncompleteScheduleException $failure) {
    echo "Round parity, without backtracking: no schedule\n";
}

// With backtracking, the search chooses a first leg that can be played twice
$repeatedLeague = $twoInARow->schedule(
    $league,
    new RoundRobinOptions(
        legs: 2,
        strategy: new RepeatedLegStrategy(),
        backtracking: true,
        roleAssignment: new RoundParityRoleAssignment()
    )
);
echo count($repeatedLeague), " events\n";
```

The block prints:

```
56 events
56 events
Round parity, without backtracking: no schedule
56 events
```

The league the default returns has the pairings and rounds of the league
without the constraint, and a first leg that is balanced: only roles were
looked for. Under a limit of two in a row over two mirrored legs it was
found for every field of even size from 6 to 30, and for the fields of 3,
5, 7, 9 and 11 (over three and four legs too, measured up to 20
participants). For every field of odd size from 13 to 29 the search stopped
at its bound, after some seconds, and four participants have no first leg
that keeps it, with any roles. The round-parity roles break the limit in
those fields too, so there the schedule fails under every role assignment:
under the default with the message of round parity, and under
`BalancedRoleAssignment` with one that says a bound stopped the search. A
limit of three in a row, which is enough to rule out a run of four, was
found for every field from 3 to 20 over one to four legs.

That is for the library's own `ConsecutiveRoleConstraint`, which the search
of roles reasons about exactly. The same limit written as a constraint of
your own (a closure, a class) is another matter: the scheduler cannot know
which earlier event such a rejection turns on.
[Balanced where possible](#balanced-where-possible) says what the default
does then.

How the scheduler looks for a first leg within the rule of the role
assignment, when every layout has failed with the roles it gave:

1. It puts the roles to the constraints in order. Where a constraint that
   may read anything (a custom constraint, a metadata rule) rejects an
   event, it reverses the first-leg seating the event comes from, fixes it,
   and asks the role assignment for roles around the fixed seatings. For a
   rule that fixes the role of single pairings ("the first participant is
   first-named against the second") this is exact: `BalancedRoleAssignment`
   finds a balanced leg around the fixed seatings whenever there is one.
2. Where that does not end with a schedule, it searches the roles of the
   first leg, each choice held to the rule, with the later legs following by
   their own contract. That is what a limit on streaks or on the role totals
   needs, whose verdict on an event turns on the roles before it.

All searches are bounded, and a failure says which case it is. (Under the
default, a failure of the search for balanced roles is not a failure of the
schedule: the roles the generator proposes are tried, and the message is
theirs when they fail too. What follows about a first leg searched within a
rule is what `BalancedRoleAssignment`, named, or a role assignment of your
own with a rule reports.)

- **Every choice was tried.** For the roles of a shuffled later leg, the
  message says that the constraints reject every choice of roles for the
  later legs in this layout. For the roles of a first leg under a rule, it
  says that the roles of the first leg were searched within what the role
  assignment keeps, in each of the layouts tried, and that no choice of them
  has a schedule the constraints accept. Where a constraint that reads no
  role rejected an event in every layout (`NoRepeatPairings`,
  `MinimumRestPeriodsConstraint`, `SeedProtectionConstraint`), nothing is
  searched, and the message says that no choice of roles for the first leg
  changes that. Where the first leg was not
  searched (round parity), it says so, and so does the failure of a
  repeated or mirrored leg, when it was a `ConsecutiveRoleConstraint` or a
  `RoleBalanceConstraint` that rejected one of its events (a custom
  constraint does not say whether it reads roles, so nothing is advised
  for one). Each names `RoundRobinOptions(backtracking: true)` as the
  option that searches what it did not: the pairings and their rounds, and
  under round parity the roles of the first leg. A failure after a search
  within a rule also names the options that search every choice of roles,
  for a constraint that needs roles the role assignment does not keep
  (one that names a participant first in all of its events cannot have a
  balanced leg): `backtracking: true` with `RoundParityRoleAssignment`. A backtracking search that
  went through every way to play the first leg, with every choice of roles,
  says that the configuration is unsatisfiable with this leg strategy (and
  this role assignment, where it searched within its rule).
- **A bound stopped the search.** A search of the roles of later legs stops
  after 20,000 rejected roles for one layout. The searches of first-leg
  roles within a rule stop after 20,000 rejected roles or 200,000 events put
  to the constraints, counted over all the layouts of a call together. A
  backtracking search stops after its step budget of 200,000 steps
  ([Backtracking Generation](#backtracking-generation)), which the steps of
  its role searches count against. The message names the bound and says that
  roles may exist beyond it; it does not say that none exist. A search the
  budget stopped says of how many first legs it went through every choice
  of roles, and of how many a bound stopped it.

A schedule that fails pays for the search. Without backtracking the roles
of shuffled later legs are searched once for every rotated participant
order tried, up to 25, each up to the bound of rejected roles: 24
participants and two shuffled legs under a limit of two in a row, with a
custom constraint that leaves one pairing no round in the second leg, take
49 seconds to fail (17 before the roles were searched). The search of
first-leg roles within a rule adds one allowance for the whole call, not
one for each order: under `BalancedRoleAssignment` a schedule no balanced
roles complete fails a few tenths of a second to a few seconds later than
it did under round parity, depending on what the constraints cost to ask.
Under the default the same search runs before the fall-back where the
constraints are the library's own, and is cut short where they are not
([Balanced where possible](#balanced-where-possible)).

How far the search goes back when a role is rejected depends on the
constraint set. For a set of `ConsecutiveRoleConstraint`,
`RoleBalanceConstraint`, `MinimumRestPeriodsConstraint`,
`SeedProtectionConstraint` and `NoRepeatPairings` objects it goes straight
to an earlier event of the same participants: under a limit of two in a
row, and under a limit on the role totals, it found the roles of two to
four shuffled legs for every seed measured up to 40 participants. For a set
that holds anything else (a custom
constraint, a metadata rule, a class of your own) it goes back one event at
a time, which is as thorough and slower: under a limit of two in a row
beside a custom constraint, the roles of two to four shuffled legs were
found for every seed measured up to 24 participants, and the bound stopped
some from 30 up. The measurements are in
`docs/design/backtracking-generation.md`.

A leg strategy of your own states which of the two its roles are in the
`LegPlanContribution` it returns from `planLegs()`
(`rolesArePreferences: true` for a preference). The default is a rule, so a
strategy that says nothing is never reversed.

### Backtracking Generation

The greedy generator retries bounded rotated orderings when constraints
reject a schedule — but the circle method fixes which pairings share a
round purely by list order, so it only ever sees a handful of round
decompositions, and some satisfiable constraint sets fail every one of
them. **Backtracking generation** searches the decompositions the
rotations cannot reach. The fixture-placement policy below is one such
set: every rotation the greedy generator tries violates it, and the
search finds the schedule that satisfies it:

```php
use MissionGaming\Tactician\DTO\Event;

// Celtic must meet Athletic in round 1, Livorno in round 2 and Red Star
// in round 3
$placement = ['athletic|celtic' => 1, 'celtic|livorno' => 2, 'celtic|redstar' => 3];

$constraints = ConstraintSet::create()
    ->custom(
        function (Event $event) use ($placement): bool {
            $ids = array_map(fn (Participant $p) => $p->getId(), $event->getParticipants());
            sort($ids);
            $requiredRound = $placement[implode('|', $ids)] ?? null;
            $round = $event->getRound()?->getNumber();

            return $requiredRound === null || $round === null || $round === $requiredRound;
        },
        'Fixture Placement'
    )
    ->build();

$schedule = (new RoundRobinScheduler($constraints))
    ->schedule($participants, new RoundRobinOptions(backtracking: true));
```

The rules of the search (see `docs/design/backtracking-generation.md`):

- **Opt-in, greedy first.** Greedy remains the default and always runs
  first; the search only starts when every rotation has failed, so
  satisfiable-by-rotation configurations pay nothing.
- **Deterministic and bounded.** Seat, opponent, and orientation order
  are fixed, and a step budget bounds the exponential worst case. The
  failure diagnostic distinguishes a proven-unsatisfiable configuration
  (search space exhausted) from one that ran out of budget.
- **Leg scope.** The search is over how the pairings of leg 1 fall into
  rounds, and over roles. Later legs derive from the actual rounds of
  leg 1 through the leg strategy. For every first leg it finds, the search
  holds the pairings and rounds and searches the roles of all legs: those
  of the first leg, and those of the later legs where the leg strategy
  states them as preferences
  ([Leg Strategies and Role Constraints](#leg-strategies-and-role-constraints)).
  Under a role assignment that states a rule (the two balanced ones), the
  roles of the first leg are searched within that rule, so the leg that is
  returned is balanced (or, under the default where no balanced one is
  found, the leg a search with the generator's roles returns, marked). A
  first leg that no roles complete
  is a dead end, and the search goes on to the next. The roles of the
  layout the rotations started from are searched as well: before the
  search begins where the role assignment states a rule, and otherwise
  once, after the first leg found has failed.

### Multi-Leg Schedule Analysis

```php
// Multi-leg schedules provide additional metadata
echo "Total legs: " . $mirroredSchedule->getMetadataValue('legs') . "\n";
echo "Rounds per leg: " . $mirroredSchedule->getMetadataValue('rounds_per_leg') . "\n";
echo "Total rounds: " . $mirroredSchedule->getMetadataValue('total_rounds') . "\n";

// Process the schedule round by round - the natural shape for assigning
// dates per round or rendering matchday views
$roundsPerLeg = $mirroredSchedule->getMetadataValue('rounds_per_leg');
foreach ($mirroredSchedule->getEventsByRound() as $roundNumber => $events) {
    $leg = (int) ceil($roundNumber / $roundsPerLeg);
    echo "Leg {$leg}, Round {$roundNumber}:\n";

    foreach ($events as $event) {
        [$home, $away] = $event->getParticipants();
        echo "  {$home->getLabel()} vs {$away->getLabel()}\n";
    }
}
```

### Multi-Leg with Constraints

```php
use MissionGaming\Tactician\Constraints\MinimumRestPeriodsConstraint;

// A constraint sees the events of every leg generated so far
$constraints = ConstraintSet::create()
    ->noRepeatPairings()
    ->add(new MinimumRestPeriodsConstraint(3))  // The two meetings of a pair are at least 3 rounds apart
    ->build();

$scheduler = new RoundRobinScheduler($constraints);

$schedule = $scheduler->schedule(
    $participants,
    new RoundRobinOptions(legs: 2, strategy: new MirroredLegStrategy())
);
```

### Role Assignment

The **role** of a participant in an event is its position: first-named or
second-named (home and away in a football league, white and black on a chess
board). A **role assignment** decides the roles of a round robin, one leg at
a time. It never changes who meets whom, or in which round.

`RoundRobinOptions` takes one as `roleAssignment`:

| Role assignment | Roles of a single leg | Identifier |
|-----------------|-----------------------|------------|
| `BalancedWherePossibleRoleAssignment` (the default) | Those of `BalancedRoleAssignment` wherever the scheduler finds a schedule with them, which without constraints is always. Otherwise those of `RoundParityRoleAssignment`, and the schedule says so ([Balanced where possible](#balanced-where-possible)). | `balanced_where_possible` |
| `BalancedRoleAssignment` | As even as the leg allows. A participant ends at most 1 apart in a field of even size, where it plays an odd number of events, and exactly 0 apart in a field of odd size. Balanced or refused: a schedule no balanced roles complete fails. | `balanced` |
| `RoundParityRoleAssignment` | Alternate with round parity. A participant ends up to 3 apart in a field of even size and up to 4 apart in a field of odd size. Of four participants, one is second-named in all three of its events. It was the default up to 0.2.x. | `round_parity` |

"Apart" is the difference between the number of events a participant plays
first-named and the number it plays second-named.

```php
use MissionGaming\Tactician\Quality\RoleBalanceMetric;
use MissionGaming\Tactician\RoleAssignment\BalancedRoleAssignment;

// Options that name no role assignment are balanced where possible:
// without constraints, the schedule of the balanced role assignment
$balanced = (new RoundRobinScheduler())->schedule($participants);
$named = (new RoundRobinScheduler())->schedule(
    $participants,
    new RoundRobinOptions(roleAssignment: new BalancedRoleAssignment())
);
var_dump($named->toJson() === $balanced->toJson()); // bool(true)

// The roles of 0.2.x, by name
$parity = (new RoundRobinScheduler())->schedule(
    $participants,
    new RoundRobinOptions(roleAssignment: new RoundParityRoleAssignment())
);

foreach ($balanced as $event) {
    [$first, $second] = $event->getParticipants();
    echo "Round {$event->getRound()?->getNumber()}: {$first->getLabel()} v {$second->getLabel()}\n";
}

// The mean distance between a participant's two role counts
(new RoleBalanceMetric())->measure($parity);   // 1.5
(new RoleBalanceMetric())->measure($balanced); // 1.0
```

The block prints:

```
bool(true)
Round 1: Red Star FC v Celtic
Round 1: Athletic Bilbao v AS Livorno
Round 2: Celtic v Athletic Bilbao
Round 2: AS Livorno v Red Star FC
Round 3: AS Livorno v Celtic
Round 3: Red Star FC v Athletic Bilbao
```

The pairings, their rounds, their order and the byes are the ones round
parity produces: only the roles differ. For a single leg of 2 to 30
participants the balanced roles also keep these two limits, which the test
suite checks:

- No participant plays in the same role more than twice in a row. Round
  parity reaches four in a row.
- While the leg is played, no participant is more than 1 apart in a field of
  even size, or more than 2 apart in a field of odd size.

The option is plain data too. `toArray()` writes the `role_assignment` key
only when a role assignment was named, so options that do not name one
serialize as before, and options that name one keep it through a round trip,
the default included:

```php
$balancedOptions = RoundRobinOptions::fromArray(['legs' => 2, 'role_assignment' => 'balanced']);
$balancedOptions->toArray();
// ['format_version' => 1, 'legs' => 2, 'strategy' => 'mirrored', 'backtracking' => false, 'role_assignment' => 'balanced']

// The default has a name of its own, for options that must stay on it
$namedDefault = RoundRobinOptions::fromArray(['legs' => 2, 'role_assignment' => 'balanced_where_possible']);
$namedDefault->toArray();
// ['format_version' => 1, 'legs' => 2, 'strategy' => 'mirrored', 'backtracking' => false, 'role_assignment' => 'balanced_where_possible']
```

The three identifiers mean what the three classes mean: `balanced` is the
strict one (balanced or refused), `round_parity` gives exactly the roles of
0.2.x, and `balanced_where_possible` is the default of 0.3.0, named.

Balanced where possible is the default since 0.3.0. To keep the roles of
0.2.x, name the default of those releases:
`new RoundRobinOptions(roleAssignment: new RoundParityRoleAssignment())`, or
`'role_assignment' => 'round_parity'` in plain data. Options that name it
serialize with the key, so a stored configuration stays on these roles.
Options that name no role assignment serialize without the key and follow
the default of the release that reads them, which 0.2.x announced and
0.3.0 changes: an options array stored by 0.2.x without it gives balanced
roles when a schedule is generated from it again (or, under constraints
that leave the scheduler none, the roles it gave before, marked), and
nothing reports it.
Where a schedule that was generated before must come out the same, add the
key to the stored options before upgrading
([Format Versions](#format-versions) has why the form is still version 1).

#### Balanced where possible

Balanced roles are what the default prefers, not something it insists on.
`BalancedWherePossibleRoleAssignment` decides a leg exactly as
`BalancedRoleAssignment` does, and the scheduler looks for balanced roles
under it in the same way. They differ where no schedule with balanced roles
is found: `BalancedRoleAssignment` is balanced or refused, and the default
**falls back**. The scheduler then generates the schedule with the roles the
generator proposes, exactly as it does under `RoundParityRoleAssignment`,
and marks it. So:

- a schedule without constraints, and one whose constraints accept balanced
  roles that the scheduler finds, is byte for byte the schedule
  `BalancedRoleAssignment` gives;
- a schedule that fell back is the one `RoundParityRoleAssignment` gives for
  the same participants, options, constraints and randomizer, with one more
  metadata key;
- the default fails only where `RoundParityRoleAssignment` fails, and with
  its failure: the same message, violations and analysis. Nothing that
  completed with the default of 0.2.x fails for the sake of balance.

The mark is the metadata key `role_fallback` (`RoleFallback::METADATA_KEY`),
whose value is the reason. It is absent from every schedule the search for
balanced roles returned, and from every schedule generated under a role
assignment you named. (It says that the scheduler fell back, not what the
roles turned out to be: with `backtracking: true` the roles of a fall-back
are those a backtracking search chose, which can happen to be balanced, and
the schedule is marked all the same.)
It is part of the schedule's plain data, so it survives `toArray()` and
`fromArray()`; `RoleFallback::of()` reads it:

```php
use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\RoleAssignment\BalancedRoleAssignment;
use MissionGaming\Tactician\RoleAssignment\RoleFallback;

// AS Livorno has no ground of its own and is never first-named. No leg of
// four can be balanced then: it plays all three of its events in one role
$awayOnly = ConstraintSet::create()->custom(
    fn (Event $event): bool => $event->getParticipants()[0]->getId() !== 'livorno',
    'Livorno is second-named'
)->build();

// The default completes the schedule with the roles the generator
// proposes, which happen to keep the rule, and says that it did
$awaySchedule = (new RoundRobinScheduler($awayOnly))->schedule($participants);
$fallback = RoleFallback::of($awaySchedule);
echo $fallback?->value, "\n";
echo $awaySchedule->getMetadataValue(RoleFallback::METADATA_KEY), "\n";

// The mark is plain data
RoleFallback::of(Schedule::fromArray($awaySchedule->toArray())); // RoleFallback::NoBalancedRoles

// A schedule with balanced roles carries none
RoleFallback::of((new RoundRobinScheduler())->schedule($participants)); // null

// The balanced role assignment, named, is balanced or refused
try {
    (new RoundRobinScheduler($awayOnly))->schedule(
        $participants,
        new RoundRobinOptions(roleAssignment: new BalancedRoleAssignment())
    );
} catch (IncompleteScheduleException $failure) {
    echo "Balanced, named: no schedule\n";
}
```

The block prints:

```
no_balanced_roles
no_balanced_roles
Balanced, named: no schedule
```

`RoleFallback::of()` returns null for a schedule without the mark, and
throws an `InvalidInputException` for a value under the key that is not one
of the reasons, as a schedule stored by a later release may hold. The
reasons, each with what naming `BalancedRoleAssignment` would do for the
same input:

| Reason | Stored as | What happened | `BalancedRoleAssignment`, named |
|--------|-----------|---------------|---------------------------------|
| `RoleFallback::NoBalancedRoles` | `no_balanced_roles` | Every choice of balanced roles the scheduler searches was tried, and none completes the schedule: in each layout of the circle method that was tried, and with `backtracking: true` in every way to play the first leg. Under the library's own constraints, which the search reasons about exactly, and under constraints of your own where the search came to its end inside the bound it then has, as in the example | Fails |
| `RoleFallback::SearchStoppedAtBound` | `search_stopped_at_bound` | The search for balanced roles stopped at one of its bounds ([Leg Strategies and Role Constraints](#leg-strategies-and-role-constraints)). Balanced roles may exist beyond it | Stops at the same bound and fails |
| `RoleFallback::BalancedRolesRejected` | `balanced_roles_rejected` | The constraints rejected the balanced roles the scheduler put to them, and with a schedule already in hand it did not search the others through: the search spent the 4,000 events it then has, or, with `backtracking: true`, no backtracking search was made for balance. Under constraints of your own, see below. It does not say that no balanced roles exist | Searches to the full bounds, which can take seconds, and returns a balanced schedule or fails |

More reasons may be added in a later release, so a `match` over them needs
a `default` arm.

**What it costs, and the order of the attempts.** Without constraints the
default is the balanced role assignment and nothing else. Under constraints
the order depends on what the search of roles can know about them:

- **The library's own constraints** (`ConsecutiveRoleConstraint`,
  `RoleBalanceConstraint`, `NoRepeatPairings`,
  `MinimumRestPeriodsConstraint`, `SeedProtectionConstraint`, in a
  `ConstraintSet`). The search reasons about these exactly, and goes
  straight back to an event that matters. The scheduler does everything it
  does under `BalancedRoleAssignment` first: the balanced roles as given in
  every rotated participant order, the search of other balanced roles in
  those layouts, and with `backtracking: true` the backtracking search for
  a balanced first leg. Only when all of that has failed does it generate
  the schedule as under `RoundParityRoleAssignment`. A schedule with
  balanced roles is therefore found wherever `BalancedRoleAssignment` finds
  one, and a fall-back comes after the whole search, bounds included.
- **Constraints of your own** (a closure, a `MetadataConstraint`, a class or
  subclass of yours, a subclass of `ConstraintSet`, or any of them beside
  the library's own). The scheduler cannot know what such a constraint
  reads, and the search goes back one event at a time for it, which can
  spend its whole allowance without a result. So the roles the generator
  proposes are tried before that search:

  1. The balanced roles as given, in the first layout. If the constraints
     accept them, that is the schedule.
  2. Everything `RoundParityRoleAssignment` does. Its schedule, or its
     failure, is kept.
  3. Everything `BalancedRoleAssignment` does, from the first layout again:
     - the balanced roles as given are put to the constraints in each
       rotated participant order;
     - in each of those layouts the seatings a constraint of yours rejects
       are fixed the other way round, and the leg is balanced around them.
       A rule that rejects an event whatever came before it (a fixed role,
       "the first participant is first-named against the second") is solved
       by this, exactly;
     - where that gives no schedule, the roles of the first leg are
       searched within the balance, one event at a time for a constraint
       of yours. This is what a limit on what a participant did before (a
       streak, a running total) needs, since the scheduler cannot tell
       which earlier event to change.

     With no schedule in hand all of it has the bounds it always has. With
     one in hand it is held to 4,000 events put to the constraints (the
     count is checked before each rotated order, so it can be passed by
     the events of one layout), and nothing else about it changes: a
     schedule it finds within them is the one `BalancedRoleAssignment`
     gives. Under `ShuffledLegStrategy` the count does not stop the search
     of the later legs' roles in the rotated order under way, which has
     its own bound of 20,000 rejected roles
     ([Leg Strategies and Role Constraints](#leg-strategies-and-role-constraints)):
     the count is looked at again when that search has ended. With
     `backtracking: true`, a backtracking
     search for a balanced first leg is made only where the schedule in
     hand came from a backtracking search itself (where a rotated order
     gave it, the schedule needed no search, and none is made for
     balance), and what is left of the 4,000 is its step budget.
  4. A balanced schedule from step 3 is returned. Otherwise the schedule of
     step 2 is returned with the reason step 3 ended on, or the failure of
     step 2 is thrown.

So with a constraint of your own that limits what a participant did before,
the default gives balanced roles when the balanced roles as given keep the
limit or the search finds others that do within 4,000 events, and otherwise
the round-parity roles when they keep it, marked `balanced_roles_rejected`.
The search is what such a fall-back costs: up to 4,000 events put to your
constraint, after the balanced roles of the first layout and the schedule
of round parity. A limit of
two in a row written as a closure, over two mirrored legs: round parity
keeps it in every field of even size from 6 to 40. The default finds
balanced roles for 6 and 8 participants, in 2.5 and 23 ms where round
parity takes under 1, and from 10 up it returns the schedule of round
parity after 3,271 to 5,481 more questions than round parity asks: in 47 ms
for 1.3 with 10 participants, 90 for 11 with 20 and 304 for 106 with 40
(over four legs, 129 ms for 49 with 20 and 677 for 474 with 40).
Write the limit as the library's `ConsecutiveRoleConstraint` and the search
finds balanced roles for those fields; or name `BalancedRoleAssignment` and
the search runs to its bounds for the closure too, and fails where it
finds none (it found balanced roles for 6, 8 and 10 participants, and
stopped at a bound, after one to thirteen seconds, from 12 up).
Shuffled legs can cost far more than the figures above, since the count
does not stop the search of a layout's later legs: a limit of three in a
row written as a closure, over two shuffled legs of 32 participants, fell
back after 81,662 more questions than round parity asks, in 2.2 seconds
for 0.9 (one seed).

The roles of a fall-back are those of round parity, which
[Legs after the first](#legs-after-the-first) and the table of
[Role-Based Constraints](#role-based-constraints) describe in their own
columns: a participant can end a leg up to 3 apart in a field of even size
and 4 in a field of odd size. An application that needs balance and would
rather have no schedule than an unbalanced one names
`BalancedRoleAssignment`; one that takes what it gets reads the mark.

#### Legs after the first

The role assignment decides the roles of every leg as the generator lays it
out. The leg strategy then does to those roles what it does to any roles, so
no leg strategy changes its meaning. With `BalancedRoleAssignment`, and with
the default wherever it does not fall back, for 1 to 6 legs:

| Leg strategy | Each leg on its own | Whole schedule, even field | Whole schedule, odd field | Each pairing |
|--------------|---------------------|----------------------------|---------------------------|--------------|
| `MirroredLegStrategy` | Every leg is within the limit of a single leg | 1 apart for an odd number of legs and 0 apart for an even number: the odd-numbered legs are played one way and the even-numbered legs the other way | 0 apart | As even as the number of meetings allows: an even split for 2, 4 and 6 legs, one apart for 1, 3 and 5 legs |
| `RepeatedLegStrategy` | Every leg is within the limit of a single leg | As many apart as there are legs: every leg has the roles of leg 1 | 0 apart | Every meeting the same way |
| `ShuffledLegStrategy` | Leg 1 is within the limit of a single leg | No limit: the roles of the later legs are random | No limit | Random after leg 1 |

The table holds for a scheduler that has a `Randomizer` too. The scheduler
shuffles the participant order, lays the first leg out from it, and derives
every later leg from that first leg: the same pairings in the same rounds of
the leg, in the roles the leg strategy gives them. (Up to 0.2.x it laid the
later legs out from the order as given, so they were not the first leg
mirrored or repeated: about half the pairings of two mirrored legs had the
same participant first-named in both, and a pair could meet in the last
round of one leg and the first round of the next.)

One thing the table does not promise:

- **Streaks across two legs.** The limit of two in a row holds inside a leg.
  Where one leg ends and the next begins, a role can repeat more often. What
  follows is for two mirrored legs and holds for any larger number of them,
  because every boundary between two mirrored legs is the first one again or
  the first one with the two roles exchanged. Two
  mirrored legs end balanced under either role assignment; the balanced one
  also balances the halfway point, and across the middle of the schedule it
  puts a participant in the same role three times in a row in a field of
  even size, and four times in a field of odd size whose size is one more
  than a multiple of four (three times in the other fields of odd size).
  That is one more than round parity in most fields: measured for 6 to 30
  participants, round parity repeats a role at most twice there in a field
  of even size, and three times in a field of odd size from 9 participants
  up. Add a `ConsecutiveRoleConstraint` if a schedule of several legs must
  not do that: the scheduler then looks for another balanced first leg that
  keeps the limit where the legs meet. Where it finds none, the default
  falls back to the round-parity roles if they keep the limit, and
  `BalancedRoleAssignment`, named, fails loudly
  ([Leg Strategies and Role Constraints](#leg-strategies-and-role-constraints)).
  Repeated legs do not have this: the last round of a leg is followed by the
  first round of the same roles, and the balanced roles repeat a role at
  most twice there too.

#### Role assignments and constraints

Constraints stay hard filters. A constraint sees every event with the roles
it has in the schedule, and the scheduler never returns a first leg that its
role assignment would not have given: where it changes the roles of a first
leg to satisfy a constraint, it does so within the rule the role assignment
states, and returns the leg only when the role assignment keeps it as it is.
The default is the one role assignment that also accepts the leg the
generator proposes, where no balanced one is found, and a schedule that has
it says so ([Balanced where possible](#balanced-where-possible)). What
follows about `BalancedRoleAssignment` holds for the default too, up to the
point where one fails and the other falls back.

- **Role constraints the balanced roles satisfy as they are.** For a single
  leg of 2 to 30 participants, `ConsecutiveRoleConstraint` with a limit of 2
  holds, and so does `RoleBalanceConstraint` with a limit of 1 in a field of
  even size (2 in a field of odd size). The round-parity roles break both
  for four participants.
- **Role constraints the balanced roles break as they are.** The scheduler
  looks for other balanced roles for the first leg, without any option
  ([Leg Strategies and Role Constraints](#leg-strategies-and-role-constraints)
  says how). A rule that fixes the role of one participant or of one pairing
  ("the first participant is first-named against the second") is the plain
  case: the scheduler fixes the seatings the rule rejects, and
  `BalancedRoleAssignment` balances the leg around them whenever a balanced
  leg with those roles exists. Of the 729 ways to fix the roles of some of
  the six pairings of four participants, every one that leaves a balanced
  leg gets one, which the test suite holds.
- **Constraints no balanced leg satisfies.** Under `BalancedRoleAssignment`
  generation fails with an `IncompleteScheduleException` whose violations
  name the constraint. A limit of one same-role event in a row is an
  example: no round robin of an even number of participants, four or more,
  has it, with any roles, so it fails under every role assignment. A rule
  that itself puts a participant out of balance is another: one that names
  one of three participants first in both of its events, where a balanced
  leg of three has each participant once in each role, or one of four
  participants second in all three. Such a rule can succeed with the
  round-parity roles, where they happen to agree with it, and then the
  default returns that schedule, marked, as 0.2.x returned it without a
  mark. Where the round-parity roles of no rotated order agree with
  the rule, the default fails as round parity does; `backtracking: true`
  then lets the search choose the roles, under the default as under
  `RoundParityRoleAssignment`.
- **What the search does not reach.** Without `backtracking` the pairings
  and their rounds are those of the circle method, for each rotated
  participant order tried: a limit on streaks that no balanced roles keep
  in those layouts fails under `BalancedRoleAssignment`, although another
  way to play the leg might keep it, and the failure names the option. The
  searches are bounded, and one that a bound stopped says so: in the
  failure under `BalancedRoleAssignment`, and in the mark of the schedule
  under the default (`search_stopped_at_bound`).
- **Rotated retries.** Every retry lays the legs out again and asks the role
  assignment again, so a schedule that needed a retry keeps the limits above.
  The rotated orders are tried with the roles as the role assignment gives
  them before any roles are searched, so a schedule that a rotated order
  completes is the one it was.
- **Backtracking.** The search chooses the first leg: how its pairings fall
  into rounds, and its roles. Under `BalancedRoleAssignment` the roles of
  every first leg it finds are searched within the balance, so the leg that
  is returned is balanced, the constraints accept it and every leg derived
  from it, and a first leg with no such roles sends the search on to the
  next; where the search goes through all of them, the failure says that
  the configuration is unsatisfiable with this leg strategy and this role
  assignment. Under `RoundParityRoleAssignment`, which keeps every leg, the
  roles the search chose stand. Under the default the search for a balanced
  first leg runs first, and where it finds none the schedule is the one
  the search returns under `RoundParityRoleAssignment`, marked. A role assignment of your own that states
  no rule is asked about the leg the search found, roles it changes are
  checked against the constraints again, and a rejection fails with an
  `IncompleteScheduleException`: the scheduler does not keep the roles of
  the search in place of the ones asked for. Constraints that do not read
  roles are never affected.

#### Writing a role assignment

Implement `RoleAssignmentInterface`. It has two methods.

`assignRoles()` receives one leg as a list of rounds, and a
`RoleAssignmentContext`. A round is a list of seatings, and a seating is two
seats in the roles the generator proposes, the first-named participant at
index 0. In a field of odd size the seating of the bye has `null` in one
seat. Return the same rounds with each seating unchanged or reversed. The
context says which leg of how many the rounds are (`$context->leg`,
`$context->legs`) and which seatings the scheduler has fixed
(`$context->isFixed($round, $position)`): seatings a constraint rejected the
other way round, to be returned as given. An assignment that decides every
seating by itself need not read it.

`rule()` states what the assignment holds a first leg to, as a constraint on
one event, or returns null. With a rule, the scheduler looks for other roles
within it when the constraints reject the ones `assignRoles()` gave, and
returns a first leg only when `assignRoles()` returns that leg unchanged.
Without one, the roles `assignRoles()` gives stand, and a constraint they
break fails the schedule unless `backtracking: true` is set.

```php
use MissionGaming\Tactician\Constraints\ConstraintInterface;
use MissionGaming\Tactician\RoleAssignment\RoleAssignmentContext;
use MissionGaming\Tactician\RoleAssignment\RoleAssignmentInterface;

// The participant with the better seed is first-named in every event
final class BetterSeedFirst implements RoleAssignmentInterface
{
    public function assignRoles(array $rounds, RoleAssignmentContext $context): array
    {
        foreach ($rounds as $round => $seatings) {
            foreach ($seatings as $position => [$first, $second]) {
                if ($first !== null && $second !== null && $second->getSeed() < $first->getSeed()) {
                    $rounds[$round][$position] = [$second, $first];
                }
            }
        }

        return $rounds;
    }

    // These roles are the only ones this assignment gives: nothing to search
    public function rule(): ?ConstraintInterface
    {
        return null;
    }
}

$seededClubs = [
    new Participant('celtic', 'Celtic', 1),
    new Participant('athletic', 'Athletic Bilbao', 2),
    new Participant('livorno', 'AS Livorno', 3),
];

$seedFirst = (new RoundRobinScheduler())->schedule(
    $seededClubs,
    new RoundRobinOptions(roleAssignment: new BetterSeedFirst())
);
```

An answer that drops, adds, moves or re-pairs a seating is refused with an
`InvalidConfigurationException` whose reason is `InvalidRoleAssignment`. A
custom role assignment has no plain-data identifier, so `toArray()` refuses
options that carry one, as it does for a custom leg strategy.

## Results and Standings

Record outcomes with `Result` and build league tables with `StandingsCalculator`:

```php
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Standings\BuchholzTiebreaker;
use MissionGaming\Tactician\Standings\StandingsCalculator;
use MissionGaming\Tactician\Standings\WinDrawLossRanking;
use MissionGaming\Tactician\Standings\WinsTiebreaker;

$alice = new Participant('alice', 'Alice');
$bob = new Participant('bob', 'Bob');
$carol = new Participant('carol', 'Carol');
$dave = new Participant('dave', 'Dave');
$participants = [$alice, $bob, $carol, $dave];

// Results are recorded against events - normally the ones a scheduler
// or engine produced
$eventOne = new Event([$alice, $bob]);
$eventTwo = new Event([$alice, $carol]);
$eventThree = new Event([$carol, $dave]);

// A win, a draw (no winner), and a scored win
$results = [
    new Result($eventOne, $alice),
    new Result($eventTwo),
    new Result($eventThree, $carol, ['carol' => 3, 'dave' => 1]),
];

// The 3/1/0 convention with tiebreakers applied in order
$calculator = new StandingsCalculator(
    WinDrawLossRanking::threeOneZero(),
    [new WinsTiebreaker(), new BuchholzTiebreaker()]
);
$standings = $calculator->calculate($participants, $results);

foreach ($standings as $entry) {
    $position = $standings->getPosition($entry->getParticipant());
    echo "{$position}. {$entry->getParticipant()->getLabel()}: "
        . "{$entry->getRankingValue()} pts "
        . "({$entry->getWins()}W {$entry->getDraws()}D {$entry->getLosses()}L)\n";
}
```

The table is ordered by a pluggable **ranking strategy**: "points from
wins, draws, and losses" is one way to order a table, not the definition
of ordering, so `StandingsCalculator` takes a `RankingStrategy` and each
entry exposes the strategy-computed value via `getRankingValue()` alongside
its W/D/L record. `WinDrawLossRanking` is the built-in implementation, with
the sport conventions as named constructors — `threeOneZero()`
(association football) and `oneHalfZero()` (chess) — and plain-data
construction via `WinDrawLossRanking::fromArray(['win' => 3, 'draw' => 1,
'loss' => 0])` for config-driven platforms. The tiebreakers the library has,
and how a chain of them is built from plain data, are under
[Tiebreakers](#tiebreakers). Ties beyond the configured tiebreakers fall back to score
difference and score for. Entries that are still level are ordered by seed
(a participant without one after those that have one) and finally by ID, so
that the table is the same for every order of the participants array; a
label is never compared.

Each event may have at most one result, and the calculator throws an
`InvalidInputException` for two results of one event. Two results are of one
event when they hold the same `Event` object, or when their events carry the
same round number, the same participants in either order and the same tie
leg (the `tie_leg` metadata of a two-legged tie; an event without it is leg
1). The second rule is the one that holds for stored data: a result recorded
twice comes back from JSON as two `Event` objects that are equal, and it is
refused as it was before it was stored. Two things follow:

- An event with no round has nothing but the object to tell it from another
  meeting of the same participants, so two such events are two events
  played.
- One table over results that share round numbers (two seasons, or two
  stages that each count their rounds from 1) refuses the first pair that
  met in the same round of both. Calculate a table for each and combine
  them, or give the events round numbers that continue from one to the
  next. Two meetings of one pair in one round are told apart by a
  `tie_leg` each. The message of the exception names the round and the
  participants, and these ways out.

### Tied sets

A standings table gives every entry a position of its own, also when no
result separates two entries: the calculator then orders them by seed and
finally by ID, so that the same results always give the same table. That
order says nothing about how the participants performed. An application
that advances, relegates or seeds by position needs to know where it applies,
and `Standings::getTiedSets()` reports it:

```php
// Before any result exists nothing separates the four participants
$unplayed = $calculator->calculate($participants, []);

$advancing = 2;
foreach ($unplayed->getTiedSets() as $tiedSet) {
    $labels = array_map(
        fn(Participant $participant): string => $participant->getLabel(),
        $tiedSet->getParticipants()
    );
    echo "Positions {$tiedSet->getFirstPosition()} to {$tiedSet->getLastPosition()}: "
        . implode(', ', $labels) . "\n";

    // A set that starts at or above the cut and ends below it
    if ($tiedSet->getFirstPosition() <= $advancing && $tiedSet->getLastPosition() > $advancing) {
        echo "The results do not decide who takes the top {$advancing} positions\n";
    }
}

// In the table of the three results above, results decide every position
echo count($standings->getTiedSets()) . " tied sets\n";
```

That prints:

```
Positions 1 to 4: Alice, Bob, Carol, Dave
The results do not decide who takes the top 2 positions
0 tied sets
```

Two entries are **level** when every figure the calculator compares before
the fallback is equal: the ranking value, the value of each configured
tiebreaker, the score difference and scores-for
(`StandingEntry::isLevelWith()` is that comparison). A **tied set**
(`TiedSet`) is a group of two or more level entries, as large as it can be.
In a table the calculator built, level entries are always next to each other
(a `Standings` constructed by hand is read in the order it was given, and
only adjacent level entries form a set), so a set spans the
consecutive positions from `getFirstPosition()` to `getLastPosition()`, and
`getEntries()` and `getParticipants()` list its members in table order. The
sets are returned in table order; a table with two separate ties returns two
sets, each with its own positions, and a table in which results decide every
position returns an empty list. An entry is never in two sets.

A tie that a configured tiebreaker breaks is not reported: add a tiebreaker
to the calculator and the entries it separates leave the set. The record
(played, wins, draws, losses) is not compared unless a tiebreaker compares
it, because the table is not ordered by it.

"Equal" means the same number, with no tolerance, because that is the
comparison that orders the table: two entries are reported as tied exactly
when the calculator found nothing to order them by. The built-in rankings
(3/1/0 and 1/½/0) and tiebreakers add whole numbers and halves, which are
exact. A custom ranking value such as 0.1 is not: three wins at 0.1 add up to
slightly more than one draw at 0.3, so the table places the first entry above
the second and does not report them as tied. Use values with an exact binary
form (whole numbers, halves, quarters) where ties must be recognised.

Reading the tied sets changes nothing in the table: the entries, their order
and `getPosition()` are what they were. Deciding a tie (a play-off, a drawing
of lots, a ruling) is the application's rule. Once it is decided, the order
goes into the table as a decision:
[Settling a tied set by a decision](#settling-a-tied-set-by-a-decision).

**Where the participants are an entrant list, its order decides.** The seed
and the ID are the fallback of a table that stands alone. A stage has an
entrant list, and wherever one exists, the position in it decides what
results do not; a label, a seed attribute or an ID never does. The engines
order their own tables that way, and `withTiedSetsOrderedBy()` does it for a
table of yours. It moves entries inside a tied set only: every entry that
results place stays where it is, and the sets and their positions are the
same.

```php
// The list as entered, in the order that matters to the application
$entrants = [$dave, $carol, $bob, $alice];

$inListOrder = $calculator->calculate($entrants, [])->withTiedSetsOrderedBy($entrants);

echo implode(', ', array_map(
    fn ($entry): string => $entry->getParticipant()->getLabel(),
    $inListOrder->getEntries()
)) . "\n"; // Dave, Carol, Bob, Alice
```

A `StageOutcome` built with an entrant list (its last constructor argument)
does the same to the table it is given, so that what a rank selector reads
is the table the outcome shows.

**A rank is shared where a position is not.** `getPosition()` counts every
entry, so no two entries have one position. `getRank()` is the rank the
results give: every entry of a tied set has the rank of the set's first
position, and the entry after the set has the rank of its own position.
`getRanks()` lists the rank of every entry in table order.

```php
// One result so far: Alice beat Bob. Carol and Dave have not played.
$early = $calculator->calculate($participants, [new Result($eventOne, $alice)]);

echo implode(', ', $early->getRanks()) . "\n";                                     // 1, 2, 3, 3
echo "rank {$early->getRank($dave)}, position {$early->getPosition($dave)}\n";     // rank 3, position 4
echo implode(', ', $unplayed->getRanks()) . "\n";                                  // 1, 1, 1, 1
```

Bob is second there on Buchholz: the opponent he lost to has three points.
A rank does not change when the entries of a tied set are put in another
order, and it decides nothing either: a competition that shares a place, a
prize or a seeding between the holders of one rank reads it here and applies
its own rule. A tied set that a [decision](#settling-a-tied-set-by-a-decision)
ordered shares no rank: the decision placed its entries one after the
other, so each has its position as its rank.

### Settling a tied set by a decision

When every figure is level, the order has to come from outside the results:
the application settles it by its own rule and tells the library the order.
That is a **decision**: a `Decider` holding the IDs of the level
participants, best first, with an optional label and an optional reason.
The label and the reason are the application's words and are opaque to the
library, which stores them and hands them back. It records that the order
was decided outside the results, and never how.

```php
use MissionGaming\Tactician\DTO\Decider;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

// A pool of four in which every event is drawn 0-0
$four = array_map(fn (int $number) => new Participant("p{$number}", "Entrant {$number}"), range(1, 4));
$goalless = array_map(
    fn (Event $event) => new Result($event, null, [
        $event->getParticipants()[0]->getId() => 0,
        $event->getParticipants()[1]->getId() => 0,
    ]),
    (new RoundRobinScheduler())->schedule($four)->getEvents()
);
$level = (new StandingsCalculator())->calculate($four, $goalless);

$tiedSet = $level->getTiedSets()[0];
echo count($tiedSet) . " entries level in places {$tiedSet->getFirstPosition()} to {$tiedSet->getLastPosition()}\n";

// The application settles the order and records it, best first
$decision = new Decider(['p3', 'p1', 'p4', 'p2'], 'drawn', 'Drawn by the committee');
$decided = $level->withDecisions([$decision]);

foreach ($decided as $entry) {
    $settledBy = $decided->getDecisionFor($entry->getParticipant());
    echo "{$decided->getPosition($entry->getParticipant())}. {$entry->getParticipant()->getId()}"
        . ($settledBy === null ? '' : " ({$settledBy->getLabel()}: {$settledBy->getReason()})") . "\n";
}
```

That prints:

```
4 entries level in places 1 to 4
1. p3 (drawn: Drawn by the committee)
2. p1 (drawn: Drawn by the committee)
3. p4 (drawn: Drawn by the committee)
4. p2 (drawn: Drawn by the committee)
```

`withDecisions()` returns a new table in which the entries of the tied set
are in the order of the decision, in the places the set held. The table
carries the decision as the mark of those places: `getDecisionFor()` returns
it for a participant whose position it settled and null for one that results
placed, `getDecisions()` lists the decisions in table order, and the tied
set says which decision ordered it (`TiedSet::getDecision()`). A decided set
is still reported by `getTiedSets()`: its entries are level, and no result
orders them. Show such a position as decided, with the label and the reason.

**A decision never overrides a result.** It orders one whole tied set and
nothing else: no entry passes one it is not level with, none leaves its
tier, and every entry outside the set stays where it is. A decision that is
anything else is rejected whole with a `RejectedDecisionException`, and the
table is not changed. `getReason()` says which rule it broke, `getDecider()`
which decision, and `getParticipantIds()` which participants are at fault:

| The decision | Reason (`RejectedDecisionReason`) | `getParticipantIds()` |
|--------------|-----------------------------------|-----------------------|
| names a participant the table has no entry for | `UnknownParticipant` | the IDs with no entry |
| names a participant that another decision names too, one given with it or one the table already carries | `SetAlreadyDecided` | the IDs named twice |
| names participants that are not level with each other, or one that is level with nobody | `ParticipantsNotLevel` | the IDs that are not level with the first it names |
| names only some of the entries of a tied set | `PartOfLevelSet` | the entries it leaves out |

Where the decisions given together break more than one rule, the first in
the table is reported, whichever decision breaks it and whatever order they
are given in.

```php
use MissionGaming\Tactician\Exceptions\RejectedDecisionException;

foreach ([['p3', 'p1'], ['p3', 'p1', 'p4', 'p9']] as $ids) {
    try {
        $level->withDecisions([new Decider($ids)]);
    } catch (RejectedDecisionException $e) {
        echo "{$e->getReason()->value}: " . implode(', ', $e->getParticipantIds()) . "\n";
    }
}
```

```text
part_of_level_set: p2, p4
unknown_participant: p9
```

- **A decision orders the whole set, also when only its first places
  matter.** With two of the four to go through, the decision still names
  all four. A table then has one order whatever is read from it, and the
  same decision serves a selection of the top two and a display of the
  whole table.
- **Decisions are added, and a set takes one.** `withDecisions()` adds to
  the decisions a table carries, in any order and with the same result. To
  change a decision, apply the new one to the table as it was calculated.
- **Apply decisions last.** A decision is about the entries that are level
  once everything else is counted, so it comes after
  [adjustments](#adjustments) and after byes are credited. A table that
  carries a decision and is then given to `withAdjustments()` or
  `withByesCredited()` keeps it where the change leaves the decided set as
  it was, and is refused where it does not, with the reason `Stale`: the
  library does not show an order as decided for a set the decision was not
  made for, and does not drop it silently either.
- **A decision that results have overtaken is rejected.** Given again to a
  table in which a later result has separated its participants, it is
  `ParticipantsNotLevel`: there is nothing left for it to settle.
- **The entrant list leaves a decided set alone.** `withTiedSetsOrderedBy()`
  orders the sets that are still undecided, and so does a `StageOutcome`
  built with an entrant list.

```php
use MissionGaming\Tactician\Standings\Adjustment;

// The decision was made for four level entrants. A point is then taken from p1
try {
    (new StandingsCalculator())->withAdjustments($decided, [new Adjustment('p1', -1.0)]);
} catch (RejectedDecisionException $e) {
    echo $e->getReason()->value . "\n";
}

// Adjust first, and decide what is level after that
$adjustedFirst = (new StandingsCalculator())
    ->withAdjustments($level, [new Adjustment('p1', -1.0)])
    ->withDecisions([new Decider(['p3', 'p4', 'p2'], 'drawn')]);

echo implode(', ', array_map(fn ($entry) => $entry->getParticipant()->getId(), $adjustedFirst->getEntries())) . "\n";
```

```text
stale
p3, p4, p2, p1
```

A decision is plain data, as every `Decider` is: `toArray()` writes
`participants`, `label` and `reason` after `format_version`, and
`Decider::fromArray()` reads it back, so an application stores its decisions
beside its results. A stage state does not hold them. The engines pair and
re-seed from the entrant list where results are level
([position decides](#tied-sets)), and a decision changes neither: it is
applied to the table an application shows and to a
[rank selection](#selections-that-results-do-not-decide), which is where an
order that results left open is acted on. The table of an engine's outcome
takes one the same way: `$outcome->getStandings()->withDecisions([...])`.

A tie decided by lot between two qualifiers, from the tied table to the
bracket it seeds, is a runnable example:
[`examples/28-ties-settled-by-decision.php`](../examples/28-ties-settled-by-decision.php).

### Tiebreakers

A tiebreaker gives every participant a value, higher is better
(`TiebreakerInterface`). The calculator compares the values of entries that
are level on the ranking value, in the order the tiebreakers were given, and
stores them on the entry under the tiebreaker's name
(`getTiebreakerValue()`). The library has these:

| Identifier | Class | Value |
|------------|-------|-------|
| `wins` | `WinsTiebreaker` | The number of wins. |
| `score-difference` | `ScoreDifferenceTiebreaker` | Scores-for minus scores-against. |
| `scores-for` | `ScoresForTiebreaker` | The participant's own scores, added up. |
| `buchholz` | `BuchholzTiebreaker` | The ranking values of every opponent met, added up. |
| `sonneborn-berger` | `SonnebornBergerTiebreaker` | The ranking values of the opponents beaten, and half those of the opponents drawn with. |
| `ranking-value` | `RankingValueTiebreaker` | The ranking value of the results. It separates nobody on its own; it is a measure for the next row. |
| `between-tied:` and one of the above | `BetweenTiedParticipants` | That measure over the events between the tied participants only. |

The identifier is the name the tiebreaker gives itself, and so the key of
its value on an entry. The score difference and scores-for are compared
anyway, after every tiebreaker; naming them in the chain gives them an
earlier place and shows their values. The two tiebreakers that read
opponents' ranking values take an option for [byes](#byes-in-a-table).

#### Between the tied participants

Many competitions separate level participants by the events they played
against each other before any figure of the whole table (a sport calls it
head-to-head). `BetweenTiedParticipants` wraps another tiebreaker, the
measure, and asks it about a small table: the participants who are level
when its turn in the chain comes, and the results of the events between
them alone.

```php
use MissionGaming\Tactician\Standings\BetweenTiedParticipants;
use MissionGaming\Tactician\Standings\RankingValueTiebreaker;
use MissionGaming\Tactician\Standings\ScoreDifferenceTiebreaker;
use MissionGaming\Tactician\Standings\TiebreakerChain;

// Alice, Bob and Carol each beat one of the other two, and each beat Dave:
// the three finish on 6 points
$league = [
    new Result(new Event([$alice, $bob]), $alice, ['alice' => 3, 'bob' => 0]),
    new Result(new Event([$bob, $carol]), $bob, ['bob' => 2, 'carol' => 0]),
    new Result(new Event([$carol, $alice]), $carol, ['carol' => 1, 'alice' => 0]),
    new Result(new Event([$alice, $dave]), $alice, ['alice' => 1, 'dave' => 0]),
    new Result(new Event([$bob, $dave]), $bob, ['bob' => 1, 'dave' => 0]),
    new Result(new Event([$carol, $dave]), $carol, ['carol' => 9, 'dave' => 0]),
];

$betweenTheTiedFirst = new StandingsCalculator(
    WinDrawLossRanking::threeOneZero(),
    [
        new BetweenTiedParticipants(new RankingValueTiebreaker()),
        new BetweenTiedParticipants(new ScoreDifferenceTiebreaker()),
        new ScoreDifferenceTiebreaker(),
    ]
);

foreach ($betweenTheTiedFirst->calculate($participants, $league) as $entry) {
    echo $entry->getParticipant()->getLabel() . ': ' . $entry->getRankingValue() . ' pts, '
        . $entry->getTiebreakerValue('between-tied:ranking-value') . ' and '
        . $entry->getTiebreakerValue('between-tied:score-difference') . ' between the tied, '
        . $entry->getTiebreakerValue('score-difference') . " overall\n";
}
```

That prints:

```
Alice: 6 pts, 3 and 2 between the tied, 3 overall
Carol: 6 pts, 3 and -1 between the tied, 8 overall
Bob: 6 pts, 3 and -1 between the tied, 0 overall
Dave: 0 pts, 0 and 0 between the tied, -11 overall
```

The three are level on points, and on the points they took from each other
(3 each: one win). The score difference of the three events among them puts
Alice first, although Carol has the best score difference of the whole
table. Bob and Carol are still level, and the next tiebreaker in the chain,
the overall score difference, orders them.

How a tiebreaker of this kind is computed:

- **For the participants who are level.** A tied set is the participants
  with the same ranking value (the total, with byes and adjustments in it)
  and the same value for every tiebreaker before the run this one is in. A
  participant level with nobody is a set of one.
- **Several in a row are one small table.** Tiebreakers of this kind that
  follow one another in the chain are a run, and every one of a run is
  computed over the same tied set: the participants level when the first of
  the run is asked. So `between-tied:ranking-value`,
  `between-tied:score-difference` and `between-tied:scores-for`, in that
  order, rank everyone level on points by the points, then the score
  difference, then the scores-for of the events among all of them. Take
  three participants on 4 points, A 0-0 B, A 5-0 C and B 1-0 C: A and B
  have 4 points each from those events and C none, and A is above B on the
  score difference of the three events, +5 against +1, whatever the score
  difference of the whole table says. What A and B did against C counts,
  although the first measure placed C apart. A tiebreaker of another kind
  ends the run: one between the tied that comes after it is computed for
  the participants level at that point, as a new small table.
- **From the events between them only.** The measure is handed the results
  of the events whose participants are all in the set, and the entries of a
  table of the set calculated from those results alone, by the calculator's
  ranking strategy. Two participants who have not met have nothing between
  them: every measure gives each its value for no result, and they stay
  level.
- **Not again for a subset left level.** Where the values of a run split a
  set of four into one, two and one, the two that are left keep the values
  they have over the four and go on to the next tiebreaker of another
  kind; the run is not taken again over the events between the two. A
  competition that takes its measures again over those still level gives
  the chain a second run, after a tiebreaker of another kind, of
  `TiedParticipantsTiebreaker`s of its own under other names.
  `BetweenTiedParticipants` is named after its measure, so one measure has
  one place: a calculator whose chain holds a tiebreaker of this kind and
  has one name twice is refused when it is built (`InvalidInputException`).
- **The same whatever the order of the input.** The sets are read from the
  figures, so the values do not depend on the order of the participants or
  of the results.

A tiebreaker of your own is computed this way when it implements
`TiedParticipantsTiebreaker`, which adds no method to `TiebreakerInterface`:
it is handed the results and the entries of the tied set.

Who is tied depends on the byes and the adjustments of a table, so a
calculator with a tiebreaker of this kind takes them with the results, in
one call: [`calculateWith()`](#byes-in-a-table). `withAdjustments()` and
`withByesCredited()`, which change a table that exists, refuse such a
calculator (`IncompatibleOptions`) when they would change anything.

#### A chain from plain data

`TiebreakerChain::fromIdentifiers()` builds the tiebreakers from a list of
identifiers, in the order they apply, and `identifiersOf()` writes one. An
entry is an identifier of the table above, or a map that names one under
`tiebreaker` and gives its option. A whole calculator is plain data too:
`StandingsCalculator::fromArray()` reads a ranking
(`WinDrawLossRanking::fromArray()`), a chain and an awarded result policy,
each of which may be left out.

```php
$chain = TiebreakerChain::fromIdentifiers([
    'between-tied:ranking-value',
    'between-tied:score-difference',
    'score-difference',
]);
echo count($chain) . ' tiebreakers: ' . implode(', ', TiebreakerChain::identifiersOf($chain)) . "\n";

$configured = StandingsCalculator::fromArray([
    'ranking' => ['win' => 3, 'draw' => 1, 'loss' => 0],
    'tiebreakers' => ['wins', ['tiebreaker' => 'buchholz', 'byes' => 'own-ranking-value']],
    'awarded_results' => ['count_scores' => false],
]);
echo json_encode($configured->toArray()['tiebreakers']) . "\n";
```

That prints:

```
3 tiebreakers: between-tied:ranking-value, between-tied:score-difference, score-difference
["wins",{"tiebreaker":"buchholz","byes":"own-ranking-value"}]
```

An identifier that is not known, a key a map does not have and a name that
is in the chain twice are refused with an `InvalidConfigurationException`
(`UnknownIdentifier`, `UnknownOptionKey`, `DuplicateName`). Only the
library's tiebreakers and `WinDrawLossRanking` have a plain-data form: a
calculator that holds a tiebreaker or a ranking strategy of your own is
built in code, and `toArray()` refuses it (`NotSerializable`).

### Awarded results

An event that never takes place can still have an outcome: the application
awards it. Mark the result with an `Award` and it is an **awarded result**:
not played, and counting for the participant it names as the winner (for
nobody, as a draw, when it names none). Why the event was awarded is the
application's business. The `Award` takes an optional label and an optional
reason, both free text that the library stores and never reads.

```php
use MissionGaming\Tactician\DTO\Award;
use MissionGaming\Tactician\Standings\AwardedResultPolicy;

// Bob and Dave never met: the event is awarded to Dave, with a nominal 3-0
$awarded = new Result(
    new Event([$bob, $dave]),
    $dave,
    ['bob' => 0, 'dave' => 3],
    award: new Award('no-show', 'Bob did not appear')
);

$daveEntry = $calculator->calculate($participants, [...$results, $awarded])->getEntryFor($dave);

echo "{$daveEntry?->getWins()}W, {$daveEntry?->getRankingValue()} pts, "
    . "{$daveEntry?->getPlayed()} played of which {$daveEntry?->getAwarded()} awarded, "
    . "score difference {$daveEntry?->getScoreDifference()}\n";

// The same table where an awarded result carries no score
$withoutScores = new StandingsCalculator(
    WinDrawLossRanking::threeOneZero(),
    [new WinsTiebreaker(), new BuchholzTiebreaker()],
    AwardedResultPolicy::fromArray(['count_scores' => false])
);
$daveEntry = $withoutScores->calculate($participants, [...$results, $awarded])->getEntryFor($dave);

echo "{$daveEntry?->getWins()}W, {$daveEntry?->getRankingValue()} pts, "
    . "score difference {$daveEntry?->getScoreDifference()}\n";
```

That prints:

```
1W, 3 pts, 2 played of which 1 awarded, score difference 1
1W, 3 pts, score difference -2
```

An awarded result is a result in every respect that is not stated otherwise:

- It counts in the table. It is one of the results of its participants: a
  win for the winner it names and a loss for the other participant, or a
  draw for both. It is counted in `getPlayed()`, so that played is still
  wins plus draws plus losses, and `StandingEntry::getAwarded()` says how
  many of the results counted were awarded.
- It is the one result of its event. An awarded result beside a played
  result of the same event is two results of one event, and the calculator
  and the bracket engines refuse it as they refuse any two. To annul a
  played result and award the event, replace the result: in a list of
  results of your own, put the awarded result in the place of the played
  one, and in a stage state use `withResultReplaced()`
  ([Correcting a Recorded Result](#correcting-a-recorded-result)).
- It settles what a result settles. In an elimination bracket the
  participant it names advances, and a round of a stage whose events all
  have a result, played or awarded, awaits nothing.

Two things a competition decides for itself, and both are set on the
calculator with an `AwardedResultPolicy`, which is plain data
(`fromArray()`/`toArray()`):

- **`count_scores`** (`countScores`, on by default). On, the scores recorded
  on an awarded result are its nominal score (3-0 above) and count like any
  others. Off, they are added to no scores-for and no scores-against, so the
  score difference does not move. An awarded result without scores adds none
  either way.
- **`count_between_tied_participants`** (`countBetweenTiedParticipants`, on
  by default). Off, a tiebreaker computed between the tied participants only
  is handed the results without the awarded ones. The awarded result still
  counts in the table and in every other tiebreaker.

With both on, which is the default, an awarded result is counted exactly as
the same result without the mark. Where no result is awarded, neither choice
changes anything. The ranking strategy and the tiebreakers are handed each
result as it was recorded, so a strategy of your own that reads scores can
ask `Result::isAwarded()` and decide for itself.

A tiebreaker [between the tied participants](#between-the-tied-participants)
(a sport calls it head-to-head) is a `TiedParticipantsTiebreaker`, and the
calculator applies the choice to every one of them, the library's
`BetweenTiedParticipants` and one of your own alike, without a line of code
in the tiebreaker for it:

```php
// Alice and Bob finish on one win each. Alice's is over Bob, and was awarded.
$trio = [$alice, $bob, $carol];
$trioResults = [
    new Result(new Event([$alice, $bob]), $alice, award: new Award()),
    new Result(new Event([$bob, $carol]), $bob),
];

foreach ([true, false] as $counts) {
    $table = (new StandingsCalculator(
        tiebreakers: [new BetweenTiedParticipants(new WinsTiebreaker())],
        awardedResults: new AwardedResultPolicy(countBetweenTiedParticipants: $counts)
    ))->calculate($trio, $trioResults);

    $aliceEntry = $table->getEntryFor($alice);
    echo "{$aliceEntry?->getWins()}W, {$aliceEntry?->getTiebreakerValue('between-tied:wins')} between the tied, "
        . count($table->getTiedSets()) . " tied sets\n";
}
```

That prints:

```
1W, 1 between the tied, 0 tied sets
1W, 0 between the tied, 1 tied sets
```

With the exclusion on, nothing separates Alice from Bob any more: the
awarded win is still in her record and her points, and the tiebreaker did
not see it.

A result carries the mark in its array form under `award`, a key that is
there only for an awarded result, so the array of a played result has the
keys it had and data stored before the mark existed reads as played. A
misspelt `award` is refused like any key a result does not have, and not
read as a result that was played.

### Adjustments

Competitions add and remove points for reasons that are no event: a ruling,
a bonus, a penalty carried over. An `Adjustment` records one: the ID of the
participant, a signed amount, and an optional label and reason, free text
that the library stores and never reads. The application decides it; the
library applies it.

```php
use MissionGaming\Tactician\Standings\Adjustment;

// Alice has a point removed and Bob is given two
$adjustments = [
    new Adjustment('alice', -1.0, 'ruling', 'Late registration'),
    Adjustment::fromArray(['participant' => 'bob', 'amount' => 2]),
];

$adjusted = $calculator->withAdjustments($standings, $adjustments);

foreach ($adjusted as $entry) {
    echo "{$entry->getParticipant()->getLabel()}: {$entry->getEarnedRankingValue()} earned, "
        . "{$entry->getAdjustment()} adjustment, {$entry->getRankingValue()} total\n";
}
```

That prints:

```
Carol: 4 earned, 0 adjustment, 4 total
Alice: 4 earned, -1 adjustment, 3 total
Bob: 0 earned, 2 adjustment, 2 total
Dave: 0 earned, 0 adjustment, 0 total
```

The adjustment is never folded invisibly into what a participant earned. An
entry has three figures: `getEarnedRankingValue()` is what the results gave
(with the credit of a bye, in a table that credits byes),
`getAdjustment()` is the sum of the participant's adjustments, and
`getRankingValue()` is the two together, which is what the table is ordered
by and what two entries are level on. Nothing else on the entry changes: the
record, the scores and the awarded count are those of the results.

- **The amount is an amount of the ranking value.** "Points" belong to one
  way of ordering a table. Under `WinDrawLossRanking` the amount is points.
  Under any other `RankingStrategy` it is that strategy's unit, and since a
  ranking value is always higher-is-better, a positive amount always places
  the participant better and a negative one worse: two penalty strokes,
  under a strategy that ranks by strokes taken, negated, are `-2.0`. The
  calculator only adds, so it needs to know nothing about the scale, and
  unlike the credit of a bye an adjustment is accepted under every ranking
  strategy.
- **Tiebreakers use what was earned.** The tiebreaker values are those of
  the results. A tiebreaker computed from opponents' ranking values
  (`BuchholzTiebreaker`, `SonnebornBergerTiebreaker`) counts an opponent for
  the ranking value it earned: an opponent's adjustment is not in it,
  whatever the tiebreaker does with [byes](#byes-in-a-table). Points removed
  for a reason that is no event say nothing about how strong the opponent
  was to meet. One kind of tiebreaker does depend on the adjustments: who is
  tied, for a tiebreaker [between the tied participants](#between-the-tied-participants),
  is read from the totals with the adjustments in them, so a calculator
  that has one takes the adjustments with the results
  (`calculateWith($participants, $results, adjustments: $adjustments)`).
- **An adjustment for a participant the table does not have is rejected**
  with an `InvalidInputException`.
- **The order stays total.** The amounts for one participant are added
  exactly and rounded once, so the figure does not depend on the order of
  the list, and amounts that cancel leave nothing: 0.1 and 0.2 added and
  each taken back again are 0.0, and the participant is level again with
  whoever it was level with. Amounts that do not cancel are floats like any
  others: 0.1 and 0.2 are not 0.3, and [level means equal](#tied-sets).
  Entries that are level after the adjustments keep the order they had in
  the table given; where the participants are an entrant list, follow with
  `withTiedSetsOrderedBy()`, as the engines do.
- `withAdjustments()` replaces any adjustments the table carries: pass
  every adjustment of the competition each time.
- **A decision comes after the adjustments.** A
  [decision](#settling-a-tied-set-by-a-decision) settles entries that are
  level on the adjusted total. A table that already carries one is refused
  when the adjustments leave its participants no longer one whole tied set
  (`RejectedDecisionException`, reason `Stale`).

A results-driven stage keeps its adjustments on the stage state, so that
every table an engine builds has them, the one the next Swiss round is
paired from included:

```php
use MissionGaming\Tactician\Scheduling\SwissPairingEngine;
use MissionGaming\Tactician\Stage\StageState;

$swiss = new SwissPairingEngine();
$stage = StageState::start($participants);   // Alice, Bob, Carol, Dave

// Round 1 pairs Alice with Bob and Carol with Dave; Alice and Carol win
$opening = $swiss->pairNextRound($stage);
$stage = $stage->withRoundPlayed($opening, [
    new Result($opening->getEvents()[0], $alice),
    new Result($opening->getEvents()[1], $carol),
]);

// As it stands, the two winners meet next. Carol then has four points
// removed: she is last on -1, and Alice meets the best-placed participant
// she has not played.
$stage = $stage->withAdjustment(new Adjustment('carol', -4.0, 'ruling'));

foreach ($swiss->pairNextRound($stage)->getEvents() as $event) {
    $labels = array_map(fn (Participant $participant): string => $participant->getLabel(), $event->getParticipants());
    sort($labels);
    echo implode(' v ', $labels) . "\n";
}

// The adjustments are stored with the state
echo count(StageState::fromJson($stage->toJson())->getAdjustments()) . " adjustment stored\n";
```

That prints:

```
Alice v Dave
Bob v Carol
1 adjustment stored
```

`withAdjustment()` rejects an adjustment for a participant the stage has
not seen. Adjustments are kept in the order recorded and never merged; to
take one back, record the opposite amount, which keeps the record of both.
A participant who withdraws keeps its adjustments. In the table of an
elimination outcome an adjustment orders the entrants who went out in the
same round and moves nobody from one tier to another: who advanced is
decided by the ties.

An adjustment can be recorded at any point of any stage, and the state
keeps when: `withAdjustment()` records it as made after the last round the
state holds at that moment (`Adjustment::getAfterRound()`, 0 before the
first round), with no argument for it. The round that is paired next is
paired from the totals as they then stand, and a round that is already
recorded is ranked from the adjustments made before it was paired: an
adjustment made later changes none of its events and nothing that is read
from it. That holds for a re-seeded bracket (`reseedEachRound`) too, with a
withdrawal or without, and for the round an engine shows beside a recorded
one (`RecordedRound::getDerivation()`, see
[Rounds the engine would have paired otherwise](#rounds-the-engine-would-have-paired-otherwise)):
a round the engine paired is not reported as paired by hand because of an
adjustment made since. A table calculated on its own
(`StandingsCalculator::withAdjustments()`) has no rounds and does not read
the round of an adjustment.

Rows given to `StageState::fromHistory()` state the round of every
adjustment once they hold a round (`Adjustment::madeAfterRound()`; one
without it is refused, `AdjustmentWithoutRound`), and a round that is
played while adjustments can be made is recorded when it is paired
(`withRoundPaired()`); see
[When a Withdrawal or an Adjustment Happened](#when-a-withdrawal-or-an-adjustment-happened).

A combined pool table (`StageOutcome::combining()`) keeps the adjustment
each pool entry shows.

A league with an awarded result and an adjustment, from the results to the
table, is a runnable example:
[`examples/25-awarded-results-and-adjustments.php`](../examples/25-awarded-results-and-adjustments.php).

### Byes in a table

A bye is a round a participant sat out. It is no result, so
`calculate()` counts it nowhere. What it is worth in a table is the
competition's choice, and the **ranking strategy** states it
(`RankingStrategyWithByeValue::getByeValue()`): what a win is worth, what a
draw is worth, nothing, or any other amount. `WinDrawLossRanking` values a
bye as a win unless it is told otherwise, with `withByeValue()` or the key
`bye` of its plain data (a number, or `'win'`, `'draw'` or `'loss'` for the
value the same array gives that outcome).

`calculateWith()` calculates a table with its byes, and with its adjustments,
in one call. The byes are counts by participant ID, which is what a stage
state (`StageState::getByeCounts()`), an outcome (`StageOutcome::getByes()`)
and a schedule (`Schedule::getByeCounts()`) return.

```php
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Standings\ByeTreatment;

// Alice beat Bob and lost to Carol, Bob beat Carol. Alice and Carol each
// sat one round out.
$trioPlayed = [
    new Result(new Event([$alice, $bob]), $alice),
    new Result(new Event([$carol, $alice]), $carol),
    new Result(new Event([$bob, $carol]), $bob),
];
$byes = ['alice' => 1, 'carol' => 1];

foreach (['win', 'draw', 0] as $bye) {
    $ranking = WinDrawLossRanking::fromArray(['win' => 3, 'draw' => 1, 'loss' => 0, 'bye' => $bye]);
    $entry = (new StandingsCalculator($ranking))->calculateWith($trio, $trioPlayed, $byes)->getEntryFor($alice);

    echo "{$entry?->getPlayed()} played, {$entry?->getByes()} bye, "
        . "{$entry?->getRankingValueOfResults()} from results, {$entry?->getEarnedRankingValue()} earned\n";
}
```

That prints:

```
2 played, 1 bye, 3 from results, 6 earned
2 played, 1 bye, 3 from results, 4 earned
2 played, 1 bye, 3 from results, 3 earned
```

- **A bye is a figure of its own.** `getByes()` counts the byes of an entry,
  also where a bye is worth nothing. Played, wins, draws and losses count
  results only, so the record still adds up.
- **The ranking value says where it came from.**
  `getRankingValueOfResults()` is what the results are worth,
  `getEarnedRankingValue()` adds the value of each bye, and
  `getRankingValue()` adds the [adjustment](#adjustments). The table is
  ordered by the last.
- **One value, everywhere.** The engines hand the calculator the byes
  recorded on the stage state and read one table for the order a round is
  paired from and for the outcome, so a bye cannot be worth one thing for
  pairing and another in the final table.
- **A ranking strategy of your own says what a bye is worth** by
  implementing `RankingStrategyWithByeValue`. One that does not keeps
  working wherever no bye is credited; a table with a bye to credit is
  refused under it (`IncompatibleOptions`), because nothing says what
  sitting out counts for on its scale.

A tiebreaker that adds up the ranking values of opponents has to say what a
bye is in that sum, and the two the library has take the answer as a
`ByeTreatment`:

| `ByeTreatment` | The participant's own bye | An opponent's ranking value |
|----------------|---------------------------|-----------------------------|
| `Uncounted` (`uncounted`, the default) | adds nothing | the value of its results, without the credit of its byes |
| `Zero` (`zero`) | is an opponent worth nothing: adds nothing | the value it earned, with the credit of its byes |
| `OwnRankingValue` (`own-ranking-value`) | is an opponent with the participant's own earned ranking value | the value it earned, with the credit of its byes |

```php
// Alice's opponents: Bob has 3, all from results, and Carol has 6, of
// which 3 for her bye. Alice herself earned 6.
foreach (ByeTreatment::cases() as $treatment) {
    $table = (new StandingsCalculator(tiebreakers: [new BuchholzTiebreaker($treatment)]))
        ->calculateWith($trio, $trioPlayed, $byes);

    echo "{$treatment->value}: {$table->getEntryFor($alice)?->getTiebreakerValue('buchholz')}\n";
}
```

That prints:

```
uncounted: 6
zero: 9
own-ranking-value: 15
```

`Uncounted` is the default because it is what the tiebreakers computed
before a bye could be valued, so a table calculated by an earlier release is
calculated the same; choose one of the other two for a table in which a bye
is credited. `SonnebornBergerTiebreaker` takes the same three, and counts
the participant's own bye in full under `OwnRankingValue`, as an opponent
beaten. No treatment reads an opponent's adjustment. In a table without
byes the three give the same value.

A tiebreaker of your own reads byes by implementing
`TiebreakerWithByeTreatment`: it is then handed entries that carry the byes
and their credit, where every other tiebreaker is handed the entries of the
results alone.

`withByesCredited()` credits byes to a table that exists and keeps its
tiebreaker values as they are. That is right while no tiebreaker reads a
bye; with one that does, or with one between the tied participants, it
refuses a bye (`IncompatibleOptions`) and `calculateWith()` is the call.

**Which byes a schedule has** is read from the schedule: an entrant has a
bye in a round when no event of that round names it. `getByesByRound()`
lists them by round number, as participants, and `getByeCounts()` adds them
up, for a schedule a generator returned, one read back from storage and one
put together by hand alike. `RoundPairing::getByes()` has the byes of a
round an engine paired, and `StageState::getByesByRound()` those of every
round recorded.

```php
$oddField = (new RoundRobinScheduler())->schedule($trio);

foreach ($oddField->getByesByRound() as $round => $sittingOut) {
    echo "Round {$round}: " . count($sittingOut) . " bye\n";
}

$counts = $oddField->getByeCounts();
ksort($counts);
echo json_encode($counts) . "\n"; // {"alice":1,"bob":1,"carol":1}
```

## Swiss Tournaments

`SwissPairingEngine` is a **stage engine** (`StageEngineInterface`): it
pairs one round at a time from the recorded `StageState`. Participants are
ordered by standings and paired adjacently (Monrad style), backtracking
past repeat pairings. Byes rotate to the lowest-placed participant with
the fewest so far and are credited with what the ranking strategy says a
bye is worth, the value of a win unless you choose another: in the order
the next round is paired from, and in the table of the outcome, which is
the same table.

Where no result separates two participants, their position in the list the
stage was started with decides: the first round pairs position 1 with 2 and
3 with 4, and its bye goes to the last position. No seed attribute, label
or ID is read, so give `StageState::start()` the entrants in the order that
matters.

Participants who are level form a **score group**. With a `Randomizer` the
engine shuffles the order within each group, and a credited bye is level
with the win it stands for. Level means equal, with one allowance. A
`WinDrawLossRanking` value is a float sum, and with a scale floats cannot
hold exactly (0.1 for a draw) the same results added in another order
differ in the last digits; the engine reads two such totals as level when
they are no further apart than that rounding can put them, and never when a
real result separates them. With 3/1/0 or 1/0.5/0 scoring the sums are
exact, so level means equal. The values of a ranking strategy of your own
are always compared exactly: return equal values for participants you mean
to be level.

Every results-driven format shares one driver loop — the single
integration a platform writes:

```php
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Scheduling\SwissPairingEngine;
use MissionGaming\Tactician\Stage\StageState;

$participants = array_map(
    fn (string $name) => new Participant(strtolower($name), $name),
    ['Alice', 'Bob', 'Carol', 'Dave', 'Erin', 'Frank', 'Grace', 'Heidi']
);

// plannedRounds lets length-aware constraints (e.g. seed protection) size
// their windows correctly, and tells isComplete() when the stage ends
$engine = new SwissPairingEngine(plannedRounds: 5);

$state = StageState::start($participants);
while (!$engine->isComplete($state)) {
    $pairing = $engine->pairNextRound($state);

    // Record the round as soon as it is paired...
    $state = $state->withRoundPaired($pairing);

    // ...play it, then add its results
    $results = [];
    foreach ($pairing->getEvents() as $event) {
        $results[] = new Result($event, $event->getParticipants()[0]);
    }

    $state = $state->withAdditionalResults($results);
}

// The uniform completion product: standings, results, byes, final round
$outcome = $engine->getOutcome($state);
$leader = $outcome->getStandings()->getEntries()[0];
```

**Record a round when it is paired.** `withRoundPaired()` records the
pairing, its byes included, with none of its results, and
`withAdditionalResults()` adds them as they come; the engine waits for them
before it pairs the next round. A state's clock is the rounds it holds: a
withdrawal or an [adjustment](#adjustments) is recorded with the number of
the last round recorded at that moment, and a round is read from what was
recorded before it. Recorded when it is paired, the round comes before
whatever happens while it is played, which is what happened (see
[When a Withdrawal or an Adjustment Happened](#when-a-withdrawal-or-an-adjustment-happened)).
`withRoundPlayed($pairing, $results)` records a round and its results in one
call, where nothing can happen in between; `withRoundPaired($pairing)` is
`withRoundPlayed($pairing, [])`, and the two store the same state.

The outcome's standings are the table the rounds were paired from. A bye
counts in it as it counts for pairing: the ranking value of an entry
includes the value of a bye for each bye the participant had, and
`StandingEntry::getByes()` says how many. Played, wins, draws and losses
count results only, so with a bye worth a win a participant who had a bye
and won twice in three rounds shows two played, two wins and nine points.

What a bye is worth is the ranking strategy's to say
([Byes in a table](#byes-in-a-table)). A `WinDrawLossRanking` values it as a
win unless told otherwise, and the same value orders the next round and
stands in the final table:

```php
use MissionGaming\Tactician\Standings\StandingsCalculator;
use MissionGaming\Tactician\Standings\WinDrawLossRanking;

// Five participants, so one sits out each round; a bye scores nothing here
$field = array_slice($participants, 0, 5);
$byeScoresNothing = new SwissPairingEngine(
    standingsCalculator: new StandingsCalculator(WinDrawLossRanking::threeOneZero()->withByeValue(0.0)),
    plannedRounds: 3
);

$stage = StageState::start($field);
while (!$byeScoresNothing->isComplete($stage)) {
    $round = $byeScoresNothing->pairNextRound($stage);
    $stage = $stage->withRoundPlayed($round, array_map(
        fn ($event) => new Result($event, $event->getParticipants()[0]),
        $round->getEvents()
    ));
}

$byes = 0;
$scoredForByes = 0.0;
foreach ($byeScoresNothing->getOutcome($stage)->getStandings() as $entry) {
    $byes += $entry->getByes();
    $scoredForByes += $entry->getRankingValue() - 3.0 * $entry->getWins();
}
echo "{$byes} byes in the table, {$scoredForByes} points for them\n"; // 3 byes in the table, 0 points for them
```

Under a ranking strategy of your own that does not say what a bye is worth
(it is no `RankingStrategyWithByeValue`), a stage in which a bye is recorded
is refused (`IncompatibleOptions`). A tiebreaker that reads opponents'
ranking values (Buchholz, Sonneborn–Berger) leaves byes out unless it is
given a `ByeTreatment`: by default an opponent counts for the value of its
results, without its byes.

`StageState` absorbs the between-round bookkeeping (bye threading, round
numbers, played pairings) and serializes — `toArray()`/`fromArray()` and
`toJson()`/`fromJson()` — so platforms persist it between rounds instead
of re-deriving it. A platform that holds the rounds and results in its own
rows stores no state and builds one from them
([Stages Resolved from Your Own Rows](#stages-resolved-from-your-own-rows)).
Withdrawals are a first-class verb:
`$state->withoutParticipant($p)` removes a participant from pairing while
their recorded games still count toward standings. When repeat avoidance and
the constraints leave no complete pairing, a `NoValidPairingException` is
thrown with a diagnostic report.

A round is paired from the results of the rounds before it, so the engine
waits for them: while an event of a recorded round has no result,
`pairNextRound()` refuses with an `UnresolvedRoundException` that lists the
events, `isComplete()` is false, and nothing else throws. `getReadiness()`
answers the same without an exception, and
`pairNextRoundOnIncompleteResults()` pairs a round early on purpose
([Rounds That Are Not Finished](#rounds-that-are-not-finished)).

For a whole Swiss schedule without recorded results, use the
`SwissScheduler` preset, which drives this engine through the same loop
in results-free mode (`new SwissPairingEngine(resultsFree: true)`),
recording no results. With no results every participant of a field
of even size is level, so each round is a non-repeat pairing of the field:
drawn at random when the scheduler has a source of draws, as below, and
otherwise in the order of the list, the same on every call (the first round
pairs position 1 with 2 and 3 with 4). In a field of odd size one
participant has a bye in every round, and a bye counts as a win in the
pairing order: the participants who have had a bye are ordered ahead of
the others, with or without a source of draws, and are the first to be
paired, so those rounds are not a uniform draw. The scheduler refuses more
rounds than the number of participants minus one. That is the most a field
of even size can play without a repeat; a field of odd size could play one
round more and is refused it:

```php
use MissionGaming\Tactician\Scheduling\SwissOptions;
use MissionGaming\Tactician\Scheduling\SwissScheduler;
use Random\Randomizer;

$schedule = (new SwissScheduler(null, new Randomizer()))
    ->schedule($participants, new SwissOptions(rounds: 3));

// A draw that repeats: the same participants, rounds and seed give the
// same schedule on every call, also on one scheduler asked twice
$drawn = (new SwissScheduler())->schedule($participants, new SwissOptions(rounds: 3, seed: 2026));
```

The source of draws is the `seed` of the options or a `Randomizer` given to
the scheduler, and not both (a scheduler that has both refuses the call,
with the reason `IncompatibleOptions`). A seed is plain data
(`SwissOptions::fromArray(['rounds' => 3, 'seed' => 2026])`) and builds a new
`Randomizer` on the `Xoshiro256StarStar` engine for every call, so the engine
is part of the output for a seed. A `Randomizer` has state: a scheduler that
holds one continues its sequence, and two calls give two schedules.

Every round count the scheduler accepts is generated when it has no
constraints. A round is paired without a look at the rounds to come, so at
the larger round counts a draw can reach a round that has no pairing left
without a repeat. The scheduler then draws again, continuing the same
sequence, so the schedule is still a function of the seed; a schedule that
needs no second draw is the one the first draw gives. If twenty draws are
not enough, or there is nothing to draw from, it builds the rounds by the
rotation that lays out a round robin, which cannot fail. Without a source of
draws there is one draw, and it needs the rotation at a few of the largest
round counts (of the fields up to 44: 26 participants from 24 rounds on, 34
from 32, and 37 at 36). The first round is the list as given there too,
with the bye of a field of odd size at the last position; the later rounds
are those of the rotation, in which nobody has a second bye. With constraints a
schedule may not exist at all, and an `IncompleteScheduleException` reports
the round the last draw could not pair.

An event of a round that is never played is recorded with an
[awarded result](#awarded-results), which counts in the table like any other
result. Points added or removed for reasons that are no event are recorded
on the state with `StageState::withAdjustment()`: the next round is paired
from the adjusted totals, and the table of the outcome shows each
[adjustment](#adjustments) beside what was earned.

### Correcting a Recorded Result

`withResultReplaced()` replaces the result of one event of the **last
recorded round**, for a result that was entered wrongly. It corrects the
record; it does not decide an event by giving it a winner it did not have
(an elimination event that finished level stays a draw and is decided by
its decider, which this verb adds to the recorded draw where the decision
came after the round was recorded, see
[Recording a Level Event](#recording-a-level-event)). The event is found by its round
number, its participants in either order and its tie leg, so the state may
have come back from storage. The replacement takes the place of the old
result; nothing else in the state changes.

```php
// Round 5 is the last recorded round. Its first event was entered as a
// win for the first participant; the second participant won it.
$event = $pairing->getEvents()[0];
[$enteredWinner, $actualWinner] = $event->getParticipants();

$corrected = $state->withResultReplaced(new Result($event, $actualWinner));

echo count($state->getResults()) . ' results before, '
    . count($corrected->getResults()) . " after\n"; // 20 results before, 20 after
```

A result of an earlier round is refused. The rounds after it were paired
from it (a bracket's next round holds the winners, a Swiss round follows
the table), and a state that kept them over a changed result would hold
pairings no engine made from it:

```php
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;

$firstRoundEvent = $state->getRoundsPlayed()[0]->getEvents()[0];

try {
    $state->withResultReplaced(new Result($firstRoundEvent, $firstRoundEvent->getParticipants()[1]));
    echo "Replaced\n";
} catch (InvalidConfigurationException $e) {
    echo "Refused: round {$e->getContext()['round']} is not the last recorded round "
        . "({$e->getContext()['last_round']})\n"; // Refused: round 1 is not the last recorded round (5)
}
```

To correct an earlier round, rebuild the state: `StageState::start()`, then
`withRoundPlayed()` for each round that still stands, with the corrected
result, and ask the engine for the next round again. An event that has no
recorded result is refused as well: add a first result with
`withAdditionalResults()`.

### Recording Which Engine Pairs a State

A stage state does not say which engine paired its rounds, and an engine
replays whatever it is handed. A Swiss state restored into a bracket
engine, or a two-legged bracket into an engine built for one leg, is read
as that engine's own history. Stamp the state with the engine's
**fingerprint** and every engine with another fingerprint refuses it:

```php
use MissionGaming\Tactician\Scheduling\SingleEliminationEngine;

$engine = new SwissPairingEngine(plannedRounds: 5);

$stamped = StageState::start($participants)
    ->withEngineFingerprint($engine->getFingerprint());

// The stamp is stored with the state and comes back with it
$restored = StageState::fromJson($stamped->toJson());

echo count($engine->pairNextRound($restored)->getEvents()) . " events\n"; // 4 events

try {
    (new SingleEliminationEngine())->pairNextRound($restored);
    echo "Paired as a bracket\n";
} catch (InvalidConfigurationException $e) {
    echo 'Refused: ' . implode('; ', $e->getContext()['differences']) . "\n";
    // Refused: format: recorded swiss, this engine single-elimination
}
```

The stamp is optional. A state without one is accepted by every engine,
serializes without the `engine_fingerprint` key, and data stored before the
stamp existed loads as an unstamped state. A stamped state keeps its stamp
through every verb.

**What a fingerprint covers.** Two engines have the same fingerprint when
they are the same format and agree on every option that shapes which rounds
the format has or how they are paired. An option at its default value is
not part of the fingerprint. That is the rule by which an option is added:
an engine that gains an option keeps the fingerprint of every configuration
that leaves the option alone, and the states stamped before are still
accepted.

**How long a fingerprint lasts.** The `Stage` namespace is experimental,
and how a stage is described is not settled. The fingerprint follows the
rule for experimental API and promises nothing beyond it (see
[Versioning and stability](../README.md#versioning-and-stability)):

- A patch release keeps the fingerprint of every configuration, so within
  one 0.x series a stored stamp stays valid. The exception is the one
  the patch rule itself has: a fingerprint that was wrong (one string for
  two configurations that pair differently, for example) may be corrected,
  and the changelog then lists it under "Output change (fix)".
- A 0.x minor release may change the scheme. The changelog of that release
  then has a migration note saying what a stored stamp is replaced with.
  The string states the version of its scheme (`tactician:v2:`), so that a
  stamp of another scheme can be told from one of this scheme.

0.3.0 made that change, from `v1` to `v2`: it pairs a Swiss round and a
re-seeded bracket round from another order than 0.2.x did (the position in
the entrant list where 0.2.x read the seed attribute, the label and the ID,
and a bye credited in a re-seeded bracket), so the same recorded state can
give another next round. A state stamped by 0.2.x is therefore refused by
every engine, with a message that says the stamp is of an earlier release.
Once you have decided that the stage goes on under the rules of this
release, stamp it again:

```php
$fingerprintOfThisRelease = $engine->getFingerprint();
$stampedAgain = $state->withEngineFingerprint($fingerprintOfThisRelease);
```

Rounds already recorded stand. A Swiss stage pairs its later rounds by the
new order. A fixed-path bracket and a double-elimination bracket pair as
they always did. A re-seeded bracket keeps the rounds it has: a recorded
round that this release would pair differently is read as it was recorded
and reported as paired by hand
([Rounds the engine would have paired otherwise](#rounds-the-engine-would-have-paired-otherwise)),
and its later rounds are folded by the new order from what was played.

| Engine | Part of the fingerprint when not at the default | Not part of it |
| --- | --- | --- |
| `SwissPairingEngine` | The standings rules a round is paired from: the ranking scale (default 3/1/0), the tiebreakers in order (default none) | `plannedRounds`, the constraints, the randomizer |
| `SingleEliminationEngine` | `legsPerTie` (default 1), `reseedEachRound` (default off), `thirdPlace` (default off); in a re-seeded bracket also the standings rules, as for Swiss | `grandFinalReset`; the standings calculator of a bracket on a fixed path |
| `DoubleEliminationEngine` | `legsPerTie` (default 1), `grandFinalReset` (default on) | The standings calculator; `reseedEachRound` and `thirdPlace`, which the engine refuses |

```php
use MissionGaming\Tactician\Standings\StandingsCalculator;
use MissionGaming\Tactician\Standings\WinDrawLossRanking;

$default = (new SwissPairingEngine())->getFingerprint();

// The length of a Swiss stage is not part of it: a stage that is extended
// by a round keeps its stamp
var_dump((new SwissPairingEngine(plannedRounds: 6))->getFingerprint() === $default); // bool(true)

// 3/1/0 is the default scale, stated or not
$threeOneZero = new StandingsCalculator(WinDrawLossRanking::threeOneZero());
var_dump((new SwissPairingEngine(standingsCalculator: $threeOneZero))->getFingerprint() === $default); // bool(true)

// Another scale pairs the same results differently
$chess = new SwissPairingEngine(standingsCalculator: new StandingsCalculator(WinDrawLossRanking::oneHalfZero()));
var_dump($chess->getFingerprint() === $default); // bool(false)

try {
    $chess->pairNextRound($restored);
    echo "Paired on the chess scale\n";
} catch (InvalidConfigurationException $e) {
    echo 'Refused: ' . implode('; ', $e->getContext()['differences']) . "\n";
    // Refused: ranking: recorded the default, this engine win-draw-loss,1,0.5,0
}
```

The planned rounds of a Swiss stage say when it ends; no round is paired or
read differently for them. The standings calculator of a bracket that is not
re-seeded orders the outcome and pairs nothing.

**What it cannot cover.** A fingerprint describes what the library can
describe, and these limits follow from that:

- The constraints and the randomizer of a Swiss engine change pairings and
  are not part of the fingerprint: a closure and a seed have no name.
  Restore them with the state, as you do today.
- A `RankingStrategy` of your own is stated as "custom". The engine tells it
  from every `WinDrawLossRanking` scale and not from another strategy of
  your own. A subclass of `StandingsCalculator` is stated as "custom" too,
  whatever ranking strategy and tiebreakers it was built with: it may order
  the table by rules of its own, so it differs from the library's
  calculator and not from another subclass. A tiebreaker is stated by its
  `getName()`, so one that orders differently needs a name of its own,
  including a subclass of one of the library's.
- A subclass of `SwissPairingEngine` (the class is not final) does not have
  the fingerprint of the engine it extends, because it may pair differently.
  Two subclasses have the same one unless they override `getFingerprint()`.

**Treat the string as opaque.** Compare it for equality with the one an
engine gives. Do not parse it, write one by hand or rely on how it is
spelled: nothing is promised about the spelling, and the string itself is
kept only for as long as stated above. A fingerprint that begins with
`tactician:` is the library's. An engine of your own can use any non-empty
string that does not begin with it, and call
`$state->requireEngineFingerprint()` with that string.

All four engine methods (`getPlan()`, `pairNextRound()`, `isComplete()`,
`getOutcome()`) refuse a state stamped with another fingerprint, with an
`InvalidConfigurationException`. Its message, and the list `differences` in
its context, say where the two differ: the format, or each option with its
value on either side. That text is for a person and may change; the context
also holds the two fingerprints as `recorded` and `engine`. To change the
configuration on purpose in mid-stage, stamp the state again:
`$state->withEngineFingerprint($newEngine->getFingerprint())`; passing
`null` removes the stamp.

The three engines implement `Stage\FingerprintedEngine`. `getFingerprint()`
is not part of `StageEngineInterface`, so that an engine written against
that interface keeps working; code that holds a `StageEngineInterface`
tests for it before it stamps:

```php
use MissionGaming\Tactician\Stage\FingerprintedEngine;
use MissionGaming\Tactician\Stage\StageEngineInterface;

function startStage(StageEngineInterface $engine, array $participants): StageState
{
    $state = StageState::start($participants);

    return $engine instanceof FingerprintedEngine
        ? $state->withEngineFingerprint($engine->getFingerprint())
        : $state;
}

var_dump(startStage(new SingleEliminationEngine(), $participants)->getEngineFingerprint() !== null); // bool(true)
```

## Pot Draws

A pot draw is a format for a league phase or a seeding stage. The entrants
are ordered by seed and cut into **pots** of equal size, in order: the first
block of the list is pot 1, the next pot 2, and so on. Every entrant meets a
fixed number of opponents from every pot, its own included, never the same
opponent twice, and every entrant is in every round. Every round is drawn
before any event is played. The UEFA Champions League league phase since
2024/25 is the best-known example: 36 entrants, 4 pots of 9, two opponents
from every pot, so 8 events each.

Operators often call this format "Swiss". It is not Swiss pairing: nothing is
paired from results, and no standings exist while it is drawn. It is a
partial round robin constrained by pots. For pairing from results, use the
[Swiss engine](#swiss-tournaments).

`PotDrawScheduler` is a whole-schedule generator, like `RoundRobinScheduler`.
Its options are the number of pots, the number of opponents an entrant meets
from each pot, and the seed of the draw. The number of rounds is derived:
pots × opponents per pot.

```php
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\PotDrawOptions;
use MissionGaming\Tactician\Scheduling\PotDrawScheduler;

// 36 entrants in seeding order. List position is the seeding: the first
// nine are pot 1. No seed attribute is read.
$entrants = [];
for ($position = 1; $position <= 36; ++$position) {
    $entrants[] = new Participant("e{$position}", "Entrant {$position}");
}

$drawOptions = new PotDrawOptions(pots: 4, opponentsPerPot: 2, seed: 2026);
$scheduler = new PotDrawScheduler();

// The plan knows the shape before anything is drawn
$plan = $scheduler->getPlan($entrants, $drawOptions);
echo $plan->getAlgorithm() . "\n";          // pot-draw
echo $plan->getPotSize() . "\n";            // 9
echo $plan->getTotalRounds() . "\n";        // 8
echo $plan->getEventsPerRound() . "\n";     // 18
echo $plan->getExpectedEventCount() . "\n"; // 144

$schedule = $scheduler->schedule($entrants, $drawOptions);
echo count($schedule) . "\n";               // 144
```

The result is an ordinary `Schedule`: iterate it, group it by round,
serialize it, or hand it to the [timeline assigner](#timeline-assignment).
`$plan->getPotOf($participant)` gives the 1-based pot of an entrant and
`$plan->getPotMembers(1)` the members of a pot. With one pot the format is a
draw of non-repeat pairings over the whole field.

The generator is pairwise: every event has two participants. It takes no
`ConstraintSet`; pairs of entrants that must not meet are not an input yet.

### Roles

A role is the position of a participant in an event: the first-named
participant is in the first role (home, white, server) and the other in the
second. A pot draw balances them:

- the two role counts of an entrant differ by at most one, and are equal when
  the entrant has an even number of events;
- with an even number of opponents per pot, an entrant is in each role
  exactly half the time against every pot.

In the draw above every entrant has four events in each role, one of each
against every pot:

```php
$topSeed = $entrants[0];
$rolesByPot = [];
foreach ($schedule as $event) {
    if (!$event->hasParticipant($topSeed)) {
        continue;
    }

    [$first, $second] = $event->getParticipants();
    $isFirst = $first->getId() === $topSeed->getId();
    $rolesByPot[$plan->getPotOf($isFirst ? $second : $first)][] = $isFirst ? 'first' : 'second';
}

ksort($rolesByPot);
foreach ($rolesByPot as $pot => $roles) {
    sort($roles);
    echo "Pot {$pot}: " . implode(', ', $roles) . "\n";
}
```

```text
Pot 1: first, second
Pot 2: first, second
Pot 3: first, second
Pot 4: first, second
```

### The seed and determinism

The seed is an option, not a `Random\Randomizer` handed to the scheduler, so
that a draw can be stored as plain data and repeated. The same entrants, pots,
opponents per pot and seed give the same schedule on every call, on one
scheduler or on a new one; the scheduler keeps no state between calls. Another
seed gives another schedule. Any integer is a seed, and the default is 0.
This seed names a draw. It has nothing to do with the seed of a participant,
which is a ranking.

```php
use MissionGaming\Tactician\DTO\Schedule;

$stored = $drawOptions->toArray(); // ['format_version' => 1, 'pots' => 4, 'opponents_per_pot' => 2, 'seed' => 2026]

$again = (new PotDrawScheduler())->schedule($entrants, PotDrawOptions::fromArray($stored));
$other = $scheduler->schedule($entrants, new PotDrawOptions(pots: 4, opponentsPerPot: 2, seed: 2027));

$events = fn (Schedule $drawn): array => $drawn->toArray()['events'];
var_dump($events($again) === $events($schedule)); // bool(true)
var_dump($events($other) === $events($schedule)); // bool(false)
```

`PotDrawOptions::fromArray()` refuses a key it does not know (reason
`UnknownOptionKey`), as every `fromArray()` does, so a misspelt option is not
silently read as its default.
A key that is left out takes its default: one pot, one opponent per pot,
seed 0.

The seed chooses the order of the members inside each pot, the order of the
pots, which matchings are used, every choice of the walk described below, the
roles, and the order of the rounds and of the events in them. The
engine behind it (`Random\Engine\Xoshiro256StarStar`) is part of the output,
and the golden fixtures pin one draw of each worked case.

### How a draw is made, and what it is uniform over

A draw is made in three steps: who meets whom in which round is built, a walk
changes it, and then every event is given its roles.

The generator first builds the rounds directly, with no search. They keep
every rule of the format, and they have a shape the format does not ask for:
every round sets whole pots against each other, and the pairings between two
pots follow one pattern (a rotation of the two pots' members against each
other). The generator then walks away from that shape with two moves, each of
which keeps every rule:

- **The round exchange** moves events between two rounds. Following an
  entrant's event in one round, its opponent's event in the other, and so on,
  comes back to the entrant; the two rounds trade the events on the way. Every
  entrant passed is still in both rounds once, and no event changes.
- **The opponent exchange** changes who meets whom. In one round, two members
  of one pot whose opponents are in one pot exchange opponents, unless that
  would make two entrants meet twice. Each of the four keeps the pot its
  opponent comes from, so every count the format states is what it was.

A move the rules do not allow is skipped. Nothing is searched and nothing is
tried again, so a draw cannot fail and takes time in proportion to its number
of events. The walk takes 16 steps for every round of the schedule, and more
for a field of 60 entrants or fewer (1,024 divided by the number of entrants
and rounded down, for every round). A step is one round exchange and, in one
round, as many offers of an opponent exchange as the round has events.

**The roles are given last**, by one rule. Every entrant puts its events in
twos, in an order drawn for it: two events against the same pot as far as
they go, and then, with an odd number of opponents per pot, the events left
over, one against each pot. It is first in one event of a two and second in
the other. The twos join the events into trails, and one coin for each trail
decides every role on it. So an entrant is first as often as second but for
one event at most, and also against any one pot, which with an even number of
opponents per pot means exactly half each way.

What that gives, and what it does not:

- **Who meets whom is drawn evenly.** Between two given pots, or inside one,
  every pairing is as likely as any other.
- **The shape the rounds were built with is gone, as far as a longer walk can
  remove it.** The length of the walk was chosen by measurement, over every
  supported configuration with up to 60 entrants: how often a round is made
  of whole pots, how many rounds hold an event inside a pot, how often a
  round's events are listed pot pair by pot pair, how often two rounds form a
  single cycle, and how often the pairings between two pots, or among three,
  are still a rotation. A walk of this length and a walk ten times as long
  give the same figures: within 5 in 100 for every one of them in every
  configuration, and within 1 in 100 on average. For 36 entrants in 4 pots of
  9 with two opponents per pot: no round is made of whole pots (as built,
  five rounds in eight are), and the pairings between two pots pass the test
  for a rotation for 52 pot pairs in 100 (as built, for all), which is the
  share among all the ways two pots of nine can be joined.
- **No entrant is more likely to be first than another, and no pattern of
  roles is built in.** Every assignment of roles that keeps the counts above
  can be drawn. With two opponents per pot each is as likely as any other for
  the same pairings. Otherwise they are not equally likely, and the spread
  widens with the field: the class docblock of `PotDrawScheduler` gives the
  figures worked out for the smallest fields. That is about whole
  assignments: each single event is as likely one way round as the other.
- **The draw is not claimed to be uniform over every schedule the format
  allows.** A walk of this kind, run long enough, is as likely to end on any
  one of the ways to meet that it can reach as on any other. It is not proved
  that it can reach all of them, and the walk has a fixed length. The roles
  are drawn after the walk, so pairings that allow more assignments of roles
  are not drawn more often for that. For a few of the smallest fields every
  schedule was listed and the draws of 240,000 seeds counted against the
  list: 8 entrants in 2 pots of 4, 6 in 2 pots of 3, and 6 as one pot with
  four opponents each give every way to meet as often as any other, and every
  assignment of roles.
- **Two kinds of field keep part of the shape they were built with:**
  - with 6 entrants in 3 pots of 2, two rounds can only trade all of their
    events, and an exchange of opponents gives a round of the same pots: every
    round has one pot playing inside itself and the other two meeting each
    other. That is 48 of the 72 ways those nine events can be placed;
  - one pot in which every member meets every other is a single round robin:
    no pairing can change, and when the pot size less one is a prime number
    (a pot of 8, 12 or 14, say) its rounds are those of the circle method,
    with the members in a drawn order.

### Feasible, supported and refused configurations

A configuration can exist only if all four hold:

1. the number of entrants is even, because every entrant is in every round
   and no bye is issued;
2. the entrants divide into pots of equal size, because pots of unequal size
   cannot give every entrant the same number of opponents from every pot;
3. opponents per pot is at most pot size minus one, because an entrant cannot
   meet more members of its own pot than the pot has;
4. pot size × opponents per pot is even, because the events inside one pot
   number half of that product.

Of the configurations that can exist, this version draws those it can build
directly, without a search:

| Pot size | Opponents per pot | |
|------|------|------|
| Even | Any, up to pot size minus one | Drawn |
| Odd | 2 | Drawn (the number of pots is then even) |
| Odd | 4, 6, ... | Refused as not yet supported |

Everything else is refused before anything is drawn, by `getPlan()` and
`schedule()` alike, with an `InvalidConfigurationException` whose reason says
which rule failed and whose context carries the numbers:

```php
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;

$attempts = [
    '19 entrants, 1 pot, 2 per pot' => [19, 1, 2],
    '20 entrants, 3 pots, 1 per pot' => [20, 3, 1],
    '20 entrants, 5 pots of 4, 4 per pot' => [20, 5, 4],
    '20 entrants, 4 pots of 5, 1 per pot' => [20, 4, 1],
    '36 entrants, 4 pots of 9, 4 per pot' => [36, 4, 4],
];

foreach ($attempts as $name => [$count, $pots, $opponentsPerPot]) {
    try {
        $scheduler->getPlan(array_slice($entrants, 0, $count), new PotDrawOptions($pots, $opponentsPerPot));
    } catch (InvalidConfigurationException $e) {
        echo "{$name}: {$e->getReason()?->value} " . json_encode($e->getContext()) . "\n";
    }
}
```

```text
19 entrants, 1 pot, 2 per pot: odd_participant_count {"participant_count":19}
20 entrants, 3 pots, 1 per pot: unequal_pots {"participant_count":20,"pots":3,"remainder":2}
20 entrants, 5 pots of 4, 4 per pot: too_many_opponents_per_pot {"opponents_per_pot":4,"pot_size":4,"maximum_opponents_per_pot":3}
20 entrants, 4 pots of 5, 1 per pot: odd_pot_with_odd_opponents {"pot_size":5,"opponents_per_pot":1,"participant_slots_inside_one_pot":5}
36 entrants, 4 pots of 9, 4 per pot: configuration_not_yet_supported {"participant_count":36,"pots":4,"pot_size":9,"opponents_per_pot":4,"supported_opponents_per_pot_for_odd_pot_size":[2]}
```

| Reason | The configuration |
|------|------|
| `OddParticipantCount` | Has an odd number of entrants. It is checked before the rules on pots, so an odd field of three or more is reported as odd whatever the pots (a field of one is `TooFewParticipants`) |
| `UnequalPots` | Does not divide into the pots asked for |
| `TooManyOpponentsPerPot` | Asks for more opponents from a pot than a pot has other members |
| `OddPotWithOddOpponents` | Has pots of odd size and an odd number of opponents per pot |
| `ConfigurationNotYetSupported` | Can exist, and has no direct construction yet: pots of odd size with four or more opponents per pot |

`ConfigurationNotYetSupported` is raised by the scheduler. `Stage\PotDrawPlan`
itself describes every configuration that can exist, so a schedule made
elsewhere can be checked with `validateIntegrity()`. That check is of the
format and not only of the counts: every entrant once in every round, the
exact number of opponents from every pot, no rematch, and the role balance.
The scheduler runs it on every schedule it returns.

Generation takes time proportional to the number of events (entrants × pots ×
opponents per pot / 2), the walk included: a few milliseconds for 60
entrants in 6 pots with three opponents per pot.

## Elimination Brackets

The elimination engines are **presets**: a whole bracket as one stage,
behind the same stage driver loop as Swiss. Each engine works out the
bracket from the recorded stage state on every call.
Entry pairing folds by **list position** (position 1 is the top entrant;
positions 1 and 2 land in opposite halves), fields that are not a power of
two give byes to the top positions, and every round carries a label.

```php
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Scheduling\SingleEliminationEngine;
use MissionGaming\Tactician\Stage\MatchOutcomeSelector;
use MissionGaming\Tactician\Stage\StageState;

$participants = array_map(
    fn (string $name) => new Participant(strtolower($name), $name),
    ['Alice', 'Bob', 'Carol', 'Dave', 'Erin', 'Frank', 'Grace', 'Heidi']
);

$engine = new SingleEliminationEngine();

$state = StageState::start($participants); // list order = seeding order
while (!$engine->isComplete($state)) {
    $pairing = $engine->pairNextRound($state);
    echo "{$pairing->getLabel()}\n"; // 'quarterfinal', 'semifinal', 'final', ...

    $state = $state->withRoundPaired($pairing);

    $results = [];
    foreach ($pairing->getEvents() as $event) {
        // ...play the match; an event that finishes level needs a decision
        // (see "Recording a Level Event" below)...
        $results[] = new Result($event, $event->getParticipants()[0]);
    }
    $state = $state->withAdditionalResults($results);
}

$outcome = $engine->getOutcome($state);

// Who won the bracket: the winner of its last tie
$titleHolder = MatchOutcomeSelector::winners()->select($outcome)[0];
```

The outcome's standings are the bracket placement, with the win/loss record
of every entrant. In the bracket above (single elimination, one event per
tie, eight entrants): the winner 3-0, the runner-up 2-1, the semifinal
losers 1-1 and the quarter-final losers 0-1. The entrants who went out in
the same round share a **tier** of the table. The two semifinal losers are
level in theirs, and so are the four quarter-final losers: the table lists
them in the order of the entrant list, and `getTiedSets()` reports them.
See [Who won the bracket](#who-won-the-bracket) for what the table says of
every bracket.

`EliminationOptions` configures the preset (plain-data constructible via
`fromArray()`):

- `reseedEachRound: true` re-ranks survivors by standings and re-folds
  every round, instead of the default fixed bracket path. The standings
  are the win/loss table of the stage so far, in which a bye counts as the
  tie it stands for, won in every leg: an entrant who sat out the first
  round ranks with the winners of that round, not below them. Survivors
  who are level are in the order of the entrant list, so in a field of
  five the entrants in positions 1 and 2 are in opposite semifinals.
  That is with a bye worth a win, the default: the ranking strategy says
  what a bye is worth ([Byes in a table](#byes-in-a-table)), and with a
  bye worth nothing the entrants who sat out rank below the winners of
  the round. Crediting a bye needs that value: a re-seeded bracket whose
  field is not a power of two, which therefore has byes, is refused under
  a ranking strategy that does not say what a bye is worth (it is no
  `RankingStrategyWithByeValue`; reason `IncompatibleOptions`), from the
  first call on, before a round is paired.
- `legsPerTie: 2` plays every tie over two mirrored legs (annotated with
  `tie_leg` metadata). Whoever wins more legs advances; when the legs are
  level, the aggregate is **yours** to resolve — away goals, extra time,
  penalties are rules Tactician never owns — and you record the decision
  as the decider of one leg's result (see
  [Recording a Level Event](#recording-a-level-event)). Per-leg results
  remain ordinary results: the records of the
  table count each leg as it was recorded, and the decision places the
  entrant it sent on above the one it did not.
- `thirdPlace: true` adds a **third-place tie** to the last round of a
  single elimination bracket, between the two entrants who went out in the
  round before it, so that the bracket decides places 3 and 4 (see
  [A third-place tie](#a-third-place-tie)). Off by default, and refused by
  the double elimination engine.

`DoubleEliminationEngine` adds a losers bracket and a grand final: everyone
must lose twice to be eliminated, so when the losers champion wins the grand
final a reset match decides the title (disable with
`new DoubleEliminationEngine(new EliminationOptions(grandFinalReset: false))`).
Conflicting, duplicate, or round-less results are rejected with clear
errors; partially recorded rounds are completed with
`$state->withAdditionalResults([...])`. A round that is recorded and not
finished is read without an exception, and it is the next round that is
refused until the results are in
([Rounds That Are Not Finished](#rounds-that-are-not-finished)). An entrant
who withdraws from a bracket under way keeps its slot, and its opponent
advances without an event
([Withdrawals from a Bracket](#withdrawals-from-a-bracket)).

### Who won the bracket

Rank 1 of the outcome's standings is the participant who won the bracket,
rank 2 the one it beat in the last tie, and everyone else follows by the
round that put them out, the latest first. That holds for every bracket the
two engines play: any field size, byes, one or two legs per tie, single and
double elimination. `MatchOutcomeSelector::winners()` reads the same
participant from the recorded results of the last round, and the two never
differ.

The table is ordered by the bracket first and by the records second:

- The entrants who went out in the same round share a **tier**
  (`Standings::getTiers()`): the winner alone, the other finalist alone,
  then the two of the semifinals, the four of the quarter-finals, and so
  on. In double elimination a tier is a round of the losers bracket, so the
  places are 1, 2, 3, 4, then two, two, four, four. A
  [third-place tie](#a-third-place-tie) puts the two of the semifinals in a
  tier each, so the places of that bracket are 1, 2, 3, 4, then four.
- Inside a tier the standings calculator orders the entrants from their
  records: ranking value, tiebreakers, score difference, scores-for.
- Where that leaves two of them level, their position in the entrant list
  decides, and `getTiedSets()` reports them. A tied set never crosses a
  tier.

No record moves an entrant from one tier to another, and there are three
cases in which the records alone would say something else:

**Double elimination.** A participant who loses early, comes through the
losers bracket and wins the first grand final has more wins than the
participant who then wins the reset:

```php
use MissionGaming\Tactician\Scheduling\DoubleEliminationEngine;

[$alice, , , , , , , $heidi] = $participants;
$double = new DoubleEliminationEngine();

// Heidi loses to Alice in round 1, then wins every event she plays up to
// and including the first grand final. Alice wins the reset. In every
// other event the first-named participant wins
$doubleState = StageState::start($participants);
while (!$double->isComplete($doubleState)) {
    $pairing = $double->pairNextRound($doubleState);
    $doubleState = $doubleState->withRoundPaired($pairing);

    $results = [];
    foreach ($pairing->getEvents() as $event) {
        $heidiWins = $event->hasParticipant($heidi)
            && $pairing->getRoundNumber() > 1
            && $pairing->getLabel() !== 'grand final reset';
        $results[] = new Result($event, $heidiWins ? $heidi : $event->getParticipants()[0]);
    }
    $doubleState = $doubleState->withAdditionalResults($results);
}

$doubleOutcome = $double->getOutcome($doubleState);
[$rankOne, $rankTwo] = $doubleOutcome->getStandings()->getEntries();

echo "Rank 1: {$rankOne->getParticipant()->getLabel()} ({$rankOne->getWins()}-{$rankOne->getLosses()})\n";
echo "Rank 2: {$rankTwo->getParticipant()->getLabel()} ({$rankTwo->getWins()}-{$rankTwo->getLosses()})\n";
echo 'Won the bracket: ' . MatchOutcomeSelector::winners()->select($doubleOutcome)[0]->getLabel() . "\n";
```

```text
Rank 1: Alice (4-1)
Rank 2: Heidi (5-2)
Won the bracket: Alice
```

**A two-legged tie settled by a decider.** Each leg is recorded as it
was played, so two finalists who win a leg each have the same record. The
decision is what the bracket went by, and the table follows the bracket:

```php
use MissionGaming\Tactician\DTO\Decider;
use MissionGaming\Tactician\Scheduling\EliminationOptions;

$twoLegged = new SingleEliminationEngine(new EliminationOptions(legsPerTie: 2));

$finalState = StageState::start([$alice, $heidi]);
$finalTie = $twoLegged->pairNextRound($finalState);
[$firstLeg, $secondLeg] = $finalTie->getEvents();

// 2-1, then 0-1 the other way: a leg each. Under the application's rules
// Heidi advances, and the decider on the second leg says so
$finalState = $finalState->withRoundPlayed($finalTie, [
    new Result($firstLeg, $alice, [$alice->getId() => 2, $heidi->getId() => 1]),
    new Result(
        $secondLeg,
        $heidi,
        [$alice->getId() => 0, $heidi->getId() => 1],
        decider: new Decider([$heidi->getId()], 'tie-break')
    ),
]);

$finalOutcome = $twoLegged->getOutcome($finalState);
$heidiEntry = $finalOutcome->getStandings()->getEntries()[0];

echo 'Rank 1: ' . $heidiEntry->getParticipant()->getLabel() . "\n";
echo 'Won the tie: ' . MatchOutcomeSelector::winners()->select($finalOutcome)[0]->getLabel() . "\n";
echo count($finalOutcome->getStandings()->getTiedSets()) . " tied sets\n";
echo "Scores: {$heidiEntry->getScoreFor()} for, {$heidiEntry->getScoreAgainst()} against\n";
```

```text
Rank 1: Heidi
Won the tie: Heidi
0 tied sets
Scores: 2 for, 2 against
```

**A bye.** A bye is not an event, so it is no win in a record. A
participant who has a bye and wins the bracket has the same points as the
participant it beat in the final, who played a round more:

```php
$bob = $participants[1];

// Three entrants: Heidi, in position 1, has the bye of the first round
$byeState = StageState::start([$heidi, $alice, $bob]);
while (!$engine->isComplete($byeState)) {
    $pairing = $engine->pairNextRound($byeState);
    $byeState = $byeState->withRoundPlayed($pairing, array_map(
        fn ($event) => new Result($event, $event->getParticipants()[0]),
        $pairing->getEvents()
    ));
}

$byeOutcome = $engine->getOutcome($byeState);

foreach (array_slice($byeOutcome->getStandings()->getEntries(), 0, 2) as $entry) {
    echo "{$entry->getParticipant()->getLabel()} ({$entry->getWins()}-{$entry->getLosses()})\n";
}
echo 'Won the bracket: ' . MatchOutcomeSelector::winners()->select($byeOutcome)[0]->getLabel() . "\n";
```

```text
Heidi (1-0)
Alice (1-1)
Won the bracket: Heidi
```

Heidi and Alice both have three points from one win, and the bracket puts
Heidi first.

Use `MatchOutcomeSelector` to read who advances from one knockout round,
which the table of a whole bracket does not say, and the standings for the
placement of everyone. A rank selection over the table of a bracket reads
the places the bracket decided: `RankRangeSelector::overall(1, 2)` is the
two finalists. Where entrants of one tier are level (the two who went out
in the semifinals, when no score separates them), a selection that takes
one of them (`overall(3, 3)`) is refused, and so is one that takes both
(`overall(1, 4)`), whose order is undecided, unless the selector is told to
accept ties inside the selection (see
[Selections that results do not decide](#selections-that-results-do-not-decide)).
A bracket with a third-place tie decides those two places as well, and
both selections are answered.

### A third-place tie

A single elimination bracket leaves places 3 and 4 open: the two entrants
who went out in the semifinals share a tier, and only their records order
them. `EliminationOptions(thirdPlace: true)` (`'third_place' => true` in
plain data) makes the bracket decide them. Its last round then holds two
ties: the final, and a **third-place tie** between the two entrants who
went out in the round before it.

```php
use MissionGaming\Tactician\Stage\BracketTie;
use MissionGaming\Tactician\Stage\RankRangeSelector;

$withThirdPlace = new SingleEliminationEngine(new EliminationOptions(thirdPlace: true));

[$alice, $bob, $carol, $dave] = $participants;
$fourState = StageState::start([$alice, $bob, $carol, $dave]);

// Semifinals: Alice v Dave and Bob v Carol. The first-named win
$semifinalRound = $withThirdPlace->pairNextRound($fourState);
$fourState = $fourState->withRoundPlayed($semifinalRound, array_map(
    fn ($event) => new Result($event, $event->getParticipants()[0]),
    $semifinalRound->getEvents()
));

$lastRound = $withThirdPlace->pairNextRound($fourState);

echo "Round {$lastRound->getRoundNumber()}, {$lastRound->getLabel()}\n";
foreach ($lastRound->getEvents() as $event) {
    [$first, $second] = $event->getParticipants();
    echo BracketTie::of($event)?->value . ": {$first->getLabel()} v {$second->getLabel()}\n";
}
```

```text
Round 2, final
final: Alice v Bob
third-place: Dave v Carol
```

Both ties are in one round, which keeps the label of the last round of
every bracket (`'final'`), and the round lists the events of the final
first. The label therefore does not tell the two ties apart. Each event of
that round says which tie it belongs to: the engine writes the backing
string of a `BracketTie` case (`'final'` or `'third-place'`) under
`BracketTie::METADATA_KEY` (`'bracket_tie'`) in the event's metadata, and
`BracketTie::of($event)` reads it back as the case. The metadata is plain
data, so the mark is stored with the event, in `Event::toArray()` and in a
serialized stage state, and an application that keeps one record per tie
can store it as a column of its own. Only the events of the last round of
a bracket with the option carry it: `BracketTie::of()` is `null` for every
event of an earlier round and for every event of a bracket without the
option. The engine pairs and resolves the bracket without the mark: it
knows a recorded tie by its round and its two participants, as it knows
every other. The library reads the mark in two places, both named below:
`MatchOutcomeSelector` leaves the third-place tie out of its selection, and
an engine without the option refuses a state that holds one. So keep the
mark on the events you record the round with, and do not use the key
`bracket_tie` for data of your own.

The third-place tie is a tie like the others. It is played over the same
number of legs as the final (`legsPerTie: 2` gives it two events, each
marked `'third-place'` beside its `tie_leg`), and one that finishes level
is decided by the decider recorded on its result (see
[Recording a Level Event](#recording-a-level-event)). The stage is complete
when both ties of the last round are decided. With one of them decided and
the other not, the round is not finished: the bracket is not complete, and
the next round is refused (`RoundPartiallyResolved`) until the missing
result is recorded with `$state->withAdditionalResults([...])`, as in every
round of a bracket ([Rounds That Are Not Finished](#rounds-that-are-not-finished)).

```php
[$final, $thirdPlaceTie] = $lastRound->getEvents();

$fourState = $fourState->withRoundPlayed($lastRound, [
    new Result($final, $bob),
    new Result($thirdPlaceTie, $carol),
]);

$fourOutcome = $withThirdPlace->getOutcome($fourState);

foreach (array_values($fourOutcome->getStandings()->getEntries()) as $index => $entry) {
    echo ($index + 1) . ". {$entry->getParticipant()->getLabel()} ({$entry->getWins()}-{$entry->getLosses()})\n";
}
echo 'Third: ' . RankRangeSelector::overall(3, 3)->select($fourOutcome)[0]->getLabel() . "\n";
echo 'Won the bracket: ' . implode(', ', array_map(
    fn (Participant $participant) => $participant->getLabel(),
    MatchOutcomeSelector::winners()->select($fourOutcome)
)) . "\n";
```

```text
1. Bob (2-0)
2. Alice (1-1)
3. Carol (1-1)
4. Dave (0-2)
Third: Carol
Won the bracket: Bob
```

The outcome gives places 1 to 4: the entrant who advanced from the final,
the other finalist, the entrant who advanced from the third-place tie, and
the other one. Each of the four is alone in its tier, so no record moves
one of them and a rank selection of any of those places is decided. The
results of the third-place tie count in the records as those of every tie
do.

`MatchOutcomeSelector` reads the outcome's last round, which now holds two
ties, and leaves the third-place tie out: that tie places its two entrants
and sends neither on. `winners()` is the one participant who won the
bracket and `losers()` the other finalist, as after a bracket without the
tie and in whatever order the round was recorded, and a
`StageTransition` with `afterWholeBracket: true` still counts one. The
places the tie decides are a rank selection (`overall(3, 3)`,
`overall(1, 4)`). The engine knows its two ties by their participants, and
its outcome says which of them is the final
(`StageOutcome::getSettledTies()`), so the selection is the final's also
where the last round was recorded with events that do not carry
`bracket_tie`. For an outcome that carries no settled ties, as one built by
hand does, the selector reads the results and knows the tie by its mark
alone: such a round without the marks is read as any round is, one
participant for each of its two ties in the recorded order.

What the option does in each bracket:

| Bracket | With `thirdPlace: true` |
|---------|-------------------------|
| 4 entrants or more | The last round holds the final and the third-place tie. Byes are in the first round only, so the round before the last always has two ties and two entrants go out in it. |
| 3 entrants | No third-place tie is played. The top entrant has a bye in the round before the last, so one entrant goes out in it and is third: the table is the table of the bracket without the option. The final is still marked `'final'`. |
| 2 entrants | Refused on every call (`TooFewParticipants`): there is no third place to decide. |
| Double elimination | Refused when the engine is constructed (`IncompatibleOptions`): the losers bracket already decides third place. |

Which of the two entrants is first-named follows the final. On a fixed
path the entrant who went out of the tie that fed the final's first-named
side is first-named, so the tie mirrors the final (above, Alice came
through the first semifinal and Dave went out of it). A bracket with
`reseedEachRound` folds the final from the table of the earlier rounds, and
names the two entrants of the third-place tie in the order of the same
table, level entries in the order of the entrant list.

The plan counts the tie: `getPlan($state)->getExpectedEventCount()` is one
tie more than the bracket without it, `hasThirdPlaceTie()` says whether the
bracket has one, and `getTotalRounds()` does not change. The option is part
of the [engine fingerprint](#recording-which-engine-pairs-a-state) when it
is on, so a state stamped by an engine with it is refused by an engine
without it, and the other way round; with the option off the fingerprint is
the one it was. A state that carries no stamp is told apart by its last
round, once that is recorded. A last round recorded without the third-place
tie is read by an engine with the option as a round paired by hand, and
decides places 1 and 2 only (see
[Rounds the engine would have paired otherwise](#rounds-the-engine-would-have-paired-otherwise)).
An engine without the option refuses a state whose round holds an event
marked `'third-place'` (`RecordedRoundMismatch`). Until then the state says
nothing of the option, and
either engine pairs its last round; a field of 3, which has no such tie, is
read by both. In plain data the option is the key
`third_place`: `EliminationOptions::toArray()` writes it only when the
option is on, and an array without the key is a bracket without the tie.

Not offered: a number of legs for the last round that differs from the
rest of the bracket, and ties for the places below fourth.

### Recording a Level Event

An elimination tie must send one participant on, and an event can still
finish level. Who advances is then **your** decision, under rules Tactician
never owns and never asks about. Record the event as the draw it was, with
the scores it was played to, and give its result a **decider**
(`DTO\Decider`): the ID of the participant who advances, and optionally a
label and a reason in your own words. The library stores the label and the
reason and never reads them: it records that the tie was decided outside
its results, and for whom, and never how. This is the reading a level
two-legged tie has, in both engines and in every round of them (the losers
bracket, the grand final and its reset, and a
[third-place tie](#a-third-place-tie) included).

```php
use MissionGaming\Tactician\DTO\Decider;

[$alice, $bob, $carol, $dave] = $participants;

$state = StageState::start([$alice, $bob, $carol, $dave]);
$semifinals = $engine->pairNextRound($state);
[$aliceVsDave, $bobVsCarol] = $semifinals->getEvents();

$state = $state->withRoundPlayed($semifinals, [
    // Level at 1-1; Dave is the one who advances
    new Result(
        $aliceVsDave,
        null,
        ['alice' => 1, 'dave' => 1],
        decider: new Decider([$dave->getId()], 'tie-break', 'Level after the event')
    ),
    new Result($bobVsCarol, $bob, ['bob' => 2, 'carol' => 0]),
]);

foreach ($engine->pairNextRound($state)->getEvents() as $event) {
    [$first, $second] = $event->getParticipants();
    echo "{$first->getLabel()} v {$second->getLabel()}\n"; // Dave v Bob
}
```

The recorded result is not changed: `isDraw()` is true, `getWinner()` is
null and the scores are the scores. The decider holds no score and changes
none. Whatever your application keeps about its own tie-break (a count, a
time) belongs in the result's `metadata`, where no score figure of the
library reads it: scores-for, scores-against and the score difference of a
table come from the scores of the events and from nothing else. To read who
advanced from a result yourself, read its decider, or ask
`TieDecision::advancer()`, which is the reading the engines and
`MatchOutcomeSelector` use:

```php
use MissionGaming\Tactician\Stage\TieDecision;

$recorded = $state->getResults()[0];

echo ($recorded->isDraw() ? 'drawn' : 'won') . ', '
    . TieDecision::advancer([$recorded], $alice, $dave, 1)?->getLabel() . " advances\n"; // drawn, Dave advances
echo implode('-', $recorded->getScores()) . ', decided for '
    . implode(', ', $recorded->getDecider()?->getParticipantIds() ?? [])
    . " by {$recorded->getDecider()?->getLabel()}\n"; // 1-1, decided for dave by tie-break
```

The decider names its participants as a list, because nothing in the type
assumes two sides. A tie of the bracket engines is between two and sends
one of them on, so there the list holds exactly one ID.

It is stored with the result. `Result::toArray()` writes it under `decider`
(`participants`, `label`, `reason`) for a result that has one and writes no
such key for any other, and a stage state keeps it through `toArray()` and
JSON, so a state that holds decided ties is replayed as it was recorded:

```php
$restored = StageState::fromJson($state->toJson());
[$level, $won] = $restored->toArray()['results'];

echo json_encode($level['decider']), "\n";
// {"participants":["dave"],"label":"tie-break","reason":"Level after the event"}
echo json_encode($level['scores']), "\n";                                    // {"alice":1,"dave":1}
echo array_key_exists('decider', $won) ? 'a decider' : 'no decider', "\n"; // no decider
echo implode(' v ', array_map(
    fn (Participant $p) => $p->getLabel(),
    $engine->pairNextRound($restored)->getEvents()[0]->getParticipants()
)) . "\n"; // Dave v Bob
```

In the outcome's standings a level event that was decided this way counts
as a win for the participant who advanced and a loss for the other, with
the scores as recorded. The records of a single-leg bracket are therefore
the ones it has when every event has a winner, and re-seeding
(`reseedEachRound`) ranks the survivor of a level event with the other
winners of the round. The participant who advanced from a level final is
rank 1, as in every bracket (see
[Who won the bracket](#who-won-the-bracket)).

Only the table the engine computes reads the decision. The outcome's
results are the results as recorded, so a table you compute from them
yourself counts the level event as a draw, and so does
`StageOutcome::combining()`, which computes its combined table from the
results of the outcomes it is given.

A level event that names nobody leaves its tie undecided. The state itself
accepts the result, because it does not know the format, and the tie then
awaits the decision as another tie awaits a result: the bracket is not
complete (`isComplete()` is false, `getOutcome()` null), reading it throws
nothing, and `getReadiness()` lists the events of the tie
(`PairingReadiness::getUndecidedEvents()`; see
[Rounds That Are Not Finished](#rounds-that-are-not-finished)). What is
refused is the next round: `pairNextRound()` says which tie, with the
reason `UndecidedTie` and, in the context of the exception, the round and
the two participants.

```php
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;

$undecided = StageState::start([$alice, $bob, $carol, $dave])
    ->withRoundPlayed($semifinals, [
        new Result($aliceVsDave, null, ['alice' => 1, 'dave' => 1]),
        new Result($bobVsCarol, $bob),
    ]);

try {
    $engine->pairNextRound($undecided);
    echo "Paired\n";
} catch (InvalidConfigurationException $e) {
    echo "{$e->getReason()?->value}: {$e->getMessage()}\n";
    // undecided_tie: Invalid scheduler configuration: Elimination events cannot end in a draw
    // (Alice vs Dave): the event is level, so record who advances as the decider of its result
    echo json_encode($e->getContext()), "\n"; // {"round":1,"participants":["alice","dave"]}
}
```

A decider settles a level tie and does nothing else, so one that says
anything else is refused:

| What is recorded | What happens |
|------------------|--------------|
| A level tie, and no decider on any of its results | The tie awaits its decider: the bracket is not complete, and `pairNextRound()` refuses with `UndecidedTie` |
| A decider that names a participant who is not in the event | `Result` refuses it when it is built, and when it is read from plain data (`InvalidInputException`) |
| A decider on a tie that its results already decide: an event that has a winner, or a leg of a two-legged tie that the leg wins decide | The engine refuses the state on every call: `InvalidResult`. A decider cannot confirm a result or overturn one |
| A decider that names more than one participant of a tie between two | `InvalidResult` |
| A decider on both legs of a two-legged tie | `InvalidResult`, whether the two agree or not: a tie has one decision |

```php
try {
    $engine->pairNextRound(StageState::start([$alice, $bob, $carol, $dave])->withRoundPlayed($semifinals, [
        // Alice won 2-1: there is nothing left to decide
        new Result($aliceVsDave, $alice, ['alice' => 2, 'dave' => 1], decider: new Decider([$dave->getId()])),
        new Result($bobVsCarol, $bob),
    ]));
    echo "Paired\n";
} catch (InvalidConfigurationException $e) {
    echo "{$e->getReason()?->value}\n"; // invalid_result
}
```

**Two legs.** The decider goes on one result of the tie. Either leg's
result is read, and the last leg played is the natural place: that is when
the tie is known to be level. While a leg has no result the tie is
incomplete and a decider on the other leg decides nothing yet. The
[two-legged final above](#who-won-the-bracket) is the worked case: 2-1,
then 0-1, and the decider on the second leg.

**Elsewhere.** Only the elimination engines and `MatchOutcomeSelector` read
a decider. A standings calculator, the Swiss engine and a round robin carry
it with the result and do not read it: a drawn result is a draw there, with
a decider or without.

**Before 0.3.0** the decision was `tie_winner` metadata on the result. The
key is deprecated and still read; see
[The `tie_winner` metadata key](#the-tie_winner-metadata-key).

Record the decision with the result where it is known by then, in the
`withRoundPlayed()` call that records the round. Where it comes later, the
tie awaits it as another tie awaits a result
([Rounds That Are Not Finished](#rounds-that-are-not-finished)), and
`$state->withResultReplaced(...)` is how it is added: replace the result
with the same draw, carrying its decider. That changes nothing the event
recorded. What the verb is not for is turning a level event into a win it
was not: if the draw was entered by mistake, replace it with the result the
event had ([Correcting a Recorded Result](#correcting-a-recorded-result)),
and if the event did finish level, leave it a draw and record who advances.

```php
$decided = $undecided->withResultReplaced(new Result(
    $aliceVsDave,
    null,
    ['alice' => 1, 'dave' => 1],
    decider: new Decider([$alice->getId()])
));

[$final] = $engine->pairNextRound($decided)->getEvents();
echo implode(' v ', array_map(fn (Participant $p) => $p->getLabel(), $final->getParticipants())) . "\n"; // Alice v Bob
```

### An Event That Is Not Played

A tie that never takes place is settled by an
[awarded result](#awarded-results): record a result that carries an `Award`
and names the participant who goes on, and that participant advances, in
both engines and in every round of them.

```php
use MissionGaming\Tactician\DTO\Award;

$bracket = StageState::start([$alice, $bob, $carol, $dave]);
$semifinals = $engine->pairNextRound($bracket);
[$aliceVsDave, $bobVsCarol] = $semifinals->getEvents();

$bracket = $bracket->withRoundPlayed($semifinals, [
    // Alice is not there: the event is awarded to Dave
    new Result($aliceVsDave, $dave, award: new Award('no-show')),
    new Result($bobVsCarol, $bob, ['bob' => 2, 'carol' => 0]),
]);

[$final] = $engine->pairNextRound($bracket)->getEvents();
echo implode(' v ', array_map(fn (Participant $p) => $p->getLabel(), $final->getParticipants())) . "\n"; // Dave v Bob
```

An awarded result is read as any other result of the tie. In a two-legged
tie it is a leg won, and every leg needs a result, so a tie that is not
played at all takes an awarded result for each leg. An awarded result that
names no winner leaves the tie level, like a draw, and the tie then needs
its decider as above. The awarded result can carry it: the award says that
the event was not played, and the decider who goes on from the level tie.
The two do not otherwise meet, so an awarded result that names a winner
decides its tie and takes no decider, as a played one does. Awarding an
event to both sides, or to neither, is not something a result can say.

```php
$notPlayed = StageState::start([$alice, $bob, $carol, $dave])->withRoundPlayed($semifinals, [
    // Neither side is there. The event is awarded to nobody, and Dave goes on
    new Result($aliceVsDave, award: new Award('double no-show'), decider: new Decider([$dave->getId()], 'drawn by lot')),
    new Result($bobVsCarol, $bob, ['bob' => 2, 'carol' => 0]),
]);

[$final] = $engine->pairNextRound($notPlayed)->getEvents();
echo implode(' v ', array_map(fn (Participant $p) => $p->getLabel(), $final->getParticipants())) . "\n"; // Dave v Bob
```

## Stages Resolved from Your Own Rows

An application that keeps its fixtures and results in its own tables does
not have to store a `StageState` beside them. `StageState::fromHistory()`
builds one on each request from the **history** the application holds: the
entrants in their order, the rounds that were paired, whatever results
exist, and who has withdrawn. An engine reads it as it reads a state that
was recorded verb by verb and stored.

```php
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Scheduling\SingleEliminationEngine;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Stage\StageState;

// From the application's rows: four entrants, in seeding order...
$ada = new Participant('ada', 'Ada');
$bo = new Participant('bo', 'Bo');
$cy = new Participant('cy', 'Cy');
$di = new Participant('di', 'Di');

// ...the semifinals that exist as fixtures, and the one result so far
$semifinal = new Round(1, ['label' => 'semifinal']);
$adaVsDi = new Event([$ada, $di], $semifinal);
$boVsCy = new Event([$bo, $cy], $semifinal);

$history = StageState::fromHistory(
    entrants: [$ada, $bo, $cy, $di],
    rounds: [new RoundPairing(1, 'semifinal', [$adaVsDi, $boVsCy])],
    results: [new Result($adaVsDi, $ada)],
);

$bracket = new SingleEliminationEngine();
var_dump($bracket->isComplete($history)); // bool(false)
```

**History is supplied, never recomputed.** A round that exists is a fact:
the engine reads it as it is and derives only the round that does not exist
yet. It never pairs a recorded round again, and it never refuses one for
being another round than it would have paired.

| Argument | What it is |
| --- | --- |
| `entrants` | The list the first round was paired from, in its order. An entrant who has withdrawn since stays in it, so that it keeps its place |
| `rounds` | One `RoundPairing` for each round that was paired: its number, its label, its events and the participants who had a bye. In any order |
| `results` | The results recorded so far, of any of the rounds, in any order. A result is matched to its event by round number, the two participants in either order and the `tie_leg` of the event's metadata, not by object |
| `withdrawals` | The entrants who have withdrawn, each as a `Withdrawal` with the number of the last round recorded when it withdrew (0 before the first), those after one round in the order they happened. Once `rounds` holds a round every withdrawal says its round; a plain participant is accepted only where no round is supplied |
| `adjustments` | The [adjustments](#adjustments) made, in the order they are to be kept in, each with the round it was made after (`Adjustment::madeAfterRound()`, 0 before the first). Once `rounds` holds a round every adjustment says its round; one without it is accepted only where no round is supplied |

The state is the one the verbs build: `StageState::start()`, then
`withRoundPlayed()` for each round in the order of the numbers, with its
results in the order of its events, with `withoutParticipant()` and
`withAdjustment()` at the moments the history says they happened (see
[When a Withdrawal or an Adjustment Happened](#when-a-withdrawal-or-an-adjustment-happened)).
It carries no engine fingerprint; add one with
`withEngineFingerprint()` where you keep one. The order of the events inside
a round is kept as you give it, and no engine reads a recorded round by it:
the rows may come back from the database in any order.

Rows can state what no engine paired, so a history is held to three **hard
rules** beside what recording a round always requires. Each breach is an
`InvalidConfigurationException` with a reason, and its context names the
round and the participant:

| The history | Reason |
| --- | --- |
| Round numbers that are not 1, 2, 3 and so on: one left out, or one given twice | `RoundOutOfSequence` |
| A participant of an event, a bye, a result, a withdrawal or an adjustment who is not one of the entrants | `ParticipantNotAnEntrant` |
| A participant in a round twice: in two events against different opponents, in an event and among the byes, or in one event listed twice. The two legs of a tie are one appearance | `ParticipantTwiceInRound` |
| Two results for one event | `DuplicateResult` |
| Rounds, and a withdrawal given as a plain participant: it does not say after which round it happened | `WithdrawalWithoutRound` |
| Rounds, and an adjustment without `madeAfterRound()`: it does not say after which round it was made | `AdjustmentWithoutRound` |
| Anything `withRoundPlayed()` refuses | [The reason it gives](#a-stored-stage-state-is-checked-as-it-was-recorded) |

With no round supplied, a withdrawal takes the entrant out of the draw, as
`withoutParticipant()` does before the first round is recorded, and an
adjustment is made after round 0.

`StageState::fromArray()` and `fromJson()` are still the readers of what
`toArray()` and `toJson()` wrote, for an application that stores the state.
Both ways go through the same verbs. A runnable example with fixture rows,
a round drawn by hand with its bye, an unplayed tie and a withdrawal with
its round:
[`examples/27-stage-from-application-rows.php`](../examples/27-stage-from-application-rows.php).

### Rounds That Are Not Finished

A round with some of its results, or none, is the normal condition of a
stage that is being played. Reading such a stage throws nothing:

| Call | A recorded round that awaits a result |
| --- | --- |
| `isComplete()` | `false` |
| `getOutcome()` | `null` |
| `getStandings()` | The table as recorded so far |
| `getRecordedRounds()` | Every recorded round, each with the events it still awaits |
| `getReadiness()` | Not ready, with the events that await a result |
| `pairNextRound()` | Refused: an `UnresolvedRoundException` with those events |

The last three rows are the same fact. The next round is paired from the
results of the rounds before it, so an engine refuses to pair it while an
**unresolved event** remains, and `getReadiness()` is how an application
learns that without catching anything:

```php
use MissionGaming\Tactician\Exceptions\UnresolvedRoundException;

$readiness = $bracket->getReadiness($history);
echo $readiness->isReady() ? 'ready' : 'waiting', "\n"; // waiting
foreach ($readiness->getUnresolvedEvents() as $event) {
    [$first, $second] = $event->getParticipants();
    echo $first->getLabel() . ' v ' . $second->getLabel() . "\n"; // Bo v Cy
}

// The table is there at any point: Ada has won, and leads it
echo $bracket->getStandings($history)->getEntries()[0]->getParticipant()->getLabel(), "\n"; // Ada

try {
    $bracket->pairNextRound($history);
} catch (UnresolvedRoundException $exception) {
    echo $exception->getReason()?->value, ', round ', $exception->getRoundNumber(), "\n"; // round_partially_resolved, round 1
}
```

`getReadiness()`, `getRecordedRounds()` and `getStandings()` are the
methods of `HistoryReadingEngine`, which the three engines implement. A
`PairingReadiness` says one of three things: the stage is ready
(`isReady()`, and `getRoundNumber()` is the number the next round will
carry), it awaits results (`isAwaitingResults()`, with
`getUnresolvedEvents()` and the round they are in), or it is complete
(`isComplete()`). Ready means that nothing recorded stands in the way of
the next round. A bracket engine then pairs it; the Swiss engine can still
find that no pairing is left that nobody has played
(`NoValidPairingException`), which only its search tells. An `UnresolvedRoundException` is an
`InvalidConfigurationException` with the reason `RoundPartiallyResolved`;
it carries the same events (`getUnresolvedEvents()`) and the round
(`getRoundNumber()`). Record a round when you pair it, with the results you
have, and add the others with `withAdditionalResults()`; a recorded round is
read from the state (`getLastRound()`) and is not paired a second time.

A bracket tie that has every result and finished level, with no
[decider](#recording-a-level-event) on any of them, awaits a fact too: the
application's decision. It is read like a round that awaits a result, and
listed apart from one:

| Call | A recorded tie that is level and has no decider |
| --- | --- |
| `isComplete()` | `false` |
| `getOutcome()` | `null` |
| `getStandings()` | The table as recorded so far, in which the level event is a draw |
| `getReadiness()` | Not ready: `getUndecidedEvents()` lists the events of the tie (both legs of a two-legged one), and `getUnresolvedEvents()` the events that have no result |
| `getRecordedRounds()` | The round lists the same events (`RecordedRound::getUndecidedEvents()`), and `isResolved()` is false |
| `pairNextRound()` | Refused: an `InvalidConfigurationException` with the reason `UndecidedTie`, the round and the two participants. It is thrown before the `UnresolvedRoundException` where the round awaits both |

Record the decision by replacing the result with the same result and its
decider (`withResultReplaced()`). An entrant of such a tie who withdraws
gives it away, like any tie the results do not decide.

The methods still throw for a state that cannot be read at all: one stamped
with another engine's fingerprint, one with too few participants and, in a
bracket, an event with two results, a result that names a winner outside
its tie, or a decider the engine refuses (`InvalidResult`).

A Swiss stage waits for its results too, because a round is paired from the
table. An application that means to pair a round before the last results
are in says so in so many words, and the round it gets is marked:

```php
use MissionGaming\Tactician\Scheduling\SwissPairingEngine;

$swiss = new SwissPairingEngine(plannedRounds: 3);
$swissState = StageState::start([$ada, $bo, $cy, $di]);
$firstRound = $swiss->pairNextRound($swissState);

// One of the two results of round 1 is in
$swissState = $swissState->withRoundPlayed($firstRound, [new Result($firstRound->getEvents()[0], $ada)]);
var_dump($swiss->getReadiness($swissState)->isReady()); // bool(false)

$early = $swiss->pairNextRoundOnIncompleteResults($swissState);
var_dump($early->isPairedOnIncompleteResults()); // bool(true)
```

The round is paired from the table as it stands, in which the missing
result counts for nobody. The mark is recorded and stored with the round
(`paired_on_incomplete_results` in its plain data, written only when it is
set), so that the round can later be told from one paired on full results;
pass it to the `RoundPairing` you build when your rows hold the rounds.
When nothing was missing, the method is `pairNextRound()` and marks
nothing. An event that is never played is not left without a result: give
it an [awarded result](#awarded-results), which is a result. The result
that was missing still has to arrive: until it does the stage is not
complete, and `pairNextRound()` goes on refusing. `withAdditionalResults()`
adds to the last recorded round only, so once the early round is recorded a
result of the round before it is supplied with the history
(`fromHistory()` takes the results of any round), or the state is rebuilt
from its rounds with that result in its place. A bracket
has no such method: its next round holds who advanced, and that is not
known before the ties are decided.

**Pairing without results at all** is another thing, and a property of the
stage, not of one call. `new SwissPairingEngine(resultsFree: true)` is the
engine in **results-free mode**: rounds are recorded without results on
purpose, no event awaits one, and the stage is complete when its planned
rounds are recorded. It is the mode the whole-schedule
[`SwissScheduler`](#swiss-tournaments) preset pairs in. The mode is part of
the [engine fingerprint](#recording-which-engine-pairs-a-state) when it is
on, so a stage that has no results yet is never mistaken for one that is to
have none:

```php
$preDrawn = new SwissPairingEngine(plannedRounds: 2, resultsFree: true);
$drawState = StageState::start([$ada, $bo, $cy, $di]);
while (!$preDrawn->isComplete($drawState)) {
    $drawState = $drawState->withRoundPlayed($preDrawn->pairNextRound($drawState), []);
}

echo count($drawState->getRoundsPlayed()), "\n"; // 2
var_dump($preDrawn->getFingerprint() === $swiss->getFingerprint()); // bool(false)
```

### Rounds the engine would have paired otherwise

A recorded round that is not the round the engine would have paired, drawn
by hand or paired under other rules, is a **manual round**. It is read as
recorded and it is never refused for that. `getRecordedRounds()` reports it,
with the engine's own round beside it, so that an application can show the
difference and keep a record of it:

```php
// The engine pairs four entrants as Ada v Di and Bo v Cy. These semifinals
// were drawn by hand: Ada v Cy and Bo v Di.
$adaVsCy = new Event([$ada, $cy], $semifinal);
$boVsDi = new Event([$bo, $di], $semifinal);
$byHand = StageState::fromHistory(
    entrants: [$ada, $bo, $cy, $di],
    rounds: [new RoundPairing(1, 'semifinal', [$adaVsCy, $boVsDi])],
    results: [new Result($adaVsCy, $cy), new Result($boVsDi, $bo)],
);

$recorded = $bracket->getRecordedRounds($byHand)[0];
var_dump($recorded->isManual()); // bool(true)
foreach ($recorded->getDerivation()?->getEvents() ?? [] as $event) {
    [$first, $second] = $event->getParticipants();
    echo $first->getLabel() . ' v ' . $second->getLabel() . "\n"; // Ada v Di, then Bo v Cy
}

// The final is between the two who won the ties that were played
[$first, $second] = $bracket->pairNextRound($byHand)->getEvents()[0]->getParticipants();
echo $first->getLabel() . ' v ' . $second->getLabel() . "\n"; // Cy v Bo
```

A `RecordedRound` holds the round as recorded (`getPairing()`), the round
the engine would have paired from the rounds before it
(`getDerivation()`), whether the two differ (`isManual()`), the events that
still await a result (`getUnresolvedEvents()`, `isResolved()`), the events
that await none because an entrant withdrew (`getUncontestedEvents()`), and
whether the round was paired on incomplete results. Two rounds differ when
some two participants meet in one and not in the other, or another
participant sits the round out; roles, the order of the events, labels and
metadata are not compared.

Every later round follows from the round as recorded:

| Engine | After a manual round |
| --- | --- |
| `SwissPairingEngine` | The next round is paired from the table of the results and avoids the pairs that were played. An engine that holds a `Randomizer` has no derivation to show (`getDerivation()` is null, `isManual()` false): what it drew at the time is not part of the state |
| `SingleEliminationEngine` | The entrants who advanced from the recorded ties are the next round. On a fixed path a tie stands where the earlier of its two entrants stood in the bracket; a re-seeded bracket folds them by the table as it always does. A last round recorded without its [third-place tie](#a-third-place-tie) decides places 1 and 2 only |
| `DoubleEliminationEngine` | The same, stage by stage. The later stages take their entrants by place (which dropper meets which survivor), so a recorded stage must leave as many places as the stage has: one that leaves more or fewer entrants in the bracket is refused (`RecordedRoundMismatch`) |

An event of a bracket round between two entrants neither of whom is in the
round, or with an entrant who is in another tie of that round already, is
no tie of the bracket: it is read by nothing, as an event the application
added always was. (`fromHistory()` refuses the second kind before an engine
sees it.)

The derivation is there to be shown, and nothing is paired from it. It is
the round the engine pairs from what was known when the recorded round was
paired: the rounds before it and their results, the adjustments made before
it, and the entrants who had not withdrawn by then. A round the engine
paired itself is therefore not reported as manual, whatever was recorded
after it, unless a later round names somebody who had left before this one
(see
[When a Withdrawal or an Adjustment Happened](#when-a-withdrawal-or-an-adjustment-happened)).
What `isManual()` promises, and what it does not:

- **It compares pairings and nothing else.** Two rounds differ when some
  two participants meet in one and not in the other, or another participant
  sits the round out. A round an organiser drew that happens to be the
  engine's round is not manual; keep a mark of your own where the fact that
  somebody drew it matters.
- **It needs the times.** The verbs record after which round each
  adjustment and each withdrawal happened; a history states them. An
  adjustment with no round leaves the table of the time unknown, and the
  engine then gives no derivation and reports no round as manual
  (`getDerivation()` is null): every round of a Swiss stage, and every
  round after the first of a re-seeded bracket. A withdrawal with no round,
  which only data stored by 0.2.x holds, is read as described under
  [When a Withdrawal or an Adjustment Happened](#when-a-withdrawal-or-an-adjustment-happened),
  and a tie that is missing from the round because one of its entrants has
  withdrawn, and neither that round nor a later one names it, is then not
  counted as a difference.
- **It needs the engine that paired.** The derivation is this engine's,
  with its options and its standings rules. A round paired by an engine
  with other options, or by an earlier release with another pairing order,
  is manual to this one.
- **It has nothing to say where the engine draws.** A Swiss engine that
  holds a `Randomizer` has no derivation, and neither has an engine whose
  search finds no pairing for the round.
- **A round is read as recorded either way.** The flag changes nothing the
  engine does next.

### Withdrawals from a Bracket

An entrant who withdraws from a bracket that is under way keeps its slot:
`withoutParticipant()` on a state with a recorded round, or the
`withdrawals` of a history. A tie it is in and has no complete result for
becomes an **uncontested tie**. It is not played, the other entrant
advances, and nothing is drawn again:

```php
use MissionGaming\Tactician\Stage\Withdrawal;

// Ada has won her semifinal; Cy withdraws after round 1 is recorded, before his tie is played
$afterWithdrawal = StageState::fromHistory(
    entrants: [$ada, $bo, $cy, $di],
    rounds: [new RoundPairing(1, 'semifinal', [$adaVsDi, $boVsCy])],
    results: [new Result($adaVsDi, $ada)],
    withdrawals: [new Withdrawal($cy, afterRound: 1)],
);

$semifinals = $bracket->getRecordedRounds($afterWithdrawal)[0];
echo count($semifinals->getUncontestedEvents()), ' not played, ',
    count($semifinals->getUnresolvedEvents()), " awaited\n"; // 1 not played, 0 awaited

[$first, $second] = $bracket->pairNextRound($afterWithdrawal)->getEvents()[0]->getParticipants();
echo $first->getLabel() . ' v ' . $second->getLabel() . "\n"; // Ada v Bo
```

- **A tie that is already paired** keeps its events in the recorded round.
  They await no result (`getUncontestedEvents()` lists them) and the round
  is finished without them.
- **A tie that is not paired yet** gets no event. The entrant who advances
  is among the byes of the round the engine pairs, and so is the entrant
  who takes the third place from a third-place tie the other has left; a
  round in which no tie is left to play takes no round number.
- **Results stand.** A tie with a complete result is decided by it,
  whoever withdraws afterwards: an entrant who won and then withdrew went
  on, and it is its next tie that is not played.
- **Both entrants of a tie: a void tie.** If both withdraw, nobody is
  there to go through. A tie with no complete result both of whose
  entrants have withdrawn is **void**: it sends nobody on, the slot it
  would have filled is empty, so the entrant who would have met its winner
  goes on alone (it is among the byes of the round the engine pairs), and
  where the two ties that feed a tie are both void, that tie has nobody in
  it. An entrant who stands alone and had withdrawn when its round was
  paired has nobody to go through against and is not there to go
  through: it goes out in that round, and a final it stands alone in has
  no winner. The two entrants of a void tie go out in it and share the tier of
  its round. An entrant who was given a tie because the other had left
  went through it only if it was still in the stage when its next tie was
  paired (a round that names it was paired while it was in): leaving
  before that, it left nobody to go through. Where the other had already
  left when the round of the tie was paired, the round lists the entrant
  among its byes and it went through then. See [ADR 0011](adr/0011-a-tie-both-entrants-left-is-void.md).
- **The last tie, and the end of the bracket.** A final, a third-place
  tie, a grand final or its reset has no tie after it. It is decided the
  moment its results decide it, or one of its entrants withdraws while
  the other is still in the stage: the other has it then. The bracket is
  **over** once its last ties are decided, and a withdrawal after that
  takes nothing back: the winner of a finished bracket stays its winner,
  and every placing stays as it was. A withdrawal from whatever comes next
  belongs to the state of that stage. A final decided by a withdrawal
  stays decided while the third-place tie is still played, and so does a
  third-place tie decided while the final is. So the order of two
  withdrawals after the same round is part of the history: a state keeps
  it, and a history gives the withdrawals of one round in that order
  ([ADR 0013](adr/0013-a-finished-bracket-keeps-its-winner.md)).
- **Double elimination.** The withdrawn entrant takes the loss: from the
  winners bracket it drops to the losers bracket, where its next tie is
  uncontested too. A void winners tie drops nobody: the losers bracket
  slot it would have filled is empty, and its two entrants go out together
  with those the next losers round puts out.
- **The table.** A withdrawn entrant is placed by the round it went out
  in, as every entrant is, and keeps its place in the entrant list where
  records are level.
- **Before the first round is recorded** nothing has been drawn as far as
  the state knows, so the entrant is out of the draw and the first round is
  folded from those who remain. The withdrawal is kept, after round 0, and
  the first round says the rest when it is recorded: an entrant it names
  was in the draw (it withdrew while that round was played) and keeps its
  place, and one it does not name withdrew before the draw and is no
  entrant.

A Swiss stage has no ties to give away. A participant who withdraws is not
paired again and keeps the results it has; an event of a recorded round it
did not play still awaits a result, and an
[awarded result](#awarded-results) is how the application says what
becomes of it.

**Who won a bracket a finalist withdrew from.** The outcome says who went
on from its last tie (`StageOutcome::getSettledTies()`, one `SettledTie`
for the final), and `MatchOutcomeSelector` reads that, so a composition
that takes the bracket winner by selector gets the right entrant whether
the final was played, recorded and not played, or never paired:

```php
use MissionGaming\Tactician\Stage\MatchOutcomeSelector;

// Ada and Bo won the semifinals; Bo withdraws before the final is paired
$finalistLeft = StageState::fromHistory(
    entrants: [$ada, $bo, $cy, $di],
    rounds: [new RoundPairing(1, 'semifinal', [$adaVsDi, $boVsCy])],
    results: [new Result($adaVsDi, $ada), new Result($boVsCy, $bo)],
    withdrawals: [new Withdrawal($bo, afterRound: 1)],
);

$decided = $bracket->getOutcome($finalistLeft);
assert($decided !== null); // no final is left to play: the bracket is complete

echo MatchOutcomeSelector::winners()->select($decided)[0]->getLabel(), "\n"; // Ada
echo MatchOutcomeSelector::losers()->select($decided)[0]->getLabel(), "\n"; // Bo
echo $decided->getStandings()->getEntries()[0]->getParticipant()->getLabel(), "\n"; // Ada

// The last round on record is the semifinals: the final has no round
echo $decided->getFinalRound()?->getLabel(), "\n"; // semifinal
```

Bo's withdrawal ended the bracket, so Ada's after it changes nothing: the
winner of a finished bracket stays its winner.

```php
// Bo withdraws after the semifinals, and Ada, who had the final given to her, after him
$bothLeft = StageState::fromHistory(
    entrants: [$ada, $bo, $cy, $di],
    rounds: [new RoundPairing(1, 'semifinal', [$adaVsDi, $boVsCy])],
    results: [new Result($adaVsDi, $ada), new Result($boVsCy, $bo)],
    withdrawals: [new Withdrawal($bo, afterRound: 1), new Withdrawal($ada, afterRound: 1)],
);

$stillDecided = $bracket->getOutcome($bothLeft);
assert($stillDecided !== null);

echo implode(', ', array_map(
    static fn (Participant $finalist): string => $finalist->getLabel(),
    MatchOutcomeSelector::winners()->select($stillDecided)
)), "\n"; // Ada
echo implode(' ', $stillDecided->getStandings()->getTiers()), "\n"; // 0 1 2 2
```

Only a state stored by 0.2.x, whose withdrawals carry no time, can hold a
final both finalists left at times nobody knows: that final is void, the
selector names nobody as its winner, and the two finalists share the second
tier.

An outcome that carries no settled ties (a Swiss stage, a pooled
outcome, one built by hand without them) is read from the results of its
final round, as before.

### When a Withdrawal or an Adjustment Happened

A round is paired from what is known at that moment, and it is read the
same way afterwards: from the adjustments made before it was paired, and
from who was in the stage then. So a state records, for each adjustment and
each withdrawal, **after which round** it happened: the number of the last
round that was recorded at that moment (0 before the first round). "After
round 2" covers the whole time from the recording of round 2 to the
recording of round 3, whether round 2 was still being played or was
finished.

**The state's clock is the rounds it holds, so record a round when it is
paired.** To a state, a round is paired at the moment it is recorded. The
driver loop records it then, with `withRoundPaired()`, and adds its
results with `withAdditionalResults()` as they come: whatever happens
while the round is played is then recorded after it, which is when it
happened. An application that records a round only once it has been played
(pair, play, record) records whatever happens while the round is played
*before* the round:

- **A withdrawal of an entrant the round names is still read correctly.**
  A round that names a participant, in an event or as a bye, was paired
  while that participant was in the stage, and the engines read that
  before the round the withdrawal states: the entrant is in the round,
  and gives its tie away there. That holds for the first round too: a
  withdrawal before any round is recorded is kept after round 0, and the
  first round, recorded afterwards, gives the entrant its place back
  where it names it. A round that does not name an entrant says nothing
  of it, and that shows in one place: in double elimination, a losers
  round with no tie to play is not recorded, and is read from who was in
  the stage when the next recorded round, often a winners round, was
  paired. An entrant who went on alone from such a losers round and
  withdraws while that winners round is played is placed one stage lower
  when the withdrawal is recorded before the winners round than when the
  round is recorded first. Who goes on, every round and the winner are the
  same either way, and a round recorded when it is paired has no such
  limit.
- **An adjustment is not.** Nothing in a round says which adjustments were
  known when it was paired. One made while round 3 is played and recorded
  before round 3 is read as known when round 3 was paired: a re-seeded
  bracket then ranks round 3 with it, and the round an engine shows beside
  round 3 (`getDerivation()`, and with it `isManual()`) is paired with it,
  in a Swiss stage too. Recorded when it is paired, round 3 comes first,
  and none of that happens.
- **A round is not to name somebody who had left before it was paired.**
  The rule above reads every such name as a fact. A list of byes that takes
  in everyone without an event, a withdrawn entrant among them, says that
  the entrant was in the stage for that round and for every round before
  it, whatever round its withdrawal states: the rounds between are then
  compared with rounds that pair the entrant and can be reported as
  manual, and a bracket is read with the entrant still in it, which
  changes the rounds that follow and which a double elimination engine can
  refuse.

The verbs need no argument for it, because the state knows its rounds:

```php
use MissionGaming\Tactician\Standings\Adjustment;

$semifinals = new RoundPairing(1, 'semifinal', [$adaVsDi, $boVsCy]);

$live = StageState::start([$ada, $bo, $cy, $di])
    ->withAdjustment(new Adjustment('di', 1.0))            // before the first round
    ->withRoundPaired($semifinals)                         // the semifinals are drawn
    ->withAdditionalResults([new Result($adaVsDi, $ada)])
    ->withoutParticipant($cy)                              // after round 1
    ->withAdjustment(new Adjustment('bo', -1.0));          // after round 1

foreach ($live->getAdjustments() as $adjustment) {
    echo $adjustment->getParticipantId(), ' after round ', $adjustment->getAfterRound(), "\n"; // di after round 0, then bo after round 1
}
foreach ($live->getWithdrawals() as $withdrawal) {
    echo $withdrawal->getParticipant()->getLabel(), ' after round ', $withdrawal->getAfterRound(), "\n"; // Cy after round 1
}

// Rows say the same, and give the same state
$fromRows = StageState::fromHistory(
    entrants: [$ada, $bo, $cy, $di],
    rounds: [$semifinals],
    results: [new Result($adaVsDi, $ada)],
    withdrawals: [new Withdrawal($cy, afterRound: 1)],
    adjustments: [
        (new Adjustment('di', 1.0))->madeAfterRound(0),
        (new Adjustment('bo', -1.0))->madeAfterRound(1),
    ],
);
var_dump($fromRows->toArray() === $live->toArray()); // bool(true)
```

| | The verbs | A history |
| --- | --- | --- |
| An adjustment | `withAdjustment()` records it as made after the last recorded round, 0 before the first. One that states a round keeps it, so an adjustment made earlier can be recorded late; a round after the last recorded one is refused | `Adjustment::madeAfterRound()` on each, required once the history holds a round (`AdjustmentWithoutRound` otherwise). A round after the last one supplied is refused (`ValueOutOfRange`) |
| A withdrawal | `withoutParticipant()` records it as a `Withdrawal` after the last recorded round, 0 before the first, in the order the withdrawals happen (`getWithdrawals()`) | A `Withdrawal` for each, required once the history holds a round (`WithdrawalWithoutRound` otherwise). They are put in the order of their rounds, and those of one round stay in the order you give them: give them in the order they happened, which decides who had a last tie of a bracket (see [Withdrawals from a Bracket](#withdrawals-from-a-bracket)) |

What the engines read from it:

- **A re-seeded bracket** (`reseedEachRound`) ranks the survivors of a
  recorded round from the adjustments made before that round, so an
  adjustment recorded later changes neither the round nor anything read
  from it. The round paired next has every adjustment.
- **The derivation** beside a recorded round, and with it `isManual()`,
  is the round the engine pairs from the same knowledge, in every engine.
- **A bracket** reads a tie from who was in it. An entrant who withdrew
  before a round was paired is not in it, and the entrant it would have met
  goes on; one who withdrew afterwards was, and gives the tie away. Where
  both entrants of a tie have withdrawn, the one still in the stage when
  its next tie was paired went on, and where neither was the tie is void.
  A last tie goes to the entrant still in the stage when the other left,
  and the bracket is read as it stood when it was over.
- **The tables**, and the outcome, have every adjustment, whatever its
  round.

**Every withdrawal and every adjustment states its round once there are
rounds.** Undated withdrawals were read otherwise than they were played as
soon as two of them met in one tie, and a round is read from the
adjustments made before it was paired, which an adjustment without its
round does not say. So a history that holds a round and gives a withdrawal
as a plain participant is refused (`WithdrawalWithoutRound`), and so is one
that gives an adjustment without `madeAfterRound()`
(`AdjustmentWithoutRound`); a stored state that holds a round is refused
for either too. With no round there is only one time it can have been: a
plain participant is a withdrawal after round 0, and an adjustment without
its round is made after round 0. No release wrote an adjustment without its
round: 0.2.x stored no adjustments. Data stored by 0.2.x keeps loading, and
its withdrawn participants have no place and no time. The library does not
then pretend to know when they left; the engines read what the recorded
rounds themselves show:

| A withdrawal without its round (0.2.x data) | What is read |
| --- | --- |
| A round that names the participant | The round was paired before it left, and so was every round before it. This is read with a round too: a round that names the entrant goes before the round its withdrawal states |
| Two withdrawals from one tie that has no complete result | The one a later recorded round names went on; where a later round names neither, or both, the tie is void, the last tie of a bracket included |
| A round that holds no tie and no bye for the participant, where no later round names it either | It had left before the round. A round supplied without its byes is misread here: an entrant who had a bye and withdrew before the next round looks the same. With the rounds stated, as every history now states them, a round supplied without its byes is read correctly |

## Pools, Progression, and Multi-Stage Tournaments

A **pool** is just a bucket of participants: what format the bucket plays,
how it is scored, and how it progresses are separate concerns. The retired
group-stage monolith is now a composition of generic primitives
(`playPool()` below stands for your application playing a pool's schedule
and returning one `Result` per event):

<!-- snippet: setup
/** @return list<\MissionGaming\Tactician\DTO\Result> */
function playPool(\MissionGaming\Tactician\DTO\Schedule $schedule): array
{
    // The participant whose label is first in the alphabet wins, so that
    // results decide every place of a pool
    return array_map(
        static function (\MissionGaming\Tactician\DTO\Event $event): \MissionGaming\Tactician\DTO\Result {
            [$first, $second] = $event->getParticipants();

            return new \MissionGaming\Tactician\DTO\Result($event, strcmp($first->getLabel(), $second->getLabel()) < 0 ? $first : $second);
        },
        $schedule->getEvents()
    );
}

/** @return list<\MissionGaming\Tactician\DTO\Result> */
function playPoolByMargin(\MissionGaming\Tactician\DTO\Schedule $schedule, int $margin): array
{
    // The same winners, each by the given score to nil
    return array_map(
        static function (\MissionGaming\Tactician\DTO\Event $event) use ($margin): \MissionGaming\Tactician\DTO\Result {
            [$first, $second] = $event->getParticipants();
            $winner = strcmp($first->getLabel(), $second->getLabel()) < 0 ? $first : $second;
            $loser = $winner === $first ? $second : $first;

            return new \MissionGaming\Tactician\DTO\Result($event, $winner, [$winner->getId() => $margin, $loser->getId() => 0]);
        },
        $schedule->getEvents()
    );
}
-->

```php
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Scheduling\SingleEliminationEngine;
use MissionGaming\Tactician\Stage\PoolDistributor;
use MissionGaming\Tactician\Stage\RankRangeSelector;
use MissionGaming\Tactician\Stage\StageOutcome;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Standings\StandingsCalculator;

// Eight entrants in seeding order
$participants = array_map(
    fn (string $name) => new Participant(strtolower($name), $name),
    ['Alice', 'Bob', 'Carol', 'Dave', 'Erin', 'Frank', 'Grace', 'Heidi']
);

// Serpentine pools by list position: with two pools, positions 1, 4, 5, 8
// land in pool A and 2, 3, 6, 7 in B
$pools = PoolDistributor::serpentine($participants, pools: 2);

// Each pool plays ANY per-stage format - round robin here
$calculator = new StandingsCalculator();
$scheduler = new RoundRobinScheduler();
$poolOutcomes = [];
foreach ($pools as $label => $poolParticipants) {
    $schedule = $scheduler->schedule($poolParticipants);
    $results = playPool($schedule); // application-side

    // Progressing from a partial table promotes the wrong participants:
    // check every pairing has a result before qualifying
    assert($scheduler->getPlan($poolParticipants)->findUnplayedPairings($results) === []);

    // The pool's entrant list goes with its table: where results leave
    // two entries level, their position in it decides
    $poolOutcomes[$label] = new StageOutcome(
        $calculator->calculate($poolParticipants, $results),
        $results,
        entrants: $poolParticipants
    );
}

// One combined outcome, optionally carrying the pool structure
$combined = StageOutcome::combining($poolOutcomes, $calculator, $participants);

// The hand-off: top 2 per pool, pool winners first, in the order that
// keeps a pool's two qualifiers apart in a bracket (A1 vs B2, B1 vs A2)
$qualifiers = RankRangeSelector::topPerGroup(2)->select($combined);

$knockout = new SingleEliminationEngine();
$knockoutState = StageState::start($qualifiers); // position 1 = seed 1
```

**Progression selectors** are the hand-off between stages: they consume a
`StageOutcome` and produce the ordered entrant list of the next stage.
Order is authoritative: every stage takes its entrants from list position,
so library selectors and consumer-derived lists behave identically.
Two families cover the two legitimate substrates (and mixing them within
one decision invites contradictory qualification — pick one ranking
authority per decision):

```php
use MissionGaming\Tactician\Stage\BestOfRankSelector;
use MissionGaming\Tactician\Stage\MatchOutcomeSelector;

// Standings-based (rank slices):
RankRangeSelector::topPerGroup(2);            // the classic qualifiers
RankRangeSelector::perGroup(from: 3, to: 4);  // a losers' route
RankRangeSelector::overall(from: 1, to: 8);   // best 8 across all pools
BestOfRankSelector::ofRank(rank: 3, count: 2); // the 2 best of those placed third

// Outcome-based (recorded match results - never points arithmetic):
MatchOutcomeSelector::winners();              // knockout round -> next round
MatchOutcomeSelector::losers();               // knockout round -> repechage
```

The three selector classes are plain-data constructible
(`fromArray()`/`toArray()`) with stable identifiers; the data does not
say which class it belongs to, so store that beside it. Selectors are
optional machinery, not a gate: a consumer computing its own qualification
hands the next stage an ordered list directly, with no penalty.

**A per-group selection is ordered for a bracket.** It lists every pool's
first selected rank, then every pool's second, and so on. The first block
is in pool order. Each later block is in the order that keeps the entrants
of one pool apart when the whole list is folded into a bracket, as the
elimination engines fold it: as few pairs of one pool as possible that can
meet in the first round, then in the second, and so on. For the top two of
every pool, a pool's winner and runner-up are always in opposite halves and
can meet only in the final:

| Pools | `topPerGroup(2)` returns | First round of the bracket |
|-------|--------------------------|----------------------------|
| 2 | A1, B1, A2, B2 | A1 v B2, B1 v A2 |
| 3 | A1, B1, C1, C2, B2, A2 | C2 v B2, C1 v A2 (A1 and B1 have byes) |
| 4 | A1, B1, C1, D1, B2, A2, D2, C2 | A1 v C2, D1 v B2, B1 v D2, C1 v A2 |

The order depends on the number of pools and of ranks alone. It is the
order for a bracket of exactly these entrants; put anywhere else (dealt
into pools again, or into a bracket with others) it has no special meaning.

### Selections that results do not decide

A rank selector reads positions, and a standings table gives every entry a
position even where no result separates two entries
([tied sets](#tied-sets)). Acting on such a position would let something
other than a result decide who goes through. So a `RankRangeSelector`
**refuses** a selection that results do not decide, with an
`UndecidedSelectionException` (an `InvalidConfigurationException`) that
names the level participants, the places they hold between them and the
pool:

| The table | Reason (`getReason()`) |
|-----------|------------------------|
| has no recorded result at all | `UnplayedStage` |
| has level entries on both sides of a boundary of the selected ranks: only some of them would be selected | `TieAcrossSelection` |
| has level entries that are all inside the selected ranks: who is selected is decided, the order they are listed in is not | `TieWithinSelection` |

A tie that touches none of the selected ranks is not refused.

```php
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Exceptions\UndecidedSelectionException;

// A pool of four, entered in this order, in which every event is drawn
$drawnPool = [
    new Participant('zed', 'Zed'),
    new Participant('yan', 'Yan'),
    new Participant('bob', 'Bob'),
    new Participant('amy', 'Amy'),
];
$draws = array_map(
    fn (Event $event) => new Result($event),
    $scheduler->schedule($drawnPool)->getEvents()
);
$undecided = StageOutcome::combining([
    'A' => new StageOutcome($calculator->calculate($drawnPool, $draws), $draws, entrants: $drawnPool),
]);

try {
    RankRangeSelector::topPerGroup(2)->select($undecided);
} catch (UndecidedSelectionException $e) {
    echo "{$e->getReason()?->value}: pool {$e->getPool()}, places {$e->getFirstPlace()} to {$e->getLastPlace()}: "
        . implode(', ', $e->getParticipantIds()) . "\n";
}
```

```text
tie_across_selection: pool A, places 1 to 4: zed, yan, bob, amy
```

What happens next is the application's rule: a play-off, a drawing of lots,
a tiebreaker added to the calculator. Where the rule gives an order that no
result gives, the selector takes it as a
[decision](#settling-a-tied-set-by-a-decision): the level participants,
best first, in a `Decider`.

```php
use MissionGaming\Tactician\DTO\Decider;

// The four are level; the application has put them in this order
$decision = new Decider(['bob', 'zed', 'amy', 'yan'], 'drawn', 'Drawn after the last event');
$selection = RankRangeSelector::topPerGroup(2)->withDecisions([$decision])->selection($undecided);

foreach ($selection->getParticipants() as $qualifier) {
    $settledBy = $selection->getDecisionsFor($qualifier);
    echo $qualifier->getLabel() . ($settledBy === [] ? '' : " ({$settledBy[0]->getLabel()})") . "\n";
}
```

```text
Bob (drawn)
Zed (drawn)
```

`select()` returns the same list, and `selection()` returns it with its
marks (a `RankSelection`): `getParticipants()` is the list,
`isSettledByDecision()` says whether a decision put a selected participant
at its place, `getDecisionsFor()` hands back the decision with its label
and reason, and `getDecisions()` lists every decision the selection rests
on. A participant that results placed has no mark. The rules are those of
a table:

- A decision orders one whole set of level entries, in the table it is for:
  a pool's table in a per-group selection (the pool its participants are
  in), the outcome's own table in an overall one. One that does not is
  rejected with a `RejectedDecisionException` that names the pool
  (`getPool()`), and nothing is selected.
- It settles the order also where both level entrants are selected. With
  the first two of a pool level and both going through, the order still
  decides which slot of the next stage each of them takes, so the selection
  stays refused (`TieWithinSelection`) until a decision is supplied, or
  until the selector is told to go on without one.
- A decision for a tie that touches no selected rank is applied and changes
  nothing in the list. A table with no result is refused whatever is
  decided: a decision settles a tie, not a stage that was not played.
- A table the outcome was built with may carry decisions already
  (`Standings::withDecisions()`); the selector reads them as if they were
  given to it. The combined table of `StageOutcome::combining()` carries
  none: it is calculated afresh, and a decision of a pool's table stays
  with that table, where a per-group selection reads it. A tie in the
  combined table is settled by a decision of its own, given to the
  selector.
- In plain data the decisions are the key `decisions`, a list of deciders
  in their array form, written only when there are any. An application
  that keeps its selectors as configuration and its decisions as records
  gives them to the selector when it selects, as above.

Going on without a decision is a choice too, and it is made on the selector:

```php
// Accept level entries when all of them are selected: who goes through is
// still decided by results, and only their order is taken from the table
$firstFour = RankRangeSelector::topPerGroup(4)->withTiesInsideSelectionAccepted()->select($undecided);

// Accept the table as it stands, unplayed pools included: where results
// decide nothing, the position in the entrant list does
$firstTwo = RankRangeSelector::topPerGroup(2)->withTableOrderAccepted()->select($undecided);

echo implode(', ', array_map(fn (Participant $p) => $p->getLabel(), $firstTwo)) . "\n"; // Zed, Yan
```

In an accepted selection level entries are in the order of the outcome's
entrant list, which is why the pools above were given theirs. An outcome
built without one keeps the table it was given, which for a table from
`StandingsCalculator` is its own fallback order (seed, then ID). In plain
data the choice is the key `ties` (`'refuse'`, the default,
`'accept-inside-selection'` or `'accept-table-order'`); `toArray()` writes
it only when it is not the default.

The selector sees the table and nothing else. It recognises a pool with no
result at all; it cannot know that a pool with some results still has
events to play. Check that before you select, as the loop above does with
`findUnplayedPairings()`, or with `isComplete()` for a stage an engine
drives.

**Ahead-of-time composition validation** checks that a declared
multi-stage structure telescopes before any fixture exists:

```php
use MissionGaming\Tactician\Stage\CompositionValidator;
use MissionGaming\Tactician\Stage\MatchOutcomeSelector;
use MissionGaming\Tactician\Stage\StageTransition;

$violations = (new CompositionValidator())->validateChain(16, [
    new StageTransition('quarterfinals', 8, MatchOutcomeSelector::winners()),
    new StageTransition('semifinals', 4, MatchOutcomeSelector::winners()),
    new StageTransition('final', 2, MatchOutcomeSelector::winners()),
]);
// [] - the chain telescopes: 16 -> 8 -> 4 -> 2
```

The validator counts; it runs nothing. It reads a `MatchOutcomeSelector`
as the hand-off after one knockout round of the previous stage's entrants:
winners are half of them, rounded up for a bye, and losers half, rounded
down. That is the arithmetic of a chain whose stages are single knockout
rounds, as declared above. It is not what the selector returns from the
outcome of a whole bracket played by one of the elimination engines, which
is the one winner (or loser) of the final. The validator cannot see which
of the two a stage is, so say it on the transition that leaves a whole
bracket: `new StageTransition('next', 8, MatchOutcomeSelector::winners(),
afterWholeBracket: true)` is counted as a selection of 1. A stage needs 2
entrants, so that transition is always reported, which is the point: the
winner of a bracket alone cannot fill the next stage.

Consumer-derived selections participate by declaring expected entrant
counts (a transition without a selector); concurrent routes (winners
forward, losers to a repechage) validate as separate chains from the same
source.

### The best of a rank across pools

Some formats take the top of every pool and then the best few of those
placed next: the top two of three pools and the two best of the entrants
placed third. `RankRangeSelector::perGroup(3, 3)` returns the three
entrants placed third in pool order; `BestOfRankSelector` ranks them
against each other and returns the best, best first
(`playPoolByMargin()` stands for your application: the same winners as
`playPool()`, each by that score to nil):

```php
// Twelve entrants in three pools of four
$field = array_map(
    fn (string $name) => new Participant(strtolower($name), $name),
    ['Ada', 'Ben', 'Cy', 'Di', 'Eli', 'Fay', 'Gus', 'Hal', 'Ian', 'Joy', 'Kit', 'Lou']
);
$margins = ['A' => 1, 'B' => 2, 'C' => 3];
$stagePools = [];
foreach (PoolDistributor::serpentine($field, pools: 3) as $label => $members) {
    $results = playPoolByMargin($scheduler->schedule($members), $margins[$label]);
    $stagePools[$label] = new StageOutcome($calculator->calculate($members, $results), $results, entrants: $members);
}
$stage = StageOutcome::combining($stagePools, $calculator, $field);

// Third in their pools: Gus (3 points, score difference -1), Hal (3, -2), Ian (3, -3)
$bestThirds = BestOfRankSelector::ofRank(rank: 3, count: 2)->select($stage);

echo implode(', ', array_map(fn (Participant $p) => $p->getLabel(), $bestThirds)) . "\n"; // Gus, Hal
```

Who holds the rank in a pool is read from that pool's own table. The
holders are then compared by the figures the **combined** table has for
them (the table of the outcome `StageOutcome::combining()` builds), in the
order a table is ordered by: ranking value, each tiebreaker of the
calculator given to `combining()`, score difference, scores-for. A
tiebreaker that means something only among entrants who met each other (a
head-to-head measure) means nothing between entrants of different pools:
keep it on the calculator of the pools' tables and give `combining()` a
calculator without it. The selection is the same whatever order the pools,
their entrants and their results were given in.

**Pools of unequal size need a comparison method.** Pools are unequal when
their tables do not have the same number of entries. Their entrants have
then played different numbers of events, and what is compared changes who
goes through, so the selector applies no method of its own: it refuses
until one is chosen, with the reason `PoolComparisonRequired` and the pool
sizes in the context.

```php
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Stage\PoolComparison;

// The same stage without Lou: pool A has three entrants, who play two events each
$poolA = [$field[0], $field[5], $field[6]];
$resultsA = playPoolByMargin($scheduler->schedule($poolA), 1);
$unequal = StageOutcome::combining(
    ['A' => new StageOutcome($calculator->calculate($poolA, $resultsA), $resultsA, entrants: $poolA)] + $stagePools,
    $calculator,
    array_slice($field, 0, 11)
);

try {
    BestOfRankSelector::ofRank(3, 2)->select($unequal);
} catch (InvalidConfigurationException $e) {
    echo "{$e->getReason()?->value}: " . json_encode($e->getContext()['pool_sizes']) . "\n";
}
```

```text
pool_comparison_required: {"A":3,"B":4,"C":4}
```

| `PoolComparison` | Plain data | What is compared |
|------------------|------------|------------------|
| `RawTotals` | `'raw-totals'` | The figures as the table has them. An entrant of a larger pool has had more events to collect them in |
| `PerEvent` | `'per-event'` | Every figure divided by the events the entrant has played: ranking value, each tiebreaker value, score difference, scores-for. An entrant with no event played has 0 for each, and its adjustment, if it has one, for the ranking value |
| `DiscardLowest` | `'discard-lowest'` | In every pool larger than the smallest, the entrants placed below the size of the smallest pool are left out with every result they are in, and the table is calculated again by the calculator of the outcome, with the adjustments of the entrants that are kept. Raw totals of that table are compared |

```php
$best = fn (PoolComparison $comparison): string => implode(', ', array_map(
    fn (Participant $p) => $p->getLabel(),
    BestOfRankSelector::ofRank(3, 2)->comparedBy($comparison)->select($unequal)
));

// Gus is third of three with no point from 2 events; Hal and Ian have 3 from 3
echo $best(PoolComparison::RawTotals) . "\n";     // Hal, Ian
echo $best(PoolComparison::PerEvent) . "\n";      // Hal, Ian: 1 point an event each
// Without their wins over the entrant placed fourth, Hal and Ian have no
// point either, and Gus has the best score difference of the three
echo $best(PoolComparison::DiscardLowest) . "\n"; // Gus, Hal
```

With pools of one size no method is needed. One that was chosen is still
applied, and where every compared entrant has played the same number of
events the three give the same order. The selector counts entries, not
events: it does not see that pools of one size played different numbers of
events (a pool with events still to play, pools of different leg counts, a
pool in which an entrant sat a round out or withdrew), so check
completeness before you select, and choose `PerEvent` where the numbers of
events differ by design. `DiscardLowest` recalculates a table, which takes
the calculator: `combining()` hands it to the outcome, and an outcome built
by hand takes it as its last constructor argument.

Three things to know before choosing `PerEvent` for a calculator with
tiebreakers, and before comparing pools that had byes, adjustments or
awarded results:

- **`PerEvent` divides a tiebreaker value once, by the entrant's own
  events.** That is right for a value that adds something up over the
  entrant's events, as `WinsTiebreaker` does. It is not enough for
  `BuchholzTiebreaker` and `SonnebornBergerTiebreaker`, which add up the
  ranking values of opponents: those values have themselves been collected
  over more events in a larger pool, so after the division the entrant of
  the larger pool is still ahead for no reason but the size of its pool.
  And a tiebreaker of your own that is already a ratio is divided as well,
  which a ratio should not be. Give `combining()` a calculator without
  such a tiebreaker (the pools' own tables may keep it), or choose
  `DiscardLowest`, which calculates every tiebreaker again from the
  results that are left.
- **A bye credited in a pool's table is not in the figures compared.** The
  combined table is calculated from the results, and credits no bye. Where
  a pool's own table credits a bye
  (`StandingsCalculator::calculateWith()`, as the table of a Swiss stage
  does), that credit decides who holds the rank in the pool, and the
  holders are then compared by what they scored in the events they played.
  The entries of the combined table show no bye (`getByes()` is 0 there),
  whatever the ranking strategy says a bye is worth, and a tiebreaker
  that reads byes finds none in it.
- **A tiebreaker between the tied participants says little across pools.**
  In the combined table the participants level on points come from several
  pools, and those of different pools have not met: a
  [`BetweenTiedParticipants`](#between-the-tied-participants) value there
  counts only the events against entrants of the same pool that happen to
  be level too. Keep such a tiebreaker in the pools' own tables, where it
  decides who holds a rank, and give `combining()` a calculator without
  it.
- **An [adjustment](#adjustments) is in the figures
  compared, under every method, and an awarded result is a result.**
  `combining()` carries the adjustment of a pool's table over to the
  combined table, and the ranking value compared is the total a table is
  ordered by: what was earned plus the adjustment. No other figure changes
  with it. `RawTotals` compares that total. `PerEvent` divides it by the
  events played, the adjustment with what was earned, so that three points
  taken away weigh what three points not won weigh, and the methods still
  agree where the entrants have played the same number of events; an
  entrant that has played no event has nothing to divide by and is compared
  by its adjustment as it stands (0 without one). `DiscardLowest`
  calculates the table again from the results that are left and applies
  the adjustment of every entrant that is kept again, whole: it comes from
  no event, so no result that is left out takes any of it away, and an
  entrant that is left out goes with its adjustment. An
  [awarded result](#awarded-results) is one of the events played that
  `PerEvent` divides by, and `DiscardLowest` keeps it or leaves it out with
  the other results of the two entrants, counted as the calculator's
  awarded result policy counts it.

**A selection that results do not decide is refused**, exactly as a
`RankRangeSelector` refuses one, with an `UndecidedSelectionException`:

| What is undecided | The exception |
|-------------------|---------------|
| A pool has no result, or level entries of a pool hold the rank: who holds it is not decided | names the pool (`getPool()`) |
| Holders that are level on every compared figure are on both sides of the cut (`TieAcrossSelection`) or are all selected (`TieWithinSelection`) | names the rank (`getRankAcrossPools()`); the places are places among the holders, best first, and the pool is null |
| Under `DiscardLowest`, level entries of a larger pool are on both sides of the place below which its entrants are left out | names the pool; the ranks run from the selected rank to the size of the smallest pool |

The same two choices go on regardless: `withTiesInsideSelectionAccepted()`
and `withTableOrderAccepted()`. Level holders are then listed in the order
of the outcome's entrant list, and in the order of its table when it has
none. In plain data the selector is `['rank' => 3, 'count' => 2]`, with
`comparison` and `ties` written when they were chosen and
[`format_version`](#format-versions) written first; an unknown key is
refused.

**A decision settles either kind of tie.** `withDecisions()` takes
[decisions](#settling-a-tied-set-by-a-decision) as a `RankRangeSelector`
does, and which order a decision is for follows from the participants it
names:

- participants of one pool: it orders one whole tied set of that pool's
  table, which settles who holds the rank there (and, under
  `DiscardLowest`, who is left out of a larger pool);
- participants of more than one pool: it orders one whole group of holders
  that the comparison leaves level, under whichever method was chosen. It
  orders the holders among themselves and moves none of them past a holder
  it is not level with; a decision that names an entrant who does not hold
  the rank is rejected (`ParticipantsNotLevel`, with the rank in
  `getRankAcrossPools()`).

The level holders need not be a tied set of any table: the combined table
can have other entrants level with them, and per event played it can place
them apart. So the decision for them is given to the selector, and the
table does not take it. The same holds for an overall selection compared
per event played, whose `UndecidedSelectionException` says so with
`isPerEvent()`. Whichever of the three a refusal is (a pool's table, the
holders of a rank, or the table per event played), a decision that orders
exactly the participants it names, given to the selector that refused,
settles it. `selection()` marks a holder with the decision that settled who
holds the rank in its pool, with the one that settled its place among the
holders, or with both, in that order. Under `DiscardLowest`, a decision for
level entries on both sides of the place below which a larger pool is left
out says whose results are dropped, and every holder is compared without
them: every selected place is marked with that decision, between the other
two. The decisions of the outcome's own table are not read by this
selection, which takes figures from that table and no order; nor by an
overall selection per event played, which orders the entries itself.

**`RankRangeSelector::overall()` takes the same setting.** It reads the
combined table, which ranks entrants of every pool together, so across
pools of unequal size it refuses with `PoolComparisonRequired` until
`comparedBy()` says how: `RawTotals` takes the table as it stands, and
`PerEvent` puts it in the order of its figures per event played. It takes
no `DiscardLowest` (the entrants left out would have no place in the table)
and a per-group selection takes no method at all; both are refused with
`IncompatibleOptions`.

**The selector states its yield, and does not place for a bracket.**
`getSelectionSize()` is the count, so composition validation checks a
transition that uses it. The list is best first and nothing else: put
after a per-group selection (`[...$topTwo, ...$bestThirds]`) it can pair an
entrant with one of its own pool in the first round of a bracket, because
where each has to go depends on which pools the selected entrants come
from. Order the combined list yourself where that matters.

## Timeline Assignment

`TimelineAssigner` maps a generated schedule onto real dates and times.
The mechanism is Tactician's; the policy is yours — you translate your
competition config into a declarative **slot model**, and the assigner
produces timestamped events deterministically. Round-aligned scheduling
("everyone plays round N at time T") is the one-slot case; staggered
kickoffs are the same model with more slots:

```php
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Timeline\TimelineAssigner;
use MissionGaming\Tactician\Timeline\TimelineDefinition;

// Any generated schedule: here a four-participant round robin
$participants = [
    new Participant('celtic', 'Celtic'),
    new Participant('athletic', 'Athletic Bilbao'),
    new Participant('livorno', 'AS Livorno'),
    new Participant('redstar', 'Red Star FC'),
];
$schedule = (new RoundRobinScheduler())->schedule($participants);

// Weekly match days with three staggered kickoffs, an hour apart
$timeline = new TimelineDefinition(
    start: new DateTimeImmutable('2026-08-01 18:00', new DateTimeZone('Europe/London')),
    roundInterval: new DateInterval('P7D'),
    slotsPerRound: 3,
    slotInterval: new DateInterval('PT1H'),
);

$scheduled = (new TimelineAssigner())->assign($schedule, $timeline);

foreach ($scheduled->getEventsByRound() as $round => $scheduledEvents) {
    foreach ($scheduledEvents as $scheduledEvent) {
        // ScheduledEvent wraps the untouched Event with its UTC kickoff
        $when = $scheduledEvent->getKickoff();   // DateTimeImmutable, UTC
        $event = $scheduledEvent->getEvent();
    }
}
```

The rules of the mechanism:

- **Timezone-explicit in, UTC out.** The definition's start carries the
  stage's timezone, intervals are added in that zone, and assigned
  kickoffs are emitted in UTC. Display-timezone policy stays app-side.
- **Every slot is computed from the start.** Round N is the start plus
  N−1 round intervals, taken as one step; a later slot of the round is the
  round's first slot plus one slot interval for each slot before it, taken
  as one step. No slot is computed from the slot before it, so nothing
  that happens to one round carries into the rounds after it.
- **Days keep the time of day; hours are elapsed time.** An interval
  written in days, weeks, months or years keeps the wall-clock time across
  a daylight-saving change: `P7D` from a 19:00 start is 19:00 every week.
  One written in hours, minutes or seconds is elapsed time: `PT168H` is
  19:00 until the clocks change and an hour off from then on, and `PT24H`
  likewise. Write a round interval of whole days as `P1D` or `P7D` (see
  the example below this list). One case keeps neither: a kickoff that
  falls in the hour the clocks skip (01:30 in London on the night they go
  forward) is moved an hour later by PHP, for that round alone: the round
  a week later is at 01:30 again. A local time that occurs twice (01:30 on
  the night the clocks go back) is the first of the two, whatever season
  the start is in. That is so for an interval of days or longer; one that
  also has hours, minutes or seconds (`P1DT12H`) takes the one PHP takes,
  which is the first where the timezone database marks the start as
  daylight-saving time and the second where it does not.
- **Months keep the day, or the month end.** An interval written in months
  or years keeps the start's day of the month, and a month that is too
  short for that day gives its last day: `P1M` from 31 January is 28
  February (29 in a leap year), 31 March, 30 April; from 30 January it is
  the end of February and the 30th of every other month. Where an interval
  has months and days (`P1M7D`), round N is the start plus N−1 times the
  months, and then N−1 times the days.
- **These rules are for an ISO 8601 duration.** That is what
  `new DateInterval('P1M')` and `round_interval` in plain data give. Two
  other kinds of `DateInterval` differ, as PHP treats them differently. One
  made by `DateInterval::createFromDateString()` does not show its parts,
  so it has no multiple and is added one step at a time, each step from the
  one before: `createFromDateString('1 month')` from 31 January is still 3
  March, 3 April, 3 May. Write `new DateInterval('P1M')` for month ends.
  One returned by `diff()` has its hours, minutes and seconds added as
  local time, not as elapsed time.
- **A round ends before the next one starts.** The slots of a round are at
  rising times, and the last of them must be before the first slot of the
  next round. Hourly rounds of two slots an hour apart are refused
  (`OverlappingTime`): the second slot of one round and the first slot of
  the next would be the same kickoff on the same resources. The definition
  checks its first two rounds when it is built; the assigner checks the
  rounds it assigns, because a short month or a clock change can make a
  later round shorter than the first. The assigner checks the slots the
  timeline declares for a round, whether or not the round fills them.
- **The rounds compared are the rounds played.** `assign()` compares the
  rounds the schedule holds, in round order, each with the next one it
  holds: a schedule of rounds 1 and 3 has round 1 compared with round 3,
  and its last round is compared with nothing. The slots of every round
  rise, so that covers every pair: no slot of a round the schedule holds
  is at or after a slot of a later round it holds. A round the schedule
  does not hold is never looked at, so no round is refused for running
  into one that nobody plays. Monthly rounds (`P1M`) from 31 December
  with two slots 30 days apart have round 2 on 31 January and 2 March,
  and round 3 from 28 February: a schedule of rounds 1 and 2 is
  assigned, and one that holds round 3 as well is refused. The order is
  the order of the round numbers, so a later round at an earlier time is
  refused like one at the same time. `assignRound()` is given one round
  and keeps nothing between calls, so it compares that round with the
  one before it by number, which a stage that plays its rounds in order
  from round 1 has played. Round 1 is compared with nothing, and so is
  no round with the one after it: an overlap of two rounds is reported
  when the later of them is assigned. The round before is the one before
  by number whether or not it was played. A caller whose rounds have a
  gap (a number skipped, or a pairing with no events) has a round
  compared with one nobody played, which can refuse it, and the two
  played rounds on either side of the gap compared with each other by
  no call; `assign()` given a schedule of the rounds played compares
  exactly those.
- **A round too far away is refused.** A round whose time is more than
  some 270 billion years from the start, which is past what PHP's
  timestamps hold, fails with the reason `ValueOutOfRange`; PHP would
  return a date that has wrapped round. Short of that a round number has
  no limit where the interval is a duration: the steps are multiplied
  out, so round a million costs what round two costs. An interval made by
  `DateInterval::createFromDateString()` has no multiple and is added once
  for each step, so it has a limit on the steps instead: 100,000, which
  is round 100,001 (a round a day for 273 years), and a round past it
  fails with the same reason.
- **A round has at most 10,000 slots.** A definition with more
  (`TimelineDefinition::MAX_SLOTS_PER_ROUND`) is refused when it is built,
  with the reason `ValueOutOfRange`, before any slot is computed. The
  limit is there because the check that the slots of a round rise visits
  every slot the round declares, when the definition is built and again
  for each round assigned: no arithmetic says where a timezone's clocks
  put two local times at one instant. 10,000 slots are a kickoff every
  minute, day and night, for a week; `docs/design/timeline-assignment.md`
  says why the limit has that value.
- **Deterministic filling.** A round's events fill its slots in schedule
  order against slot time order — the same schedule and timeline always
  produce the same kickoffs.
- **Loud validation.** A round with more events than the timeline can
  hold (slots × resources) fails with diagnostics, and a schedule
  carrying round-less events is refused rather than silently dropping
  fixtures.
- **Round numbers are absolute offsets.** Round N lands at
  start + (N−1) round intervals whether or not earlier rounds exist, so
  cross-leg-continuous numbering maps stably.
- **Decoration, not mutation.** `ScheduledEvent`/`ScheduledSchedule`
  wrap events without touching them; re-assignment against a different
  timeline is cheap, and the decorated view serializes
  (`toArray()`/`fromArray()`/JSON) so platforms persist assigned
  kickoffs.

The two ways to write a week, across the end of British Summer Time on
25 October 2026:

```php
$londonTime = new DateTimeZone('Europe/London');

foreach (['P7D', 'PT168H'] as $roundInterval) {
    $weekly = (new TimelineAssigner())->assign($schedule, new TimelineDefinition(
        start: new DateTimeImmutable('2026-10-17 19:00', $londonTime),
        roundInterval: new DateInterval($roundInterval),
        resources: ['Pitch 1', 'Pitch 2'],
    ));

    $kickoffs = [];
    foreach ($weekly->getEventsByRound() as $roundEvents) {
        $kickoffs[] = $roundEvents[0]->getKickoff()->setTimezone($londonTime)->format('j M H:i');
    }
    echo "{$roundInterval}: " . implode(', ', $kickoffs) . "\n";
}
```

```text
P7D: 17 Oct 19:00, 24 Oct 19:00, 31 Oct 19:00
PT168H: 17 Oct 19:00, 24 Oct 19:00, 31 Oct 18:00
```

The assigner places events on slots and checks that a round fits and that
it ends before the next round starts. It also refuses a round that puts
one participant in two events at the same time, which a slot with two or
more resources would otherwise allow, with or without a rule
(`InvalidConfigurationException`, reason `ParticipantDoubleBooked`; the
context lists every participant concerned under `double_bookings`). It
fills in schedule order and cannot move an event, so the remedy is in the
schedule: the generators do not produce such a round. Two events of one
participant in different slots of a round are assigned; whether the time
between them is enough is a rule's to say. The check is made round by
round, and the rounds of one timeline cannot overlap; if two stages can
overlap in time, add a `MinimumRestRule` (see
[Time-Aware Rules](#time-aware-rules)) and validate the two timelines
together.

**Resources** host concurrent events within a slot — venue, pitch,
court, board, station; the name is generic because the concept is.
Declaring them lifts the one-event-per-slot default: each slot hosts one
event per resource, filled slot by slot, resource by resource in
declared order, and every `ScheduledEvent` carries its assigned resource
(`getResource()`, serialized alongside the kickoff):

```php
$timeline = new TimelineDefinition(
    start: new DateTimeImmutable('2026-08-01 15:00', new DateTimeZone('Europe/London')),
    roundInterval: new DateInterval('P7D'),
    resources: ['Pitch 1', 'Pitch 2'],   // two concurrent kickoffs per slot
);
```

Timelines are **per stage** — a group stage playing weekly slots and a
finals weekend are two `TimelineDefinition`s. For results-driven stages,
assign round by round through the engine bridge:

```php
use MissionGaming\Tactician\Scheduling\SwissPairingEngine;
use MissionGaming\Tactician\Stage\StageState;

$engine = new SwissPairingEngine(plannedRounds: 3);
$state = StageState::start($participants);

$pairing = $engine->pairNextRound($state);
$scheduledEvents = (new TimelineAssigner())->assignRound($pairing, $timeline);
```

`assignRound()` makes the checks `assign()` makes, on the one round: the
round fits, its slots rise, it starts after the round numbered before it
has ended, and it satisfies the rules the assigner was built with (see
[Time-Aware Rules](#time-aware-rules)). A rule is shown this round's
events and no others, since the assigner keeps nothing between calls.

Definitions are plain-data constructible for config-driven platforms,
with ISO 8601 durations:

```php
$timeline = TimelineDefinition::fromArray([
    'start' => '2026-08-01 18:00:00',
    'timezone' => 'Europe/London',
    'round_interval' => 'P7D',
    'slots_per_round' => 3,
    'slot_interval' => 'PT1H',
]);
```

`toArray()` writes a definition back to that form, and what it writes reads
back to the same slot times. An ISO 8601 duration, as PHP reads one, holds
years, months, days, hours, minutes and whole seconds. A `DateInterval` can
hold more, and a definition or a session grid built with such an interval
works, and is refused by `toArray()` with the reason `NotSerializable`: it
is never written as a duration that means something else. The intervals
refused are:

- one made by `DateInterval::createFromDateString()`, whatever the string.
  `next weekday` and `last day of next month` have no duration. Amounts of
  time alone (`1 week`, `1 month`, `25 minutes`) have one, and it gives
  other slot times: such an interval is added one step at a time and its
  hours as local time, as described above, and the duration is not (`1
  month` from 31 January is 3 March where `P1M` is 28 February);
- one from `diff()` that has hours, minutes or seconds, because PHP adds
  those as local time and the hours of a duration are elapsed time. An
  interval of whole days, months and years that `diff()` returned is
  written;
- one with a fraction of a second, an inverted one, and one with a negative
  part.

Build the interval as `new DateInterval('PT25M')` where the configuration
has to be stored. A start with a fraction of a second is written with it
(`2026-08-01 18:00:00.250000`), and so are a session start of a grid and
a bound of a blackout window. A `MinimumRestRule` adds its rest once and
in UTC, so its `toArray()` writes a rest of amounts of time from either
source (`48 hours` is `PT48H`) and refuses only what has no duration: a
relative string that is more than amounts, a fraction of a second, a sign.

**A datetime in plain data states its date in full**: the year, the month
and the day, as in `2026-08-01 18:00` or `2026-08-01T18:00:00`. The time of
day is optional and defaults to midnight (`2026-11-09` is
`2026-11-09 00:00:00`); so do the seconds and a fraction of a second. The
zone comes from the `timezone` field. A zone or offset written into the
string as well must be that field's value, spelled the same way: it is
compared as a name, not as an offset, so `+01:00` or `BST` is refused under
`Europe/London` even in summer, and `Z` or a Unix timestamp under `UTC`
(the reason is `TimezoneMismatch`). This holds wherever a datetime is read
from plain data: the `start` of a timeline, the `from` and `to` of a
blackout window and the `sessions` of a session grid. The `kickoff` of a
serialized scheduled event declares no zone: it is UTC unless the string
carries one, and any zone the string carries is accepted.

Two kinds of string are rejected, with the reason `UnparseableTime` (the
kickoff with an `InvalidInputException`):

- A string whose answer would come from the clock. It is relative to the
  current time (`now`, `tomorrow`, `+1 week`, `next monday 20:00`, the
  empty string), or it has no date (`20:00`), or its date has no year
  (`August 1 20:00`).
- A string that does not mean what it writes. It names a date or a time
  that does not exist (`2026-02-30 20:00`, `2026-08-01 24:00`,
  `2026-08-01 23:59:60`), a weekday that is not the weekday of its date
  (`Mon, 01 Aug 2026 19:00:00`: 1 August 2026 is a Saturday), or a second
  timezone that is not the first (`2026-08-01 19:00 +01:00 +05:00`; where
  a `timezone` field is declared and the first zone is not it, the reason
  is `TimezoneMismatch`, because that check comes first).

PHP's date parser accepts all of these. It resolves the first kind against
the clock, so the same configuration would give a different timeline each
time it is loaded, and the library never asks for the current time. It
reads the second kind to another instant than the one written: 30 February
as 2 March, `24:00` as the next day, the Saturday as the Monday after it,
and the first of two timezones.

<!-- snippet: throws="MissionGaming\Tactician\Exceptions\InvalidConfigurationException" -->
```php
// "tomorrow" is a different day each time the configuration is loaded
TimelineDefinition::fromArray([
    'start' => 'tomorrow 18:00',
    'timezone' => 'Europe/London',
    'round_interval' => 'P7D',
]);
```

Every other string PHP reads is accepted, and read as PHP reads it: a month
name (`1 August 2026 19:00`), an RFC 2822 date with the right weekday (its
offset falls under the rule on zones above), an ISO 8601
week date (`2026-W31-6T19:00`) or ordinal date (`2026-213T19:00`), and,
where the rule on zones above allows the zone they carry (a kickoff, or a
`timezone` field of `+00:00`), a Unix timestamp (`@1785610800`) and a
timezone written twice (`+0000 (UTC)`). That
includes an offset from a date the string states, which is counted from that
date and not from today: `2026-08-01 20:00 +1 week` is 8 August at 20:00,
and `first monday of August 2026 19:00` is 3 August. A relative date with no
date to count from is for the application to compute and pass on.

One local time is still read to another: a time that a clock change skips
in the declared timezone (`2026-03-29 01:30` in `Europe/London`) is moved
forward by the hour that was skipped.

### Time-Aware Rules

Assignment is deterministic slot arithmetic, so a violated time rule
cannot be routed around — it can only be reported, loudly. **Timeline
rules** (`TimelineRule`) judge the assigned kickoffs; they are
deliberately not generation constraints, which filter pairings during a
search before any time exists:

```php
use MissionGaming\Tactician\Timeline\BlackoutRule;
use MissionGaming\Tactician\Timeline\MinimumRestRule;
use MissionGaming\Tactician\Timeline\TimelineAssigner;

$assigner = new TimelineAssigner([
    // Rest measured in hours rather than rounds, compared as UTC
    // instants (DST cannot shrink it); any positive rest also forbids
    // double-booking
    MinimumRestRule::fromArray(['rest' => 'PT48H']),

    // Half-open windows [from, to); translating policy (breaks,
    // closures, holidays) into windows is the application's job
    BlackoutRule::fromArray(['windows' => [[
        'from' => '2026-11-09 00:00:00',
        'to' => '2026-11-17 00:00:00',
        'timezone' => 'Europe/London',
        'label' => 'international break',
    ]]]),
]);

// Violating any rule fails assignment with every violation in the
// exception context
$scheduled = $assigner->assign($schedule, $timeline);
```

`assignRound()` applies the same rules to the round it is given, and fails
the same way. It judges that round alone: a blackout window is checked in
full, and a minimum rest only between the events of the round, because the
events of earlier rounds are not there to compare with.

Rules also validate standalone — `$rule->validate($scheduled)` returns
violation strings — so an application driving a results-driven stage
round by round can check the timeline it accumulates, which is how rest
between rounds is checked there. Both built-in
rules are plain-data constructible with `fromArray()`/`toArray()`, and
custom rules implement the two-method `TimelineRule` interface.

## Schedule Repacking

Generation invents events; repacking repairs a schedule whose events
already exist. Given movable events, pinned events that must not move,
and a **session grid**, `ScheduleRepacker` assigns every movable event a
`(session, slot)` position such that no participant is ever in two
events at once and each participant's events within a session run back
to back where possible. The canonical case is a season behind schedule:
the outstanding fixtures are fixed facts with wildly unequal
per-participant loads, and the operator needs them landed on declared
match nights.

The grid is an explicit ordered list of session starts — deliberately
not `TimelineDefinition`'s regular cadence, because recovery grids are
irregular and the last session routinely runs deeper than the rest:

```php
use MissionGaming\Tactician\Repack\SessionGrid;

$grid = SessionGrid::fromArray([
    'sessions' => ['2026-08-12 20:00', '2026-08-19 20:00'],
    'timezone' => 'Europe/London',       // authoritative, like the timeline family
    'slot_interval' => 'PT30M',
    'slots_per_session' => 3,
    'slots_per_session_overrides' => [1 => 4],  // final session runs deeper
    'capacity_per_slot' => 3,            // concurrent events per slot
]);
```

**Sessions do not overlap.** Session starts are strictly ascending, and
the last slot of a session must be before the start of the next one. A
grid with sessions at 20:00 and 20:25 and two slots 25 minutes apart is
refused when it is built (`InvalidConfigurationException`, reason
`OverlappingTime`): the second slot of the first session and the first
slot of the second would be the same instant, and a participant could be
placed in both with no double booking reported. The check reads each session's
own slot count, overrides applied, and nothing follows the last session,
so that one can run as deep as it needs to. The slots of one session must
be at rising instants as well, which they are for a slot interval written
as an ISO 8601 duration (unless its slots are days apart across a calendar
day the timezone skipped, as `Pacific/Apia` skipped 30 December 2011). A
shape-only grid has no instants and is not
checked. Every slot time is computed from its session's start, never from
the slot before it.

**A grid has at most 100,000 positions** (`SessionGrid::MAX_POSITIONS`):
the slot counts of its sessions added up, overrides applied. The number
of sessions (`session_count`, or the length of `sessions`),
`slots_per_session`, each slot count in `slots_per_session_overrides`
and their sum are each refused above it, with the reason
`ValueOutOfRange`, before anything of that size is allocated or visited.
The limit is the same for both forms of grid, and its value comes from
the instant-based one, which keeps the instant of every position
(`docs/design/schedule-repack.md` says why). 100,000 positions are a
slot every five minutes, day and night, for nearly a year.
`capacity_per_slot` has a limit as well, of a million million
(`SessionGrid::MAX_CAPACITY_PER_SLOT`); see
[Unbounded Capacity](#unbounded-capacity).

Events carry an opaque caller-supplied id — the library never invents
one, never parses it, and orders results by nothing else. The same pair
may meet more than once (the input is a multigraph); only the id is
unique. Pinned events occupy their position for both participants and
are never assigned a new one; a pin may name a participant that appears
nowhere in the movable set (a withdrawn participant's played fixtures
still block their opponents' positions):

```php
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Repack\MovableEvent;
use MissionGaming\Tactician\Repack\PinnedEvent;
use MissionGaming\Tactician\Repack\RepackOptions;
use MissionGaming\Tactician\Repack\RepackRequest;
use MissionGaming\Tactician\Repack\ScheduleRepacker;

$celtic = new Participant('celtic', 'Celtic');
$athletic = new Participant('athletic', 'Athletic Bilbao');
$livorno = new Participant('livorno', 'AS Livorno');
$rayo = new Participant('rayo', 'Rayo Vallecano');

$request = new RepackRequest(
    movableEvents: [
        new MovableEvent('e03', $celtic, $livorno),
        new MovableEvent('e04', $celtic, $rayo),
        // ...
    ],
    pinnedEvents: [
        // Played at session 0, slot 0 — historical provenance, immovable
        new PinnedEvent('e01', $celtic, $athletic, 0, 0),
    ],
    grid: $grid,
    options: new RepackOptions(consolidationWeight: 3, earlyFillWeight: 1)
);

$outcome = (new ScheduleRepacker())->repack($request);

foreach ($outcome->getAssignments() as $assignment) {
    // $assignment->getEventId(), ->getSession(), ->getSlot(),
    // ->getKickoff() — the position's UTC kickoff from the grid
}
```

**Repacking returns compromises instead of throwing.** This is a
deliberate deviation from the generation contract: an operator repairing
a broken season the week of the games needs "here is the schedule, and
here are the compromises in it", not an exception. The outcome carries
every compromise as structured data (participant, session, kind,
magnitude — never pre-formatted prose), and the caller decides what is
fatal. `RepackOptions(throwOnViolations: true)` opts into a
`RepackViolationsException` that still carries the full outcome.

The contract, in order:

1. **Hard, never traded** — no participant occupies one position twice,
   counting pins; and pins do not move. `ParticipantDoubleBooked` exists
   as a violation kind only so the final audit can prove it never
   happens.
2. **Hard, or reported** — no interior gap in a participant's slots
   within a session (`ContiguityBroken`, with the gap size). Interval
   edge colouring is not always possible, so an instance that cannot be
   gap-free is reported, never silently relaxed.
3. **Objectives** — start each participant's run at the session's first
   slot (`LateStart` reports the depth when impossible), concentrate
   each participant's events into fewer sessions, and fill early
   sessions first. The last two pull against each other;
   `consolidationWeight` and `earlyFillWeight` make the trade explicit,
   and consolidation dominates by default.

Capacity problems surface as `CapacityExceeded` — most usefully scoped
to a participant whose outstanding events outnumber its free positions
once pins are respected (`getShortfall()` says how many positions the
operator must add). Every such participant is reported, with its demand
counted before any event is dropped. The shortfall events come back in
`getUnplaced()` with reasons, and every movable event is either assigned
or listed there — the counts reconcile exactly. No more events are
dropped than the shortfalls need: an event between two over-capacity
participants counts towards both, so those go first, and names one of
the two.

Placing an event comes before keeping a session contiguous. When the
packing leaves an event without a position, a **last placement step**
moves events that are already placed to make room for it: along an
alternating path (the event takes a position where one placed event is
in its way, that event moves to a position of its own, and so on), and
where that is not enough, by taking placed events out for unplaced
ones when that lets one more in: one for one, two for one, or as many
as it takes at a single position. That is how it changes which events
of a participant with more events than positions are the ones left
out, which decides whether the rest fill the grid. It may move events
between sessions and leave a gap or a late start, which are reported
like any other. It never double-books, never moves a pin and never
exceeds a slot's capacity, and an outcome with nothing unplaced never
reaches it.

Keeping a session contiguous comes before the two weights. The weights
choose a session for each event, and a choice that looks best by them
can be one no slot order lays out without a gap. So the repacker also
lays the events out with no regard to the weights (whole sets of events
that can share a slot, one set to a position in grid order, around the
pins), and returns that schedule when it places more events than the
one the weights chose, or as many with fewer gap slots. A schedule that
still has a gap is then searched for another placement of as many
events with fewer gap slots; on a small request that search tries every
placement, so a gap it reports is one no placement of as many events
avoids, and on a large one it stops at a limit of its own.

Both of those schedules come from the events taken set by set, and no
one order of the events is the best for every request. So where the
result still places fewer events than a bound on what any placement can
hold, or still has a gap, the repacker plans and packs the events once
more in the order of their participants, with the steps its budgets
have left, and returns that third schedule when it places more events,
or as many with fewer gap slots. A result that is already clean costs
nothing more.

**The ids decide as little as they can.** The repacker takes the
participants and the events in an order made from the request itself:
how many events a participant has, where it is pinned and whom it
plays. An id decides only which of several events between the same two
participants is which, and between participants the request does not
tell apart. Give the same request other ids and the outcome has the
same number of events at every position, the same number unplaced for
each reason and the same violations but for the names in them; the
schedule is the same participant for participant, up to a symmetry of
the request (in a complete round robin any participant can stand for
any other, and which of them gets which run of opponents follows the
ids). There are two exceptions, in which the ids do decide and the
counts can differ. The ordering has a fixed amount of work it may do,
sized for 40 participants: in a larger request in which everybody is
alike and no two participants can simply be exchanged (a complete round
robin of 42 or more with one round already played), it stops and falls
back to the ids. And two participants that differ, in a request in
which everything the repacker compares about them is equal, are ordered
by id; that takes a request built for the purpose, and none met in
testing was one. The lists an outcome returns are still in id order.
Without the objective `LeastChurn` the order does not read the current
positions events may state, so the count of moved events can follow the
ids; with it, the current positions decide before the ids do, and the
count of moved events does not follow them either
([Objectives](#objectives)).

The repacker is deterministic (same input, same output, independent of
input list order), pure (no clock reads, no I/O), and bounded in steps
(the packing searches spend from one budget of
`RepackOptions(stepBudget: ...)` steps, and the steps after the packing
from a second of the same size, so a repack can spend up to twice that;
the layout made before any session is chosen, the last placement step
and the search for fewer gap slots also stop at limits on their own
work, which a budget below the default makes smaller in proportion; the
ordering of the participants has a fixed limit of its own). The bound
makes a repack reproducible, which is what a preview that is later
confirmed needs. It is a bound on work and not on seconds: see
[The Step Budget](#the-step-budget) for what a repack takes at which
budget. Events that fall entirely outside the grid cannot collide with
it and are the caller's to filter before the request — collision is
exact position identity, by design; there is no fuzzy time-overlap
detection.

### Grids Without Instants

`SessionGrid` as built above is **instant-based**: it knows when every
position is, and each assignment carries a UTC kickoff. An application
that keeps its times in another form (local wall-clock times, a list of
its own slot records) has no instants to give and no use for the ones it
would get back. For that, `SessionGrid::shapeOnly()` builds a
**shape-only grid**: how many sessions, how many slots each, and the
capacity.

```php
$shape = SessionGrid::shapeOnly(
    sessions: 2,
    slotsPerSession: 3,
    slotsPerSessionOverrides: [1 => 4],
    capacityPerSlot: 3
);

$shaped = (new ScheduleRepacker())->repack(new RepackRequest(
    $request->getMovableEvents(),
    $request->getPinnedEvents(),
    $shape
));

foreach ($shaped->getAssignments() as $assignment) {
    $ordinal = $shape->ordinalOf($assignment->getSession(), $assignment->getSlot());
    echo "{$assignment->getEventId()}: session {$assignment->getSession()}, "
        . "slot {$assignment->getSlot()}, ordinal {$ordinal}\n";
}
// e03: session 0, slot 1, ordinal 1
// e04: session 0, slot 2, ordinal 2

echo json_encode($shape->positionOf(5)), "\n";   // {"session":1,"slot":2}
echo json_encode($shape->positionOf(7)), "\n";   // null: the grid has 7 positions, 0 to 6
echo json_encode($shape->toArray()), "\n";
// {"format_version":1,"session_count":2,"slots_per_session":3,"capacity_per_slot":3,"slots_per_session_overrides":{"1":4}}
```

The repacker reads positions and nothing else, so a shape-only grid gets
every event the same `(session, slot)` as the instant-based grid of the
same shape. What differs is what the library can say about time:

- `hasInstants()` is false, and `getSessionStart()`, `getSlotInterval()`,
  `getSlotTime()` and `positionOf()` given an instant throw an
  `UnavailableValueException`. A shape-only grid never invents an
  instant.
- An assignment has no kickoff: `SlotAssignment::hasKickoff()` is false,
  `getKickoff()` throws the same exception, and `toArray()` carries
  `'kickoff' => null`.
- The exception reports a mistake in the calling code, not in its data,
  and is not one to catch: code that may be given either form of grid
  asks `hasInstants()` or `hasKickoff()` first. It extends
  `\LogicException`, so code that reads `getKickoff()` from the
  assignments of an instant-based grid has nothing new to handle. See
  [Exception Hierarchy](#exception-hierarchy).
- As plain data the grid has `session_count` in place of `sessions`,
  `timezone` and `slot_interval`. `fromArray()` reads the shape-only form
  only when there is no `sessions` key; a shape-only grid that also gives
  `timezone` or `slot_interval` is rejected (`IncompatibleOptions`). An
  instant-based grid serializes exactly as it did before shape-only grids
  existed.

<!-- snippet: throws="MissionGaming\Tactician\Exceptions\UnavailableValueException" -->
```php
$shape->getSlotTime(0, 0);
// A shape-only grid has no instants, so it cannot give a slot time.
// Check hasInstants() before asking for a time.
```

Two lookups turn a position into something an application can index its
own records by, and back. They work on both forms of grid. A position's
**ordinal** is its 0-based index in grid order, session by session and
slot by slot: `ordinalOf($session, $slot)` returns it (and throws an `InvalidConfigurationException` with the reason
`PositionOutOfRange` for a position the grid does not have), and
`positionOf($ordinal)` returns `['session' => ..., 'slot' => ...]`, or
null when no position has that ordinal. On an instant-based grid
`positionOf()` also takes an instant and returns the position whose slot
time it is, or null:

```php
$halfPastEight = new DateTimeImmutable('2026-08-19 20:30', new DateTimeZone('Europe/London'));

echo json_encode($grid->positionOf($halfPastEight)), "\n";   // {"session":1,"slot":1}
echo $grid->ordinalOf(1, 1), "\n";                           // 4
var_dump($grid->hasInstants());                              // bool(true)
```

The instants are compared, so the timezone the argument is written in
does not matter. No two positions share an instant: a grid in which two
would is refused when it is built.

### Unbounded Capacity

`capacityPerSlot` is how many events may share one slot, and its default
is 1: one event at a time. That default is right for one shared resource
and wrong for events that need nothing shared, where the only limit is
that no participant is in two events at once. Pass `null` for
**unbounded capacity** (`'capacity_per_slot' => SessionGrid::UNBOUNDED`,
the string `'unbounded'`, in plain data). It is the way to say "no
limit": do not pass a very large integer for that. The planner adds the
capacity up over the positions of the grid, so a capacity above a
million million (`SessionGrid::MAX_CAPACITY_PER_SLOT`) is refused when
the grid is built, with the reason `ValueOutOfRange`; that number times
the most positions a grid can have is still an integer PHP holds, and a
number written to mean "no limit" (2,147,483,647, say) is below it and
works as before.

```php
$events = [
    new MovableEvent('e03', $celtic, $livorno),
    new MovableEvent('e04', $celtic, $rayo),
    new MovableEvent('e05', $celtic, $athletic),
    new MovableEvent('e06', $livorno, $rayo),
    new MovableEvent('e07', $athletic, $rayo),
];
$pins = [new PinnedEvent('e01', $celtic, new Participant('napoli', 'Napoli'), 0, 1)];

// One session of three slots. With the default capacity of 1 the pin
// fills a slot, and the grid has two positions for five events
$crowded = (new ScheduleRepacker())->repack(
    new RepackRequest($events, $pins, SessionGrid::shapeOnly(sessions: 1, slotsPerSession: 3))
);
echo count($crowded->getAssignments()), ' placed, ', count($crowded->getUnplaced()), " unplaced\n";
// 2 placed, 3 unplaced

$open = SessionGrid::shapeOnly(sessions: 1, slotsPerSession: 3, capacityPerSlot: null);
$roomy = (new ScheduleRepacker())->repack(new RepackRequest($events, $pins, $open));
echo count($roomy->getAssignments()), ' placed, ', count($roomy->getUnplaced()), " unplaced\n";
// 4 placed, 1 unplaced

var_dump($open->hasUnboundedCapacity());   // bool(true)
var_dump($open->getCapacityLimit());       // NULL
echo $open->toArray()['capacity_per_slot'], "\n";   // unbounded
```

Unbounded capacity removes the limit on a slot and nothing else:

- The grid as a whole can no longer be too small, so the outcome never
  carries a `CapacityExceeded` with a null participant.
- A participant can still have more events than free positions, which no
  capacity changes. That is the one event still unplaced above: Celtic
  has three events and, with the pin, two free slots. It is reported as a
  `CapacityExceeded` naming the participant.
- Any number of events may be pinned at one position.
- `getCapacityPerSlot()` returns an integer, and an unbounded grid has
  none to return: it throws an `UnavailableValueException`.
  `getCapacityLimit()` returns the integer or null and never throws; code
  that may be given either kind of grid reads that, or asks
  `hasUnboundedCapacity()` first.

A missing or null `capacity_per_slot` in plain data still means 1, as it
always has. That is why the plain-data form of unbounded is a word.

### Reading the Outcome

`getViolations()` returns every compromise as a `RepackViolation`, each
with a `getKind()` (a `ViolationKind` case) and a `toArray()`. There are
five kinds, each a class of its own with getters for what it is about.
`getViolationsOfKind(ViolationKind $kind)` filters the list and returns
it typed as the interface. The five accessors named after the kinds
return the same lists typed as their classes, so the getters can be read
without an `instanceof` check:

```php
foreach ($crowded->getCapacityExceededViolations() as $violation) {
    $who = $violation->getParticipant()?->getLabel() ?? 'the grid';
    echo "{$who}: {$violation->getDemand()} needed, {$violation->getCapacity()} available, "
        . "{$violation->getShortfall()} short\n";
}
// Celtic: 3 needed, 2 available, 1 short
// the grid: 4 needed, 2 available, 2 short

foreach ($crowded->getLateStartViolations() as $violation) {
    echo "{$violation->getParticipant()->getLabel()} starts at slot {$violation->getFirstSlot()} "
        . "of session {$violation->getSession()}\n";
}
// Athletic Bilbao starts at slot 2 of session 0
// Rayo Vallecano starts at slot 2 of session 0

foreach ($crowded->getUnplaced() as $unplaced) {
    $because = $unplaced->getParticipant()?->getLabel() ?? 'nobody in particular';
    echo "{$unplaced->getEventId()}: {$unplaced->getReason()->value} ({$because})\n";
}
// e04: no_slot_available (nobody in particular)
// e05: participant_over_capacity (Celtic)
// e06: no_slot_available (nobody in particular)

echo count($crowded->getEventUnplacedViolations()), "\n";      // 3
echo count($crowded->getContiguityBrokenViolations()), "\n";   // 0
echo count($crowded->getParticipantDoubleBookedViolations()), "\n";   // 0
```

| Kind (`ViolationKind`) | Class and typed accessor | What it reports |
|---|---|---|
| `ParticipantDoubleBooked` (`participant_double_booked`) | `ParticipantDoubleBooked`, `getParticipantDoubleBookedViolations()` | A participant at one position twice. The repacker never produces it; the final audit checks for it so that its absence is a fact. |
| `EventUnplaced` (`event_unplaced`) | `EventUnplaced`, `getEventUnplacedViolations()` | A movable event that received no position. One for each entry of `getUnplaced()`, so code that reads violations alone misses nothing. |
| `ContiguityBroken` (`contiguity_broken`) | `ContiguityBroken`, `getContiguityBrokenViolations()` | A participant whose slots in one session have a gap between them. |
| `LateStart` (`late_start`) | `LateStart`, `getLateStartViolations()` | A participant whose first slot in a session is not the session's first. |
| `CapacityExceeded` (`capacity_exceeded`) | `CapacityExceeded`, `getCapacityExceededViolations()` | More events than positions: for a participant, or (null participant) for the grid. |

`ContiguityBroken` and `LateStart` are reported for a participant and a
session only where the participant has a movable event in that session; a
session holding only that participant's pins is history the repack did
not arrange. `isClean()` is true when there is no violation of any kind
and nothing unplaced, late starts included.

An unplaced event (`UnplacedEvent`) has a reason, an `UnplacedReason`
case: `ParticipantOverCapacity` (`participant_over_capacity`) when one of
its participants has more events than free positions, and then
`getParticipant()` is that participant (for an event between two such
participants, one of the two); `NoSlotAvailable`
(`no_slot_available`) when no position on the whole grid had capacity
left with both participants free, and then `getParticipant()` is null.
Each over-capacity participant has at least its shortfall of events
unplaced as `participant_over_capacity`.

### Figures to Rank an Outcome By

The violations say what is wrong with a schedule. Five figures say how
good it is, and each is an accessor of the outcome, so two outcomes can
be compared without the request they came from:

| Figure | Accessor | What it counts |
|---|---|---|
| Unplaced events | `getUnplacedCount()` | The movable events with no position: the size of `getUnplaced()`. It comes first: an outcome that places more events is the better one. |
| Gap slots | `getGapSlotCount()` | The slots participants wait through between two of their own events in a session: the gap slots of every `ContiguityBroken`, added up. |
| Lone-event sessions | `getLoneEventSessionCount()`, `getLoneEventSessions()` | The sessions that hold exactly one event, pinned events counted, where that one is a movable event: a session opened for a single event. |
| Idle sessions | `getIdleSessionCount()` | For each participant with a placed movable event, the sessions it has no event in between two sessions it has one in, pinned events counted; summed over the participants. |
| Moved events | `getMovedCount()`, `getMovedEventIds()` | The events placed somewhere other than their **current position** (below). |

Every outcome has all five, whatever the options were. They are figures
and not compromises: `isClean()` does not look at the last three. Three
of them need defining exactly:

- A **lone-event session** is a figure of the grid, not of a
  participant: a session with two events is not one, although each of
  four participants has a single event in it. A session whose one event
  is pinned is not counted, because the repack did not arrange it, and
  an empty session is not counted either.
- An **idle session** lies strictly between a participant's first and
  last session with an event. With events in sessions 1, 2 and 5, the
  participant has two idle sessions, 3 and 4; sessions 0 and 6 are not
  idle for it. A session is counted once for each participant it is
  idle for. A participant whose events are all pinned is left out: its
  idle sessions are history.
- A **gap slot** is counted where a participant has a movable event in
  the session, with its pinned slots as part of the pattern, exactly as
  `ContiguityBroken` reports it. A late start is not a gap slot.

```php
use MissionGaming\Tactician\Repack\RepackOutcome;

// Three sessions of two slots, two events to a slot. Three events are
// fixed where they are; the others say where they are now, and one, which
// was postponed, has no position
$napoli = new Participant('napoli', 'Napoli');
$nights = SessionGrid::shapeOnly(sessions: 3, slotsPerSession: 2, capacityPerSlot: 2);
$fixed = [
    new PinnedEvent('n01', $celtic, $athletic, 0, 0),
    new PinnedEvent('n02', $livorno, $rayo, 0, 0),
    new PinnedEvent('n03', $celtic, $napoli, 0, 1),
];
$outstanding = [
    new MovableEvent('n04', $celtic, $livorno, 1, 0),
    new MovableEvent('n05', $athletic, $rayo, 1, 0),
    new MovableEvent('n06', $celtic, $rayo, 1, 1),
    new MovableEvent('n07', $athletic, $napoli, 2, 0),
    new MovableEvent('n08', $livorno, $napoli, 2, 1),
    new MovableEvent('n09', $athletic, $livorno),
];

$plain = (new ScheduleRepacker())->repack(new RepackRequest($outstanding, $fixed, $nights));

echo $plain->getUnplacedCount(), "\n";                          // 0
echo $plain->getGapSlotCount(), "\n";                           // 0
echo json_encode($plain->getLoneEventSessions()), "\n";         // [2]
echo $plain->getIdleSessionCount(), "\n";                       // 0
echo json_encode($plain->getMovedEventIds()), "\n";             // ["n08"]

echo json_encode($plain->toArray()['metrics']), "\n";
// {"unplaced":0,"gap_slots":0,"lone_event_sessions":[2],"idle_sessions":0,"moved":["n08"]}
```

The repack moved `n08` from the last session to the middle one, where
its two participants already play, and left `n07` alone in the last:
that is the consolidation weight at work, and the figures show what it
cost. `toArray()` writes the five under `metrics`, because three of them
cannot be worked out from the rest of the array (it holds neither the
participants of an event nor the pinned events). The fingerprint does
not cover them: they follow from the request and the assignments, which
it does cover.

To rank outcomes, put the figures in the order that matters to the
application and compare the lists. Unplaced events first is the one
order the library itself never departs from:

```php
$rank = static fn(RepackOutcome $outcome): array => [
    $outcome->getUnplacedCount(),
    $outcome->getLoneEventSessionCount(),
    $outcome->getIdleSessionCount(),
    $outcome->getGapSlotCount(),
    $outcome->getMovedCount(),
];

echo json_encode($rank($plain)), "\n";   // [0,1,0,0,1]
```

### Current Positions and Moved Events

A movable event may say where it is now: a session and a slot of the
request's grid, the fourth and fifth arguments of `MovableEvent`, as a
pinned event has them. It is a statement of fact and never a demand.
The repacker is free to put the event elsewhere, and reports every
event it placed anywhere but there as **moved**, whether the move is to
another session or to another slot of the same one.

- An event that states no current position (a new event, a postponed
  one) is never counted as moved, wherever it is placed. Neither is an
  unplaced event, which is in `getUnplaced()`.
- A current position need not be a position the grid has. An event
  whose session was taken off the grid states the position it had; it
  cannot stay there, so it counts as moved wherever it is placed. Only a
  negative index, or a session without a slot, is refused
  (`InvalidConfigurationException`).
- Nothing checks current positions against the pins or against each
  other. Where a pinned event holds the position (the slot is full, or
  one of the participants is pinned there), the event moves. Where two
  events cannot both stay (one participant in both, or more events than
  the slot holds), at most one does.
- The sessions and slots are indexes into this request's grid. When the
  grid itself changed (a session removed from the middle), translate
  each position to the new grid first, and state a position the grid
  does not have, or none, for an event whose session is gone.

**Current positions alone change nothing.** A request that states them
and names no objective is repacked exactly as the same request without
them: the same assignments, unplaced events and violations, and the
same fingerprint. Only `getMovedEventIds()` knows the difference. To
have the repacker keep events where they are, name the objective.

### Objectives

`RepackOptions(objectives: [...])` names figures for the repacker to
keep low, most important first. There are three (`RepackObjective`):

| Objective | Keeps low | Identifier in plain data |
|---|---|---|
| `LeastChurn` | Moved events | `least_churn` |
| `FewestLoneEventSessions` | Lone-event sessions | `fewest_lone_event_sessions` |
| `FewestIdleSessions` | Idle sessions | `fewest_idle_sessions` |

```php
use MissionGaming\Tactician\Repack\RepackObjective;

$keeping = (new ScheduleRepacker())->repack(new RepackRequest(
    $outstanding,
    $fixed,
    $nights,
    new RepackOptions(objectives: [RepackObjective::LeastChurn])
));

echo json_encode($keeping->getMovedEventIds()), "\n";      // []
echo $keeping->getAssignmentFor('n08')?->getSession(), "\n";   // 2
echo $keeping->getAssignmentFor('n09')?->getSession(), "\n";   // 0
echo json_encode($rank($keeping)), "\n";                   // [0,0,1,0,0]

$together = (new ScheduleRepacker())->repack(new RepackRequest(
    $outstanding,
    $fixed,
    $nights,
    new RepackOptions(objectives: [RepackObjective::FewestLoneEventSessions])
));
echo json_encode($rank($together)), "\n";                  // [0,0,0,0,1]

echo json_encode(RepackOptions::fromArray(['objectives' => ['least_churn', 'fewest_idle_sessions']])->toArray()), "\n";
// {"format_version":1,"consolidation_weight":3,"early_fill_weight":1,"step_budget":200000,"throw_on_violations":false,"objectives":["least_churn","fewest_idle_sessions"]}
```

With the fewest moved events asked for, every event stays where it is
and the postponed one takes a free slot of the first session. Napoli now
sits out the middle session, which is the idle session in the rank: the
objective asked for was churn, and it was not traded for anything.

**Where objectives rank.** A repack places as many events as it can,
then leaves as few gap slots as it can, and no objective is ever paid
for with either: a request with objectives places at least as many
events as the same request without them, and where it places as many,
has no more gap slots. Among schedules level on those two, the
objectives decide in the order given, a later one only between schedules
level on every earlier one. The two weights and the late starts come
after every objective. An objective can be named once; its place in the
list is its rank.

**What each objective does, and how well.** None of the three is
optimised exactly, and they are not pursued alike:

- **`LeastChurn` is searched for.** The repacker first makes the
  schedule it would make with no objective. Then it holds every event
  that can stay at its current position there, as if pinned, and places
  the others around them. Where that places fewer events, it lets held
  events move again along the alternating paths of the last placement
  step until as many are placed; where it leaves more gap slots, the
  search for fewer gap slots moves the events around the gaps. Where the
  result is still worse than the schedule made with no objective, the
  held events that had to do with the difference are let go and it
  tries again, three times at most. The best schedule of all these, the
  one with no objective included, is returned. It needs events with
  current positions to act on.
- **`FewestLoneEventSessions` and `FewestIdleSessions` are a tie-break
  and a repair.** They decide between the schedules the repacker makes
  anyway where those are level on everything that ranks before them.
  Then single events are moved to a free position (the one event of a
  lone-event session to a session that has events, or another event
  into it; an event of a participant into a session that participant
  sits out) wherever that makes the figure smaller and makes nothing
  that ranks before it worse.

How close that comes was measured against every placement of 6,000 small
requests (three to six events on up to six positions), among the
placements that place the most events with the fewest gap slots:

| Objective | At the fewest there can be | Mean excess | Worst | With no objective |
|---|---|---|---|---|
| `LeastChurn` | 93.0% of the requests | 0.08 events | 3 | 35.3%, mean excess 1.16 |
| `FewestLoneEventSessions` | 96.8% | 0.03 sessions | 1 | 88.2%, mean excess 0.13 |
| `FewestIdleSessions` | 95.8% | 0.05 sessions | 2 | 90.3%, mean excess 0.14 |

No such reference exists for a request of real size, so there the
figures are compared with the same request and no objective. On 1,200
seasons of 8 to 40 participants with whole rounds played and pinned and
every other event at its place in the calendar (and then one of six
changes: none, a round postponed, a slot lost, new events, the last
session taken away, a few events re-pinned by hand), a repack with no
objective moved 302,521 of 322,566 events and `LeastChurn` moved 40,856,
with one event more placed in all and 1,150 gap slots for 1,556. On 440
round robins with pins at scattered positions it moved 50,071 for
81,394. `FewestIdleSessions` left 4,940 idle sessions for 6,340 on the
seasons and 6 for 263 on the scattered pins; `FewestLoneEventSessions`
left none of the 13 lone-event sessions of the seasons (a grid sized for
its events has few to begin with).

Three limits are worth knowing:

- **Contiguity comes before churn.** Where the schedule that exists has
  a gap that another schedule avoids (a round robin of an odd number
  has a participant sitting out a slot in the middle of a session), the
  events around the gap move, and where no schedule with few moves is
  found that is level on gap slots with the one made with no objective,
  the repacker returns that one, in which most events have moved.
- **Which events are left out is not chosen by an objective.** When not
  every event fits, the events that stay unplaced are the ones the
  placement steps leave, and a schedule that left out another event and
  moved one fewer is not looked for.
- **Without `LeastChurn`, the count of moved events can follow the
  ids.** The repacker orders participants by what the request says of
  them and lets the ids decide between participants that look alike to
  it ("The ids decide as little as they can", above). Two such
  participants can have different current positions, so the same
  request under other ids can come back with another number of moved
  events: the schedule is the same up to a symmetry of the request, and
  the current positions are not symmetric. Two events between four
  participants, on two sessions of one slot, `a`–`b` in session 1 and
  `c`–`d` in session 0, move none with no objective; renamed `w`–`x` and
  `c`–`d`, both move. With no objective, or with either of the other
  two, that count is the one figure renaming changed on the requests
  measured (60 of 400 seasons and 36 of 160 round robins with scattered
  pins).

  With `LeastChurn` the current positions decide before the ids:
  between participants that look alike, and between events of the same
  two participants. The same request under other ids then comes back
  with the same figures, the moved events among them, and the same
  events at every position and the same violations but for the names in
  them, under the condition of "The ids decide as little as they can":
  wherever the ids would decide only between participants that a
  symmetry of the request exchanges. Renaming every participant and
  event changed nothing on 400 seasons, 160 round robins with scattered
  pins and 2,895 requests of other shapes (double round robins among
  them), under `LeastChurn` alone and with all three objectives; before
  the current positions decided, 10 of the seasons and 9 of the round
  robins came back with other figures. The ids still decide where the
  current positions say nothing either (participants they do not tell
  apart even when each is put first in turn and the others are told
  apart by where they meet it, or no current positions at all), and in
  the two exceptions given there. Reading the current positions takes
  about twice the work of the ordering and has a limit of its own, twice
  the ordering's, so that both are sized for the same requests: a
  complete round robin of 40 with one round played and some events
  elsewhere gives the same figures under every naming (36, 38 and 40
  participants, three such requests of each, eight namings), and from
  42, past both limits, the count of moved events can follow the ids. The schedule made with no objective, which
  no outcome with an objective is worse than on events placed or gap
  slots, is made in this order too: the same schedule up to a symmetry
  of the request, and on all of those requests the same events placed,
  the same gap slots and the same number of events at every position as
  the repack with no objective.

**What objectives cost.** The schedule with no objective is always made
first, on the two budgets as before, so that no objective can cost an
event. Everything the objectives add runs on the steps those two budgets
have left, so a repack still spends at most twice `stepBudget`, and
`isBudgetExhausted()` means what it meant. The work that is not counted
in steps is done once for each schedule made, so a repack with
objectives takes longer. On the requests above (PHP 8.4, no OPcache,
on a machine doing other work, so read them as proportions), the median
and the 90th percentile in milliseconds:

| Requests | No objective | `LeastChurn` | Either of the other two, at most |
|---|---|---|---|
| Seasons of 8 to 15 participants | 4 / 261 | 6 / 463 | 4 / 274 |
| Seasons of 16 to 23 | 32 / 389 | 48 / 766 | 38 / 411 |
| Seasons of 24 to 31 | 211 / 1,010 | 258 / 1,354 | 211 / 1,087 |
| Seasons of 32 to 40 | 562 / 2,162 | 611 / 2,393 | 566 / 2,301 |
| Scattered pins, 8 to 15 | 39 / 421 | 329 / 1,278 | 25 / 446 |
| Scattered pins, 16 to 23 | 275 / 486 | 811 / 1,554 | 268 / 489 |
| Scattered pins, 24 to 31 | 477 / 893 | 1,172 / 2,126 | 497 / 859 |
| Scattered pins, 32 to 40 | 1,076 / 1,820 | 1,928 / 3,483 | 1,030 / 1,866 |

The fewest moved events cost little on a season, where nearly every
event is held and few are placed, and two to three times the repack on
scattered pins, where the schedule that exists rarely survives and the
attempts are whole repacks.

### The Shortest Grid

`ShortestGridFinder` answers "how many sessions does this take?". The
caller says what a grid of a given number of sessions is, as a function
from that number to a `SessionGrid` (which dates, how many slots, what
capacity: the library knows nothing of calendars), and the finder
returns a `ShortestGridResult`: the fewest sessions on which a repack
places every event, and what happened at every smaller number.

```php
use MissionGaming\Tactician\Repack\ShortestGridFinder;

$shortest = (new ShortestGridFinder())->find(
    movableEvents: $outstanding,
    pinnedEvents: $fixed,
    gridOf: static fn(int $sessions): SessionGrid => SessionGrid::shapeOnly($sessions, slotsPerSession: 2, capacityPerSlot: 2),
    maxSessions: 6
);

var_dump($shortest->isFound());                                // bool(true)
echo $shortest->getSessionCount(), "\n";                       // 3
echo json_encode($shortest->getRuledOut()), "\n";              // [1,2]
echo json_encode($shortest->getUnplacedBySessionCount()), "\n";   // {"3":0}
var_dump($shortest->isProvenShortest());                       // bool(true)
echo count($shortest->getOutcome()?->getAssignments() ?? []), "\n";   // 6

// One event to a slot: nine events need nine positions, and four
// sessions of two slots have eight
$tooShort = (new ShortestGridFinder())->find(
    $outstanding,
    $fixed,
    static fn(int $sessions): SessionGrid => SessionGrid::shapeOnly($sessions, slotsPerSession: 2),
    maxSessions: 4
);
var_dump($tooShort->isFound());                                // bool(false)
echo json_encode($tooShort->getRuledOut()), "\n";              // [1,2,3,4]
```

It tries the numbers one at a time, from `minSessions` (1 unless given)
to `maxSessions`, and stops at the first that places everything. It
never skips ahead: a search that halves the range would need "places
everything" to hold for every number above the first that works, and
nothing guarantees that. The caller's function may give a larger number
a grid that does not contain the smaller one, and the repacker is a
bounded search that can find a placement on one grid and miss one on a
larger. (On 320 generated requests the four numbers above the one found
placed everything too, every time. That is a measurement, not a
guarantee.)

What happened at each smaller number is in the result, because it
decides what the answer means:

- **Ruled out** (`getRuledOut()`): counting alone shows the events do
  not fit, so no repack was run. A pinned event is at a position that
  grid does not have, the grid has fewer places (positions times
  capacity) than the request has events, or a participant has more
  events than the grid has positions. That is a proof.
- **Repacked** (`getUnplacedBySessionCount()`): a repack ran and left
  that many events unplaced. That shows the repacker found no placement
  there, not that none exists.

`isProvenShortest()` is true when a grid was found and every smaller
number was ruled out by counting. Otherwise the number is the shortest
the repacker found. When no number up to `maxSessions` places
everything, `isFound()` is false and `getOutcome()` is the outcome on
the largest grid that was repacked (null when every number was ruled
out), which says how far short it is.

"Places everything" means no unplaced event and nothing more: the
outcome on the grid found can have gaps and late starts, and reports
them. The options are those of every repack the search runs, objectives
included, except that `throwOnViolations` is not applied. The cost is
one repack for each number that counting does not rule out. On the 320
requests above (seasons, scattered pins, uneven loads and round robins
of an odd number, 8 to 40 participants) the first number counting let
through placed everything on 319 and the second on the other one, so a
search cost one repack: 27 milliseconds at the median, 372 at the 90th
percentile and 1.6 seconds at most.

### The Step Budget

`RepackOptions(stepBudget: ...)` is counted in search steps, not in
time: the same request and budget give the same outcome on every
machine. There are two budgets of that size. The load planning and the
packing of each session spend from the first, and the search of one
session may spend no more than a quarter of what is left of it when it
starts, so that a session whose search does not end leaves steps for
the sessions after it. The last placement step, which runs only when an
event is still unplaced, and the search for fewer gap slots, which runs
only when a gap is left, spend from a second one of their own, so that
they still run when the searches before them spent theirs. The third
schedule, made only where the result leaves something to gain, has no
budget of its own: it is planned and packed with what the first has
left, and placed and searched with what the second has left. **A repack can therefore spend up to twice `stepBudget`.**

A step is not a fixed amount of work, so some searches count their own
work as well, in position checks (one look at whether an event fits a
position), and stop at a limit on it: the laying out of the events
before any session is chosen, which spends no step at all; the last
placement step, half a million checks on a request of up to 200 events
on 25 positions, a hundred for every event and position on a larger
one, and three million at most; and the search for fewer gap slots,
200,000. **The budget governs those limits too.** At the default budget
of 200,000 steps and at every larger one they are as stated, so a larger
budget never changes what those searches do. Below the default each is
the same share of its limit as the budget is of the default: a budget
of 20,000 steps gives every one of them a tenth of its position checks,
and the search for the events that two over-capacity participants share
a tenth of its nodes. The last placement step never has fewer than one
check for every event and position, whatever the budget. So a smaller
`stepBudget` shortens every search of a repack, and the default and
anything above it behave exactly alike in everything that is not
counted in steps.

When a budget runs out, or a search reaches a limit that a budget below
the default made smaller, the repacker stops searching and returns what
it has reached, with everything that is left reported as usual.
`isBudgetExhausted()` says whether that happened:

```php
var_dump($outcome->isBudgetExhausted());   // bool(false)

$starved = (new ScheduleRepacker())->repack(new RepackRequest(
    $request->getMovableEvents(),
    $request->getPinnedEvents(),
    $grid,
    new RepackOptions(stepBudget: 1)
));
var_dump($starved->isBudgetExhausted());   // bool(true)
```

- **True**: the budget cut at least one search short. Either a search
  wanted another step and was refused, or the budget was below the
  default and a search reached the share of its work limit that such a
  budget leaves it. A larger budget may give a different outcome for the
  same request. It is not a promise of a better one: the request may
  have no better packing, and the searches are heuristics.
- **False**: the budget stopped nothing, so a larger budget gives the
  same outcome. It does not mean the outcome is the best possible: at
  the default budget and above, a search that stops at its own work
  limit is not one the budget stopped, because no budget lifts that
  limit.

**What a budget buys, measured.** How long a repack takes depends on
the request and on the machine; the budget bounds the work and does not
turn into a number of seconds. These are the median and the longest
time in milliseconds, and the events placed, on PHP 8.4 with no OPcache
on one core. The seasons are round robins of one and two legs with a
fifth or a half of their rounds already played and pinned, on grids
that fit exactly, have a position to spare or are short (16 requests a
row); the scattered pins are 200 round robins of 16 to 40 participants
with an eighth to a half of their events pinned at positions drawn at
random.

| Request | 1 step | 2,000 steps | 20,000 steps | 200,000 steps (default) |
|---|---|---|---|---|
| Season of 20, one leg (1,944 events in all) | 9 / 30 ms, 1,769 | 9 / 25 ms, 1,824 | 9 / 79 ms, 1,824 | 8 / 501 ms, 1,824 |
| Season of 20, two legs (3,944) | 16 / 39 ms, 3,714 | 24 / 56 ms, 3,787 | 36 / 111 ms, 3,824 | 59 / 364 ms, 3,824 |
| Season of 24, one leg (2,808) | 13 / 27 ms, 2,562 | 20 / 37 ms, 2,639 | 19 / 70 ms, 2,664 | 21 / 527 ms, 2,664 |
| Season of 24, two legs (5,784) | 33 / 48 ms, 5,479 | 43 / 81 ms, 5,609 | 82 / 171 ms, 5,640 | 127 / 393 ms, 5,640 |
| Season of 30, one leg (4,464) | 22 / 38 ms, 4,110 | 38 / 54 ms, 4,217 | 42 / 176 ms, 4,284 | 58 / 360 ms, 4,284 |
| Season of 30, two legs (9,024) | 56 / 92 ms, 8,608 | 95 / 123 ms, 8,726 | 225 / 500 ms, 8,836 | 286 / 1,043 ms, 8,844 |
| Season of 36, one leg (6,504) | 35 / 59 ms, 6,061 | 60 / 91 ms, 6,202 | 61 / 120 ms, 6,288 | 85 / 449 ms, 6,288 |
| Season of 36, two legs (13,128) | 106 / 128 ms, 12,569 | 142 / 189 ms, 12,774 | 318 / 683 ms, 12,881 | 686 / 1,460 ms, 12,912 |
| Season of 40, one leg (8,024) | 43 / 67 ms, 7,495 | 74 / 107 ms, 7,688 | 91 / 371 ms, 7,783 | 173 / 922 ms, 7,784 |
| Season of 40, two legs (16,184) | 125 / 171 ms, 15,517 | 183 / 259 ms, 15,749 | 379 / 719 ms, 15,895 | 792 / 1,762 ms, 15,944 |
| Scattered pins (55,573) | 17 / 57 ms, 53,072 | 31 / 117 ms, 53,721 | 106 / 511 ms, 54,706 | 576 / 3,048 ms, 55,265 |
| The slowest request found: two legs among 29, 807 events, five pinned | 96 ms, 793 | 137 ms, 793 | 504 ms, 793 | 4,086 ms, 793 |

Read it this way. **The budget bounds the work of a repack, and the
bound follows the budget**: the most any search may do at 20,000 steps
is a tenth of what it may do at the default, and the longest time falls
with it (3 seconds to half a second on the scattered pins, 4 seconds to
half a second on the slowest request). It does not make every repack
take a tenth as long: a search that ended well inside its limit at the
default is not shortened by a tenth of that limit, and a phase has more
to do when the one before it was cut short, so the seasons of 40 over
two legs take half their default time at 20,000 steps. For the same
reason a smaller budget is a smaller bound and not a promise that one
request takes less time: where the default budget ends a search just
inside its limit and a smaller one cuts it off, the phases after it
have what it left to do, and the request can take longer than at the
default. The largest difference found on 4,666 generated requests at up
to 17 budgets was 80 events among 11 participants, five of them with more
events than the 12 positions: 36 milliseconds at the default, 160 to
180 at 100,000 steps, 100 at 50,000 and 7 at 1,000. At a budget of one
step the largest was a few milliseconds (12 for 11). What no budget
shortens is in the first column: reading the request, ordering the
participants (bounded by a fixed limit of its own, at most about a
tenth of a second and 12 milliseconds on these seasons, and up to
about a third of a second under the objective `LeastChurn`; it is left
whole so that the ids of a request decide no more at a small budget
than at a large one), the passes over the events that are not searches,
one position check for every event and position in the layout and the
last placement step, and a second plan where the first leaves something
to gain. That comes to about 0.04 seconds for a season of 40 over one
leg and 0.12 over two (1,560 events). A smaller `stepBudget` trades
events placed, gaps and late starts for time, as the columns show;
where a repack runs while a person waits, choose the budget from the
column that fits, and bound the size of the request too, because the
first column grows with it.

A budget below the default can also change what an outcome says about
capacity, where two or more participants have more events than free
positions. Every such participant is reported at every budget, with the
same demand and capacity: that is counted from the request and needs no
search. Which events are left out for them is searched for (an event
between two of them counts for both), and with a share of its nodes that
search can leave out more events than the shortfalls need. The grid's
own `CapacityExceeded`, the one with no participant, counts the events
left after those. So below the default it can state a smaller demand
than the default budget states, or be missing where the default budget
reports it, and an unplaced event can have the reason
`ParticipantOverCapacity` where the default budget gives it
`NoSlotAvailable`. `isBudgetExhausted()` is true for such an outcome. Of
6,000 generated requests of 5 to 11 events with over-capacity
participants, 201 have the grid's violation at the default budget; 31
of them came back without it at one step, 3 at 100 steps and none at
2,000. Read the capacity of the grid from an outcome whose flag is
false.

Requests that pack without a search cost the same at every budget: a
complete single round robin of 24 participants took 5 to 11
milliseconds over sessions of four to six slots, and one of 40
participants about 16 milliseconds, with no pins. A request that does
not come back clean costs more than its searches, because the repacker
then tries again: it runs the last placement step where events are left
over, and where the result still places fewer events than could be
placed, or has a gap, it plans and packs a third schedule. Sixty round
robins of 40 with 10 to 400 events pinned, on grids from too small to
roomy, took 0.75 seconds at the median and 3 seconds at most at the
default budget; round robins of two legs among 40 (1,460 events) with
100 pinned took 2 seconds at the median and 4.8 at most.

One thing is still not counted in steps or in position checks, and no
budget shortens it: sessions of many slots, on a request of many
participants with few events each, whose sessions do not pack. Before a
session's exact search the repacker works out a table of which start
slots can give gap-free runs at all, which grows with two to the power
of the slot count. That table is paid for in steps (16 steps for every
1,024 entries of a row, nothing for a session of up to seven slots),
and a session whose share of the budget cannot pay for it is packed
greedily, with `isBudgetExhausted()` true. The planning before it still
scores wide sessions by work that is not counted, which is what is left
at 16 slots and more: 800 events among 200 participants took 0.9
seconds on sessions of 12 slots, 2.6 seconds at 16, 10 seconds at 18
and 34 seconds at 20 at the default budget (0.2.3 took 0.3, 3.7 and 17
seconds on the first three, and left a third to three quarters more gap
slots). 20 slots is the widest session the repacker reasons about (a
wider session is packed greedily, and a request whose sessions pack
without a search costs milliseconds at most widths: a complete round
robin of 40 on sessions of 16 slots took 22 milliseconds, and 1.3
seconds at 19). It takes little memory now that the table is paid for:
16 MB at 18 slots and 54 MB at 20 on the request of 200 participants
above, which took 230 MB at 16 slots and 890 MB at 18 before. Give a
grid of sessions that wide a time limit of your own if the request is
made while a person waits. `composer bench` measures the machine at
hand.

The flag says nothing about violations: an outcome can be clean with the
flag true, and can carry violations with it false. It is not part of
`toArray()` or of the fingerprint, because it describes how the outcome
was reached and not the outcome.

### Outcome Fingerprints

`fingerprint()` returns a short string that is equal for two outcomes
holding the same assignments, unplaced events and violations, and
different when any of them differs. Its use is detecting that a plan has
changed: show an operator a preview, keep its fingerprint, and when the
operator confirms, repack again and compare before writing anything.

```php
echo $outcome->fingerprint(), "\n";
// v1:170a5b5c2b8386a00fa959d26afcd76fab43b8521e08106b3bdbbf2334dbc945

$recomputed = (new ScheduleRepacker())->repack($request);
var_dump($recomputed->fingerprint() === $outcome->fingerprint());   // bool(true)

// The same positions with no kickoffs are not the same plan
var_dump($shaped->fingerprint() === $outcome->fingerprint());       // bool(false)
```

The format is a contract. A fingerprint is a scheme, a colon and 64
lowercase hexadecimal digits: `v1:` (`RepackOutcome::FINGERPRINT_SCHEME`)
followed by the SHA-256 of the canonical document below. A change to the
canonical document is a new scheme, so compare two fingerprints only when
their schemes are equal, and recompute a stored fingerprint of another
scheme.

The canonical document of scheme `v1` is these bytes:

```
"tactician.repack.outcome.v1\n" SET(assignments) SET(unplaced) SET(violations)

SET(records)  = "l" COUNT ":" the VALUE of every record, in ascending byte order ";"
VALUE(null)   = "n;"
VALUE(bool)   = "b1;" for true, "b0;" for false
VALUE(int)    = "i" DECIMAL ";"
VALUE(float)  = "f" the 16 hexadecimal digits of the IEEE 754 double, big-endian ";"
VALUE(string) = "s" LENGTH-IN-BYTES ":" the bytes ";"
VALUE(list)   = "l" COUNT ":" the VALUE of every item, in the list's order ";"
VALUE(map)    = "m" COUNT ":" VALUE(key) VALUE(item) for every entry,
                the entries in ascending byte order ";"
```

Each of the three is a set of records, and a record is a map with exactly
these keys:

| Record | Keys |
|---|---|
| An assignment | `event_id`, `session`, `slot`, `kickoff` |
| An unplaced event | `event_id`, `reason`, `participant` |
| A `ParticipantDoubleBooked` | `kind`, `participant`, `session`, `slot`, `event_ids` |
| An `EventUnplaced` | `kind`, `event_id`, `reason`, `participant` |
| A `ContiguityBroken` | `kind`, `participant`, `session`, `gap_slots`, `occupied_slots` |
| A `LateStart` | `kind`, `participant`, `session`, `first_slot` |
| A `CapacityExceeded` | `kind`, `participant`, `demand`, `capacity`, `shortfall` |

Each key holds what `toArray()` of the record holds under it: an event ID
as a string, a session, a slot and a count as integers, a participant as
its ID or null, a kind and a reason as their backing strings, the lists
as lists, and a kickoff as the string `2026-08-12T19:00:00Z` or null. The
keys are named because they are the scheme: a key that a `toArray()`
gains in a later version is not part of scheme `v1` and does not change a
`v1` fingerprint.

`COUNT`, `LENGTH-IN-BYTES` and `DECIMAL` are ASCII decimal digits with no
leading zeros, `DECIMAL` with a leading `-` when negative. A list is an
array whose keys are 0, 1, 2 and so on in order; any other array is a
map. What is put in byte order is the encoded bytes: of each record in a
set, and of each entry (its key and item together) in a map, so the entry
of the key `slot` (`s4:slot;...`) comes before that of `kickoff`
(`s7:kickoff;...`). Byte order compares unsigned bytes, a shorter string
before a longer one that begins with it. An outcome with nothing in it
has the document `"tactician.repack.outcome.v1\nl0:;l0:;l0:;"`, and one
assignment of the event `e1` to session 0, slot 2 with no kickoff is the
record `m4:s4:slot;i2;s7:kickoff;n;s7:session;i0;s8:event_id;s2:e1;;`.

What follows from that:

- The order of the three lists does not matter, and neither does the
  order of the keys of a record. The order of a list inside a record does
  (the occupied slots of a `ContiguityBroken`, the event IDs of a
  `ParticipantDoubleBooked`); the repacker always writes those ascending.
- Every field the records have today is covered. A participant is covered
  by its ID and by nothing else of it, so a changed label or changed
  metadata leaves the fingerprint as it was. A kickoff is covered to the
  second and is null for a shape-only grid.
- `isBudgetExhausted()` is not covered: two outcomes holding the same
  plan are the same plan, however each search ended.
- Nothing in it depends on the PHP version, the platform, the locale or
  an ini setting.
- A violation of a class of your own is covered through its `toArray()`,
  the whole of it, so its fingerprint is as stable as that method's
  result. If that returns something the encoding has no form for (an
  object, a resource), `fingerprint()` throws an `InvalidInputException`.

### Weight Bounds

The repacker scores a move as an integer of at most
`earlyFillWeight × (sessions − 1) + 2 × consolidationWeight`. Weights for
which that is larger than `PHP_INT_MAX` are rejected, because PHP would
compute the score as a float, in which a large weight swallows a small
one and the result is no longer the trade the weights state:

- `RepackOptions` rejects a consolidation weight above
  `RepackOptions::MAX_CONSOLIDATION_WEIGHT` (half of `PHP_INT_MAX`,
  rounded down) with the reason `ValueOutOfRange`.
- `RepackRequest`, which has the grid, rejects weights for which the
  whole expression is too large for that grid's number of sessions, with
  the reason `IncompatibleOptions`.

Weights of ordinary size are nowhere near either bound: on a 64-bit
build the defaults would need a grid of more than nine quintillion
sessions.

### Repack API Reference

Every public class of the `MissionGaming\Tactician\Repack` namespace and
every public method on it. The classes under `Repack\Internal` are not
public API.

#### `ScheduleRepacker`

| Method | Returns |
|---|---|
| `repack(RepackRequest $request)` | The `RepackOutcome`. Throws a `RepackViolationsException` only when the options ask for it and the outcome is not clean. |

#### `RepackRequest`

`new RepackRequest(array $movableEvents, array $pinnedEvents, SessionGrid $grid, RepackOptions $options = new RepackOptions())`.
The order of the two lists carries no meaning. Input that contradicts
itself throws an `InvalidConfigurationException`; see
[Configuration Errors](#configuration-errors).

| Method | Returns |
|---|---|
| `getMovableEvents()` | The movable events, as given |
| `getPinnedEvents()` | The pinned events, as given |
| `getGrid()` | The `SessionGrid` |
| `getOptions()` | The `RepackOptions` |

#### `MovableEvent`

`new MovableEvent(string $id, Participant $participantA, Participant $participantB, ?int $currentSession = null, ?int $currentSlot = null)`.
The ID is not empty and the two participants differ. The last two are
the event's current position, given together or not at all, each 0 or
more; see [Current Positions and Moved Events](#current-positions-and-moved-events).

| Method | Returns |
|---|---|
| `getId()` | The caller's ID of the event |
| `getParticipantA()` | The first participant |
| `getParticipantB()` | The second participant |
| `getParticipants()` | Both, as a list of two |
| `hasCurrentPosition()` | Whether the event says where it is now |
| `getCurrentSession()` | The 0-based session it is in now, or null |
| `getCurrentSlot()` | The 0-based slot within that session, or null |

#### `PinnedEvent`

`new PinnedEvent(string $id, Participant $participantA, Participant $participantB, int $session, int $slot)`.

| Method | Returns |
|---|---|
| `getId()` | The caller's ID of the event |
| `getParticipantA()` | The first participant |
| `getParticipantB()` | The second participant |
| `getParticipants()` | Both, as a list of two |
| `getSession()` | The 0-based session the event is pinned in |
| `getSlot()` | The 0-based slot within that session |

#### `SessionGrid`

`new SessionGrid(array|int $sessionStarts, ?DateInterval $slotInterval, int $slotsPerSession = 1, array $slotsPerSessionOverrides = [], ?int $capacityPerSlot = 1)`
builds an instant-based grid from a list of session starts and a slot
interval. The integer and the null in the first two types are how
`shapeOnly()` reaches the constructor (a session count and no interval);
do not pass them yourself, call `shapeOnly()`. The constant `SessionGrid::UNBOUNDED` is
the plain-data word for an unbounded capacity; `SessionGrid::MAX_POSITIONS`
(100,000) is the most positions, sessions or slots of one session a grid
can have, and `SessionGrid::MAX_CAPACITY_PER_SLOT` (a million million) the
largest capacity that is a number.

| Method | Returns |
|---|---|
| `shapeOnly(int $sessions, int $slotsPerSession = 1, array $slotsPerSessionOverrides = [], ?int $capacityPerSlot = 1)` | A shape-only grid (static) |
| `fromArray(array $config)` | A grid of either form from plain data (static) |
| `toArray()` | The plain data `fromArray()` accepts, with `format_version` first |
| `hasInstants()` | False for a shape-only grid |
| `getSessionCount()` | How many sessions |
| `getSlotCount(int $session)` | How many slots that session has, overrides applied |
| `getPositionCount()` | How many positions the grid has in all |
| `hasPosition(int $session, int $slot)` | Whether the grid has that position |
| `ordinalOf(int $session, int $slot)` | The position's 0-based index in grid order |
| `positionOf(DateTimeImmutable\|int $at)` | `['session' => ..., 'slot' => ...]` for an ordinal or an instant, or null |
| `getCapacityLimit()` | Events per slot, or null when unbounded |
| `hasUnboundedCapacity()` | Whether the capacity is unbounded |
| `getCapacityPerSlot()` | Events per slot as an integer; throws an `UnavailableValueException` when unbounded |
| `getSessionStart(int $session)` | The session's start as declared; throws an `UnavailableValueException` on a shape-only grid |
| `getSlotInterval()` | The time between slots; throws an `UnavailableValueException` on a shape-only grid |
| `getSlotTime(int $session, int $slot)` | The slot's time in UTC; throws an `UnavailableValueException` on a shape-only grid |

#### `RepackOptions`

`new RepackOptions(int $consolidationWeight = 3, int $earlyFillWeight = 1, int $stepBudget = 200_000, bool $throwOnViolations = false, array $objectives = [])`.
The first four are public read-only properties: `$consolidationWeight`,
`$earlyFillWeight`, `$stepBudget` and `$throwOnViolations`. So is the
fifth, `$objectives`, a list of `RepackObjective` cases in order of
importance with no case twice. The constant
`RepackOptions::MAX_CONSOLIDATION_WEIGHT` is the largest consolidation
weight.

| Method | Returns |
|---|---|
| `fromArray(array $config)` | Options from plain data with the keys `consolidation_weight`, `early_fill_weight`, `step_budget`, `throw_on_violations` and `objectives`, a list of objective identifiers (static) |
| `toArray()` | The same plain data, with `format_version` first; `objectives` is written only when there is one |

#### `RepackObjective`

An enum. Cases: `LeastChurn` (`least_churn`), `FewestLoneEventSessions`
(`fewest_lone_event_sessions`) and `FewestIdleSessions`
(`fewest_idle_sessions`). See [Objectives](#objectives).

#### `RepackOutcome`

The constant `RepackOutcome::FINGERPRINT_SCHEME` is the scheme
`fingerprint()` writes.

| Method | Returns |
|---|---|
| `getAssignments()` | A `SlotAssignment` for every placed movable event, ordered by event ID. Pinned events are not in it. |
| `getAssignmentFor(string $eventId)` | That event's `SlotAssignment`, or null when it is unplaced or unknown |
| `getUnplaced()` | An `UnplacedEvent` for every movable event with no position, ordered by event ID |
| `getViolations()` | Every `RepackViolation`, ordered by kind |
| `getViolationsOfKind(ViolationKind $kind)` | The violations of that kind |
| `getParticipantDoubleBookedViolations()` | The `ParticipantDoubleBooked` violations |
| `getEventUnplacedViolations()` | The `EventUnplaced` violations |
| `getContiguityBrokenViolations()` | The `ContiguityBroken` violations |
| `getLateStartViolations()` | The `LateStart` violations |
| `getCapacityExceededViolations()` | The `CapacityExceeded` violations |
| `getUnplacedCount()` | How many movable events have no position |
| `getGapSlotCount()` | The gap slots of every `ContiguityBroken`, added up |
| `getLoneEventSessions()` | The sessions holding one event and no more, that one movable, ascending |
| `getLoneEventSessionCount()` | How many of those there are |
| `getIdleSessionCount()` | The idle sessions, summed over the participants |
| `getMovedEventIds()` | The IDs of the events placed anywhere but at their current position, ascending |
| `getMovedCount()` | How many of those there are |
| `isClean()` | Whether nothing is unplaced and there is no violation |
| `isBudgetExhausted()` | Whether the step budget stopped a search |
| `fingerprint()` | The outcome's fingerprint |
| `toArray()` | `['format_version' => 1, 'assignments' => ..., 'unplaced' => ..., 'violations' => ..., 'metrics' => ...]`, each list holding the `toArray()` of its records without their own `format_version` (see [Format Versions](#format-versions)), and `metrics` the five figures: `unplaced`, `gap_slots`, `lone_event_sessions`, `idle_sessions`, `moved` |

Building one by hand takes the three lists and, optionally, the budget
flag and the three figures an outcome cannot work out from its lists:
`new RepackOutcome(array $assignments, array $unplaced, array $violations, bool $budgetExhausted = false, array $loneEventSessions = [], int $idleSessions = 0, array $movedEventIds = [])`.
A list that holds anything other than the objects or values it is for,
or a negative count of idle sessions, throws an `InvalidInputException`.
An outcome built without the figures has none: no lone-event session, no
idle session, no moved event.

#### `SlotAssignment`

`new SlotAssignment(string $eventId, int $session, int $slot, ?DateTimeImmutable $kickoff)`.
The repacker builds these; the constructor is for an outcome built by
hand, in a test for example.

| Method | Returns |
|---|---|
| `getEventId()` | The movable event's ID |
| `getSession()` | The 0-based session |
| `getSlot()` | The 0-based slot within the session |
| `hasKickoff()` | False when the grid is shape-only |
| `getKickoff()` | The position's time, in UTC on an assignment the repacker made (an assignment built by hand keeps the time it was given, in the timezone it was given in); throws an `UnavailableValueException` when there is none |
| `toArray()` | `format_version`, `event_id`, `session`, `slot`, and `kickoff` as `2026-08-12T19:30:00Z` or null. The kickoff is converted to UTC, whatever timezone it is held in |

#### `UnplacedEvent`

`new UnplacedEvent(string $eventId, UnplacedReason $reason, ?Participant $participant = null)`.

| Method | Returns |
|---|---|
| `getEventId()` | The movable event's ID |
| `getReason()` | The `UnplacedReason` |
| `getParticipant()` | The participant with too many events, or null |
| `toArray()` | `format_version`, `event_id`, `reason` and `participant` (its ID, or null) |

#### `UnplacedReason`

An enum. Cases: `ParticipantOverCapacity` (`participant_over_capacity`)
and `NoSlotAvailable` (`no_slot_available`).

#### `ViolationKind`

An enum. Cases: `ParticipantDoubleBooked`, `EventUnplaced`,
`ContiguityBroken`, `LateStart` and `CapacityExceeded`, with the backing
strings in the table under [Reading the Outcome](#reading-the-outcome).

#### `RepackViolation`

The interface the five violation classes implement. A class of your own
may implement it too, in an outcome built by hand; the repacker returns
the five only. Such a violation is in `getViolations()` and in
`getViolationsOfKind()` for the kind it states, and in none of the five
typed accessors, each of which returns objects of its own class.

| Method | Returns |
|---|---|
| `getKind()` | The `ViolationKind` |
| `toArray()` | Plain data, always with a `kind` key holding the kind's backing string. The library's violations write `format_version` first |

#### `ParticipantDoubleBooked`

`new ParticipantDoubleBooked(Participant $participant, int $session, int $slot, array $eventIds)`.

| Method | Returns |
|---|---|
| `getKind()` | `ViolationKind::ParticipantDoubleBooked` |
| `getParticipant()` | The participant booked twice |
| `getSession()` | The session of the position |
| `getSlot()` | The slot of the position |
| `getEventIds()` | The IDs of the events that collide, sorted |
| `toArray()` | `format_version`, `kind`, `participant`, `session`, `slot` and `event_ids` |

#### `EventUnplaced`

`new EventUnplaced(string $eventId, UnplacedReason $reason, ?Participant $participant = null)`.

| Method | Returns |
|---|---|
| `getKind()` | `ViolationKind::EventUnplaced` |
| `getEventId()` | The movable event's ID |
| `getReason()` | The `UnplacedReason` |
| `getParticipant()` | The participant with too many events, or null |
| `toArray()` | `format_version`, `kind`, `event_id`, `reason` and `participant` |

#### `ContiguityBroken`

`new ContiguityBroken(Participant $participant, int $session, int $gapSlots, array $occupiedSlots)`.

| Method | Returns |
|---|---|
| `getKind()` | `ViolationKind::ContiguityBroken` |
| `getParticipant()` | The participant whose slots have a gap |
| `getSession()` | The session |
| `getGapSlots()` | How many slots between the participant's first and last are empty |
| `getOccupiedSlots()` | The slots the participant occupies in the session, pins included, ascending |
| `toArray()` | `format_version`, `kind`, `participant`, `session`, `gap_slots` and `occupied_slots` |

#### `LateStart`

`new LateStart(Participant $participant, int $session, int $firstSlot)`.

| Method | Returns |
|---|---|
| `getKind()` | `ViolationKind::LateStart` |
| `getParticipant()` | The participant who starts late |
| `getSession()` | The session |
| `getFirstSlot()` | The first slot the participant occupies in it |
| `toArray()` | `format_version`, `kind`, `participant`, `session` and `first_slot` |

#### `CapacityExceeded`

`new CapacityExceeded(?Participant $participant, int $demand, int $capacity)`.

| Method | Returns |
|---|---|
| `getKind()` | `ViolationKind::CapacityExceeded` |
| `getParticipant()` | The participant with more events than free positions, or null when it is the grid that is too small |
| `getDemand()` | How many positions were needed |
| `getCapacity()` | How many there are |
| `getShortfall()` | How many events do not fit: the demand less the capacity, never below zero |
| `toArray()` | `format_version`, `kind`, `participant`, `demand`, `capacity` and `shortfall` |

#### `ShortestGridFinder`

`new ShortestGridFinder(ScheduleRepacker $repacker = new ScheduleRepacker())`.

| Method | Returns |
|---|---|
| `find(array $movableEvents, array $pinnedEvents, callable $gridOf, int $maxSessions, int $minSessions = 1, RepackOptions $options = new RepackOptions())` | A `ShortestGridResult`. `$gridOf` takes a number of sessions and returns the `SessionGrid` of that many. Throws an `InvalidConfigurationException` for a range that is not `1 <= $minSessions <= $maxSessions` (`ValueOutOfRange`), a function that returns something other than a grid (`WrongValueType`) or a grid of another number of sessions (`IncompatibleOptions`), and whatever a `RepackRequest` throws for the events |

#### `ShortestGridResult`

`new ShortestGridResult(?int $sessionCount, ?SessionGrid $grid, ?RepackOutcome $outcome, array $unplacedBySessionCount, array $ruledOut)`.
The finder builds these; the constructor is for a result built by hand.

| Method | Returns |
|---|---|
| `isFound()` | Whether a number of sessions placed every event |
| `getSessionCount()` | That number, or null |
| `getGrid()` | The grid of that many sessions, or null |
| `getOutcome()` | The outcome on that grid; when nothing was found, the outcome on the largest grid repacked, or null when none was |
| `getUnplacedBySessionCount()` | Number of sessions => events left unplaced, for every repack that ran, ascending |
| `getRuledOut()` | The numbers of sessions refused by counting, without a repack, ascending |
| `isProvenShortest()` | Whether a grid was found and every smaller number was ruled out by counting |

See `examples/19-repacking-a-season.php` for a complete runnable
walkthrough, `examples/29-rescheduling-with-least-churn.php` for current
positions, objectives and the shortest grid, and
`docs/design/schedule-repack.md` for the algorithm and its design
decisions.

## Schedule Quality and Optimization

Constraints are hard filters — a schedule satisfies them or generation
fails. **Quality metrics** measure the graded properties two valid
schedules can still differ on. Every metric follows one convention:
**lower is better, zero is ideal** — metrics measure defects:

| Metric | Measures |
|---|---|
| `RoleBalanceMetric` | How unevenly appearances split between the first role (home, white, server...) and the second |
| `RoleStreakMetric` | Longest run of consecutive same-role appearances beyond strict alternation |
| `RestSpreadMetric` | How irregularly the gaps between each participant's playing rounds vary |
| `PairingSpacingMetric` | How far each pair's repeat meetings deviate from an even spread across the rounds the schedule spans, from its lowest round number to its highest |

`ScheduleScorer` composes metrics with weights — which metrics matter is
your policy, the scorer is the arithmetic — and reports per-metric
values so a chosen schedule is explainable:

```php
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Quality\PairingSpacingMetric;
use MissionGaming\Tactician\Quality\RoleBalanceMetric;
use MissionGaming\Tactician\Quality\ScheduleScorer;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

// The schedule to grade: a single-leg round robin, in which each of the
// four participants has three events and so cannot split roles evenly:
// one apart is the least there is, and the default roles give exactly that
$participants = [
    new Participant('celtic', 'Celtic'),
    new Participant('athletic', 'Athletic Bilbao'),
    new Participant('livorno', 'AS Livorno'),
    new Participant('redstar', 'Red Star FC'),
];
$schedule = (new RoundRobinScheduler())->schedule($participants);

$scorer = new ScheduleScorer([
    ['metric' => new RoleBalanceMetric(), 'weight' => 3.0],
    ['metric' => new PairingSpacingMetric(), 'weight' => 1.0],
]);

$score = $scorer->score($schedule);   // 3.0: the weighted defect score (3.0 x 1.0 + 1.0 x 0.0)
$report = $scorer->report($schedule); // ['Role Balance' => 1.0, 'Pairing Spacing' => 0.0]
```

A weight is a positive, finite number, and every score is finite (zero is
the ideal one). The scorer
throws `InvalidConfigurationException` for a weight of `NAN` or `INF` when
it is built, and for a metric of your own that measures `NAN` or `INF`, or a
weighted sum that overflows, when it scores: a score that cannot be
compared cannot be ranked.

`ScheduleOptimizer` generates N candidates and keeps the best-scoring
one. The generators are deterministic given a randomizer, so sampling
schedules is sampling seeds — one master randomizer derives a child per
sample, and the same master seed always reproduces the same winner:

```php
use MissionGaming\Tactician\LegStrategies\ShuffledLegStrategy;
use MissionGaming\Tactician\Quality\ScheduleOptimizer;
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use Random\Engine\Mt19937;
use Random\Randomizer;

$optimizer = new ScheduleOptimizer($scorer, new Randomizer(new Mt19937(2026)));

$result = $optimizer->optimize(
    fn (Randomizer $r) => (new RoundRobinScheduler(null, $r))->schedule(
        $participants,
        new RoundRobinOptions(legs: 2, strategy: new ShuffledLegStrategy($r))
    ),
    25
);

$result->getSchedule();         // the winner
$result->getScore();            // its weighted score
$result->getReport();           // its per-metric breakdown
$result->getSamplesGenerated(); // candidates that generated successfully
```

Three rules of the mechanism:

- **Thread the child randomizer everywhere.** Determinism holds only if
  every randomness source in the generation pipeline uses the supplied
  child — the scheduler *and* anything else that randomizes (note the
  `ShuffledLegStrategy($r)` above). An unseeded source anywhere makes
  sampling unrepeatable.
- **Failed samples are skipped, not fatal.** A sample whose generation
  throws an `IncompleteScheduleException` (a shuffled ordering no retry can
  fix) is counted in `getSamplesFailed()`; only zero valid candidates is an
  error, in which case the last generation failure is rethrown with its
  diagnostics. Any other exception from the generator ends the run at once.
- **The first of equal scores wins.** A later sample replaces the best so
  far only when it scores lower.

Optimization applies to whole-schedule generators only — results-driven
engines (Swiss, elimination) pair from results that do not exist yet, so
there is nothing to sample up front. Custom metrics implement the
two-method `QualityMetric` interface.

## Serialization

Schedules round-trip through JSON; participants are listed once and
referenced by ID, so restored schedules share participant instances:

```php
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

$schedule = (new RoundRobinScheduler())->schedule([
    new Participant('celtic', 'Celtic'),
    new Participant('athletic', 'Athletic Bilbao'),
    new Participant('livorno', 'AS Livorno'),
    new Participant('redstar', 'Red Star FC'),
]);

$json = $schedule->toJson();
$restored = Schedule::fromJson($json);
```

`Participant`, `Round`, `Event`, `Result` and `Schedule` all expose
`toArray()`/`fromArray()` for custom persistence. So do `Award` and
`Adjustment`. A result writes its `award` key, and a stage state its
`adjustments` key, only when there is something to write: the array of a
played result and of a state without adjustments has neither, and data
stored without them reads as played and as unadjusted.

What a round trip keeps is what JSON can hold:

- **Metadata must be JSON data.** Scalars, lists and maps come back as they
  were. An object in metadata comes back as whatever its JSON form decodes
  to, and a float with no fraction (`1.0`) comes back as an integer.
- **The entrant list is kept, in its order.** A schedule knows its
  entrants: `getEntrants()` returns the list a generator was given, in that
  order, which is the seeding where position decides. A serialized schedule
  lists them as its `participants`, each once, in that order, an entrant in
  no event included, and `fromArray()` and `fromJson()` give the same list
  back. A schedule built from events alone (`new Schedule($events)`) has no
  list to keep, so its entrants are the participants in the order they
  first appear in the events; pass the list as the third argument,
  `new Schedule($events, $metadata, $entrants)`, to state it. A schedule
  that 0.2.x stored has its participants in that first-appearance order,
  and loads with them as its entrants: the order the generator was given
  was not written then and cannot be recovered from the data.

Text that is not valid JSON is reported by a `JsonConversionException` (a
`\JsonException`), and valid JSON or an array with an entry of the wrong
shape by an `InvalidInputException` (an `\InvalidArgumentException`). A
missing top-level key is not an error: `Schedule::fromArray([])` is an empty
schedule. Both exceptions are covered by
`catch (TacticianException)`: see [Error Handling](#exception-hierarchy).

### Format Versions

Every array a `toArray()` returns, and so every JSON string a `toJson()`
writes, starts with the key `format_version`: an integer that says which
version of its form the array is written in. Every form is at version 1
today.

```php
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\InvalidInputException;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Scheduling\SwissOptions;

$stored = (new RoundRobinScheduler())->schedule([
    new Participant('celtic', 'Celtic'),
    new Participant('athletic', 'Athletic Bilbao'),
])->toArray();

echo implode(', ', array_keys($stored)), "\n";              // format_version, participants, events, metadata
echo $stored['format_version'], "\n";                       // 1
echo implode(', ', array_keys($stored['events'][0])), "\n"; // participants, round, metadata

echo json_encode((new SwissOptions(rounds: 5))->toArray()), "\n"; // {"format_version":1,"rounds":5}

// Stored by 0.2.x, which wrote no version: read as version 1
unset($stored['format_version']);
echo count(Schedule::fromArray($stored)), "\n";             // 1

// Written by a release that has changed the form
try {
    Schedule::fromArray(['format_version' => 2, 'events' => []]);
} catch (InvalidInputException $exception) {
    echo $exception->getMessage(), "\n";
    // Unsupported format version 2 in schedule data: this version of the library reads version 1.
    // The data was written by a later release; upgrade the library to read it
}
```

The rule, which [ADR 0005](adr/0005-serialized-forms-carry-a-format-version.md)
records with its reasons:

- **The key is written once to an array, at its top.** The participants and
  events inside a schedule, the round inside an event, the rounds,
  results and adjustments inside a stage state, the award and the decider
  inside a result, the decisions inside a rank selector and the records inside a repack outcome carry none: they are read under the version of the array that holds them.
  A part serialized by itself (`$participant->toArray()`) is the top of its
  own array and has the key.
- **An array without the key is version 1.** Version 1 is the form 0.2.x
  wrote, and beside it the optional keys 0.3.0 added (`strategy_seed` in
  round-robin options, `seed` in Swiss options, `ties`, `comparison` and
  `decisions` in a rank range selector, `third_place` in elimination
  options, `award` and `decider` in a result, `adjustments`, `entrants` and
  `withdrawals` in a stage state, `after_round` in an adjustment,
  `paired_on_incomplete_results` in a round pairing,
  `bye` in a win-draw-loss ranking), each of which may be left out. So rows
  stored by 0.2.x load
  unchanged. There is nothing to migrate, and no need to add the key to
  stored data. One stored form loads unchanged and means something else:
  round-robin options without a `role_assignment` key name no role
  assignment, and give roles that are balanced where possible from 0.3.0
  where they gave round-parity roles before. Nothing is refused, so a schedule generated
  from them again has other roles without an error; add
  `'role_assignment' => 'round_parity'` to stored options that must keep
  the old ones ([Role Assignment](#role-assignment)). The form is still
  version 1; ADR 0005 says why, and that a later change of this default
  would be version 2.
- **A version the library does not know is refused**, as is a value below 1
  or one that is not an integer: configuration with an
  `InvalidConfigurationException` (reason `UnsupportedFormatVersion`), data
  with an `InvalidInputException`. A higher version means a later release
  wrote the array; upgrade the library that reads it.
- **Forms are versioned one by one.** When a release changes a form, that
  form takes the next number, the changelog says what differs, and
  `fromArray()` keeps reading the earlier versions. A patch release never
  changes a form.
- **An earlier release does not check the version.** 0.2.x reads an array
  written by 0.3.0 as it read its own, ignoring `format_version` and every
  other key it does not know, so an application that rolls back keeps
  reading its rows. The exception is `PotDrawOptions::fromArray()` of 0.2.2
  and 0.2.3, which refuses the key: remove it from stored pot draw options
  before reading them with those releases. What 0.2.x has no key for it
  does not see: it reads an awarded result as one that was played, a
  stage state without its adjustments, and a level tie settled by a decider
  as one that nobody settled.

`format_version` is not the `v2` inside an engine fingerprint
([Recording Which Engine Pairs a State](#recording-which-engine-pairs-a-state))
or the `v1` in front of an outcome fingerprint
([Outcome Fingerprints](#outcome-fingerprints)). Those version the scheme
each fingerprint is built by, and each changes without the other: 0.3.0
moved the engine fingerprint scheme from `v1` to `v2`, and the stage state
that holds a stamp is still format version 1. An outcome fingerprint does
not cover `format_version`, so adding the key changed no fingerprint.

### Keys a Form Does Not Have

Every `fromArray()` in the library refuses an array that holds a key its
form does not have. A misspelt key is otherwise read as the key left out,
which for an option is its default and for a result with a misspelt
`winner` is a draw, and nothing says so.

```php
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidInputException;
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;

try {
    RoundRobinOptions::fromArray(['legs' => 2, 'stratgy' => 'repeated']);
} catch (InvalidConfigurationException $exception) {
    echo $exception->getReason()?->value, "\n";                  // unknown_option_key
    echo implode(', ', $exception->getContext()['unknown']), "\n"; // stratgy
    echo implode(', ', $exception->getContext()['known']), "\n";
    // format_version, legs, strategy, backtracking, strategy_seed, role_assignment
}

try {
    Participant::fromArray(['id' => 'celtic', 'label' => 'Celtic', 'seeding' => 1]);
} catch (InvalidInputException $exception) {
    echo $exception->getMessage(), "\n";
    // Unknown key in participant data: 'seeding' (the known keys are 'format_version', 'id', 'label', 'seed', 'metadata')
}
```

The two kinds of form report it with the exception they report every other
fault of their array with:

- **Configuration** (the options classes, `WinDrawLossRanking`,
  `AwardedResultPolicy`, `TimelineDefinition`, `SessionGrid`, the timeline rules and the progression
  selectors, and each window of a `BlackoutRule`) throws an
  `InvalidConfigurationException` with the reason `UnknownOptionKey`. Its
  context lists the keys that are not known under `unknown` and the ones the
  form has under `known`.
- **Data** (`Participant`, `Round`, `Event`, `Result`, `Award`, `Decider`, `Schedule`,
  `RoundPairing`, `StageState`, `Adjustment`, `ScheduledEvent` and
  `ScheduledSchedule`,
  nested in one another or on their own) throws an `InvalidInputException`
  whose message names both.

Data of your own does not go beside the library's keys. Put it in the
`metadata` of the participant, the round, the event, the result or the
schedule it belongs to, which is returned as it was given, or keep it beside
the array and not in it.

A known key whose value is `null` is read as the key left out, wherever a
key may be left out: `['legs' => null]` is one leg, and a `seed` of `null`
is no seed. `PotDrawOptions` is the exception: its three keys are integers
or absent.

### A Stored Stage State Is Checked as It Was Recorded

`StageState::fromArray()` and `fromJson()` do not trust the history they are
given. The state is recorded again through `withRoundPlayed()`, round by
round, so whatever that verb refuses of a live state is refused of a stored
one, with the same `InvalidConfigurationException` and the same reason:

| The stored data | Reason |
| --- | --- |
| A round numbered below 1 | `ValueOutOfRange` |
| Rounds not in play order, or one round number twice | `RoundOutOfSequence` |
| A pairing that holds an event of another round | `EventNotInRound` |
| A pairing that holds an event with no round | `EventWithoutRoundNumber` |
| A result for an event its round does not hold | `EventNotInRound` |
| A result of a round that is not recorded, or one that does not stand with the other results of its round | `EventNotInRound` |
| A result whose event has no round | `EventWithoutRoundNumber` |

```php
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Scheduling\SwissPairingEngine;
use MissionGaming\Tactician\Stage\StageState;

// Two rounds recorded without results, which is results-free mode
$engine = new SwissPairingEngine(resultsFree: true);
$state = StageState::start([
    new Participant('celtic', 'Celtic'),
    new Participant('athletic', 'Athletic Bilbao'),
    new Participant('livorno', 'AS Livorno'),
    new Participant('redstar', 'Red Star FC'),
]);
foreach ([1, 2] as $round) {
    $state = $state->withRoundPlayed($engine->pairNextRound($state), []);
}

$stored = $state->toArray();
echo count(StageState::fromArray($stored)->getRoundsPlayed()), "\n"; // 2

// The same data with its rounds the other way round
$stored['rounds'] = array_reverse($stored['rounds']);
try {
    StageState::fromArray($stored);
} catch (InvalidConfigurationException $exception) {
    echo $exception->getReason()?->value, "\n"; // round_out_of_sequence
}
```

What the verbs accept is accepted from storage too: a gap in the round
numbers, a pairing whose participants are no longer active, and two results
of one event. (`StageState::fromHistory()` refuses the first and the last:
it reads rows the verbs did not write, and holds them to
[three rules more](#stages-resolved-from-your-own-rows).) Two results of one event are a state to repair and not one to read tables from
(the standings and the bracket engines refuse it), and it has to load to be
repaired: see [Correcting a Recorded Result](#correcting-a-recorded-result).
A state that `toArray()` or `toJson()` wrote is always one the verbs
recorded, so stored states are affected only where something else wrote or
edited the data.

## Schedule Validation

Every whole-schedule generator checks the schedule it built against its stage plan before it returns it: the number of events, then the plan's own integrity rules. A schedule that falls short is never returned; the generator throws an `IncompleteScheduleException`.

### Basic Validation

```php
use MissionGaming\Tactician\Constraints\ConsecutiveRoleConstraint;
use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

$participants = [
    new Participant('celtic', 'Celtic'),
    new Participant('athletic', 'Athletic Bilbao'),
    new Participant('livorno', 'AS Livorno'),
    new Participant('redstar', 'Red Star FC'),
];

// Configure restrictive constraints that might prevent complete scheduling
$constraints = ConstraintSet::create()
    ->add(ConsecutiveRoleConstraint::homeAway(1))  // Very restrictive
    ->build();

try {
    $scheduler = new RoundRobinScheduler($constraints);
    $schedule = $scheduler->schedule($participants);
    
    // If we get here, the schedule is complete and valid
    echo "Schedule generated successfully with " . count($schedule) . " events\n";
    
} catch (IncompleteScheduleException $e) {
    // Schedule validation caught an incomplete tournament
    echo "Cannot generate complete schedule: " . $e->getMessage() . "\n";
    
    // Get diagnostic information
    $violations = $e->getViolationCollector()->getViolations();
    foreach ($violations as $violation) {
        echo "Violation: " . $violation->getDescription() . "\n";
    }
    
    // Get expected vs actual event counts
    echo "Expected events: " . $e->getExpectedEventCount() . "\n";
    echo "Generated events: " . $e->getActualEventCount() . "\n";
}
```

### Validation Features

```php
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;

try {
    $scheduler = new RoundRobinScheduler($constraints);
    $schedule = $scheduler->schedule($participants);
    
} catch (InvalidConfigurationException $e) {
    // Invalid scheduler configuration
    echo "Configuration error: " . $e->getMessage() . "\n";
    echo "Context: " . json_encode($e->getContext()) . "\n";
    
} catch (IncompleteScheduleException $e) {
    // Schedule incomplete due to constraint conflicts
    echo "Incomplete schedule: " . $e->getMessage() . "\n";
    
    // Generate diagnostic report
    $diagnostics = $e->getDiagnosticReport();
    echo $diagnostics . "\n";
}
```

## Framework Integration

Tactician has zero production dependencies and its entry points are
stateless objects, so a framework has little to wire. The work of an
integration is the adapter an application writes around the library, and
the guides describe that:

- **[Symfony](integrations/symfony.md)**: participants in and output
  copied into the application's own records, results-driven stages with the
  engine fingerprint, repacking with a preview and a confirmation, errors
  and time.
- **[Laravel](integrations/laravel.md)**: the three places where the
  framework shows; it refers to the Symfony guide for the rest.

The framework-free core of each pattern is runnable: a stage living across
request cycles with `StageState` serialized between them
([`examples/18-stateless-web-flow.php`](../examples/18-stateless-web-flow.php)),
an application's records in and out with a repack previewed and confirmed
([`examples/23-application-adapter-and-repack.php`](../examples/23-application-adapter-and-repack.php)),
and results recorded on a bracket
([`examples/24-recording-bracket-results.php`](../examples/24-recording-bracket-results.php)).

## Error Handling

### Constraint Attribution

A generation failure carries a probed analysis of *which constraint
blocks which pairing where* — not a guess from constraint names, an
evaluation: every missing pairing is tested against every configured
constraint in each candidate round and both orientations, against the
schedule that was actually generated. The diagnostic report renders it
directly:

```text
=== BLOCKED PAIRINGS ===
• Team 1 vs Team 2 cannot join the generated schedule in any round (blocked by: Derby Ban)

=== CONSTRAINT ATTRIBUTION ===
• Derby Ban rejects Team 1 vs Team 2 in 3 of 3 rounds
```

Programmatic access goes through the attached report. The configuration
below is the one that produces the report above — a round robin needs
every pair to meet, so banning one pairing cannot be satisfied:

```php
use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

$participants = [
    new Participant('team1', 'Team 1'),
    new Participant('team2', 'Team 2'),
    new Participant('team3', 'Team 3'),
    new Participant('team4', 'Team 4'),
];

// Team 1 and Team 2 may never meet
$derbyBan = ConstraintSet::create()
    ->custom(
        fn (Event $event) => !($event->hasParticipant($participants[0]) && $event->hasParticipant($participants[1])),
        'Derby Ban'
    )
    ->build();

$scheduler = new RoundRobinScheduler($derbyBan);

try {
    $schedule = $scheduler->schedule($participants);
} catch (IncompleteScheduleException $e) {
    $analysis = $e->getAnalysis(); // ?DiagnosticReport
    $analysis?->getImpossiblePairings();   // blocked everywhere, culprits named
    $analysis?->getConstraintViolations(); // per-constraint attribution
    $analysis?->getSuggestions();          // includes structural-fullness notes
}
```

Three findings, three vocabularies: **blocked pairings** no round will
accept (culprit constraints named — or "a combination of constraints"
when no single one rejects everywhere), per-constraint **attribution**
("rejects Alice vs Bob in 6 of 6 rounds" — a constraint is only charged
with rounds it rejects in both orientations), and **structural**
conflicts (a pairing whose only allowed rounds are already at capacity
is blocked by arithmetic, not by any constraint). Custom constraints
are attributed exactly like built-ins, because probing evaluates the
real predicates. The analysis answers "could this pairing join what was
built?" — it does not claim global unsatisfiability except where the
backtracking search proved it.

### Exception Hierarchy

Every exception the library throws on purpose implements the marker
interface `Exceptions\TacticianException`. It adds no method; each class
below it keeps a PHP parent type, so a catch clause written against that
parent type matches too.

| Class (in `MissionGaming\Tactician\Exceptions`) | Extends | Thrown when |
|------|------|------|
| `SchedulingException` (abstract) | `\Exception` | A scheduling failure. Its subclasses carry a diagnostic report (`getDiagnosticReport()`). |
| `InvalidConfigurationException` | `SchedulingException` | A scheduler, engine, stage, timeline or grid is configured in a way that cannot work. `getReason()` says which mistake it was: see [Configuration Errors](#configuration-errors). |
| `PinConflictException` | `InvalidConfigurationException` | A repack request pins one participant in two events at the same session and slot. It carries the two event IDs (`getEventIds()`). |
| `UnresolvedRoundException` | `InvalidConfigurationException` | The next round of a results-driven stage is asked for while an event of a recorded round still awaits its result. It carries the events (`getUnresolvedEvents()`) and the round (`getRoundNumber()`): see [Rounds That Are Not Finished](#rounds-that-are-not-finished). |
| `UndecidedSelectionException` | `InvalidConfigurationException` | A `RankRangeSelector` or a `BestOfRankSelector` is asked for a selection that results do not decide. It carries the IDs of the level participants (`getParticipantIds()`), the places they hold between them and the pool, or, where the holders of one rank were compared across pools, that rank (`getRankAcrossPools()`); `isPerEvent()` says that they are level per event played and not as a tied set of the table: see [Selections that results do not decide](#selections-that-results-do-not-decide) and [The best of a rank across pools](#the-best-of-a-rank-across-pools). |
| `IncompleteScheduleException` | `SchedulingException` | Constraints leave a schedule that cannot be completed. |
| `NoValidPairingException` | `SchedulingException` | No complete pairing exists for a Swiss round. |
| `RepackViolationsException` | `SchedulingException` | A repack leaves violations and `RepackOptions(throwOnViolations: true)` asked for an exception instead of the outcome. |
| `InvalidInputException` | `\InvalidArgumentException` | An argument of a value object is outside its allowed range (`new Round(0)`), or the serialized data given to the `fromArray()` or `fromJson()` of a value object, a stage state or a scheduled schedule is malformed: a missing field of an entry, a value of the wrong type, an unknown participant ID, a key the form does not have, a `format_version` the release does not read. Malformed or out-of-range configuration (options, timelines, grids, rules, selectors, a ranking) is an `InvalidConfigurationException` instead, and so is a stored stage state whose pieces are well formed and whose history no verb would have recorded. |
| `RejectedDecisionException` | `\InvalidArgumentException` | A decision given to a standings table or a rank selector does not order one whole set of level entries. `getReason()` is a `RejectedDecisionReason` (`UnknownParticipant`, `SetAlreadyDecided`, `ParticipantsNotLevel`, `PartOfLevelSet`, `Stale`), and it carries the decision (`getDecider()`), the IDs at fault (`getParticipantIds()`) and, from a selector, the pool or the rank: see [Settling a tied set by a decision](#settling-a-tied-set-by-a-decision). It is rejected input, not a configuration error: `catch (InvalidConfigurationException)` does not match it. |
| `JsonConversionException` | `\JsonException` | A `fromJson()` method is given text that is not valid JSON, or a `toJson()` method meets a value JSON cannot represent. The message and code are PHP's; the PHP exception is the previous one. |
| `InvariantViolationException` | `\LogicException` | The library reached a state its own logic rules out. It reports a defect in the library, not a mistake in the input. |
| `UnavailableValueException` | `\LogicException` | An object was asked for a value it does not hold: a time from a shape-only `SessionGrid`, the kickoff of an assignment made on one, the capacity of an unbounded grid as a number. It reports a mistake in the calling code, which the object's `has...()` method (named in the message) would have prevented. It is not a configuration error and not a library defect. |

`SchedulingException` is the base of the scheduling failures only. A rejected
argument of a value object or malformed serialized data is an
`InvalidInputException`, which is not a `SchedulingException`: catch
`TacticianException` for both.

The scheduling failures are told apart by class:

```php
use MissionGaming\Tactician\Exceptions\SchedulingException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;

try {
    $schedule = $scheduler->schedule($participants);
    
} catch (InvalidConfigurationException $e) {
    // Handle configuration errors
    handleConfigurationError($e);
    
} catch (IncompleteScheduleException $e) {
    // Handle incomplete schedules
    handleIncompleteSchedule($e);
    
} catch (SchedulingException $e) {
    // Handle any other scheduling exception
    handleGenericSchedulingError($e);
}

function handleIncompleteSchedule(IncompleteScheduleException $e): void
{
    echo "Schedule could not be completed:\n";
    echo "Reason: " . $e->getMessage() . "\n";
    
    // Analyze constraint violations
    $violations = $e->getViolationCollector()->getViolations();
    if (!empty($violations)) {
        echo "\nConstraint violations:\n";
        foreach ($violations as $violation) {
            echo "- " . $violation->getDescription() . "\n";
        }
    }
    
    // Show completion statistics
    echo "\nCompletion: {$e->getActualEventCount()}/{$e->getExpectedEventCount()} events\n";
}
```

### Configuration Errors

An `InvalidConfigurationException` says what is wrong in four ways, each for
a different reader:

| Accessor | Returns | For |
|------|------|------|
| `getReason()` | An `Exceptions\InvalidConfigurationReason` case, or null for an exception that code outside the library built without one | Code that has to tell one mistake from another. Do not match the message text. |
| `getContext()` | The values involved, keyed by name | Code that needs the values: the IDs, the counts, the setting that was rejected |
| `getRequirements()` | What the failing component requires, one statement per entry; empty when it states none | A screen that lists the rules next to the error |
| `getDiagnosticReport()` | The issue, every context value and the requirements, as text | An operator, a log |

The message (`getMessage()`) is a sentence for a person. Most messages are
the same for every instance of a mistake and name no event and no
participant (every repack request error is like that); a few write a value
into the sentence, such as the labels of a drawn tie. Either way, read the
values from the context and the report, and tell mistakes apart by the
reason.

A repack request that pins one participant in two events at the same session
and slot throws `PinConflictException`, a subclass that carries the two event
IDs. The participants are the four from
[Constraint Attribution](#constraint-attribution):

```php
use MissionGaming\Tactician\Exceptions\PinConflictException;
use MissionGaming\Tactician\Repack\PinnedEvent;
use MissionGaming\Tactician\Repack\RepackRequest;
use MissionGaming\Tactician\Repack\SessionGrid;

$grid = SessionGrid::fromArray([
    'sessions' => ['2026-08-12 20:00'],
    'timezone' => 'UTC',
    'slot_interval' => 'PT25M',
    'slots_per_session' => 4,
    'capacity_per_slot' => 2,
]);

try {
    // Team 1 is pinned twice at session 0, slot 3
    new RepackRequest(
        movableEvents: [],
        pinnedEvents: [
            new PinnedEvent('e1', $participants[0], $participants[1], 0, 3),
            new PinnedEvent('e2', $participants[2], $participants[0], 0, 3),
        ],
        grid: $grid
    );
} catch (PinConflictException $e) {
    echo 'Unpin one of: ' . implode(', ', $e->getEventIds()) . "\n";
    echo 'Reason: ' . $e->getReason()?->value . "\n";
    echo $e->getDiagnosticReport() . "\n";
}
```

```text
Unpin one of: e1, e2
Reason: pin_conflict
=== INVALID CONFIGURATION DIAGNOSTIC REPORT ===

Issue: A participant is pinned twice at one position

=== CONFIGURATION DETAILS ===
• participant: team1
• session: 0
• slot: 3
• event_ids: ["e1", "e2"]
```

The grid above holds two events per slot. A request checks the capacity of a
slot before it checks participants, so on a grid whose slots hold one event
(the default) the same two pins are reported as `PinCapacityExceeded`, by an
`InvalidConfigurationException` whose context has the session, the slot and
the capacity, and no event ID.

`PinConflictException` also has `getParticipantId()`, `getSession()` and
`getSlot()`. It is an `InvalidConfigurationException`, so a catch clause
written for that class matches it, and its message is the one that class
gave before the subclass existed.

For every other configuration error, branch on the reason. A `match` over it
needs a `default` arm, because a release may add a case:

```php
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Timeline\TimelineDefinition;

try {
    TimelineDefinition::fromArray([
        'start' => '2026-08-01 19:00:00',
        'timezone' => 'Europe/Edinburg',
        'round_interval' => 'P7D',
    ]);
} catch (InvalidConfigurationException $e) {
    echo match ($e->getReason()) {
        InvalidConfigurationReason::UnparseableTime => 'Check the date, the timezone name and the durations.',
        InvalidConfigurationReason::TimezoneMismatch => 'Remove the offset from the date: the timezone field decides.',
        default => 'See the report.',
    } . "\n";
    echo $e->getDiagnosticReport() . "\n";
}
```

```text
Check the date, the timezone name and the durations.
=== INVALID CONFIGURATION DIAGNOSTIC REPORT ===

Issue: start or its timezone is not parseable

=== CONFIGURATION DETAILS ===
• start: 2026-08-01 19:00:00
• timezone: Europe/Edinburg
```

A timezone string that PHP cannot use at all (one that holds a NUL byte, as
JSON-decoded configuration can) is reported the same way, with the reason
`UnparseableTime`. So is a datetime that does not state an instant by
itself, such as `tomorrow`, `20:00` or `2026-02-30 20:00` (see
[Timeline Assignment](#timeline-assignment) for the form a datetime takes).

The report writes a list value out in full, in its own order, with strings in
double quotes (`event_ids: ["e1", "e2"]`) and with keys where the array is not
a list (`[from: "2026-01-01", to: "2026-01-02"]`). Inside the quotes a double
quote and a backslash are written with a backslash before them and a control
character as its C escape (`\n`, `\000`), so one entry is one quoted run on
one line. An object is written as its
class name. Two bounds keep it readable: a list of more than 20 entries is cut
after the twentieth and followed by the number left out
(`... 80 more of 100`), and a list nested more than three levels deep is
written as its size (`[2 items]`). An empty list is `[0 items]`.

The "REQUIREMENTS" block of the report belongs to the component that failed.
An error from the round-robin scheduler, its options or its plan lists the
five round-robin requirements
(`InvalidConfigurationException::ROUND_ROBIN_REQUIREMENTS`). An error from a
component that states no requirements, such as the two above, has no such
block. An `InvalidConfigurationException` that your own code builds with
neither a reason nor requirements keeps the round-robin block it carried
before reasons existed; pass `requirements: []` or a list of your own to
change that.

The reasons, with the string each is backed by (`$reason->value`, a stable
identifier for logs and stored data):

| Case | Value | The mistake |
|------|------|------|
| `TooFewParticipants` | `too_few_participants` | Fewer participants than the format, or one pool of it, needs |
| `DuplicateParticipantIds` | `duplicate_participant_ids` | Two participants in one list share an ID |
| `InvalidLegCount` | `invalid_leg_count` | The number of legs is not an integer the format accepts |
| `InvalidRoundCount` | `invalid_round_count` | The number of rounds is not a positive integer, or is more than the format can provide |
| `UnsupportedOptions` | `unsupported_options` | A scheduler was given the options object of another format |
| `IncompatibleOptions` | `incompatible_options` | Two valid settings cannot be used together, or one needs another that is missing |
| `UnknownIdentifier` | `unknown_identifier` | A strategy, algorithm or mode string is not a known identifier |
| `WrongValueType` | `wrong_value_type` | A value has the wrong type, or a required key of plain-data configuration is missing |
| `ValueOutOfRange` | `value_out_of_range` | A number is outside the range allowed for it |
| `EmptyList` | `empty_list` | A list that needs at least one entry is empty |
| `DuplicateName` | `duplicate_name` | Two entries of one list carry the same name |
| `NotSerializable` | `not_serializable` | The configuration has no plain-data form to serialize to |
| `UnsatisfiableLegStrategy` | `unsatisfiable_leg_strategy` | The leg strategy cannot produce the legs asked for |
| `InvalidRoleAssignment` | `invalid_role_assignment` | A role assignment returned something other than the seatings it was given, unchanged or reversed |
| `UnknownOptionKey` | `unknown_option_key` | Plain-data configuration holds a key its form does not have. Every `fromArray()` that reads configuration raises it; see [Keys a Form Does Not Have](#keys-a-form-does-not-have) |
| `UnsupportedFormatVersion` | `unsupported_format_version` | Plain-data configuration states a `format_version` this release does not read: a higher one (a later release wrote it), one below 1, or a value that is not an integer; see [Format Versions](#format-versions) |
| `OddParticipantCount` | `odd_participant_count` | The format has every participant in every round, issues no bye, and was given an odd number of participants |
| `UnequalPots` | `unequal_pots` | The participants do not divide into the number of pots asked for |
| `TooManyOpponentsPerPot` | `too_many_opponents_per_pot` | More opponents are asked from one pot than a pot has other members |
| `OddPotWithOddOpponents` | `odd_pot_with_odd_opponents` | A pot of odd size cannot hold an odd number of events per member inside itself |
| `ConfigurationNotYetSupported` | `configuration_not_yet_supported` | The configuration is feasible and the library has no construction for it yet |
| `BracketComplete` | `bracket_complete` | A further round was asked of a finished bracket |
| `PlannedRoundsPlayed` | `planned_rounds_played` | A further round was asked of a Swiss stage that has played all its planned rounds |
| `RoundPartiallyResolved` | `round_partially_resolved` | An engine was asked for the next round (`pairNextRound()`) while an event of a recorded round still awaits its result: some or all ties of a bracket's current round, or an event of a Swiss round. The exception is an `UnresolvedRoundException`, which carries the events. `isComplete()`, `getOutcome()` and `getReadiness()` do not throw for such a round |
| `EventWithoutRoundNumber` | `event_without_round_number` | An event has no round number where one is required |
| `InvalidResult` | `invalid_result` | A result cannot belong to the stage it was recorded in |
| `DuplicateResult` | `duplicate_result` | Two results were recorded for the same match |
| `UndecidedTie` | `undecided_tie` | A tie that must produce a winner has none |
| `IncompatibleOutcome` | `incompatible_outcome` | A progression selector was given an outcome of a shape it cannot read |
| `RankUnavailable` | `rank_unavailable` | A progression selector asked for a rank the standings do not have |
| `UnplayedStage` | `unplayed_stage` | A rank selection was asked of a table with no recorded result (`UndecidedSelectionException`) |
| `TieAcrossSelection` | `tie_across_selection` | A rank selection takes only some of the entries that no result separates (`UndecidedSelectionException`) |
| `TieWithinSelection` | `tie_within_selection` | Entries that no result separates are all selected, and the order they are selected in is not decided (`UndecidedSelectionException`) |
| `PoolComparisonRequired` | `pool_comparison_required` | A selection that compares entrants of different pools was asked of pools of unequal size, with no comparison method chosen. The context lists the pool sizes (`pool_sizes`) |
| `RoundOutOfSequence` | `round_out_of_sequence` | A round was recorded out of play order: its number is not above the last recorded one. From `StageState::fromHistory()`: the round numbers are not 1, 2, 3 and so on |
| `ParticipantNotAnEntrant` | `participant_not_an_entrant` | A history given to `StageState::fromHistory()` names a participant who is not one of its entrants |
| `ParticipantTwiceInRound` | `participant_twice_in_round` | A round of a history given to `StageState::fromHistory()` holds one participant twice |
| `WithdrawalWithoutRound` | `withdrawal_without_round` | A history given to `StageState::fromHistory()` holds rounds and a withdrawal that does not say after which round it happened |
| `AdjustmentWithoutRound` | `adjustment_without_round` | A history given to `StageState::fromHistory()` holds rounds and an adjustment that does not say after which round it was made |
| `EventNotInRound` | `event_not_in_round` | An event, or the event of a result, does not belong to the round it was recorded with (one with no round number at all is `EventWithoutRoundNumber`) |
| `NoRoundRecorded` | `no_round_recorded` | Results were added or replaced in a stage with no recorded round |
| `ResultNotRecorded` | `result_not_recorded` | A result was to be replaced for an event that has no recorded result |
| `RoundSuperseded` | `round_superseded` | A result was to be replaced in a round that a later round was paired from |
| `EmptyEngineFingerprint` | `empty_engine_fingerprint` | A stage state was to be stamped with an empty engine fingerprint |
| `EngineFingerprintMismatch` | `engine_fingerprint_mismatch` | A stage state carries the fingerprint of another engine or configuration than the one reading it |
| `RecordedRoundMismatch` | `recorded_round_mismatch` | A round recorded on a stage state cannot be a round of the bracket reading it: it holds a third-place tie and the single elimination engine has none, it leaves another number of entrants in a double elimination stage than the stage has places for, or it is a round of a re-seeded bracket that an entrant had withdrawn before, in a state that holds an adjustment which does not say after which round it was made ([When a Withdrawal or an Adjustment Happened](#when-a-withdrawal-or-an-adjustment-happened)). A round that only pairs the entrants otherwise is read and reported as manual |
| `EmptyEventId` | `empty_event_id` | A movable or pinned event has an empty ID |
| `IdenticalParticipants` | `identical_participants` | An event names the same participant on both sides |
| `DuplicateEventId` | `duplicate_event_id` | Two events of one repack request share an ID |
| `PinOffGrid` | `pin_off_grid` | A pinned event sits at a position the grid does not have |
| `PinCapacityExceeded` | `pin_capacity_exceeded` | More events are pinned at one position than a slot can hold |
| `PinConflict` | `pin_conflict` | One participant is pinned in two events at one position (`PinConflictException`) |
| `PositionOutOfRange` | `position_out_of_range` | A session, slot, round or resource index is outside the grid or timeline |
| `UnparseableTime` | `unparseable_time` | A datetime, its timezone or an ISO 8601 duration cannot be parsed, or the datetime is relative to the current time, has no date or no year, or does not mean what it writes |
| `TimezoneMismatch` | `timezone_mismatch` | A time carries a timezone that contradicts the declared one |
| `NonAdvancingTime` | `non_advancing_time` | An interval, a window or a sequence of starts does not move time forward |
| `OverlappingTime` | `overlapping_time` | The slots of one round of a timeline, or of one session of a grid, run up to or past the first slot of the next, or two slots of one round or session are not at rising times |
| `TimeRuleViolation` | `time_rule_violation` | The assigned timeline breaks a time rule |
| `TimelineCapacityExceeded` | `timeline_capacity_exceeded` | A round has more events than the timeline has places for |
| `ParticipantDoubleBooked` | `participant_double_booked` | One participant is in two events of a round that the timeline places at the same instant |
| `ConstraintViolation` | `constraint_violation` | Built by `SchedulingException::constraintViolation()`, which is [deprecated](#deprecations) |
| `InvalidSchedule` | `invalid_schedule` | Built by `SchedulingException::invalidSchedule()`, which is [deprecated](#deprecations) |

A case says what kind of mistake was made, not which component found it:
`TooFewParticipants` comes from the round-robin scheduler, the Swiss engine
and the elimination engines alike. Every configuration error the library
raises states one; `getReason()` returns null only for an exception that
code outside the library builds without a reason.

### Catching Every Library Exception

One clause catches everything in the table above. The three calls below fail
in three different ways (the scheduler is the one from
[Constraint Attribution](#constraint-attribution), whose constraint no
schedule can satisfy):

```php
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\TacticianException;

$attempts = [
    'A schedule the constraints rule out' => fn () => $scheduler->schedule($participants),
    'A round numbered zero' => fn () => new Round(0),
    'JSON that is cut short' => fn () => Schedule::fromJson('{"participants": ['),
];

foreach ($attempts as $what => $attempt) {
    try {
        $attempt();
    } catch (TacticianException $e) {
        echo $what . ': ' . $e::class . "\n";
    }
}
```

```text
A schedule the constraints rule out: MissionGaming\Tactician\Exceptions\IncompleteScheduleException
A round numbered zero: MissionGaming\Tactician\Exceptions\InvalidInputException
JSON that is cut short: MissionGaming\Tactician\Exceptions\JsonConversionException
```

A catch clause for the PHP parent type still matches, with the same message.
Code written before the marker existed needs no change:

```php
try {
    new Round(0);
} catch (\InvalidArgumentException $e) {
    echo $e->getMessage() . "\n"; // Round number must be positive
}

try {
    Schedule::fromJson('{"participants": [');
} catch (\JsonException $e) {
    echo $e->getMessage() . "\n"; // Syntax error
}
```

#### What the marker does not cover

`TacticianException` marks what the library reports. These reach the caller
unchanged, because the library does not raise them:

- **An exception from code the caller supplied**: a custom constraint
  predicate, a role extractor or metadata validator, a `RankingStrategy`,
  tiebreaker, leg strategy or quality metric of the caller's own, and the
  `jsonSerialize()` method of an object placed in metadata.
- **A failure of the random source**: `Random\RandomException` or
  `Random\BrokenRandomEngineError` from the `Random\Randomizer` passed to a
  scheduler, engine, optimizer or leg strategy.
- **PHP's `\Error` family**: a `\TypeError` for an argument of the wrong
  type, for example.

## Advanced Patterns

### Custom Schedulers

The class below is a sketch of the shape, not a complete scheduler: it
leaves out `getPlan()` and the generation itself.

<!-- snippet: skip reason="A sketch of a custom scheduler: it omits getPlan() and generateCustomEvents(), so the class cannot be declared." -->
```php
use MissionGaming\Tactician\Scheduling\SchedulerInterface;
use MissionGaming\Tactician\Scheduling\SchedulerOptions;
use MissionGaming\Tactician\DTO\Schedule;

class CustomScheduler implements SchedulerInterface
{
    public function schedule(
        array $participants,
        ?SchedulerOptions $options = null
    ): Schedule {
        // Accept exactly one options type (define your own SchedulerOptions
        // implementation) and reject any other loudly; null means your
        // documented defaults
        $events = $this->generateCustomEvents($participants);

        return new Schedule($events, [
            'algorithm' => 'custom',
            'participant_count' => count($participants),
        ]);
    }

    // Implement getPlan() — the plan declares your algorithm's shape
    // (rounds, legs, expected events) so validation and diagnostics work
    // without round-robin assumptions, and it fails loudly for
    // unsatisfiable configurations before any event exists...
}
```

### Stage Plans

Every scheduler can declare the shape of a stage before generating it. The
plan is what validation, diagnostics, and shape-aware constraints (e.g.
`SeedProtectionConstraint`) read the shape from:

```php
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Scheduling\SwissScheduler;
use MissionGaming\Tactician\Scheduling\SwissOptions;

$participants = [
    new Participant('celtic', 'Celtic'),
    new Participant('athletic', 'Athletic Bilbao'),
    new Participant('livorno', 'AS Livorno'),
    new Participant('redstar', 'Red Star FC'),
];

$plan = (new RoundRobinScheduler())->getPlan($participants, new RoundRobinOptions(legs: 2));

$plan->getAlgorithm();          // 'round-robin' — stable identifier
$plan->getTotalRounds();        // 6 for 4 participants over 2 legs
$plan->getLegs();               // 2
$plan->getRoundsPerLeg();       // 3 (bye-aware: 5 participants would need 5)
$plan->getExpectedEventCount(); // 12

// Round-robin-family plans also guarantee pairwise meeting counts:
$plan->getExpectedMeetings($participants[0], $participants[1]); // 2

// Swiss has rounds but no legs: the legs accessors are null because the
// concept does not apply — never a fabricated 1
$swissPlan = (new SwissScheduler())->getPlan($participants, new SwissOptions(rounds: 3));
$swissPlan->getAlgorithm();     // 'swiss'
$swissPlan->getTotalRounds();   // 3
$swissPlan->getLegs();          // null
```

A plan also validates a complete schedule against its declared shape:
`$plan->validateIntegrity($schedule)` returns human-readable violation
strings (duplicate pairings, foreign participants, short rounds), and the
schedulers run it automatically before returning a schedule.

### Scheduling Context

```php
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Scheduling\SchedulingContext;

$existingEvents = [new Event([$participants[0], $participants[1]], new Round(1))];

// Create context with participants, the stage plan, and existing events
$context = new SchedulingContext(
    $participants,
    $plan,
    $existingEvents,
    currentLeg: 1,
    participantsPerEvent: 2
);

// Shape facts come from the plan
$totalRounds = $context->getPlan()->getTotalRounds();

// Check if participants have played together
$havePlayed = $context->haveParticipantsPlayed($participants[0], $participants[1]); // true

// Get events for a specific participant
$playerEvents = $context->getEventsForParticipant($participants[0]);

// Get the events two participants both take part in
$meetings = $context->getEventsBetween($participants[0], $participants[1]);

// Add new events to context (contexts are immutable: this returns a new one)
$newEvent = new Event([$participants[2], $participants[3]], new Round(1));
$newContext = $context->withEvents([$newEvent]);
```

### Deterministic Randomization

The library draws nothing from a global random source. What is random
comes from a `Random\Randomizer` you pass in, and a seeded one makes a
schedule repeatable:

```php
use Random\Randomizer;
use Random\Engine\Mt19937;

$seeded = fn (): RoundRobinScheduler => new RoundRobinScheduler(null, new Randomizer(new Mt19937(12345)));

$scheduler = $seeded();
$schedule = $scheduler->schedule($participants);

// A new scheduler with the same seed repeats the schedule
var_dump($seeded()->schedule($participants)->toJson() === $schedule->toJson()); // bool(true)

// The same scheduler does not: its randomizer has moved on
var_dump($scheduler->schedule($participants)->toJson() === $schedule->toJson()); // bool(false)
```

A `Randomizer` is an object with state, and a scheduler or engine that
holds one draws from it on every call. "The same seed gives the same
schedule" therefore means the same seed and the same sequence of calls: the
first call on a scheduler built with seed 12345 always returns the same
schedule, the second call always returns the same other one. To repeat a
schedule, build the randomizer again from its seed; do not keep one
scheduler and call it twice. The same holds for a `SwissPairingEngine`, a
`SwissScheduler` and a `ShuffledLegStrategy` that hold a randomizer, and
for a `Randomizer` object shared between two of them.

Three things to know beside that:

- A scheduler without a `Randomizer` is deterministic and keeps no such
  state: every call returns the same schedule for the same input.
- `ShuffledLegStrategy` does not draw from the scheduler's randomizer. It
  has a source of its own, and is refused without one: nothing in the
  library draws from an unseeded randomizer that the caller did not pass
  in. Given an integer seed (`new ShuffledLegStrategy(seed: 2026)`, or
  `'strategy' => 'shuffled', 'strategy_seed' => 2026` in the plain data of
  `RoundRobinOptions::fromArray()`), it starts its draws again from that
  seed at the start of every `schedule()` call, on a `Random\Randomizer`
  it builds on the `Xoshiro256StarStar` engine, so the same seed gives the
  same roles on every call. The seed is the strategy's alone: it does not
  shuffle the participant list, which is the scheduler's randomizer's to
  do. Given a `Randomizer`, the strategy holds it and the paragraph above
  applies. For roles that differ from run to run, pass
  `new ShuffledLegStrategy(new Randomizer())`, which says so in your own
  code.
- A pot draw takes its seed as an option and keeps no state between calls
  (see [The seed and determinism](#the-seed-and-determinism)). So does a
  pre-drawn Swiss schedule when its options have a seed
  (`new SwissOptions(rounds: 3, seed: 2026)`): the scheduler builds a new
  `Randomizer` from it on every call, and is refused a seed if it also
  holds a `Randomizer` (see [Swiss Tournaments](#swiss-tournaments)).

## Real-World Examples

Each example below is complete in itself and repeats the imports it needs.

### Premier League Style Tournament

```php
use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Constraints\MinimumRestPeriodsConstraint;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\LegStrategies\MirroredLegStrategy;
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

// 20 teams in seeding order, home and away legs
$clubs = [
    'Manchester City', 'Arsenal', 'Liverpool', 'Aston Villa', 'Tottenham',
    'Chelsea', 'Newcastle', 'Manchester United', 'West Ham', 'Crystal Palace',
    'Brighton', 'Bournemouth', 'Fulham', 'Wolves', 'Everton',
    'Brentford', 'Nottingham Forest', 'Leicester', 'Ipswich', 'Southampton',
];

$teams = [];
foreach ($clubs as $position => $club) {
    $teams[] = new Participant(strtolower(str_replace(' ', '-', $club)), $club, $position + 1);
}

$constraints = ConstraintSet::create()
    ->noRepeatPairings()
    ->add(new MinimumRestPeriodsConstraint(5))  // A pair's two meetings are at least 5 rounds apart
    ->build();

$scheduler = new RoundRobinScheduler($constraints);

// Generate full season: 2 legs (home and away)
$season = $scheduler->schedule(
    $teams,
    new RoundRobinOptions(legs: 2, strategy: new MirroredLegStrategy())
);

echo "Premier League season: " . count($season) . " matches\n";
// 380 matches: each of the 20 teams hosts each of the other 19 once
```

### Gaming Tournament with Skill Brackets

A rule that forbids some pairings outright cannot be combined with a round
robin, which needs every pair to meet. Swiss pairing has no such
requirement, so it is the format for a bracketed field:

```php
use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Constraints\MetadataConstraint;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\SwissOptions;
use MissionGaming\Tactician\Scheduling\SwissScheduler;

// Entrants seeded in sign-up order. With no results the Swiss table is in
// seed order, so that is the order pairing works from.
// It mixes the tiers: left to itself, Swiss pairing would put a
// professional against an amateur four times in three rounds
$players = [
    new Participant('pro1', 'ProGamer1', 1, ['tier' => 3]),
    new Participant('am1', 'Amateur1', 2, ['tier' => 1]),
    new Participant('pro2', 'ProGamer2', 3, ['tier' => 3]),
    new Participant('am2', 'Amateur2', 4, ['tier' => 1]),
    new Participant('semi1', 'SemiPro1', 5, ['tier' => 2]),
    new Participant('semi2', 'SemiPro2', 6, ['tier' => 2]),
    new Participant('semi3', 'SemiPro3', 7, ['tier' => 2]),
    new Participant('semi4', 'SemiPro4', 8, ['tier' => 2]),
];

// Only players of the same or adjacent skill tiers may meet, so the
// professionals (tier 3) never play the amateurs (tier 1). The values
// must be numeric: requireAdjacentValues() ignores non-numeric values
$constraints = ConstraintSet::create()
    ->add(MetadataConstraint::requireAdjacentValues('tier'))
    ->build();

$tournament = (new SwissScheduler($constraints))->schedule($players, new SwissOptions(rounds: 3));

echo count($tournament) . " matches\n"; // 12 matches: 4 per round, none of them professional v amateur
```

Asking a round robin for the same rule fails loudly instead of dropping
the four professional-versus-amateur matches (this block is expected to
throw, and the documentation test asserts that it does):

<!-- snippet: throws="MissionGaming\Tactician\Exceptions\IncompleteScheduleException" -->
```php
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

// Throws IncompleteScheduleException
(new RoundRobinScheduler($constraints))->schedule($players);
```

### Corporate Team Building Tournament

Pairing departments across buildings only is another rule a round robin
cannot honour — Engineering and Sales share a building and would have to
meet — so this is Swiss as well:

```php
use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Constraints\MetadataConstraint;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\SwissOptions;
use MissionGaming\Tactician\Scheduling\SwissScheduler;

$departments = [
    new Participant('eng', 'Engineering', 1, ['location' => 'Building A']),
    new Participant('mark', 'Marketing', 2, ['location' => 'Building B']),
    new Participant('sales', 'Sales', 3, ['location' => 'Building A']),
    new Participant('hr', 'Human Resources', 4, ['location' => 'Building B']),
];

// Cross-building pairings only
$constraints = ConstraintSet::create()
    ->add(MetadataConstraint::requireDifferentValues('location'))
    ->build();

// Each department has two possible opponents, so the tournament is two rounds
$teamBuilding = (new SwissScheduler($constraints))->schedule($departments, new SwissOptions(rounds: 2));

foreach ($teamBuilding as $event) {
    [$first, $second] = $event->getParticipants();
    echo "Round {$event->getRound()?->getNumber()}: {$first->getLabel()} vs {$second->getLabel()}\n";
}
```

### Home-and-Away League with Spaced Return Fixtures

```php
use MissionGaming\Tactician\Constraints\ConsecutiveRoleConstraint;
use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Constraints\MinimumRestPeriodsConstraint;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\LegStrategies\MirroredLegStrategy;
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

$teams = [
    new Participant('team1', 'Team Alpha'),
    new Participant('team2', 'Team Beta'),
    new Participant('team3', 'Team Gamma'),
    new Participant('team4', 'Team Delta'),
    new Participant('team5', 'Team Epsilon'),
    new Participant('team6', 'Team Zeta'),
];

// MinimumRestPeriodsConstraint spaces out repeat meetings of the same
// pair: it only matters when there is more than one leg
$constraints = ConstraintSet::create()
    ->add(new MinimumRestPeriodsConstraint(4))  // A pair's return fixture is at least 4 rounds later
    ->add(ConsecutiveRoleConstraint::homeAway(2))  // Max 2 consecutive home/away
    ->build();

try {
    $scheduler = new RoundRobinScheduler($constraints);
    $tournament = $scheduler->schedule(
        $teams,
        new RoundRobinOptions(legs: 2, strategy: new MirroredLegStrategy())
    );

    echo "Tournament scheduled successfully!\n";
    echo "Total matches: " . count($tournament) . "\n";                             // 30
    echo "Total rounds: " . $tournament->getMetadataValue('total_rounds') . "\n";   // 10

} catch (IncompleteScheduleException $e) {
    echo "Could not schedule with current constraints\n";
    echo "Try reducing the minimum rest period or relaxing the consecutive role limit\n";
}
```

## Performance Considerations

### Iterating and Counting

A `Schedule` holds every one of its events in memory, as an array:
generation builds the whole schedule before returning it, and nothing is
loaded lazily or released as you iterate. A schedule is iterable and
countable, so you can loop over it and count it directly — that is a
convenience, not a memory saving. Schedules are small in practice (a
20-participant double round robin is 380 events); if memory does matter,
it is a question of how your application stores and pages events, not of
how you iterate.

A `Schedule` is its own iterator and has one cursor. A `foreach` over a
schedule inside another `foreach` over the same object moves that cursor
to the end, so the outer loop stops after its first event. To compare every
event with every other, loop over `getEvents()`, which is a plain array.

```php
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

$manyParticipants = [];
for ($i = 1; $i <= 16; ++$i) {
    $manyParticipants[] = new Participant("p{$i}", "Player {$i}");
}

$largeSchedule = (new RoundRobinScheduler())->schedule($manyParticipants);

// Iterate the schedule directly, in generated order
foreach ($largeSchedule as $event) {
    // ...process the event...
}

// Count the events: 16 participants meeting once each
$totalEvents = count($largeSchedule); // 120

// The same events as a plain array, or grouped by round number
$events = $largeSchedule->getEvents();
$eventsByRound = $largeSchedule->getEventsByRound();
```

### Constraint Optimization

A constraint set asks its constraints in the order they were added and
stops at the first one that rejects an event. (The round-robin scheduler
then asks each constraint about the rejected event once more, to record
which of them rejected it.) Read the history through the context's lookups
(`getEventsForParticipant()`, `getEventsBetween()`, `getEventsInRound()`,
`getEventsForLeg()`), which cost the events they return; a constraint that
scans `getExistingEvents()` itself costs the whole schedule for every
event it is asked about.

```php
use MissionGaming\Tactician\Constraints\ConsecutiveRoleConstraint;
use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Constraints\MetadataConstraint;
use MissionGaming\Tactician\Constraints\MinimumRestPeriodsConstraint;

// Put the constraints most likely to reject an event first, so the
// later ones are evaluated less often
$optimizedConstraints = ConstraintSet::create()
    ->add(new MinimumRestPeriodsConstraint(3))  // The one you expect to reject most often
    ->add(ConsecutiveRoleConstraint::homeAway(2))
    ->add(MetadataConstraint::requireSameValue('division'))
    ->noRepeatPairings()  // The one you expect to reject least often
    ->build();
```

## Deprecations

A deprecated method still works and still returns what it returned before. It
is removed in `1.0.0`. Until then, each one is marked in two ways:

- The `@deprecated` docblock tag, which an IDE and a static analyser such as
  PHPStan report where you call the method, on every PHP version.
- PHP's `#[\Deprecated]` attribute. From PHP 8.4, a call emits an
  `E_USER_DEPRECATED` notice that names the method and what to use instead.
  PHP 8.3 does not act on the attribute, so a call there emits nothing.

An application that turns every notice into an exception will therefore see a
call to one of these methods fail on PHP 8.4 or later where it passes on
PHP 8.3. Nothing in the library calls a deprecated method.

The methods are all deprecated since `0.2.2`. Namespaces are relative to
`MissionGaming\Tactician`. One constant and the metadata key it names are
deprecated since `0.3.0`: see
[The `tie_winner` metadata key](#the-tie_winner-metadata-key) below.

| Deprecated | Use instead |
|------------|-------------|
| `Scheduling\SchedulingContext::getTotalLegs()` | `getPlan()->getLegs()`. The method answers 1 for a format that has no legs (Swiss, elimination); the plan answers `null` there, because the concept does not apply. |
| `Exceptions\SchedulingException::invalidParticipantCount()` | `new InvalidConfigurationException(...)` with `InvalidConfigurationReason::TooFewParticipants` |
| `Exceptions\SchedulingException::constraintViolation()` | `new InvalidConfigurationException(...)` with `InvalidConfigurationReason::ConstraintViolation` |
| `Exceptions\SchedulingException::invalidSchedule()` | `new InvalidConfigurationException(...)` with `InvalidConfigurationReason::InvalidSchedule` |
| `Validation\ScheduleValidator::generateDiagnosticReport()` | No replacement. The report of a generation that failed is `IncompleteScheduleException::getDiagnosticReport()`. |
| `Validation\ScheduleValidator::generateConstraintSuggestions()` | No replacement. The suggestions for a generation that failed are in the `DiagnosticReport` that `IncompleteScheduleException::getAnalysis()` returns. |
| `Diagnostics\SchedulingDiagnostics::identifyConstraintConflicts()` | No replacement. |
| `Diagnostics\SchedulingDiagnostics::suggestConstraintAdjustments()` | No replacement. The suggestions of an analysis are `DiagnosticReport::getSuggestions()`. |

`getTotalLegs()` goes because of what it answers. The others are helpers
that nothing in the library called. `ScheduleValidator` and
`SchedulingDiagnostics` are also `@internal`: the schedulers run them, and
what they find reaches you through the exception a scheduler throws (see
[Error Handling](#error-handling)).

Reading the leg count from the plan, and building the exception the factories
built:

```php
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Scheduling\SchedulingContext;
use MissionGaming\Tactician\Stage\SwissPlan;

$field = [
    new Participant('celtic', 'Celtic'),
    new Participant('athletic', 'Athletic Bilbao'),
    new Participant('livorno', 'AS Livorno'),
    new Participant('redstar', 'Red Star FC'),
];

$swissContext = new SchedulingContext($field, new SwissPlan($field, 3));

// Was: $swissContext->getTotalLegs(), which answered 1
var_dump($swissContext->getPlan()->getLegs());      // NULL: Swiss has no legs
var_dump($swissContext->getPlan()->getLegs() ?? 1); // int(1): a default you chose, where you need one

// Was: SchedulingException::invalidParticipantCount(1)
$issue = 'Invalid participant count: 1. Must be at least 2.';
$tooFew = new InvalidConfigurationException(
    $issue,
    ['participant_count' => 1, 'minimum_required' => 2],
    $issue,
    reason: InvalidConfigurationReason::TooFewParticipants,
    requirements: InvalidConfigurationException::ROUND_ROBIN_REQUIREMENTS
);

var_dump($tooFew->getReason() === InvalidConfigurationReason::TooFewParticipants); // bool(true)
var_dump($tooFew->getMessage()); // string(49) "Invalid participant count: 1. Must be at least 2."
```

The three factories built their exception the same way, and the block above
builds exactly what `invalidParticipantCount(1)` returned: the sentence is
both the issue and the exception message (leave the third argument out and
the message begins "Invalid scheduler configuration: "), and the requirements
are the round-robin list (leave them out and the diagnostic report has no
"REQUIREMENTS" block). The other two differ in the sentence and the context
only:

- `invalidParticipantCount($count)`: the sentence
  `"Invalid participant count: {$count}. Must be at least 2."`, the context
  `['participant_count' => $count, 'minimum_required' => 2]`, the reason
  `TooFewParticipants`.
- `constraintViolation($constraint)`: the sentence
  `"Constraint violation: {$constraint}"`, the context
  `['constraint' => $constraint]`, the reason `ConstraintViolation`.
- `invalidSchedule($reason)`: the sentence `"Invalid schedule: {$reason}"`,
  the context `['reason' => $reason]`, the reason `InvalidSchedule`.

### The `tie_winner` metadata key

Before the typed [decider](#recording-a-level-event), who advances from a
level elimination tie was recorded as `tie_winner` metadata on a result of
the tie: the ID of the participant. The key is deprecated since `0.3.0`. It
is still read, and decides what it decided before, wherever a level tie has
no decider. It is no longer read from `1.0.0`: a level tie that carries
only the key is then undecided, and the engine refuses the round after it
(`UndecidedTie`).

| Deprecated | Use instead |
|------------|-------------|
| `Stage\TieDecision::TIE_WINNER_KEY` | No constant replaces it: pass a `DTO\Decider` as the `decider` argument of `Result`. The constant, like the methods above, is removed in `1.0.0`. |

The constant is marked as the methods are, with the docblock tag and the
attribute. The key is a string in your data, which nothing can mark, so
the library says it when it reads it:

- A call that takes an advancer from the key emits one `E_USER_DEPRECATED`
  notice: `isComplete()`, `pairNextRound()`, `getOutcome()`,
  `getReadiness()`, `getRecordedRounds()` and `getStandings()` of the two
  elimination engines, every one of which reads the bracket;
  `TieDecision::advancer()`; and `MatchOutcomeSelector::select()` where it
  reads the results itself, which is for an outcome that carries no settled
  ties (one built by hand). For the outcome of an engine the selector reads
  the tie as the engine settled it, and the notice came from the
  `getOutcome()` call that built the outcome. It is one notice for the
  call, however many ties of the bracket the key decided.
- It is emitted on every PHP version, PHP 8.3 included, because it does not
  come from the attribute.
- A key that is not read emits nothing: on a result of a tie that its
  results decide (where it never was read), in a result that is only
  stored, loaded or counted in a table, and on a tie that carries a decider.

Record the decider where you recorded the key:

```php
use MissionGaming\Tactician\DTO\Decider;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Scheduling\SingleEliminationEngine;
use MissionGaming\Tactician\Stage\StageState;

[$celtic, $athletic] = $field;
$bracket = new SingleEliminationEngine();
$final = $bracket->pairNextRound(StageState::start([$celtic, $athletic]));
[$event] = $final->getEvents();
$scores = ['celtic' => 1, 'athletic' => 1];

// Was: new Result($event, null, $scores, ['tie_winner' => 'athletic'])
$level = new Result($event, null, $scores, decider: new Decider(['athletic']));

$decided = StageState::start([$celtic, $athletic])->withRoundPlayed($final, [$level]);
echo $bracket->getOutcome($decided)?->getStandings()->getEntries()[0]->getParticipant()->getLabel(), "\n"; // Athletic Bilbao
```

Stored results keep loading, and keep deciding their ties until `1.0.0`.
To move stored data over before then, give each level result that carries
the key a `decider` in its place:

```php
// A stage state as an earlier release stored it: the level final carries the key
$stored = StageState::start([$celtic, $athletic])
    ->withRoundPlayed($final, [new Result($event, null, $scores, ['tie_winner' => 'athletic'])])
    ->toArray();

foreach ($stored['results'] as $index => $result) {
    $advances = $result['metadata']['tie_winner'] ?? null;

    // A single-leg event: level when it has no winner
    if (is_string($advances) && $result['winner'] === null) {
        $stored['results'][$index]['decider'] = ['participants' => [$advances]];
        unset($stored['results'][$index]['metadata']['tie_winner']);
    }
}

$migrated = StageState::fromArray($stored);
echo json_encode($migrated->toArray()['results'][0]['decider']), "\n";
// {"participants":["athletic"],"label":null,"reason":null}
echo $bracket->getOutcome($migrated)?->getStandings()->getEntries()[0]->getParticipant()->getLabel(), "\n"; // Athletic Bilbao
```

A two-legged tie is level when its legs are, and a leg of it may have no
winner in a tie that the other leg decides, so its results are read
together: the results of one round between the same two participants are
one tie. The tie takes a decider when no leg was won or each side won one,
on its last leg, from the first leg that carries the key (the one that was
read). The key is removed from every result of a tie that has all its
results, the ties the legs decide included, where it was never read. With
`$legsPerTie` at 1 the same loop moves single events over.

```php
use MissionGaming\Tactician\Scheduling\EliminationOptions;

[, , $livorno, $redstar] = $field;

// Two-legged semifinals as an earlier release stored them. Celtic and Red
// Star won a leg each, and the key on the second leg sends Red Star on.
// Athletic won both legs, so the key on its second leg was never read
$twoLegged = new SingleEliminationEngine(new EliminationOptions(legsPerTie: 2));
$semifinals = $twoLegged->pairNextRound(StageState::start($field));
[$firstLeg, $secondLeg, $otherFirstLeg, $otherSecondLeg] = $semifinals->getEvents();
$storedLegs = StageState::start($field)
    ->withRoundPlayed($semifinals, [
        new Result($firstLeg, $celtic, ['celtic' => 2, 'redstar' => 1]),
        new Result($secondLeg, $redstar, ['celtic' => 0, 'redstar' => 1], ['tie_winner' => 'redstar']),
        new Result($otherFirstLeg, $athletic),
        new Result($otherSecondLeg, $athletic, [], ['tie_winner' => 'livorno']),
    ])
    ->toArray();

$legsPerTie = 2;

// The results of one tie: one round and the same two participants
$ties = [];
foreach ($storedLegs['results'] as $index => $result) {
    $pair = $result['event']['participants'];
    sort($pair);
    $ties[$result['event']['round']['number'] . ' ' . implode(' ', $pair)][] = $index;
}

foreach ($ties as $indexes) {
    if (count($indexes) < $legsPerTie) {
        continue; // A leg has no result yet: leave the key, which is read when it has one
    }

    // In leg order, whatever order the results were recorded in
    usort($indexes, fn (int $a, int $b): int => ($storedLegs['results'][$a]['event']['metadata']['tie_leg'] ?? 1)
        <=> ($storedLegs['results'][$b]['event']['metadata']['tie_leg'] ?? 1));

    $legWins = [];
    $advances = null;
    foreach ($indexes as $index) {
        $result = $storedLegs['results'][$index];
        if ($result['winner'] !== null) {
            $legWins[$result['winner']] = ($legWins[$result['winner']] ?? 0) + 1;
        }

        // The first leg that carries the key is the one that was read
        $advances ??= $result['metadata']['tie_winner'] ?? null;
        unset($storedLegs['results'][$index]['metadata']['tie_winner']);
    }

    // Level: no leg won, or a leg each. Otherwise the legs decide
    $level = $legWins === [] || array_values($legWins) === [1, 1];
    if ($level && is_string($advances)) {
        $storedLegs['results'][end($indexes)]['decider'] = ['participants' => [$advances]];
    }
}

$migratedLegs = StageState::fromArray($storedLegs);
foreach ($migratedLegs->getResults() as $result) {
    echo json_encode($result->getMetadata()), ' ', json_encode($result->getDecider()?->getParticipantIds()), "\n";
}
// [] null
// [] ["redstar"]
// [] null
// [] null

[$finalFirstLeg] = $twoLegged->pairNextRound($migratedLegs)->getEvents();
echo implode(' v ', array_map(fn (Participant $p) => $p->getLabel(), $finalFirstLeg->getParticipants())), "\n";
// Red Star FC v Athletic Bilbao
```

Two things differ between the key and the decider, and both matter when
you move data over:

- **A decider on a tie that its results decide is refused**
  (`InvalidResult`), where the key was not read and went unnoticed. Give a
  decider to the ties that are level and to no others: an event without a
  winner, as above, and a two-legged tie whose legs are level (a leg each,
  or both drawn), on one of its legs. Remove the key from every other
  result.
- **A decider must name a participant of the event**, and `Result` refuses
  one that does not when it is built. The key could hold any value, and a
  wrong one was refused only when the engine read it.

While you change over you can write both. Where a tie carries a decider
the decider is what is read, and a key beside it that names the same
participant raises nothing. One that names another participant is refused
(`InvalidResult`): the library does not choose between two answers.

---

This guide covers the essential patterns for using Tactician effectively. For more advanced use cases or when contributing to the library, see the [Architecture documentation](ARCHITECTURE.md) and [Contributing guidelines](CONTRIBUTING.md).
