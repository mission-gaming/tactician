# Design note: Schedule Repacking

**Status: IMPLEMENTED** — built from an external brief (v2, the revision
with an empty pinned set on the reference instance). This note doubles as
the decisions log for the overnight implementation run; every judgement
call made without the maintainer awake is recorded here.

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

## Decisions log (maintainer asleep — review these)

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
   shortfall first, then id.
9. **Objectives 5 vs 6 trade** (consolidate vs finish early) is explicit:
   `RepackOptions(consolidationWeight: 3, earlyFillWeight: 1)` — integer
   weights, consolidation deliberately dominant by default because its
   absence is the documented failure of the caller-side implementation the
   brief replaces.
10. **Step budget**: single shared integer budget
    (`RepackOptions(stepBudget: 200_000)` default) counted in elementary
    search steps across Phase A improvement, Phase B backtracking nodes,
    and Phase C repair attempts. Bounded by steps, not wall clock, for
    reproducibility.
11. **Algorithm** follows the brief's three-phase shape:
    - **Phase A** assigns events to sessions (greedy by tightest
      participant first, scored by the two weights, capacity- and
      pin-aware) then improves by bounded single-event moves, then repairs
      slot-level parity (|{v : d_v > c}| must be even) by further moves —
      computed against pin-adjusted target sets, so pins are consulted
      *before* loads are fixed (the Cinder Row trap).
    - **Phase B** packs each session by prefix-target matching: each
      participant's ideal slot set is computed around its pins (bridge
      internal gaps first, extend downward toward slot 0, then upward),
      and a bounded depth-first search places one perfect matching per
      slot. Success means zero gaps and zero late starts by construction.
    - **Phase C** falls back to prioritized greedy packing
      (critical-participant-first) plus bounded repair moves within the
      session (direct moves, then two-colour alternating-chain (Kempe)
      swaps that preserve properness; chains containing a pinned event are
      unswappable), then a final cross-session sweep tries any remaining
      free colour anywhere before an event is declared unplaced.
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
19. **`examples/index.php` not updated** for example 19 — it only lists
    examples 1–12; examples 13–18 already shipped without index entries,
    so this follows the established practice rather than fixing the
    drift mid-feature.

## Testing

Per the brief: property tests over seeded random multigraphs (properness,
pin immobility, exact assignment/unplaced reconciliation), byte-identical
determinism under input shuffling, K₈ exactness (must come back with zero
violations), K₅ honesty (no interval colouring exists; must be reported,
not mangled), and the reference fixture both ways round (clean; and
mis-pinned ⇒ `CapacityExceeded` naming Fallowmead, shortfall exactly 3).
