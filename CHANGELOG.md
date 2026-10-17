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

The entries of this section before the one on slot times come from one
decision (#55, and item 17 of
#121): **wherever an entrant list exists and results do not decide an
order, the position in that list decides it; a label, a seed attribute or
an ID never does** (ADR 0004,
`docs/adr/0004-position-decides-where-results-do-not.md`, which replaces
ADR 0002). Each entry says who is affected and what to check.
What a state stored by 0.2.x does under them is under **Changed**, in the
entry on the engine fingerprint.

- **The table of an elimination outcome is the bracket placement.** The
  standings of `SingleEliminationEngine::getOutcome()` and
  `DoubleEliminationEngine::getOutcome()` were a table of wins and losses,
  and rank 1 of it was not the entrant who won the bracket in three cases:
  in double elimination, where the longer route through the losers bracket
  collects more wins (770 of the 1,920 simulated brackets the issue
  counts); after a bye, which is no win; and after a two-legged final
  settled by a tie decision. Now the bracket orders the
  table first: the entrant who advanced from the last tie is rank 1, the
  one it beat there rank 2, and everyone else follows by the round that put
  them out, the latest first. Entrants who went out in the same round share
  a tier (`Standings::getTiers()`, new); the standings calculator orders
  them from their records, and where it leaves them level, their position
  in the entrant list does. The records (played, wins, draws, losses,
  ranking value, scores) are unchanged. Who is affected: code that reads
  the order of an elimination outcome's standings, `getPosition()` on it or
  a `RankRangeSelector` over it. What to check: a place read from that
  table. Before and after, eight entrants in double elimination, where p8
  loses round 1, wins the losers bracket and the first grand final, and
  loses the reset to p1:

  ```text
  before  1. p8 (5-2)   2. p1 (4-1)
  after   1. p1 (4-1)   2. p8 (5-2)
  ```

  `MatchOutcomeSelector::winners()` returned the right entrant before and
  still does. Golden fixture: `double-elimination.txt` (the `standings:`
  line of the 12-entrant bracket and of the bracket with a reset; no
  pairing in the file changed).
- **A re-seeded bracket ranks an entrant who had a bye with the winners of
  the round, and level survivors by list position.**
  `EliminationOptions(reseedEachRound: true)` ranked the survivors by a
  table in which a bye earned nothing, so the entrants at the top of the
  list, who have the byes, were folded as the bottom of the next round, and
  it ordered level survivors by seed attribute, label and ID. A bye now
  counts in that ranking as the tie it stands for, won in every leg, and
  level survivors are in the order of the entrant list. Who is affected:
  every re-seeded bracket whose field is not a power of two, and every
  re-seeded bracket whose entrant list is not in the order of its seed
  attributes and labels. What to check: the pairings of every round after
  the first. Before and after, five entrants p1 to p5, where p4 beats p5 in
  round 1:

  ```text
  before  semifinals: p4 v p3, p1 v p2
  after   semifinals: p1 v p4, p2 v p3
  ```

  Golden fixture: `single-elimination.txt` (the re-seeded 12-entrant
  bracket: rounds 2 to 4 and the `standings:` line; the fixed-path brackets
  in the file changed in nothing).
- **A Swiss round is paired in list order where results do not decide, and
  the first bye goes to the last position.** `SwissPairingEngine` took the
  order of level participants from the standings fallback: seed attribute,
  then label, then ID. An unseeded list entered as Zed, Amy, Bob, Cat was
  paired Amy with Bob and Cat with Zed. It is now paired Zed with Amy and
  Bob with Cat, whatever the participants are called, and with a
  `Randomizer` the shuffle of each score group starts from list order. Who
  is affected: a Swiss stage, and a `SwissScheduler` schedule, whose
  participant list is not already in the order of its seed attributes (or,
  unseeded, of its labels). What to check: the pairings of such a stage
  from its next round on; put the list in the order you mean. Before and
  after, the list above, first round:

  ```text
  before  Bob v Amy, Zed v Cat
  after   Amy v Zed, Cat v Bob
  ```

  No golden fixture changed: the fixtures and the examples list their
  participants in the order of their seeds and labels. A participant who
  has withdrawn has no position in the list any more: in the table of the
  stage it stands after the active participants it is level with, and
  withdrawn participants who are level with each other are in the order of
  their IDs, whatever order the recorded events name them in.
- **A Swiss bye counts in the outcome's table as it counts for pairing.**
  The engine credited a bye as a win when it ordered a round and as nothing
  in the table of `getOutcome()`, so a participant who sat out a round
  finished below participants it had been paired above. The outcome's table
  is now the table the rounds were paired from: the ranking value of an
  entry includes one win for each bye (`StandingEntry::getByes()`, new,
  says how many), while played, wins, draws and losses still count results
  only. Among participants level on that value, one whose points include a
  bye is no longer placed below the others: the tiebreakers, the scores and
  then the list position order them. Who is affected: a Swiss stage in a
  field of odd size. What to check: the final table, and the pairings of a
  stage in progress. Before and after, a participant with one win and one
  bye after two rounds on the 3/1/0 scale:

  ```text
  before  played 1, 1 win, 3 points
  after   played 1, 1 win, 1 bye, 6 points
  ```

  A tiebreaker that reads opponents' ranking values (Buchholz,
  Sonneborn–Berger) still counts an opponent's bye as nothing unless it is
  given a bye treatment, and a bye is worth a win unless the ranking is
  given another value for it (both under **Added**).
- **A standings table calculated on its own is the same for every order of
  its participants.** The last fallback of `StandingsCalculator` was seed,
  then label in natural order, then ID compared as PHP compares two
  strings. That comparison reads numeric strings as numbers: `'01'` and
  `'1'` were not ordered and kept their input order, and for IDs that mix
  the two kinds it is not transitive (`'2'` is below `'10'`, `'10'` below
  `'1a'`, `'1a'` below `'2'`), so `['2', '10', '1a']` came out `2, 10, 1a`
  and `['10', '2', '1a']` came out `1a, 2, 10`. The fallback is now seed,
  then ID, in an order that is total for every set of strings: an ID
  written as a whole number without a sign or a leading zero first, in the
  order of the values, and every other ID after those, byte by byte. The
  label is not compared. Who is affected: tables with entries that results
  leave level, where those entries have no seed or share one. What to
  check: the order inside a tied set (`getTiedSets()` reports them); to
  keep an order of your own, put the entrants in it and call
  `withTiedSetsOrderedBy()` (below). Before and after, two unseeded
  participants level on everything:

  ```text
  ids b ("Amy") and a ("Zed")     before  b, a   after  a, b
  ids 01 and 1, given as 01, 1    before  01, 1  after  1, 01
  ids p2 and p10                  before  p2, p10 (labels "Player 2", "Player 10")
                                  after   p10, p2
  ```

  Plain decimal IDs keep their order: `9` is still before `10`.
- **Constraints find a participant by ID.** `SeedProtectionConstraint` and
  the two `ConsecutiveRoleConstraint` factories (`homeAway()`,
  `position()`) looked for the `Participant` object itself in an event, so
  a participant rebuilt from stored data was not recognised and an event
  they should reject was let through. They now compare IDs, as everything
  else does. Who is affected: code that puts events and participants
  together by hand from more than one object per ID; the schedulers and
  engines pass the objects they were given and were not affected. What to
  check: nothing in a generated schedule changes. Before and after, top
  seeds A and B in a protected round, the event built with a copy of A:
  accepted before, rejected now.
- **A per-group selection of three or more pools no longer pairs a pool
  with itself.** `RankRangeSelector::topPerGroup(2)` listed every rank in
  pool order (A1, B1, C1, A2, B2, C2), which a bracket folds into C1
  against C2 with three pools, and into one half for a pool's winner and
  runner-up with four or eight. Each rank after the first is now listed in
  the order that keeps the entrants of one pool apart in a bracket folded
  from the list: as few pairs of one pool as possible that can meet in the
  first round, then in the second, and so on. For the top two of every
  pool, a pool's winner and runner-up are in opposite halves for any number
  of pools. Who is affected: per-group selections of two or more ranks from
  three or more pools. What to check: the order of the selected entrants,
  and anything that reads it by index. A selection from one or two pools
  is unchanged (A1, B1, A2, B2, and so on in pool order for every further
  rank), and so is a selection of a single rank from any number of pools.
  Before and after:

  ```text
  3 pools  before  A1 B1 C1 A2 B2 C2   round 1: A2 v B2, C1 v C2
           after   A1 B1 C1 C2 B2 A2   round 1: C2 v B2, C1 v A2
  4 pools  before  A1 B1 C1 D1 A2 B2 C2 D2
           after   A1 B1 C1 D1 B2 A2 D2 C2
  ```
- **`SwissScheduler` generates every round count it accepts.** It accepts
  up to the number of participants minus one, and failed with an
  `IncompleteScheduleException` for roughly one seed in ten to one in four
  at the larger counts (the first draw of 30 of 200 seeds at 6 participants
  and 4 rounds, of 44 at 12 and 11), because a round is paired without a
  look at the rounds to come. A draw that reaches a round with no pairing is now drawn again from
  the same sequence, up to twenty draws, and after that, or with nothing to
  draw from, the rounds are built by the rotation that lays out a round
  robin. Without a source of draws there is one draw, and the rotation is
  what generates the schedule where that draw fails (26 participants from
  24 rounds on, 34 from 32 and 37 at 36, of the fields up to 44); its first
  round is the list as given, as in every schedule drawn without a source.
  Who is affected: calls that threw. A schedule that 0.2.x
  generated is generated again unchanged, apart from the list-order change
  above: the first draw is tried first and is the same draw. With
  constraints a schedule may not exist, and the exception is still thrown,
  now for the last draw. No golden fixture changed (`swiss.txt` holds
  3 rounds of 8 and 4 rounds of 9, which never needed a second draw).
- **Every slot time is computed from the start, not from the slot before
  it.** `TimelineDefinition::getSlotTime()` added the round interval once
  per round, each addition from the round before, so whatever one round was
  adjusted by stayed in every later round. Round N is now the start plus
  N−1 round intervals taken as one step, and a slot is its round's first
  slot plus its slot intervals taken as one step.
  `SessionGrid::getSlotTime()` and `positionOf()` compute a slot from its
  session's start the same way. Four things change:

  - *Months keep the day, or the month end.* An interval of months or years
    from the 29th, 30th or 31st keeps that day of the month, and a month too
    short for it gives its last day.

    | `P1M` from 31 January 2026, rounds 1 to 5 | |
    |---|---|
    | Before | 31 Jan, 3 Mar, 3 Apr, 3 May, 3 Jun |
    | After | 31 Jan, 28 Feb, 31 Mar, 30 Apr, 31 May |

  - *A local time the clocks skip moves one round.* A weekly 01:30 in
    `Europe/London` from 15 March 2026 was 01:30, 01:30, 02:30 (29 March has
    no 01:30), then 02:30 in every later round. It is now 01:30, 01:30,
    02:30, 01:30, 01:30. The slots of a session grid a day apart behave the
    same.
  - *An interval with months and days is multiplied out first.* Round N of
    `P1M7D` is the start plus N−1 months, plus 7 × (N−1) days. From 1
    January 2026 round 12 was 18 February 2027 and is now 16 February 2027;
    rounds 2 and 3 (8 February, 15 March) are as before. The same holds for
    an interval with a date part and a time part (`P1DT12H`) in a timezone
    that changes its clocks: the days are counted in local time and the
    hours as elapsed time, each once from the start.
  - *A local time that occurs twice is the first of the two.* For an
    interval of days, weeks, months or years, a round at a local time the
    clocks repeat (01:30 on the night they go back) is the earlier of the
    two instants, whatever season the start is in. Adding round by round
    gave the one PHP takes, which goes by the round before: the first
    where the timezone database marks that round as daylight-saving time,
    the second where it does not. In most timezones the round before is on
    summer time and marked so, and the first was taken as it is now. Two
    cases took the second and now take the first:

    - *The round before is on winter time*, which takes an interval long
      enough to step over the whole summer: `P8M` from 25 February 2026 at
      01:30 in `Europe/London` gave the second 01:30 (01:30 UTC) on 25
      October and now gives the first (00:30 UTC), and `P1Y` from 6
      November 2023 at 01:30 in `America/New_York` gave 06:30 UTC on 6
      November 2044 and now gives 05:30 UTC. An interval of days or weeks
      that long (`P34W`) is the same.
    - *The timezone database marks winter time, not summer time, as the
      daylight-saving one*, as it does today for `Europe/Dublin`,
      `Africa/Casablanca` and `Africa/El_Aaiun`. There an interval of any
      length took the second: a weekly 01:30 in `Europe/Dublin` from 18
      October 2026 was at 01:30 UTC on 25 October and is now at 00:30 UTC,
      as it is in `Europe/London`.

    An interval that also has hours, minutes or seconds takes the one PHP
    takes: the first where the timezone database marks the start as
    daylight-saving time, the second where it does not.

  Who is affected: a timeline whose round interval has months or years and
  whose start is on the 29th, 30th or 31st; a timeline or a grid with an
  interval of days, weeks or months whose local time of day falls in the
  hour a clock change skips; a timeline whose one interval mixes months
  with days, or days with hours in a timezone with clock changes; and a
  timeline or a grid with an interval of days or longer whose local time
  of day falls in the hour a clock change repeats, where the interval
  steps over the whole summer or the timezone is one of the three named
  above. What to
  check: kickoffs stored from such a timeline no longer equal what
  `getSlotTime()` and `TimelineAssigner` return, so assign again before
  comparing. For such a grid (slots a day or more apart, one of them in a
  skipped hour) the kickoffs of a repack's assignments move with the
  slots, and `RepackOutcome::fingerprint()`, which covers the kickoffs,
  differs from one stored before. Nothing else changes: an interval of days, weeks, hours or
  minutes at a time of day that exists on every date, and an interval of
  months from the 1st to the 28th, give the kickoffs they gave, and no
  golden fixture changed. An interval made by
  `DateInterval::createFromDateString()` has no multiple and is still added
  one step at a time, `createFromDateString('1 month')` included: PHP does
  not show the parts of such an interval, so from 31 January it still
  gives 3 March, 3 April. Write `new DateInterval('P1M')` for month ends.
- **`PairingSpacingMetric` no longer assumes that rounds start at 1.** It
  took the highest round number for the length of the schedule, so the same
  schedule numbered from a later round measured worse: a mirrored two-leg
  round robin of four in rounds 1 to 6 measured 0.0, and in rounds 11 to 16
  it measured 5.0. The length is now the rounds the schedule spans, highest
  minus lowest plus one, and both measure 0.0. Who is affected: anyone
  scoring a schedule whose lowest round number is above 1 (a later leg or
  stage scored by itself), and `ScheduleScorer`/`ScheduleOptimizer` totals
  that include this metric for one. A schedule whose first round is 1
  measures as before.

- **Mirrored legs alternate: `MirroredLegStrategy` reverses the roles in the
  even-numbered legs only.** It reversed them in every leg after the first,
  so a pair that met three times was split 1:2 and a pair that met four
  times 1:3, and the imbalance grew with every leg. The odd-numbered legs
  now have the roles of the first leg and the even-numbered legs have them
  reversed. A pair's meetings are split as evenly as their number allows
  (level over an even number of legs, one apart over an odd number), an even
  number of legs ends with every participant first-named as often as
  second-named, and an odd number ends as a single leg does.

  **Who is affected.** A round robin of three or more legs under
  `MirroredLegStrategy`, which is the default leg strategy, so
  `new RoundRobinOptions(legs: 3)` and `['legs' => 3]` are included: with
  either role assignment, with or without constraints, a `Randomizer` or
  backtracking. Schedules of one or two legs are byte for byte what they
  were. So is every schedule under `RepeatedLegStrategy` or
  `ShuffledLegStrategy`, and every two-legged elimination tie.

  **What changes.** In the third leg, and in every odd-numbered leg after
  it, each event has its two participants in the other order. Which pair
  meets in which round does not change, and neither do the byes, the round
  numbers, the metadata or the number of events. Before and after for four
  participants `1` to `4` over three legs, with the default options (rounds
  1 to 6, the first two legs, are the same in both):

  ```
            before        after
  R7:       4-1 3-2       1-4 2-3
  R8:       1-2 3-4       2-1 4-3
  R9:       3-1 2-4       1-3 4-2
  ```

  Before, 1 met 4 as `1-4`, `4-1`, `4-1`; after, as `1-4`, `4-1`, `1-4`.
  The same field over four legs had one participant first-named in nine
  events in a row and ended 6 apart; it now repeats a role three times at
  most and ends level.

  **What to check (migration).**
  - A schedule of three or more mirrored legs that was generated before and
    is stored: it is not regenerated identically. Keep the stored events if
    they are published; regenerate if the even split is wanted. Anything
    that compares a fresh schedule with a stored one, or with its hash,
    for such options will see a difference.
  - Code that read "leg 2 and later" as "roles reversed": the rule is now
    "even-numbered legs".
  - A `RoleBalanceConstraint` or `ConsecutiveRoleConstraint` on such a
    schedule sees the new roles. What that does depends on the scheduler:
    - **Without a `Randomizer`**, a limit that two mirrored legs satisfy is
      now satisfied by any number of them, so a configuration that failed
      with an `IncompleteScheduleException` at four mirrored legs (a role
      balance limit of 3 in a field of even size, for example) can now
      succeed. None is known that succeeded and now fails: the tests compare
      the old roles with the new for both constraints at every limit from 1
      to 5, in fields of 2 to 8, over three and four legs and with either
      role assignment.
    - **With a `Randomizer`**, the later legs change for another reason
      as well, which the next entry describes: they now derive from the
      first leg. A role limit is then answered as it is without a
      `Randomizer`, which can refuse a configuration that 0.2.x completed.
      Generate such a configuration again before relying on it: it fails
      loudly if the limit no longer holds.
  - To keep the old roles, pass a leg strategy of your own that reverses
    every leg after the first. `LegStrategyInterface` has two methods:
    `generateEventForLeg()` returns the pairing as given for leg 1 and
    reversed for every other leg, and `planLegs()` states what
    `MirroredLegStrategy` states. The tests hold such a strategy to the
    "before" column above.
  - A leg strategy of your own that states `rolesMirrorAcrossLegs: true` is
    not affected. The flag is documented as "reverses the roles in the
    even-numbered legs" from this release; the library carries it and acts
    on nothing in it.

  With a scheduler that holds a `Randomizer` the alternation is the same
  from this release: every later leg derives from the first leg (the next
  entry), so leg 3 has the roles of leg 1 there too.

  Six golden fixtures change, in their sections for three and four legs and
  in the rounds of the third leg only (their sections for one and two legs
  are unchanged by this entry; the four of a scheduler with a `Randomizer`
  change in every leg after the first by the next one):
  `round-robin/mirrored-unseeded.txt`,
  `round-robin/mirrored-seed-1.txt`, `round-robin/mirrored-seed-42.txt`,
  `round-robin/mirrored-seed-1337.txt`,
  `round-robin/balanced-mirrored-unseeded.txt` and
  `round-robin/balanced-mirrored-seed-42.txt`.

  The documents that described the old behaviour as the contract are
  corrected with it: the docblocks of `MirroredLegStrategy`,
  `LegPlanContribution`, `RoundRobinPlan` and `RoleBalanceConstraint`, the
  tables under "Role-Based Constraints" and "Legs after the first" in the
  usage guide, the architecture document, the roadmap's limitations and the
  role assignment design note.
- **A round-robin scheduler with a `Randomizer` derives every later leg
  from its first leg.** `new RoundRobinScheduler($constraints, $randomizer)`
  shuffled the participant order for the first leg only and laid every
  later leg out from the order as given. The later legs were therefore a
  round robin of their own: a pairing fell in another round of its leg, a
  mirrored leg did not reverse the first, and a repeated leg did not repeat
  it. Measured over two mirrored legs of 4 to 14 participants and five
  seeds each, 49.8% of the pairings had the same participant first-named in
  both legs, 20 of the 55 schedules had a pair that met in the last round
  of one leg and the first round of the next, and over four legs 54 of 55
  had a pairing split 3:1 or 4:0. Now the first leg is laid out from the
  shuffled order and every later leg has its pairings in the same rounds of
  the leg, in the roles the leg strategy derives from the first leg's, as a
  scheduler without a `Randomizer` always did, and as a backtracking search
  did with one (#51). The three figures are 0%, none and none.

  **Who is affected.** Every round robin of two or more legs from a
  scheduler that holds a `Randomizer`: every field size from 2, every leg
  count from 2, each of the three leg strategies, either role assignment,
  with or without constraints, and whatever the seed. A `ScheduleOptimizer`
  whose generator builds such a scheduler is included. Not affected, byte
  for byte: a schedule of one leg, with or without a `Randomizer`; every
  schedule from a scheduler without one, whatever its leg strategy
  (a `ShuffledLegStrategy` with a seed or a randomizer of its own
  included); and a schedule that only a backtracking search completes.

  **What changes.** Without constraints the first leg is the leg the same
  seed always gave (under constraints it can be another: a later leg that
  is now rejected sends the scheduler on to its next shuffle).
  From the second leg on, each event is the first-leg event of its pairing
  one leg later: reversed in the even-numbered legs of
  `MirroredLegStrategy`, as it is under `RepeatedLegStrategy`, and with a
  drawn role under `ShuffledLegStrategy` (the draws are the same, one for
  each event in order; the pairings they apply to are the first leg's). The
  byes of a field of odd size follow: a participant sits out the same round
  of every leg. A field of two changes too, where the seed shuffles the
  order: its one pairing was not reversed in the second mirrored leg. The
  number of events, the round numbers and the metadata do not change.
  Before and after for four participants `1` to `4`, two mirrored legs,
  `Mt19937` seed 1 and round-parity roles (rounds 1 to 3, the first leg,
  are the same in both):

  ```
            before        after
  R1:       4-2 1-3       4-2 1-3
  R2:       1-4 2-3       1-4 2-3
  R3:       4-3 2-1       4-3 2-1
  R4:       4-1 3-2       2-4 3-1
  R5:       1-2 3-4       4-1 3-2
  R6:       3-1 2-4       3-4 1-2
  ```

  Before, 1 met 4 in rounds 2 and 4 and 2 met 3 in the same two; after,
  every pair meets again three rounds on, with the roles reversed.

  **What to check (migration).**
  - A stored schedule of two or more legs that came from a scheduler with
    a `Randomizer` is not regenerated identically from the same seed. Keep
    the stored events if they are published. Anything that compares a
    fresh schedule with a stored one, or with its hash, sees a difference
    from the first round of the second leg on.
  - Role constraints (`ConsecutiveRoleConstraint`, `RoleBalanceConstraint`)
    over several legs are now answered as they are without a `Randomizer`,
    whatever the seed, because the schedule is the schedule of the list as
    given under other names. A limit that only held because the legs were
    unrelated is refused: five participants over two mirrored legs under
    `ConsecutiveRoleConstraint::homeAway(3)` with round-parity roles
    completed in 0.2.x for the seeds 1 to 5 and now throw an
    `IncompleteScheduleException`, as they always did without a
    `Randomizer` (the two legs meet in a run of four). A limit that the
    unrelated legs broke by chance now holds: a role balance limit of 3 (4
    in a field of odd size) over any number of mirrored legs, for one.
  - `MinimumRestPeriodsConstraint` sees a pair's meetings exactly one leg
    apart, as it does without a `Randomizer`: a minimum up to the number of
    rounds in a leg always holds, and a larger one never does.
  - There is no option that gives the old later legs back: they were not a
    choice. For a schedule without constraints they can be put together
    from two calls, which is what 0.2.x did: the first leg of the scheduler
    with the `Randomizer` (`legs: 1`), followed by the legs after the first
    of a scheduler without one for the same options. That was compared
    with 0.2.x for 2 to 14 participants, two to four legs, the three leg
    strategies, both role assignments and five seeds, and held for all
    1,755 schedules. Under constraints the old schedule also depended on
    which rotated orders were rejected, and nothing reproduces it.

  Eight golden fixtures change, in their sections of two legs or more and
  in the rounds after the first leg only (each first leg, and each section
  of one leg, is unchanged; a section of two participants changes only
  where the seed exchanges the two, which of the three seeds 42 does): `round-robin/mirrored-seed-1.txt`,
  `round-robin/mirrored-seed-42.txt`, `round-robin/mirrored-seed-1337.txt`,
  `round-robin/repeated-seed-1.txt`, `round-robin/repeated-seed-42.txt`,
  `round-robin/repeated-seed-1337.txt`,
  `round-robin/balanced-mirrored-seed-42.txt` (which the entry on the
  default under **Changed** then replaces), and
  `examples/17-schedule-optimization.txt`, whose 25 candidates each come
  from a scheduler with a `Randomizer` over two legs: the schedule it keeps
  and its scores change, and its spacing metric reads 0 for every
  candidate, because the two meetings of a pair are now always a leg
  apart.

  The documents that described the old behaviour are corrected with it: the
  docblocks of `RoundRobinScheduler`, `MirroredLegStrategy`,
  `RepeatedLegStrategy`, `RoleBalanceConstraint` and
  `MinimumRestPeriodsConstraint`, the sections "Role-Based Constraints" and
  "Legs after the first" of the usage guide, the architecture document, the
  roadmap's limitations and the role assignment design note.
- **`SlotAssignment::toArray()` writes a kickoff that is not in UTC as the
  instant it is.** The kickoff was formatted as it was held and a `Z` was
  put after it, so an assignment built by hand with 20:15 in London was
  written `...T20:15:00Z`, an hour away from the instant it holds, and
  `RepackOutcome::fingerprint()` covered that string. The kickoff is now
  converted to UTC before it is written (`...T19:15:00Z` in August), and
  two outcomes that hold one instant in two timezones have one
  fingerprint. `getKickoff()` still returns the object it was given. The
  repacker always builds its assignments in UTC, so nothing it returns,
  and no fingerprint of an outcome it returned, changes; only an
  assignment constructed by hand with a kickoff in another timezone is
  written differently (#121, item 9).
- **`TimelineDefinition::toArray()` writes the fraction of a second of a
  start that has one, and `formatInterval()` shows the sign, the fraction
  and the relative date string of an interval.** A start of
  `19:00:00.25` was written `19:00:00`, so the definition read back a
  quarter of a second earlier; it is written `2026-08-01 19:00:00.250000`,
  which `fromArray()` reads. A start on a whole second is written as
  before. `TimelineDefinition::formatInterval()`, which errors use to
  describe an interval, wrote an inverted `P7D` as `P7D`, an interval of a
  second and a half as `PT1S`, and one made from `next weekday` as `PT0S`.
  It now writes `-P7D`, `PT1.5S` and `next weekday`: forms that
  `parseInterval()` refuses, where the old ones read back as another
  interval. An interval built from an ISO 8601 duration is written as
  before. The name of a `MinimumRestRule` and its violation messages use
  the same method and change with it for such a rest; its `toArray()`
  refuses such a rest (see Changed). `SessionGrid::toArray()` and
  `BlackoutRule::toArray()` write the fraction of a second of a session
  start and of a window bound the same way: a start of `20:00:00.25` was
  written `20:00:00`, and a blackout window inside one second was written
  as one that ends when it starts, which `fromArray()` refuses. A time on a
  whole second is written as before by all three (#121, item 6).
- **A role constraint no longer fails two or more shuffled legs, or (with
  backtracking) repeated and mirrored legs, although a schedule exists
  ([#117](https://github.com/mission-gaming/tactician/issues/117)).** The
  scheduler fixed the role of every later-leg event once and never
  reconsidered it, and the backtracking search stopped at the first leg it
  found. Eight participants, two legs and
  `ConsecutiveRoleConstraint::homeAway(2)` threw an
  `IncompleteScheduleException` for every seed of a shuffled strategy and
  for the repeated strategy, with and without `backtracking`. What a
  schedule now gets depends on what the leg strategy's contract leaves
  free:
  - **Shuffled legs: a draw is a preference.** Where the constraints reject
    an event as drawn, it is given the other role; where they reject both,
    the roles of earlier events of the later legs are changed until every
    event is accepted. This runs without `backtracking`, in every layout
    tried. Nothing is drawn again, so the same seed gives the same schedule.
  - **Repeated and mirrored legs: the roles are a rule**, and a later-leg
    event is never reversed. What can change is the first leg. Without
    `backtracking` its roles are the role assignment's, and under a role
    assignment that states no rule (`RoundParityRoleAssignment`) such a
    schedule still fails; under the two balanced role assignments, the
    default among them, they are searched within the balance (#51, under
    **Added**). With `backtracking: true` the search now crosses the
    boundary of the first leg: for every first leg it finds it searches the
    roles of all legs with the pairings and rounds held, and a first leg
    that no roles complete sends it on to the next one, where it used to
    fail with "the search does not cross leg boundaries". The roles of the
    circle layout are searched once as well.

  No output changes for an input that succeeded before: every golden
  fixture is unchanged, and a schedule whose roles the constraints accept
  as they were is the schedule it was (for shuffled legs, exactly the
  draw). Inputs that threw now return a schedule. Who is affected: code
  that relied on the exception for such a configuration, and code that
  reads the exception messages below. The bounds of the two searches, what
  was measured and what still fails within them are in
  `docs/design/backtracking-generation.md` ("Roles across legs") and
  `docs/ROADMAP.md`; the usage guide has the contract of each strategy
  ("Leg Strategies and Role Constraints").

- **A repack no longer depends on how its events and participants are
  named, and contiguity comes ahead of the consolidation weight**
  ([#49](https://github.com/mission-gaming/tactician/issues/49)). Almost
  every repack outcome changes: positions, which events are unplaced where
  some are, the violations, and with them `toArray()` and
  `RepackOutcome::fingerprint()`. No public signature changes, and the
  hard rules are as they were: no participant is double-booked, a pin
  never moves, no slot is filled past its capacity, infeasibility comes
  back as violations, and the same request gives the same outcome.

  *What was wrong.* The repacker ordered events by `strcmp()` on their
  ids and every tie-break followed, so the same events on the same grid
  were packed well under one naming and badly under another. And it chose
  each event's session by the two weights before it looked at any slot,
  so it reported gaps where a placement without one existed.

  *What it does now.* The participants are taken in an order made from
  the request (how many events each has, where it is pinned, whom it
  plays), and the events by their two participants; an id decides only
  between events of the same two participants and between participants
  the request does not tell apart. Before any session is chosen the
  events are split into rounds (sets that can share a slot) laid on the
  grid's positions around the pins, and the planner takes them round by
  round, so that the sessions it fills by the weights are ones that pack.
  The rounds are also a schedule of their own: they are returned where
  they place more events than the planned schedule, or as many with fewer
  gap slots. A schedule that still has a gap is then searched for a
  placement of as many events with fewer gap slots. Where the result
  places fewer events than a bound on what any placement can hold, or
  still has a gap, the events are planned and packed once more in the
  order of their participants, on the steps the two budgets have left,
  and that third schedule is returned when it places more events, or as
  many with fewer gap slots. The last placement step, which moves placed
  events to make room for an unplaced one, searches further than it did
  (below). One session's exact search may spend at most a quarter of
  the steps left, where one that did not end used to take them all from
  the sessions after it; the table that search starts from is paid for
  out of the same quarter once a session has eight slots or more.

  *What is and is not promised about ids.* For the same request under
  other ids, the number of events at every position, the unplaced counts
  by reason, and every violation but for the names in it are the same;
  the schedule is the same participant for participant up to a symmetry
  of the request (where two participants are alike in the request, their
  ids decide which gets which part of the schedule). The tests hold the
  repacker to that on random requests with pins, on the small corpus, and
  on round robins with rounds pinned, among them round robins of 36 and
  40 with one round played. It does not hold for every request, and
  there are two known exceptions in which the ids decide and the counts
  differ. The ordering may do a fixed amount of work, sized for 40
  participants: in a larger request in which everybody is alike and no
  two participants can simply be exchanged (a complete round robin of 44
  or more with one round already played) it stops and falls back to the
  ids. And two participants that differ, but are equal in everything the
  ordering compares, are ordered by id: a request built for the purpose
  (two different graphs of 16 participants with six events each, which
  the comparison cannot tell apart, side by side) came back with 83 late
  starts under one naming and 84 under another. None of 10,000 generated
  requests, each under three other namings, showed either.

  Before and after (0.2.3 against this release, default options):

  | Request | 0.2.3 | Now |
  |---|---|---|
  | Events `100` P4 v P3, `101` P4 v P2, `102` P3 v P1, `103` P4 v P1 on sessions of two and four slots, capacity 1 | all four in session 1 (`102`, `103`, `100`, `101`), P3 with a gap | `102`, `103` in session 0 and `100`, `101` in session 1, no gap |
  | Complete round robin of 10 on three four-slot sessions, capacity 5, ids `1`–`45` | no gap, 4 late starts | clean |
  | The same with ids `1000`–`1044` | 5 gaps, 8 late starts | clean, the same schedule |
  | Complete round robin of 24 on six four-slot sessions, ids `1`–`276` | 14 gaps, 6 late starts, budget spent | clean |
  | Complete round robin of 40 on ten four-slot sessions | 24 gaps, 8 late starts, budget spent | clean |
  | Complete round robin of 6 on one session of 21 slots, capacity 3 | 4 gaps (8 gap slots) | clean |
  | 84 season-like requests of 14 to 40 participants (shuffled round robins, sessions of four to six slots, up to 32 pins) | 643 gaps, 908 late starts, 47 without a gap | 77 gaps, 201 late starts, 64 without a gap |
  | 2,000 small random requests compared with an exhaustive search | 9% with a gap that a placement of as many events avoids; 31% with other counts under other event ids | none; none |

  Every complete round robin of an even number of participants from 6 to
  40, on sessions of four slots, now comes back with no violation at all
  under each of three id schemes.

  *What is not better.* The repacker is a set of bounded searches, and
  another order of events is better on most requests and worse on some.
  On 11,860 generated requests (random requests of up to 90 events,
  over-capacity requests, shuffled round robins of 5 to 40 participants
  with one or two legs, up to 60 pins, sessions of three to six slots,
  grids from too small to roomy), compared with 0.2.3 request by request:

  | | 0.2.3 | Now |
  |---|---|---|
  | Events placed, of 263,445 | 214,288 | 214,357 |
  | Gap slots | 10,024 | 2,504 |
  | Late starts | 25,847 | 25,712 |
  | Requests with no gap | 9,322 | 11,040 |

  No request places fewer events than 0.2.3 did, and 52 place more.
  Counting events placed first, then gap slots, then late starts, 3,385
  requests came back better and 958 worse. Of the worse ones, 812 have
  as many events placed and as many gap slots and only more late starts,
  which are not compared when one schedule is chosen over another. The
  other 146 (1.2%) place as many events with more gap slots, 380 more
  between them; on 63 of them a budget stopped a search. Round robins of
  an odd number of participants are not improved as those of an even
  number are: without pins, 142 of them have 376 gap slots where they
  had 372, and 27 came back with more gap slots against 34 with fewer.
  A round of an odd number leaves one participant out, so the rounds
  laid out as they stand have a gap wherever that falls inside a
  session, and the schedule has to come from the planner. The reference
  scenario (`tests/Fixtures/repack-scenario.json`) had 7 late starts and
  has 10, still with no gap, and its mis-pinned variant went from 2 gaps
  and 6 late starts to no gap and 10 late starts.

  *Events placed come first.* As this change first stood, it placed
  fewer events than 0.2.3 on 23 of those requests (one fewer on 21 of
  them), 768 of 770 on a round robin of 40 with ten pins on a grid with
  exactly as many places as events, and fewer on 13 of 151 larger
  requests (round robins of 39 and 40 with up to 400 events pinned, of
  two legs with 100 pinned, and 800 random events, on grids from too
  small to roomy). Nearly all are requests whose participants have more
  events than positions, or whose grid has fewer places than events:
  which events are left out decides whether every place can be filled,
  and the new order left others out. Three things were added for them.
  The last placement step searches further: it tries every single
  exchange before any chain of two, takes two events out for one where
  both participants of an unplaced event are in the way wherever it
  goes, puts one more event at a position along an augmenting path
  among the events there and the unplaced ones, and lets a path move an
  event that an exchange brought in. The third schedule above starts
  from another order where the first two leave something to gain. And
  the limit on the step's own work, which was 500,000 position checks
  on every request, is now a hundred checks for every event and
  position, never fewer than 500,000 and never more than 3,000,000: on
  770 events and 39 positions the fixed limit stopped the step while it
  was still placing events. With the three, none of the 11,860 requests
  and none of the 151 places fewer events than 0.2.3 did (the 151 place
  104,693 events for 104,392, with 9,463 gap slots for 22,166), and the
  round robin of 40 places all 770. Twenty-nine of those requests are
  in the tests with the counts 0.2.3 placed
  (`tests/Fixtures/repack/placed-by-0.2.3.json`), and requests in which
  a few participants have most of the events are compared with an
  exhaustive search. It is not a promise for every request: the
  repacker is still a set of bounded searches from three orders of the
  events. A second review looked for requests of another shape that do
  place fewer, and found them where the pins are scattered: of 200
  round robins of 16 to 40 participants with an eighth to a half of
  their events pinned at positions drawn at random, on a grid with a
  place for every event or one position to spare, 11 place fewer events
  than 0.2.3 did, by one to three (100 place more; 55,265 events for
  54,946 in all, with 904 gap slots for 1,557). Of 600 larger requests
  of mixed kinds (round robins of one and two legs, of an odd number,
  with extra events for a few participants, complete bipartite and
  random pairings, on grids from short to roomy) two place fewer and 35
  more, and of 7,500 small ones of the over-capacity kinds none does
  and 41 place more. On nine of the eleven the last placement step
  places as many as 0.2.3 or more when it is given forty times its limit
  of position checks and fifty times its limit of exchanges, in two to
  five times the time; on the other two (18 participants each) no limit
  that was tried does. Where
  the rounds played are pinned as whole rounds, which is what a season
  under way looks like, none of 320 requests of 20 to 40 participants
  places fewer and 144 place more.

  *Time* (PHP 8.4, one core, `composer bench`, fastest of three):

  | Benchmark | 0.2.3 | Now |
  |---|---|---|
  | 24 participants, even sessions | 162 ms | 5 ms |
  | 24 participants, uneven sessions with pins | 464 ms | 51 ms |
  | 40 participants, even sessions | 638 ms | 17 ms |
  | 40 participants, uneven sessions with pins | 70 ms | 154 ms |

  The last one is slower: 0.2.3 happened to pack that request without a
  search, and this release spends about 0.1 seconds laying out its
  rounds around the ten pins. Both return it with no gap. A request
  that comes back clean costs what its first schedule costs. One that
  does not is planned a second time and searched further for events to
  place, and that is where the time goes: the 11,860 requests took 288
  seconds in all where 0.2.3 took 106, the slowest of them 1.8 seconds
  (a round robin of 40 with four pins, which took 0.5
  seconds in 0.2.3). The 151 larger requests took 148 seconds where
  0.2.3 took 79: a round robin of 40 with pins a median of 0.75 seconds
  and 3 at most, one of two legs among 40 (1,460 events) a median of 2
  seconds and 4.8 at most, where 0.2.3 took 0.9 and placed ten events
  fewer with 18 more gap slots. The 200 round robins with scattered
  pins named above took 0.58 seconds at the median and 3 at most, where
  0.2.3 took 0.11 and 1.4, and the slowest request the second review
  found, two legs among 29 participants (807 events and five pinned, on
  a grid with 43 more places than events, where 14 events still find no
  position), 4.1 seconds where 0.2.3 took 0.4 for as many events
  placed. Requests that 0.2.3 packed without a search are the ones
  that are slower by the most: a round
  robin of 40 with 400 of its events pinned took 0.03 seconds and takes
  0.5 (with 84 gap slots for 157), 800 events between 800 separate
  pairs of participants 0.12 and 0.35, and 800 events of one participant
  against three others, of which 60 can be placed and the step cannot
  prove it, 0.2 and 1.5. Sessions of many slots were the slowest thing
  a repack did, and still are, though less so: the table a session's
  exact search starts from grows with two to the power of its slot
  count, and it is now paid for in steps, so a session whose share of
  the budget cannot pay for it is packed greedily. A request of 800
  events among 200 participants, whose sessions do not pack, took 0.3
  seconds on sessions of 12 slots and takes 0.9, 3.7 seconds at 16 slots
  and takes 2.6, and 17 seconds and 890 MB at 18 slots and takes 10
  seconds and 16 MB (with 767 gap slots for 1,336 at 12 slots, 1,104 for
  1,703 at 16 and 1,551 for 2,037 at 18). The usage guide has the
  figures.

  *What the step budget governs.* The laying out of the
  events before any session is chosen, the last placement step, the
  search for fewer gap slots and the search for the events two
  over-capacity participants share are not bounded by steps alone (a
  step of theirs is not a fixed amount of work, and the first spends
  none): each stops at a limit on its own work. At the default
  `stepBudget` of 200,000 and at every larger one those limits are
  fixed, so a larger budget changes nothing there. Below the default
  each is the same share of its limit as the budget is of the default,
  and the last placement step never has fewer than one position check
  for every event and position. So a smaller budget shortens every
  search of a repack, as it did in 0.2.3, where every search was
  counted in steps. `RepackOutcome::isBudgetExhausted()` is true when a
  search reached such a smaller limit, as it is when a step was
  refused, so false still means that a larger budget gives the same
  outcome. The order of the participants is the one search no budget
  shortens: it has a fixed limit of its own (at most about a tenth of a
  second), left whole so that the ids of a request decide no more at a
  small budget than at a large one. As this change first stood the
  limits did not follow the budget, and a budget of one step took 0.27
  seconds at the median and 1.3 at most on the scattered pins and 2
  seconds on the slowest request. Measured now (PHP 8.4, no OPcache,
  one core; median / longest in milliseconds, then events placed), on
  160 seasons of one and two legs among 20 to 40 participants with a
  fifth or a half of their rounds played and pinned, on the 200 round
  robins with scattered pins, and on the slowest request:

  | Requests | `stepBudget` | 0.2.3 | Now |
  |---|---|---|---|
  | 160 seasons (71,808 events) | 1 | 23 / 167, 64,465 | 31 / 171, 67,884 |
  | | 2,000 | 33 / 229, 69,549 | 49 / 259, 69,215 |
  | | 20,000 | 60 / 351, 69,810 | 72 / 719, 69,919 |
  | | 200,000 (default) | 78 / 895, 69,823 | 117 / 1,762, 70,008 |
  | 200 with scattered pins (55,573 events) | 1 | 8 / 35, 48,420 | 17 / 57, 53,072 |
  | | 2,000 | 34 / 99, 54,477 | 31 / 117, 53,721 |
  | | 20,000 | 74 / 358, 54,938 | 106 / 511, 54,706 |
  | | 200,000 (default) | 111 / 1,407, 54,946 | 576 / 3,048, 55,265 |
  | Two legs among 29 (807 events) | 1 | 34, 751 | 96, 793 |
  | | 2,000 | 111, 793 | 137, 793 |
  | | 20,000 | 238, 793 | 504, 793 |
  | | 200,000 (default) | 398, 793 | 4,086, 793 |

  The budget bounds the work and the longest time falls with it; it is
  not a dial that makes every repack take a tenth as long at a tenth of
  the budget, because a search that ended well inside its limit at the
  default is not shortened by a share of that limit. Seasons of 40 over
  two legs (1,560 events) take 0.12 seconds at a budget of one step and
  0.38 at a tenth of the default, where they take 0.79 at the default
  and took 0.07, 0.18 and 0.30 in 0.2.3; over one leg, 0.04, 0.09 and
  0.17. Where the pins are scattered, a budget between a hundredth and
  a tenth of the default places fewer events than 0.2.3 placed at that
  budget (fewer on 135 of the 200 requests at 2,000 steps and more on
  12), in about the same time; at one step and at the default it places
  more. Two more things hold below the default. A smaller budget is a
  smaller bound and not less time for every request: one whose search
  the default ends just inside a limit can take longer at a smaller
  budget, because the phases after a search that was cut short have
  more to do (the largest difference found: 36 milliseconds at the
  default and 160 to 180 at half of it). And where two or more
  participants have more events than positions, the grid's own
  `CapacityExceeded` (the one with no participant) can state a smaller
  demand than at the default or be missing, and an unplaced event can
  have the reason `ParticipantOverCapacity` where the default gives
  `NoSlotAvailable`; `isBudgetExhausted()` is true for such an outcome,
  and the over-capacity participants themselves are reported alike at
  every budget. In 0.2.3 the capacity violations did not depend on the
  budget. The usage guide ("The Step Budget") has the table by size of
  season and what no budget shortens.

  *Who is affected.* Everyone who repacks. *What to check:* an outcome
  kept from an earlier release, or its fingerprint, no longer matches a
  new run of the same request, so repack again before comparing; code
  that expected a particular event at a particular position for a fixed
  request; the number of events placed where the pins are scattered
  over the grid and not whole rounds, which is lower by one to three on
  about one such request in twenty and higher on about half (above);
  time limits set close to what a repack used to take, because a
  request that does not come back clean takes longer than it did (the
  slowest found, 4.1 seconds for 0.4), and a `stepBudget` below the
  default is the way to bound that, for fewer events placed and more
  gaps as the table shows; and a `stepBudget` already chosen below the
  default for the time it gave, which now buys other outcomes and other
  times (the table above).
  The order of the lists an outcome returns has not changed: assignments
  and unplaced events by event id, violations by kind and then by
  participant id and session, over-capacity participants by shortfall and
  then id. Two ties that the ids used to decide follow the repacker's
  own order now. Where two over-capacity participants share a dropped
  event and have the same shortfall, the one it names is the first in
  that order and no longer the one with the lower id. And of an
  over-capacity participant's events against opponents with the same
  room to spare, the one left out is the last in that order and no
  longer the one with the highest id. Code that predicted either from
  the ids must read the unplaced list instead.

  Golden fixtures regenerated, each because the positions (and so the
  fingerprints) changed as described: `tests/Fixtures/golden/repack/round-robin.txt`
  (every case now has no violation), `repack/scenario.txt` (late starts
  as above; the mis-pinned variant leaves out event `1009` where it left
  out `1022`), `repack/budget-stops.txt` (a round robin without pins now
  gives the same clean outcome at every budget, where a budget of one
  step left events unplaced; every case with pins has fewer violations
  than it had at every budget, at the default one 9 for 15 with eight
  participants, 12 for 37 with twelve, 43 for 76 with sixteen, 51 for
  124 with twenty and 61 for 193 with twenty-four; and the case of
  eight participants with pins says at a budget of 1,000 steps that a
  budget stopped a search, where it said none did, because the third
  schedule is made on what is left; three of its lines at budgets below
  the default are what they are because the work limits follow the
  budget: with eight participants and pins, 10 violations at 1,000
  steps, and at 30,000 steps the default's outcome with a budget having
  stopped a search; with twelve and pins, 33 violations at 1,000 steps;
  no line at the default budget depends on it), `examples/19-repacking-a-season.txt` (the
  same counts, one late start named for another participant) and
  `examples/23-application-adapter-and-repack.txt` (three of five events
  placed as before; `fx10` is placed and `fx09` left out, where it was
  the other way round).

### Added

- **The roles of the first leg are searched within what the role
  assignment keeps, without `backtracking`
  ([#51](https://github.com/mission-gaming/tactician/issues/51)).** A role
  assignment decided the roles of the first leg before any constraint saw
  an event, and a constraint that rejected them failed the schedule,
  although other roles the same role assignment would have given satisfy it.
  A role assignment now states the rule it holds a first leg to
  (`RoleAssignmentInterface::rule()`), and when every rotated order has
  failed with the roles as given, the scheduler goes through the same
  layouts again and looks for roles within that rule:
  - a seating that a closure, a metadata rule or a constraint of your own
    rejects is fixed the other way round, and the role assignment is asked
    again with the fixed seatings in its `RoleAssignmentContext`.
    `BalancedRoleAssignment` balances the leg around them whenever a
    balanced leg with those roles exists, whatever the size of the field;
  - where that does not complete the schedule, as under a limit on
    same-role runs or on the role totals, which fixes no seating, the roles
    of the first leg are searched, each choice held to the rule, with the
    later legs following by the leg strategy.

  Only roles are looked for: the pairings and their rounds are those of
  the layout, and a schedule that a rotated order completes with the roles
  as given is returned as it always was. `BalancedRoleAssignment` states
  the rule that no participant has a role more often in a leg than half its
  events rounded up; `RoundParityRoleAssignment` states none, so nothing
  about it changes. What this gives the default: two mirrored legs of eight
  under `ConsecutiveRoleConstraint::homeAway(2)` complete without
  `backtracking`, with a balanced first leg, where the balanced roles of
  0.2.2 failed; and of the 729 ways to fix the roles of some of the six
  pairings of four participants, none fails while a balanced leg with those
  roles exists, where 143 did. With `backtracking: true` the same search
  runs for every first leg the backtracking search finds, so a first leg
  that the role assignment would change no longer ends the search. The
  search is bounded (20,000 rejected roles and 200,000 events put to the
  constraints, over all the layouts of a call) and a failure says whether a
  bound stopped it: two to four mirrored legs under a limit of two in a row
  were found for every field of even size from 6 to 20 and for 3, 5, 7, 9
  and 11 participants, and the search stopped at its bound for 13, 15, 17
  and 19 (over two legs, for every field of odd size from 13 to 29). A
  limit of three in a row was found for every field from 3 to 20. A
  failure of this search is a failure of the schedule under
  `BalancedRoleAssignment`; under the default the roles the generator
  proposes are tried after it (the entry on the default under **Changed**).
  `docs/design/role-assignment.md` ("The default") has the decision and
  the measurements, `docs/ROADMAP.md` what still fails.
- **`RoleAssignment\BalancedWherePossibleRoleAssignment`** (experimental),
  the default of `RoundRobinOptions` from this release (the entry on the
  default under **Changed** has what that changes), and
  `'balanced_where_possible'` in plain data. It decides a leg exactly as
  `BalancedRoleAssignment` does and states the same rule, so the scheduler
  looks for balanced roles under it in the same way; where it finds no
  schedule with them, it generates the schedule again with the roles the
  generator proposes, as under `RoundParityRoleAssignment`, and marks it.
  The fall-back is the scheduler's: it knows this class, and a role
  assignment of your own cannot ask for it. Options that name the class
  serialize with the identifier and keep it through a round trip; options
  that name nothing serialize without the key, as before. `'balanced'` and
  `'round_parity'` mean what they meant: balanced roles or a failure, and
  the roles of 0.2.x.
- **`RoleAssignment\RoleFallback`** (experimental), and the metadata key
  `role_fallback` of a round-robin schedule. A schedule that the default
  role assignment could not give balanced roles says so in its metadata,
  under `RoleFallback::METADATA_KEY` (`'role_fallback'`), with the reason
  as a string; `RoleFallback::of(Schedule $schedule): ?RoleFallback` reads
  it and returns null for a schedule without it. The key is absent from
  every schedule with balanced roles and from every schedule generated
  under a role assignment that was named, so those are byte for byte what
  they were. It is part of the schedule's plain data and survives
  `toArray()`, `fromArray()` and JSON; the schedule form stays version 1,
  since `metadata` is the free-form map it always was. Three reasons, each
  with what naming `BalancedRoleAssignment` would do for the same input:
  `NoBalancedRoles` (`no_balanced_roles`): every choice of balanced roles
  that is searched was tried, and it would fail;
  `SearchStoppedAtBound` (`search_stopped_at_bound`): a bound stopped the
  search, and it would stop at the same bound and fail;
  `BalancedRolesRejected` (`balanced_roles_rejected`): under a constraint
  of your own the balanced roles put to it were rejected and, with a
  schedule in hand, the search for others stopped after the 4,000 events
  it may then put to the constraints (or, with `backtracking: true`, no
  backtracking search was made for balance), where it would search to its
  full bounds. `of()` throws an `InvalidInputException` for a value under
  the key that is none of them. More reasons may be added, so a `match`
  needs a `default` arm.
- **`RoleAssignment\RoleAssignmentContext`** (experimental), the second
  argument of `assignRoles()`: `$leg` and `$legs` (which leg of how many
  the roles are decided for; the scheduler asks once for each leg), and the
  seatings the constraints have fixed, read with `isFixed(int $round, int
  $position)` and `hasFixedSeatings()`. A role assignment that does not
  read them loses that step and nothing else: an answer that reverses a
  fixed seating is not used, and the scheduler goes on to search the roles
  within the rule. It is an object so that more can be told later without
  another change of the interface.
- **A tie in a table can be settled by a decision the application
  supplies.** Where every figure is level, the order has to come from
  outside the results, and until now an application could only apply it to
  the list it passed to the next stage. A table and the rank selectors now
  take it as a decision: a `DTO\Decider` holding the IDs of the level
  participants, best first, with an optional label and reason that the
  library stores and never reads. It records that the order was decided
  outside the results and never how (#99, ADR 0008).
  - `Standings::withDecisions()` (new) returns the table with each named
    tied set in the order of its decision, in the places the set held. The
    table carries the decisions as the marks of those places:
    `getDecisions()`, `getDecisionFor()` and `TiedSet::getDecision()` (all
    new). The constructors of `Standings` and of `TiedSet` take the
    decisions as a new last parameter, default none. A decided set is
    still reported by `getTiedSets()`; its decision tells it from one that
    is undecided.
  - **A decision never overrides a result.** It orders one whole tied set
    and nothing else, so no entry passes one it is not level with and none
    leaves its tier. Anything else is rejected with a
    `RejectedDecisionException` (new; it extends `\InvalidArgumentException`
    and carries the marker interface), whose `getReason()` is a
    `RejectedDecisionReason` (new): `UnknownParticipant`,
    `SetAlreadyDecided` (two decisions for one set, or two that overlap),
    `ParticipantsNotLevel` (a participant who is not tied, or the wrong
    set) and `PartOfLevelSet`. A decision names every member of its set,
    also where only the first places matter, so that a table has one order
    whatever is read from it. Where the decisions given together break
    more than one rule, the first of them in that order is reported,
    whatever order the decisions are in.
  - **Decisions are applied last.** `StandingsCalculator::withAdjustments()`
    and `withByesCredited()` keep the decisions of a table where the change
    leaves every decided set as it was and refuse the table where it does
    not (reason `Stale`), so no order is shown as decided for a set the
    decision was not made for. `Standings::withTiedSetsOrderedBy()`, and a
    `StageOutcome` built with an entrant list, leave a decided set in the
    order of its decision.
  - `RankRangeSelector::withDecisions()` and
    `BestOfRankSelector::withDecisions()` (new) apply decisions to the
    orders a selection reads before it reads a rank: a pool's table, the
    outcome's own table, and the two orders that no table holds, the
    holders of one rank across pools and a pooled table compared per event
    played. A set that a decision settled is not refused; an unplayed
    table still is. This is the third way on from an
    `UndecidedSelectionException`, beside the two that accept an undecided
    order, and the one for a tie between two entrants who both go through
    and take different slots of the next stage.
  - `RankRangeSelector::selection()` and `BestOfRankSelector::selection()`
    (new) return what `select()` returns as a `RankSelection` (new), which
    says which selected places a decision settled and hands back the
    decision with its label and reason (`isSettledByDecision()`,
    `getDecisionsFor()`, `getDecisions()`). `select()` returns the list as
    before. A best-of-rank selection that leaves out the lowest-placed of
    a larger pool (`PoolComparison::DiscardLowest`) marks every selected
    place with a decision that settled who is left out, because every
    holder is compared without those entrants.
  - `UndecidedSelectionException::isPerEvent()` (new, with a ninth
    constructor parameter and the context key `per_event`) says that the
    participants are level per event played and not as a tied set of the
    table, so that the three kinds of tie can be told apart: one table, the
    holders of a rank, the table per event played. The message of that
    case now says "the standings per event played".
  - Plain data: a decision is a `Decider` array (`participants`, `label`,
    `reason`). The two rank selectors write the key `decisions`, a list of
    such arrays without a format version of their own, only when they were
    given any, and read it back; it is one more optional key of version 1
    of their forms (ADR 0005). A stage state holds no decision, and no
    engine reads one: a Swiss round and a re-seeded bracket round are
    paired from the entrant list where results are level, as before.
  - The combined table of `StageOutcome::combining()` is calculated
    afresh and carries no decision of a pool's table; a tie in it takes a
    decision of its own. A selection that orders entries itself (the
    holders of a rank, a table per event played) reads the decisions given
    to the selector and not those of the outcome's own table.
  - With no decision supplied, every table, selection and array form is
    what it was. Example: `examples/28-ties-settled-by-decision.php`.
- **The value of a bye is the caller's choice.** A ranking strategy says
  what one bye adds to a ranking value by implementing the optional
  interface `Standings\RankingStrategyWithByeValue` (`getByeValue(): float`).
  `WinDrawLossRanking` implements it: a bye is worth a win, as it was,
  unless another value is given with `withByeValue()` (new), a fourth
  constructor argument `byeValue` (default null, for the value of a win),
  or the optional key `bye` of `fromArray()`: a number, or `'win'`,
  `'draw'` or `'loss'` for the value the same array gives that outcome.
  `toArray()` writes `bye` only for a ranking that was given a value, so
  the array of every other ranking is as it was. The Swiss engine and a
  re-seeded bracket credit a bye with that value, in the one table they
  read for the order a round is paired from and for the outcome, so the
  two cannot value a bye differently. A ranking strategy of your own that
  implements the interface is no longer refused a stage with a bye; one
  that does not is refused as before, and the message names the interface.
  An engine fingerprint states the value (`bye-value`) only when it is not
  the value of a win, so every stamp written before is unchanged. With the
  defaults no table, pairing or fingerprint changes; no golden fixture
  changed ("Byes in a table" in `docs/USAGE.md`, ADR 0009,
  `docs/adr/0009-a-bye-is-worth-what-the-ranking-strategy-says.md`; #58).
- **A table with its byes and its adjustments in one call:
  `StandingsCalculator::calculateWith(array $participants, array $results,
  array $byes = [], array $adjustments = [], int $creditsPerBye = 1)`.** The
  byes are counts by participant ID, as `StageState::getByeCounts()`,
  `StageOutcome::getByes()` and the new `Schedule::getByeCounts()` return
  them. Every bye is counted on the entry (`StandingEntry::getByes()`, also
  where a bye is worth nothing) and credited with the value of a bye;
  played, wins, draws and losses count results only.
  `StandingEntry::getRankingValueOfResults()` (new, with a thirteenth
  constructor parameter `rankingValueOfResults`, default null) is what the
  results alone are worth, beside `getEarnedRankingValue()` (that plus the
  byes) and `getRankingValue()` (that plus the adjustment). With
  tiebreakers that read no bye and are not computed between the tied
  participants, the table is entry for entry the one
  `withAdjustments(withByesCredited(calculate(...)))` gives. The engines
  calculate their tables with it.
- **A bye treatment for the tiebreakers computed from opponents.**
  `BuchholzTiebreaker` and `SonnebornBergerTiebreaker` take a
  `Standings\ByeTreatment` (new) as a constructor argument: `Uncounted`
  (the default, and what they computed before: the participant's own bye
  adds nothing and an opponent's ranking value is that of its results),
  `Zero` (a bye is an opponent worth nothing, and an opponent's ranking
  value is what it earned, byes included) or `OwnRankingValue` (each bye
  of the participant adds its own earned ranking value, and an opponent's
  ranking value is what it earned). No treatment reads an opponent's
  adjustment, and in a table without byes the three give one value. The
  name of the tiebreaker, and so the key of its value, is the same under
  each. A tiebreaker of your own reads byes by implementing the optional
  interface `Standings\TiebreakerWithByeTreatment` (new), and is then
  handed entries that carry the byes and their credit. An engine
  fingerprint states a treatment (`tiebreaker-byes`) only when it is not
  `Uncounted`.
- **Tiebreakers between the tied participants, and by score difference and
  scores-for.** `Standings\BetweenTiedParticipants` (new) wraps another
  tiebreaker as its measure and is computed, for each set of participants
  level when its turn in the chain comes, from the events between them
  alone (a sport calls it head-to-head):
  `new BetweenTiedParticipants(new RankingValueTiebreaker())`, and the same
  around `WinsTiebreaker`, `ScoreDifferenceTiebreaker` and
  `ScoresForTiebreaker`. The last two and `RankingValueTiebreaker` are new
  tiebreakers; the score difference and scores-for of a whole table were
  compared before, after the tiebreakers, and can now be given an earlier
  place. The calculator forms the tied sets, so the values are the same
  for every order of the participants and the results. Tiebreakers of this
  kind that follow one another in a chain are one small table: every one
  of the run is computed over the participants level when the first of
  them is asked, from the events among all of those, so
  `between-tied:ranking-value`, `between-tied:score-difference` and
  `between-tied:scores-for` rank the level participants by the points,
  then the score difference, then the scores-for they took from each
  other. A tiebreaker of another kind ends the run, and one between the
  tied after it is computed for those level at that point. A run is not
  taken again over a subset it leaves level: that subset goes on to the
  next tiebreaker of another kind (ADR 0010,
  `docs/adr/0010-a-tiebreaker-between-the-tied-participants-is-computed-by-the-calculator.md`).
  `TiedParticipantsTiebreaker`, the marker interface added with awarded
  results in this release, now has that contract: a tiebreaker of your own
  that implements it is handed the results and the entries of one tied set,
  not those of the whole table. A value is kept and compared under the name
  of its tiebreaker, so `new StandingsCalculator()` refuses a chain that
  holds a tiebreaker between the tied participants and has one name twice
  (`InvalidInputException`): the same `BetweenTiedParticipants` measure has
  one place in a chain. A chain without such a tiebreaker is not checked,
  as before.
- **Shared ranks.** `Standings::getRank(Participant): ?int` and
  `Standings::getRanks(): list<int>` give the rank the results decide: the
  entries of a tied set share the rank of the set's first position, and the
  entry after the set has the rank of its own position (1, 2, 2, 2, 5).
  A tied set that a decision ordered (`Standings::withDecisions()`) shares
  no rank: its entries have the ranks of their positions, because the
  decision placed them one after the other. `getPosition()` is unchanged
  and never shared.
- **A tiebreaker chain and a standings calculator from plain data.**
  `Standings\TiebreakerChain::fromIdentifiers()` (new) builds the library's
  tiebreakers from a list, in order: `'wins'`, `'score-difference'`,
  `'scores-for'`, `'ranking-value'`, `'buchholz'`, `'sonneborn-berger'`,
  each of them after `'between-tied:'`, or a map
  `['tiebreaker' => 'buchholz', 'byes' => 'own-ranking-value']` for the
  two that take a bye treatment. `identifiersOf()` writes a chain and
  `identifiers()` lists what is known. `StandingsCalculator::fromArray()`
  and `toArray()` (new) read and write a whole calculator: `ranking` (the
  form of `WinDrawLossRanking`), `tiebreakers` (a chain) and
  `awarded_results` (the form of `AwardedResultPolicy`), each optional when
  read. An unknown identifier, a key a form does not have and a name that
  is in a chain twice are refused; a calculator with a ranking strategy or
  a tiebreaker of your own has no plain-data form and `toArray()` refuses
  it (`NotSerializable`). The two forms are version 1 and are new in this
  release; `bye` is a new optional key of version 1 of the win-draw-loss
  ranking (ADR 0005).
- **The byes of a schedule and of a stage state, by round.**
  `Schedule::getByesByRound()` returns, for every round that has an event,
  the entrants that have no event in it, as participants in entrant order,
  and `Schedule::getByeCounts()` adds them up by participant ID. Both are
  read from the events and the entrant list, so they hold for a round-robin
  schedule, the whole-schedule Swiss preset, a schedule read back from
  storage (also one whose `byes` metadata was dropped) and one built by
  hand. `StageState::getByesByRound()` lists the byes of every recorded
  round. `RoundPairing::getByes()` has those of one round, as before.
- **A typed decider for a knockout tie that finishes level.** A `Result`
  can carry the decision that settled its tie: `new Result($event, null,
  $scores, decider: new Decider([$participant->getId()], 'label',
  'reason'))`. `DTO\Decider` (new) holds the IDs of the participants who
  advance, as a list, and an optional label and an optional reason that
  the library stores and never reads: it records that the tie was decided
  outside its results and for whom, and never how. `Result::getDecider()`
  reads it, and the constructor of `Result` has a sixth parameter,
  `decider`, default none. It is the one way to settle a level tie for a
  single event and for two legs alike, in both elimination engines and in
  every round of them (the losers bracket, the grand final and its reset,
  and a third-place tie), and `MatchOutcomeSelector` and
  `TieDecision::advancer()` read it the same way (#98).
  - The result keeps the scores of the event as played. The decider holds
    no score and changes none, and `isDraw()` and `getWinner()` say what
    they said: scores-for, scores-against and the score difference of
    every table come from the scores of the events only. The table of an
    elimination outcome counts a decided level single-leg event as a win
    for the participant who advanced, as it did when the decision was
    metadata.
  - A tie between two sends one participant on, so its decider names
    exactly one. The list is there because the type assumes no number of
    sides (ADR 0003); a decider that names two participants of such a tie
    is refused with the reason `InvalidResult`.
  - A decider settles a level tie and nothing else. One on a tie that its
    results already decide (an event with a winner, a two-legged tie the
    leg wins decide) is refused with `InvalidResult`, and so is a decider
    on each leg of one tie, whether the two agree or not. `Result` itself
    refuses a decider that names a participant who is not in its event
    (`InvalidInputException`), when it is built and when it is read from
    plain data.
  - In a two-legged tie the decider is on one leg's result. Either leg is
    read; the second is where it is recorded when the tie ends.
  - An awarded result that names no winner leaves its tie level, and may
    carry the decider that settles it: the award says the event was not
    played, the decider who goes on.
  - A level tie without a decider leaves its round unresolved, as before,
    and the engines now say which tie it is: the `UndecidedTie` exception
    of `isComplete()`, `pairNextRound()` and `getOutcome()` carries the
    round beside the two participants in its context (`['round' => 1,
    'participants' => ['p1', 'p4']]`). Two entrants can meet twice in a
    bracket, in the grand final and its reset, so the pair alone did not
    say.
  - A result writes the decider under the key `decider` (`participants`,
    `label`, `reason`), only when it has one, so the array of every other
    result is unchanged, an array written by an earlier release loads
    unchanged, and a stage state that holds decided ties is replayed from
    `toArray()` and from JSON. `decider` is one more optional key of
    version 1 of the result form (ADR 0005). A 0.2.x release that reads
    such an array does not see the key: it finds the tie undecided, and a
    state it writes back no longer has the decider. An application that may
    have to go back to 0.2.x records `tie_winner` metadata beside the
    decider, naming the same participant (see **Deprecated**, "Both at
    once"), which a 0.2.x release reads.
  - Only the elimination engines and `MatchOutcomeSelector` read a
    decider. A standings calculator, the Swiss engine and a round robin
    carry it with the result and do not read it.
  - With no decider recorded, every pairing, table and fingerprint is what
    it was: no existing golden fixture changed but for the wording of one
    message (see **Changed**). New fixtures:
    `elimination-decided-ties.txt` (brackets of level ties settled by a
    decider, which are pairing for pairing the brackets the same advancers
    win) and `wire/stage-state-decided-tie.json`.

  What this commits the library to: `DTO\Decider` is `@api`, in a stable
  namespace, so its constructor, `getParticipantIds()`, `getLabel()`,
  `getReason()`, its plain-data form, `Result::getDecider()` and the sixth
  parameter of `Result` are kept under the compatibility policy from the
  release that ships them. The usage guide has it under "Recording a Level Event".
- **`LegPlanContribution::$rolesArePreferences`**, the fifth constructor
  parameter, false by default. A leg strategy says with it whether the role
  it gives a later-leg event is a rule (the scheduler never reverses the
  event) or a preference (the scheduler reverses an event the constraints
  reject, and searches the roles of the later legs when both roles are
  rejected). `ShuffledLegStrategy` says true; the mirrored and the repeated
  strategy, and every strategy written before the flag existed, say false
  and are treated as they were.
- **Awarded results.** A `Result` can say that its event was not played and
  that the application gave it this outcome: `new Result($event, $winner,
  $scores, award: new Award('label', 'reason'))`. `DTO\Award` (new) is the
  mark, with an optional label and an optional reason that the library
  stores and never reads; `Result::isAwarded()` and `Result::getAward()`
  read it, and the constructor of `Result` has a fifth parameter, `award`,
  default none. An awarded result is a result: it counts for the winner it
  names (as a draw when it names none), is counted in played, wins, draws
  and losses, is the one result of its event (one beside a played result of
  the same event is refused as two results of one event), and settles what
  a result settles, so the participant it names advances from an
  elimination tie. `StandingEntry::getAwarded()` (new, with a constructor
  parameter `awarded`, default 0) says how many of an entry's results were
  awarded. Two things are the caller's choice, on the calculator:
  `StandingsCalculator` takes an `AwardedResultPolicy` (new) as a third
  constructor argument and returns it with `getAwardedResultPolicy()`.
  `countScores` (`count_scores` in `fromArray()`/`toArray()`, default
  true) says whether the scores of an awarded result, its nominal score,
  count in scores-for, scores-against and the score difference;
  `countBetweenTiedParticipants` (`count_between_tied_participants`,
  default true) says whether a tiebreaker computed between the tied
  participants only is handed awarded results. Such a tiebreaker declares
  itself with the new marker interface `TiedParticipantsTiebreaker`; the
  library's is `BetweenTiedParticipants` (below). A result writes the mark under the key
  `award`, only when it is awarded, so the array of a played result is
  unchanged and an array written by an earlier release reads as played. An
  engine fingerprint states either choice of the policy only when it is
  off, so every stamp written before is unchanged ("Awarded results" in
  the usage guide, #100).
- **Adjustments.** `Standings\Adjustment` (new) is an amount added to, or
  taken from, one participant's ranking value for a reason that is no
  event: a participant ID, a signed amount, and an optional label and
  reason that the library never reads, with `fromArray()`/`toArray()`
  (`participant`, `amount`, `label`, `reason`).
  `StandingsCalculator::withAdjustments(Standings $standings, array
  $adjustments)` applies them to a table and puts it in order again;
  `StandingEntry::getAdjustment()` is the sum for the entry,
  `getEarnedRankingValue()` what the results gave, and `getRankingValue()`
  the two together, which the table is ordered by
  (`StandingEntry::withAdjustment()` builds such an entry, and the
  constructor has a twelfth parameter, `adjustment`, default 0.0). The
  amounts for one participant are added exactly and rounded once, so the
  figure is the same for every order of the list and amounts that cancel
  leave nothing; a sum that no float holds is refused. The
  amount is on the scale of the ranking strategy, so an adjustment is
  accepted under every strategy; tiebreakers computed from opponents'
  ranking values use what an opponent earned. A stage keeps its
  adjustments on its state: `StageState::withAdjustment(Adjustment
  $adjustment)` and `getAdjustments()`, written under the key
  `adjustments` only when there is one. Every table an engine builds from
  the state has them: the next Swiss round is paired from the adjusted
  totals, a re-seeded bracket folds its survivors by them (record its
  adjustments before its second round is paired: a later one can change a
  round already played, and the engine then refuses the state with
  `RecordedRoundMismatch`), and the table of
  an outcome shows each beside what was earned, where it orders the
  entrants a bracket put out in the same round and moves nobody between
  tiers. `StageOutcome::combining()` keeps the adjustment of each pool
  entry. An adjustment for a participant the table or the stage does not
  have is rejected with an `InvalidInputException`. A participant who
  withdrew and is named by nothing but an adjustment is still one of
  `StageState::getAllSeenParticipants()`. With no awarded result and no
  adjustment every table and every pairing is what it was ("Adjustments"
  in the usage guide, #100).
- **A schedule keeps its entrants: `Schedule::getEntrants()`.** It returns
  every participant the schedule is of, once, as a list. For a schedule
  that `RoundRobinScheduler`, `SwissScheduler` or `PotDrawScheduler`
  returned it is the list the scheduler was given, the same objects in the
  same order, which is the seeding where position decides. The constructor
  takes the list as a third, optional parameter:
  `new Schedule($events, $metadata, $entrants)`. Left out, the entrants are
  read from the events in the order of first appearance, which is what a
  schedule built by hand had before. Given, the list has to hold every
  participant of every event and no ID twice (`InvalidInputException`
  otherwise), and may hold an entrant who is in no event. `addEvent()`
  keeps the list and adds a participant it does not hold after it. The
  list survives `toArray()`/`fromArray()` and JSON in its order (#53).
- **`InvalidConfigurationReason::UnsupportedFormatVersion`**
  (`unsupported_format_version`), for plain-data configuration that states
  a format version the release does not read; see the entry on
  `format_version` under Changed.
- **An entrant list on a table and on an outcome.**
  `Standings::withTiedSetsOrderedBy(array $entrants)` returns the table
  with the entries of every tied set in the order of the list, and moves
  nothing that results placed. `StageOutcome` takes the entrant list of its
  stage as a sixth constructor argument (`entrants`, default none) and
  applies it to its table; `getEntrants()` returns it, and
  `StageOutcome::combining()` takes one for the combined table as a third
  argument. The engines pass the participant list of the state. An outcome
  built by hand without a list keeps the table it is given.
- **Tiers of a table.** `Standings` takes the tier of each entry as a
  second constructor argument (default none) and returns them with
  `getTiers()`; entries of different tiers are never in one tied set. The
  elimination engines use them for the bracket placement.
- **Byes in a table.** `StandingsCalculator::withByesCredited(Standings
  $standings, array $byes, int $creditsPerBye = 1)` adds the value of a bye
  (the value of a win, unless the ranking is given another: see the entry
  on the value of a bye) for each bye to a table and puts it in order
  again (inside each tier, for a table that has tiers);
  `StandingEntry::getByes()` says how many byes an entry has,
  and `StandingEntry::withByes()` builds such an entry. The
  constructor of `StandingEntry` has a tenth parameter, `byes`, default 0.
  `creditsPerBye` is how many times each bye is credited: 1, or the legs
  of a tie where a bye stands for a tie of several legs. On the
  development branch the parameter was first named `winsPerBye`, from when
  a bye was always worth a win; no release shipped that name, so it is
  renamed without a deprecation, here and on `calculateWith()`.
- **A seed for a pre-drawn Swiss schedule.** `SwissOptions(rounds: 5,
  seed: 2026)`, and `'seed'` in `SwissOptions::fromArray()`; `toArray()`
  writes the key when there is a seed. The scheduler builds a
  `Random\Randomizer` on the `Xoshiro256StarStar` engine from it on every
  call, so the same participants, rounds and seed give the same schedule on
  every call, also on one scheduler asked twice, and the engine is part of
  the output for a seed. A scheduler that holds a `Randomizer` is refused a
  seed in its options (`IncompatibleOptions`).
- **`UndecidedSelectionException`**, an `InvalidConfigurationException`,
  with `getParticipantIds()`, `getFirstPlace()`, `getLastPlace()`,
  `getPool()`, `getFrom()` and `getTo()`, and four cases of
  `InvalidConfigurationReason`: `UnplayedStage`, `TieAcrossSelection`,
  `TieWithinSelection` and `RecordedRoundMismatch` (see **Changed**).
- **The choice to go on from an undecided table.**
  `RankRangeSelector::withTiesInsideSelectionAccepted()` and
  `withTableOrderAccepted()`, and the key `ties` in `fromArray()` and
  `toArray()` (`'refuse'`, `'accept-inside-selection'`,
  `'accept-table-order'`; written only when it is not `'refuse'`).
- **`ShuffledLegStrategy` takes an integer seed, and
  `RoundRobinOptions` reads and writes it as plain data.**
  `new ShuffledLegStrategy(seed: 2026)` draws the roles of the later legs
  from a `Random\Randomizer` it builds on the `Xoshiro256StarStar` engine
  from that seed, and starts the draws again whenever `planLegs()` is
  called, which the scheduler does once at the start of every `schedule()`
  call. The same participants, options and seed therefore give the same
  schedule on every call and in every process; the roles are those of a
  strategy handed `new Randomizer(new Xoshiro256StarStar(2026))` and used
  once, and the engine is part of the output for a seed. The restart is
  made in `planLegs()` only, so a class that extends the strategy and
  overrides that method calls the parent's; a clone of a strategy built
  from a seed has draws of its own, which start at the seed. The seed is the
  public property `ShuffledLegStrategy::$seed` (null for a strategy that
  holds a randomizer). In plain data it is the key `strategy_seed`:
  `RoundRobinOptions::fromArray(['legs' => 2, 'strategy' => 'shuffled',
  'strategy_seed' => 2026])`, and `toArray()` writes it back after
  `backtracking` for a shuffled strategy built from a seed. The key has
  that name, and not `seed`, because it seeds the leg strategy only: the
  order of the participant list is still shuffled by the `Randomizer` given
  to the scheduler, or not at all.
- **`InvalidConfigurationReason::OverlappingTime`** (`overlapping_time`), the
  reason of the two overlap rejections under Changed. A `match` over every
  case of the enum needs an arm for it.
- **What the fixes of #121 add to the public surface.** Each is described
  with its fix under Changed or Fixed below:
  `TimelineDefinition::writeInterval(DateInterval $interval, string $key):
  string`; the optional fourth constructor parameter and public property
  `StageTransition::$afterWholeBracket` (`bool`, default `false`); and two
  cases of `InvalidConfigurationReason`, `PlannedRoundsPlayed`
  (`planned_rounds_played`) and `ParticipantDoubleBooked`
  (`participant_double_booked`). A `match` over the reason needs a
  `default` arm, as the enum has always said.
- **A third-place tie for single elimination, opt-in (#93).**
  `EliminationOptions` has a fourth constructor parameter and public
  property, `thirdPlace` (`bool`, default `false`; `third_place` in
  `fromArray()`). With it, the last round of a `SingleEliminationEngine`
  bracket holds two ties: the final, and a tie between the two entrants who
  went out in the round before it. The round keeps the label `final` and
  lists the final's events first. Each event of that round says which tie
  it belongs to through the new enum `Stage\BracketTie` (`Final`,
  `ThirdPlace`): the engine writes the backing string (`'final'`,
  `'third-place'`) under `BracketTie::METADATA_KEY` (`'bracket_tie'`) in
  the event's metadata, where it is stored with the event, and
  `BracketTie::of(Event $event): ?BracketTie` reads it back;
  `BracketTie::winnerRank()` is 1 or 3. The third-place tie has the legs of
  every tie of the bracket and is decided as they are, a level one through
  its decider, and the stage is complete when both ties are
  decided (one of the two decided is `RoundPartiallyResolved`, as in every
  round). The outcome's table then gives places 1 to 4, each in a tier of
  its own: the entrant who advanced from the final, the other finalist, the
  entrant who advanced from the third-place tie, the other one.
  `MatchOutcomeSelector` reads the final and leaves the third-place tie
  out, which it knows by the mark: `winners()` is the one participant who
  won the bracket and `losers()` the other finalist, as after a bracket
  without the tie and in whatever order the round was recorded, so a
  `StageTransition` with `afterWholeBracket: true` still counts one, and
  places 3 and 4 are a rank selection. On a fixed path the entrant who went out of the tie that
  fed the final's first-named side is first-named; a re-seeded bracket
  names the two in the order of the table it folds the final from.
  A field of 3 has one entrant who goes out before the final: no
  third-place tie is played, and that entrant is third, as without the
  option. A field of 2 with the option is refused on every call
  (`TooFewParticipants`), and so is the option by
  `DoubleEliminationEngine` when it is constructed (`IncompatibleOptions`),
  whose losers bracket decides third place. `EliminationPlan` takes
  `thirdPlace` as a fourth constructor argument (default `false`), counts
  the tie in `getExpectedEventCount()`, and has `hasThirdPlaceTie(): bool`
  and the constant `THIRD_PLACE_MINIMUM`; `getTotalRounds()` does not
  change. The option is part of the engine fingerprint when it is on
  (`;third-place=yes`), so a state stamped with it is refused by an engine
  without it, and the other way round. A state without a stamp is refused
  too once its last round is recorded (`RecordedRoundMismatch`): by an
  engine with the option when the round lacks the tie, and by one without
  it when the round holds an event marked `'third-place'`.
  With the option off nothing changes: every pairing, every event (none
  carries `bracket_tie`), every table and every fingerprint is what it was,
  and no existing golden fixture changed; the new fixture
  `single-elimination-third-place.txt` pins the brackets with the option.
  `EliminationOptions::toArray()` writes `third_place` only when the option
  is on, so the plain-data form of options without it has the keys it had,
  and an array stored without the key is read as a bracket without the tie:
  `third_place` is an optional key of version 1 of the form, like `seed` in
  Swiss options, and a misspelling of it is refused like any unknown key.
  Not offered: a number of
  legs for the last round that differs from the rest of the bracket, and
  ties for the places below fourth. The usage guide has it under "A
  third-place tie".
- **The best of a rank across pools: `BestOfRankSelector`.**
  `BestOfRankSelector::ofRank(rank: 3, count: 2)` returns the two best of
  the entrants placed third in their pools, best first: the selection
  behind "the top two of each pool and the two best third-placed". Who
  holds the rank is read from each pool's own table; the holders are
  compared by the figures the combined table of the outcome has for them
  (ranking value, each tiebreaker of the calculator given to
  `StageOutcome::combining()`, score difference, scores-for). It has
  `fromArray()`/`toArray()` (`rank`, `count`, and `comparison` and `ties`
  when chosen, after `format_version`; an unknown key is refused), states
  its count as its
  selection size for `CompositionValidator`, and refuses an undecided
  selection as `RankRangeSelector` does, with the same two choices to go
  on (`withTiesInsideSelectionAccepted()`, `withTableOrderAccepted()`). The
  list is best first and is not placed for a bracket (#94).
- **`PoolComparison`, the method pools of unequal size are compared by**,
  an enum with three cases: `RawTotals` (`raw-totals`), `PerEvent`
  (`per-event`: every figure divided by the events the entrant has played)
  and `DiscardLowest` (`discard-lowest`: the entrants placed below the size
  of the smallest pool are left out of each larger pool with their results,
  and the table is calculated again). `BestOfRankSelector::comparedBy()`
  takes any of them and `RankRangeSelector::comparedBy()` the first two,
  for an overall selection; in plain data it is the key `comparison`,
  written only when a method was chosen. No method is applied by default:
  see **Changed** for what that means for `overall()`. An adjustment is
  compared under every method, as part of the ranking value a table is
  ordered by: `PerEvent` divides that total by the events played (an
  entrant that has played none is compared by its adjustment as it
  stands), and `DiscardLowest` applies the adjustments of the entrants it
  keeps to the table it calculates again. An awarded result is a result
  under every method.
- **What the selection across pools adds to existing types.**
  `StageOutcome` takes the calculator its table was calculated with as a
  seventh constructor argument (`calculator`, default none) and returns it
  with `getCalculator()`; `StageOutcome::combining()` passes on the one it
  is given, and `DiscardLowest` needs it.
  `UndecidedSelectionException` has an eighth constructor parameter,
  `rankAcrossPools` (default null), and `getRankAcrossPools()`: the rank
  whose holders were compared, when the tie is among them; its context then
  holds the key `rank_across_pools`, and has the six keys it had otherwise.
  One case of `InvalidConfigurationReason`, `PoolComparisonRequired`
  (`pool_comparison_required`). New example:
  `examples/26-best-next-placed-qualifiers.php`.

### Changed

- **Breaking: the roles of a round robin are balanced where possible by
  default ([#51](https://github.com/mission-gaming/tactician/issues/51)).**
  `RoundRobinOptions` without a role assignment now means
  `BalancedWherePossibleRoleAssignment` (under **Added**), where it meant
  `RoundParityRoleAssignment`. The scheduler gives a schedule balanced
  roles wherever it finds a schedule with them, which without constraints
  is always: in the first leg, and in every later leg of
  `MirroredLegStrategy` and `RepeatedLegStrategy`, each participant's two
  role counts end at most 1 apart in a field of even size and equal in a
  field of odd size (the later legs of `ShuffledLegStrategy` draw their
  roles, as they did); the round-parity roles ended up to 3 and up to 4
  apart, and of four participants one was second-named in all three of its
  events. Where the constraints leave it no schedule with balanced roles
  that it finds, it **falls back**: the schedule is generated with the
  round-parity roles, exactly as under `RoundParityRoleAssignment`, and its
  metadata says so and why. So a configuration that completed with the
  default options of 0.2.x still completes, and the default fails only
  where `RoundParityRoleAssignment` fails, with its failure. This is an
  output change that is not a fix: the old roles were what was documented.

  `BalancedRoleAssignment`, named, is the strict choice and promises what
  it promised: balanced roles, or an `IncompleteScheduleException`.

  **Who is affected.** Every round robin of three or more participants
  generated without naming a role assignment: every leg count, each of the
  three leg strategies, with or without a `Randomizer` and whatever its
  seed, with or without constraints, `backtracking` on or off, and options
  built in code or read from plain data. A `ScheduleOptimizer` whose
  generator makes such a schedule is included. Not affected, byte for byte:
  a field of two; a schedule whose options name a role assignment, either
  of the two that 0.2.x had (compared with 0.2.x over 2 to 14 participants,
  one to four legs, the three leg strategies, no seed and five seeds:
  identical apart from the later legs of a scheduler with a `Randomizer`,
  which the entry under "Output change (fix)" changes for every role
  assignment); Swiss, elimination and pot-draw schedules, which do not use
  a role assignment.

  **What changes.** Roles only. Who meets whom, in which round, the order
  of the events, the byes of a field of odd size and the metadata are what
  they were; which of the two participants of an event is first-named is
  not, in the first leg and so in every leg the strategy derives from it.
  That holds without constraints, and under constraints wherever they
  accept the roles of the same rotated order under both role assignments.
  Where a constraint rejects the roles of the one and not of the other,
  the two schedules come from different rotated orders and differ in who
  meets whom in which round as well: six participants `1` to `6` under a
  closure that refuses `1` first-named against `2` open with `1-6 2-5 3-4`
  under round parity and with `2-3 4-1 6-5` under the default.
  Compared over 936 schedules (2 to 14 participants, one to four legs, the
  three strategies, no seed and five seeds): no pairing and no round
  differs from the round-parity schedule, and every schedule of three or
  more participants differs from it in roles. Four participants `1` to `4`,
  one leg, no seed:

  ```
            0.2.x (round parity)   0.3.0 (balanced)
  R1:       1-4 2-3                4-1 2-3
  R2:       2-1 4-3                1-2 3-4
  R3:       1-3 4-2                3-1 4-2
  ```

  Before, 1 was first-named twice, 2 twice, 3 never and 4 twice; after, 1
  and 2 once and 3 and 4 twice, which is as close as three events allow.

  A schedule that falls back is, without a `Randomizer`, the schedule
  0.2.x gave, event for event, with one more metadata key (with one, it is
  what `RoundParityRoleAssignment` gives from the same seed, whose later
  legs the entry under "Output change (fix)" changes). The same four
  participants under a rule that never names `3` first: no leg can be
  balanced, since `3` plays all three of its events in one role, and the
  default returns the left-hand column above with
  `'role_fallback' => 'balanced_roles_rejected'` in its metadata.

  **What to check (migration).**
  - To keep the roles of 0.2.x, name the old role assignment:
    `new RoundRobinOptions(roleAssignment: new RoundParityRoleAssignment())`,
    or `'role_assignment' => 'round_parity'` in plain data. A test holds
    that output to fixtures whose bodies are the 0.2.x default's
    (`round-parity-*.txt` below).
  - **Not every schedule of the default is balanced.** Code that needs to
    know reads the mark: `RoleFallback::of($schedule)` is null for a
    schedule with balanced roles and the reason for one without. Code that
    would rather have no schedule than an unbalanced one names
    `BalancedRoleAssignment`, or `'role_assignment' => 'balanced'`.
  - **Stored options.** `RoundRobinOptions::fromArray()` reads data without
    a `role_assignment` key as naming no role assignment, which gets the
    default of the release that reads it, as the usage guide has said since
    0.2.2. Stored options without the key therefore now give roles that are
    balanced where possible: that is everything `toArray()` wrote for
    options that named no role assignment, and hand-written data without
    the key. Options that named one, the old default included, were written
    with the key and are read as they were written. Add
    `'role_assignment' => 'round_parity'` to stored data that must keep
    generating the schedule it generated. The form itself is unchanged and
    stays version 1: the absent key means what it meant, the default of the
    reading release, and it is that default which moved. The new default
    has an identifier of its own, `'balanced_where_possible'`, for options
    that should name it; 0.2.x refuses that identifier.
  - A stored or published schedule is not regenerated identically. Keep the
    stored events; anything that compares a fresh schedule, or its hash,
    with a stored one sees a difference from round 1.
  - **Same-role runs where two mirrored legs meet are one longer in most
    fields.** A leg of balanced roles has no participant in one role more
    than twice in a row, but the second mirrored leg starts with the roles
    of round 1 reversed, and a participant can then play three in a row in
    a field of even size, and in a field of odd size three or, where its
    size is one more than a multiple of four (5, 9, 13 and so on), four.
    The longest run anywhere in two to four mirrored legs, measured for 3
    to 20 participants, under the default and under round parity: 3 and 2
    in every field of even size from 6 up; 4 and 3 with 9, 13 and 17
    participants; the same with 3, 4, 5, 11, 15 and 19 (2, 3, 4, 3, 3, 3);
    3 and 4 with 7. Code that relied on round parity to keep such runs at
    two in a field of even size gets three by default. Add
    `ConsecutiveRoleConstraint::homeAway(3)` to rule out the run of four:
    the scheduler then finds a balanced first leg without it, in every
    field from 3 to 20 over one to four legs. `homeAway(2)` rules out the
    run of three as well, and is found for every field of even size from 6
    up and of odd size up to 11. With 13 or more participants of odd size
    the search stops at its bound, and round parity breaks that limit there
    too, so the schedule fails, as it did in 0.2.x; four participants have
    no schedule of two mirrored legs under it at all (see **Added**).
  - **Role constraints are answered differently.** A constraint sees the
    balanced roles first, and where it rejects them the scheduler looks for
    other balanced roles (see **Added**) and then falls back. Measured
    against 0.2.x under default options: 40,888 configurations in fields of 2 to 20 (1,520 without
    constraints; the library's role limits alone and beside rest, repeat
    and seed-protection rules; limits, pinned roles and metadata rules
    written as closures, as a class and a subclass of your own, and inside
    a subclass of `ConstraintSet`; one to four legs, the three strategies,
    with and without a `Randomizer`, `backtracking` on and off in the
    small fields). Without a `Randomizer`, no configuration that completed
    fails. Of the 22,106 that completed in 0.2.x, 22,003 complete: about
    four in five with balanced roles and the others with a fall-back, and
    every fall-back without a `Randomizer` has the events 0.2.x gave it.
    The other 103 hold
    a `Randomizer` and fail: each fails under `RoundParityRoleAssignment`
    too, because its later legs now derive from the first (the entry under
    "Output change (fix)"). Of the configurations that failed in 0.2.x,
    9,034 complete with balanced roles. With the library's own constraints
    and nothing else (8,464 of the configurations) none falls back: each
    is balanced or fails as it did. A constraint that itself puts a
    participant out of balance in a leg, such as one that names a
    participant first in both of its events in a field of three, or second
    in all three in a field of four, completes as it did where the
    round-parity roles agree with it, marked.
  - **A limit on roles written as a closure or a class of your own gets
    balanced roles only where they are found within 4,000 events.** The
    scheduler knows what the library's two role limits read and goes
    straight back to the event that matters; a constraint of yours does not
    say, and a search for it goes back one event at a time and can run to
    its bound in a league of ordinary size. So where such a constraint
    rejects the balanced roles and the round-parity roles complete the
    schedule, the search for other balanced roles is the one
    `BalancedRoleAssignment` makes, held to 4,000 events put to the
    constraints. A schedule it finds within them is the one
    `BalancedRoleAssignment` gives; otherwise the default returns the
    round-parity schedule, marked `balanced_roles_rejected`, or
    `no_balanced_roles` where the search went through every choice.
    Measured with a closure
    that does what `ConsecutiveRoleConstraint::homeAway(2)` does, two and
    four mirrored legs, default options, every field of even size measured
    from 6 to 40: 6 and 8 participants get balanced roles (in 2.5 and 23 ms
    over two legs, where 0.2.x took 0.3 and 0.6 for its round-parity
    schedule), and from 10 up the default returns the schedule of 0.2.x,
    marked, after asking the closure 3,271 to 5,481 more questions than
    0.2.x asked it: over two legs in 47 ms for 1.3 with 10 participants,
    54 for 2.2 with 12, 90 for 11.3 with 20 and 304 for 104 with 40, and
    over four in 63 ms for 8.8 with 12 and 677 for 460 with 40. In a sweep
    of 31,164 configurations (fields of 2 to 40, the constraints above), a
    fall-back under a constraint of your own took 33 ms at the median with
    10 participants, 83 with 20 and 313 with 40, of which the search for
    balanced roles is 30 to 85 ms; 22,101 of the 22,185 configurations that
    `BalancedRoleAssignment` balances were balanced by the default, with
    the same schedule, and the other 84 fell back. What to change, if such
    a schedule should be
    balanced: use the library's constraint where it says the same
    (`ConsecutiveRoleConstraint`, `RoleBalanceConstraint`), which is
    searched exactly and found for those fields; or name
    `BalancedRoleAssignment`, which searches to its bounds for a constraint
    of yours too (it finds 6, 8 and 10 participants over two legs, and
    stops at a bound, after 1 to 13 seconds, from 12 up). What to change,
    if the time matters more than the balance: name
    `RoundParityRoleAssignment`. A rule of yours
    that fixes the role of a pairing whatever came before is solved for
    and not searched for, with a schedule in hand as without, as far as
    the 4,000 events go.
  - A `RoleBalanceConstraint` limit that the balanced roles need is lower:
    1 for a single leg of a field of even size and 2 for one of odd size,
    where round parity needs 3 and 4. Repeated legs of balanced roles are
    as many apart as there are legs, so a limit of 3 or 4 that two repeated
    legs broke under round parity now holds.
  - **A schedule that has no balanced roles can take longer**, to fall back
    or to fail, because balanced roles are looked for first. Under the
    library's own constraints that is the whole search of first-leg roles:
    up to 20,000 rejected roles and 200,000 events put to the constraints
    in a call, the passes that fix the seatings a constraint rejects
    included, and with `backtracking: true` the step budget of a
    backtracking search for a balanced first leg. Measured: one to seven
    seconds for a limit of two in a row over two to four mirrored legs of
    13 to 29 participants of odd size, where the search stops at its bound
    and the schedule then fails as it did. Under a constraint of your own
    the round-parity roles are tried before the search, and with their
    schedule in hand what is still spent on balanced roles is held to 4,000
    events put to the constraints (not so the search of the later legs of
    a shuffled schedule, which keeps its own bound of 20,000 rejected
    roles; the usage guide, "Balanced where possible", has what that
    comes to); with none in hand, a schedule that
    failed in 0.2.x fails after the search as well: a role pinned in every leg over
    two mirrored legs, which no schedule has, fails in 0.3, 0.6 and 1.4
    seconds with 8, 12 and 20 participants, where 0.2.x failed in 4, 18
    and 128 milliseconds. A
    schedule that completes with the roles as given costs what it did, and
    so does one that fails because of who meets whom and when under
    `NoRepeatPairings`, `MinimumRestPeriodsConstraint` or
    `SeedProtectionConstraint`: those read no role, and no search is made
    for them. Under `ShuffledLegStrategy` such a failure does take longer
    than it did: every rotated order is laid out for the balanced roles and
    again for the roles of round parity, so that the draws, and with them
    the failure, are those of `RoundParityRoleAssignment`. Measured over
    two shuffled legs: 35 ms for 23 with 16 participants and 141 for 89
    with 24 where a pair may not meet again within more rounds than a leg
    has, and 65 for 57 and 410 for 361 where the top two seeds are
    protected in every round.
  - **A schedule balanced by a search costs more than its round-parity
    schedule did.** Where the balanced roles as
    given break a constraint, every rotated participant order is tried with
    them before other balanced roles are searched for, so that a schedule a
    rotated order completes is the one it was. Two mirrored legs under
    `ConsecutiveRoleConstraint::homeAway(2)`, which round parity keeps
    without a retry: 14 ms for 2.3 with 12 participants, 86 for 12 with
    20, 350 for 42 with 30 and 860 for 108 with 40, six to eight times the
    time of 0.2.x for a schedule that is balanced where that one was not.
    Name `RoundParityRoleAssignment` where the time matters more than the
    balance.
  - **Failure messages.** Under the default every message is the one
    `RoundParityRoleAssignment` gives, which is the one 0.2.x gave for the
    same failure: nothing about balance is added, and `getAnalysis()` and
    `getViolationCollector()` are those of that failure. Under
    `BalancedRoleAssignment`, named, a failure after a search of first-leg
    roles ends with what was searched: "The roles of the first leg were
    then searched within what <role assignment> keeps, in each of the N
    layouts tried, and no choice of them has a schedule the constraints
    accept. The pairings and their rounds were not searched:
    RoundRobinOptions(backtracking: true) searches them.", or "…and the
    search stopped at a bound (20000 rejected roles, or 200000 events put
    to the constraints, over all the layouts). Roles that satisfy the
    constraints may exist beyond it; …". Both go on to name the way out
    for a constraint that needs roles the role assignment does not keep:
    "Roles that <role assignment> does not keep were not searched either:
    RoundRobinOptions(backtracking: true, roleAssignment: new
    RoundParityRoleAssignment()) searches every choice of roles." Where the
    constraint that rejects is one of the library's that read no role
    (`NoRepeatPairings`, `MinimumRestPeriodsConstraint`,
    `SeedProtectionConstraint`), nothing is searched and the message ends
    "No choice of roles for the first leg changes that: in each of the N
    layouts tried the constraints reject an event for who meets whom and
    when, whichever participant is first-named. The pairings and their
    rounds were not searched: …". The sentence "The roles of leg 1 were not
    searched: …" is left out of the message of a later leg when that search
    follows. With `backtracking: true` the finding reads "…the constraints
    reject the first leg, or the later legs that <strategy> derives from
    it, in each of the N round decompositions of the first leg it tried,
    whatever roles <role assignment> keeps for the first leg, so the
    configuration is unsatisfiable with this leg strategy and this role
    assignment". The exception of the last layout with the roles as given
    is the `getPrevious()` of such a failure, and `getAnalysis()` and
    `getViolationCollector()` are filled as before. (In 0.2.2 and 0.2.3
    `BalancedRoleAssignment` failed without a search of the first leg, with
    the message of the last layout.)
  - **A constraint that counts its calls is asked more often.** The
    default generates a constrained schedule up to three times in a call,
    and under a constraint of yours searches for balanced roles with up to
    4,000 more events once the round-parity roles have completed the
    schedule. The usage guide asks for pure predicates ("What a constraint
    may rely on"), and what is said above holds for those. Every question
    of those generations is one a generation asks: an event with the events
    laid out before it, so a constraint that relies on the first leg being
    there for an event of a later leg (the first meeting of a pair, read
    for the return meeting) is not asked without it by any of them. The
    analysis of a failed attempt asks what the usage guide says it asks,
    under every role assignment: each missing pairing in every round,
    against the events of that attempt.

  **Golden fixtures.** Every fixture of a round robin with default options
  changes, in roles only: `round-robin/mirrored-unseeded.txt`,
  `round-robin/mirrored-seed-1.txt`, `round-robin/mirrored-seed-42.txt`,
  `round-robin/mirrored-seed-1337.txt`, `round-robin/repeated-unseeded.txt`,
  `round-robin/repeated-seed-1.txt`, `round-robin/repeated-seed-42.txt`,
  `round-robin/repeated-seed-1337.txt`, `round-robin/constrained.txt`
  (which also gains one section, a backtracking search around pinned
  rounds), `wire/schedule.json`, and the examples that print a round robin
  or something computed from one: `examples/01-basic-round-robin.txt`,
  `02-participants-and-metadata.txt`, `03-iterating-schedules.txt`,
  `04-basic-constraints.txt`, `05-seed-protection.txt`,
  `06-rest-periods.txt`, `07-metadata-constraints.txt`,
  `08-custom-constraints.txt`, `09-multi-leg-home-away.txt`,
  `10-complex-tournament.txt`, `11-error-handling.txt`,
  `15-timeline-assignment.txt`, `17-schedule-optimization.txt`,
  `21-standings-and-tiebreakers.txt`,
  `23-application-adapter-and-repack.txt` and
  `25-awarded-results-and-adjustments.txt`. No schedule in them falls
  back, and the failure messages the examples print are those of 0.2.x
  (`16-backtracking-generation.txt`, which prints a failure and a searched
  schedule, does not change at all). The fixtures that pinned the
  opt-in are replaced by fixtures that pin the old default by name:
  `round-robin/balanced-mirrored-unseeded.txt`,
  `balanced-mirrored-seed-42.txt`, `balanced-repeated-unseeded.txt` and
  `balanced-constrained.txt` are removed, since the default fixtures now
  hold that output, and `round-robin/round-parity-mirrored-unseeded.txt`,
  `round-parity-mirrored-seed-42.txt`, `round-parity-repeated-unseeded.txt`
  and `round-parity-constrained.txt` are added, each with the body the
  default fixture of the same name had before this change.
- **`LegStrategyInterface::planLegs()` can be called more than once in a
  `schedule()` call.** It was called once, before any event was generated.
  Under the default role assignment the scheduler can generate a
  constrained schedule up to three times in a call (the entry above), and
  each run has to draw what a call draws, so a leg strategy that says it
  needs randomization (`LegPlanContribution::$requiresRandomization`) is
  asked for its plan again before each further run, with the same
  arguments. That is where a `ShuffledLegStrategy` built from a seed
  starts its draws again. Who is affected: a leg strategy of your own that
  says so and does more in `planLegs()` than state its facts. What to
  check: that calling it again is harmless, and that a strategy which
  draws from a seed starts its draws there. The plan of the call is still
  built from the first answer, and a strategy that does not say it needs
  randomization, or a schedule under a role assignment that was named, is
  asked once, as before.
- **Breaking: `RoleAssignmentInterface` (experimental) has a second
  parameter and a second method.** The signature is
  `assignRoles(array $rounds, RoleAssignmentContext $context): array`,
  where it was `assignRoles(array $rounds): array`, and
  `rule(): ?ConstraintInterface` is new. Who is affected: a class that
  implements the interface, and code that calls `assignRoles()` itself.
  What to change: add the parameter (an implementation that does not need
  it ignores it), and add `public function rule(): ?ConstraintInterface {
  return null; }`, which keeps the behaviour of 0.2.x exactly: the roles the
  class gives stand, and are searched with `backtracking: true` only. A
  caller passes `new RoleAssignmentContext()` for a single leg. Returning a
  rule is the opt-in to the search described under **Added**; the usage
  guide says what a rule must be ("Writing a role assignment").
  `BalancedRoleAssignment` and `RoundParityRoleAssignment` are changed with
  the interface; with a context that fixes no seating both return the
  roles they returned. A class written for 0.2.x fails to load until it is
  changed ("Declaration of ... must be compatible with ..."), so nothing
  runs with the old signature unnoticed. Before and after, for a class
  that needs neither the context nor a rule:

  ```php
  // 0.2.x
  public function assignRoles(array $rounds): array
  {
      // ...
  }

  // 0.3.0
  public function assignRoles(array $rounds, RoleAssignmentContext $context): array
  {
      // ... as before
  }

  public function rule(): ?ConstraintInterface
  {
      return null;
  }
  ```

  The parameter is required and the method is in the interface, not in an
  optional second one, so that the interface has the shape it is meant to
  keep before it leaves the experimental surface: a parameter added later
  would break every implementer again.
- **Byes and adjustments are refused for a table that exists where a
  tiebreaker depends on them.** `StandingsCalculator::withByesCredited()`
  keeps the tiebreaker values of the table it is given, and so does
  `withAdjustments()`. A calculator with a tiebreaker that reads byes (a
  bye treatment other than `Uncounted`) is refused a bye by
  `withByesCredited()`, and one with a tiebreaker between the tied
  participants (`TiedParticipantsTiebreaker`) is refused a bye by
  `withByesCredited()` and an adjustment by `withAdjustments()`, each with
  an `InvalidConfigurationException` (reason `IncompatibleOptions`) that
  names `calculateWith()`, which takes both with the results. Who is
  affected: nobody with the tiebreakers of an earlier release at their
  defaults; a calculator that names one of the new tiebreakers or
  treatments and applies byes or adjustments in a second step. Nothing to
  credit and nothing to adjust is never refused.
- **Signatures.** Additive for callers. `WinDrawLossRanking::__construct()`
  has a fourth parameter (`byeValue`), `StandingEntry::__construct()` a
  thirteenth (`rankingValueOfResults`), and `BuchholzTiebreaker` and
  `SonnebornBergerTiebreaker` have a constructor (`byes`), where they had
  none: a subclass of either that declares a constructor of its own is not
  affected. `WinDrawLossRanking` implements `RankingStrategyWithByeValue`
  and the two tiebreakers implement `TiebreakerWithByeTreatment`; both
  interfaces are optional, so a `RankingStrategy` or a
  `TiebreakerInterface` of your own needs no change and is treated as it
  was. `StandingsCalculator::withAdjustments()` and
  `StageOutcome::combining()` declare an `InvalidConfigurationException`
  they did not declare: the first throws it in the case above, the second
  does not throw it with a calculator of the library (static analysis that
  checks exceptions asks a caller to declare or catch it). A class that
  extends `StandingsCalculator`, `Standings` or `Schedule` and declares a
  method named `calculateWith`, `fromArray`, `toArray`, `getRank`,
  `getRanks`, `getByesByRound` or `getByeCounts` with another signature is
  affected. The engines call `calculateWith()`; a subclass of
  `StandingsCalculator` is still calculated through its own `calculate()`,
  `withByesCredited()` and `withAdjustments()`.
- **The score groups of a Swiss stage allow for the value of a bye.** The
  rounding allowance of the Swiss engine (two win/draw/loss totals that
  differ by no more than the rounding of a float sum are level) reads the
  value of a bye as one more value of the scale. With a bye worth a win,
  which is every stage paired before, nothing changes.
- **The message of an undecided tie names the decider.** A level
  elimination tie with no decision is refused as before, with the reason
  `UndecidedTie`; its message told the caller to record `'tie_winner'`
  metadata, which is deprecated (see **Deprecated**), and now says to
  record the decider: "... the event is level, so record who advances as
  the decider of its result", and for two legs "... the legs are level, so
  record who advances as the decider of one leg's result". Code that
  branches on the reason is unaffected; code that matched the message text
  on `tie_winner` is not, and should branch on the reason. Golden fixture:
  `examples/24-recording-bracket-results.txt` (that one line; the example
  now records a decider and prints the results it printed).
- **Breaking: an overall rank selection across pools of unequal size is
  refused until a comparison method is chosen.**
  `RankRangeSelector::overall()` reads the combined table of a pooled
  outcome, which compares raw totals: across pools of 4 and 3 entrants, 9
  points from 3 events ranked level with 9 from 2, silently. `select()` now
  throws an `InvalidConfigurationException` with the reason
  `PoolComparisonRequired` for an outcome whose pools do not have the same
  number of entries, with the pool sizes in the context (`pool_sizes`),
  whatever the tie policy of the selector. Who is affected: every use of
  `overall()` on a pooled outcome with pools of unequal size. Migration:
  `RankRangeSelector::overall(1, 8)->comparedBy(PoolComparison::RawTotals)`
  (`'comparison' => 'raw-totals'` in plain data) selects exactly what the
  selector selected before; `PoolComparison::PerEvent` compares per event
  played instead. Before: `RankRangeSelector::overall(1, 8)->select($outcome)`
  returned eight entrants for pools of 4, 4 and 3. After: it throws, and
  with `->comparedBy(PoolComparison::RawTotals)` it returns the same eight.
  Unchanged: an outcome without pools, pools of one size, and every
  per-group selection (#94). ADR 0006
  (`docs/adr/0006-pools-of-unequal-size-are-compared-by-a-method-the-caller-names.md`)
  records the decision, what counts as unequal (the number of entries of
  the pools' tables, not the events played) and what a comparison per
  event does and does not do with tiebreaker values.
- **Breaking: every array a `toArray()` returns starts with
  `format_version`, and a version the library does not know is refused.**
  No serialized form said which release's rules it was written under, so
  the first change to a form would have left a reader unable to tell an
  old array from a new one. Every form now states its version, an integer,
  as the first key of the array: the options classes
  (`RoundRobinOptions`, `SwissOptions`, `PotDrawOptions`,
  `EliminationOptions`, `RepackOptions`), `WinDrawLossRanking`,
  `AwardedResultPolicy`, `TimelineDefinition`, `SessionGrid`,
  `BlackoutRule`, `MinimumRestRule`, `RankRangeSelector`,
  `BestOfRankSelector`, `MatchOutcomeSelector`, `Participant`, `Round`,
  `Event`, `Result`, `Award`, `Schedule`, `RoundPairing`, `StageState`,
  `Adjustment`,
  `ScheduledEvent`, `ScheduledSchedule`, `RepackOutcome`, `SlotAssignment`,
  `UnplacedEvent` and the five repack violations. Every form is at version
  1 (#53). ADR 0005 (`docs/adr/0005-serialized-forms-carry-a-format-version.md`)
  records the rule:
  - The key is written once to an array, at its top. A form held in
    another (the participants and events of a schedule, the round of an
    event, the rounds, results and adjustments of a stage state, the award
    of a result, the records of a repack outcome) is written without it, so the size of a stored schedule grows
    by one key and not by one key per event.
  - **Data stored by 0.2.x loads unchanged.** An array without the key is
    read as version 1, which is the form 0.2.x wrote and, beside it, the
    optional keys this release adds (`strategy_seed`, `seed` in Swiss
    options, `ties` and `comparison` in a rank range selector, `award` in a
    result, `adjustments` in a stage state). Nothing is to be
    migrated and the key need not be added to stored rows.
    `tests/Fixtures/stored-by-0.2/` holds a schedule and a stage state as
    v0.2.0 wrote them and 124 values of every form that is read as v0.2.3
    wrote them, and a test reads them on every run. What 0.2.x wrote and
    0.3.0 does not read is in two other entries of this section: `'strategy'
    => 'shuffled'` without a seed, and a timeline whose slots overrun its
    round.
  - A `format_version` above 1, below 1 or not an integer is refused:
    configuration with an `InvalidConfigurationException` whose reason is
    the new `InvalidConfigurationReason::UnsupportedFormatVersion`
    (`unsupported_format_version`; the context holds `format_version` and
    `newest_supported`), data with an `InvalidInputException`.
  - Forms are versioned one by one. A later release that changes a form
    gives it the next number, says so here, and keeps reading the earlier
    versions; a patch release never changes a form.
  - It is not the `v2` of an engine fingerprint or the `v1` of an outcome
    fingerprint, which version those schemes. The stage state form is
    version 1 whichever scheme its stamp is of, and
    `RepackOutcome::fingerprint()` does not cover the key, so the key
    changes no fingerprint.

  Who is affected: code that compares a `toArray()` result or a `toJson()`
  string with a literal, that validates it against a schema of its own, or
  that iterates the keys of the array. What to change: expect the key.
  Before: `$options->toArray() === ['rounds' => 5]`. After:
  `$options->toArray() === ['format_version' => 1, 'rounds' => 5]`. Code
  that reads single keys (`$schedule->toArray()['events']`) and code that
  passes a stored array back to `fromArray()` needs no change.

  Rolling back: an array written by 0.3.0 is still read by 0.2.x, which
  cannot check the version and ignores the key, as it ignores every key it
  does not know (`strategy_seed`, `seed` in Swiss options, and `ties` and
  `comparison` in a rank range selector, and before 0.2.2 `role_assignment`
  and `engine_fingerprint`, are read as left out; so are `award` and
  `adjustments`, which means 0.2.x reads an awarded result as one that was
  played and a stage state without its adjustments). The one exception is
  `PotDrawOptions::fromArray()` of 0.2.2 and 0.2.3, which refused unknown
  keys already and refuses `format_version` (`Unknown pot draw option`):
  remove the key from stored pot draw options before reading them with
  those releases. A schedule written by 0.3.0 and written again by 0.2.x
  has its participants back in the order of first appearance.

  Golden fixtures: `tests/Fixtures/golden/wire/schedule.json` and
  `tests/Fixtures/golden/wire/stage-state.json` gain `"format_version":1`
  as their first key and are otherwise byte for byte what they were;
  `tests/Fixtures/golden/examples/18-stateless-web-flow.txt` reports each
  stored state 19 bytes longer, the length of that key. No other fixture
  changes: the repack fixtures and the examples that print violations
  render the records as the outcome writes them, which carry no key of
  their own.
- **Breaking: `StageState::fromArray()` and `fromJson()` refuse a history
  the verbs refuse.** The stored data was checked for its shape and its
  history was trusted: rounds in any order, a pairing filed under another
  round number than its events carry, a result of a round that is not
  recorded. An engine then replayed that history as if `withRoundPlayed()`
  had recorded it. A stored state is now recorded again through
  `withRoundPlayed()` when it is read, so what the verb refuses of a live
  state is refused of a stored one, with the same
  `InvalidConfigurationException` and reason: `ValueOutOfRange` for a
  round number below 1 (the verb's own check is new, under Fixed),
  `RoundOutOfSequence` for rounds out of play order or a round number
  twice, `EventNotInRound` for
  an event or a result that does not belong to its round, a result of a
  round that is not recorded and a result that does not stand with the
  results of its round, and `EventWithoutRoundNumber` for an event or a
  result's event with no round. What the verbs accept still loads: a gap in
  the round numbers, a pairing whose participants are not active, and two
  results of one event, which has to load so that `withResultReplaced()`
  can repair it. Who is affected: an application whose stored states were
  written or edited by something other than `toArray()` or `toJson()` (a
  migration script, a hand-built array), and code that catches only
  `InvalidInputException` around `fromArray()`. A state the library wrote
  is always one the verbs recorded and loads as before, whichever release
  wrote it. What to change: catch `InvalidConfigurationException` as well
  (`catch (TacticianException)` covers both), and rebuild a refused state
  from your own rows with `StageState::start()` and `withRoundPlayed()`.
  Before: `StageState::fromArray(['rounds' => [$roundTwo, $roundOne], ...])`
  returned a state. After: it throws, reason `RoundOutOfSequence`. Both
  methods declare one more `@throws`, which code analysed with checked
  exceptions sees (#53).
- **Breaking: `StandingsCalculator::calculate()` recognises two results of
  one event by what the events say, and no longer by the object alone.** It
  refused two results that held the same `Event` object and counted any
  two that did not. An `Event` object does not survive storage, so a result
  recorded twice (the same request sent twice, say) was refused on the live
  state and counted twice once the state had been through `toJson()` and
  `fromJson()`: one match gave two wins, and a Swiss stage paired its next
  round from that table. Two results are now of one event when they hold
  the same object, or when their events carry the same round number, the
  same participants in either order and the same tie leg (the `tie_leg`
  metadata; an event without it is leg 1), which is how `StageState` and
  the bracket engines already recognise an event. Both are refused with
  the `InvalidInputException` the calculator already threw; where the
  event has a round, its message now goes on with the round and the
  participants and with what to do when the two results are in fact of two
  events. The Swiss engine, which pairs from the table, refuses such a
  state before and after storage alike, and `withResultReplaced()` repairs
  it, also in a state that came back from JSON. An event with no round has
  nothing but the object to tell it from another meeting of the same
  participants, so two such events are still two events played. Who is
  affected: code that passes one `calculate()` call results that share
  round numbers between the same participants and are in fact different
  events, which the library's own generators and engines never produce
  within one schedule or one stage: an all-time table over two seasons
  generated alike, or over two stages that each count their rounds from 1.
  What to change: calculate a table for each and combine them, give the
  later events round numbers that continue from the earlier ones, or give
  events of one round that are two meetings a `tie_leg` each. Before:
  `calculate($field, [...$seasonOne, ...$seasonTwo])` returned a table.
  After: it throws at the first pair that met in the same round of both
  (#53).
- **Breaking: a serialized schedule lists its participants in entrant
  order, and `Schedule::fromArray()` refuses a participant listed twice.**
  A schedule did not hold the list it was generated from. `toArray()` wrote
  the participants in the order they first appear in the events, so five
  entrants `p1` to `p5` were written, and read back, as `p2, p5, p3, p4,
  p1`, and the seeding, which is the order of the list, could not be
  recovered. A schedule now keeps its entrants (see `getEntrants()` under
  Added), and the `participants` of the array are that list in its order,
  an entrant in no event included. The key, its shape and the events are
  unchanged, so the form is still version 1. Who is affected: code that
  relies on the order of `participants` in a serialized schedule, or that
  compares a stored schedule byte for byte with a newly generated one; and
  data whose `participants` list one ID twice, which was read with the
  later entry winning and is now refused (`toArray()` never wrote that).
  What to change: nothing for reading; a comparison of stored JSON with
  new output sees the participants in another order. Schedules that 0.2.x
  stored load unchanged, with their participants, in the order they were
  written, as their entrants: the order the generator was given was not
  written and cannot be recovered. Before, for the three entrants `1, 2,
  3`: `"participants":[{"id":"2",...},{"id":"3",...},{"id":"1",...}]`.
  After: `"participants":[{"id":"1",...},{"id":"2",...},{"id":"3",...}]`.
  Golden fixture: `tests/Fixtures/golden/wire/schedule.json` lists its
  three participants as `1, 2, 3` where it listed `2, 3, 1`; its events and
  metadata are unchanged (#53).
- **Breaking: `new Participant()` refuses an empty ID, and `new Event()`
  refuses a participant listed twice.** `Participant::fromArray()` already
  refused the empty string as an ID and the constructor took it; an event
  could pair a participant with itself, by the same object twice or by two
  objects with one ID, and the plans, the event index and a constraint each
  carried a guard for it. Both constructors now throw an
  `InvalidInputException` (an `\InvalidArgumentException`):
  `A participant ID cannot be empty`, and `An event cannot list participant
  <id> twice: a participant is not paired with itself`. Only the empty
  string is empty: `'0'`, a space and every other string remain IDs, and
  IDs are compared as strings, so `'01'` and `'1'` still make an event.
  `Event::fromArray()`, and so `Schedule::fromArray()`,
  `StageState::fromArray()` and the other readers of events, refuse data
  that lists one ID twice in an event. Who is affected: code that builds a
  placeholder participant with an empty ID (a bye or "to be decided" side),
  and code that builds an event of one participant against itself, for
  instance to stand for a bye. Nothing a scheduler or an engine returns is
  such a participant or such an event, so stored schedules and states the
  library wrote load as before. What to change: give a placeholder an ID of
  its own (`new Participant('bye', 'Bye')`), and do not model a bye as an
  event: the schedulers report byes in the schedule metadata and the
  engines in `RoundPairing::getByes()`. Before: `new Event([$alice,
  $alice])` returned an event. After: it throws. Code analysed with checked
  exceptions sees one more `@throws` on `Participant::__construct()` (#53).
- **Breaking: every `fromArray()` refuses a key its form does not have.**
  A key that was not known used to be ignored, so a misspelt key was read
  as the key left out: `RoundRobinOptions::fromArray(['legs' => 2,
  'stratgy' => 'repeated'])` gave two mirrored legs, and a result stored
  with `winer` in place of `winner` was read as a draw. Both now throw
  (#53).
  - *Configuration* throws an `InvalidConfigurationException` with the
    reason `UnknownOptionKey`, whose context lists the keys that are not
    known under `unknown` and the keys of the form under `known`:
    `RoundRobinOptions`, `SwissOptions`, `EliminationOptions`,
    `RepackOptions`, `WinDrawLossRanking`, `AwardedResultPolicy`,
    `TimelineDefinition`,
    `SessionGrid`, `BlackoutRule` (the rule and each of its windows),
    `MinimumRestRule`, `RankRangeSelector`, `BestOfRankSelector` and
    `MatchOutcomeSelector`.
    `PotDrawOptions` already did; its message is now `Unknown key in pot
    draw options: '<key>'` where it was `Unknown pot draw option`, and its
    reason and context are unchanged.
  - *Data* throws an `InvalidInputException` whose message names the keys
    that are not known and the known ones: `Participant`, `Round`, `Event`,
    `Result`, `Award`, `Schedule`, `RoundPairing`, `StageState`,
    `Adjustment`, `ScheduledEvent` and `ScheduledSchedule`, on their own or nested in one another.

  Who is affected: an application that passes a `fromArray()` an array with
  keys of its own in it (a database row with its `id` and timestamps, a
  form with a CSRF token, an event array decorated with a venue), and one
  whose stored configuration holds a misspelt key, which until now did
  nothing. Arrays that `toArray()` wrote hold no such key and load as
  before. What to change: pass the library's keys only, and keep data of
  your own in the `metadata` of the participant, round, event, result or
  schedule it belongs to, or beside the array. Before:
  `RepackOptions::fromArray($row)` with `$row = ['id' => 7, 'step_budget'
  => 50000]`. After: `RepackOptions::fromArray(['step_budget' =>
  $row['step_budget']])`. A misspelt key is to be corrected: the exception
  names it and lists the keys the form has. A known key given as `null` is
  still read as the key left out wherever it was, and `session_count`
  beside `sessions` in a session grid is still accepted and not read. Code
  analysed with checked exceptions sees no new exception type: each method
  already declared the one it now throws for this.
- **Breaking: a rank selection that results do not decide is refused.**
  `RankRangeSelector::select()` read positions from a table whatever
  decided them. A pool in which every event was drawn, entered as Zed, Yan,
  Bob, Amy, sent Amy and Bob through by the alphabet, and a pool with no
  result at all sent two "qualifiers" on. `select()` now throws an
  `UndecidedSelectionException` (an `InvalidConfigurationException`) when
  the table has no recorded result (reason `UnplayedStage`), when level
  entries hold places on both sides of a boundary of the selected ranks
  (`TieAcrossSelection`), and when level entries are all inside the
  selected ranks, where their order would seed the next stage
  (`TieWithinSelection`). The exception carries the IDs of the level
  participants, the places they hold between them and the label of the
  pool. A tie that touches none of the selected ranks is not refused, and a
  table that results decide is selected from as before. Who is affected:
  every use of `topPerGroup()`, `perGroup()` and `overall()` on a table
  that can hold a tie at the selected ranks, which without a tiebreaker and
  recorded scores is any round-robin pool. What to change: decide the tie
  (a play-off, a tiebreaker on the calculator, the order of the entrant
  list you pass on), or say on the selector that the selection goes on:
  `->withTiesInsideSelectionAccepted()` accepts level entries when all of
  them are selected, and `->withTableOrderAccepted()` accepts the table as
  it stands, which is what 0.2.x did, with level entries in the order of
  the outcome's entrant list in place of the label order. Give a hand-built
  `StageOutcome` its entrant list (the new sixth argument) for that order
  to be yours. Before and after, the drawn pool above and
  `topPerGroup(2)`:

  ```text
  before  Amy, Bob
  after   UndecidedSelectionException: places 1 to 4 of pool A (zed, yan, bob, amy)
          with ->withTableOrderAccepted(): Zed, Yan
  ```

  The selector cannot see that a pool with some results has events still
  to play; check that before selecting, as before. The error for a rank a
  pool does not have (`RankUnavailable`) is unchanged and is raised first.
- **Breaking: the engine fingerprint scheme is `tactician:v2:`, and a
  state stamped by 0.2.x is refused until it is stamped again.** The same
  recorded state can give another next round than it gave under 0.2.x (the
  Swiss and re-seeding entries above), and a fingerprint stands for how a
  state is paired, so the scheme changed as its contract provides for a
  0.x minor. Every fingerprint is the string of 0.2.x with `v2` in place of
  `v1`; no option was added or renamed. An engine refuses a state that
  carries a `v1` stamp, on every call, with an
  `InvalidConfigurationException` (reason `EngineFingerprintMismatch`) that
  says the stamp was written by an earlier release. Who is affected: an
  application that stamps its states (`withEngineFingerprint()`) and has
  stages in progress across the upgrade. A state that was never stamped is
  accepted as before. What a stored state does, format by format:
  - *Fixed-path single elimination and double elimination*: every pairing
    is what it was. The state resolves to the same rounds; only the order
    of the outcome's table is the new one. Stamp it again.
  - *Swiss*: the recorded rounds stand, and the next round is paired by the
    new order (list position between level participants, and the table
    order among participants whose points include a bye). Stamp it again
    once that is what you want; to finish a stage exactly as 0.2.x would
    have paired it, finish it on 0.2.x. Nothing refuses a Swiss state that
    was never stamped: it is paired by the new order without a word. A
    round that 0.2.x paired and you have published, and that is not yet
    recorded, stands only if you record the pairing you kept
    (`withRoundPlayed()` with that `RoundPairing`): asked again,
    `pairNextRound()` can answer with other pairs, and results for the
    published events are then refused as not part of the round
    (`EventNotInRound`).
  - *Re-seeded single elimination*: the bracket is replayed from its
    entrant list on every call. Where 0.3.0 pairs a recorded round as
    0.2.x did, the recorded rounds stand, and the next round is paired by
    the new order, which can be another round than 0.2.x would have made
    of the same results. Where it does not (a field
    that is not a power of two, or level survivors whose list order is not
    their seed and label order), the engine refuses the state, stamped or
    not, with the new reason `RecordedRoundMismatch`, on `pairNextRound()`,
    `isComplete()` and `getOutcome()`: the recorded results belong to ties
    the bracket no longer has. Finish such a bracket on 0.2.x, or rebuild
    the state from the rounds that stand.

  What to change: after the upgrade, `$state =
  $state->withEngineFingerprint($engine->getFingerprint())` for each stored
  stamped state you have decided to carry on.
- **Breaking: a single-elimination state whose recorded round lacks a tie
  of the bracket is refused** (`RecordedRoundMismatch`). The engine used to
  answer such a state with a round the state already held under other
  pairings, which `withRoundPlayed()` then refused as out of sequence. That
  is the refusal the entry above relies on; it also meets a state whose
  participant list changed after a round was recorded (a withdrawal, which
  the bracket engines do not support). A recorded round that holds more
  than the bracket's ties is still accepted.
- **Breaking: a re-seeded bracket that has a bye needs a ranking strategy
  that says what a bye is worth.** Crediting the bye needs a value for it, so
  `SingleEliminationEngine` with `reseedEachRound` and a `RankingStrategy`
  that is not a `RankingStrategyWithByeValue` (new; `WinDrawLossRanking`
  is one) throws an
  `InvalidConfigurationException` (reason `IncompatibleOptions`) for a
  field that is not a power of two. The field says that the bracket has
  byes, so every call refuses it, `pairNextRound()` for the first round
  included: the bracket is not started and then left without a second
  round. A field that is a power of two, and a bracket on a fixed path, are
  not affected. What to change: implement `RankingStrategyWithByeValue` on
  the strategy, use a `WinDrawLossRanking`, or use a fixed
  path. The Swiss engine refuses later, as it did: it pairs a round that
  has a bye and refuses to pair the round after it (the message now begins
  "Crediting a bye requires a ranking strategy that says what a bye is
  worth"), and
  `SwissPairingEngine::getOutcome()` raises it too, where it used to return
  a table without the bye. A Swiss stage whose only bye is in its last
  round (one planned round, or a withdrawal before the last) therefore has
  no outcome on such a scale: build its table with your own calculator
  from the state's results.
- **Signatures.** Additive, and source-compatible for callers: the
  optional parameters named under **Added** (`Standings::__construct()`,
  `StandingEntry::__construct()`, `StageOutcome::__construct()`,
  `StageOutcome::combining()`, `SwissOptions::__construct()`).
  `Standings::__construct()` throws an `InvalidInputException` for tiers
  that do not fit its entries, and `RankRangeSelector::select()` and
  `SwissScheduler::schedule()` each declare one more case of a checked
  exception they already declared. A class that extends
  `StandingsCalculator` or `Standings` and overrides a constructor is not
  affected; one that declares a method named `withByesCredited`,
  `withTiedSetsOrderedBy`, `getTiers` or `getByes` with another signature
  is.
- **Breaking: `ShuffledLegStrategy` is refused without a source of its
  draws.** `new ShuffledLegStrategy()` used to make an unseeded
  `Random\Randomizer` for every event, so a schedule with shuffled legs
  differed from run to run whatever the scheduler was seeded with, and no
  seed could repeat it. It now throws an `InvalidConfigurationException`
  (reason `IncompatibleOptions`), as does a strategy given both a
  randomizer and a seed. Nothing in the library makes an unseeded
  randomizer any more. The constructor is
  `__construct(?Randomizer $randomizer = null, ?int $seed = null)`: the
  second parameter is new, and exactly one of the two must be given. A
  strategy given a `Randomizer` draws exactly what it drew before, so no
  schedule that was repeatable changes. Who is affected: code that calls
  `new ShuffledLegStrategy()` with no argument, and a class that extends
  the strategy and calls its constructor that way. What to change: pass a
  seed (`new ShuffledLegStrategy(seed: $seed)`) for a schedule that repeats
  on every call, or a seeded `Randomizer` as before; for roles that differ
  from run to run, which is what the old default gave, pass
  `new ShuffledLegStrategy(new Randomizer())`. Code analysed with checked
  exceptions sees one more `@throws` on the constructor.
- **Breaking: `RoundRobinOptions::fromArray()` refuses `'strategy' =>
  'shuffled'` without a `strategy_seed`, and a `strategy_seed` with any
  other strategy.** Both throw an `InvalidConfigurationException` with the
  reason `IncompatibleOptions`; a `strategy_seed` that is neither an
  integer nor null has the reason `WrongValueType`, and null is read as the
  key left out. The first used to build the unseeded strategy above.
  The second is refused because a seed
  beside a strategy that draws nothing would decide nothing and
  `toArray()` could not give it back. Who is affected: stored
  configuration that says `'strategy' => 'shuffled'`. What to change: add
  `'strategy_seed' => <integer>` to it; any integer will do, and keeping
  it is what makes the schedule repeatable. Data for the mirrored and the
  repeated strategy is read as before.
- **Breaking: `RoundRobinOptions::toArray()` refuses a shuffled strategy
  that holds a `Randomizer`**, with the reason `NotSerializable`. It used
  to write `'strategy' => 'shuffled'` and drop the randomizer, and
  `fromArray()` rebuilt the options with an unseeded strategy. The state of
  a randomizer has no plain-data form. Who is affected: code that
  serializes options built with `new ShuffledLegStrategy($randomizer)`.
  What to change: build the strategy from a seed when the options are to be
  stored. For a strategy built from a seed the array has one more key,
  `strategy_seed`; for the two other strategies it is unchanged.
- **Breaking: the messages of a round-robin failure in a later leg.** They
  now say what was searched and whether a bound stopped the search, and
  only an exhausted backtracking search says "unsatisfiable". Who is
  affected: code that matches the message text. What to change: match the
  exception class, and read `getViolationCollector()` and `getAnalysis()`.
  - With `backtracking: true`, "Backtracking found a first leg, but
    constraints reject its leg N derivation and the search does not cross
    leg boundaries" is gone. In its place: "Backtracking search exhausted
    the search space: the constraints reject the later legs that <strategy>
    derives from each of the N round decompositions of the first leg it
    tried, whatever the roles of the first leg, so the configuration is
    unsatisfiable with this leg strategy"; the same finding with
    "exhausted its step budget" when the budget ran out first and the
    search had been through every choice of roles of each first leg it
    tried, and otherwise "exhausted its step budget … It tried M round
    decompositions of the first leg: for N the constraints reject the later
    legs …; for N the search for roles stopped at a bound (20000 rejected
    roles, or the step budget)", which claims the first only of the first
    legs it was shown for; "…for N of
    the M it tried the search for roles stopped at its bound of 20000
    rejected roles" when a role search was stopped; "…without finding a
    complete schedule that <role assignment> keeps" when roles exist that
    the role assignment would change; and "…<strategy> returned no event
    for A vs B in round R of leg N" for a strategy that returns none.
  - Without it, for a strategy whose roles are preferences (shuffled
    legs), "Failed to generate complete schedule for leg 2. Generated N
    events, expected M…" becomes "Failed to generate complete schedule for
    leg 2: the constraints reject every choice of roles for the events of
    leg 2 in this layout…", or "…the search for roles the constraints
    accept stopped at its bound of 20000 rejected roles…". Both end by
    naming the option that searches what they did not:
    "RoundRobinOptions(backtracking: true) searches them".
  - Where one of these messages names the leg strategy or the role
    assignment, an anonymous class is named after what it implements
    (`…\LegStrategyInterface@anonymous`), without the file it is declared
    in.
  - Without it, for a strategy whose roles are rules (mirrored and
    repeated legs, and a strategy of your own that says nothing), the
    message of a later leg that ends short is what it was, with one
    sentence added when a `ConsecutiveRoleConstraint` or a
    `RoleBalanceConstraint` rejected one of its events: "A constraint on
    roles (<name>) rejected an event of leg N in the roles <strategy> gives
    it, which follow from the roles of leg 1 by a rule. The roles of leg 1
    were not searched: RoundRobinOptions(backtracking: true) searches
    them." (Under `BalancedRoleAssignment`, which states a rule, the roles
    of leg 1 are searched and the sentence is left out. Under the default
    the message is the one round parity gives, with the sentence: see the
    entry on the default above.) Nothing is added when the rejection came from any other
    constraint: a closure or a class of your own does not say whether it
    reads roles.
  - `getActualEventCount()` of such a failure is the number of events in
    place when the search was furthest on, and the violation collector
    holds the event it could get no further than, once for each role the
    constraints rejected.
- **A failing backtracking search over several legs takes longer.** It
  used to stop at the first leg it found; it now goes through first legs
  and roles until its step budget is spent (seconds, where it was
  milliseconds: up to 6 seconds measured for 16 to 24 participants and
  three or four legs that no roles satisfy). A configuration that
  succeeded takes what it took.
- **A failing schedule of shuffled legs can take longer without
  backtracking too.** Where it used to fail at the first event the
  constraints rejected, the roles of the later legs are now searched, in
  every rotated participant order the scheduler tries (up to 25), each up
  to the bound of 20,000 rejected roles. That costs most with a constraint
  set that holds a closure, a metadata rule or a class of your own, which
  is searched one event at a time: 24 participants and two shuffled legs
  under a limit of two in a row, beside a closure that closes the second
  leg to one pairing, failed in 17 seconds before and fail in 49 now (103
  with four legs); 16 participants in 1.8 seconds before and 14 now.
  Every such search is bounded by a count and not by time, and the failure
  names the bound when one stopped it.
- **A role search uses memory in proportion to the square of the number
  of events.** It keeps the context of every round it has placed (of every
  event, with backtracking) to go back to. Who is affected: a schedule of
  shuffled legs with a constraint set, and a backtracking search over
  several legs, in a large field. Four shuffled legs under a limit of two
  in a row take 16 MB for 24 participants, 52 MB for 40 and 120 MB for 56,
  where 40 participants without constraints take 6 MB; four repeated
  legs with backtracking take 84 MB for 32 participants. What to check:
  PHP's `memory_limit` for a field of that size
  (`docs/design/backtracking-generation.md`, "Bounds").
- **Constraints and leg strategies are asked more often in a search.** A
  backtracking search over several legs puts the events of each first leg
  it finds to the constraints again when it searches the roles, and asks a
  mirrored, repeated or custom rule strategy again for a later-leg event
  whenever the search has gone back past it. A strategy of preferences is
  asked once for each event of a layout, as before. Who is affected: a
  constraint or a strategy that counts its calls, which the usage guide
  already excludes for constraints ("What a constraint may rely on").
- **A timeline whose rounds overlap is rejected.** Nothing checked that the
  slots of a round come before the next round's first slot, so the same
  resource and the same participants could be given two events at one
  kickoff. `new TimelineDefinition(...)` and `TimelineDefinition::fromArray()`
  now throw an `InvalidConfigurationException` (`OverlappingTime`) when the
  first round's last slot is at or after the second round's first slot, and
  `TimelineAssigner::assign()` and `assignRound()` throw the same for a
  round they assign whose last slot is at or after the first slot of the
  next round that is played
  (a short month or a clock change can shorten a later round). Before and
  after: hourly rounds (`PT1H`) of two slots an hour apart were built, and
  round 1 slot 1 and round 2 slot 0 were both 19:00; the definition is now
  refused. Who is affected: a timeline with more than one slot per round
  whose slots span the round interval or more. What to check: make the
  round interval longer than (slots − 1) × slot interval; stored
  configuration of such a timeline no longer loads. The assigner checks
  every slot the timeline declares for a round, filled or not; which
  rounds it compares is the next entry.
- **The assigner compares the rounds that are played.** This settles the
  check of the entry above before its first release; 0.2.3 compared no
  rounds, so it is no change from 0.2.3 beyond that entry. As first
  written, the check compared each round assigned with the round numbered
  after it, whether or not the schedule had that round (#152). Two rounds
  on either side of a gap were then never compared with each other: with
  rounds half a local hour apart (an interval from `diff()`) from 00:30 in
  `Europe/London` on 29 March 2026, rounds 2 and 4 are both 02:00, and a
  schedule of those two rounds was assigned. And a sound schedule was
  refused for a round it does not hold: with `P1M` from 31 December and
  two slots 30 days apart, round 2 ends on 2 March and round 3 would
  start on 28 February, so a schedule of rounds 1 and 2 was refused. Now
  `TimelineAssigner::assign()` compares the rounds the schedule holds, in
  round order, each with the next one it holds, and its last round with
  nothing; since the slots of every round rise, no slot of a round the
  schedule holds is at or after a slot of a later round it holds, or the
  schedule is refused. `assignRound()`, which is given one round and
  keeps nothing between calls, compares the round with the one before it
  by number, which a stage played in order from round 1 has played, and
  round 1 with nothing: the last round of a stage is not refused for a
  round after it that does not exist, and an overlap of two rounds is
  reported when the later of them is assigned. The round before is the
  one before by number whether or not it was played, so where a caller's
  rounds have a gap `assignRound()` compares a round with one nobody
  played, and `assign()` on the rounds played is the check of them. The context of the
  exception names the later round under `next_round`, beside `round`,
  and the message names it too (`Round 2 is still being played when
  round 4 starts`). What to check: nothing for `assign()`; code that
  assigns round by round is told of two overlapping rounds one call
  later than the first form of the check told it.
- **A session grid whose sessions overlap is rejected.** `SessionGrid`
  checked only that session starts ascend. The constructor and
  `fromArray()` now throw an `InvalidConfigurationException`
  (`OverlappingTime`) when the last slot of a session is at or after the
  start of the next session, slot-count overrides applied. Before and after:
  sessions at 20:00 and 20:25 with two slots 25 minutes apart were accepted,
  two positions were at 20:25, and a repack placed one participant in both
  and reported no double booking; the grid is now refused. Who is affected: an
  instant-based grid whose sessions run into one another. What to check:
  space the session starts, or lower the slot count of the earlier session
  with an override; `positionOf()` given an instant no longer has two
  positions to choose between. A shape-only grid has no instants and is not
  checked, and the last session can still run as deep as it needs to. A
  grid whose sessions are interleaved without sharing an instant (sessions
  at 20:00 and 21:00 with four slots 25 minutes apart, the last of them at
  21:15) is refused too: its positions are not in time order.
- **Two slots of one round, or of one session, at the same time are
  rejected.** The slots of a round and of a session must be at rising
  times, and a round of one slot must be before the next round. An interval
  written as an ISO 8601 duration gives that, so this does not affect
  `new DateInterval('PT1H')` or plain data (the one exception is an
  interval of days across a calendar day a timezone skipped, as
  `Pacific/Apia` skipped 30 December 2011: the slot of the skipped day and
  the slot of the day after are one instant). It affects an interval
  that `DateTimeImmutable::diff()` returned, whose hours PHP adds as local
  time: with slots one such hour apart from 00:30 in `Europe/London` on 29
  March 2026, the slots are 00:30, 02:30 (there is no 01:30) and 02:30.
  Adding slot by slot gave 00:30, 02:30, 03:30, so this is a case the
  change above made, and it is refused where it would be a silent double
  booking: by `new TimelineDefinition()` for the first round, by
  `TimelineAssigner::assign()` and `assignRound()` for the round they
  assign, and by `new SessionGrid()` for any session, all with
  `OverlappingTime`. What to check: write the interval as a duration
  (`PT1H`), which is elapsed time and gives 00:30, 02:30, 03:30.
- **A round or a slot too far from its start is refused.** Slot times are
  now multiplied out, and PHP adds an interval too large for its timestamps
  without an error and returns a date that has wrapped round.
  `TimelineDefinition::getSlotTime()` and the constructors throw an
  `InvalidConfigurationException` (`ValueOutOfRange`) for a time more than
  10^14 days (some 270 billion years) from the start it is counted from.
  Nothing that returned a time before is affected: such a round was never
  reached, one addition per round.
- **The counts of a timeline and of a session grid have stated limits.**
  A count in configuration is an integer the caller gives, and building a
  definition or a grid no longer costs time or memory in proportion to
  one unless it has a limit; a value above its limit is refused with an
  `InvalidConfigurationException` (`ValueOutOfRange`, with the limit in
  the context under `limit`) before anything of that size is visited or
  allocated (#153). Two of the cases are this release's own, from the
  checks of rising slot times above, and were never released; the limits
  themselves are new against 0.2.3, which had none. Each, with what 0.2.3
  did:
  - *Slots per round: at most 10,000*
    (`TimelineDefinition::MAX_SLOTS_PER_ROUND`). `slots_per_round` at the
    largest integer looped without end, in the check of rising slots
    this release added, which visits every slot of the first round (a
    million slots took 1.5 seconds). 0.2.3 checked nothing and built any
    count at once. The check cannot be done by arithmetic, since only a
    timezone's clocks say where two local times are one instant, so the
    count has a limit: a kickoff every minute, day and night, for a
    week, checked in a few hundredths of a second. Who is affected: a
    definition of more than 10,000 slots per round, which 0.2.3 built
    and this release refuses.
  - *Positions of a grid: at most 100,000* (`SessionGrid::MAX_POSITIONS`),
    for the number of sessions, `slots_per_session`, each slot count of
    `slots_per_session_overrides`, and their sum. A slot count at the
    largest integer looped without end on an instant-based grid, in this
    release's check of the sessions; 0.2.3 built such a grid at once, and
    raised a `TypeError` from `getPositionCount()` when two sessions of
    that size were added up. The limit is set by the instant-based grid,
    which keeps the instant of every position: some 40 MB and a sixth of
    a second at the limit, a slot every five minutes, day and night, for
    nearly a year. Who is affected: a grid of more than 100,000
    positions, of either form, which 0.2.3 built.
  - *Steps of an interval made by `DateInterval::createFromDateString()`:
    at most 100,000*, which is round 100,001 of a timeline (a round a day
    for 273 years). Such an interval has no multiple and is added once
    per step. 0.2.3 added once per round for every interval, so it
    returned round 200,000 of any timeline in a tenth of a second and did
    not return from a round number near the largest integer; this
    release multiplies a duration out (the entry above) and now refuses
    what is left. Who is affected: a round number above 100,001 under
    such an interval. What to check: write the interval as a duration
    (`P7D`), which has no limit of steps.
  - *Capacity per slot: at most a million million*
    (`SessionGrid::MAX_CAPACITY_PER_SLOT`), or unbounded. See **Fixed**.
    Who is affected: a `capacity_per_slot` above 10^12, which 0.2.3
    accepted, and repacked where the capacity times the positions of the
    grid was still an integer.

  A count at its limit loads, and nothing below a limit changes: the slot
  times and the positions are the ones this release gave before. What to
  check: nothing, for a competition; a stored definition or grid above a
  limit no longer loads. The usage guide states each limit where it
  describes the count ("Timeline Assignment", "Schedule Repacking",
  "Unbounded Capacity"), and `docs/design/timeline-assignment.md` and
  `docs/design/schedule-repack.md` say why each has the value it has.
- **The slots of a round or a session under an interval made from a
  relative date string are computed in one pass.** This release computes
  every slot from its round's or its session's first slot (above), and
  for an interval made by `DateInterval::createFromDateString()`, which
  is added one step at a time, that was one addition for each step of
  each slot: 1,000 slots `25 minutes` apart took a quarter of a second to
  build and 10,000 took 25 seconds, where 0.2.3 built both at once. Each
  slot is now the one before it plus the interval, which is the same
  time for a number of additions equal to the slot count. Never
  released; the slot times are unchanged (#153).
- **A slot count override that is not an integer is refused for its
  type.** `slots_per_session_overrides` with a value that is not an
  integer (`'3'`, `3.0`, `true`) was refused with the reason
  `ValueOutOfRange` and the message that a session needs at least one
  slot, in 0.2.3 as well. The reason is now `WrongValueType` and the
  message says the override must be an integer, as for
  `slots_per_session` itself. Who is affected: code that matches on that
  reason for an override. An integer below 1 is refused as before.
- **`TimelineAssigner::assignRound()` applies the assigner's rules.** It
  ignored them: an assigner built with a `BlackoutRule` refused a kickoff
  inside the window from `assign()` and scheduled it from `assignRound()`.
  It now throws the `InvalidConfigurationException` (`TimeRuleViolation`)
  that `assign()` throws, with every violation in the context. The rules
  are shown the round's events only, because the assigner keeps nothing
  between calls: a blackout is judged in full, and a minimum rest only
  within the round. Who is affected: code that passes rules to the
  constructor and calls `assignRound()`. What to check: a round that broke a
  rule now throws where it returned events; to judge rest between rounds,
  keep validating the accumulated `ScheduledSchedule` with the rule's
  `validate()`, as before.
- Tooling only; no output and no public signature changes. The development
  tools are raised to Rector 2.7 and PHPStan 2.3 (`rector/rector: ^2.7`,
  `phpstan/phpstan: ^2.3`, `phpstan/phpstan-strict-rules: ^2.1`; the lock
  file holds Rector 2.7.0, PHPStan 2.3.1 and
  `phpstan/phpstan-strict-rules` 2.1.0), and Rector runs with its
  parallel workers again. Rector 2.7.0 writes a cache entry to a temporary
  file and renames it, so a worker can no longer load an entry that another
  has half written; `->withoutParallel()` is removed from `rector.php`, and
  the Rector step of the gate takes about half as long. The test that
  required the one-process setting is replaced by one that fails when
  `composer.json` allows a Rector earlier than 2.7, and another does the
  same for strict rules earlier than 2.1
  (`tests/Feature/GateConfigurationTest.php`). What the new versions found
  is fixed in the code, with nothing added to a baseline or to
  `ignoreErrors`:
  - Rector's new `TernaryToNullsafeCoalesceRector` asked for one expression
    in `Timeline\ZonedTime` and one in a test helper
    (`tests/Support/ThrowSites.php`) to change from
    `$x !== null ? $x->y() : $z` to `$x?->y() ?? $z`. In both, what is read
    from `$x` is never `null`, so the two forms give the same value. The
    test helper reads a property, where PHPStan 2.3 in turn reports the
    `?->` as unnecessary on the left of `??` (`nullsafe.neverNull`); it is
    written `$x->y ?? $z`, which both tools accept.
  - PHPStan 2.3 reported that an offset might not exist (12 findings) in the
    oracle of `tests/Feature/StandingsTiedSetsInvariantsTest.php`, which
    counted into `$record[$id]['wins']` and the like. The oracle now reads a
    participant's whole line, changes it and writes it back; it computes the
    same figures.
  - Nothing was changed for `foreach.valueOverwrite`, which PHPStan 2.3
    reports at two loops in `src/` (`Repack\Internal\SessionPacker`,
    `Scheduling\DoubleEliminationEngine`) with the strict rules 2.0.12 and
    not with 2.1.0. Each loop names its value variable as an earlier loop
    that has ended named its own, and nothing reads the variable between
    the two; 2.1.0 reports the overwrite only of a variable that was
    assigned before the loop and is read after it. That is why the
    constraint on the strict rules is raised: with `^2.0` an install could
    hold a version that reports the two loops.

  The scheduled workflow's `Unlocked dependencies` jobs, which install the
  newest versions and had been failing on these findings, pass again.
- **Breaking: `SwissPairingEngine::pairNextRound()` refuses a round beyond
  the planned rounds.** With `plannedRounds: 1` and round 1 recorded,
  `isComplete()` was true and `pairNextRound()` still returned a second
  round; the bracket engines refuse in the same position. It now throws an
  `InvalidConfigurationException` with the new reason `PlannedRoundsPlayed`
  (`planned_rounds_played`) and the context `planned_rounds` and
  `next_round`. An engine built without `plannedRounds` pairs as before,
  for as long as a pairing exists. Who is affected: code that calls
  `pairNextRound()` without asking `isComplete()` first, or that plays more
  rounds than the number it gave the engine. What to check: every loop that
  pairs a Swiss stage. Before: a pairing of round `plannedRounds + 1`.
  After: the exception. To play a longer stage, build the engine with the
  new number of rounds; the planned rounds are not part of the engine's
  fingerprint, so the recorded state is still accepted (#121, item 1).
- **Breaking: `Standings::getPosition()` counts the order of the table and
  no longer reads the array keys.** A `Standings` built by hand from an
  array with the keys 5 and 9 reported the positions 6 and 10, and one with
  string keys raised a raw `TypeError`, while `getTiedSets()` and iteration
  counted the same entries as 1 and 2. The position is now the entry's
  place in the order of the array, from 1. A table from
  `StandingsCalculator`, and any table built from a list, gives the
  positions it gave before. Who is affected: code that constructs
  `Standings` itself from an array that is not a list (the result of
  `array_filter()` without `array_values()`, say) and reads `getPosition()`.
  What to check: whether anything relied on the key. Before:
  `new Standings([5 => $a, 9 => $b])->getPosition($b)` was 10. After: 2
  (#121, item 3).
- **Breaking: `SeedProtectionConstraint` accepts an event that has no
  round.** Such an event was counted as round 0, which is inside every
  protection window, so an event of two top seeds was rejected even with a
  period of 0.0, which protects no round. An event with no round is in no
  round and therefore in no protected one: `isSatisfied()` now returns
  true for it, as it does where the plan has no total number of rounds.
  Every event the schedulers and the engines build carries a round, so no
  generated schedule changes. Who is affected: code that calls
  `isSatisfied()`, or `ConstraintSet::isSatisfied()` with this constraint
  in the set, on an event built without a `Round`. What to check: give such
  an event its round where the protection is meant to apply. Before:
  `false` for two top seeds in an event with no round. After: `true`
  (#121, item 4).
- **Breaking: `MatchOutcomeSelector` refuses a final round that holds an
  event of more than two participants.** A winner and a loser are a
  reading of two participants. Given an event of three, the losers mode
  returned the first participant, or the second when the first had won,
  and said nothing of the third: with the third as the winner it named the
  first the loser. The winners mode returned the recorded winner. Both
  modes now throw an `InvalidConfigurationException` with the reason
  `IncompatibleOutcome` and the context `mode` and `participants`, in line
  with ADR 0003 (`docs/adr/0003-multi-participant-events-are-a-2-0-goal.md`):
  a component that is pairwise by nature refuses such an event and never
  answers as if it were pairwise. Nothing in the library generates an
  event of more than two participants, so no outcome an engine returns is
  affected. Who is affected: code that builds a `StageOutcome` by hand
  whose final round holds such an event and passes it to the selector.
  What to check: read the winner of such an event from its `Result`
  directly. Before: a participant list. After: the exception
  (#121, item 13).
- **Breaking: `TimelineDefinition::toArray()` and `SessionGrid::toArray()`
  refuse an interval that would not read back as the same interval.** An
  ISO 8601 duration, as PHP reads one, holds years, months, days, hours,
  minutes and whole seconds. A `DateInterval` can hold more, and that part
  was dropped without a word: an interval made by
  `DateInterval::createFromDateString('next weekday')` was written `PT0S`,
  which does not even load again, and `last day of next month` was written
  `P1M`. Both methods now write an interval through the new
  `TimelineDefinition::writeInterval(DateInterval $interval, string $key):
  string`, which throws an `InvalidConfigurationException` with the reason
  `NotSerializable` for:

  - *an interval made by `createFromDateString()`, whatever the string.*
    One made from more than amounts of time (`next weekday`) has no
    duration. One made from amounts of time alone has a duration, and it is
    another interval. PHP does not show the parts of an interval made from a
    string, so a timeline and a grid add it one step at a time, each step
    from the one before, and its hours, minutes and seconds as local time; a
    duration read back is added as all its steps at once from the start, and
    its hours as elapsed time (see "Every slot time is computed from the
    start" under Output change). `createFromDateString('1 month')` from 31
    January gives 3 March and 3 April, and the `P1M` it was written as gives
    28 February and 31 March; `createFromDateString('25 minutes')` from the
    first 01:50 of the night the clocks go back in London gives 02:15 GMT,
    and `PT25M` gives 01:15 GMT;
  - *an interval from `diff()` that has hours, minutes or seconds*, because
    PHP adds those as local time too. An interval from `diff()` of whole
    days, months and years is written as before: those are calendar steps
    in both forms;
  - *an inverted interval, one with a negative part, and one with a
    fraction of a second*, none of which a duration PHP reads can hold.

  Every interval built from an ISO 8601 duration is written as before. The
  refusal is made when the object is serialised and not when it is built: a
  timeline or a grid with such an interval works, and gives the slot times
  it gave before; it has no plain-data form. In a timezone that never
  changes its clocks (`UTC`, a fixed offset) the hours of the two forms
  agree, and the interval is refused all the same: the rule does not
  depend on the timezone. Who is affected: code that builds a
  `TimelineDefinition` or a `SessionGrid` with
  `DateInterval::createFromDateString()` (`'25 minutes'`, `'1 week'`), or
  with what `diff()` returned for two times of day, and calls `toArray()`
  on it; nothing else that reads such a timeline or grid changes, and
  `RepackOutcome::fingerprint()` does not cover the grid. What to change:
  build the interval from a duration, `new DateInterval('PT25M')` or
  `new DateInterval('P7D')`. The duration is not the same interval in the
  cases listed under "Every slot time is computed from the start" (a month
  end, a time of day the clocks skip or repeat), so compare the slot times
  where one applies; to keep the times of the relative interval, keep
  building it and store the string it is made from in your own
  configuration. Before: a duration string that could mean another
  interval. After: the exception. Both `toArray()` methods declare it, so
  code analysed with checked exceptions sees one more `@throws`
  (#121, item 15).
- **Breaking: `MinimumRestRule::toArray()` refuses a rest that
  `fromArray()` could not read.** The rest is written as
  `TimelineDefinition::formatInterval()` shows it, and that method now
  shows a fraction of a second, a sign and a relative date string (see
  Output change); none of the three is a duration `fromArray()` reads, so
  the rule would have been written as data that fails when it is loaded.
  `toArray()` now throws an `InvalidConfigurationException` with the reason
  `NotSerializable` for such a rest. A rest of amounts of time made by
  `createFromDateString()` (`48 hours`) or by `diff()` is written as
  before, unlike the interval of a timeline: the rule adds its rest once
  and in UTC, where it equals its duration. Who is affected: code that
  builds the rule with `createFromDateString('next weekday')` or the like,
  with a fraction of a second or with a negative part, and stores it.
  Before: `PT0S`, or a duration without the fraction or the sign, which
  loaded as another rest or not at all. After: the exception
  (#121, items 6 and 15).
- **Breaking: `TimelineAssigner` refuses a round that puts one participant
  in two events at the same instant.** A slot with two or more resources
  holds several events, and nothing stopped two of them from holding the
  same participant: `assign()` and `assignRound()` returned a timeline in
  which one participant plays on two pitches at once, unless a
  `MinimumRestRule` happened to be configured. Both now throw an
  `InvalidConfigurationException` with the new reason
  `ParticipantDoubleBooked` (`participant_double_booked`); the context has
  the round under `round` and, under `double_bookings`, every participant
  concerned with the kickoff and the two events. The assigner refuses, as
  it does for a violated rule, and does not report: its filling is
  deterministic, so it cannot move one of the two events, and no policy
  accepts a participant in two places at once. Participants are matched by
  ID. Two events of one participant in different slots of a round are
  assigned as before; whether the time between them is enough stays a
  rule's to say. No schedule a generator returns has a participant twice in
  a round, so no generated schedule is affected. Who is affected: code that
  assigns a schedule or a pairing built by hand, or by a format of its own,
  in which a participant has two events in one round, on a timeline with
  resources. What to check: the order of the round's events, which decides
  the slot each lands in, or give the round more slots than resources.
  Before: two scheduled events of one participant with the same kickoff.
  After: the exception. Where a `MinimumRestRule` reported such a clash,
  the reason changes from `TimeRuleViolation` to `ParticipantDoubleBooked`,
  because the round is refused before the rules are asked (#121, item 10).

- The `Coverage` job of CI measures with PCOV where it used Xdebug. It
  reports line coverage only, which PCOV measures, and the suite had grown
  until the job took 25 to 40 minutes under Xdebug. The `Mutation testing`
  workflow still uses Xdebug, and `composer test-coverage` works with either
  driver as before. Nothing a consumer installs changes.

### Deprecated

- **The `'tie_winner'` metadata of a result, and
  `TieDecision::TIE_WINNER_KEY`, which names it.** Who advances from a
  level elimination tie is recorded as the decider of the result (see
  **Added**). The metadata key is still read, and decides what it decided
  before, wherever a level tie has no decider; it is no longer read from
  `1.0.0`, when the constant is removed and a level tie that carries only
  the key is undecided.
  - **What to change.** Where a result is built with `['tie_winner' =>
    $id]` in its metadata, pass `decider: new Decider([$id])` in its place.
    Stored results keep loading and keep deciding until `1.0.0`; to move
    them over, give each result of a level tie that carries the key a
    `decider` of `['participants' => [$id]]` and remove the key. A
    two-legged tie is level when its legs are (no leg won, or a leg each),
    so its two results are read together. The usage guide has the new
    code and both migrations of stored data, single events and two-legged
    ties, as executed snippets ("The `tie_winner` metadata key").
  - **How it is raised.** A metadata key cannot carry an attribute, so the
    library raises the notice itself: a call that takes an advancer from
    the key emits one `E_USER_DEPRECATED` notice, whatever the number of
    ties the key decided in that call, and on every PHP version, PHP 8.3
    included. Those calls are `isComplete()`, `pairNextRound()` and
    `getOutcome()` of the two elimination engines,
    `MatchOutcomeSelector::select()` and `TieDecision::advancer()`. An
    application that turns every notice into an exception will see them
    fail until it records deciders. The constant carries the `@deprecated`
    tag and the `#[\Deprecated]` attribute, as the deprecated methods do,
    so a use of it is reported by an IDE and PHPStan, and emits a notice of
    its own from PHP 8.4.
  - **Where nothing is raised.** A key that is not read: on a result of a
    tie that its results decide, where it never was read; on a result that
    is only stored, loaded or counted in a table; and on a tie that carries
    a decider.
  - **Where the two differ.** The key on a tie that its results decide was
    not read and went unnoticed; a decider there is refused
    (`InvalidResult`). The key could hold any value and was refused only
    when the engine read one that named nobody in the tie; a decider must
    name a participant of the event when the result is built.
  - **Both at once.** A tie that carries a decider is settled by the
    decider. A `tie_winner` beside it that names the same participant
    raises nothing, so an application can write both while it changes over;
    one that names another participant is refused with `InvalidResult`,
    because the library does not choose between two answers.

### Fixed
- **A session grid given a very large `session_count` or capacity is
  refused, where it raised a PHP error.** Both are in 0.2.3 as well.
  `SessionGrid::fromArray(['session_count' => PHP_INT_MAX])` and
  `SessionGrid::shapeOnly(PHP_INT_MAX)` raised a `ValueError` from
  `array_fill()`. A grid with a `capacity_per_slot` near the largest
  integer was built, and `ScheduleRepacker::repack()` then raised a
  `TypeError`: the planner adds the capacity up over the positions of the
  grid, and the sum was no longer an integer. Both are now refused when
  the grid is built, with an `InvalidConfigurationException`
  (`ValueOutOfRange`): the sessions above 100,000
  (`SessionGrid::MAX_POSITIONS`) and the capacity above a million million
  (`SessionGrid::MAX_CAPACITY_PER_SLOT`), a number whose sum over the
  most positions a grid can have is far inside PHP's integers. A number
  written to mean "no limit" that is below it (2,147,483,647, say) is
  accepted and repacked as before; the way to say it is a capacity of
  null (`'unbounded'` in plain data). The entry on stated limits under
  **Changed** has the other counts (#153).
- **A schedule with shuffled legs can be repeated from its configuration.**
  `RoundRobinOptions::fromArray(['strategy' => 'shuffled'])` could not seed
  the strategy it built, so a scheduler with a seeded `Randomizer` gave a
  different schedule on every run (#52). The seed above closes that: the
  property tests now hold every leg strategy, at one to three legs and
  every field size from 2 to 16, to the same schedule on every run from the
  same plain data, with and without a seeded scheduler.
- **`new BlackoutRule([...])` labels an unlabelled window by its position,
  not by its array key.** The default label added 1 to the key, so a window
  under a string key raised a raw `TypeError`, and windows under keys 3 and
  7 were labelled `blackout 4` and `blackout 8`. The label is now
  `blackout N` with N the position of the window in the array, counted from
  1, whatever the keys are. A list is labelled as before, and
  `BlackoutRule::fromArray()`, which always built a list, is unchanged
  (#121, items 2 and 16).
- **`TieDecision::advancer()` refuses a single-leg result whose winner is
  not in the tie.** With one leg it returned the winner the result
  recorded, whoever that was; with two legs the same input was refused. It
  now throws the `InvalidConfigurationException` of the two-legged case
  (reason `InvalidResult`, context `winner` and `participants`). The winner
  is matched by ID, and a winner who is one of the two is returned as
  before, so no bracket the engines resolved changes (#121, item 5).
- **`MetadataConstraint` casts its validator's answer to bool.** A
  validator that returned anything but a bool (the 1 of a `preg_match()`,
  a count, null) raised a raw `TypeError` when the constraint was asked,
  where `CallableConstraint` casts its predicate's answer. Both now cast:
  0, null, an empty string and an empty array reject the event. A
  validator that returns a bool is read as before (#121, item 7).
- **`CompositionValidator::validateChain()` can be told that a stage plays
  a whole bracket.** It counted every `MatchOutcomeSelector` as the
  hand-off after one knockout round, half the previous stage's entrants,
  and nothing could tell it otherwise: after a whole bracket of 16 the
  selector returns the 1 winner of the final and the validator computed 8.
  The validator runs nothing, so the shape of the stage is declared:
  `StageTransition` has a fourth, optional constructor parameter and public
  property, `bool $afterWholeBracket = false`. With it true, a
  match-outcome selection is counted as 1 participant, which the validator
  reports against the entrants the next stage declares. A transition that
  does not pass it is counted as before, so no chain that validated
  changes its answer (#121, item 8).
- **`StageState` refuses an ID listed twice as active, and a round
  numbered below 1.** `StageState::fromArray()` (and `fromJson()`) accepted
  stored data whose `active` list named one ID twice, which `start()`
  refuses and from which an engine would pair a participant against
  itself; it now throws an `InvalidInputException`, as it does for an ID
  listed twice in `participants`. `withRoundPlayed()` accepted a pairing
  numbered 0 or below as the first round, although round numbers are
  1-based; it now throws an `InvalidConfigurationException` with the reason
  `ValueOutOfRange`. Data that `toArray()` wrote, and every pairing an
  engine returns, is accepted as before (#121, item 11).
- **`RoundRobinPlan` and `MatchOutcomeSelector` read the participants of
  an event in their order, whatever the array keys are.** An `Event` keeps
  the participant array it is given, keys included. With string keys or
  sparse integer keys, `RoundRobinPlan::validateIntegrity()` and
  `findUnplayedPairings()` raised a raw `Error` (a call on null), and
  `MatchOutcomeSelector::select()` a raw `TypeError` or an unknown named
  argument. All three now take the first and the second participant in the
  order the event holds them. An event built from a list is read as before
  (#121, item 12).
- **An incomplete round robin no longer blames constraints for a leg its
  strategy left short, and its report no longer lists round 0.** When a
  leg strategy returned no event for a pairing, the message of the
  `IncompleteScheduleException` ended "Constraints may be preventing
  complete schedule generation", also from a scheduler with no constraints
  at all. It now says how many pairings of the leg the strategy returned no
  event for, and how many more constraints rejected; under backtracking it
  names the pairing. A leg that only constraints left short keeps its
  message. In `getDiagnosticReport()`, a rejection recorded without a round
  was listed under "Affected rounds" as round 0; it is left out there, as
  `ConstraintViolationCollector::getAffectedRounds()` leaves it out. The
  suggestions at the end of the report told a scheduler with no constraints
  to "Try relaxing constraint requirements" and to "Use fewer or less
  restrictive constraints". When no rejection was recorded they now say
  so, give the advice about constraints as a condition ("if the scheduler
  has constraints"), and, for a stage with legs, say to check that a leg
  strategy of the caller's returns an event for every pairing. A report
  with a recorded rejection has the suggestions it had. The texts are for
  people and are not a contract (#121, item 14).

- **A shard of the `Mutation testing` workflow is no longer ended by a
  runner shutdown before it reports.** Tooling only: nothing a consumer
  installs changes. Some changes the mutation runner makes turn a counting
  loop into one that never ends and adds to an array on every pass (in
  `SessionPacker` and `ContiguityTargets`, a `++$slot` that becomes
  `--$slot`). The runner stops a test process at a time limit only, and such
  a process took all the memory of a CI runner before that, so the job ended
  with "The runner has received a shutdown signal" and no summary. The test
  process of every change now has a memory limit of one gigabyte
  (`TACTICIAN_MUTATION_MEMORY_LIMIT` replaces it): a process that reaches it
  ends with PHP's fatal error, the runner counts the change as tested and
  goes on, and the process writes a line to `build/mutation-memory.log`. The
  summary of a run says how many changes ended that way and in which source
  files, and leaves them out of the share that a failing test noticed, as it
  does for the changes that ran into the time limit. The limit comes with
  `composer mutation`: the command now runs `tests/bin/run-mutation.php`,
  which adds a directory to `PHP_INI_SCAN_DIR` (a value of your own is kept)
  so that PHP runs `tests/mutation-memory.php` before the script of every
  process of the run. Composer's autoloader does not load that file, and
  `composer.json`'s autoload sections are unchanged. A run started as
  `vendor/bin/pest --mutate` has no limit, as before, and its summary says
  so. None of these changes shows a defect in the library: each removes the
  bound of a loop that is bounded as written.

- A test run no longer depends on the `memory_limit` PHP is configured
  with. The suite peaks at about PHP's default of 128M (125 to 127 MB), so
  under that default a run ended with "Allowed memory size exhausted" in a
  test that has nothing to do with memory, a different one each time, and
  passed when run again; `composer test-coverage` needs about 1.1 GB with
  either driver, nearly all of it where the coverage is reported after the
  last test, and did not finish under the default at all. A process that
  runs tests now raises a limit below 4G to 4G (`tests/Pest.php`,
  `tests/Support/SuiteMemoryLimit.php`). It never lowers one: a higher
  limit and no limit, which is what PHP has on a CI runner, stay as they
  are, and so does the limit of the test process of a mutation. The limit
  is not stated in `phpunit.xml`, because PHPUnit sets such a value
  outright and would lower the limit of a CI run. Nothing a consumer
  installs changes.

## [0.2.3] - 2026-10-13

Three fixes to `ScheduleRepacker` output that was broken. No public
signature changes. A repack whose outcome has no unplaced event and no
`CapacityExceeded` is returned byte for byte as 0.2.2 returned it, with the
same fingerprint. An outcome that has either can change: it is changed only
where it left an event unplaced that a placement could hold, dropped more
events than a participant's shortfall needed, or understated a
`CapacityExceeded`. For every outcome that changes, `toArray()` and
`RepackOutcome::fingerprint()` change with it, so a fingerprint kept from
0.2.2 for such a request no longer matches: repack again before comparing.

### Output change (fix)

- **The repacker places events it used to leave unplaced although a
  placement held them.** The phases before the last sweep never moved a
  placed event to free a position for another one, so a larger step budget
  could not help. A last placement step now runs when an event is still
  unplaced after the sweep: it moves placed events along alternating paths
  (a Kempe chain is one), and where that is not enough, exchanges one placed
  event for one unplaced event when that lets a further one in. It never
  double-books a participant, never moves a pin and never fills a slot past
  its capacity. It is offered the events dropped for an over-capacity
  participant too: a participant never holds more positions than it has
  free, so placing one only changes which of its events is the one that does
  not fit, and the reasons are then given again to the events still
  unplaced, by the drop rule below. Before and after:

  | Request | 0.2.2 | 0.2.3 |
  |---|---|---|
  | Complete round robin of 6 on six one-slot sessions, capacity 3 | 13 placed, 2 `no_slot_available` | 15 placed |
  | Complete round robin of 24 on six four-slot sessions, capacity 12 (ids `1`–`276`) | 250 placed, 26 unplaced | 276 placed |
  | The same with ids from `1000` | 255 placed, 21 unplaced | 276 placed |
  | Complete round robin of 40 on ten four-slot sessions, capacity 20 | 732 placed, 48 unplaced | 780 placed |
  | Complete round robin of 40 on nine four-slot sessions, capacity 20 (each participant 3 over) | 628 placed | 720 placed, the most there can be |

  Every complete round robin of an even number of participants from 6 to 40
  on a grid with at least as many slots per participant as it has events now
  places every event, and on 3,000 small random requests the number placed
  equals the best any placement achieves (it fell short on 458 of them).
  Who is affected: anyone whose repack outcome lists unplaced events. What
  to check: an outcome that listed events as `no_slot_available` may now
  place them, so its assignments move as well, and the assignments of a
  session the step moved events through can carry gaps or late starts that
  were not there (they are reported as `ContiguityBroken` and `LateStart`).
  The golden fixtures `repack/round-robin.txt` (the second case above),
  `repack/budget-stops.txt` (the six lines whose outcome had unplaced
  events: five at a budget of 30 steps and one at 1,000) and
  `examples/19-repacking-a-season.txt` and
  `examples/23-application-adapter-and-repack.txt` (see the next two entries)
  change; no other fixture does.

  **A repack can now spend up to twice `RepackOptions::$stepBudget`.** The
  step has a budget of its own, of that size: the earlier searches stop
  where they always stopped, and a run whose earlier searches spent the
  whole first budget still gets the step. `isBudgetExhausted()` is also
  true when the step's budget stopped it; false still means that a larger
  budget gives the same outcome. The step also stops searching after a
  fixed number of position checks of its own (about a sixth of a second),
  whatever the budget. It looks for an exchange only while a placement
  could hold more events than are placed; where that bound cannot rule out
  more, the search runs until its own limits. The cost, measured with the
  default budget on some 300 requests of 4 to 40 participants and up to
  804 events: a request the step does not reach costs what it did in
  0.2.2, and the step itself adds about 0.1 to 0.2 seconds where it runs
  (804 events on eight five-slot sessions: 0.40 seconds before, 0.58
  after, 644 events placed where 0.2.2 placed 640). Most of the extra time
  comes from elsewhere: where the drop rule below drops fewer events, the
  packing searches have more events to place and can spend their whole
  first budget where they did not. A request that nearly fits a two-leg
  season of about 40 participants took up to about 1.1 seconds (657
  events of 38 participants on seven five-slot sessions, capacity 19:
  0.13 seconds before, 1.02 after, 615 placed where 0.2.2 placed 581), and
  one that is far too large for its grid went from 0.01 to 0.83 seconds
  (558 events on two five-slot sessions: 180 placed where 0.2.2 placed
  78). A smaller `stepBudget` trades placed events for time. The step's
  limit can leave an event unplaced that a longer search would have
  placed: on over-capacity requests near a full grid it cost one or two
  events on 20 of 120 requests measured, and on one request of 84 events
  it placed 65 where an unlimited search placed 67; every one of them
  still placed at least what 0.2.2 placed.

- **The over-capacity drop rule drops an event between two over-capacity
  participants first.** It sorted a participant's events by the slack of the
  opponent, so an event shared by two participants who both had too many
  was kept and two others were dropped. It now drops first as many shared
  events as can count towards both shortfalls, and only then each
  participant's other events, by the opponent's slack as before. The search
  for the shared events stops at a fixed node limit; with many
  over-capacity participants sharing many events (a dozen sharing some
  forty) it can stop with fewer than the most there are, and then drops
  more events than the shortfalls need. The last placement step is offered
  every drop and places those it can. Before and
  after, for one slot of capacity 3 and the events `p3 v p5`, `p5 v p6` and
  `p3 v p2` (p3 and p5 each have two events for one position): 0.2.2 placed
  1 event and dropped `p5 v p6` and `p3 v p2`; now `p3 v p5` is dropped and
  the other two are placed. Who is affected: requests in which two
  over-capacity participants meet. What to check: more events are placed,
  and different events are listed as `participant_over_capacity`. Where the
  grid itself is full, the number placed can stay the same while the events
  dropped, and so the assignments and their `ContiguityBroken` and
  `LateStart` violations, change, and the grid's `CapacityExceeded` has a
  larger demand, because fewer events were dropped before it counts. In
  `examples/19-repacking-a-season.php` the grid without the deeper session
  places 8 events instead of 7 (Celtic and Rayo Vallecano are both one over
  and meet twice); in `examples/23-application-adapter-and-repack.php` the
  first preview drops `fx05` (Harbour Athletic v Northgate Rovers, both one
  over) instead of `fx09` and `fx10`, and `fx10` is now unplaced as
  `no_slot_available`, because no placement holds more than three of the
  five fixtures.

- **`CapacityExceeded` reports a participant's demand before any event was
  dropped, and reports every participant with more events than free
  positions.** The participants were processed one after another, and a
  participant's demand was counted after the events dropped for earlier
  ones, so it was understated, and a participant whose shortfall an earlier
  drop had covered was not reported at all. Before and after, for one slot
  with no capacity limit and the events `p1 v p2` twice, `p1 v p3` and
  `p2 v p4`: 0.2.2 reported p1 with demand 3 and p2 with demand 2 (shortfall
  1); now both have demand 3 and shortfall 2. Who is affected: requests with
  two or more over-capacity participants that meet. What to check: a
  `CapacityExceeded` can have a larger demand and shortfall, and there can be
  more of them. `CapacityExceeded::getShortfall()` said that exactly the
  shortfall of a participant's events are unplaced as
  `participant_over_capacity`, naming it; once two over-capacity participants
  meet that was not true before either, and the docblock now says what is:
  at least that many are, each naming it or the other over-capacity
  participant of the event. The `CapacityExceeded` of the grid
  as a whole keeps its meaning: it still counts the events left after the
  participants' drops, which are fewer where the previous entry applies.

### Changed

- The `Mutation testing` workflow has an eighth shard, `repack recolouring`,
  for the new internal file `src/Repack/Internal/LeftoverRecolourer.php`.
  Nothing a consumer installs changes.

## [0.2.2] - 2026-10-09

A new format (the pot draw), an opt-in balanced role assignment for round
robin, additions to the stage, standings, exception and repack API, and fixes
to output that was broken. For a fixed input and seed, what 0.2.1 returned
is returned unchanged, except where an entry under "Output change (fix)"
says otherwise. No golden fixture outside `examples/` has changed; the
three example fixtures that did change follow examples that were rewritten.
Two things are listed elsewhere: an input that failed outright in 0.2.1 and
now succeeds is under "Fixed", and one exception message that goes on after
the words it had is under "Changed". Before upgrading from 0.2.1, read
"Output change (fix)" and "Changed": each entry says whom it affects and
what to check. A call to one of the eight methods under "Deprecated" emits
a notice from PHP 8.4.

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
  100 and round it to two decimal places. The percentage is rounded to at
  most two decimal places, a half going up, and is written with `.` as the
  decimal separator and without trailing
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
  with `|` gave two pairings one key (`a` v `b|c`, and `a|b` v `c`). Where
  that made generation or a bracket fail outright, the entry is under
  "Fixed". For a field that holds such ids:
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
- A datetime in plain-data configuration that PHP would resolve against the
  clock, or read to another instant than the one written, is rejected.
  **Configuration that writes the date of each datetime in full
  (`2026-08-01 19:00`, `2026-08-01T19:00:00`, `2026-11-09`) is unaffected:
  every such string parses to the instant it did before, and a date without
  a time of day is still midnight.** The string went straight to PHP's date
  parser, which also accepts `now`, `tomorrow`, `+1 week`,
  `next monday 20:00` and the empty string and resolves them against the
  clock, so the same configuration gave a different timeline or session grid
  each time it was loaded. That contradicts the rule that the library never
  asks for the current time. These are now rejected with an
  `InvalidConfigurationException` (reason `UnparseableTime`, the message an
  unparseable string gives: `start or its timezone is not parseable`) by
  `TimelineDefinition::fromArray()` (`start`), `SessionGrid::fromArray()`
  (`sessions`) and `BlackoutRule::fromArray()` (`from`, `to`), and with an
  `InvalidInputException` (`Scheduled event kickoff is not parseable`) by
  `ScheduledEvent::fromArray()` and `ScheduledSchedule::fromArray()`/
  `fromJson()` (`kickoff`):
  - a string whose answer came from the clock: one relative to the current
    time, the empty string, a time of day without a date (`20:00`), and a
    date without its year (`August 1 20:00`);
  - a date or a time that does not exist, which PHP rolled over into the
    next one: `2026-02-30 20:00` (read as 2 March), `2026-08-01 24:00`,
    `2026-08-01 23:59:60`, the 366th day of a year that has 365;
  - a weekday name that is not the weekday of the date
    (`Mon, 01 Aug 2026 19:00:00 +0000`, a Saturday, which PHP moved to the
    Monday after);
  - a second timezone that is not the first
    (`2026-08-01 19:00 +01:00 +05:00`, of which PHP read the first and
    ignored the second).

  What to check: configuration or stored data that holds one of these.
  Everything else PHP reads is accepted as before and parses to the same
  instant: a date without a time of day, a month name
  (`1 August 2026 19:00`), RFC 2822 with the right weekday, an ISO 8601 week
  date (`2026-W31-6T19:00`) or ordinal date (`2026-213T19:00`), a Unix
  timestamp (`@1785610800`), a timezone written twice (`+0000 (UTC)`), and
  an offset from a date the string states (`2026-08-01 20:00 +1 week`,
  `first monday of August 2026 19:00`), which PHP counts from that date and
  not from the clock. A zone or offset in the string is still checked
  against the `timezone` field, and a string that has both faults is still
  reported for its timezone (`TimezoneMismatch`). A relative date with no
  date to count from is for the application to compute and pass on.
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

- Every class, interface, trait and enum states its stability in its
  docblock, with exactly one of `@api` (stable surface), `@experimental`
  (public, expected to change in a minor release before `1.0.0`) and
  `@internal` (not public API). The README's "Versioning and stability"
  section still decides which of the first two a type is: a type that an
  entry of its stable list covers is `@api`, and every other public type is
  `@experimental`. No type moved between the two lists; six types that had
  no mark are now `@internal` (see "Changed").
  An architecture test (`tests/Feature/StabilityAnnotationsTest.php`) fails
  when a type has no annotation or more than one, when an annotation
  contradicts the README, when a namespace is in neither list, when a type
  the README lists as stable is `@internal`, and when a public signature
  hands out or asks for an `@internal` type. It pins the `@internal` types
  outside `Repack\Internal`.

- Balanced role assignment for round robin, opt-in:
  `new RoundRobinOptions(roleAssignment: new BalancedRoleAssignment())`, or
  `'role_assignment' => 'balanced'` in plain data. The role of a participant
  in an event is its position, first-named or second-named. With the default
  roles a single leg ends up to 3 apart between the two for one participant in
  a field of even size and up to 4 apart in a field of odd size; of four
  participants, one is second-named in all three of its events. With the
  balanced roles every participant ends a leg at most 1 apart in a field of
  even size, which is the least possible because it plays an odd number of
  events, and exactly 0 apart in a field of odd size. The pairings, their
  rounds, their order and the byes do not change: only who is first-named
  does. In a single leg of 2 to 30 participants no participant plays more
  than two events in a row in the same role (the default reaches four), and
  while the leg is played no participant is more than 1 apart in a field of
  even size or 2 apart in a field of odd size.

  The new namespace `RoleAssignment` holds `RoleAssignmentInterface` and its
  two implementations: `RoundParityRoleAssignment`, which is the default and
  keeps the roles the library has always produced, and
  `BalancedRoleAssignment`. `RoundRobinOptions` gains a fourth constructor
  parameter, `roleAssignment`, and a public property of the same name;
  `fromArray()` reads the optional key `role_assignment` (`round_parity` or
  `balanced`), and `toArray()` writes that key only when a role assignment
  was named, so options that do not name one serialize to the three keys
  they always have and options that name one, the default included, keep it
  through a round trip. `InvalidConfigurationReason::InvalidRoleAssignment`
  is the reason when a custom role assignment returns anything but the
  seatings it was given, each unchanged or reversed.

  No output changes for options that do not set the role assignment, and the
  existing golden fixtures are unchanged. What the balanced roles add up to over
  several legs depends on the leg strategy, which keeps its meaning, and a
  role constraint that the balanced roles break fails generation loudly: the
  section "Role Assignment" of the usage guide has the table per leg strategy
  and the rules for constraints and backtracking.

  **The balanced role assignment becomes the default in 0.3.** To keep the
  roles of today after that release, name the current default now:
  `new RoundRobinOptions(roleAssignment: new RoundParityRoleAssignment())`,
  or `'role_assignment' => 'round_parity'`.
- A single-leg elimination event that finishes level can be recorded and
  decided. Record it as the draw it was, with the ID of the participant who
  advances as `TieDecision::TIE_WINNER_KEY` (`'tie_winner'`) metadata on
  its result: the key a level two-legged tie already uses, read the same
  way. That participant advances in `SingleEliminationEngine` (fixed path
  and re-seeded) and in `DoubleEliminationEngine` (both brackets, the grand
  final and its reset, where the other participant takes the loss), and
  `MatchOutcomeSelector` selects them as the winner. Before,
  `StageState::withRoundPlayed()` accepted the draw and every later call of
  the engine threw, so the only way on was to replace the result with a win
  that did not happen. The recorded result stays a draw; in the standings
  the engines compute, a level event decided this way counts as a win for
  the participant who advanced, so the table places the bracket as it does
  when every event has a winner. A table computed from the recorded
  results, the combined table of `StageOutcome::combining()` included,
  counts it as the draw it was. A decision that names a participant
  outside the event is refused as it is for two legs
  (`InvalidConfigurationReason::InvalidResult`). No input that worked
  before gives another output: the decision is read only when the result is
  level, never on a result that has a winner, for one leg as for two. See
  "Recording a Level Event" in the usage guide.
- Pot draws: `Scheduling\PotDrawScheduler`, a whole-schedule generator for
  the format in which the entrants are cut into pots of equal size in seeding
  order and every entrant meets a fixed number of opponents from every pot,
  its own included, with no rematch. Every round is drawn before any event is
  played. The format is often called "Swiss"; it is not Swiss pairing, because
  nothing is paired from results. The classes are experimental.
  - `Scheduling\PotDrawOptions` holds `pots`, `opponentsPerPot` and `seed`
    (plain data: `pots`, `opponents_per_pot`, `seed`). `fromArray()` throws on
    a key it does not know. The same participants, options and seed give the
    same schedule on every call; the scheduler takes no `Random\Randomizer`
    and builds a seeded one for each call.
  - `Stage\PotDrawPlan` (algorithm identifier `pot-draw`) states the rounds
    (pots × opponents per pot) and the events before anything is drawn, and
    its `validateIntegrity()` checks the format: every entrant once in every
    round, the exact number of opponents from every pot, no rematch, and the
    role balance. The scheduler validates every schedule it returns with it.
  - List position is the seeding: the first block of the list is pot 1. No
    seed attribute is read.
  - Roles are balanced. The two role counts of an entrant differ by at most
    one, and with an even number of opponents per pot an entrant is in each
    role exactly half the time against every pot.
  - The schedule is built directly, with no search, for any even pot size,
    and for an odd pot size with two opponents per pot. What is built is
    who meets whom in which round, and it is then the start of a walk of
    two moves, each of which keeps every rule of the format: two rounds
    trade events, which changes no event, and two members of one pot
    exchange opponents inside a round, each keeping the pot its opponent
    comes from, when that makes no two entrants meet twice. So a round does
    not set one whole pot against another, and the pairings between two
    pots are not kept to the pattern they were built with. The length of
    the walk follows the configuration (16 steps for every round, and more
    for a field of 60 entrants or fewer); it was chosen so that the measured
    shape of a draw agrees with that of a walk ten times as long, for every
    supported configuration with up to 60 entrants. Nothing is searched or
    tried again, and generation time is proportional to the number of
    events. The roles are given last, by one rule: every entrant puts its
    events in twos, against one pot as far as they go, and is first in one
    event of a two and second in the other. That keeps an entrant within
    one of even against every pot as well as over all its events, and every
    assignment of roles with those counts can be drawn. Who meets whom is
    drawn evenly, and no entrant is more likely to hold the first role
    than another. The draw is not claimed to be uniform over every schedule
    the format allows: "How a draw is made, and what it is uniform over" in
    the usage guide says what is measured, what is argued, and which fields
    keep part of the shape they were built with.
  - A configuration that cannot exist is refused before anything is drawn,
    with a reason of its own: `OddParticipantCount` (no bye is issued),
    `UnequalPots`, `TooManyOpponentsPerPot` and `OddPotWithOddOpponents`. A
    configuration that can exist and has no direct construction yet (an odd
    pot size with four or more opponents per pot) is refused with
    `ConfigurationNotYetSupported`. The context of each carries the numbers.
  - Not in this release: pairs of entrants that must not meet, pots given
    explicitly instead of cut from list order, and the configurations that
    need a search.
  - `examples/22-pot-draw.php` draws a league phase of 36 entrants in four
    pots: the plan before the draw, the opponents of the top seed pot by
    pot, the same seed giving the same schedule, and a configuration that
    is refused.
- Six cases of `Exceptions\InvalidConfigurationReason` for the pot draw: the
  five above and `UnknownOptionKey`, for a plain-data key the options do not
  have. The enum itself is new in this release: see "A reason on every
  configuration error" below.
- A property suite (`tests/Feature/PotDrawInvariantsTest.php`) that draws
  every supported pot draw configuration with up to 60 entrants over several
  seeds and checks the rules of the format by counting in the events.
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
  replacing a result and the engine fingerprint; an event with no round
  number at all is `EventWithoutRoundNumber` there too). `getReason()`
  returns null only for an exception that code outside the library builds
  without a reason. A `match` over the reason needs a `default` arm, because
  a release may add a case.
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
  that were outside `SchedulingException` in 0.2.1:
  `Exceptions\InvalidInputException` (extends `\InvalidArgumentException`;
  the 60 sites that threw one, in the DTOs, the stage and timeline value
  objects, the constraints, `SwissPairingEngine` and `StandingsCalculator`),
  `Exceptions\InvariantViolationException` (extends `\LogicException`; the
  three internal guards that threw one) and
  `Exceptions\JsonConversionException` (extends `\JsonException`). Each
  extends the type its sites threw before, so existing catch clauses still
  match, and no message, code or previous exception has changed. The code
  that is new in this release throws the same classes, and one more that is
  outside `SchedulingException`: `Exceptions\UnavailableValueException`
  (below). The usage guide lists the classes and what the marker does not
  cover.
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
  `DoubleEliminationEngine`, which implement the new interface
  `Stage\FingerprintedEngine` (`StageEngineInterface` is unchanged; test for
  the new interface where you hold the old one). A state did not say which
  engine paired its rounds, so a state restored into another engine, or into
  the same engine built from other options, was replayed as that engine's
  own history. A state stamped with a fingerprint is refused by `getPlan()`,
  `pairNextRound()`, `isComplete()` and `getOutcome()` of every engine whose
  fingerprint differs, with an `InvalidConfigurationException` whose message
  and context (`differences`) say where the two differ. The stamp is
  opt-in. An unstamped state is accepted by every engine as before and
  serializes exactly as before; a stamped one adds an `engine_fingerprint`
  key to `toArray()` and `toJson()`, and `fromArray()` loads data without
  the key as an unstamped state.

  A fingerprint is an opaque string, compared for equality. It stands for
  the format and for the options that shape which rounds the format has or
  how they are paired, and an option at its default is left out: an engine
  that gains an option keeps the fingerprint of every configuration that
  does not use it. `Stage` is experimental and the fingerprint follows the
  rule for experimental API, no more: a patch release keeps the fingerprint
  of every configuration, and a 0.x minor release may change the scheme,
  with a migration note here saying what a stored stamp is replaced with
  (the string states the version of its scheme, `tactician:v1:`, so that a
  later scheme can be told apart).

  Part of it when not at the default: for the elimination
  engines `legsPerTie`, and `reseedEachRound` (single) or `grandFinalReset`
  (double); for Swiss, and for a re-seeded bracket, the standings rules a
  round is paired from (the `WinDrawLossRanking` scale, the tiebreakers in
  order). Not part of it: the planned rounds of a Swiss stage, which may be
  extended without a new stamp, and the constraints and the randomizer,
  which the engine cannot describe. A `RankingStrategy` of your own and a
  subclass of `StandingsCalculator` or of `SwissPairingEngine` are stated as
  such without being named, so each differs from the library's own and not
  from another of yours. Fingerprints that begin with `tactician:` are the
  library's; give an engine of your own a string that does not.
- `StandingsCalculator::getTiebreakers()`, the tiebreakers in the order they
  are applied, beside `getRankingStrategy()`.
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
- Wider property tests. Test suite only; the library is unchanged.
  - Round robin: every field size from 2 to 30, one to three legs, each leg
    strategy and a shuffled field, held to what a complete round robin is,
    counted from the events: every pairing once in every leg, nobody twice
    in a round, every round full, rounds 1-based and continuous across legs,
    and the `byes` metadata of an odd field naming the participant who is in
    no event of the round, once per participant per leg (none in an even
    field).
  - The roles of the default round robin over several legs: the end
    imbalance for 2 to 30 participants and one to four mirrored or repeated
    legs as the exact value that follows from a single leg by counting, and
    the longest run of one role as it is, so that a change either way fails.
  - Seeds: for everything that takes a `Random\Randomizer` (the round-robin
    scheduler, its backtracking search, `ShuffledLegStrategy`, the Swiss
    engine and scheduler, `ScheduleOptimizer`), twenty seeds give twenty
    different results, so a randomizer that is accepted and never asked
    fails a test. A second `schedule()` call on a scheduler that holds a
    randomizer is pinned as what it is: the next schedule of the same random
    sequence, not the first one again.
  - Swiss: stages of 2 to 32 participants driven round by round with random
    results, with and without tiebreakers, a randomizer, a constraint and
    withdrawals, the state going through JSON after every round. Every round
    is checked against the usage guide: everyone active is paired once or
    has the bye, nobody meets twice, one bye in an odd field and none in an
    even one, the bye to the lowest-placed participant with the fewest so
    far, nobody paired down past an opponent from a higher score group that
    was available, and a `NoValidPairingException` only when no complete
    pairing exists. Fields of up to 16 are driven to their last round, and
    the number of stages that cannot pair it is recorded.
- Tests for 33 of the 48 lines of `src/` that no test executed in the
  coverage report of the continuous integration, each through the behaviour
  that reaches it, among them the single-move relocation of the repack load
  planner (`tests/Feature/RepackRelocationTest.php`,
  `tests/Feature/UncoveredPathsTest.php`). The 15 lines that remained were
  guards for states the surrounding code rules out; each carries its reason
  in the source, in a comment that starts `// Not reached:`. With the code
  added since, the report of this release leaves 21 lines unexecuted (99.6%
  of lines are executed): 16 such guards, and five lines without that
  comment, in `NoRepeatPairings`, `SchedulingContext` and the Swiss round
  search.
- A `composer mutation` script: mutation testing of `src/Scheduling` and
  `src/Repack/Internal` with Pest's mutation runner, which is installed with
  Pest, over the tests named in `phpunit.mutation.xml`. A workflow of its
  own, `.github/workflows/mutation.yml`, runs it once a week (a maintainer
  can also start it by hand), apart from the scheduled workflow so that a
  slow or stopped run cannot hide what that one found. The source is
  mutated in seven shards, one `Mutation testing, <shard>` job each, sized
  for a three-hour limit on a standard runner; a test fails
  when a PHP file of the two directories is in no shard or in two. Each
  job enforces no minimum and publishes its shard's score, the counts and
  the first untested changes in the job summary. The runner's score counts
  a change whose tests ran into the time limit as noticed, so the summary
  also gives the share that a failing test noticed; a job that is stopped
  at its time limit is red, gives no score and says how many changes the
  run got through. It does not run for a pull request, because a run takes
  hours of a runner, and it is not part of `composer ci`. Infection was
  tried first and is not used: it has no adapter for Pest, and through its
  PHPUnit adapter it counts every change as noticed, because it does not
  recognise the result line Pest prints.

- `SchedulingContext::getEventsBetween()` returns the events two
  participants both take part in, under the keys they have in
  `getExistingEvents()` and in that order. It is the lookup for a constraint
  about a pairing's history, and it reads the pair's own events, not the
  whole schedule.
- A benchmark suite (`composer bench`, in `tests/Benchmark/`): schedule
  repack at 24 and 40 participants, round robin with no constraints, with
  retries and with no solution, and Swiss pairing under a constraint.
  `composer bench-compare` measures another commit's `src/` and the working
  tree by turns on one machine and fails when the working tree is more than
  1.5 times slower; the CI job `Benchmarks` runs it for a pull request
  against its base. The job is not a required check, and the suite is not
  part of `composer ci`. The runner (phpbench) is installed by `composer
  bench-install` from `tools/phpbench/`, with a lock file of its own, and
  is not a development dependency of the library: it requires an abandoned
  package, which must not enter the library's `composer.lock`.

- Two examples, and a check that keeps the integration guides honest.
  Documentation and examples only; the library is unchanged.
  - `examples/23-application-adapter-and-repack.php`: the adapter an
    application writes. Its own records go in as participants with the
    primary key as the ID, the schedule is copied into fixture rows with the
    round, both roles and the byes, and a repack moves the fixtures that may
    move onto a shape-only grid with unbounded capacity around one pinned
    fixture. The violations are read through the typed accessors, and a
    previewed plan is applied only when computing it again gives the same
    `fingerprint()` on the same nights: a shape-only grid holds no times, so
    the fingerprint is the same when a kickoff is moved, and the example
    compares the nights itself.
  - `examples/24-recording-bracket-results.php`: a bracket kept as JSON
    between requests and stamped with the engine fingerprint. A level event
    is recorded with `tie_winner`, a wrong result is corrected with
    `withResultReplaced()`, and three refusals are told apart by their
    reason (`EngineFingerprintMismatch`, `UndecidedTie`, `RoundSuperseded`).
  - `tests/Feature/IntegrationGuidesTest.php`: every `php` block of a guide
    in `docs/integrations/` is marked as an excerpt of a runnable example or
    as schematic framework code. An excerpt must match its example line for
    line, every block must parse and import only library classes that exist,
    every class, method, constructor argument and enum case the prose names
    in backticks must exist, and every link must lead to a file and a heading
    that exist. In a schematic block, every static member of an imported
    library class and every method called on an object must exist in the
    library, unless the method is in the test's list of framework and
    application methods. `AGENTS.md` says how the guides are checked.

### Changed

- Several operations cost less. **These changes alter no output for any
  input** (the outputs this release does change are under "Output change
  (fix)"): every
  schedule, pairing, repack outcome (assignments, unplaced events,
  violations, `isBudgetExhausted()` and `fingerprint()`), exception and
  diagnostic report is what it was, and a step budget stops every search at
  the step it stopped at before. The golden fixtures are unchanged. The
  figures are CPU time on one core, PHP 8.4 without OPcache, before and
  after; `composer bench` measures your own machine.
  - **A round robin that cannot be completed.** The scheduler tries up to
    min(participants, 25) rotated orderings and reports the last. It built
    a full failure analysis for every one and threw all but the last away;
    it now builds the one it reports. With `NoRepeatPairings(acrossLegs:
    true)` over two legs, which no schedule satisfies: 0.71 s to 0.012 s at
    12 participants, 4.0 s to 0.030 s at 16, 15.5 s to 0.068 s at 20, and
    45.9 s to 0.13 s at 24. A schedule that succeeds on a later ordering
    paid for the discarded analyses too: `SeedProtectionConstraint(2,
    0.25)` at 32 participants over two legs, 0.41 s to 0.013 s.
    `ScheduleOptimizer` pays it once per sample. This applies to a
    `ConstraintSet` that holds only `NoRepeatPairings`,
    `MinimumRestPeriodsConstraint`, `RoleBalanceConstraint` and
    `SeedProtectionConstraint` objects. An analysis asks the constraints
    about pairings the ordering never tried, so a constraint that runs
    code of yours (a callable, a role extractor, a metadata validator, a
    class or subclass of your own, a subclass of `ConstraintSet`) could
    keep state or throw, and then answer the next ordering differently.
    For those every ordering is still analysed and nothing changes,
    neither the result nor the time.
  - **Constraints that read the history.** `SchedulingContext` answers its
    lookups by participant, pairing, round and leg from an index of its
    events, where every lookup scanned the whole schedule. A check costs
    the events it concerns, not the length of the schedule.
    `NoRepeatPairings` over two legs: 0.12 s to 0.009 s at 32 participants
    and 2.1 s to 0.07 s at 64. `NoRepeatPairings`,
    `MinimumRestPeriodsConstraint(2)`, `ConsecutiveRoleConstraint::homeAway(3)`
    and `RoleBalanceConstraint::homeAway(3)` together at 32 participants:
    0.79 s to 0.063 s. A custom constraint written against the context gets
    the same lookups, with the same results under the same keys.
  - **Backtracking round robin** (`RoundRobinOptions(backtracking: true)`).
    The search changes one path's state in place and extends one context,
    where every pairing attempt copied the events placed so far. With a
    constraint that reads the history, spending the whole budget of 200,000
    attempts went from 18.0 s to 1.8 s (`RoleBalanceConstraint::homeAway(2)`,
    20 participants). With a constraint that answers in constant time it
    is unchanged, at 0.5 to 0.8 s: that time is the attempts themselves.
    The docblock of `BacktrackingRoundRobinGenerator::STEP_BUDGET` said
    "well under a second" and now states these figures.
  - **Swiss pairing.** With no constraints, or with a `ConstraintSet` that
    holds only the four constraint classes named above, the search for a
    round no longer walks a branch that provably holds no complete pairing:
    a set of unpaired participants that cannot be split into pairs that
    have not played and that the constraints accept. It returns the
    pairing the plain search returns, or fails as it fails, sooner. Under
    any other constraint the search is the plain one, and asks each
    constraint exactly what it asked before, in the same order: a branch
    with no pairing in it may still hold a question your constraint throws
    on or counts. A state
    with no pairing left (two halves of 11 that have played every pairing
    across the halves): 32 s to 0.003 s before `NoValidPairingException`.
    `SwissScheduler`, 24 participants over all 23 rounds: 1.5 s to 0.018 s.
    The engine with results recorded, 32 participants over 24 rounds: 4.8 s
    to 0.05 s; 64 participants over 28 rounds did not finish in two minutes
    and takes 0.13 s. Without constraints the engine no longer builds a context
    per candidate, which had cost a copy of every recorded event each time.
  - **Schedule repack.** The exact packing of a session keeps the number of
    options of each participant up to date as it matches and releases
    pairs, where it counted them afresh for every participant at every
    step, and the load planner works out the parity score of each load
    shape once. A complete single round robin with the default budget: 24
    participants, 0.11 s to 0.05 s on eight sessions of four slots and
    1.1 s to 0.5 s on six sessions of five slots with six pins; 40
    participants, 1.7 s to 0.6 s on twelve sessions of four slots and 2.1 s
    to 0.7 s on eight sessions of six slots with ten pins. A request that
    uses the whole budget still takes half a second to a second or so (the
    benchmark suite's slowest, 40 participants on sessions of four slots,
    went from 2.7 s to 1.3 s): the budget counts steps, and each now costs
    less.
  - **Repack of sessions with many slots.** The repacker works out which
    start slots can give gap-free runs with tables that grow with two to
    the power of the slot count, before it spends a step. With no step
    left it now decides the one thing it needs (whether any placement
    exists) without the largest table, and it counts runs of one length
    where it applied them one by one. Two sessions of 20 slots, 24
    participants and 100 events with a budget of 1: 28 s and 385 MB to
    4.2 s and 51 MB. With the default budget a session of 16 slots: 2.7 s
    to 1.0 s. **Still slow, and left for a release that may change
    output**: with steps to spend, a session of 17 to 20 slots still builds
    the full table, in seconds and hundreds of megabytes. Making that work
    count against the budget would change what a given budget returns, so
    it is not done here. `IntervalPlacement::MAX_SLOTS` is unchanged.
- The timing statements in the documentation say what was measured: the
  cost of the failure analysis (`docs/design/diagnostics-attribution.md`),
  of the backtracking budget (`docs/design/backtracking-generation.md`), of
  a repack and of wide sessions (the usage guide, "The Step Budget"), and
  the performance section of `docs/ARCHITECTURE.md`, which claimed a
  context cache that did not exist and memory that grows linearly with the
  number of participants.
- Six types that were public and unmarked are now `@internal`:
  `Scheduling\BacktrackingRoundRobinGenerator`, the traits
  `Scheduling\EliminationBracketSupport` and
  `Validation\ValidatesScheduleCompleteness`, `Validation\ScheduleValidator`,
  `Diagnostics\SchedulingDiagnostics` and `Timeline\ZonedTime`. All six are
  in experimental namespaces. The schedulers, the engines and the
  `fromArray()` factories use them, and nothing in the usage guide, the
  examples or the integration guides tells an application to. They behave as
  before; the annotation says that they carry no compatibility guarantee. An
  application that uses one directly should stop: turn the backtracking
  search on with `RoundRobinOptions(backtracking: true)`, check a schedule
  with the plan's `validateIntegrity()`, read a failure from the exception a
  scheduler throws, and pass a configured datetime to the `fromArray()`
  factory that reads it. The public method the validation trait declares,
  `getViolationCollector()`, is still part of each scheduler that uses the
  trait. The protected members it supplies (`$validator`,
  `$violationCollector`, `initializeValidation()`,
  `validateGeneratedSchedule()`, `recordViolation()` and `clearViolations()`)
  are internal with it: a subclass of `RoundRobinScheduler`, `SwissScheduler`
  or `PotDrawScheduler` that uses one should stop.

- The refusal of a drawn single-leg elimination event that names nobody
  now says how to record the decision. Its message begins with the words it
  had and goes on, so code that matches the beginning still matches and
  code that compares the whole message must change; the exception class,
  `getReason()` (`UndecidedTie`) and `getContext()` are unchanged. Before
  and after, for Alice against Dave:

  ```
  Invalid scheduler configuration: Elimination events cannot end in a draw (Alice vs Dave)
  Invalid scheduler configuration: Elimination events cannot end in a draw (Alice vs Dave): the event is level, so record who advances as 'tie_winner' metadata on its result
  ```
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

- The integration guides are rewritten, and three examples present other
  results. Documentation and examples only; the library is unchanged.
  - `docs/integrations/symfony.md` described service registration, options
    read from container parameters, a schedule stored as JSON and a console
    command, said that its snippets were executed (they were not), and did
    not mention repacking. It now describes the adapter an application owns:
    participants in and what makes a good ID, the output copied into the
    application's own records and when storing the library's JSON is right,
    results-driven stages (the engine fingerprint, a round in play, a level
    event, a corrected result), repacking (what may move, the two forms of
    grid, reading the outcome, preview and confirmation by fingerprint, the
    step budget), errors (one catch, the reason, who fixes what) and time.
    Its library usage is quoted from the examples and checked against them.
    `docs/integrations/laravel.md` is now the three places where the
    framework shows, and refers to the Symfony guide for the rest.
  - Both guides said that list position decides how every stage is seeded.
    That is not so for a Swiss stage: before a result exists the engine
    pairs in the order of the standings, which is the `seed` attribute, then
    the label, then the ID. The guide says so, and
    `examples/18-stateless-web-flow.php` no longer says the opposite in a
    comment.
  - `examples/10-complex-tournament.php` held four constraints of which two
    changed nothing (`noRepeatPairings()`, and a minimum gap the two legs
    already give): the season is the same with them and without. It holds
    the other two. Its results no longer report a gap between repeat
    meetings, and the count of constraints is 2.
  - `examples/14-groups-to-knockout.php` says in a comment what its check of
    unplayed pairings does not see in a pool of several legs.
  - `examples/11-error-handling.php` caught `SchedulingException` as the
    catch-all, which lets an `InvalidInputException` through. It catches
    `TacticianException`, shows a scheduling failure and a rejected argument
    caught by it, and reports the reason of the configuration error.
  - `examples/19-repacking-a-season.php` declared a deeper final session
    that no event used. The sessions now have two slots and the final one
    four, the plan uses them, and the example reports how many events find
    no position without the deeper session.
  - `examples/20-double-elimination.php` said in a comment that rank 1 of
    the standings is the title holder. In double elimination the entrant who
    lost the grand final can be level with the one who won it. The comment
    says to read the winner of the final round.
  - The fixtures of examples 10, 11 and 19 in
    `tests/Fixtures/golden/examples/` changed with them. No fixture outside
    `examples/` changed.

### Deprecated

Each method below still works and returns what it returned before. It is
removed in `1.0.0`. It carries the `@deprecated` docblock tag and PHP's
`#[\Deprecated]` attribute: from PHP 8.4 a call emits an `E_USER_DEPRECATED`
notice, and on PHP 8.3 the attribute does nothing. An application that turns
notices into exceptions will see such a call fail on PHP 8.4 or later. The
usage guide lists the same methods under "Deprecations".

- `SchedulingContext::getTotalLegs()`. It answers 1 for a format that has no
  legs (Swiss, elimination), which is not a fact about the stage. Read
  `getPlan()->getLegs()` instead: it is `null` where the concept does not
  apply. `isMultiLeg()` and `getEventsForLeg()` answer as before and no
  longer call it, so a subclass of `SchedulingContext` that overrides
  `getTotalLegs()` no longer changes what those two answer.
- The three static factories of `SchedulingException`:
  `invalidParticipantCount()`, `constraintViolation()` and
  `invalidSchedule()`. Nothing in the library calls them. Construct an
  `InvalidConfigurationException` with the `InvalidConfigurationReason`
  instead; the usage guide gives the arguments that build exactly what each
  factory built.
- `ScheduleValidator::generateDiagnosticReport()` and
  `ScheduleValidator::generateConstraintSuggestions()`. Nothing in the
  library calls them, and there is no replacement: the report of a generation
  that failed is `IncompleteScheduleException::getDiagnosticReport()`, and
  its suggestions are in the `DiagnosticReport` that `getAnalysis()` returns.
- `SchedulingDiagnostics::identifyConstraintConflicts()` and
  `SchedulingDiagnostics::suggestConstraintAdjustments()`. Nothing in the
  library calls them, and there is no replacement: the suggestions of an
  analysis are `DiagnosticReport::getSuggestions()`.

### Fixed

- Documentation only; the library is unchanged. The guides were checked
  statement by statement against `src/`, and the statements below were
  false. If you relied on one of them, the behaviour is what it always was;
  what changed is the text.
  - **Rank 1 of an elimination outcome's standings is not always the
    participant who won the bracket.** The usage guide and the architecture
    document said the champion is "rank 1 of the standings, or the winners
    of the final round". The table is a win/loss record of the whole stage.
    In a double-elimination bracket of eight, a participant who loses
    round 1, wins the losers bracket and the first grand final is rank 1
    with five wins, above the participant who wins the reset. Two finalists
    of a two-legged tie who win a leg each are level in the table whatever
    `tie_winner` says, and a bye is no win in a bracket's table, so the
    fallback order can put the runner-up first. Read the winner with
    `MatchOutcomeSelector::winners()`. The usage guide shows all three
    cases under "Who won the bracket".
  - **"The same seed always produces the same schedule" holds for a new
    scheduler, not for a second call.** A scheduler or engine that holds a
    `Random\Randomizer` draws from it on every call, so its second
    `schedule()` returns a different schedule; a new scheduler with the same
    seed repeats the first. `RoundRobinOptions::fromArray()` with
    `'strategy' => 'shuffled'` builds a `ShuffledLegStrategy` with no
    randomizer, so that schedule is not repeatable even from a seeded
    scheduler.
  - **A `RoleBalanceConstraint` limit of 3 (field of even size) or 4 (odd)
    is not "always satisfiable".** It holds for one leg and for two or three
    mirrored legs. It fails for four mirrored legs and for two repeated legs
    at four participants and from six up, and can fail for a seeded
    scheduler over several legs. The usage guide has the table.
  - **Timeline intervals in hours are elapsed time.** The guide said
    interval arithmetic is wall-clock, so that a weekly kickoff keeps its
    time across a daylight-saving change. That is true of `P7D` and `P1D`.
    `PT168H` and `PT24H` are an hour off after the change.
  - **The seed attribute does not place anyone in a bracket, a pool or a
    pot.** The glossary said it drives bracket placement and pool
    distribution; those read list position. The attribute is read by
    `SeedProtectionConstraint` and by the last fallback of the standings
    order, which the glossary did not say.
  - **`MinimumRestPeriodsConstraint` is not about time or about a
    participant's rest.** The architecture document called it "time-based
    rest period enforcement". It counts the rounds between two meetings of
    the same pair, and rejects nothing in a single leg.
  - **`docs/ARCHITECTURE.md`, second half, rewritten.** It described lazy
    evaluation and generator patterns (a schedule is built whole and held in
    an array), memory that grows linearly with participants (it grows with
    the events, which is quadratic), `SchedulingException` as the base of
    all exceptions (the marker interface `TacticianException` is; four
    classes are outside `SchedulingException`), every DTO as a readonly
    class (`Schedule` is not, and has one iteration cursor: a loop over a
    schedule nested in a loop over the same object ends the outer one), a
    round-robin plan that makes a disagreement between plan and generator
    "impossible by construction" (the generator lays out its own rounds, and
    the finished schedule is checked against the plan), leg strategies that
    plan against the constraints (none reads them), multi-leg awareness in
    every component (legs belong to round robin), double elimination as a
    graph an application could compose from library parts (no public part
    pairs one knockout round), and a component diagram of the round-robin
    path only. It now also says what the event index is, what the library
    assumes of a constraint, and which namespace refers to which.
  - **What a custom constraint may rely on** is stated in the usage guide:
    the answer must depend only on the event and the context, and neither
    the number of times a constraint is asked nor the order is promised. A
    rejected event is asked about a second time, retries and searches ask
    again, and a failure analysis asks about events that were never
    candidates. The library does not rely on this where relying on it would
    change what a constraint that breaks it gets back: the two shortcuts of
    the performance entry under "Changed" are taken only for constraints
    that run no code of yours.
  - **Smaller corrections in the usage guide**: `StandingsCalculator`
    recognises a repeated event by the `Event` object, so two objects for
    one match are counted twice; `SeedProtectionConstraint` and the
    `ConsecutiveRoleConstraint` factories look for the participant object
    and do not recognise a second object with the same ID; a
    `SwissScheduler` without a randomizer pairs the same way on every
    call, in the order of a table with no results (seed, label, ID) and not
    in list order, and refuses more rounds than participants minus one; the
    timeline assigner does not check that a participant is in one event at
    a time unless a `MinimumRestRule` is given; a serialized schedule lists
    participants in order of first appearance, not in the entrant order;
    `ScheduleOptimizer` skips a sample only for an
    `IncompleteScheduleException`.
  - **More corrections in the usage guide**: in a results-free Swiss
    schedule of a field of odd size the participants are not all level,
    because a bye counts as a win in the pairing order (and as nothing in
    the outcome's table), and the bound of participants minus one rounds
    refuses a field of odd size a last round it could play without a
    repeat; a bracket re-seeded each round ranks an entrant who had a bye
    below the winners of that round; a constraint asked by the
    backtracking search or the Swiss round search sees the pairings already
    made in the candidate's own round; `CompositionValidator` counts a
    `MatchOutcomeSelector` as the hand-off after one knockout round, which
    is not what the selector returns from a whole bracket; a zone written
    into a configured datetime must be the declared `timezone` spelled the
    same way (`+01:00` is refused under `Europe/London`, a Unix timestamp
    under `UTC`); a kickoff in the hour the clocks skip moves every later
    round; malformed or out-of-range configuration is an
    `InvalidConfigurationException` and not an `InvalidInputException`, and
    `Schedule::fromArray([])` is an empty schedule and not an error; an odd
    pot-draw field of one entrant is reported as too few, not as odd; a
    `SlotAssignment` built by hand writes its kickoff out unconverted.
  - **`AGENTS.md` and `docs/CONTRIBUTING.md`** no longer say that a stage
    never reads the seed attribute, that nothing derived from a table
    depends on input order, or that every DTO is readonly.
  - **`docs/ROADMAP.md`** says in which release each part shipped, and its
    known limitations are the ones above. **The design notes** state their
    status (two still read "implementing" or "proposed" for features that
    shipped in 0.1.0) and
    list where the shipped code differs from the design: there is no
    `GroupStagePlan`, selectors do not reseed with `withSeed()`, and the
    elimination engines are not compositions of single-round stages.
  - **Two entries of this changelog**: 0.1.0 named a `PointsSystem` class,
    which that release did not contain (it shipped `RankingStrategy` and
    `WinDrawLossRanking`), and 0.2.0 called the repacker "seeded" (it takes
    no seed and draws nothing at random).
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
- Test suite only; the library is unchanged. Tests that proved less than
  their titles said now prove it, or are gone:
  - `tests/Feature/ComplexConstraintTest.php` asserted for four constraint
    sets that generation throws `IncompleteScheduleException`, and took the
    exception for proof that no schedule exists. The scheduler is greedy and
    also throws for sets a schedule satisfies, so each case now first shows
    by counting, checked against the constraints themselves, that no round
    is left for some pairing. One of the four was not infeasible: a limit of
    two same-role events in a row over two shuffled legs of eight
    participants is satisfied by a schedule the library itself generates
    with mirrored legs. That test also drew its roles from an unseeded
    randomizer. It is now a seeded test of the loud failure, a test that
    shows the satisfying schedule, and a test of the wanted behaviour marked
    as not yet met. A test with that mark is not run and does not fail a
    run, so `tests/Feature/GateConfigurationTest.php` names the tests that
    may carry it: this one, and no other until it is listed there.
  - Three tests of `ScheduleValidator` whose only assertion was
    `expect(true)->toBeTrue()` now assert that the valid schedule is
    accepted and that the nearest invalid one is refused. The test titled
    "handles zero participant scenario without division error" asserted that
    `DivisionByZeroError` is thrown; it is now named for that, and covers
    one participant as well as none.
  - A test that timed a closure against the wall clock is removed (the value
    it also asserted is asserted by the tests beside it). A "stress test"
    is renamed for what it checks. Two seed-protection tests that asserted
    "fewer top-seed meetings than some number", which the unconstrained
    schedule also satisfied, now assert that no two protected seeds meet
    inside the window and that they do without the constraint. The two
    tests of mirrored legs check every event and every participant, not the
    first two events and one participant.
  - A `use` statement without effect in one test file made PHP print a
    warning at the start of every run; it is removed.
- Documentation only; the library is unchanged. The docblocks in `src/` no
  longer say what the code does not do, and every public method and
  constructor of a type marked `@api` or `@experimental` now states its
  contract: what it guarantees about its result, what it requires of its
  arguments, and what it throws and when. The statements that were false:
  - The class docblocks of `SingleEliminationEngine`,
    `DoubleEliminationEngine` and `StageOutcome` named rank 1 of an
    outcome's standings as a way to read the champion, and
    `getStandings()`/`getOutcome()` said the table reproduces bracket
    placement with joint ranks. The table is a win/draw/loss record in the
    standings calculator's order, with no joint ranks. In double elimination
    a participant who wins the losers bracket and the first grand final and
    loses the reset can rank above the title holder; a two-legged final that a
    tie decision settles leaves the finalists level; a finalist who had a
    bye has a win fewer. Read who won a bracket from its final round, with
    `MatchOutcomeSelector::winners()`.
  - `RoleBalanceConstraint` called limits of 3 (a field of even size) and 4
    (odd) "always satisfiable", and mirrored multi-leg schedules "perfectly
    balanced". That holds for a single leg and for two or three mirrored
    legs (only two end balanced). It does not hold for the repeated leg
    strategy at two or more legs, for four mirrored legs, for the shuffled
    strategy, or for a multi-leg schedule from a scheduler with a
    randomizer.
  - `MinimumRestPeriodsConstraint` now says what it measures: the rounds
    between two meetings of the same pair, not a participant's rest and not
    time, so it rejects nothing in a single leg. `Timeline\MinimumRestRule`
    no longer calls itself its time-aware counterpart.
  - `TimelineDefinition` and `SessionGrid` said interval arithmetic is
    wall-clock and that a weekly kickoff keeps its local time across a
    daylight-saving change. That is true of the date part of an interval
    (`P1D`, `P7D`), except for a local time the clocks skip, which is moved
    on by the skipped hour for that round or slot and every later one. The
    time part is elapsed time: `PT24H` and `PT168H` move the local time by
    an hour across the change.
  - `TimelineDefinition::fromArray()`, `BlackoutRule::fromArray()` and
    `SessionGrid::fromArray()` referred to `ZonedTime`, which is internal,
    for the datetime form they accept. They state the form.
  - `StandingsCalculator` said a participant without results is placed at
    the bottom of the table (it is placed by its zeroed record like any
    other, above a participant level with it whose score difference is
    below zero), and described the last
    steps of the order as "natural-order label and ID comparison" (the
    label is compared in natural order ignoring case, the ID as PHP
    compares two strings).
  - `SwissScheduler` and `SwissPairingEngine` described the results-free
    preset as random pairing of a field tied at zero. In a field of odd
    size a recorded bye counts in the pairing order, and without a
    randomizer nothing is random.
  - `RepeatedLegStrategy` and `MirroredLegStrategy` said later legs repeat
    or mirror the first exactly, which holds only for a scheduler without a
    randomizer; `LegPlanContribution` said its design makes drift between
    the plan and the generator impossible, although nothing checks a
    strategy's flags against the events it generates;
    `LegStrategyInterface::generateEventForLeg()` did not say that it is
    called for legs 2 and up only.
  - `RoundRobinPlan` called itself the single home of the round-robin
    arithmetic that generation reads; the scheduler lays out the rounds of a
    leg from the size of the field itself, and the validation of the
    finished schedule is what catches a difference. `SchedulingContext`
    said it never infers a shape fact; it treats a plan without legs as one
    leg. `Participant::withSeed()` was described as the way to reseed for a
    new stage; stages seed from list position, and the copy is not
    recognised by the two constraints that match a participant as the same
    object.
  - `Schedule` called itself immutable and able to add metadata: its events
    and metadata never change, but it is its own iterator and keeps a
    cursor, and nothing adds metadata. `Round::getMetadataValue()` returns
    null, not the default, for a key whose value is null.
  - `SchedulerOptions::fromArray()` said unknown values fail loudly; only
    `PotDrawOptions` refuses an unknown key.
    `ProgressionSelector::select()` said the selection is ordered best
    first; a match-outcome selection is in bracket order.
    `ScheduledSchedule` said its events are in kickoff order; they are in
    the order given. `TimelineAssigner` said a round fails with more events
    than slots; the limit is slots times capacity.
    `ViolationKind::CapacityExceeded` named a session scope that is never
    produced. `IncompleteScheduleException` named constraint violations as
    its only cause.
  - Several `@throws` tags named fewer conditions than the code has, among
    them those of both elimination engines, `ScheduleScorer`,
    `SwissOptions::fromArray()`, `EliminationOptions::fromArray()`,
    `BlackoutRule` and `Round::fromArray()`; one named a condition that
    never throws (`SwissPairingEngine::getOutcome()` with fewer than two
    participants). The array shapes of `StageState::toArray()` and
    `ScheduledSchedule::toArray()` lacked a key the arrays have.

  `Constraints\ConstraintInterface` now states what a constraint of your
  own may rely on: it should answer from the event and the context alone;
  it is asked more often than the schedule has events, about events that
  are never scheduled, and in an order that is not part of the contract;
  one that keeps state or throws is not refused, no shortcut is taken for
  it, and it is asked every question it was asked before this release's
  two shortcuts existed. This describes what the library already does.

  No tag changes what static analysis infers, but for these: the
  array shapes named above, `@return $this` on the three chaining methods
  of `ConstraintSetBuilder`, and `@throws InvalidInputException` (a
  `\LogicException`) on `ConsecutiveRoleConstraint::homeAway()` and
  `position()` and on `RoleBalanceConstraint::homeAway()`, whose
  constructors already declared it. No method declares a new checked
  exception.
- Tooling only; the library is unchanged. The Rector step of `composer ci`
  no longer fails now and then with `Child process error` and a syntax error
  that names `bc7465525847387785d7c`, on a change that Rector does not read.
  Rector 2.6.7 writes a cache entry in place, and each of its parallel
  workers loads and rewrites the entry for the configuration when it starts,
  so a worker could load an entry that another had half written. `rector.php`
  now runs Rector as one process, which checks the same paths with the same
  rules and takes about twice as long (some 25 seconds more for each CI
  job). Rector 2.7.0 writes the entry atomically; a test
  (`tests/Feature/GateConfigurationTest.php`) requires the one-process
  setting until that is the locked version, and from then on fails until the
  setting is removed.

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
- Deterministic, step-bounded solving configured through `RepackOptions` (the
  repacker draws nothing at random and takes no seed). Kickoffs serialize in
  the timeline family's UTC format.
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
- **Standings**: `StandingsCalculator` with a pluggable `RankingStrategy`
  (`WinDrawLossRanking`) and pluggable tiebreakers.
- **Constraints**: the `ConstraintSet` builder; constraints are hard filters
  that fail loudly with diagnostics instead of silently dropping matches.
- **Timeline**: deterministic UTC kickoff assignment that decorates events
  into scheduled schedules.
- **Schedule quality**: `ScheduleScorer`, which composes weighted quality
  metrics (role balance, role streaks, rest spread, pairing spacing), and
  `ScheduleOptimizer`, which keeps the best of N seeded candidate schedules.
- **Immutable DTOs**: readonly value objects with `toArray()`/`fromArray()`;
  `Schedule` round-trips JSON.

[Unreleased]: https://github.com/mission-gaming/tactician/compare/v0.2.3...HEAD
[0.2.3]: https://github.com/mission-gaming/tactician/compare/v0.2.2...v0.2.3
[0.2.2]: https://github.com/mission-gaming/tactician/compare/v0.2.1...v0.2.2
[0.2.1]: https://github.com/mission-gaming/tactician/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/mission-gaming/tactician/compare/v0.1.1...v0.2.0
[0.1.1]: https://github.com/mission-gaming/tactician/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/mission-gaming/tactician/releases/tag/v0.1.0
