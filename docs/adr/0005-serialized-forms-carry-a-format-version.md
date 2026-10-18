# 0005. Serialized forms carry a format version

## Context

The library writes plain data in many forms: a schedule, a stage state, a
scheduled schedule, a repack outcome, their parts (participant, round, event,
result, round pairing, scheduled event, the records of an outcome), and
configuration (the options classes, a ranking, a timeline, a session grid,
the timeline rules, the progression selectors). Applications store these
arrays, often as JSON in a database column, and read them back with a later
release than the one that wrote them.

Through 0.2.x no form said which release's rules it was written under. A
reader had the array and nothing else. As long as a form never changes that
is harmless; the first time a key is added, renamed or given another meaning,
the reader can no longer tell an old array from a new one, and it reads one
of them wrongly without knowing. The same is true the other way round: an
older release handed an array from a newer one reads what it recognises and
drops the rest.

Two strings in the library already look like versions and are not this one:
the `v2` inside an engine fingerprint (`tactician:v2:swiss...`,
`Stage\EngineFingerprint`; 0.2.x wrote `v1`) and the `v1` in front of a
repack outcome fingerprint (`RepackOutcome::FINGERPRINT_SCHEME`). Each is
the version of the scheme its own string is built by.

## Decision

**One key, in one place.** Every array a `toArray()` of the library returns
has `format_version` as its first key. The value is an integer, the version
of that form. `toJson()` and `jsonSerialize()` write the same array.

**One version to an array.** A form held inside another is written without
the key: the participants and events of a schedule, the round of an event,
the rounds, results and adjustments of a stage state, the award and the
decider of a result, the decisions of a rank selector, the records of a
repack outcome.
The array that is stored states one version, at its top, and what it holds
is read under that version. A part serialized by itself, with its own
`toArray()`, is the top of its own array and carries the key. A reader
accepts the key on a nested form too, provided it is a version it reads, so
an array put together by hand from separately serialized parts loads.

**A missing version is version 1.** Version 1 of every form is the form
0.3.0 writes: what 0.2.x wrote, which had no version key, and beside it
the optional keys 0.3.0 added before the key existed (`strategy_seed` in
round-robin options, `seed` in Swiss options, `ties`, `comparison` and
`decisions` in a rank range selector, `third_place` in elimination options,
`award` and `decider` in a result, `adjustments`, `entrants` and
`withdrawals` in a stage state, `after_round` in an adjustment,
`paired_on_incomplete_results` in a round pairing and `bye` in a
win-draw-loss ranking). Each of those may be left out and is then read
as 0.2.x behaved, so an array without the version key is read as version 1,
under the rules of version 1, and data stored by 0.2.x keeps loading.
Two of them say when something happened, and what leaving them out means
is stated where it is decided
([ADR 0007](0007-recorded-rounds-are-read-not-derived.md), "A state says
when"): an adjustment without `after_round` is of unknown time, and is
never read as made at a time that would suit. A withdrawal has its time
wherever the state has the entrant list: `entrants` and `withdrawals` come
together, with an entry under `withdrawals` (`after_round` from 0) for every
entrant who is not active ([ADR 0012](0012-a-withdrawal-states-its-round.md)),
and data that has neither (0.2.x) is read with the withdrawn participants of
unknown time.
`tests/Fixtures/stored-by-0.2/` holds every form as the 0.2.x tags wrote it,
and a test reads it on every run. From 0.3.0 on a key is not added to a
form without the form taking its next version (see "Changing a form").

**An earlier release reads a later array as it always did.** 0.2.x does
not know the key and cannot check it. Its readers ignored keys they did not
know, so an array written by 0.3.0 loads in 0.2.x with the version, and
whatever else 0.2.x has no key for, left unread: an application that rolls
back from 0.3.0 keeps reading its rows. One reader is the exception:
`PotDrawOptions::fromArray()` of 0.2.2 and 0.2.3 refused unknown keys
already, and refuses `format_version` with them; drop the key from stored
pot draw options before reading them with those releases. From 0.3.0 on
every reader refuses a key and a version it does not know, so the question
does not come up again in this form: a later array is either the same form
or is refused.

One form says more in 0.3.0 than it did, and is still version 1. The
`participants` of a schedule are its entrants in their order, where 0.2.x
listed the participants in the order they first appear in the events. The
key, its type and what a reader does with it are the same: each participant
of the schedule once, referenced by ID from the events. An array from 0.2.x
read by 0.3.0 gives the entrants in the only order the array holds, which is
the order a schedule built from those events has anyway; an array from 0.3.0
read by 0.2.x gives the same events. Neither reader misreads the other's
array, which is the test the next paragraph but one states.

A second form means something else in 0.3.0 than it did, and is still
version 1. Round-robin options that name no role assignment are written
without the `role_assignment` key, by 0.2.2, 0.2.3 and 0.3.0 alike, and the
usage guide of 0.2.2 and 0.2.3 said what the absent key means: no role
assignment was named, and the options follow the default of the release that
reads them. 0.3.0 changes that default from round-parity roles to roles that
are balanced where possible: balanced wherever the scheduler finds a
schedule with them, which without constraints is always, and otherwise the
round-parity roles, with a mark in the schedule's metadata. So an array
without the key gives round-parity roles when 0.2.x reads it and that
default when 0.3.0 does, which is what its writer was told it would do:
neither reader misreads the other's array, and the form does not take a
version for it. (0.2.0 and 0.2.1 had neither the key nor a role assignment
to name; their options are read the same way.)

The default of 0.3.0 has an identifier of its own,
`balanced_where_possible`, beside `round_parity` and `balanced`, which mean
what they meant: the roles of 0.2.x exactly, and balanced roles or a
failure. It is written only for options that named the default, as the
other two are, and is part of version 1 as 0.3.0 defines it. 0.2.2 and
0.2.3 do not know it and refuse an array that holds it (an unknown
identifier), loudly, where an array that names nothing loads there and
gives round-parity roles.

The price is that nothing is refused: an application that stored such options
under 0.2.x and generates a schedule from them again gets the same pairings
in the same rounds with other roles, and no error tells it. (Under
constraints the pairings and rounds can differ as well, where another
rotated participant order completes the schedule; and where no schedule
with balanced roles is found, the roles are the ones 0.2.x gave and the
metadata says so.) Options that
named a role assignment, the old default included, were written with the key
and are read as they were written. Stored options that must keep the roles
of 0.2.x take `'role_assignment' => 'round_parity'` before the upgrade; the
changelog of 0.3.0 says so.

The alternative was version 2 of the form, with version 1 read as round
parity. It was not taken, because an array without the version key is
version 1: every options array written by hand, in a configuration file or
in code, would then mean round-parity roles for good, and
`RoundRobinOptions::fromArray(['legs' => 2])` would not be
`new RoundRobinOptions(legs: 2)`. The default of 0.3.0 would have been the
default for options built in code only.

That is a statement about the step from 0.2.x to 0.3.0, the release that
fixes what version 1 is. From 0.3.0 on, an absent `role_assignment` in
version 1 means what `balanced_where_possible` means, and a release that
changes the default again changes the form ("Changing a form" below names
the case) and takes version 2 for it.

The mark of a fall-back is not a key of the schedule form. It is an entry
of the schedule's `metadata`, which the form carries as the free-form map
it always was: `role_fallback`, with the reason as a string, present only
on a schedule that fell back. A reader that does not know it keeps it with
the rest of the metadata, 0.2.x included, so the schedule form stays
version 1.

**A version the reader does not know is refused.** A value above the newest
version the release reads means the array was written by a later release.
It is refused, with the version found and the newest one read, and never
read on the hope that the forms are close enough. So is a value below 1 and
a value that is not an integer, `null` included: the key is the library's
own and it never writes it as null. Configuration reports this as an
`InvalidConfigurationException` with the reason `UnsupportedFormatVersion`,
data as an `InvalidInputException`, each form using the exception it uses
for every other fault of its array.

**Versions belong to forms, not to releases.** Each form counts its own
versions from 1. A release that changes the schedule form makes it version
2 and leaves every other form where it was. The number says nothing about
the library version and is not compared with it.

**Changing a form.** A form changes when an array it wrote before would be
refused or read differently by the code that reads the new one, or the
other way round: a key added, removed or renamed, a value given another
type or another meaning, a default changed for a key that may be left out.
Such a change:

1. takes the next version number for that form, and `toArray()` writes it;
2. keeps `fromArray()` reading every earlier version, each under its own
   rules (`Serialization\PlainData` returns the version of the array it
   checked, for the reader to branch on);
3. is made in a minor release, never in a patch, with a changelog entry
   that names the form, the two versions and what differs;
4. shows as a diff that is reviewed with the change: of the wire fixtures
   in `tests/Fixtures/golden/wire/` for a schedule and a stage state, the
   two forms that have one, and of the arrays the tests of the form expect
   for every other form.

An earlier version stops being read only in a major release, and the
changelog of that release says so.

**The fingerprints are not format versions.** `format_version` says how an
array is to be read. An engine fingerprint says which engine, under which
configuration, may read a stage state; it is one string value of the stage
state form, and the `v2` inside it versions how that string is composed. An
outcome fingerprint is a hash whose scheme names the keys it covers, and
`format_version` is not one of them. Either scheme can move on without any
form changing, and a form can change without either scheme moving. 0.3.0
shows the first: it moved the engine fingerprint scheme from `v1` to `v2`
([ADR 0004](0004-position-decides-where-results-do-not.md)), and the stage
state form is version 1 as it was. A stage state of format version 1 holds
a stamp of either scheme: the form stores the string and does not interpret
it, so a state stamped by 0.2.x loads, and it is the engine that refuses
the stamp.

## Consequences

- Data written by 0.2.x loads in 0.3.0 and later as version 1. What 0.3.0
  refuses that 0.2.x accepted is not a matter of the version: it is input
  that was never valid and was read without a check, and the changelog of
  0.3.0 lists each case.
- Every stored array is one key longer. Nested forms are not, so the cost
  does not grow with the number of events.
- A nested form has no version of its own to be read by. Its reader, called
  for a part that carries no key, reads version 1. So a form that is held
  in others (an event in a schedule, a result, a round pairing, a stage
  state, a scheduled event) cannot change by itself: the forms that hold it
  take their next version with it, and their readers tell the reader of the
  part which version to read. A part cut out of a stored array and handed to
  its own `fromArray()` is read as version 1 whatever array it came from,
  which is right only while the part's form is at version 1. That is the
  price of one key to an array, and it is to be paid in the release that
  first changes a nested form.
- An array written by a later release fails loudly on an older one where
  its form has changed, and loads where it has not.
- A reader that meets `format_version` under a key it does not otherwise
  know about does not refuse it as an unknown key: it is a key of every
  form.
- Code that compares a `toArray()` result with a literal array, or stores
  it under a schema of its own, sees the new key.
- `tests/Feature/PlainDataFormsTest.php` holds every form to the rule: the
  key is first, a nested form carries none, an array without it reads as
  the same value as one with version 1, and every other value is refused.
  The catalogue it reads (`tests/Support/PlainDataForms.php`) is compared
  with the `fromArray()` and `toArray()` methods `src/` declares, so a new
  form fails the suite until it is listed.
- The usage guide states the rule for a reader of stored data
  ([Format Versions](../USAGE.md#format-versions)); this record is where it
  is decided.
