# 0024. A kickoff is written with its fraction of a second

Status: accepted.

## Context

Since 0.3.0 (#136) a `TimelineDefinition` and a `SessionGrid` whose start
has a fraction of a second write it (`2026-08-01 19:00:00.250000`) and read
it back, so the definition gives the same slot times after a round trip.
The kickoffs assigned from such a definition did not survive one:
`ScheduledEvent::toArray()` (and `ScheduledSchedule::toArray()`, which
writes its events with it) and `SlotAssignment::toArray()` wrote an
instant to the second, as their docblocks said. A kickoff read back was a
quarter of a second before the slot time the definition gives, and nothing
said so (#148).

There were two ways to make the two agree:

1. Write the fraction where a kickoff has one.
2. Refuse a start with a fraction wherever kickoffs are written.

The second refuses a configuration the library already accepts, writes and
reads, and it would have to refuse it in the one place that knows nothing
of the configuration (a scheduled event has its kickoff, not the start it
came from). It would also leave a kickoff built by hand with a fraction
written as another instant.

Two rules bear on the first:

- ADR 0005: adding, removing or renaming a key, or changing what a value
  means, gives a serialized form its next version.
- `RepackOutcome::fingerprint()`: a change to the canonical document of a
  scheme is a new scheme. Its key `kickoff` holds what
  `SlotAssignment::toArray()` holds under it.

## Decision

A kickoff is written as an ISO 8601 UTC string to the second
(`2026-08-01T18:00:00Z`) and, when it has a fraction of a second, with the
six digits of the fraction after the seconds
(`2026-08-01T18:00:00.250000Z`). `ScheduledEvent::toArray()`,
`ScheduledSchedule::toArray()` and `SlotAssignment::toArray()` all write
it that way, from one function. A kickoff on a whole second is written as
before.

The forms keep version 1. No key is added or renamed, and the value means
what it always meant: the kickoff's instant in UTC. What was written before
for a kickoff with a fraction was that instant cut down to the second, an
output that was wrong, not another meaning. `ScheduledEvent::fromArray()`
of 0.3.0 reads the new string, as the same instant to
the microsecond, so a reader of version 1 is not misled by it.

The fingerprint keeps scheme v1. Its canonical document is defined as what
the records' `toArray()` holds under the named keys, and the bytes, keys
and encoding are unchanged; only the kickoff string of an assignment with a
fraction is longer. This is the same kind of change as the conversion of a
kickoff to UTC in 0.3.0 (#121, item 9): an output that stated the wrong
instant is corrected, and only outcomes that held such an output have a
new fingerprint.

## Consequences

- A kickoff read back from plain data is the slot time the timeline or the
  grid gives, to the microsecond (`tests/Feature/FractionalKickoffsTest.php`
  states it for several fractions, timezones and slot patterns).
- Every kickoff on a whole second, which is every kickoff of a definition or
  a grid whose start has no fraction and whose intervals are ISO 8601
  durations, is written as before, so no golden fixture and no fingerprint
  of such an outcome changes.
- A fingerprint stored for an outcome on a grid whose session starts with a
  fraction of a second is not equal to the one computed now for the same
  outcome. An application that compares the two sees the plan as changed
  once. Two such outcomes on grids a fraction of a second apart had one
  fingerprint and now have two, as the fingerprint's contract (different
  when any field differs) requires.
- A reader of the plain data that parses kickoffs with a fixed pattern of
  whole seconds has to accept the fraction. The changelog says so.
