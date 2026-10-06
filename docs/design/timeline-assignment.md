# Design note: Timeline Assignment (dates and times)

**Status: IMPLEMENTED** in 0.1.0, in three cuts: the slot model and
assignment, then time-aware rules, then resources. The position below was
accepted and the design anchors settled while it was built. `src/Timeline/`
holds `TimelineDefinition` (the declarative slot model,
config-constructible), `TimelineAssigner` (deterministic assignment over
`Schedule::getEventsByRound()` and, for results-driven stages, a
`RoundPairing`), the `ScheduledEvent`/`ScheduledSchedule` decorations
(serializable), and the rules `MinimumRestRule` and `BlackoutRule`.
Round-aligned and staggered kickoffs both come from the one slot model, as
sketched. Still open: cross-stage clash validation and per-resource
availability windows ([deferred work](../ROADMAP.md#deferred-work)).

The sections from "The question" on are the note as it was written before
the implementation. They are kept for the reasoning, and they speak of the
feature as something to come; where the shipped feature differs, the list
below says so. The usage guide has the API as it is
([Timeline Assignment](../USAGE.md#timeline-assignment)).

Settled decisions beyond the sketch:

- **One event per slot unless resources are declared**: a round with more
  events than the timeline has places for fails loudly. The first cut had
  one event per slot only; the third cut added resources (below).
- **Deterministic filling**: a round's events fill its slots in schedule
  order against slot time order.
- **Intervals are added in the definition's timezone**, as PHP adds a
  `DateInterval` to a zoned time; kickoffs are then emitted in UTC
  (`timezone-explicit in, UTC-normalized out`). An interval in days, weeks
  or months keeps the wall-clock time, so a weekly 19:00 kickoff written as
  `P7D` stays 19:00 across a daylight-saving change. An interval in hours,
  minutes or seconds is elapsed time: `PT168H` is not `P7D`, and lands an
  hour off after the change. The note first recorded this as "wall-clock
  interval arithmetic" without the distinction.
- **No check of who plays.** The sketch below lists "a participant assigned
  overlapping slots" among the things that fail loudly. The assigner does
  not check it: it validates capacity and round numbers. A
  `MinimumRestRule` of any positive rest is what rejects a participant in
  two events at one time, and it is opt-in.
- **Round numbers are absolute offsets**: round N lands at
  start + (N−1) round intervals whether or not earlier rounds exist in
  the schedule, so cross-leg-continuous numbering and partial schedules
  map stably.
- **Round-less events fail loudly**: the round-grouped view silently
  excludes them, so the assigner refuses schedules whose flat event count
  disagrees with the grouped view rather than silently dropping fixtures.
- **Resources lift the one-event-per-slot restriction** (third cut): a
  timeline optionally declares named resources — venue, pitch, court,
  board, station; the name is generic because the concept is — and each
  slot then hosts one event per resource. Filling stays deterministic
  (schedule order fills slot by slot, resource by resource in declared
  order) and each `ScheduledEvent` carries its assigned resource, so
  platforms get the display/persistence datum alongside the kickoff.
  Capacity without meaningful names is just resources with arbitrary
  labels. A round's ceiling becomes slots × resources, validated loudly
  as before. Per-resource availability windows remain future work,
  gated on a consumer needing them.
- **Time-aware rules are loud validation, not generation steering**
  (second cut): assignment is deterministic slot arithmetic, so a violated
  time rule cannot be routed around — it can only be reported. Rules
  implement `TimelineRule` and validate a `ScheduledSchedule`
  post-assignment: `MinimumRestRule` (an absolute duration between each
  participant's consecutive kickoffs — hour-based rest, which also
  subsumes double-booking for any positive rest) and `BlackoutRule`
  (config-constructible windows, half-open, declared-timezone
  authoritative like the definition itself). The assigner optionally
  carries rules and fails assignment loudly with every violation in the
  diagnostics; rules are also usable standalone against any accumulated
  `ScheduledSchedule` (the round-by-round engine path). These are
  deliberately *not* `ConstraintInterface` implementations — generation
  constraints filter pairings during a search; timeline rules judge an
  already-determined mapping.

## The question

Should Tactician assign dates and times to events, or should that remain the
consuming application's job? Today, in a typical consumer, the application
assigns one datetime per round from competition config, so every participant
plays each round's fixtures simultaneously. A desired future capability is
*staggered* fixture times within a round.

## Position: yes — Tactician should own the mechanism, never the policy

Split the concern in two:

**Mechanism (Tactician, Phase 4)** — mapping a generated schedule onto time
slots. This is domain-independent scheduling logic: given a schedule's round
structure and a declarative description of available slots, produce
timestamped events, deterministically, with validation. It belongs in the
library for three reasons:

1. **It interacts with correctness guarantees only the library can give.**
   Staggered times introduce failure modes a per-app implementation will
   miss: a participant double-booked into overlapping slots, rest windows
   measured in hours rather than rounds, blackout periods. Tactician already
   has the validation-with-diagnostics machinery and the constraint system —
   time-aware rules (rest measured in hours, blackout windows, venue
   capacity) are only possible if the library sees times. (They shipped as
   timeline rules, separate from the generation constraints:
   `MinimumRestPeriodsConstraint` still counts rounds between two meetings
   of a pair.)
2. **Round-aligned assignment is a trivial special case of slot
   assignment.** "Everyone plays round N at time T" is one slot per round;
   staggering is multiple slots per round. Designing the slot model gives
   both for the price of one, instead of the application growing a second,
   parallel scheduler for the staggered case.
3. **Every consumer rebuilds it otherwise.** The application layer's version
   is inevitably entangled with its config and persistence, so nothing is
   reusable and nothing is property-tested.

**Policy (the application)** — everything that decides *which* slots exist
and what happens around them: parsing competition config into a slot
pattern, timezone policy, persistence, notifications, deadline windows
(e.g. teamsheet submission), and rescheduling workflows. Tactician should
never read an RRULE config or know what a "match day" means to a given
product; the application translates its config into the library's
declarative input.

## Sketch

The sketch was two calls: a `TimelineDefinition` with a zoned start, a round
interval and one slot per round for round-aligned play, handed with a
schedule to `TimelineAssigner::assign()`; and the same definition with three
slots per round and a slot interval for staggered kickoffs. Both shipped in
that shape, and the usage guide has them as executed code
([Timeline Assignment](../USAGE.md#timeline-assignment)); the code is not
repeated here, where nothing would run it.

Design anchors, as proposed (the list under the status says how each was
settled):

- **Decoration, not mutation**: events stay immutable; assignment produces
  `ScheduledEvent` wrappers (or a `ScheduledSchedule`), so pairing logic and
  serialization are untouched and re-assignment is cheap.
- **`Schedule::getEventsByRound()` is the bridge**: the assigner consumes the
  round-grouped view; nothing about generation changes.
- **Deterministic slot filling** with declared ordering, so the same schedule
  and timeline always produce the same kickoff times.
- **Validation with diagnostics**, matching the library's character: a
  participant assigned overlapping slots, or a timeline with fewer slots than
  a round has events, fails loudly.
- **Timezone-explicit**: `DateTimeImmutable` + required `DateTimeZone` in,
  UTC-normalized out; policy about display stays app-side.

## Per-stage timelines

Timelines are **per stage**, matching the scope alignment in the Phase 3
design: a competition edition composes stages, and each stage has its own
format, rules, and — relevantly here — its own date pattern. A group stage
playing weekly Tuesday/Wednesday slots and a finals stage playing a single
weekend are two `TimelineDefinition`s, not one. Concurrent stages (e.g.
winners' and losers' routes running in parallel) each carry their own
timeline; any coordination between them (shared venues, avoiding clashes)
is application policy in the first cut. ❓ Cross-stage clash validation
could become a library capability later, but only if a consumer needs it.

## What this means for a consuming application

The integration is a translation: competition config (start date, match
days, fixtures per match day, time between matches) becomes a
`TimelineDefinition`, and an application's own code that assigned one
datetime per round can be deleted rather than extended. Staggered kickoffs
then need no new scheduling logic app-side, only config and UI to express
"3 slots per match day, an hour apart". Deadline windows, notifications, and
persistence stay entirely app-side, computed from the assigned times.

**One prerequisite worth flagging early**: staggered times are incompatible
with inferring round membership from kickoff dates. A platform that
persists only a `startDate` per fixture (as some consumers do today) can
currently reconstruct rounds because every fixture in a round shares one
datetime — the moment kickoffs stagger, that inference breaks. Any platform
wanting staggered times must persist round identity explicitly (a round
number or round/matchday entity on the fixture). Tactician's output always
carries it (`Event::getRound()`, `Schedule::getEventsByRound()`); the
application just has to stop throwing it away.

## Sequencing

Built as Phase 4, after the Phase 3 core, so that the assigner consumes the
unified engine output (`RoundPairing`) and not the shapes before it. Within
the phase the order was: slot model, round-aligned assignment and
staggering (first cut), time-aware rules (second cut), resources (third
cut).
