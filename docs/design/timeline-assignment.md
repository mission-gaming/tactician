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
- **Every slot is computed from the start** (changed in 0.3.0): round N is
  the start plus N−1 round intervals taken as one step, and a slot is its
  round's first slot plus its slot intervals taken as one step. The first
  implementation added the interval once per round, each addition from
  the round before, so one round's adjustment carried into every later
  round: `P1M` from 31 January went to 3 March and stayed on the 3rd, and
  a weekly 01:30 that met the hour the clocks skip was 02:30 from then
  on. Months now keep the start's day of the month, or the last day of a
  month too short for it, and a skipped local time moves the one round
  that lands on it.
- **Intervals are added in the definition's timezone**, as PHP adds a
  `DateInterval` to a zoned time; kickoffs are then emitted in UTC
  (`timezone-explicit in, UTC-normalized out`). An interval in days, weeks
  or months keeps the wall-clock time, so a weekly 19:00 kickoff written as
  `P7D` stays 19:00 across a daylight-saving change. An interval in hours,
  minutes or seconds is elapsed time: `PT168H` is not `P7D`, and lands an
  hour off after the change. The note first recorded this as "wall-clock
  interval arithmetic" without the distinction.
- **A repeated local time is the first of the two** (0.3.0): PHP reads a
  local time that occurs twice by the time it adds to: the first where
  the timezone database marks that time as daylight-saving time, the
  second where it does not. So one step from a start on winter time lands
  on the second 01:30 of the night the clocks go back, and one step from
  a start on summer time on the first. Round-by-round addition gave the
  first in most cases, the round before being on summer time, but not in
  all: an interval long enough to step over the summer has the round
  before on winter time, and for `Europe/Dublin`, `Africa/Casablanca` and
  `Africa/El_Aaiun` the database marks winter time as the daylight-saving
  one, so that a weekly 01:30 in Dublin took the second where the same
  kickoff in London took the first. For an interval with no time part,
  `IntervalArithmetic` takes the first always, so that the kickoff
  depends neither on the season the stage starts in nor on how a timezone
  is recorded; the changelog lists the two cases as output changes. An
  interval with a time
  part is left to PHP: its date part and its time part cannot be told
  apart after the addition, and for an interval from `diff()` they cannot
  be added separately without changing what PHP gives.
- **Rounds do not overlap** (0.3.0): the last slot of a round must be
  before the first slot of the next round, or the same resources and the
  same participants are booked for two kickoffs at once. The definition
  refuses a first round that runs into the second when it is built, and
  the assigner checks every round it assigns, since a short month or a
  clock change can shorten a later round. Events have no duration in the
  model, so "ends" means the last kickoff, and a kickoff equal to the
  next round's first is an overlap. The slots within a round must rise
  too, and a round of one slot is checked against the next like any
  other: an interval PHP adds as local time (one that `diff()` returned)
  puts two steps at one instant where the clocks skip an hour, now that
  each step is taken from the start (an interval of days written as a
  duration does the same across a calendar day a timezone skipped, as
  `Pacific/Apia` skipped 30 December 2011). The check is of the slots a round
  declares, not of the ones it fills, so that a timeline is valid or not
  whatever is placed on it.
- **A round has a furthest time** (0.3.0): the steps are multiplied out,
  and PHP adds an interval too large for its timestamps without an error
  and returns a date that has wrapped round. `IntervalArithmetic` refuses
  a result more than 10^14 days from its anchor (`ValueOutOfRange`). The
  first implementation never reached such a round: it added once per
  round. A fraction of a second is multiplied as whole microseconds, with
  its whole seconds carried, because the float PHP shows it as loses a
  microsecond when multiplied and the microseconds of many steps leave
  PHP's range long before the seconds do. The limit is on the distance
  from the anchor, so it does not guard a start that is itself near the
  end of PHP's range, and an interval made from a relative string is not
  under it: that one is still added once per step.
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
  `ScheduledSchedule` (the round-by-round engine path).
  `assignRound()` applies the assigner's rules to the round it is given
  (0.3.0; it ignored them before): a blackout is judged in full there,
  and rest only within the round, because the assigner keeps no state
  between calls. These are
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
