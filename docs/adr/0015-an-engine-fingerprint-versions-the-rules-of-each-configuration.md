# 0015. An engine fingerprint versions the rules of each configuration

Status: accepted.

## Context

An engine fingerprint (`FingerprintedEngine::getFingerprint()`, written by
`Stage\EngineFingerprint`) is a string a stage state can be stamped with,
so that an engine refuses a state another engine, or another configuration,
paired. 0.2.2 introduced it as `tactician:v1:<format>;<options>`, with the
options at their default left out so that an option can be added without
changing a stamp. The `v1` was one version for every format: the scheme.

Two things were left open (issue #57, and its comment).

First, how a stage's engine is described when the application holds the
history and builds the state from its rows on every request
(`StageState::fromHistory()`), with three candidates: the opaque string as
it is; a structured description the engine could be rebuilt from; or no
stamp at all, the engine checking the supplied history against its own
configuration.

Second, how the string changes between releases. 0.3.0 pairs a Swiss round
and a re-seeded bracket round from another order than 0.2.x
([0004](0004-position-decides-where-results-do-not.md)), and the
first draft of 0.3.0 changed the scheme to `v2` for every engine. That
refused every state 0.2.x stamped, the stamps of brackets on a fixed path
and of double elimination brackets included, although those are paired as
0.2.x paired them. Measured against the 0.2.3 tag, with every result
recorded: 1,080 stages (single elimination over one and two legs, double
elimination over one and two legs and without a reset, 2 to 19 entrants,
twelve result patterns each) pair the same rounds, round for round, roles
included. The next change to one engine would again have refused the
stamps of all of them.

## Decision

**The stamp stays an opaque string, compared for equality.** It is not a
description to build an engine from. An engine has parts no description
the library writes can hold (constraints are closures; a ranking strategy,
a tiebreaker or a calculator of the application's is code), so a
description would rebuild a different engine without a word or refuse
those parts; and the application builds its engine from its own
configuration already (`EliminationOptions`, `SwissOptions` and the
standings rules have plain-data forms). A description would also be a
second serialized form of every option, versioned under
[0005](0005-serialized-forms-carry-a-format-version.md), for no gain over
comparing two strings.

**The check of a history against the engine is a report, and the stamp is
the refusal.** `HistoryReadingEngine::getRecordedRounds()` already shows,
beside every recorded round, the round the engine would have paired there
([0007](0007-recorded-rounds-are-read-not-derived.md)), and a round paired
by other rules shows as manual. It cannot replace the stamp: a round drawn
by hand is allowed, so a manual round is no reason to refuse; and two
configurations often pair the same rounds for a while (round 1 of a Swiss
stage does not depend on the ranking scale), so agreement proves nothing.
An application that holds the history keeps the fingerprint beside the
stage's configuration, written when the stage starts, and stamps every state
it builds from the rows with it.

**The version in the string is the version of the rules of the
configuration, stated by the engine.** `EngineFingerprint::of($format,
$rules)` takes it. It starts at 1. A minor release that pairs a
configuration differently from the same recorded state raises the version
of that configuration, and of no other; every other configuration keeps its
string, so a state it stamped is still accepted. The spelling is the one
0.2.x wrote (`tactician:v<rules>:<format>;<options>`), so a configuration
whose rules did not change has the string 0.2.x wrote for it.

In 0.3.0:

| Configuration | Version | Why |
| --- | --- | --- |
| Swiss | 2 | Level participants in list order ([0004](0004-position-decides-where-results-do-not.md)) |
| Single elimination, re-seeded | 2 | Survivors in list order, a bye credited ([0004](0004-position-decides-where-results-do-not.md)) |
| Single elimination on a fixed path | 1 | Paired as 0.2.x paired it |
| Double elimination | 1 | Paired as 0.2.x paired it |

One case pairs otherwise on a fixed path and in double elimination, and does
not raise the version: a withdrawal after the first round. 0.2.x drew the
bracket again from the participants left, which rule 5 of issue #57 calls a
defect; 0.3.0 gives the tie away and reads a state with such a withdrawal
that way. In the same measurement, with one withdrawal after round 1 or 2,
0.2.3 mostly offered a round 1 again, which could not be recorded (211 of
240 stages). In the rest it paired a round with the right number that put
an entrant back where a result had already sent it down or out (5 of the
240; more often where the entrant who withdrew had just won a two-legged
tie), and that round could be recorded. A state stamped by 0.2.x that
holds such a round is accepted by 0.3.0 under its `v1` stamp:
`getRecordedRounds()` reports the redrawn round as manual (and the rounds
before it too, because 0.2.x kept no place in its list for the entrant who
withdrew), and the next round follows the bracket as 0.3.0 reads it, not
the redraw. That is the price of keeping the
stamps of every other bracket valid: a version raised for the whole
configuration would refuse all of them, to protect stages that 0.2.x had
already paired against its own bracket.

When the options of two fingerprints differ, the error names the options
(the version may differ with them, as fixed and re-seeded single
elimination do). When only the version differs, it says that an earlier
release, or a later one, wrote the stamp.

**Options follow the rule they always did.** An option at its default is
left out, a new option's default is what the engine did before it existed,
and a default is not changed. The seed of a Swiss engine
(`SwissPairingEngine(seed:)`, rule 4 of issue #57) is such an option: it is
part of the fingerprint when it is given (`;seed=2026`), and every engine
without one has the string it had. It needs no new version: an engine with
a seed is a configuration that did not exist before, and an engine without
one pairs as it did. A `Randomizer` stays out, as before: the state of an
object has no name. Results-free mode is part of it when it is on (#103's
rule, as issue #57 asks).

## Consequences

- A state 0.2.x stamped for a bracket on a fixed path or a double
  elimination bracket is accepted by 0.3.0 as it is. A state 0.2.x stamped
  for a Swiss stage or a re-seeded bracket is refused until the application
  stamps it again, as before.
- A later change to one engine's pairing refuses only the stamps of the
  configurations it changes. The engine states the version per
  configuration, and the test that pins the strings
  (`tests/Unit/Stage/StageStateEngineFingerprintTest.php`) holds every
  configuration's string, so a change of rules shows there as the rows it
  changes.
- An unstamped state is still accepted by every engine, and a Swiss stage
  carried on unstamped across a change of rules gets its next round by the
  new rules without a word. Refusing unstamped states would refuse every
  state stored without a stamp, brackets included. The report of
  `getRecordedRounds()` is where such a stage shows: the rounds the earlier
  rules paired otherwise are manual.
- The stage state form does not change: the stamp is one string value of it,
  whatever its version ([0005](0005-serialized-forms-carry-a-format-version.md),
  "The fingerprints are not format versions").
- Raising a version is a decision per configuration, and getting it wrong
  in the generous direction (not raising it for a configuration that pairs
  differently) lets a stamped state be paired by other rules without a
  refusal. The measurement above is how 0.3.0 made it, and a release that
  changes pairing makes it the same way: replay the stages of the previous
  tag and compare.
