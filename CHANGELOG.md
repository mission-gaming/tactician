# Changelog

All notable changes to Tactician are recorded in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
with the 0.x allowance described in the README's
[Versioning and stability](README.md#versioning-and-stability) section.

A patch release never changes a correct output for a fixed input and seed. Where
a release changes an output that was itself broken, the entry appears under the
heading **Output change (fix)**.

## [Unreleased]

### Output change (fix)

- `SeedProtectionConstraint::getName()` states the protection period as the
  percentage it is. The period is a fraction between 0.0 and 1.0, and the name
  wrote that fraction in front of a percent sign, so a 20% window was named
  `0.2% period`. The digits also followed PHP's `precision` setting, so the
  same constraint had a different name under a different setting. Before and
  after, for `new SeedProtectionConstraint(2, 0.2)`:

  ```
  Seed Protection (top 2, 0.2% period)                    before
  Seed Protection (top 2, 0.20000000000000001% period)    before, with precision=17
  Seed Protection (top 2, 20% period)                     after, under every setting
  ```

  The name is the key under which
  `ConstraintViolationCollector::getViolationsByConstraint()` groups
  violations, and it appears in the diagnostic report and the suggestions of
  an `IncompleteScheduleException`. Code that matches the name of this
  constraint must change the string it matches: multiply the number in it by
  100 and round it to two decimal places. The percentage is rounded to at most two decimal places, a half going
  up, and is written with `.` as the decimal separator and without trailing
  zeros, whatever the `precision` and `serialize_precision` settings and the
  locale: 0.125 gives `12.5%`, 1/3 gives `33.33%`, 2/3 gives `66.67%` and
  0.1 + 0.2 gives `30%`. The name therefore does not identify the period
  exactly: two periods closer than 0.0001 can share a name, as 1/3 and 0.3333
  do. No other constraint's name changes, and no schedule changes.
- `IncompleteScheduleException::getDiagnosticReport()` writes the share of
  missing events with `.` as the decimal separator whatever the locale:
  `Missing Events: 6 (100.0%)`. Under a locale that writes a decimal comma it
  wrote `Missing Events: 6 (100,0%)`, so the same failure produced two
  different reports on two machines. Some PHP builds take the locale from the
  environment (`LC_ALL`, `LANG`) at startup, so this needed no `setlocale()`
  call in the application. Under the default `C` locale the report is
  unchanged.
- Participant ids that are equal as numbers (`'01'` and `'1'`, `'1e3'` and
  `'1000'`), or that contain `|`, are told apart. **A field with no such ids
  is unaffected**: every key, message and schedule is byte for byte what it
  was, including the order of plain decimal ids (`9` before `10`). No action
  is needed either way. Every map of pairings was keyed by `sort($ids)` and
  `implode('|', $ids)`; `sort()` compares numeric strings as numbers, so two
  such ids tied and the key depended on which was named first, and an id
  with `|` gave two pairings one key (`a` v `b|c`, and `a|b` v `c`). For a
  field that holds such ids:
  - `SwissPairingEngine::pairNextRound()` repeated a pairing, or passed over
    one that had not been played. With ids `0e1`, `0e2`, `01`, `1` and every
    result drawn, round 2 was round 1 again; it is now `01 v 0e1, 1 v 0e2`.
  - `RoundRobinPlan::validateIntegrity()` and `findUnplayedPairings()`, and
    `SwissPlan::validateIntegrity()`, counted one pairing as two or two as
    one, and named a pairing with `|` in an id wrongly (`Pairing a vs b vs
    c`). Each violation now counts and names one pairing: `Pairing a vs b|c
    appears 2 time(s); Swiss pairings may not repeat.`
  - A `DiagnosticReport`, and so the report of an
    `IncompleteScheduleException`, listed scheduled pairings as missing.
  - `PairingSpacingMetric` measured the two meetings of such a pair as two
    pairs that meet once, so `ScheduleOptimizer` could keep a different
    candidate.
  - `MatchOutcomeSelector` treated the two legs of a tie as two ties, or two
    ties of a round as one.
  - `StageState::withRoundPlayed()` and `withAdditionalResults()` accepted a
    result for an event outside the pairing when its ids joined to the text
    of an event inside it. They now throw the
    `InvalidConfigurationException` their contract states. The `event`
    entry in that exception's context writes a `|` inside an id as `\|` and
    a `\` as `\\`.
  - For an event of three or more participants, which no generator or engine
    produces, `StageState` rejected a result that named the participants in
    another order when PHP does not order their ids consistently (`2` is
    below `10` as a number, `10` below `1a` and `1a` below `2` as text). The
    event is now found in every order.
- `SwissPairingEngine` treats two participants as level when their
  win/draw/loss totals differ only by the rounding of a float sum. **This
  affects only a `WinDrawLossRanking` whose values floats cannot hold
  exactly (0.1 for a draw). Nothing changes for 3/1/0, 1/0.5/0 or any scale
  in whole or half points, nor for any other `RankingStrategy`, whose values
  are compared exactly as before.** On such a scale the same results added
  in another order give different sums: at 1 for a win and 0.1 for a draw,
  win-draw-draw is 1.2000000000000002 and draw-draw-win is 1.2. The engine
  compared the two exactly, so level participants fell into different score
  groups: a randomizer never shuffled them together, and a bye credited as a
  win ranked above or below the win it stands for. Two totals are now level
  when they are no further apart than the rounding of sums of that many
  results can put them (for n results and a largest value M, 2 x n x n x M x
  `PHP_FLOAT_EPSILON`: 4e-15 after three rounds at 1 for a win), and never
  when a real result separates them; within a group the standings order
  decides, and a randomizer shuffles the group. What to check: a seeded
  stage on such a scale can pair differently from 0.2.1. The standings table
  itself is unchanged.
- `ScheduleScorer` refuses numbers that cannot be compared. Its constructor
  accepted a weight of `NAN` or `INF`, because `NAN` fails no comparison and
  `INF` is positive, and `score()` and `report()` returned whatever a metric
  measured. They now throw `InvalidConfigurationException`: the constructor
  for a weight that is not finite, `score()` and `report()` for a metric that
  measures `NAN` or `INF`, and `score()` for a weighted sum that overflows.
  Only a metric of your own can measure such a value; the built-in metrics
  do not.
- `InvalidConfigurationException::getDiagnosticReport()` writes a list in the
  context out entry by entry. It wrote the size of the list and nothing else,
  so a report about two colliding events did not say which two. That
  contradicted the contract of `getDiagnosticReport()`, which is a report
  with the detail needed to resolve the problem. Before and after, for a
  participant pinned twice in a repack request:

  ```
  • event_ids: [2 items]         before
  • event_ids: ["e1", "e2"]      after
  ```

  A list is written in its own order, with strings in double quotes and with
  keys where the array is not a list (`[from: "2026-01-01", to: "2026-01-02"]`).
  Inside the quotes a double quote and a backslash are written with a
  backslash before them and a control character as its C escape (`\n`,
  `\000`), so one entry is always one quoted run on one line.
  An object is still written as its class name. A list of more than 20
  entries is cut after the twentieth and followed by the number left out
  (`... 80 more of 100`), and a list nested more than three levels deep is
  still written as its size. An empty list is still `[0 items]`, and a value
  that is not a list is written as before. `getContext()` is unchanged.
- The same report writes an object of an anonymous class as
  `class@anonymous` (or the name of its parent or first interface before
  `@anonymous`). It wrote the internal name of the class, which holds a NUL
  byte and the absolute path of the file that declares it, so the report of
  one error differed from one machine to the next and put a filesystem path
  in a log:

  ```
  • strategy: class@anonymous<NUL>/srv/app/src/League.php:12$0    before
  • strategy: class@anonymous                                     after
  ```

  An object of a named class is written as its class name, as before.
- The "REQUIREMENTS" block of that report no longer lists the round-robin
  requirements under an error that has nothing to do with a round robin.
  Every configuration error ended with the same five lines ("Participants
  array must contain at least 2 participants", "Legs must be a positive
  integer (≥ 1)" and three more), so a timezone that could not be parsed and
  a pin conflict in a repack request were each reported with requirements
  that did not apply to them. Before and after, for
  `TimelineDefinition::fromArray()` with the timezone `Neverland/Nowhere`:

  ```
  === INVALID CONFIGURATION DIAGNOSTIC REPORT ===          before and after

  Issue: start or its timezone is not parseable

  === CONFIGURATION DETAILS ===
  • start: 2026-08-01 19:00:00
  • timezone: Neverland/Nowhere
                                                           before only
  === REQUIREMENTS ===
  • Participants array must contain at least 2 participants
  • Legs must be a positive integer (≥ 1)
  • All participants must have unique IDs
  • Constraint set must be valid
  • Scheduler must support the requested configuration
  ```

  The block is unchanged for the errors it describes: those of
  `RoundRobinScheduler`, `RoundRobinOptions` and `Stage\RoundRobinPlan`. It is
  also unchanged for the three factories on `SchedulingException` and for an
  `InvalidConfigurationException` that code outside the library builds the
  way it did before, because the library cannot tell what those describe.
  Every other configuration error the library raises now has no
  "REQUIREMENTS" block, and its report ends with the configuration details.
  That includes the errors of `Stage\StageState` (a duplicate ID given to
  `start()`, a round recorded out of order, an event or a result that does
  not belong to the round recorded), which are not about a round robin
  either.
- The suggestion `IncompleteScheduleException::getDiagnosticReport()` gives
  for a consecutive role constraint pointed the wrong way. The limit of a
  `ConsecutiveRoleConstraint` is the most events in a row a participant may
  have in one role, so reducing it makes the constraint stricter:

  ```
  • Try reducing the consecutive role constraint limit     before
  • Try raising the consecutive role constraint limit      after
  ```
- A timezone string that holds a NUL byte is rejected with an
  `InvalidConfigurationException`, like every other timezone PHP cannot use.
  PHP raises a `\ValueError` for such a string, which is not an `\Exception`,
  so it passed the catch clause in `Timeline\ZonedTime::parse()` and reached
  the caller as a PHP error. `TimelineDefinition::fromArray()`,
  `SessionGrid::fromArray()` and `BlackoutRule::fromArray()` document an
  `InvalidConfigurationException` for a malformed value, and JSON-decoded
  configuration can hold such a string (`"Europe/Lon\u0000don"`). The message
  is the one an unknown timezone gives (`start or its timezone is not
  parseable`), the reason is `UnparseableTime`, and the `\ValueError` is the
  previous exception. Code that caught `\ValueError` or `\Error` around these
  calls for this case no longer sees it there: catch
  `InvalidConfigurationException`.
- A datetime in plain-data configuration must state a complete, absolute date
  and time. **Configuration that writes its datetimes out in full
  (`2026-08-01 19:00`, `2026-08-01T19:00:00`, with or without seconds or a
  fraction) is unaffected: every such string parses to the instant it did
  before.** The string went straight to PHP's date parser, which also accepts
  `now`, `tomorrow`, `+1 week`, `next monday 20:00` and the empty string and
  resolves them against the clock, so the same configuration gave a
  different timeline or session grid each time it was loaded. That
  contradicts the rule that the library never asks for the current time.
  These are now rejected with an `InvalidConfigurationException` (reason
  `UnparseableTime`, the message an unparseable string gives: `start or its
  timezone is not parseable`) by `TimelineDefinition::fromArray()` (`start`),
  `SessionGrid::fromArray()` (`sessions`) and `BlackoutRule::fromArray()`
  (`from`, `to`), and with an `InvalidInputException` (`Scheduled event
  kickoff is not parseable`) by `ScheduledEvent::fromArray()` and
  `ScheduledSchedule::fromArray()`/`fromJson()` (`kickoff`):
  - a string relative to the current time, and the empty string;
  - a string that leaves a part out: a time of day without a date (`20:00`),
    a date without its year (`August 1 20:00`), and **a date without a time
    of day (`2026-11-09`), which PHP read as midnight: write
    `2026-11-09 00:00`**;
  - a relative part on top of a complete date and time
    (`2026-08-01 20:00 +1 week`), and a weekday name that contradicts the
    date (`Mon, 01 Aug 2026 19:00:00 +0000`, a Saturday, which PHP moved to
    the Monday after);
  - a date or a time that does not exist, which PHP rolled over into the
    next one: `2026-02-30 20:00` (read as 2 March), `2026-08-01 24:00`,
    `2026-08-01 23:59:60`;
  - a string with two timezones (`2026-08-01 19:00 UTC UTC`).

  What to check: configuration or stored data that holds one of these. The
  other absolute forms PHP reads are accepted as before, among them a month
  name (`1 August 2026 19:00`), RFC 2822, an ISO 8601 week date
  (`2026-W31-6T19:00`) or ordinal date (`2026-213T19:00`) and a Unix
  timestamp (`@1785610800`); a zone or offset in the string is still checked
  against the `timezone` field afterwards. A relative date is for the
  application to compute and pass on.
- A repack request whose objective weights are too large to keep the
  objective an integer is rejected with an `InvalidConfigurationException`.
  It returned an outcome before. The repacker scores a move as at most
  `earlyFillWeight × (sessions − 1) + 2 × consolidationWeight`; beyond
  `PHP_INT_MAX` PHP computed that as a float, in which the smaller weight
  was lost, so the sessions chosen were not the trade the two weights
  state. The outcome was still a proper one (no participant double-booked,
  every event assigned or reported), which is why this is listed as a
  change and not only as a fix. `RepackOptions` rejects a consolidation
  weight above `RepackOptions::MAX_CONSOLIDATION_WEIGHT` (half of
  `PHP_INT_MAX`, reason `ValueOutOfRange`), and `RepackRequest` rejects
  weights for which the expression is too large for the number of sessions
  of its grid (reason `IncompatibleOptions`). Before and after, on a grid of
  four sessions:

  ```
  new RepackOptions(consolidationWeight: PHP_INT_MAX)    before: accepted, and the request repacked
                                                         after: InvalidConfigurationException
  new RepackOptions(earlyFillWeight: PHP_INT_MAX)        before: accepted, and the request repacked
                                                         after: accepted; RepackRequest throws
  ```

  Code that passed `PHP_INT_MAX` to make one weight outrank the other must
  pass a smaller number: a consolidation weight above
  `earlyFillWeight × (sessions − 1)`, or an early-fill weight above
  `2 × consolidationWeight`, already outranks the other in every move. No
  request with weights within the bounds is affected, and its outcome is
  unchanged.
- A `RepackOutcome` built with a list that holds something other than the
  objects it is for (`new RepackOutcome(['x'], [], [])`) is rejected with an
  `Exceptions\InvalidInputException` that names the list, the key and the
  type found. A wrong entry among the assignments died with a PHP `Error` (a
  method call on a string) in the constructor. A wrong entry among the
  unplaced events or the violations was accepted, returned as it was by
  `getUnplaced()` and `getViolations()`, and died with a PHP `TypeError` in
  `toArray()` and `getViolationsOfKind()`; such an outcome can no longer be
  built.

### Added

- `Standings::getTiedSets()` reports where the order of a standings table
  comes from the final fallback and not from a result. It returns a list of
  the new `Standings\TiedSet`, in table order: each one holds two or more
  adjacent entries that are level on the ranking value, on every configured
  tiebreaker, on score difference and on scores-for, with the positions the
  set spans (`getEntries()`, `getParticipants()`, `getFirstPosition()`,
  `getLastPosition()`, and it is countable). A table in which results decide
  every position returns an empty list; a table with no results returns one
  set of every entry. `StandingEntry::isLevelWith()` is the comparison behind
  it. Values are level only when they are equal, with no tolerance, which is
  how the table is ordered. The entries, their order and every existing
  accessor are unchanged: an application can now see a tie, and the table
  still gives every entry its own position.
- A reason on every configuration error, so that code does not have to match
  message text: `InvalidConfigurationException::getReason()` returns a case
  of the new backed enum `Exceptions\InvalidConfigurationReason`
  (`TooFewParticipants`, `UnparseableTime`, `PinConflict` and others; the
  usage guide lists them with their backing strings, which are stable
  identifiers). Every site in the library that builds the exception sets
  one, those of `Stage\StageState` included (`RoundOutOfSequence`,
  `EventNotInRound`, `NoRoundRecorded`, `ResultNotRecorded`,
  `RoundSuperseded`, `EmptyEngineFingerprint` and
  `EngineFingerprintMismatch` for recording a round or its results,
  replacing a result and the engine fingerprint). `getReason()` returns null
  only for an exception that code outside the library builds without a
  reason. A `match` over the reason needs a `default` arm, because a release
  may add a case.
- `Exceptions\PinConflictException`, thrown by `RepackRequest` when one
  participant is pinned in two events at the same session and slot.
  `getEventIds()` returns the IDs of the two events, and `getParticipantId()`,
  `getSession()` and `getSlot()` say who and where. It extends
  `InvalidConfigurationException` with the same message, context, code and
  previous exception as before, so existing catch clauses and message checks
  still match.
- `InvalidConfigurationException::getRequirements()`, the statements the
  report prints under "REQUIREMENTS", as a list; and the constant
  `ROUND_ROBIN_REQUIREMENTS` that holds the round-robin ones.
- Two optional parameters at the end of the constructor of
  `InvalidConfigurationException`: `reason` and `requirements`. A call written
  against the five parameters it had before behaves as it did.
- A test (`tests/Feature/ConfigurationErrorReasonsTest.php`) that reads
  `src/` and fails when a site builds an `InvalidConfigurationException`
  without a reason, when an enum case is used by no site, or when the usage
  guide's table of reasons differs from the enum.

- One catchable type for every exception the library throws on purpose: the
  marker interface `Exceptions\TacticianException`. `catch (TacticianException)`
  now covers the scheduling failures under `SchedulingException` and the
  rejected arguments and malformed data that were thrown as a bare
  `\InvalidArgumentException` before. Three classes carry it to the sites
  that were outside `SchedulingException`: `Exceptions\InvalidInputException`
  (extends `\InvalidArgumentException`, 60 sites in the DTOs, the stage and
  timeline value objects, the constraints, `SwissPairingEngine` and
  `StandingsCalculator`), `Exceptions\InvariantViolationException` (extends
  `\LogicException`, three internal guards) and
  `Exceptions\JsonConversionException` (extends `\JsonException`). Each
  extends the type its sites threw before, so existing catch clauses still
  match, and no message, code or previous exception has changed. The usage
  guide lists the classes and what the marker does not cover.
- An architecture test (`tests/Feature/ExceptionMarkerTest.php`) that fails
  when a `throw` in `src/`, an exception built there, or a method's return
  type names a class outside `TacticianException`.
- `StageState::withResultReplaced()`: replaces the recorded result of one
  event of the last recorded round and returns a new state. `StageState` had
  no verb to change a result, so a result entered wrongly meant rebuilding
  the state. It is a correction of what was recorded, not a way to decide
  an event. The event is found by round number, participants in either order
  and tie leg. The method throws
  `InvalidConfigurationException` when no round is recorded, when the event
  has no recorded result, and when its round is not the last recorded one:
  the rounds after it were paired from its results, so the state is rebuilt
  instead (`StageState::start()`, then `withRoundPlayed()` for each round
  that stands).
- An optional engine fingerprint on `StageState`:
  `withEngineFingerprint()`, `getEngineFingerprint()` and
  `requireEngineFingerprint()`, and `getFingerprint()` on
  `SwissPairingEngine`, `SingleEliminationEngine` and
  `DoubleEliminationEngine`. A state did not say which engine paired its
  rounds, so a state restored into another engine, or into the same engine
  built from other options, was replayed as that engine's own history. A
  state stamped with a fingerprint is refused by `getPlan()`,
  `pairNextRound()`, `isComplete()` and `getOutcome()` of every engine whose
  fingerprint differs, with an `InvalidConfigurationException`. The stamp is
  opt-in. An unstamped state is accepted by every engine as before and
  serializes exactly as before; a stamped one adds an `engine_fingerprint`
  key to `toArray()` and `toJson()`, and `fromArray()` loads data without
  the key as an unstamped state.
- Property tests over awkward participant ids: numerically equal strings,
  leading zeros, exponent forms, ids that contain `|`, `:` or `\`,
  empty-looking ids, Unicode and control characters, across round robin
  (multi-leg, randomized, shuffled and backtracking), Swiss and both
  elimination engines. Each asserts the schedule or bracket is the one
  ordinary ids give, seat for seat.
- `Stage\PairKey`, the one helper that builds every pairing key. It is
  marked `@internal` and is not public API.
- A decision record,
  `docs/adr/0003-multi-participant-events-are-a-2-0-goal.md`: events with
  more than two participants (a race, a lobby) are a goal for 2.0, the
  library supports pairwise events only until then, and code written before
  2.0 must not make that goal harder than it needs to be. The agent guide
  carries the rule, and the roadmap lists the pairwise scope under its known
  limitations. No behavior changes.

- Repack: a session grid without instants. `SessionGrid::shapeOnly(int
  $sessions, int $slotsPerSession = 1, array $slotsPerSessionOverrides = [],
  ?int $capacityPerSlot = 1)` builds a grid that has the positions and no
  times, for an application that keeps its own. It is repacked exactly as
  the instant-based grid of the same shape is. `hasInstants()` tells the two
  forms apart. On a shape-only grid `getSessionStart()`, `getSlotInterval()`
  and `getSlotTime()` throw the new `Exceptions\UnavailableValueException`;
  its assignments have no kickoff (`SlotAssignment::hasKickoff()` is false,
  `getKickoff()` throws the same exception, and `toArray()` carries
  `'kickoff' => null`); and as plain
  data it has `session_count` in place of `sessions`, `timezone` and
  `slot_interval`. An instant-based grid, its assignments and its plain data
  are unchanged.
- `Exceptions\UnavailableValueException` (final, extends `\LogicException`,
  implements `TacticianException`): an object was asked for a value it does
  not hold. It is thrown by `SessionGrid::getSessionStart()`,
  `getSlotInterval()`, `getSlotTime()` and `positionOf()` given an instant on
  a shape-only grid, by `SlotAssignment::getKickoff()` on an assignment made
  on one, and by `SessionGrid::getCapacityPerSlot()` on a grid of unbounded
  capacity. It reports a mistake in the calling code, which the `has...()`
  method named in its message would have prevented, so it is distinct from
  `InvalidConfigurationException` (a configuration that cannot work) and
  from `InvariantViolationException` (a defect in the library). It is a
  `\LogicException` so that those methods declare no checked exception:
  static analysis of code that calls `getKickoff()`, `getSlotInterval()` or
  `getCapacityPerSlot()` on the grids and assignments it always had reports
  nothing new.
- Repack: `SessionGrid::ordinalOf(int $session, int $slot)` returns a
  position's 0-based index in grid order, and
  `SessionGrid::positionOf(DateTimeImmutable|int $at)` returns
  `['session' => ..., 'slot' => ...]` for an ordinal or, on an instant-based
  grid, for an instant, and null when the grid has no such position.
- Repack: unbounded slot capacity. `capacityPerSlot` accepts `null` (the
  string `'unbounded'`, `SessionGrid::UNBOUNDED`, as `capacity_per_slot` in
  plain data), after which only participants limit what shares a slot: the
  outcome never reports the grid as too small, and any number of events may
  be pinned at one position. `getCapacityLimit()` returns the capacity or
  null and `hasUnboundedCapacity()` says which; `getCapacityPerSlot()`
  throws an `UnavailableValueException` on such a grid, because it has no
  integer to return. The default is still 1, and a missing or null
  `capacity_per_slot` in plain data still means 1. Null is the way to say
  "no limit"; a very large integer is not.
- Repack: a typed accessor on `RepackOutcome` for each kind of violation, so
  that the getters of a kind can be read without `instanceof`:
  `getParticipantDoubleBookedViolations()`, `getEventUnplacedViolations()`,
  `getContiguityBrokenViolations()`, `getLateStartViolations()` and
  `getCapacityExceededViolations()`. Each returns objects of its own class:
  a violation of a class from outside the library, in an outcome built by
  hand, is returned by `getViolationsOfKind()` and by none of them.
- Repack: `RepackOutcome::isBudgetExhausted()` says whether the step budget
  stopped a search. False means a larger budget gives the same outcome; true
  means it may give a different one. The flag is the optional fourth
  constructor parameter of `RepackOutcome` and is not part of `toArray()`.
- Repack: `RepackOutcome::fingerprint()` returns a stable identifier of the
  outcome's assignments, unplaced events and violations, for detecting that
  a plan computed again differs from the plan that was shown. It is `v1:`
  and the SHA-256 of a canonical encoding that the usage guide and the
  method's docblock specify as a contract; it does not depend on the order
  of the lists, the PHP version, the platform, the locale or an ini setting.
  The scheme names the keys of each record it covers, so a key added to a
  `toArray()` in a later release does not change a `v1` fingerprint. The
  budget flag is not part of it.
- The usage guide documents every public class and method of the Repack
  namespace, among them `getViolationsOfKind()`, `ViolationKind`,
  `UnplacedEvent` and the getters of the five violation classes, which were
  public and undocumented. A test
  (`tests/Feature/RepackDocumentationCoverageTest.php`) fails when a public
  type, method, constant, property or enum case of the namespace is missing
  from that reference, or the reference names one that does not exist.

### Changed

- The constructor of `Repack\SessionGrid` accepts more than it did, and
  everything it accepted before means what it meant: `$sessionStarts` may be
  a session count and `$slotInterval` null (the shape-only form, which
  `SessionGrid::shapeOnly()` builds and the only caller meant to pass it:
  call `shapeOnly()`, not the constructor, for a shape-only grid), and
  `$capacityPerSlot` may be null. The constructor of `Repack\SlotAssignment`
  accepts a null `$kickoff`. Static analysis of calling code sees two wider
  types: `SessionGrid::toArray()` may return the shape-only keys and a
  string capacity, and `SlotAssignment::toArray()` a null `kickoff`. Neither
  can occur for a grid built the way grids were built before. No existing
  method declares a new checked exception: see `UnavailableValueException`
  under "Added".
- `toJson()` and `fromJson()` of `Schedule`, `StageState` and
  `ScheduledSchedule` now throw `Exceptions\JsonConversionException` where PHP's
  `\JsonException` escaped unwrapped. It is a `\JsonException` with the same
  message and code, so `catch (\JsonException)` still matches; the PHP
  exception is available from `getPrevious()`, which returned null before.
  Code that compares the exception's class by name, not with `instanceof` or
  a catch clause, sees the new class.
- A participant pinned twice at one position of a `RepackRequest` is now
  reported with `Exceptions\PinConflictException`, a subclass of the
  `InvalidConfigurationException` thrown before. Code that compares the
  exception's class by name sees the new class.

### Fixed

- `InvalidConfigurationException::getDiagnosticReport()` no longer raises a
  PHP warning on PHP 8.5 when a context value is the float `NAN`. PHP 8.5
  warns when `NAN` is cast to a string, and the report cast it. The text is
  unchanged: `NAN`.
- Round-robin generation, the backtracking search and both elimination
  engines accept every participant id. For a field with two ids that are
  equal as numbers, or an id that contains `|` (see "Output change (fix)"
  above), they failed outright: `RoundRobinScheduler::schedule()` threw
  `IncompleteScheduleException` for the ids `01`, `1`, `2`, `3`, because its
  own integrity check rejected the complete schedule it had built; a
  two-legged elimination tie between two such ids never resolved; two ties
  of one round were rejected as `Two results reference the same elimination
  match`; and `StageState` rejected the result of an event it had just
  recorded when the result named the two participants in the other order.
  The backtracking search also found no schedule for an odd field that
  holds a participant with the id `"\0bye"`, which it used to mark the bye
  seat. All of these now produce the schedule or bracket that any other ids
  give.
- `ScheduleOptimizer::optimize()` no longer ends without a winner. A metric
  that measured `NAN` or `INF` left no candidate that scored below the
  starting best of `INF`, so the run ended in an `AssertionError`, or in
  `throw null` with assertions off. The scorer now refuses such a
  measurement (see "Output change (fix)"), and the optimizer takes its first
  candidate as the best so far whatever it scores.

## [0.2.1] - 2026-10-06

No library behavior changes: under `src/`, only the formatting and a number
of expressions rewritten to an equivalent form have changed since 0.2.0. No
public signature has changed, and generated output for a fixed input and seed
is identical. Upgrading from 0.2.0 needs no code change.

The installed package is smaller: tests, documentation, examples and tool
configuration are no longer installed into a consumer's `vendor/` directory.

### Added

- This changelog, the versioning and stability policy in the README, and the
  release checklist in `docs/RELEASING.md`, with tests
  (`tests/Feature/VersioningDocumentationTest.php`) that check them against
  the repository.
- Two examples: a double-elimination bracket with a grand-final reset
  (`examples/20-double-elimination.php`) and a standings table with a chain of
  tiebreakers (`examples/21-standings-and-tiebreakers.php`).
- Checked results for every example. Each script in `examples/` now computes
  a named set of results and hands it to one shared renderer
  (`examples/support/Example.php`), which shows it as text on the command line
  and as a page under a web server. The suite asserts on those results what
  each example is there to demonstrate, pins them as text in
  `tests/Fixtures/golden/examples/`, and fails for an example that has no
  checked results. A change to the pages' markup touches no fixture.
- `homepage` and `support` links in `composer.json`.
- Governance files: a security policy (`SECURITY.md`), code owners, a pull
  request template with a compatibility section, and issue forms for bug
  reports and feature requests.
- Golden-output tests (`tests/Feature/GoldenOutputTest.php`) that pin generated
  schedules, bracket pairings, repack assignments, and the JSON wire shapes
  against text fixtures in `tests/Fixtures/golden/`, captured from 0.2.0. The
  development-only `composer golden-update` script regenerates them.
- A test (`tests/Feature/DocumentationSnippetsTest.php`) that executes every
  `php` code block of `README.md` and `docs/USAGE.md`, each in a PHP process of
  its own under `E_ALL`. A block that does not parse, throws, or emits a
  warning or deprecation fails the suite, as does one that stops before its
  last line. A block that never returns is stopped by a time limit and fails
  by name instead of hanging the suite. The values and printed output the two
  documents state are pinned in the same test.
- A test that fails when a script in `examples/` is missing from
  `examples/README.md` or `examples/index.php`, or when either lists a script
  that does not exist.
- A `composer security-audit` script, which audits the dependencies in
  `composer.lock`. CI runs it on every pull request and push, in a
  `Dependency audit` job of its own that is not a required check. It fails on
  a security advisory and reports an abandoned package without failing. It is
  not part of `composer ci`, which needs no network.
- A weekly scheduled workflow that runs the gate against freshly resolved
  dependencies on PHP 8.3, 8.4 and 8.5, and the suite against the next PHP
  version (allowed to fail). It does not run on pull requests.
- A `codecov.yml` with a patch-coverage target and a project threshold.
- Optional, tracked settings in `.claude/` for contributors who use an AI
  coding agent: a hook that formats each PHP file the agent edits and analyses
  it when it is under `src/` or `tests/` (`tests/Feature/AgentHookTest.php`
  covers it), and `verify` and `release` commands. The directory is not part
  of the installed package.

### Changed

- The dist archive, which is what Composer installs, carries only the
  library: `src/`, `composer.json`, `LICENSE`, `README.md` and `CHANGELOG.md`.
  A test (`tests/Feature/DistArchiveTest.php`) guards the archive's contents.
  The repository also gains an `.editorconfig`, and its `.gitignore` patterns
  are anchored to the root.
- `README.md` has one feature list instead of two. It now covers everything
  that has shipped, including schedule repacking, timeline assignment,
  schedule quality and backtracking generation, and each entry links to its
  section of the usage guide.
- Every code block in `README.md` and `docs/USAGE.md` now runs as written:
  imports and the values a block depends on are shown, and inline value
  comments match what the code produces.
- Every example runs both on the command line and in a browser; there are no
  longer separate browser and command-line examples. The example pages no
  longer load a script from another host. `examples/README.md` and
  `examples/index.php` list all 21 examples.
- Documentation and design notes describe consuming applications generically.
  A `Restricted terms` CI job checks tracked files and paths against a list
  the maintainers keep as a repository secret.
- `AGENTS.md` is now the single guide for contributors and AI coding agents,
  corrected against the code. `docs/ROADMAP.md` marks every phase as shipped,
  lists schedule repacking, and gains sections for known limitations and
  deferred work.
- `docs/CONTRIBUTING.md` describes the current checks and rules, and states
  one branch and commit convention.
- Every PHP file now declares `strict_types=1`. Six test files and the
  PHP-CS-Fixer configuration did not; a test now checks all of them.
- The static analysis gates now check something. The reformat and the
  equivalent rewrites under `src/` come from them.
  - PHPStan analyses `src/` at level 9 (the tests stay at level 8), with
    `phpstan-strict-rules` and `phpstan-deprecation-rules`. What the strict
    rules found in existing code and was not fixed is recorded in two baseline
    files under `phpstan/`.
  - Rector enabled no rule set, so it checked nothing. It now applies the PHP
    sets up to 8.3, the dead code set and the early return set. Rules that
    would change a public signature are skipped.
  - The code style is PER Coding Style (`@PER-CS`) and the PHP 8.3 migration
    set, in place of PSR-12 and a hand-written rule list.
  - `phpunit.xml` uses the schema of the installed PHPUnit. A test run fails
    on a warning, a notice, a deprecation or a risky test, and tests run in
    random order.
- `composer test-coverage` sets `XDEBUG_MODE=coverage` itself, and
  `composer examples` runs through a PHP script instead of a POSIX shell loop,
  so both work without a prepared environment. `composer examples` now also
  fails an example that emits a warning, a notice or a deprecation, and prints
  the output of the example that failed.
- The development tools locked in `composer.lock` are at newer minor and patch
  releases. The lock file is not part of the installed package.
- The repack scenario test fixture is now a synthetic instance.
- The CI workflow runs the test and coverage jobs for every change set. Its
  documentation-only fast path is removed: the paths filter behind it matched
  every file, so it never skipped anything, and the suite now executes
  documentation, so a documentation-only change must be tested.
- The CI workflow cancels a superseded run for a pull request (never a run on
  `main`), loads a coverage driver in the coverage job only, requests only the
  PHP extensions the tools need, caches Composer's downloads instead of
  `vendor/`, and can be started by hand.
- The CI workflow runs with least-privilege permissions and pinned actions, and
  Dependabot keeps the actions and the development dependencies up to date.
- The workflows use the current major versions of the checkout, cache and
  coverage-upload actions.

### Removed

- Three development requirements: `fakerphp/faker`, which nothing used, and
  the direct requirements on `nunomaduro/collision` and `phpunit/phpunit`,
  which Pest already requires and still installs. The library has no
  production dependencies, so consumers are not affected.
- The editor-specific agent rule files and the session-notes directory at the
  repository root. Their decisions that still hold are now in `AGENTS.md`,
  `docs/ROADMAP.md`, and two decision records in the new `docs/adr/`; the
  statements that no longer matched the code are gone. None of these files
  was part of the installed package.

### Fixed

- Documentation only; the library is unchanged.
  - The custom-constraint sample in `docs/USAGE.md` was a parse error (an arrow
    function with a block body).
  - The "Corporate Team Building Tournament" sample threw
    `IncompleteScheduleException`: its constraint forbade pairings a round
    robin requires. It is now a Swiss schedule, which the rule fits.
  - The "Gaming Tournament with Skill Brackets" sample passed string skill
    names to `MetadataConstraint::requireAdjacentValues()`, which ignores
    non-numeric values, so the rule it described was never applied. It now uses
    numeric tiers on a Swiss schedule, with the entrants in an order in which
    the rule changes the pairings.
  - The rest-period sample described `MinimumRestPeriodsConstraint` as rest
    between a participant's matches. The constraint spaces repeat meetings of
    the same pair, and the sample now has two legs so that it applies.
  - `README.md`, `docs/USAGE.md`, `docs/ARCHITECTURE.md` and
    `examples/README.md` claimed that iterating a schedule is lazy or
    memory-efficient. A `Schedule` holds all of its events in memory; it is
    iterable and countable.
  - `README.md` called the library "production ready" and its round-robin
    roles "balanced". The library is at 0.x with experimental namespaces, and
    the round-robin generator bounds the home/away split without making it
    equal; the README, the roadmap and the glossary now say so.
  - Examples that showed something the code does not do:
    - `examples/06-rest-periods.php` presented `MinimumRestPeriodsConstraint`
      as rest between a participant's matches, on a single-leg schedule where
      the constraint never applies. It now uses two legs and shows the gap
      between the two meetings of each pair.
    - `examples/08-custom-constraints.php` described hard constraints as
      preferences ("soft constraint", "prefer"), and its first rule covered
      rounds in which the pairing never fell. The rules it shows now change
      the schedule or fail, and the text says which.
    - `examples/10-complex-tournament.php` used `rand()` inside constraints to
      imitate a preference, so its result was not repeatable. Its constraints
      are now deterministic and each one is shown to hold.
    - `examples/09-multi-leg-home-away.php` shuffled legs with an unseeded
      randomizer and drew a league table from random scores; it now passes a
      seeded randomizer, and standings have their own example (21).
    - `examples/12-performance-patterns.php` built its sample data with
      `rand()` and read a metadata key that does not exist. It now reports
      exact counts and marks its timings as measured; the memory figures are
      gone.
    - `examples/04-basic-constraints.php` called `noRepeatPairings()` the
      constraint that makes each pair meet once; a round robin does that
      without it, and the example now says so.
- Test suite only; the library and the examples are unchanged. The test that
  serves every example as a page from PHP's built-in web server failed now and
  then on PHP 8.3 where the configuration switches the tracing JIT on (PHP
  leaves it off by default): the JIT of that version crashes the server
  process after a number of pages. The server of that test now runs without
  the JIT, and a request that fails reports its address, the status line, how
  the server ended and the end of the server log. `examples/README.md` says
  how to serve the examples on such a configuration. Where that server cannot
  be started, the test now fails on CI instead of being skipped (elsewhere it
  is still skipped, with the reason), and a server that finds its port taken
  is started again on another one, three times at most.
- CI only. A coverage upload that cannot authenticate (a pull request from a
  fork or from Dependabot) no longer fails the `Coverage` check.

## [0.2.0] - 2026-08-11

Adds schedule repacking: the library can now repair an existing schedule as
well as generate one. The release is additive; no existing API changed.

### Added

- **Schedule repacking** (`MissionGaming\Tactician\Repack`). Given movable
  events (an opaque id plus two participants), optional pinned events that may
  not move, and a declarative session grid, `ScheduleRepacker` assigns every
  movable event a `(session, slot)` position so that nobody is double-booked
  and each participant's events within a session run back to back where
  possible.
- `SessionGrid`: explicit ordered zoned session starts, a slot interval,
  per-session slot counts, and per-slot concurrency. Irregular by design,
  unlike the cadence of `TimelineDefinition`.
- `RepackOutcome`, which carries itemized violations (unplaced events, interior
  gaps, late starts, exceeded capacity) as structured data instead of throwing.
  This is the one sanctioned exception to the library's loud-failure rule.
  `RepackOptions(throwOnViolations: true)` opts back into throwing through the
  new `RepackViolationsException`.
- Seeded, step-bounded solving configured through `RepackOptions`. Kickoffs
  serialize in the timeline family's UTC format.
- Repack documentation in `docs/USAGE.md` (with glossary entries),
  `docs/ARCHITECTURE.md`, and the design log `docs/design/schedule-repack.md`,
  plus the runnable example `examples/19-repacking-a-season.php`.

The repacker shipped with the following behavior, settled in review before the
release:

- `no_slot_available` is literal: events the planner rejects re-enter the final
  free-position sweep before being reported unplaceable.
- The parity bitmask machinery is capped at 20 slots per session; wider
  sessions fall back to the greedy path instead of an exponential search.
- Violations are emitted in the documented kind-then-scope order, and
  `CapacityExceeded` has no session-scoped shape.
- The repack usage snippet, example, and tests use the PHP 8.3-compatible
  `(new ScheduleRepacker())->` form. The PHP 8.4-only `new`-chaining form was
  replaced before the release and never shipped.

## [0.1.1] - 2026-07-04

### Fixed

- The author email in `composer.json` now uses the `missiongaming.gg` domain.
  No code or API changes.

## [0.1.0] - 2026-07-04

First published release: a dependency-free tournament scheduling library for
PHP 8.3+.

### Added

- **Round robin**: single and multi-leg schedules (mirrored, repeated, and
  shuffled leg strategies), with bounded rotation retries and optional
  deterministic backtracking over round decompositions.
- **Swiss pairing**: the results-driven `SwissPairingEngine`, with a
  `SwissScheduler` preset.
- **Elimination**: single and double elimination engines configured through
  `EliminationOptions`.
- **Group stages**: pool distribution, progression selectors, and composition
  validation.
- **Standings**: `StandingsCalculator` with a configurable `PointsSystem` and
  pluggable tiebreakers.
- **Constraints**: the `ConstraintSet` builder; constraints are hard filters
  that fail loudly with diagnostics instead of silently dropping matches.
- **Timeline**: deterministic UTC kickoff assignment that decorates events
  into scheduled schedules.
- **Schedule quality**: `ScheduleScorer`, which composes weighted quality
  metrics (role balance, role streaks, rest spread, pairing spacing), and
  `ScheduleOptimizer`, which keeps the best of N seeded candidate schedules.
- **Immutable DTOs**: readonly value objects with `toArray()`/`fromArray()`;
  `Schedule` round-trips JSON.

[Unreleased]: https://github.com/mission-gaming/tactician/compare/v0.2.1...HEAD
[0.2.1]: https://github.com/mission-gaming/tactician/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/mission-gaming/tactician/compare/v0.1.1...v0.2.0
[0.1.1]: https://github.com/mission-gaming/tactician/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/mission-gaming/tactician/releases/tag/v0.1.0
