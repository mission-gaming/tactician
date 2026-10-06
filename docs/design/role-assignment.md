# Design note: Role Assignment

**Status: IMPLEMENTED** as an opt-in (`RoundRobinOptions(roleAssignment:
new BalancedRoleAssignment())`). It becomes the default in 0.3.

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
  constraint sees an event, and each event is then checked as before. A role
  constraint that the assigned roles break fails generation loudly. The
  scheduler does not try other roles: constraints are hard filters, and a
  schedule that silently had other roles than the ones asked for would be
  worse than a failure. The cost is real and is accepted here: a role
  assignment does not see the constraints, so a constraint that fixes the
  role of one pairing can reject the balanced roles although other balanced
  roles would satisfy it, and the scheduler does not look for them. Of the
  729 ways to fix the roles of some of the six pairings of four participants,
  143 fail with the balanced roles, with backtracking on, although a balanced
  schedule that satisfies them exists; 67 of those succeed with the default
  roles and no backtracking. A role assignment that is handed the constraints
  could close that gap. It would need a wider method than `assignRoles()`,
  which is one reason the namespace is experimental.
- **Leg strategies keep their meaning.** The role assignment decides the base
  roles of every leg the circle method lays out; the leg strategy then
  mirrors, repeats or shuffles them exactly as it did. "Mirrored" still means
  that every leg after the first has the roles of the first reversed, so four
  mirrored legs are not balanced in a field of even size. Balancing them would
  need a leg strategy that alternates, which is a separate feature.
- **A searched leg is balanced or refused.** The backtracking search chooses
  roles while it satisfies the constraints, so its leg is consistent and may
  be far from balanced. The role assignment is asked about that leg too. A
  leg it changes is replayed against the constraints, and a rejection is an
  `IncompleteScheduleException`. Keeping the search's roles instead would
  have returned an unbalanced schedule to a caller who asked for a balanced
  one, with nothing to say so.

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

The repair says nothing about streaks, and it does not know the constraints.
That is the reason for the replay described above.

## What is not promised

- **Streaks across legs.** The limit of two in a row is a property of one
  leg. Two mirrored legs meet with the roles of round 1 reversed, and a
  participant whose repeat sits at the edge of the leg can then play three
  in a row in a field of even size and four in a field of odd size (measured
  for 3 to 30 participants; four is reached with 5, 9, 13 and so on). For
  two mirrored legs the default role assignment scores better on
  `RoleStreakMetric` for that reason in every field of even size from 6
  participants up, and with 5 participants. Both end balanced; only the
  balanced one is balanced at the halfway point.
- **A scheduler with a `Randomizer`, over several legs.** The scheduler
  shuffles the participant order of the first leg only and lays the later
  legs out from the order as given. That predates role assignments and is
  unchanged. Each leg is balanced on its own, so a field of odd size is
  exactly balanced over any number of legs, and a field of even size is at
  most one apart per leg.
