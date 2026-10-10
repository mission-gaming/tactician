# Design note: Schedule Repacking

**Status: IMPLEMENTED** in 0.2.0, with the additions under
[API additions](#api-additions-022) in 0.2.2 and the fixes under
[Placement fixes](#placement-fixes-023) in 0.2.3. It was built from an external
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
   used only for determinism, never exposed as meaning.
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
    has a second budget of the same size.
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
    integer-keyed.
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
    worked reads differently. `session_count` beside `sessions` stays an
    ignored unknown key, as it was.
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
    keeps this meaning.
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
    checks (`WORK_LIMIT`, about a sixth of a second), a limit of its own
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
    are dropped never depends on the budget. The bound is not always
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
    an invariant test checks it on the small corpus. The cost is that a
    repack can spend up to twice `stepBudget`, which the usage guide, the
    `RepackOptions` docblock and the `StepBudget` docblock state. An
    option to switch the step off, or to size its budget, was not added:
    a patch release adds nothing to the public signature.

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
