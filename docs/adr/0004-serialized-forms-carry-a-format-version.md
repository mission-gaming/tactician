# 0004. Serialized forms carry a format version

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
the `v1` inside an engine fingerprint (`tactician:v1:swiss...`,
`Stage\EngineFingerprint`) and the `v1` in front of a repack outcome
fingerprint (`RepackOutcome::FINGERPRINT_SCHEME`). Each is the version of
the scheme its own string is built by.

## Decision

**One key, in one place.** Every array a `toArray()` of the library returns
has `format_version` as its first key. The value is an integer, the version
of that form. `toJson()` and `jsonSerialize()` write the same array.

**One version to an array.** A form held inside another is written without
the key: the participants and events of a schedule, the round of an event,
the rounds and results of a stage state, the records of a repack outcome.
The array that is stored states one version, at its top, and what it holds
is read under that version. A part serialized by itself, with its own
`toArray()`, is the top of its own array and carries the key. A reader
accepts the key on a nested form too, provided it is a version it reads, so
an array put together by hand from separately serialized parts loads.

**A missing version is version 1.** Version 1 of every form is the form
that 0.2.x wrote, which had no version key. An array without the key is
therefore read as version 1, under the rules of version 1, and data stored
by 0.2.x keeps loading. 0.3.0 writes version 1 with the key: the forms did
not change in 0.3.0, the key was added to say so. Because 0.2.x ignored
keys it did not know, an array written by 0.3.0 is read by 0.2.x as well.

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
4. shows as a diff of the wire fixtures in `tests/Fixtures/golden/wire/`,
   which is reviewed with the change.

An earlier version stops being read only in a major release, and the
changelog of that release says so.

**The fingerprints are not format versions.** `format_version` says how an
array is to be read. An engine fingerprint says which engine, under which
configuration, may read a stage state; it is one string value of the stage
state form, and the `v1` inside it versions how that string is composed. An
outcome fingerprint is a hash whose scheme names the keys it covers, and
`format_version` is not one of them. Either scheme can move to `v2` without
any form changing, and a form can change without either scheme moving. A
stage state of format version 1 may hold a stamp of a later fingerprint
scheme: the form stores the string and does not interpret it.

## Consequences

- Data written by 0.2.x loads in 0.3.0 and later as version 1. What 0.3.0
  refuses that 0.2.x accepted is not a matter of the version: it is input
  that was never valid and was read without a check, and the changelog of
  0.3.0 lists each case.
- Every stored array is one key longer. Nested forms are not, so the cost
  does not grow with the number of events.
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
