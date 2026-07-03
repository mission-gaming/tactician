# Design note: Timeline Assignment (dates and times)

**Status: IMPLEMENTING — first cut shipped (slot model + assignment)** —
the position below was accepted and the design anchors settled during the
Phase 4 implementation pass. The first cut ships `src/Timeline/`:
`TimelineDefinition` (the declarative slot model, config-constructible),
`TimelineAssigner` (deterministic assignment over
`Schedule::getEventsByRound()` and, for results-driven stages, a
`RoundPairing`), and the `ScheduledEvent`/`ScheduledSchedule` decorations
(serializable). Round-aligned and staggered kickoffs both come from the
one slot model, as sketched. Time-aware constraints are the next Phase 4
milestone; cross-stage clash validation and venue/resource modelling stay
open. Settled decisions beyond the sketch:

- **One event per slot** in this cut: a round with more events than slots
  fails loudly (concurrent kickoffs per slot arrive with venue/capacity
  modelling, which needs a capacity concept to validate against).
- **Deterministic filling**: a round's events fill its slots in schedule
  order against slot time order.
- **Wall-clock interval arithmetic** in the definition's timezone: a
  weekly 19:00 kickoff stays 19:00 across DST transitions; kickoffs are
  then emitted in UTC (`timezone-explicit in, UTC-normalized out`).
- **Round numbers are absolute offsets**: round N lands at
  start + (N−1) round intervals whether or not earlier rounds exist in
  the schedule, so cross-leg-continuous numbering and partial schedules
  map stably.
- **Round-less events fail loudly**: the round-grouped view silently
  excludes them, so the assigner refuses schedules whose flat event count
  disagrees with the grouped view rather than silently dropping fixtures.

## The question

Should Tactician assign dates and times to events, or should that remain the
consuming application's job? Today (e.g. in Metronome) the application
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
   time-aware constraints (hour-based `MinimumRestPeriodsConstraint`,
   blackout windows, venue capacity) are only possible if the library sees
   times.
2. **Round-aligned assignment is a trivial special case of slot
   assignment.** "Everyone plays round N at time T" is one slot per round;
   staggering is multiple slots per round. Designing the slot model gives
   both for the price of one, instead of the application growing a second,
   parallel scheduler for the staggered case.
3. **Every consumer rebuilds it otherwise.** The application layer's version
   is inevitably entangled with its config and persistence (as Metronome's
   is), so nothing is reusable and nothing is property-tested.

**Policy (the application)** — everything that decides *which* slots exist
and what happens around them: parsing competition config into a slot
pattern, timezone policy, persistence, notifications, deadline windows
(e.g. teamsheet submission), and rescheduling workflows. Tactician should
never read an RRULE config or know what a "match day" means to a given
product; the application translates its config into the library's
declarative input.

## Sketch

```php
// PROPOSED — not implemented (Phase 4)
$timeline = new TimelineDefinition(
    start: new DateTimeImmutable('2026-08-01 19:00', new DateTimeZone('Europe/London')),
    slotsPerRound: 1,                       // round-aligned: everyone plays together
    roundInterval: DateInterval::createFromDateString('1 week'),
);

$scheduled = (new TimelineAssigner())->assign($schedule, $timeline);
// => ScheduledEvent[] wrapping Event + DateTimeImmutable, grouped by round
```

Staggered times are the same model with more slots:

```php
// PROPOSED — not implemented (Phase 4)
$timeline = new TimelineDefinition(
    start: new DateTimeImmutable('2026-08-01 18:00', new DateTimeZone('Europe/London')),
    slotsPerRound: 3,                       // 18:00, 19:00, 20:00 kickoffs
    slotInterval: DateInterval::createFromDateString('1 hour'),
    roundInterval: DateInterval::createFromDateString('1 week'),
);
```

Design anchors (to be settled properly in the Phase 4 pass):

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

## What this means for a consuming application (Metronome as the example)

Nothing changes until Phase 4 ships: the app's date scheduler keeps
assigning one datetime per round. When Phase 4 lands, the integration is a
translation — competition config (start date, match days, fixtures per match
day, time between matches) becomes a `TimelineDefinition`, and the app's
own date code is deleted rather than extended. Staggered kickoffs then
require **no new scheduling logic app-side** — only config/UI to express
"3 slots per match day, an hour apart" and, where relevant, per-slot
fairness the library validates. Deadline windows, notifications, and
persistence stay entirely app-side, computed from the assigned times as
they are today.

**One prerequisite worth flagging early**: staggered times are incompatible
with inferring round membership from kickoff dates. A platform that
persists only a `startDate` per fixture (as Metronome does today) can
currently reconstruct rounds because every fixture in a round shares one
datetime — the moment kickoffs stagger, that inference breaks. Any platform
wanting staggered times must persist round identity explicitly (a round
number or round/matchday entity on the fixture). Tactician's output always
carries it (`Event::getRound()`, `Schedule::getEventsByRound()`); the
application just has to stop throwing it away.

## Sequencing

Phase 4, after the Phase 3 core lands (the assigner should consume
`StagePlan`-aware schedules and the unified engine output, not the
pre-Phase-3 shapes). Within Phase 4: slot model → round-aligned assignment →
staggering → time-aware constraints, each independently shippable.
