# Design note: Schedule Repacking

**Status: IMPLEMENTED** in 0.2.0, with the additions under
[API additions](#api-additions-022) in 0.2.2, the fixes under
[Placement fixes](#placement-fixes-023) in 0.2.3 and the changes under
[Order and contiguity](#order-and-contiguity-030) and
[Objectives](#objectives-030) in 0.3.0. It was built from an external
brief (v2, the revision with an empty pinned set on the reference
instance). This note doubles as the decisions log of that
implementation: every judgement call made while building it is recorded
here, in the order it was made, so a later entry can replace an earlier
one. Where that happened the earlier entry says so.

## Position

Tactician can generate a schedule and decorate it with kickoffs; it cannot
repair one. The repack component takes events that already exist — some
immovable — and assigns every movable event a `(session, slot)` colour on a
declared irregular grid such that nobody is double-booked and each
participant's events within a session run back to back. Formally: interval
edge colouring of a multigraph, restricted per session, with pre-coloured
(pinned) edges. Proper colouring is always achievable (Vizing) modulo pins;
contiguity is NP-complete to decide and therefore *reported* when
unsatisfiable, never silently relaxed.

Deliberate deviation from house style, per the brief: infeasibility returns
an outcome carrying the schedule plus structured violations instead of
throwing. The operator needs "here is the schedule and its four
compromises", not an exception. `RepackOptions(throwOnViolations: true)`
exists as a convenience.

## Decisions log

1. **Brief validated before building.** Fixture v2 checked internally and
   against the prose: 14 participants, 71 movable events (59 pairings, 12
   doubled), 0 pins, 20 evacuated defaulted events; mis-pinned variant
   arithmetic confirmed (Fallowmead 15 events vs 12 free colours → shortfall
   3; Cinder Row's only free session-0 colour is slot 0; 4 pin-only outsider
   participants). Claims about `TimelineAssigner`/`TimelineDefinition`
   verified against source. No discrepancies found.
2. **Namespace and naming.** New top-level `src/Repack/`
   (`MissionGaming\Tactician\Repack`), sibling of `Timeline` per the brief's
   "new component alongside the timeline family". `SessionGrid` accepted as
   proposed; glossary gains **session** ("sitting" rejected — "session" is
   already natural English for a match night and does not collide with
   "round"). Graph-theory vocabulary ("colour") stays in docs/comments;
   the API speaks session/slot.
3. **`TimelineDefinition` untouched.** No shared abstraction extracted: the
   only genuinely shared logic is zoned-time parsing (`ZonedTime`) and ISO
   interval parsing (`TimelineDefinition::parseInterval`/`formatInterval`,
   already public static), which `SessionGrid` reuses directly.
4. **Event ids are strings** (like `Participant` ids). The fixture's
   integer ids are cast by the test/caller. Internal ordering is `strcmp`,
   used only for determinism, never exposed as meaning. **Replaced by
   decision 39**: the internal order is made from the request, and
   `strcmp` orders only what an outcome returns.
5. **Pins consume capacity and colours but are not re-emitted**:
   `RepackOutcome::getAssignments()` contains movable events only. Corrupt
   pin input (two pins double-booking a participant, pins overflowing slot
   capacity, pins off the grid) throws `InvalidConfigurationException` (the
   first as its subclass `PinConflictException`, which names the two events;
   capacity is checked before it, so two pins that also overflow their slot
   are reported as the overflow) —
   that is broken *input*, not an unsatisfiable *instance*.
6. **Violation scoping.** `ContiguityBroken`/`LateStart` are reported only
   for `(participant, session)` cells where the participant has at least one
   *movable* assignment; pinned slots count toward the occupancy pattern but
   purely-pinned sessions are historical fact the repacker cannot influence
   and are not reported as its compromises.
7. **`CapacityExceeded` is scoped structurally**: the nullable participant
   covers the two shapes the planner actually produces (a participant's
   demand vs its free positions — the Fallowmead case — and global grid
   overflow), with `demand`, `capacity`, and derived `shortfall`.
   Session-level exhaustion surfaces as `EventUnplaced`, not as a third
   scope.
8. **Over-capacity drop rule** (which events go unplaced when a participant
   needs more colours than exist): drop that participant's events against
   the opponents with the most slack (free colours minus load), tie-broken
   by event id descending. Deterministic and least damaging to everyone
   else's feasibility. Over-capacity participants are processed by largest
   shortfall first, then id. **Amended by decisions 34 and 35**: events
   between two over-capacity participants are dropped first, and every
   over-capacity participant is reported with its demand before any drop.
9. **Objectives 5 vs 6 trade** (consolidate vs finish early) is explicit:
   `RepackOptions(consolidationWeight: 3, earlyFillWeight: 1)` — integer
   weights, consolidation deliberately dominant by default because its
   absence is the documented failure of the caller-side implementation the
   brief replaces.
10. **Step budget**: single shared integer budget
    (`RepackOptions(stepBudget: 200_000)` default) counted in elementary
    search steps across Phase A improvement, Phase B backtracking nodes,
    and Phase C repair attempts. Bounded by steps, not wall clock, for
    reproducibility. **Amended by decision 36**: the last placement step
    has a second budget of the same size. **Amended by decision 49**: a
    budget below the default also shortens the searches that count their
    own work.
11. **Algorithm** follows the brief's three-phase shape:
    - **Phase A** assigns events to sessions (greedy by tightest
      participant first, scored by the two weights, capacity- and
      pin-aware) then improves by bounded single-event moves, then repairs
      slot-level parity (|{v : d_v > c}| must be even) by further moves —
      computed against pin-adjusted target sets, so pins are consulted
      *before* loads are fixed (the Cinder Row trap).
    - **Phase B**, as first built, packed each session by prefix-target
      matching: each participant's ideal slot set was computed around its
      pins (bridge internal gaps first, extend downward toward slot 0, then
      upward), and a bounded depth-first search placed one perfect matching
      per slot, so that success meant zero gaps and zero late starts.
      **Replaced by decision 16**: the shipped Phase B places each
      participant on a gap-free run that need not start at slot 0, tries
      the placements with the least total late start first, and still
      places one perfect matching per slot. Success means zero gaps; a
      late start is reported.
    - **Phase C** falls back to prioritized greedy packing
      (critical-participant-first) plus bounded repair moves within the
      session (direct moves, then two-colour alternating-chain (Kempe)
      swaps that preserve properness; chains containing a pinned event are
      unswappable), then a final cross-session sweep tries any remaining
      free colour anywhere before an event is declared unplaced.
      **Extended by decision 33**: what the sweep leaves over goes to a
      last placement step that moves placed events.
12. **Audit is a separate final pass** over pins + assignments producing
    the violation list from observed occupancy, so reported violations are
    facts about the returned schedule, not solver intentions. Double-booking
    is audited too even though the algorithm cannot produce it — the brief
    calls it "should be unreachable", and the audit is the proof.
13. **Fixture placement**: `repack-scenario.json` lives in
    `tests/Fixtures/` as a synthetic instance with the brief's structure
    (brief §9: fixture built from the file, tables must not drift). The
    regression test derives both the clean reference run and the
    mis-pinned variant (pins built from `evacuatedDefaultedEvents`) from
    that one file, and pins the fixture's own tables (counts, loads,
    labels, the mis-pinned arithmetic) so a hand edit cannot drift them
    silently. Slot concurrency for the fixture grid is 7 (14 participants
    ⇒ 7 simultaneous events).
14. **`RepackViolationsException`** (new, extends `SchedulingException`)
    carries the full `RepackOutcome` so opting into throw-on-violations
    loses nothing.
15. **Integer index normalization.** The fixture's numeric-string ids hit
    PHP's array-key coercion ("13371" becomes int 13371 as a key), which
    silently breaks strict comparisons. Rather than sprinkle casts, the
    orchestrator maps every event and participant id to a dense integer
    index at the boundary (index order = sorted-id order, so index
    tie-breaks are id tie-breaks) and the internal phases are purely
    integer-keyed. **Amended by decision 39**: the indexes are still
    dense integers, in an order that is no longer the id order.
16. **Intervals, not prefixes.** The brief's Phase B sketch (participant
    with load d occupies slots 0..d−1) turned out too strict on the real
    instance: a session holding an odd number of participants can never
    be prefix-packed (someone must sit out every slot), but sitting out
    the *first* slot is only a `LateStart` — an objective, not the hard
    constraint. The shipped model places each unpinned participant on a
    contiguous *run* of free position; run-placement parity reduces to a
    boundary-flip bitmask DP (`IntervalPlacement`), which gives Phase A a
    cheap infeasibility score and Phase B an enumerator of concrete
    placements ordered by total late-start depth, so
    gap-free-and-from-the-top is still tried first. Phase A's parity
    repair needed whole-participant eviction moves for exactly the
    odd-participant-count case (single-event moves cannot express "one of
    the thirteen has to go"), plus cross-session swaps.
17. **Late starts are not "unclean" failures.** The brief's acceptance bar
    is "zero and zero" = zero double-bookings, zero interior gaps
    (§9.5 lists late starts nowhere). The shipped result on the
    reference instance: 71/71 placed, 0 double-booked, 0 gaps, 7 late
    starts of depth 1–2 — several provably forced (odd participant count
    in a session ⇒ someone cannot start at slot 0). The regression test
    asserts the kinds separately rather than `isClean()`, which remains
    strict (any violation, late starts included, makes it false).
18. **Mis-pinned variant residue.** Beyond the specified expectations
    (`CapacityExceeded` Fallowmead shortfall 3, exactly those 3 unplaced,
    zero double-bookings, pins immobile), the variant leaves two 1-slot
    interior gaps caused by the pins — reported honestly, asserted
    loosely (the brief demands the capacity story, not gap-freeness,
    from the deliberately-wrong instance).
19. **`examples/index.php` was not updated** for example 19 at the time:
    it listed examples 1–12 only, and examples 13–18 had shipped without
    index entries. **No longer true**: since 0.2.1 the index and
    `examples/README.md` list every example, and
    `tests/Feature/ExamplesTest.php` fails when either is missing one.

## API additions (0.2.2)

Decisions made when the API was extended for a downstream consumer that
works in local wall-clock time and has no shared resource limiting a slot.
No placement, drop rule or reported number changed, and the golden
fixtures are untouched. Two decisions reject input that was accepted
before (31 and 32); the changelog lists them as output changes.

20. **A shape-only grid is the same class, not a second one.** The
    repacker reads slot counts and a capacity and nothing else, so a grid
    without instants needs no new algorithm, only a grid that can say "I
    have no instants". `SessionGrid::shapeOnly()` builds one. A second
    class would have needed an interface over both and a change to the
    type `RepackRequest` accepts; a flag on the one class changes no
    signature. The constructor's first two parameters were widened to
    carry the form (`array|int` session starts, a nullable interval),
    because a `readonly` class has one constructor and no other way to
    initialise its properties that static analysis accepts.
21. **A shape-only grid never invents an instant.** `getSessionStart()`,
    `getSlotInterval()`, `getSlotTime()` and `positionOf()` given an
    instant throw, and an assignment made on such a grid has a null
    kickoff, which `SlotAssignment::getKickoff()` refuses to return.
    Returning a placeholder time was rejected: the consumer this is for
    was fabricating instants and ignoring the kickoffs, and a placeholder
    that looks like a time is how a wrong time reaches a user. What is
    thrown is an `UnavailableValueException`, a `\LogicException`, here
    and in decision 25. Asking is the caller's mistake, which
    `hasInstants()`, `hasKickoff()` or `hasUnboundedCapacity()` would
    have prevented, not a configuration that cannot work. An
    `InvalidConfigurationException` was the first choice and was
    rejected for what it does to code that already exists: it is a
    checked exception, so `getKickoff()`, `getSlotInterval()` and
    `getCapacityPerSlot()` would each have declared one, and static
    analysis would have reported every existing call to them after a
    patch upgrade, in code that never sees a shape-only grid.
22. **Wire shapes of existing grids do not change.** A shape-only grid
    serializes with `session_count` in place of `sessions`, `timezone`
    and `slot_interval`, and `fromArray()` reads that form only when there
    is no `sessions` key: that input threw before, so no input that
    worked reads differently. `session_count` beside `sessions` is still not
    read, as it was. It is a key of the grid, so it is not refused as an
    unknown one now that unknown keys are.
23. **Unbounded capacity is `null` in PHP and the word `unbounded` in
    plain data.** A null `capacity_per_slot` in plain data has always
    meant "not given, use 1", so null could not be given a second meaning
    without changing what existing configuration does; a string there
    threw before. The default stays 1 in this release.
24. **Unbounded means the number of events in the request, internally.**
    No slot can hold more events than the request has (movable and
    pinned), so that number never binds, and the planner's capacity sums
    cannot overflow as they would with `PHP_INT_MAX`. The result is the
    one any larger finite capacity gives (an invariant test holds the
    repacker to that). `intdiv(participants, 2)`, the value a caller
    would compute, is also never binding per slot, and was not used
    because with an odd number of participants it makes the planner
    report the grid as too small when what stops an event is its
    participants.
25. **`getCapacityPerSlot()` throws on an unbounded grid**
    (an `UnavailableValueException`); `getCapacityLimit()` returns the nullable
    value. The return type of the existing method is `int`, and any
    integer it returned for "no limit" would be used in arithmetic.
26. **`positionOf()` takes an ordinal or an instant.** A caller with its
    own slot records needs position ⇄ index; a caller with instants needs
    instant → position. Both are reverse lookups of the same grid, so one
    method takes either, and `ordinalOf()` is the forward direction of
    the first. A lookup that finds nothing returns null; only asking a
    shape-only grid about an instant throws.
27. **The budget flag is recorded where the budget is asked.** Every
    search learns that the budget is gone in one of two ways:
    `StepBudget::consume()` refuses a step, or `isExhausted()` answers
    true. Both set the record, so no phase has to remember to. The sites,
    for the record: `LoadPlanner` (`placeWithRelocation()`, `improve()`,
    `repairParity()` and its three move kinds), `IntervalPlacement`
    (`enumerate()` and `search()`), and `SessionPacker` (`matchNext()`,
    which raises the internal `BudgetExhausted`, and `repair()`). Every
    caller of `isExhausted()` asks only while it has more to search, which
    is what makes a true answer a search cut short; spending the last
    step on a search that then finishes is not exhaustion.
    `hasStepsLeft()` is the same question asked without recording
    anything, for the one caller that asks before it knows whether there
    is a search to stop: `IntervalPlacement::enumerate()`, which with no
    step left decides only whether a placement exists, and calls
    `isExhausted()` when one does.
28. **What the flag means.** False: the budget stopped nothing, so the
    run is the run any larger budget gives (an invariant test checks
    that). True: a search was cut short and a larger budget may differ.
    It is not in `toArray()`, because existing wire output may not gain a
    key in a patch release. Decision 36 says how the last placement step
    keeps this meaning, and decision 49 how the work limits that a budget
    below the default makes smaller do.
29. **The fingerprint is over named keys of `toArray()`.** The three
    lists are what an outcome is, and `toArray()` is already the pinned
    wire shape, so the values come from there. The keys do not: scheme
    `v1` lists the keys of each record class, and the fingerprint reads
    those and no others. Hashing whatever `toArray()` returns was
    rejected, because a key added to a wire shape in a later release
    would then change every `v1` fingerprint without a new scheme, and a
    specification that says "what `toArray()` returns" cannot be
    implemented from the page. A violation class from outside the
    library has no such list and is covered by its whole `toArray()`.
    The encoding is specified on `RepackOutcome::fingerprint()`:
    length-prefixed strings and counted lists (no value can be mistaken
    for two), map entries and the three record lists in byte order (so
    construction order cannot reach the hash), floats as their IEEE 754
    bytes (so no formatting setting can), SHA-256, and a scheme (`v1`)
    both in the hashed document and in front of the digest. `serialize()`
    and `json_encode()` were rejected: the first is a PHP-internal format,
    and the second writes floats by `serialize_precision`.
30. **The budget flag is not in the fingerprint.** The fingerprint
    answers "is this the plan that was shown?". Two outcomes with the
    same assignments, unplaced events and violations are the same plan,
    whichever budget produced them. Kickoffs are in it: the same
    positions at other times are not the same plan.
31. **Weights are bounded where the objective stops being an integer.**
    The planner's score is at most
    `earlyFillWeight × (sessions − 1) + 2 × consolidationWeight` in
    magnitude. Past `PHP_INT_MAX` PHP computes it as a float, where the
    smaller weight is lost to rounding. `RepackOptions` rejects a
    consolidation weight above half of `PHP_INT_MAX`; `RepackRequest`
    rejects the whole expression for its grid. Both reject on the range
    the objective can reach, without running it, so a request whose
    events happen never to reach the largest term is rejected as well.
32. **A malformed `RepackOutcome` is an `InvalidInputException`.** A list
    holding something else died with a PHP `Error` on the first method
    call. It is input to a value object, not a configuration, so it is
    reported the way the other value objects report theirs.

## Placement fixes (0.2.3)

Decisions made when three kinds of broken output were fixed in a patch
release: events left unplaced although a placement held them, an
over-capacity drop rule that dropped more events than needed, and
`CapacityExceeded` violations that understated demand or left a
participant out. No public signature changed. An outcome with no unplaced
event and no `CapacityExceeded` is byte-identical to the one 0.2.2
returned; the changelog lists what changed for the others.

33. **A last placement step moves placed events.** Phase A's single-move
    relocation and the final sweep never move an event that is placed, so
    an event they leave over stays unplaced however large the budget.
    `LeftoverRecolourer` runs after the sweep, only when an event is still
    unplaced, so an outcome with nothing unplaced is untouched by
    construction. It searches alternating paths (ejection chains: the
    event takes a position where one placed event is in its way, that
    event moves on in the same way, until one lands where nothing is in
    its way; where both participants are in the way, both events move and
    the path branches), the edge-colouring counterpart of an augmenting
    path, with a Kempe chain as one case. Each event moves at most once per
    search, so a search is linear in the placed events. Where no path
    exists, it tries an exchange: an unplaced event takes the position of
    one placed event, which leaves the schedule, and the exchange stands
    only when an alternating path, or one more exchange, then places
    another event. Every move is journalled and taken back unless the
    count of placed events rises, and every intermediate state is proper,
    respects pins and respects capacity. Placement comes before contiguity
    here, as it already did in the sweep: moved events can leave gaps and
    late starts, which the audit reports. Lifted events try their own
    session's slots first, to keep the planner's loads. A full exact
    search (an integer program, or a matching formulation over all
    positions) was rejected: it would not be bounded in steps, and the
    alternating paths reach the optimum on every instance tested (below).
    Two limits of the step's own, independent of the budget, bound the
    exchange search: two levels of exchange, and 2,000 exchanges per
    search; and no exchange is searched for once the placed count reaches
    a ceiling no placement can pass. The ceiling is the smallest of four
    bounds: all the events less the number no placement can hold (the
    planner's drop count when its search for shared drops finished, and
    otherwise the shortfalls less a bound on the shared drops that needs
    no search, decision 35); half the sum, over
    participants, of the smaller of event count and unpinned positions;
    the grid's places; and, position by position, half of each connected
    group of participants not pinned there, rounded down, and no more than
    the position's places. Without the first and the last, a request whose
    leftovers no placement can hold (only over-capacity drops left over, or
    a group with an odd number of participants, which leaves one of them
    out of every position) ran a hopeless exchange search: it spent the
    whole second budget, took a second or more where 0.2.2 took
    milliseconds, and reported the budget exhausted on an outcome 0.2.2
    reported without, identical otherwise. The ceiling does not see every
    request whose leftovers cannot be placed (a group of five linked to
    the next by one event makes the last bound count a pair at every
    position that only one position can hold), and on those the exchange
    search ran to its limits on every round: up to eight seconds on
    requests of 300 to 800 events that 0.2.2 repacked in under half a
    second. So the whole step also stops searching after 500,000 position
    checks (`WORK_LIMIT`, about a sixth of a second; more on a large
    request since 0.3.0, decision 48), a limit of its own
    like the others. It is about 25 times what the complete round robins
    of up to 40 participants and the small corpus need, which still reach
    their optimum; on a few requests it leaves an event unplaced that a
    longer search would have placed: on over-capacity requests near a
    full grid it cost one or two events on 20 of 120 requests measured,
    and on one request of 84 events it placed 65 where an unlimited search
    placed 67, always at least what 0.2.2 placed.
    Direct placements continue after it, so `no_slot_available` stays
    literally true.
34. **The over-capacity drops are offered to the last step too.** A
    participant can never hold more positions than it has free, so placing
    a dropped event cannot break its capacity; it only changes which of
    its events is the one that does not fit. Which events to drop decides
    what fits around them, and no rule on the event list alone gets it
    right every time (the small corpus has instances where dropping by
    opponent slack leaves an event over that dropping another would have
    placed). When the step places a dropped event, the reasons are given
    again from the events still unplaced, by the rule of decision 35;
    while every drop is still unplaced, the planner's reasons stand.
35. **Shared drops first, and demand before any drop.** An event between
    two over-capacity participants counts towards both shortfalls, so as
    many of those as can count twice are dropped first: a maximum
    b-matching on those events, each participant's b its shortfall. It is
    found by a depth-first search started from a greedy set, bounded by a
    fixed 100,000 nodes and not by the step budget, so that which events
    are dropped never depends on the budget (at the default budget and
    above, since decision 49: a smaller budget leaves the search its
    share of the nodes). The bound is not always
    enough: a dozen over-capacity participants sharing some forty events
    reach it, and the set found can then be smaller than the largest
    (18 where 20 exist), so the planner drops more events than the
    shortfalls need. The search reports whether it finished, or found a
    set as large as a bound that needs no search allows; only then does
    the last placement step take the drop count as the number of events
    no placement can hold, and otherwise it uses the shortfalls less that
    bound (half the sum, over the participants, of the smaller of the
    shortfall and the shared events). The step is offered the drops, so
    it places what the extra drops left out where it can. This search,
    run once by the planner and once more when the step relabels, is
    bounded by its nodes only, not by a step budget: about 40 to 65
    milliseconds each on 400 to 700 shared events. Each
    participant still short then drops by opponent slack, as decision 8
    said. A dropped event between two over-capacity participants names the
    one with the larger shortfall, then the lower id. Every over-capacity
    participant is reported, with the demand it had before any drop,
    largest shortfall first, then id: the order in which the planner always
    processed them, so an outcome with one over-capacity participant, or
    several that do not meet, reports exactly what it did. The grid's
    `CapacityExceeded` keeps its documented meaning (the events left after
    the participants' drops); it was not understated.
36. **The last step has a budget of its own, the same size.** On the
    largest requests the packing searches spend the whole budget before
    the sweep (the complete round robins of 24 and 40 in issue #48 did),
    so a step that drew on the same budget would never run where it is
    needed most. A second `StepBudget` of `RepackOptions::$stepBudget`
    steps is made for it, and every alternating-path node and every
    exchange spends one step. The searches before it stop exactly where
    they stopped in 0.2.2, because nothing they draw on changed. When the
    first budget is already spent the step runs anyway, on its own
    budget; when its own budget runs out it stops, keeps what it has
    placed, takes back the move it was in, and only direct placements
    (which are not a search) are made after that. `isBudgetExhausted()` is
    true when either budget stopped a search. Its documented meaning
    holds: when no budget stopped a search, every search ran to its own
    end, the step's limits of its own (depth, exchanges, ceiling, position
    checks) do not
    depend on the budget, and so a larger budget gives the same outcome;
    an invariant test checks it on the small corpus. (Since decision 49
    the limit on position checks does depend on a budget below the
    default, and a search that such a smaller limit stops is counted as
    one the budget stopped, which keeps the meaning.) The cost is that a
    repack can spend up to twice `stepBudget`, which the usage guide, the
    `RepackOptions` docblock and the `StepBudget` docblock state. An
    option to switch the step off, or to size its budget, was not added:
    a patch release adds nothing to the public signature.

## Grid correctness (0.3.0)

37. **Sessions that overlap are a configuration error.** The grid checked
    only that session starts ascend. Sessions at 20:00 and 20:25 with two
    slots 25 minutes apart put two positions at 20:25, and the repacker,
    for which collision is exact (session, slot) identity, placed one
    participant in both and reported no double booking. A grid is now refused
    at construction when the last slot of a session is at or after the
    start of the next (`OverlappingTime`). It throws, where the repacker
    reports: an unsatisfiable request is an outcome with violations, and
    a grid that contradicts itself is broken input, like a pin off the
    grid (decision 5). The check is on the slots a session declares, not
    on the ones a repack happens to fill, so a grid is valid or not
    whatever is placed on it. A shape-only grid has no instants and is
    not checked: its caller owns the times. The slots within a session
    are checked in the same pass (each after the one before it), because
    a slot interval that PHP adds as local time, one that `diff()`
    returned, puts two slots at one instant where the clocks skip an
    hour. The grid keeps the instants it computed there, so
    `getSlotTime()` and `positionOf()` read them and compute nothing.
38. **A slot is computed from its session's start.** `getSlotTime()` added
    the slot interval once per slot, each addition from the slot before,
    so a slot that landed in the hour the clocks skip moved every slot
    after it. It now adds the slots as one step from the session start,
    with the arithmetic the timeline uses
    (`Timeline\IntervalArithmetic`, internal). Slot intervals of hours
    and minutes, which is what a session has, are unchanged.

**The counts of a grid have stated limits** (0.3.0, with decision 37). A
grid that checks and keeps the instant of every position costs time and
memory in proportion to its positions, and the counts that make them up
are integers a caller gives: a `session_count` at the largest integer
raised a `ValueError` from `array_fill()` (as it did in 0.2.3), and a
slot count there looped without end in the check decision 37 added,
where 0.2.3 had built the grid at once and failed later, when the
positions of two such sessions were counted. No arithmetic replaces the check: where two slots
fall on one instant depends on the timezone's clocks. So the positions
are limited (`SessionGrid::MAX_POSITIONS`, 100,000) and every count is
refused above the limit before anything of its size is allocated or
visited. The value is set by the instant-based grid, some 40 MB and a
sixth of a second at the limit, and applies to the shape-only one too,
so that one number describes a grid; one limit on the positions was
chosen over a limit for each count because the positions are what is
paid for. The capacity has a limit for another reason
(`MAX_CAPACITY_PER_SLOT`, a million million): the planner adds it up
over the positions, and that sum left PHP's integers for a capacity near
the largest integer (a `TypeError` inside the repacker, in 0.2.3 as
well). The limit is the largest round number whose sum over the most
positions is far inside the integers, and it is far above the numbers
written to mean "no limit", which therefore keep working; a lower one
would have refused grids that 0.2.3 repacked. Within a session, the
slots of an interval made from a relative date string are now each
computed from the one before, which is what that interval's steps are:
computing each from the session's start cost the square of the slot
count in additions.

## Order and contiguity (0.3.0)

Decisions made when two things were changed in a minor release (issue
#49): the quality of a repack depended on how the caller named its
events and participants, and the session of each event was chosen by the
two weights before any slot was looked at, so a gap was reported where a
placement without one existed. Almost every outcome changes. No public
signature changed.

39. **The internal order is made from the request, not from the ids.**
    `StructuralOrder` orders the participants by their number of movable
    events (more first), then by the positions they are pinned at, then
    by the same facts about their opponents as far as that tells them
    apart (colour refinement, with the number of events against each
    opponent and the positions of the pins), and by id last. The events
    are ordered by their two participants and by id only between events
    of the same two. The issue asked for "degree, then id". That is not
    enough: in a round robin with rounds played every participant has
    the same degree and the same pinned positions, and on those requests
    an order by degree and id gave different counts under other
    participant ids (measured on round robins of 20 to 40 with five
    rounds played). So where refinement leaves a class of several
    participants, each of them is tried as the one to put first, and the
    one whose choice gives the refined classes the smallest description
    is taken; a class whose members can simply be exchanged (each has
    the same events against every third participant, as in a complete
    round robin) is put in id order without a trial. What this gives,
    stated exactly: the outcome is a function of the request written in
    that order, so two requests that differ only in their ids have the
    same outcome up to the names whenever they are written alike, and
    that is whenever the id only decided between participants a symmetry
    of the request exchanges. Then the number of events at every
    position, the unplaced counts by reason and the violations but for
    their names are equal, and the schedules are equal participant for
    participant up to such a symmetry (which of two like participants
    gets which part of the schedule follows the ids; it cannot follow
    anything else). It is not a canonical labelling, and no such thing is
    claimed. There are two exceptions, and a request is known for each
    on which the counts differ under other participant ids. Two
    participants that differ but have equal descriptions while the
    classes are still larger than one are ordered by id: take the
    Shrikhande graph and the four-by-four rook's graph side by side as
    one request (32 participants with six events each, 96 events), which
    refinement cannot tell apart and no symmetry exchanges; on 32
    sessions of three slots with one event to a slot it came back with
    83 late starts under one naming and 84 under another (every event
    placed, no gap, no budget spent), and on tighter grids with other
    numbers of gaps. No request that was not built for the purpose has
    shown this (none of 31,000 relabellings of 10,000 generated
    requests). And past the work limit of the ordering the classes left
    are split no further and are put in id order. The limit is 1,500,000,
    counted as one for every participant looked at and one for each of
    its opponents, and it is sized for the hardest request of 40
    participants: a complete round robin with one round played, where
    everybody is alike and no two can simply be exchanged, needs
    1,330,000 (under a tenth of a second), with 36 participants 870,000
    and with 44 participants 1,940,000, which is past it. The limit was
    600,000 at first, on the reasoning that five rounds played need
    100,000 to 500,000; with one round played a round robin of 36, 38 or
    40 then came back with other counts under other ids (40 on sessions
    of four, five and six slots: no gap, nine gaps and ten gaps under
    three namings), which is a request a season one round old makes. The
    refinement itself also stops at the limit: a chain of participants
    takes one pass for every two of them, and without that a chain of
    3,000 took 1.5 seconds to order where it now takes 0.15. This limit
    is the one work limit that no step budget makes smaller (decision
    49). A full canonical
    labelling (individualisation and refinement with a search over every
    choice, pruned by the symmetries found) was rejected: on a request
    where everybody is alike and no two can simply be exchanged, its
    search tree without that pruning has a factorial number of leaves,
    and with it the work is more than the repack it serves. An event id
    decides only which of the events between the same two participants
    is which, with no exception. What an outcome returns is ordered as
    before: assignments and unplaced events by event id, violations by
    kind and then participant id and session, over-capacity participants
    by shortfall and then id (the audit and the orchestrator sort for
    that, since the indexes no longer do). Two ties changed with the
    indexes: an event dropped between two over-capacity participants of
    the same shortfall names the one first in the structural order, not
    the lower id; and of an over-capacity participant's events against
    opponents of the same slack (decision 8) the one dropped is the last
    in the repacker's order of events, not the one with the highest id.
40. **The events are split into rounds before any session is chosen.**
    A round is a set of events that can share a slot. `RoundColouring`
    lays the rounds on the grid's positions in grid order, around the
    pins: it is a proper edge colouring onto positions, with the pinned
    events already coloured. The planner then takes the events round by
    round. Its greedy choice by the weights is the same code as before;
    what changed is that the session it prefers is now one that packs,
    because it is filled with whole rounds (every participant of a round
    has an event in every slot) and not with everything a few
    participants have between them, which is what an order by
    participant gave. The number of rounds aimed at is the
    fewest there can be (the most events any participant has, or the
    events over the capacity if that is more). Each event first tries
    those rounds, starting at the one its two indexes add up to: on a
    complete round robin of an even number that is the circle method and
    leaves nothing to search for. Several events between the same two
    participants start in successive blocks of rounds, which makes a
    round robin of several legs one circle per leg. What is left over is
    placed by `LeftoverRecolourer`, first on those rounds alone and then
    on the whole grid. Searching the first rounds alone before the rest
    of the grid matters: without it an event that did not fit at once
    went to a spare position at the end, and the rounds had holes (1,166
    gap slots in the raw layout over 84 season-like requests, against
    205 with it). The search has no step budget, only its own limit on
    position checks, so the order of the events never depends on the
    caller's budget (true as written until decision 49, and since then
    of the default budget and every larger one: a smaller budget leaves
    the search its share of the limit). Cutting that limit to under a third for speed was
    measured and rejected (191 gaps over the same 84 requests against
    93).
41. **The rounds are a schedule too, and it is returned when it is
    better.** The planned schedule is compared with the rounds as they
    were laid out: the rounds are returned when they leave fewer events
    unplaced, or as many with fewer gap slots. That is where "contiguity
    ahead of consolidation" is decided: the planned schedule is the
    weights' choice, the rounds are made with no regard to them, and gap
    slots decide between the two. On a tie the planned schedule stands,
    so the weights still decide whenever they cost no gap. Late starts
    are not compared: they are an objective like the weights. Passing
    the rounds to the packer as a starting point for its fallback was
    tried and dropped: the planner moves enough events between sessions
    that the starting point no longer fits.
42. **A schedule that still has a gap is searched for a better one.**
    `GapSearch` is a depth-first search over placements, each event
    tried where it is, then everywhere else, then (while no more events
    are left out than were) nowhere, cut where the gap slots a
    participant can no longer close reach the best found. It takes a
    placement only with strictly fewer gap slots, as many events placed,
    and no event left out that a free position holds, so
    `no_slot_available` stays literally true. The events that have to do
    with a gap are decided last, so the search changes them first: on a
    large request it searches the neighbourhood of the gaps, and on a
    small one every placement. It spends from the second budget and
    stops after 200,000 position checks of its own (about a tenth of a
    second; twice as many closed no further gap on the 84 season-like
    requests; a share of them below the default budget, decision 49).
    Against an exhaustive search
    (`RepackBruteForce::fewestGapSlots()`), 3,000 random requests of up
    to seven events and eight positions all came back with the fewest
    gap slots any placement of as many events has; half of the limit
    was enough for all of them. A local search by alternating
    chains between any two positions was considered first and not
    built: it cannot change which event is left out, and it stops at a
    schedule one exchange cannot improve, which on small requests is
    not the best one.
43. **One session's exact search has a share of the budget.** A session
    with no gap-free packing can keep its search going to the last step,
    and every session after it was then packed greedily. The search of
    a session now gets a quarter of the steps left when it starts.
    A search its share stops is recorded as a search the budget stopped.
    It had a cost the budget did not show: with a share each, every
    session of a request whose sessions do not pack did work that one
    session used to do, some of it not counted in steps. On 800 events
    among 200 participants a repack took 3 seconds on sessions of 12
    slots where 0.2.3 took 0.3, and 10 seconds at 16 slots where it took
    3.7 (with 767 and 1,104 gap slots for 1,336 and 1,703). Decision 47
    says what that work was and what was done about it. A complete round
    robin on sessions that wide packs without a search and takes
    milliseconds.
44. **What did not get better.** Late starts are not an aim of any of
    this, and the reference scenario has 10 where it had 7 (no gap
    either way). Requests with many pins and sessions of unequal length
    still end with gaps and a spent budget more often than not at 30 to
    40 participants: the planner still decides sessions before slots,
    and the rounds only make its choices better. The searches are
    heuristics on another order of events, so a request can come back
    worse than it did. Of 11,860 generated requests compared with 0.2.3
    one by one, none places fewer events (decisions 45 to 48; 23 did
    before them) and 146 place as many with more gap slots (241 before
    them), 380 gap slots more between them, against 3,385 that are
    better by events placed, then gap slots, then late starts; the
    changelog has the table. Of the 146, 63 are runs a budget stopped.
    Round robins of an odd number of participants gain next to nothing
    in sum (376 gap slots for 372 on 142 of them without pins, more on
    27 and fewer on 34; 408 before the third schedule): a round of an
    odd number leaves one participant out, and where that is in the
    middle of a session the round laid out as it stands has a gap, so
    the rounds are no schedule there and it has to come from the
    planner, whose order of events 0.2.3 happened to be given at random
    by the ids of these requests. Taking the number of rounds
    from the participants as well as from the capacity (no round holds
    more events than half the participants, so an odd round robin needs
    one round more than its participants have events) was measured: the
    layout was quicker and the gaps were the same, and it was left out.
    One of the four repack
    benchmarks is slower (70 ms to 154 ms): 0.2.3 packed that request
    without a search, and the layout leaves 35 of its 770 events over
    after the first pass, which the search then fits into the same 39
    rounds with 260,000 position checks, 0.1 seconds. That work is what
    gives the planner whole rounds, so it was not cut. Putting the positions first throughout, and deciding
    sessions from them, is the design this points to and was not
    attempted here.
45. **The last placement step searches further, because the events
    placed come first.** Compared request by request with 0.2.3 (11,860
    generated requests), the change of order as it first stood placed
    fewer events on 23 of them, and on a round robin of 40 with ten pins
    on a grid it fills exactly (768 of 770). All but a few are requests
    in which participants have more events than positions, or the grid
    fewer places than events: 0.2.3 filled every place, and the new plan
    ended one place short with nothing the step could do about it. Which
    events of an over-capacity participant are left out decides whether
    the rest can fill the grid, the order of the events decides which
    are dropped first, and the step could change that by one exchange at
    a time and no further. Four things changed in it. Every single
    exchange is tried before any chain of two (the search went depth
    first, and spent its 2,000 nodes under the first few unplaced events,
    several of them copies of one pairing; a copy is now skipped, and so
    is an exchange of an event for another of the same two
    participants). Between the two it tries an exchange that takes two
    events out: the unplaced event takes a position where both its
    participants have an event, both leave, and two of the events
    waiting must then be placed; that is what leaves a state in which
    every unplaced event has two events in its way wherever it goes,
    which is where the round robin of 40 stood. Before either, a
    position with room takes one more event along an augmenting path
    among the events placed there and the unplaced ones, as a matching
    is augmented: any number of events out and one more in, at one
    position. And an alternating path may move an event an exchange
    brought in (it could not, and on a request of six events that left
    one out that fits). The moves spend steps of the second budget and
    count towards the step's own limit of position checks, as the others
    do. A swap of the chain between two positions, tried for every pair
    of positions an unplaced event's participants are free at, was built
    and taken out again: on the 11,860 requests no request placed fewer
    events than 0.2.3 with it or without it once the augmenting path was
    there, and the totals differed by one event and four gap slots.
    Without both, two requests of 30 and 36 participants placed one
    event fewer than 0.2.3.
    A move that rearranges placed events without placing one more, so as
    to make room for a later move, was not built: it has no measure to
    steer by, and the third schedule (decision 46) is the cheaper way to
    start from somewhere else.
46. **A third schedule, in the order of the participants, where the
    first two leave something to gain.** The stronger step alone still
    left five of the 11,860 requests with fewer events than 0.2.3 placed
    (three of them new ones: the round layout uses the same step, so its
    rounds changed, and with them the order of every event). No order of
    the events is best for every request: with the step as it was, the
    events taken round by round placed fewer than 0.2.3 on all 24
    requests (that is how they were found), taken by their participants
    on 8 of them, and in a third order made from the request on 10, not
    the same ones. So
    where the schedule chosen from the first two places fewer events
    than the ceiling of the last placement step (a bound no placement
    passes), or has a gap after the search for fewer gap slots, the
    events are planned, packed and swept once more in the order of their
    two participants, the last placement step and the search for fewer
    gap slots are run on the result, and it is returned when it places
    more events, or as many with fewer gap slots; on a tie the earlier
    schedule stands. That order is what 0.2.3 had in effect, with the
    structural order of the participants in place of their ids, so the
    outcome is still a function of the request written in that order
    and depends on the ids exactly as far as decision 39 says. It is
    made on what the two budgets have left and has none of its own: a
    repack still spends at most twice `stepBudget`, and a request whose
    searches spent the first budget gets a third schedule from the
    greedy packing only. A schedule that is already clean, or that
    places as many events as the ceiling and has no gap, costs nothing
    more. With both changes, none of the 11,860 requests places fewer
    events than 0.2.3 did, 52 place more, and the requests that place as
    many with more gap slots went from 241 to 148; the round robin of 40
    places all 770. The cost is time on the requests that are not clean:
    the 11,860 took 281 seconds in all where they took 220 (0.2.3: 106),
    and the 84 season-like requests of decision 40 took 16.8 seconds
    where they took 11.0 (0.2.3: 20.6), with 79 gaps for 93. Decision 48
    has the figures as they are with that decision too. More orders
    would be more of the same trade and were not added. What is still
    worse than 0.2.3 is in decision 44.
47. **The table of a session's exact search is paid for in steps.**
    Before it enumerates run placements a session works out, for every
    participant, the cheapest way to each parity mask: a table of up to
    two to the power of its slot count entries a row, which was not
    counted in steps. With a share of the budget for each session
    (decision 43) every session built one. A row now costs 16 steps for
    every full 1,024 entries, charged to the search's share before the
    row is made, and a table the share cannot pay for is given up: the
    session is packed greedily, and the outcome says a budget stopped a
    search. A row of fewer than 1,024 entries is free, which is every
    row of a session of up to seven slots, so those sessions spend
    exactly the steps they spent and no outcome on them changes (the
    reference implementation in the tests still spends the same). At
    the default budget the rows that are paid for come to at most 12.8
    million entries in a repack. The slowness measured on wide sessions
    had a second cause, and the larger one at twelve slots: the repair
    that follows a failed search counted the events of a slot, and
    looked up a participant's event in a slot, by going through every
    event of the session at every step, so a step cost work in
    proportion to the session, and with a share for each session the
    repair ran in all of them where it used to find the budget spent.
    It keeps both up to date as it moves events now; it makes the same
    moves and the outcomes are the same. On 800 events among 200
    participants a repack takes 0.9 seconds on sessions of 12 slots, 2.6
    at 16 and 10 at 18, where the first version of this change took 3.4,
    9.9 and 21 and 0.2.3 took 0.3, 3.7 and 17; the memory at 18 slots
    went from 890 MB to 16 MB. What is left at 16 slots and more is the
    planner's parity score and the check made on a spent budget, which
    still work through every mask of a session and are not counted in
    steps; no session of more than 20 slots is reasoned about that way,
    which bounds them. Twelve slots are still three times slower than
    0.2.3 on that request, because every session's search and repair
    now run where one did, and that is what the share is for.
48. **The step's limit on position checks grows with the request.**
    The 11,860 requests are of 40 participants at most and most are much
    smaller. On 151 larger and harder ones (round robins of 39 and 40
    with up to 400 events pinned, of two legs with 100 pinned, 800
    random events among 12 to 800 participants, on grids from too small
    to roomy), decisions 45 and 46 still placed fewer events than 0.2.3
    on 11, by one to five events (13 before them). With six times the
    limit of 500,000 position checks (decision 33) they placed as many
    or more on every one of them, most with far fewer gaps: the step
    was stopped while it was still placing events. A fixed number of
    checks is not the same amount of search on every request. An
    alternating path lifts each placed event once at most and looks at
    each position for it a few times, so the events times the positions
    is about what one search that fails costs, and 500,000 checks are
    the work of some five hundred of them on 45 events and 7 positions
    and of five on 770 events and 39. The limit is now 100 checks for
    every event and position, never fewer than 500,000 (so a request of
    up to 200 events on 25 positions has the limit it had) and never
    more than 3,000,000: without the upper end a round robin of two legs
    among 40 (1,460 events on 75 positions) had 11 million each time the
    step ran, and took eight seconds. It is fixed for a request and does
    not depend on the budget, like the limit it replaces, and the round
    layout has it too (decision 49 changes that below the default
    budget, and only there). With 50 for every event and position two of the
    eleven were still short; a limit on the work since the last event
    was placed, in place of a larger one, left two short as well. On
    the 151 requests none places fewer events than 0.2.3 now and 34
    place more (104,693 events for 104,392, 9,463 gap slots for 22,166);
    three place as many with more gap slots. With decisions 45 and 46,
    the 11,860 requests place 214,357 events (0.2.3: 214,288; this
    change as it first stood: 214,314) with 2,504 gap slots (10,024;
    3,382), none places fewer events than 0.2.3 and 146 place as many
    with more gap slots (241). The cost is time on requests that leave
    events over or keep a gap. The 11,860 took 288 seconds in all (0.2.3:
    106; as it first stood: 220), the slowest 1.8; the 84 season-like
    requests of decision 40 took 19.7 seconds (20.6; 11.0), with 77 gaps
    for 93; and the 151 took 148 seconds where 0.2.3 took 79, a round
    robin of 40 with pins 0.75 seconds at the median and 3 at most, one
    of two legs a median of 2 seconds and 4.8 at most, where 0.2.3 took
    0.9 and placed ten events fewer. A repack runs the step up to four
    times (twice for the round layout, once for each planned schedule),
    so its work in this step is at most 12 million position checks,
    some four seconds. It is longest for nothing where the leftovers
    cannot be placed and the ceiling does not see it: 800 events of one
    participant against three others, of which 60 fit, took 0.2 seconds
    and takes 1.5. A bound from a set of participants that every event
    touches would see that one, and was not added here.
    A second review compared with 0.2.3 on requests made another way,
    and "none places fewer" does not hold outside the two sets above.
    Of 200 round robins of 16 to 40 participants with an eighth to a
    half of their events pinned at positions drawn at random, on a grid
    with a place for every event or one position to spare, 11 place
    fewer events than 0.2.3 (by one to three), 100 place more, and the
    200 place 55,265 for 54,946 with 904 gap slots for 1,557; they took
    0.56 seconds at the median and 3.1 at most where 0.2.3 took 0.1 and
    1.4. Of 600 larger requests of mixed kinds two place fewer and 35
    more; of 7,500 small ones of the over-capacity kinds none does and
    41 place more; of 320 seasons of 20 to 40 participants with whole
    rounds pinned none does and 144 place more. The ids of those
    requests put the events in no order, which is the order 0.2.3 took
    them in, and on nine of the eleven the step places as many as 0.2.3
    or more with forty times its limit of position checks and fifty
    times its limit of exchanges, in two to five times the time: the
    limit is a trade and those requests are where it is paid. The same
    review made the exchange search depth-first again in a copy of the
    source and compared: over 600 large requests the copy placed four
    events more in all (more on three requests, fewer on one) and over
    2,500 small ones as many on every request, so trying every single
    exchange before a chain of two is neither needed nor harmful on
    anything measured, which is why no test holds it. And it measured a
    budget of one step, where nearly all the time left is the round
    layout (decision 40), whose limit does not depend on the budget: up
    to 2 seconds on 807 events, against 0.06 with the layout's search
    switched off (and 780 events placed for 793). Giving the layout a
    share of this limit in proportion to a budget below the default
    would make `stepBudget` bound the time, and would make the rounds,
    and so the outcome, depend on the budget where the budget flag is
    false today; it was left as a decision to take, and is taken in
    decision 49.
49. **A budget below the default shortens every search, and says so.**
    `RepackOptions::$stepBudget` is the caller's one control over the
    work of a repack, and decisions 39 to 48 had put most of that work
    where it did not reach. A step is one node of a search, and a node
    of the last placement step or of the search for fewer gap slots
    looks at every position; the round layout spends no step at all.
    Each of those stops at a limit on its own work, and the limits did
    not depend on the budget. So a budget of one step shortened the
    planning and the packing and nothing else: seasons of 40
    participants over two legs with rounds played took 0.67 seconds at
    the median and 1.6 at most where 0.2.3 took 0.07 and 0.15, round
    robins with scattered pins 0.27 and 1.3 where it took 0.008 and
    0.035, and the slowest request found (two legs among 29, 807
    events) 2.0 seconds where it took 0.03. Some 97% of what was left
    was the round layout. What was decided, each point on its own:

    - **The limits are scaled, in proportion, below the default budget
      only.** A search that counts its own work gets
      `min(1, stepBudget / 200,000)` of its limit, rounded down
      (`StepBudget::workLimit()`; 200,000 is the default of the option,
      which the limits were sized with, held equal to it by a test). At
      the default and above every limit is what it was, so every
      outcome at those budgets is what it was, byte for byte: 13,293
      generated requests (662,766 movable events), repacked at the
      default by a copy of the source as it stood and by this, and
      4,533 of them also at twice the default and 2,000 at the largest
      budget there is, gave the same assignments, unplaced events,
      violations, fingerprint and flag on every one. A larger budget than the
      default was not given larger limits: that would have changed
      outcomes above the default, where the flag was false.
    - **Which limits.** The last placement step's limit on position
      checks (decision 48), wherever it runs: twice for the round
      layout, which gets a budget of its own for the purpose with steps
      that do not run out and the caller's budget for the share, and
      once for each planned schedule. The search for fewer gap slots
      (200,000 position checks, decision 42). And the planner's search
      for the events two over-capacity participants share (100,000
      nodes, decision 35), which runs once for each planned schedule and
      again when the unplaced events are given their reasons, and ran to
      its whole limit whatever the budget: giving the unplaced events
      their reasons took 34 of the 136 milliseconds a season of 40 took
      at one step. The limit of 2,000 exchanges for one exchange search
      and its depth of two (decision 33) are not scaled: an exchange
      spends a step and counts position checks, so both of the others
      already bound it.
    - **What a share of the shared-events search changes in the
      report.** Found by the last review, and a consequence of the
      point above, not a change to it. Until this decision that search
      was the same at every budget, so the capacity violations of a
      request were too. The over-capacity participants still are (their
      demand and capacity are counted before any event is dropped). The
      grid's own violation is not: it counts the events left after the
      drops for the participants (decision 35), and a search with a
      share of its nodes can drop more than the shortfalls need. A
      budget of one step leaves the search no node at all (its share of
      100,000 rounds down to none) and the greedy set stands. Of 6,000
      generated requests of 5 to 11 events with over-capacity
      participants, 201 have the grid's violation at the default; at one
      step 31 of them came back without it and one with a smaller
      demand, at 10 steps 27, at 100 steps 3, at 2,000 none. It was
      never reported where the default does not report it and never
      with a larger demand, and the flag was true on every outcome
      whose capacity violations were not the default's. The reasons of
      the unplaced events follow in the same way: with a smaller shared
      set more of them are `ParticipantOverCapacity` (on 120 of the
      6,000 at one step, where it was 14 before this decision, and on
      12 at 100 steps, where it was none). A floor of nodes for this
      search, like the one of the last placement step, was measured and
      not taken here: 100 nodes brought the 31 back on those small
      requests, but of 300 larger ones (up to five participants with
      more events than positions each, 167 with the grid's violation at
      the default) the grid's violation at one step was still not the
      default's on 18 at a floor of 1,000 nodes and on 13 at 5,000,
      where it is not on 44 without a floor: a floor mends the small
      requests and not the report. The usage guide says what a caller
      can and cannot read from an outcome below the default.
    - **The order of the participants is not scaled.** A share of its
      work limit (decision 39) would send requests back to their ids
      that the whole limit orders, and the ids would then decide more at
      a small budget than at a large one: a third exception to the
      invariance of decision 39, and one that depends on an option. It
      was tried: with a share, round robins of 8, 12, 16 and 20 with
      three rounds played came back as other schedules under other
      participant ids at a budget below the default. Left whole it costs
      what it costs at the default, at most about a tenth of a second
      whatever the request (12 milliseconds on the seasons of 40 measured
      here), and the invariance holds at every budget as decision 39
      states it, with its two exceptions and no third: 2,840 requests
      at budgets of 1, 100, 2,000 and 20,000 steps, each under three
      other namings (34,080 relabellings), came back the same in
      everything that can be said without an id.
    - **A floor: one position check for every event and position.** The
      last placement step never has fewer, whatever the budget
      (`LeftoverRecolourer::workLimitAtLeast()`). With a share alone a
      budget of one step left the round layout two position checks,
      which is no search, and the layout is what the planner's order
      comes from: the seasons of 40 over two legs placed 14,336 of
      16,184 events at one step, fewer than 0.2.3 at one step (14,849).
      With the floor they place 15,517, and it is not free: 0.12 seconds
      at the median for 0.08, on the largest requests measured (a season
      of 40 over one leg takes 0.04 with it). It is about the work of
      one search that fails, and it grows with the request as the first
      pass of the layout does, so it belongs with the work no budget
      shortens. Two and four checks for every event and position placed
      15,600 and 15,630 for 0.15 and 0.20 seconds, and were not taken.
      The floor is never more than the whole limit. The order of the
      events is deterministic at every budget with or without it: it
      was not needed for that.
    - **A search that a smaller limit stops is a search the budget
      stopped.** `isBudgetExhausted()` promises that false means a
      larger budget gives the same outcome (decision 28). A limit that
      follows the budget would break that, so each of those searches
      tells its budget when it stops at its limit with something still
      to search (`StepBudget::stoppedAtWorkLimit()`), and the budget
      records it when the limit was one it had made smaller. The round
      layout's budget is read into the flag with the other two. The
      shared-events search reports only when it stops short of a set as
      large as any can be: with such a set no longer search would have
      replaced it. The last placement step asks whether a participant
      already plays in every position, which needs no search, before it
      looks at the limit, so that a limit is not reported for a search
      that was never going to be made. The flag can still be true where
      a larger budget would have changed nothing (a search cut short
      that would have found nothing): that is what true has always
      meant.
    - **A stop at a whole limit is still not a budget stop.** At the
      default budget and above no budget lifts those limits, so the
      outcome is the one every larger budget gives and the flag is
      false, as decisions 33, 36 and 48 had it and as the test of the
      34 events of four participants holds it. The contract was read
      for whether it asks for more ("the searches have limits of their
      own that the budget does not lift" was its wording): it does not,
      and the documents now say which limits a budget below the default
      makes smaller. Nothing at or above the default was untrue and
      nothing there changed.
    - **The final sweep keeps its counts.** Not a limit, and found by
      measuring what a budget of one step still cost: the sweep that
      gives every leftover event the first free position counted what
      every position held afresh for each event, work in proportion to
      the events left over times the events placed, and a small budget
      leaves most events to it (34 milliseconds of 136 on the seasons of
      40). It counts once and keeps the counts up to date; the positions
      it gives are the same.

    What it costs and gives, measured (PHP 8.4, no OPcache, one core;
    median and maximum in milliseconds, and events placed), on 160
    seasons of one and two legs among 20 to 40 participants with a fifth
    or a half of the rounds played and pinned (16 a row), and on 200
    round robins of 16 to 40 with an eighth to a half of their events
    pinned at scattered positions:

    | Request | 1 step | 2,000 (a hundredth) | 20,000 (a tenth) | 200,000 (default) |
    |---|---|---|---|---|
    | 20, one leg (1,944 events) | 9 / 30, 1,769 | 9 / 25, 1,824 | 9 / 79, 1,824 | 8 / 501, 1,824 |
    | 20, two legs (3,944) | 16 / 39, 3,714 | 24 / 56, 3,787 | 36 / 111, 3,824 | 59 / 364, 3,824 |
    | 24, one leg (2,808) | 13 / 27, 2,562 | 20 / 37, 2,639 | 19 / 70, 2,664 | 21 / 527, 2,664 |
    | 24, two legs (5,784) | 33 / 48, 5,479 | 43 / 81, 5,609 | 82 / 171, 5,640 | 127 / 393, 5,640 |
    | 30, one leg (4,464) | 22 / 38, 4,110 | 38 / 54, 4,217 | 42 / 176, 4,284 | 58 / 360, 4,284 |
    | 30, two legs (9,024) | 56 / 92, 8,608 | 95 / 123, 8,726 | 225 / 500, 8,836 | 286 / 1,043, 8,844 |
    | 36, one leg (6,504) | 35 / 59, 6,061 | 60 / 91, 6,202 | 61 / 120, 6,288 | 85 / 449, 6,288 |
    | 36, two legs (13,128) | 106 / 128, 12,569 | 142 / 189, 12,774 | 318 / 683, 12,881 | 686 / 1,460, 12,912 |
    | 40, one leg (8,024) | 43 / 67, 7,495 | 74 / 107, 7,688 | 91 / 371, 7,783 | 173 / 922, 7,784 |
    | 40, two legs (16,184) | 125 / 171, 15,517 | 183 / 259, 15,749 | 379 / 719, 15,895 | 792 / 1,762, 15,944 |
    | Scattered pins (55,573) | 17 / 57, 53,072 | 31 / 117, 53,721 | 106 / 511, 54,706 | 576 / 3,048, 55,265 |
    | Two legs among 29, 807 events | 96, 793 | 137, 793 | 504, 793 | 4,086, 793 |

    The same requests before this decision: the seasons 70 / 1,605 at
    one step and 123 / 2,069 at the default, the scattered pins 274 /
    1,342 and 569 / 3,136, the request of 807 events 1,968 and 4,195.
    And on 0.2.3: the seasons 23 / 167 at one step (64,465 of 71,808
    events placed, where this places 67,884), 33 / 229 at 2,000 (69,549;
    69,215), 60 / 351 at 20,000 (69,810; 69,919) and 78 / 895 at the
    default (69,823; 70,008); the scattered pins 8 / 35 (48,420), 34 /
    99 (54,477), 74 / 358 (54,938) and 111 / 1,407 (54,946).

    What the table does and does not show. The budget bounds the work:
    the most a search may do at a tenth of the default is a tenth of
    what it may do at the default, and the maximum falls accordingly
    (3.0 seconds to 0.5 on the scattered pins, 4.1 to 0.5 on the slowest
    request). It is not a dial that makes every repack take a tenth: a
    search that ended well inside its limit at the default is not
    shortened by a tenth of that limit, and a phase has more to do when
    the one before it was cut short (on the seasons of 40 over two legs
    the last placement step takes three times as long at a tenth of the
    budget as at the default, because the layout handed it more events
    to place), so those seasons take half their default time at a tenth
    of the budget and not a tenth. One request can even take longer at
    a smaller budget than at the default, which the last review
    measured: 4,650 generated requests at 17 budgets each and 16 large
    ones at five, the largest
    difference a request of 80 events among 11 participants, five of
    them with more events than the 12 positions, which takes 36
    milliseconds at the default, 160 to 180 at 100,000 steps, 100 at
    50,000 and 7 at 1,000 (before this decision it took about 40 at
    every budget). The round layout is the same at both budgets there;
    what differs is the plan made from a shorter search for shared
    events, which leaves the last placement step a hundred times the
    position checks and has a third schedule made where the default
    needs none. The sum of the limits is what falls with the
    budget, not the time of every request; at one step the largest
    difference was a few milliseconds (12 for 11 on a round robin of
    two legs among 16). What no budget shortens is in the
    first column: the order of the participants, the passes that are
    not searches, the floor above, and two plans where the first leaves
    something to gain. That is 0.04 seconds for a season of 40 over one
    leg and 0.12 over two (1,560 events), where 0.2.3 took 0.05 and
    0.07. And the events placed at a budget between a hundredth and a
    tenth of the default are fewer than 0.2.3 placed at the same budget
    where the pins are scattered (53,721 for 54,477 at 2,000 steps,
    fewer on 135 of the 200 requests and more on 12; 54,706 for 54,938
    at 20,000), in about the same time: 0.2.3's searches are all counted
    in steps and cost little each, and the layout this release starts
    from needs most of its limit on those requests. At one step and at
    the default it places more (53,072 for 48,420, and 55,265 for
    54,946, the 11 requests of decision 48 that place fewer among them,
    unchanged). A larger floor buys those events back for time and was
    not taken; it is a trade to revisit with the question decision 48
    leaves open, not here.

    The outcome at a budget below the default therefore differs from
    what it was before this decision, at the same budget, on most
    requests whose searches do not end at once; the golden fixture of
    budget stops shows it on three of its lines (the changelog names
    them). Hard rules are untouched and were audited at every budget:
    50,797 outcomes of 4,533 requests at budgets from one step to twice
    the default, with no participant double-booked, no pin assigned, no
    slot past its capacity, no event lost or duplicated, no exception
    and no unplaced event with a free position.

    Rejected: a third budget of steps for the layout (a step of its
    search is not a fixed amount of work, so steps would not have
    bounded it in proportion, and a repack would have spent three times
    the budget); scaling the limits above the default as well (changes
    outcomes where the flag was false); setting the flag at a whole
    limit (true would then mean nothing at the default, where a larger
    budget changes nothing); passing the planner's count of events no
    placement can hold to the layout, which would have saved its search
    for them at small budgets and changed its work, and so outcomes, at
    the default.

## Objectives (0.3.0)

Decisions made when the outcome was given figures to rank it by, a
movable event a current position, the options a list of objectives, and
the library a search for the shortest grid (issue #50). Everything here
is additive: a request that names no objective is repacked exactly as
before, whether or not its events state current positions, and the
golden fixtures are untouched.

50. **A current position is a position of the request's grid, and a
    fact.** `MovableEvent` takes a session and a slot, the two indexes a
    pinned event and an assignment have, so that "moved" is one
    comparison: an event is moved when it stated a current position and
    is assigned another one. A session alone was considered as the
    position (a move within a session is a smaller thing than a move to
    another night) and not taken: a slot is a time, a participant told
    20:00 who plays at 21:30 has been moved, and a caller who cares only
    about sessions can count those from the ids returned. It is a fact
    and never a demand, so nothing about it is refused but a negative
    index or half a position. A position the grid does not have is
    accepted, where a pin there throws (decision 5): a pin must be
    honoured and an event whose session was taken off the grid simply
    cannot stay, and refusing it would have made the search for the
    shortest grid impossible with current positions (every grid shorter
    than the present one has events beyond it). Such an event counts as
    moved wherever it is placed. A position held by pinned events (the
    slot full, or a participant pinned there) is not refused either: the
    event moves. Two events that cannot both stay where they say they
    are (one participant in both, or more events than the slot holds)
    are a current schedule that was not proper, which is the caller's
    to have and not an error of the request; one of them stays, taken in
    the repacker's order of events.
51. **Moved means placed elsewhere.** An event with no current position
    is never moved, whatever happens to it, and an unplaced event is not
    moved: it is unplaced, which is counted first. An event whose time
    is not on the grid and whose caller has no index for it states no
    position and is not counted; the caller knows those before the
    repack.
52. **Five figures, on every outcome, as accessors of the outcome.**
    Unplaced events and gap slots are counted from the lists an outcome
    already has (`getUnplacedCount()`, and `getGapSlotCount()`, which
    adds up the gap slots of the `ContiguityBroken` violations: the
    issue calls them waits, and the library already had the word).
    Lone-event sessions, idle sessions and moved events cannot be
    counted from an outcome, which holds neither the participants of an
    event nor the pinned events, so the repacker counts them
    (`Internal\PlacementMetrics`) and the outcome holds them: three
    constructor arguments after the budget flag, optional, so that an
    outcome built by hand states them as it states its lists and has
    none when it states none. A value object for the figures was
    considered and not added: the acceptance criterion asks for
    `RepackOutcome` alone, and five accessors are that.
53. **A lone-event session is a figure of the grid.** A session that
    holds exactly one event, pinned events counted, where that event is
    a movable one: a venue opened for one event. The other reading (a
    participant with a single event in a session) is what the
    consolidation weight already pursues, and it counts something else:
    a session of two events between four participants has four such
    participants and wastes no venue. A session whose one event is
    pinned is not counted, by the rule of decision 6: it is history the
    repack did not arrange. An empty session is not counted.
54. **An idle session lies strictly between a participant's first and
    last session with an event**, pinned events counted as events, and
    the figure is the sum over the participants with a placed movable
    event (decision 6 again: a participant whose events are all pinned
    is history). The sessions before the first and after the last are
    not idle: a participant whose season starts late has not waited.
    Every session between counts, although some cannot be avoided (an
    odd round robin on sessions of one round has a bye a round).
55. **The figures are counted over lists of participants.** A repack is
    pairwise by its types: `MovableEvent` and `PinnedEvent` take two
    participants each, so there is no event with more for the figures to
    answer wrongly about, and nothing to refuse (ADR 0003). The counting
    itself reads an event as a list of participants of any length, so
    that 2.0 adds a type and not a second way of counting.
56. **The figures are written, as part of version 1 of the outcome's
    form.** `toArray()` gains a last key, `metrics`, with the five. The
    form is written and never read, and 0.3.0 is the release that fixes
    what version 1 of every form is (ADR 0005), so the key is part of
    version 1 and the form takes no second version; ADR 0005 names it
    with the other keys 0.3.0 added. `RepackOptions` gains the optional
    key `objectives` on the same footing, written only when there is
    one, so that options with no objective serialize as they did. The
    fingerprint is unchanged: scheme `v1` names its keys (decision 29),
    and the figures follow from the request and the assignments, which
    it covers.
57. **Objectives are an ordered list of an enum on the options.**
    `RepackOptions(objectives: [...])`, most important first, each case
    at most once (`RepackObjective`: `LeastChurn`,
    `FewestLoneEventSessions`, `FewestIdleSessions`), and the backing
    strings in plain data. Weights were not used: the two weights the
    options already have trade one soft preference against another
    inside the planner's score, where an objective has to rank whole
    schedules, and a lexicographic order is something a caller can
    state and a test can hold the repacker to.
58. **Objectives rank after events placed and gap slots, and never cost
    either.** The schedule of the request with no objective is made
    first, on fresh budgets, exactly as a repack without objectives
    makes it. Every schedule made after it is compared with it by
    events placed, then gap slots, then the objectives in their order,
    and it stands on a tie. So a request with objectives never places
    fewer events than the same request without them, and never as many
    with more gap slots; this is a property of the construction and not
    of the searches, and a test holds it. The two weights and the late
    starts rank after every objective: the weights chose the schedule
    the objectives start from, and a late start is an objective of the
    same kind. Putting churn ahead of contiguity was considered,
    because it is what a caller with a published calendar may want; it
    was not done, the order of decisions 41 and 42 being the brief of
    this change, and what it would buy is measured below so that the
    choice can be made with numbers.
59. **Inside one schedule's making, the objectives decide only where
    nothing above them is at stake.** `solve()` is the old body of
    `repack()` from the round layout to the reasons, and it takes an
    optional score. With one, it prefers the rounds as laid out over
    the planned schedule when both place as many events and neither has
    a gap, and the third schedule over the one before it when both
    place as many with as many gap slots. In both cases the events
    placed and the gap slots of what `solve()` returns are what they
    are with no score: the first choice is made only where the search
    for fewer gap slots has nothing to do and the third schedule is
    made or not on the count of events alone.
60. **The fewest moved events are searched for by holding events in
    place.** The events that can stay at their current positions are
    held there as pins are, and the rest are placed around them by
    `solve()`: the repacker's own steps on a request with more pins.
    This is the approach that fits the usual request, a season with
    whole rounds played in which a few events have lost their position
    or been added: nearly everything is held, a handful of events is
    placed, and the result has no moved event at all. Three things
    follow where that is not enough.
    - *More events left out than the schedule with no objective leaves.*
      The last placement step is run on the schedule with the held
      events free again, and stops when as many are placed. An
      alternating path moves the few events on it and leaves the rest
      where they are, which is the least-churn way to make room that
      the library already had.
    - *As many placed, more gap slots.* The search for fewer gap slots
      is run in the same way. It changes the events around a gap first.
    - *Still worse on one of the two.* The held events that had to do
      with it are let go and the rest are held for another attempt:
      those a participant with a gap has in the session of the gap, or
      every event of those sessions when that lets go of nothing; and
      every event of the participants of an unplaced event when more
      are unplaced. Three attempts at most (`KEEP_ATTEMPTS`), stopping
      at the first that is level with the schedule with no objective on
      events and gap slots, since holding fewer events can only move
      more.

    Letting go of whole participants at the first sign of trouble was
    the first version and was replaced: on a season of 36 with five
    events re-pinned by hand it let go of 276 events to fix six gap
    slots and ended with the schedule with no objective, 1,057 events
    moved, where the version above moves 9.
    Which events are held where two cannot both stay is decided in the
    repacker's order of events, so by the structure of the request and
    by an id only between events of the same two participants. The
    unplaced events of an attempt that the last placement step or the
    gap search changed get their reasons again by the planner's rule,
    and the capacity figures of an attempt are those of the request
    once the held events are added back to the demand and the capacity
    it was planned with: a participant never has more events held than
    it has positions free, so every shortfall is what it is with
    nothing held.
61. **The other two objectives are a tie-break and a repair.** They
    decide between the schedules that are made anyway (decision 59),
    and then `Internal\ObjectivePolish` moves single events to a free
    position where the schedule is then strictly better on gap slots
    followed by the objectives in their order: the one event of a
    lone-event session to a session that has events, or an event of
    another session into it; an event of a participant with an idle
    session into another session between that participant's first and
    last; and, for churn, an event back to its current position. A
    move is refused when an unplaced event would fit the position it
    leaves, so that `no_slot_available` stays literally true (decision
    11). It is a repair, not a search: it stops at the first schedule
    no single move improves. A search for these two was not built:
    both are small on the requests the repacker is for (see the
    figures: a season on a grid sized for it has no lone-event session
    at all), and what a search would cost is the planner's choice of
    sessions made again under another score.
62. **Everything an objective adds runs on what the two budgets have
    left.** The schedule with no objective spends from fresh budgets as
    before; the attempts, the repairs they run and the polish spend
    what is left of the same two, as the third schedule does (decision
    46), so a repack still spends at most twice `stepBudget`. The
    polish counts the moves it weighs against a limit of its own
    (2,000, a share of it below the default budget, as decision 49 has
    every such limit) and each costs a step of the second budget. The
    budget flag keeps its meaning: it is true when any search of any
    schedule was stopped by a budget. What no budget covers is the work
    that is not counted in steps, done once for each schedule made: the
    round layout of an attempt runs on its own limit as every layout
    does. Making the first attempt before the schedule with no
    objective, and skipping that schedule when the attempt comes back
    with every event placed and no gap, would make the usual request
    cheaper than a repack with no objective. It was not done, because
    the attempt would then spend from the budgets the schedule with no
    objective needs whole, or a repack would spend up to four times the
    budget; the figures below say what it would save.
63. **The shortest grid is searched one number at a time.**
    `ShortestGridFinder::find()` takes the events, a function from a
    number of sessions to a grid, and the range to search, and returns
    the first number, going up, on which a repack leaves nothing
    unplaced. A bisection was not used because it needs "places
    everything" to be monotone in the number of sessions, and it is
    not known to be: the caller's function need not make a larger grid
    a superset of a smaller one, and the repacker is a bounded search.
    It was measured (below) and held on every request tried, which is
    not a proof. Going up one at a time is right whatever the truth is,
    and costs little, because the numbers that cannot work are skipped
    without a repack: a grid is ruled out when a pin is off it, when it
    has fewer places than the request has events, or when a participant
    has more events than it has positions. The result says which
    numbers were ruled out (a proof) and which were repacked and left
    events unplaced (the repacker found no placement, which is not
    one), and `isProvenShortest()` is true only when no repack failed
    below the number found. The search is bounded by the largest number
    the caller allows, and says when it reached it without a result.
    "Places everything" is no unplaced event; gaps are reported on the
    outcome and are the caller's to judge.
64. **Under the fewest moved events, the current positions decide where
    an id would.** Decision 39 leaves the ids to decide between
    participants the request does not tell apart, and between events of
    the same two participants; with current positions those are not
    alike for churn, so under `LeastChurn` the held events, and with
    them every figure, followed the ids (measured below). Under that
    objective, and only under it, `StructuralOrder` is given the current
    positions and reads them at the two places an id decides between
    participants after refinement: the order of a class whose members
    can be exchanged, and the choice between trials with equal
    descriptions. A participant's key there is its current positions in
    grid order, each with the class of the opponent at it, so it names
    no participant. Events of the same two participants are ordered by
    current position before id (an event that states none last). The
    refinement itself does not read them, so the classes, and the order
    up to which participant of a class goes where, are those of the
    request without objectives: the schedule made first, the one no
    other may be worse than (decision 58), is the schedule with no
    objective up to a symmetry of the request wherever the ids would
    decide only between participants a symmetry exchanges. That is the
    condition of decision 39, and outside it (its two exceptions) the
    schedule made first can be another one, with no promise on events
    placed or gap slots against the repack with no objective; on 560
    generated requests it was never that, with the same events placed,
    gap slots, events at every position and violations on every one.
    Reading the positions only where the trials leave every class with
    one participant, where equal descriptions are known to be a
    symmetry, was tried and left 10 of 400 seasons with other figures
    under other ids: the choice between equal descriptions with larger
    classes is the one that decides them. Reading them in the refinement
    was not tried: it would change the classes, and with them the
    schedule made first, for every request with current positions.

What was measured (PHP 8.4, no OPcache, on a machine that was doing
other work at the time, so the times are indications), with a
reference written outside the repository that shares no code with the
library or its tests.

*The figures and the hard rules.* 1,640 generated requests of 8 to 40
participants, each with and without its current positions and under
five settings of the objectives (none, each one alone, all three),
14,760 outcomes: 1,200 seasons (a round robin of one or two legs laid
out round by round on sessions of one to four slots, its first rounds
played and pinned, every other event at its place, and then one of six
changes: nothing, one or two rounds postponed, a session a slot
shorter, new events, the last session taken away, up to three events
re-pinned by hand elsewhere) and 440 round robins with an eighth to a
half of their events pinned at positions drawn at random and the rest
saying where a random proper schedule had them. Every figure of every
outcome equalled its definition counted by brute force; no participant
was double-booked, no slot was past its capacity, no event was lost or
duplicated and no unplaced event fitted a free position; no outcome
with an objective placed fewer events than the one with none, or as
many with more gap slots; and a request with current positions and no
churn objective had the fingerprint of the same request without them.
Against a copy of the source as it stood before the change, 1,000 of
those kinds of request at the default budget and 300 at 2,000 steps
came back with the same assignments, unplaced events, violations,
fingerprint and budget flag, with current positions stated and no
objective.

*What the objectives achieve*, in sums over the requests with current
positions (events placed, gap slots, lone-event sessions, idle
sessions, moved events):

| Requests | Setting | Placed | Gap slots | Lone | Idle | Moved |
|---|---|---|---|---|---|---|
| 1,200 seasons (322,566 events) | none | 320,863 | 1,556 | 13 | 6,340 | 302,521 |
| | fewest moved | 320,864 | 1,150 | 20 | 3,779 | 40,856 |
| | fewest lone-event sessions | 320,863 | 1,556 | 0 | 6,327 | 302,520 |
| | fewest idle sessions | 320,863 | 1,530 | 12 | 4,940 | 302,421 |
| | all three, in that order | 320,864 | 1,143 | 2 | 3,688 | 40,848 |
| 440 scattered (95,356 events) | none | 95,356 | 3,665 | 0 | 263 | 81,394 |
| | fewest moved | 95,356 | 3,192 | 0 | 200 | 50,071 |
| | fewest idle sessions | 95,356 | 3,661 | 0 | 6 | 81,405 |
| | all three | 95,356 | 3,191 | 0 | 175 | 50,061 |

By the change made to a season, the events moved with no objective and
with the fewest moved asked for: nothing changed 52,498 and 7,192; a
round postponed 51,496 and 5,819; a slot lost 53,150 and 5,241; new
events 47,568 and 4,412; the last session taken away 49,120 and 7,264;
events re-pinned by hand 48,689 and 10,928. The seasons in which
nothing changed and events still move are the ones whose calendar has a
gap that another schedule avoids (a field of odd size has a bye in the
middle of a session): contiguity ranks first, so those events move.

*Against every placement.* 6,000 requests of three to six events
between three to six participants on up to six positions, with up to
two pins and current positions on most events, some colliding and some
off the grid. The repacker reached the most events placed and the
fewest gap slots on all of them. Among the placements with both, it
reached the fewest moved events on 5,578 (93.0%), with a mean excess of
0.076 events and a worst of 3 (with no objective: 35.3%, 1.155 and 6);
the fewest lone-event sessions on 5,807 (96.8%), mean excess 0.032,
worst 1 (88.2%, 0.125, 3); the fewest idle sessions on 5,746 (95.8%),
mean excess 0.045, worst 2 (90.3%, 0.136, 4). Where the fewest moved
events are missed on these, it is often because another event could
have been the one left out (three of the first six such requests looked
at): which events stay unplaced is decided by
the placement steps and not by an objective, and an exchange of an
unplaced event for a moved one would need its reasons given again and
was not built.

*Time*, median and 90th percentile in milliseconds, by number of
participants, with no objective and with the fewest moved events (the
other two objectives take about what none takes): seasons of 8 to 15, 4
/ 261 and 6 / 463; of 16 to 23, 32 / 389 and 48 / 766; of 24 to 31, 211
/ 1,010 and 258 / 1,354; of 32 to 40, 562 / 2,162 and 611 / 2,393.
Scattered pins of 8 to 15, 39 / 421 and 329 / 1,278; of 16 to 23, 275 /
486 and 811 / 1,554; of 24 to 31, 477 / 893 and 1,172 / 2,126; of 32 to
40, 1,076 / 1,820 and 1,928 / 3,483.

*The ids.* Renaming every participant and event (and exchanging the
two participants of every event) changed nothing that decision 39
promises (the events at every position, the unplaced counts, the
violations) on 280 requests with no objective, and on the same 280 with
the two objectives that are not churn. It does change the count of
moved events there: the schedule is the same only up to a symmetry of
the request, and the current positions are not symmetric (a review
counted 32 of 150 seasons and 5 of 60 scattered requests with another
count of moved events and nothing else changed, with no objective).
With the fewest moved events it changed something else as well (the
count of idle sessions or of late starts, the events at a position) on
11 of 200 seasons and 4 of 80 scattered requests. The cause was
decision 39 meeting decision 50: the order of the participants was made
from the request without its current positions, an id decided between
participants that order cannot tell apart, and two such participants
can have different current positions. Decision 64 mends it. Measured
on another generator (400 seasons of 8 to 40 participants under six
changes, 160 round robins with a twentieth to under a third of their
events pinned at scattered positions, every participant and event
renamed by a seeded bijection): before it, renaming changed a figure
on 10 seasons and 9 round robins under `LeastChurn` (10 and 9 with all
three objectives), and after it on none, the schedule mapped back by
event being the same on every one. The events moved, `LeastChurn`
alone: 5,757 on the seasons and 5,159 on the round robins before,
5,825 and 4,894 after (10,916 and 10,719 in all), with the same events
placed, and gap slots 89 and 116 before, 91 and 115 after. With no
objective nothing changed: the 560 outcomes are byte for byte those of
the code before, and 1,200 requests without current positions those of
the code before the objectives were added. On 9,000 small requests against every
placement, the fewest moved events were reached on 8,291 (8,296
before), worst excess 3 on both.

*Two choices that were not taken, in numbers* (400 of the seasons, 150
of the scattered requests). Holding every event that can stay and
placing the rest, with no repair and no comparison, which is what churn
ahead of contiguity and of events placed would mean: the seasons move
1,364 events (12,271 with the objective as built, 94,169 with none) and
pay for it with 87 events fewer placed (100,117 of 100,790 for 100,204)
and 872 gap slots for 367; the scattered requests place 2,287 events
fewer. Churn ahead of contiguity only, with events placed still first,
lies between the two and was not measured separately. And making that
held schedule first: it places everything with no gap on 206 of the
400 seasons, where it takes 3 milliseconds at the median and 14 at the
90th percentile against 20 and 425 for the repack with the objective as
built, because the schedule with no objective is never made; on the
scattered requests it does so on 1 of 150.

*The shortest grid.* 320 requests (seasons with their played rounds
pinned, scattered pins, random multigraphs with unequal loads, and
round robins of an odd number with a few events on top, for which
counting allows one session fewer than a placement needs), each on a
grid of sessions of one to five slots. The first number of sessions
that counting did not rule out placed everything on 319, and the next
one on the last, so `isProvenShortest()` was true on 319; a search took
27 milliseconds at the median, 372 at the 90th percentile and 1.6
seconds at most. The four numbers above the one found placed
everything too on every request: no case against monotonicity was
found, and none is needed for the search to be right.

No benchmark subject was added for an objective: `composer
bench-compare` runs one benchmark class against two copies of the
source, and a subject that states a current position or an objective
does not run against a copy from before this change.

## Testing

Per the brief: property tests over seeded random multigraphs (properness,
pin immobility, exact assignment/unplaced reconciliation), byte-identical
determinism under input shuffling, K₈ exactness (must come back with zero
violations), K₅ honesty (no interval colouring exists; must be reported,
not mangled), and the reference fixture both ways round (clean; and
mis-pinned ⇒ `CapacityExceeded` naming Fallowmead, shortfall exactly 3).

The 0.2.2 additions extend the property tests: fingerprints are equal for
the same request in any input order and for the same records in any order,
and change under every single-field mutation of every record class; a
shape-only grid gives every event the position the instant-based grid of
its shape gives it; unbounded capacity never reports the grid as too small
and equals every capacity too large to matter; and an outcome whose budget
flag is false is unchanged by a larger budget. Literal fingerprints for
fixed outcomes are pinned in `tests/Unit/Repack/RepackOutcomeTest.php`;
they were computed by an implementation written apart from the library's,
from the documented scheme.

The 0.2.3 fixes add two sweeps. Every complete round robin of an even
number of participants from 6 to 40, on ⌈n/4⌉ four-slot sessions of
capacity n/2, places every event, with ids `1`… in pair order, ids from
`1000`, and non-numeric ids in an unrelated order. A corpus of five named
instances (one-slot sessions, shared over-capacity events, pins holding
capacity, capacity 1) and 300 random tiny ones is compared with an
exhaustive search (`tests/Support/RepackBruteForce.php`), which the
repacker must match exactly; on the first 3,000 random seeds it matched
every one, where 0.2.2 fell short on 458. A complete round robin of 40 in
that shape took 0.5 to 0.7 seconds on the machine it was measured on. One
test takes a request on which the planner's search for shared drops
stops at its node limit, and checks that the step still places the most
events there can be, worked out from an exhaustive b-matching; another
checks that a request whose search ends at the step's position-check
limit reports no exhausted budget and is unchanged by any larger budget.

The 0.3.0 changes add three kinds of test. Relabelling: 40 random
requests with pins, the small corpus and round robins with three rounds
pinned are repacked under other participant and event ids, with the two
participants of every event exchanged at random and the lists in another
order, and must come back the same in everything that can be said without
an id; where the request has ten participants or fewer, the test also
finds every symmetry of the request by trial and requires the two
schedules to be equal participant for participant under one of them. Every
complete round robin of an even number from 6 to 40 must come back with no
violation, and as the same schedule, under each of three event id schemes.
Gaps: on the small corpus the gap slots reported must equal the fewest any
placement of as many events has (`RepackBruteForce::fewestGapSlots()`, an
exhaustive search with no bound on gaps); the four-event request of the
issue and a complete round robin of six on one session of 5 to 30 slots
must come back without a gap. The three new internal classes have unit
tests of their own, among them that `StructuralOrder` writes a request the
same way under any numbering of its participants, on named requests (a
ring, two rings of different lengths, a round robin with a round taken out
or pinned) and on 60 random ones.

The review of that change added the cases it had not looked at. Round
robins of 36 and 40 with one round played must come back with the same
counts under other participant ids (they did not, at the ordering's first
work limit), and `StructuralOrder` must order the one of 40 within its
limit as it does without one, and must stop splitting a chain of
participants once it is past the limit. Two tests of the budget were
restated, because the rounds changed what they could show. The second
budget is no longer seen in the number of events placed (the rounds are a
schedule made without a step, and they are returned when the planned
schedule places fewer), so its test is now a request of three events that
comes back without a gap only when the search for fewer gap slots has
steps of its own. And the test that the last placement step spends nothing
when only over-capacity drops are left over is on sessions of one slot,
where no schedule has a gap: on the one session it was on, the search for
fewer gap slots spends some 1,400 steps of the same budget, and a budget
large enough for that no longer noticed an exchange search of 74. The
relabelling tests compare schedules participant for participant on 733 of
their 742 comparisons, 539 of them on requests with no symmetry at all,
where the two schedules must simply be equal; the other nine are the round
robins of 12, 16 and 20, which have too many participants for the
symmetries to be found by trial and are compared by their counts.

The comparison with 0.2.3 request by request (decisions 45 to 48) added
the tests for what it found. "Places at least as many events as the last
release" has no oracle but that release, so 29 requests are kept as plain
data with the counts 0.2.3 placed, measured with a copy of its source
(`tests/Fixtures/repack/placed-by-0.2.3.json`), and the repacker must
place at least as many on each: the 24 on which the change placed fewer
as it first stood, two that a version of the fix between lost an event on,
and three of the large ones (round robins of 40 with 60, 200 and 400
events pinned). Against the change as it first stood 26 of the 29 fail:
all but the two from the version between and the one with 400 events
pinned. Seen with each thing switched off in a
copy of the final source: without the exchange for two events one request
of 30 participants fails, without the path that moves an exchanged event
the request of six events, without the third schedule two requests,
without the limit that grows with the request the last three. Where a
request is small enough there is a real oracle: 800 requests in which up
to three participants have most of six to eleven events are compared with
the exhaustive search, and three of them were one event short before (one
of them still is without the exchange for two, another without the path
that moves an exchanged event). The last placement step has a unit test
for each of its new moves on a state small enough to read (an exchange for
two events on five events, an augmenting path that takes three events out
of one slot, a path that moves an exchanged event), each seen to fail with
that move taken out. The cost table is tested from both sides: a session
of twelve slots is given up on a budget that cannot pay for its table, and
a session of seven slots spends the steps the reference implementation
spends, which does not count the table at all. One thing has no test that
fails without it: that every single exchange is tried before a chain of
two. It mattered while the step's limit was 500,000 position checks (the
round robin of 40 on a full grid came out two events short with the
search depth first), and with the limit of decision 48 every request of
the fixture passes either way. The augmenting path fails its unit test
when it is taken out and no request of the fixture, for the same reason.
Both are kept: they find at a cost of a few steps what the exchanges
find late or not at all.

Decision 49 changed what the budget tests have to hold. The property
"when the flag is false, a larger budget gives the same outcome" is
checked at fourteen budgets from one step to seven times the default
(it was checked at eight, and against two larger budgets where it is
now checked against every larger one), and on six round robins of 8 to
12 with pins at scattered positions, where the round layout has to
search and a budget's share of a work limit is what stops it and not
its steps. With the report of such a stop taken out, that sweep fails
on five of its six requests and the one over the small corpus on one;
the twelve random requests of the first test are too small for a share
of a limit to stop what the steps do not. On 4,533 generated requests
at nine budgets or more each, 25,439 outcomes have the flag false and
no larger budget gives another outcome for any of them (426 of 8,141
on 1,600 of those requests with the report taken out).
Against the source as it stood before the decision the property holds
too (its limits did not follow the budget: 29,464 outcomes with the
flag false on the same requests, none contradicted by a larger
budget), so the test that fails against it is another one: a
request of eleven events on which a budget of 100 steps refuses no step
and shortens the layout's search, where the flag must be true and the
outcome is not the default's. Before the decision that request came
back with the default's outcome and the flag false at 100 steps. Each
search has a unit test of its share and of its report (the last
placement step, the search for fewer gap slots, the round layout, the
shared-events search and the planner's use of it), and `StepBudget` of
the arithmetic, of a share that carries the caller's budget, and of the
default it takes from the options. The relabelling tests repeat at
budgets of 1, 100, 2,000 and 20,000 steps; they fail when the order of
the participants is given a share of its limit. And one request of
eleven events holds what the capacity violations may say below the
default (decision 49): the four over-capacity participants alike at
nine budgets, the grid's own violation never with more than the five
events for four places that are true of it, and the flag true wherever
the report is not the default's. It fails at 100 steps when the
shared-events search does not tell its budget that a smaller limit
stopped it.

One test changed its request. "Spends no budget on the last placement
step when only the over-capacity drops are left over" showed, by the
flag at a budget of 60 steps, that the step is told how many events no
placement can hold and so searches for no exchange. At any budget small
enough to notice that search (below 154 steps on its request) the round
layout's own search for the same drops is now cut short, and that is
what the flag reports, so the test was taken out with the decision and
its two halves tested where they are made: the planner's count on that
request, and the step's use of a count it is given. That left the
passing of the one to the other without a test. The last review found
that it does show in an outcome, on other requests: with the count not
passed in a copy, the flag was true where it is false on 15 of 3,000
outcomes of over-capacity requests and on 124 of 100,000 of very small
ones, the outcome the same. The test is back on one of those: seven
events on one session of three slots, five of them of one participant,
at 100 steps, where every search ends by 75 steps and a needless
exchange search keeps the flag true up to 160. It fails with the count
not passed.

One thing has no test: that the search for shared events follows the
budget when the unplaced events are given their reasons, as it does in
the planner. It shows in time, and in which reason an unplaced event has
below the default: with that one search left at its whole limit in a
copy, 666 of 35,700 outcomes of 2,100 over-capacity requests at 17
budgets were other outcomes, and 206 the same outcome with another flag
(the smallest request found on which it shows has seven events: at one
step the share gives one unplaced event the reason
`ParticipantOverCapacity` where the whole search gives it
`NoSlotAvailable`).
Both are outcomes the contract allows, and which of them the share
gives depends on the greedy set the search starts from, which follows
the repacker's own order of the events: a test could only repeat what
the code returns, and none was written. The test file says so where
the search is tested.

The objectives (decisions 50 to 63) have a feature test of their own
(`tests/Feature/RepackObjectivesTest.php`), on seasons of 6 to 13
participants and on requests small enough to try every placement of.
The figures of every outcome must equal their definitions counted from
the request and the assignments (`tests/Support/RepackFigures.php`,
written apart from `PlacementMetrics` and reading no violation, so it
checks the audit's gap slots too), under six settings of the
objectives. No setting may place fewer events than none or as many with
more gap slots; a request with no objective must have the assignments,
violations and fingerprint of the same request without its current
positions; the outcome must not depend on the order of the lists; an
outcome whose budget flag is false must be the outcome of every larger
budget, with objectives too; and the hard rules are checked from the
assignments. The fewest moved events are stated on cases a reader can
check (a season in which nothing changed moves nothing, at four sizes
up to 40; a postponed round goes to the session added for it), and
held to a floor against `RepackBruteForce::fewestGapSlotsThenMoved()`
on 150 small requests: the optimum on nine in ten and never more than
two events from it, which is what the wider measurement above supports
and not a property the search guarantees. The other two objectives
have one stated case each. The
pairwise limit is tested where it lives: `MovableEvent` and
`PinnedEvent` take two participants and no list, and
`PlacementMetrics` counts an event of three. The two internal classes
and the finder have unit tests, among them each refusal of the repair
(a gap it would open, a position held by an event, by a pin or full,
and an unplaced event that would fit the position left). A mutation
run of each of the two (`composer mutation -- --path=<file>`, with the
tests of `phpunit.mutation.xml`) reported 89 of 105 changes noticed in
`PlacementMetrics` and 148 of 176 in `ObjectivePolish`. Most of the
changes left are equivalent (a sort of a list already in order, a
shortcut that skips no result, a value stored where only its key is
read); the rest narrow the moves the repair tries for the two objectives
that are not churn (a slot or a session fewer in a loop), which no unit
test states and the feature test does not run under that configuration.
