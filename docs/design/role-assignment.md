# Design note: Role Assignment

**Status: IMPLEMENTED.** `BalancedRoleAssignment` shipped as an opt-in in
0.2.2 (`RoundRobinOptions(roleAssignment: new BalancedRoleAssignment())`)
and is the default since 0.3.0. [The default](#the-default) records that
decision, the bar it had to meet, and what was built to meet it.

The usage guide states what a caller can rely on
([Role Assignment](../USAGE.md#role-assignment)). This note records why the
design is what it is and why the limits hold.

## The problem

The circle method decides who meets whom in which round. It says nothing
about roles: which participant of a pairing is first-named. The generator
alternated roles with round parity, which bounds how far a participant's two
role counts drift apart and does not make them equal. A single leg ends 3
apart in a field of even size and 4 apart in a field of odd size, and of four
participants one is second-named in all three of its events.

A leg can do better. A participant in a field of even size plays an odd
number of events in a leg, so 1 apart is the least possible. In a field of
odd size it plays an even number, so 0 apart is possible.

## Settled decisions

- **A strategy, selected through the options.** `RoleAssignmentInterface`
  with two implementations, in the manner of the leg strategies. The default
  can change in a minor release without a change to any signature, and a
  caller can write a rule of its own.
- **The generator proposes, the role assignment disposes.** `assignRoles()`
  receives one leg with the roles the generator would have used, and returns
  it with seatings reversed. `RoundParityRoleAssignment` therefore returns
  its input, and the default path of the scheduler produces what it always
  did: the golden fixtures did not change. The alternative, handing over
  seats without roles, would have moved the round-parity rule out of the two
  generators and changed the backtracking search, whose preference between
  the two orientations of a pairing is that same rule.
- **Roles only.** The scheduler compares the answer with what it handed over
  and refuses anything that is not the same seatings in the same places, each
  unchanged or reversed (`InvalidConfigurationReason::InvalidRoleAssignment`).
  A role assignment cannot move a pairing to another round, so the plan, the
  byes and every constraint that does not read roles are unaffected by it.
- **Before the constraints.** The roles of a leg are decided before any
  constraint sees an event, and each event is then checked as before.
  Constraints are hard filters, and a schedule that silently had other
  roles than the ones asked for would be worse than a failure: so a role is
  changed only where the contract of whoever decided it leaves it free
  ([backtracking-generation.md](backtracking-generation.md#roles-across-legs)).
  In 0.2.2 that left the first leg of a role assignment untouched, at a cost
  that was real and was accepted then: a role assignment did not see the
  constraints, so a constraint that fixes the role of one pairing could
  reject the balanced roles although other balanced roles would satisfy it,
  and the scheduler did not look for them. Of the 729 ways to fix the roles
  of some of the six pairings of four participants, 143 failed with the
  balanced roles, with backtracking on, although a balanced schedule that
  satisfies them exists; 67 of those succeeded with the round-parity roles
  and no backtracking. That gap is what stood between the balanced roles
  and the default, and 0.3.0 closes it without giving the principle up: the
  role assignment says which roles it keeps, and the scheduler looks among
  those ([The default](#the-default)).
- **Leg strategies keep their meaning.** The role assignment decides the base
  roles of every leg the circle method lays out; the leg strategy then
  mirrors, repeats or shuffles them exactly as it did. When this was decided,
  "mirrored" meant that every leg after the first had the roles of the first
  reversed, so four mirrored legs were not balanced in a field of even size.
  Since 0.3.0 `MirroredLegStrategy` alternates (the even-numbered legs are
  reversed), which was a change to the leg strategy and none to the role
  assignment: an even number of mirrored legs is balanced, and an odd number
  is one apart in a field of even size.
- **A searched leg is one the role assignment keeps, or it is not
  returned.** The backtracking search chooses roles while it satisfies the
  constraints, so its leg is consistent and may be far from balanced.
  Keeping the search's roles would return an unbalanced schedule to a
  caller who asked for a balanced one, with nothing to say so. Since 0.3.0
  the roles of every first leg the search finds are searched within the
  rule of the role assignment, so a balanced leg that the constraints accept
  is found where the layout has one, and a layout without one sends the
  search on to the next. (In 0.2.2 the role assignment was asked about the
  leg afterwards, its changes were replayed against the constraints, and a
  rejection ended the search with an `IncompleteScheduleException`; that is
  still what happens under a role assignment that states no rule.)

## The circle rule

Let the field have `N` seats (the participants, plus a bye seat when their
number is odd). Seat 0 never moves; the other `N - 1` seats rotate by one
place per round. In every round seat `k` meets seat `N - 1 - k`, for
`k = 0 .. N/2 - 1`. Call that pairing "seating `k`".

The rule, in terms of the seats:

- Seating `k >= 1`: the participant at seat `k` is first-named when `k` is
  odd, and the participant at seat `N - 1 - k` when `k` is even.
- Seating 0 (the fixed seat against seat `N - 1`), field of even size: the
  fixed seat is second-named in odd rounds and first-named in even rounds.
- Seating 0, field of odd size: the participant opposite the fixed seat takes
  the role the first rule gives it in the seating where it meets the bye
  seat.

The generator lists seating `k` as `[seat k, seat N - 1 - k]` in odd rounds
and reversed in even rounds, so in terms of the listing the rule is the one
in the class docblock: seating `k >= 1` keeps its listed roles when
`k + round` is even, and seating 0 is always reversed in a field of even
size.

### Why it balances

Follow one rotating participant. It moves from seat `N - 1` down to seat 1,
one seat per round, and then returns to seat `N - 1`. While it sits at seats
`N - 2 .. N/2` it is the second seat of seatings `1 .. N/2 - 1`, first-named
when the seating number is even. While it sits at seats `N/2 - 1 .. 1` it is
the first seat of seatings `N/2 - 1 .. 1`, first-named when the seating
number is odd. Each seating number from 1 to `N/2 - 1` therefore gives it one
event in each role: over its `N - 2` events away from seating 0 it is
first-named exactly `N/2 - 1` times and second-named exactly `N/2 - 1`
times.

- **Field of even size.** The one remaining event, against the fixed seat,
  leaves every rotating participant exactly 1 apart whatever its role there.
  The fixed seat alternates over its `N - 1` events and ends 1 apart.
- **Field of odd size.** One rotating seat is the bye. A participant loses the
  event against it, which leaves it 1 apart in the other direction; the third
  rule gives it the lost role against the fixed seat, so it ends 0 apart. The
  bye seat would have been first-named against half of the other rotating
  seats, so the fixed seat is first-named against exactly half of its
  opponents and ends 0 apart too.

### Why it rarely repeats a role

As a rotating participant moves down one seat, its seating number changes by
one and so does its role: it alternates. The role repeats once in the leg,
around the round in which it sits opposite the fixed seat (the two neighbours
of that round are seating 1 from either side, in opposite roles), and in a
field of odd size possibly once more where its bye removes one event from
the alternation. Measured for a single leg of 2 to 30 participants, no
participant plays more than two events in a row in the same role; the default
reaches four.

With the fixed seat second-named in round 1, the running difference of a
participant in a field of even size never exceeds 1 (measured for 2 to 30
participants, and pinned by the tests). The mirror image, with the fixed seat
first-named in round 1, balances the leg equally and lets the running
difference reach 2, which is why the rule reverses seating 0 in a field of
even size.

## Any other layout

The circle rule balances a circle layout and nothing else. A first leg from
the backtracking search is not one. `BalancedRoleAssignment` therefore checks
its own result, and its answer is the first of three that is balanced: the
roles as given, the circle rule, a repair of the roles as given.

The repair reverses chains of pairings. While a participant is first-named at
least 2 more times than second-named, there is a chain from it, each pairing
entered through the participant it names first, to a participant that is
second-named more often than first-named. (Take every participant such chains
reach. Each pairing between that set and the rest names the outside
participant first, so the differences inside the set sum to zero or less; the
start contributes 2 or more, so another member is below zero.) Reversing the
chain takes 2 off the difference of the start and adds 2 to that of the far
end, which was below zero and is now at most 1, and it leaves every
participant between them unchanged, because each of those has one pairing
reversed on either side. Each pass lowers the total excess over 1, so the
repair ends with every participant at most 1 apart, which is 0 apart for one
that plays an even number of events. The mirror image handles a participant
that is second-named too often.

The repair says nothing about streaks.

### Around fixed seatings

The same repair serves a leg in which some seatings may not be reversed,
because the scheduler found that a constraint rejects them the other way
round (`RoleAssignmentContext::isFixed()`): a chain then uses free seatings
only. Without fixed seatings a chain always exists. With them it may not,
and then no balanced leg exists at all. Take the set the chains from an
out-of-balance start reach through free seatings. No member is out of
balance the other way, or a chain would end there. Every free seating
between the set and the rest already names the outside participant first
(or second, for a start with too many second-named roles), and a fixed one
cannot change, so no choice of roles for the free seatings gives the set a
smaller sum of differences than it has; and that sum is too large for a
balanced leg, in which the members of a set sum to at most their number
where each plays an odd number of events and to exactly zero where each
plays an even number. So the repair is exact: it finds a balanced leg around
the fixed seatings whenever there is one. This is the orientation problem a
flow computation solves; reversing a chain is an augmenting path, and the
argument above is the cut that shows there is none left.

The circle rule is left out when a seating is fixed, since it decides every
seating. A leg that cannot be balanced comes back with the fixed seatings as
given and is not used: the scheduler holds every leg to the rule the role
assignment states.

## The default

**Decision (0.3.0, issue #51): `BalancedRoleAssignment` is the default.**
`RoundParityRoleAssignment` stays, and naming it gives the roles of 0.2.x.

### The bar

The balanced roles were held back in 0.2.2 for one reason, the gap recorded
under "Before the constraints". A downstream consumer passes no constraints
today and will, so the bar for the default was set as: with a constraint
that fixes or limits roles, generation succeeds whenever a balanced
assignment satisfying the constraints exists, for every leg strategy, and
fails loudly, naming the constraint, only when none exists; the 729 cases of
four participants have none that fails while a balanced leg exists; and the
role assignment receives what it needs for that, since the interface was
experimental and could still change.

### What was built

- **The interface says more, in both directions.** `assignRoles()` takes a
  `RoleAssignmentContext`: the leg, the number of legs, and the seatings
  the constraints have fixed. An object, so that more can be told later
  without breaking an implementer again. And the role assignment states,
  with `rule()`, what it holds a first leg to, as a constraint on one event
  (`LegBalanceRule` for the balanced one: no participant has a role more
  often in the leg than half its events rounded up), or null.
- **The constraints are not handed over.** The issue offered "the leg and
  the constraints, or a way to ask whether a seating is allowed". Neither
  was taken as it stands. Whether a seating is allowed is a question about a
  schedule, not about a leg: under mirrored or repeated legs the event that
  a constraint rejects may be in the third leg, and under shuffled legs it
  depends on draws that are made once. The scheduler already had the
  machinery that puts whole schedules to the constraints in order
  (`LegRoleSearch`), with its bounds and its account of what a failure
  showed. So the scheduler asks the constraints, and tells the role
  assignment what it learned (the fixed seatings); and the role assignment
  tells the scheduler what it would keep (the rule), so that a search of
  roles stays inside it.
- **Two steps, for two kinds of constraint.** A rule that fixes the roles
  of single pairings is the plain case and is not a search: the scheduler
  fixes each seating a constraint of unknown reach rejects and asks again,
  and the repair above balances the leg around them or shows that no
  balanced leg has those roles. One pass over the events for each seating
  fixed. A limit on streaks or on the role totals turns on the roles before
  an event, so no seating is "fixed" by it; there the roles of the first leg
  are searched, each choice held to the rule, from the roles the role
  assignment gave. The balance rule reads the two participants' own events
  only, so the search goes straight back to an event that matters
  ([backtracking-generation.md](backtracking-generation.md#going-back)).
- **Where it runs.** After the rotated orders have all failed with the
  roles as given, in each of those layouts again, without backtracking
  being asked for; and with backtracking, for every first leg the search
  finds. The rotations come first so that a schedule a rotated order
  completes is the schedule it was. A role assignment without a rule is
  treated as before in every respect, which is how the round-parity roles
  of 0.2.x stay byte for byte what they were, failures included.

### What was measured

- The 729 cases: no case fails while a balanced leg exists, with and
  without backtracking (`tests/Feature/RoleAssignmentTest.php`).
- Against an exhaustive oracle (`tests/Support/RoleScheduleOracle.php`),
  fields of three to five (and to eight for a single leg), one to four
  legs, the three leg strategies, limits on streaks and on totals, pinned
  roles and combinations: with backtracking, a schedule is returned exactly
  when some way to play the first leg has balanced roles that keep the rule
  in every leg, and every failure says "unsatisfiable"; without it, exactly
  when one of the circle layouts tried has such roles
  (`tests/Feature/RoleConstraintsAcrossLegsTest.php`). The 20 cases of three
  participants with a pinned role that the search of 0.2.x-era roles missed
  under the balanced role assignment are among them.
- Against the round-parity default of 0.2.x, over role limits (streaks of 1
  to 4, totals 1 to 4 apart), 3 to 16 participants, one to four legs and the
  three leg strategies, 1,120 configurations: none that completed before
  fails, and 438 that failed complete. Over pinned roles in fields of 3 to
  8: the configurations that completed before and fail now are those whose
  rule leaves no balanced leg at all (three participants, one of them named
  first in both of its events).

### What it costs, and what is short

- A schedule without constraints pays for the circle rule and nothing
  else. A constrained schedule that the roles as given complete pays
  nothing more than it did.
- A constrained schedule that fails pays for the search: one allowance of
  20,000 rejected roles and 200,000 events put to the constraints for all
  the layouts of a call. The passes that fix seatings count against the
  events: a closure that fixes the role of every pairing of 40
  participants, with no balanced leg, put 1.8 million events to the
  constraints in those passes before they were counted, and took 20
  seconds to fail where it now takes 3. Where a failed rotation would have had its failure
  analysed only to be thrown away, and the constraints are the library's
  own role limits, the analysis is built once, after the search.
- The search is bounded, and beyond its bound it does not know. Two
  mirrored legs under a limit of two in a row: found for every field of
  even size from 6 to 30 and for 3 to 11 participants of odd size; stopped
  at the bound for every field of odd size from 13 to 29, after some
  seconds. It says so, and does not say that no roles exist. Four
  participants have no first leg that keeps that limit, with any roles. A
  limit of three in a row is found for every field from 3 to 20 over one
  to four legs.
- Without backtracking only the circle layouts are searched. A limit that
  no balanced roles keep there, and that another way to play the leg would
  keep, needs `backtracking: true`.
- A constraint of unknown reach that rejects a seating both ways round is
  not proof that no roles exist, since it may read anything. The search
  that follows goes back one event at a time for such a rejection, and for
  a rule that truly leaves no schedule (a role pinned in every leg, over
  two mirrored legs) it ends at its bound, not with a proof.

## What is not promised

- **Streaks across legs.** The limit of two in a row is a property of one
  leg. Two mirrored legs meet with the roles of round 1 reversed, and a
  participant whose repeat sits at the edge of the leg can then play three
  in a row in a field of even size and four in a field of odd size (measured
  for 3 to 30 participants; four is reached with 5, 9, 13 and so on). For
  two mirrored legs the round-parity role assignment scores better on
  `RoleStreakMetric` for that reason in every field of even size from 6
  participants up, and with 5 participants. Both end balanced; only the
  balanced one is balanced at the halfway point. The circle rule could not
  be changed to avoid it without giving up the running difference of 1: the
  role against the fixed seat is the one free choice a rotating participant
  has, the running difference decides it one way, and the boundary of two
  mirrored legs wants it the other way for the two participants who meet
  the fixed seat in the last two rounds. So the rule stays, and a schedule
  that must not repeat a role three times says so with a
  `ConsecutiveRoleConstraint`: in a field of even size the scheduler then
  reverses those two seatings, which is the balanced leg its search finds.
  A field of odd size has no fixed seat, and there the search finds a leg
  under a limit of two up to 11 participants and stops at its bound from 13
  up ("What it costs, and what is short"); a limit of three, which rules
  out the run of four, it finds in every field measured.

A scheduler with a `Randomizer` is no longer among them. Up to 0.2.x it
shuffled the participant order of the first leg only and laid the later legs
out from the order as given, so its legs were balanced each on its own and
nothing more could be said of the whole. Since 0.3.0 every later leg derives
from the first leg, with a `Randomizer` as without, and what the usage guide
states about several legs holds for both.
