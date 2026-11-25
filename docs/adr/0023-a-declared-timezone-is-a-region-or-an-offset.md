# 0023. A declared timezone is a region or an offset, never an abbreviation

Status: accepted.

## Context

Every configuration the library reads from plain data with a time in it
declares the timezone in a field of its own: the `timezone` of a
`TimelineDefinition`, of a `SessionGrid` and of each window of a
`BlackoutRule`. All three are read by one parser (`Timeline\ZonedTime`).

PHP's `DateTimeZone` accepts three kinds of name, and holds each as a type
of its own: an offset (`+01:00`, type 1), an abbreviation (`BST`, `EST`,
`CET`, type 2) and a region identifier of the timezone database
(`Europe/London`, type 3). An abbreviation is a fixed offset with no clock
changes: `BST` is `+01:00` in January too, and `GMT` is `+00:00` in July.
The parser accepted all three, so `'timezone' => 'BST'` gave a timeline
whose kickoffs were an hour out for the half of the year London is on GMT,
and no error said so (issue #151). The usage guide did not say what an
abbreviation meant.

The names in the abbreviation list are not only the confusing ones. `GMT`,
`Z`, `UCT` and `utc` written in lower case are abbreviations too, and each
means `+00:00`. `UTC` in capitals is the region identifier `UTC`. `Z` is
the designator of UTC in ISO 8601 and ends every kickoff the library
writes (`2026-08-01T18:00:00Z`); a start parsed from such a string is held
in the abbreviation `Z`, and 0.2.3 wrote `"timezone": "Z"` for it in the
plain data of a timeline and of a session grid.

## Decision

**A declared timezone in plain data is a region identifier or an explicit
offset. An abbreviation is refused**, every one PHP knows bar the names of
UTC below, with the reason
`TimezoneAbbreviation` (an `InvalidConfigurationException`). The context
holds the fixed offset the abbreviation stands for (`fixed_offset`), and
the message says to declare a region identifier for a place or that offset
for a fixed one. The check is of the declared name and comes before the
datetime string is read, so it is the error a configuration with both
faults gets.

**The names of UTC itself are not refused**: `Z`, `UTC` and `UCT`, in any
case. They name no place and no clock change, so the fault the rule is for
cannot happen with them, and refusing `Z` would refuse rows the library
wrote itself and starts built from the kickoffs it writes. The list is
those three and stays so. `GMT` is not on it although its offset is zero
too: it is the winter time of a place, and the abbreviation most often
written by a caller who means London, who would be an hour out all summer.

**What `toArray()` writes, `fromArray()` reads.** An object built in code
can hold a time in an abbreviation (`new DateTimeZone('BST')`, or a string
that ends in one), and works with it as the fixed offset it is. The
`toArray()` of a `TimelineDefinition` and of a `SessionGrid`, which write
the start's timezone name beside its local time, refuse such a time with
the reason `NotSerializable`, as they refuse an interval that would not
read back (`TimelineDefinition::writeInterval()`). They do not write the
offset in its place: that would read back to the same instants, and write
a zone the caller never declared. A `BlackoutRule` writes its bounds in UTC
and is not affected.

A time that is an instant and not a declaration is not covered: the
`kickoff` of a serialized scheduled event is UTC unless the string carries
a zone, and any zone it carries is read as the offset it states. A zone
written inside a configured datetime string is covered by the existing
rule that it must be the declared one, spelled the same way.

## Consequences

- A configuration that declared an abbreviation other than a name of UTC,
  and was read before, is refused from 0.4.0 (of the rows 0.2.3 wrote, one
  in the stored-forms fixture is: a timeline in `BST`). It is migrated by declaring the region it meant
  (`Europe/London` for `BST` or `GMT` meant as London), which changes the
  kickoffs of the half of the year the abbreviation was wrong for, or the
  offset it stood for (`+01:00`), which keeps every kickoff.
- A definition or a grid built in code with a start in an abbreviation, and
  written with `toArray()`, is refused where it was written before. It is
  migrated by building the start in a region identifier or an offset.
- New cases of `InvalidConfigurationReason` may be added in any release, so
  `TimezoneAbbreviation` is additive.
