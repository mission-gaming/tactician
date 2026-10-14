# Design note: Role Assignment

**Status: IMPLEMENTED.** `BalancedRoleAssignment` shipped as an opt-in in
0.2.2 (`RoundRobinOptions(roleAssignment: new BalancedRoleAssignment())`).
Since 0.3.0 the default is balanced where possible
(`BalancedWherePossibleRoleAssignment`): the balanced roles wherever the
scheduler finds a schedule with them, and otherwise the round-parity roles,
with a mark. [The default](#the-default) records that decision, the bar it
had to meet, what was built to meet it, and why the default is not
`BalancedRoleAssignment` itself.

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
  (The default of 0.3.0 asks for balanced roles where they can be had, and
  its other roles are not silent: the schedule is marked.)
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
  still what happens under a role assignment that states no rule.) Under
  the default a balanced leg is looked for in the same way, and where none
  is found the roles of the search are returned after all, by a second
  generation under round parity and with a mark: the caller who named
  nothing is told, and the caller who named `BalancedRoleAssignment` is
  refused.

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

**Decision (0.3.0, issue #51): the default is balanced where possible.**
`RoundRobinOptions` that name no role assignment get
`BalancedWherePossibleRoleAssignment`: the balanced roles where the
scheduler finds a schedule with them, and otherwise the roles the generator
proposes, with the reason in the schedule's metadata. `BalancedRoleAssignment`
stays, and naming it is the strict choice: balanced or refused.
`RoundParityRoleAssignment` stays, and naming it gives the roles of 0.2.x.

The first form of the decision was that `BalancedRoleAssignment` itself
became the default. [Why not balanced or refused](#why-not-balanced-or-refused)
records what a review of that found and why it was withdrawn.

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

`BalancedRoleAssignment` is held to that bar, and what follows under "What
was built" is how. The default was then held to a second one: no
configuration that completed with the default of 0.2.x may fail under the
new one, and none may take much longer to come back.

### What was built

- **The interface says more, in both directions.** `assignRoles()` takes a
  `RoleAssignmentContext`: the leg, the number of legs, and the seatings
  the constraints have fixed. An object, so that more can be told later
  without breaking an implementer again. And the role assignment states,
  with `rule()`, what it holds a first leg to, as a constraint on one event
  (`LegBalanceRule` for the two balanced ones: no participant has a role
  more often in the leg than half its events rounded up), or null.
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

### Why not balanced or refused

With `BalancedRoleAssignment` as the default, a review compared the branch
with 0.2.x under default options and found two classes of configuration
that completed before and failed after. Nothing was wrong with either
failure as a failure of `BalancedRoleAssignment`; both were wrong as the
behaviour of options that name nothing.

- **A limit on roles that the caller writes itself.** The search of roles
  knows what the library's two role limits read and goes straight back to
  the event that matters. A closure, a class of the caller's, a subclass of
  the library's constraint, or a subclass of `ConstraintSet` does not say,
  so the search goes back one event at a time. Two mirrored legs under a
  closure that does what `ConsecutiveRoleConstraint::homeAway(2)` does:
  0.2.x completed every field of even size from 6 to 40 in milliseconds,
  because round parity keeps that limit where the legs meet; the strict
  default completed 6, 8 and 10 participants and failed for 12, 16, 20, 24,
  32 and 40 at a bound, after 1 to 13 seconds (over four legs, 20 seconds
  with 16 participants and minutes from 24). Most of that time was not the
  search but the failure analysis of every rotated order, which a
  constraint set the library does not know has always had, and which the
  rejected default roles now reached.
- **A rule that leaves no balanced leg at all.** 3,662 small
  configurations in which a caller pins roles so that some participant is
  out of balance whatever else is chosen completed with the round-parity
  roles, where those happened to agree with the rule, and failed.

With the library's own role limits nothing regressed (1,120 configurations:
477 complete in both, 438 failed before and complete now, 205 fail in
both), and a brute-force oracle found no case in which a balanced schedule
exists within what the scheduler searches and it misses it.

Three ways out were weighed.

- *Keep the strict default and document the two classes.* A caller who set
  no option would get a failure, seconds late, for a league that scheduled
  before, and the remedy (name round parity) is one it has no reason to
  know. Balance is something the default prefers; nobody who left the
  option out asked for it at the price of the schedule.
- *Search as if every verdict were local* (go straight back for a closure
  as for the library's limits, and put what is found to the constraints
  event by event, so that it is valid). A schedule found that way is right,
  but a search that fails that way has shown nothing, the second class is
  not helped by it at all, and the cost of a failed search stays.
- *Balanced where possible.* The default keeps its preference and gives it
  up, visibly, where it cannot have it. This was chosen. It makes the two
  classes complete again, costs nothing where balanced roles are found, and
  leaves the strict behaviour to the caller who names it.

### Balanced where possible

**What it is.** `BalancedWherePossibleRoleAssignment` is a role assignment
like the other two: it answers `assignRoles()` and `rule()` exactly as
`BalancedRoleAssignment` does. The fall-back is not something a role
assignment can do in `assignRoles()`, which decides one leg and sees no
constraint; it is a second generation of the whole schedule. So the
scheduler does it, and it knows the class: `RoundRobinScheduler` runs its
generation under the balanced roles and, where that finds nothing, under
`RoundParityRoleAssignment`. No further method was added to
`RoleAssignmentInterface` for this, which had already been changed in
the release: a caller's own role assignment cannot ask for the fall-back,
and one that wraps the default is held to its rule. The class has an
identifier in plain data, `balanced_where_possible`, so that options can
name the default and stay on it when a later release changes the default;
options that name nothing are written without the key, as before
([ADR 0005](../adr/0005-serialized-forms-carry-a-format-version.md)).

**What a caller is told.** A schedule that fell back says so in its
metadata, under `role_fallback` (`RoleFallback::METADATA_KEY`), with one of
three reasons as a string; `RoleFallback::of()` reads it as an enum case.
The metadata is where the scheduler already records what it knows of a
generated schedule (its byes, its shape), it is part of the schedule's
plain data, and a key that is absent from every balanced schedule leaves
those byte for byte what they were. The reasons are chosen to answer the
question a caller has next, which is whether naming
`BalancedRoleAssignment` would help:

| Reason | Means | `BalancedRoleAssignment`, named |
|--------|-------|---------------------------------|
| `no_balanced_roles` | Every choice of balanced roles that is searched was tried | Fails |
| `search_stopped_at_bound` | A bound stopped the search | Stops at the same bound and fails |
| `balanced_roles_rejected` | The constraints rejected the balanced roles put to them, and with a schedule in hand the search for others stopped at its 4,000 events, or no backtracking search was made for balance | Searches to its bounds; may find a schedule, may take seconds |

**The order of the attempts.** What decides it is whether the search of
roles can reason about the constraints exactly
(`ConstraintPurity::readsOwnEventsOnly()`: a `ConstraintSet` of the
library's own classes, or no constraints).

Where it can:

1. Everything `BalancedRoleAssignment` does: the balanced roles as given in
   every rotated order, the search within the balance in those layouts, the
   backtracking search where it is asked for.
2. Everything `RoundParityRoleAssignment` does. Its schedule is returned
   with the reason step 1 ended on; its failure is the failure of the call.

The search of step 1 is bounded and goes straight back to the events that
matter, and it is what finds the 438 schedules that 0.2.x refused. A
fall-back after it is rare: with the library's built-in role
limits, alone or beside its rest, repeat and seed-protection rules, none was
seen in 8,464 configurations of 2 to 20 participants, which ended balanced or
failed and were never marked. One can be built from the library's classes (a
`ConsecutiveRoleConstraint` whose extractor fixes the roles of one
participant, `tests/Feature/RoleConstraintsAcrossLegsTest.php`): with four
participants the search shows that no balanced leg exists, and with six it
runs 0.8 seconds to its bound before the fall-back, with eight 1.9.

Where it cannot:

1. The balanced roles as given, in the first layout only.
2. Everything `RoundParityRoleAssignment` does. The schedule, or the
   failure, is kept.
3. Everything `BalancedRoleAssignment` does, from the first layout again.
   With nothing in hand it has its usual bounds. With a schedule in hand:
   - it is held to 4,000 events put to the constraints, the rotated
     orders, the passes that fix seatings and the searches that go back
     one event at a time together;
   - a backtracking search is made only where the schedule in hand came
     from one, with what is left of the 4,000 as its step budget.

   Nothing else differs, so a schedule found within the 4,000 is the one
   the strict role assignment finds.
4. A balanced schedule from step 3, or the schedule of step 2 with the
   reason, or the failure of step 2.

Why this order. The roles as given come first in both because they cost one
pass and are the answer for every schedule without constraints and for most
with them. Round parity comes before the search wherever the search can
spend its whole allowance without a result, which is wherever it goes back
one event at a time; it does not come first of all, because under a
constraint set the library does not know a failure of round parity is
analysed once for every rotated order, and a schedule the balanced roles
complete at once should not wait for that. With a schedule in hand, a valid
answer exists and balance is a preference, so what is still spent on it is
bounded by a count that is small beside the bounds of the strict role
assignment: 4,000 events, where that one has 200,000.

**Why a count and nothing more.** As first merged, the search with a
schedule in hand was cut short in two more ways: the two searches that go
back one event at a time were left out unless one of the library's role
limits had rejected last, and everything ended at the first event that a
constraint of unknown reach rejected in its context and accepted with no
event before it (a guess that the rejection was a limit on a streak or a
running total, which the scheduler cannot tell which earlier event to
change for). That made a fall-back cost about what round parity costs, and
it gave up schedules the count alone allows: in the second sweep below, 349
configurations that the strict role assignment balances fell back, and
265 of them are balanced inside the 4,000 events. The guess also put a
question to a constraint that no generation asks (an event with an empty
context), which a constraint may be unable to answer. Both were removed.
What is left is one rule that can be stated in a sentence: with a schedule
in hand, the search of the strict role assignment, for 4,000 events.

Each run of the generation starts where a call starts. The shuffles a
`Randomizer` gives a call are drawn once and read again by every run
(`RoundRobinScheduler::shuffled()`), so that the round-parity run under the
default is the run of `RoundParityRoleAssignment` from the same seed, and
the randomizer moves on by what a call drew and no further. A leg strategy
that draws is asked for its plan again, which is where a
`ShuffledLegStrategy` built from a seed starts its draws again; one that
holds a randomizer of its own draws on, and its fall-back is a schedule of
round parity for other draws.

A failure of a balanced run never reaches the caller, so none is analysed,
and a rotated order is left at the first event the constraints reject
instead of being generated to its end.

### What was measured

- The 729 cases: under `BalancedRoleAssignment` no case fails while a
  balanced leg exists, with and without backtracking; under the default
  every case with a balanced leg gets the same schedule, and every other
  case that round parity completes gets that one, marked
  (`tests/Feature/RoleAssignmentTest.php`).
- Against an exhaustive oracle (`tests/Support/RoleScheduleOracle.php`),
  fields of three to five (and to eight for a single leg), one to four
  legs, the three leg strategies, limits on streaks and on totals, pinned
  roles and combinations. Under `BalancedRoleAssignment`: with
  backtracking, a schedule is returned exactly when some way to play the
  first leg has balanced roles that keep the rule in every leg, and every
  failure says "unsatisfiable"; without it, exactly when one of the circle
  layouts tried has such roles. The 20 cases of three participants with a
  pinned role that the search of 0.2.x-era roles missed under the balanced
  role assignment are among them. Under the default: a schedule is returned
  wherever one exists with the roles the generator proposes, it is balanced
  and unmarked wherever balanced roles exist in what is searched, and it is
  marked exactly when its first leg is not balanced
  (`tests/Feature/RoleConstraintsAcrossLegsTest.php`).
- Against 0.2.x, under default options, with the search for balanced
  roles cut short as it first was with a schedule in hand ("Why a count and
  nothing more"; the point after this one has what the count alone
  changed, which is the split between balanced and fall-back and nothing
  else here): 40,888 configurations (fields of
  2 to 20; 1,520 without constraints; the library's role limits alone and
  beside rest, repeat and seed-protection rules; limits, pinned roles and
  metadata rules written as closures, as a class and a subclass of the
  caller's, and inside a subclass of `ConstraintSet`; one to four legs, the
  three strategies, with and without a `Randomizer`, backtracking on and
  off in the small fields; 0.2.x is `origin/main` at `6156e26`).
  - Never worse: without a `Randomizer` no configuration that completed
    fails. Of the 22,106 that completed, 17,761 complete with balanced
    roles and 4,242 fall back; the 2,175 fall-backs without a `Randomizer`
    have the events 0.2.x gave them, and every fall-back is the schedule
    `RoundParityRoleAssignment` gives on the branch (3,965 compared). 103
    with a `Randomizer` fail, and each fails under
    `RoundParityRoleAssignment` too: the later legs of a seeded schedule
    derive from the first since 0.3.0, and mirrored legs that really mirror
    cannot keep a role pinned in every leg.
  - A failure is the failure of round parity: the same message in all
    5,651 failures compared with `RoundParityRoleAssignment` named, and in
    all 4,253 failures without a `Randomizer` that 0.2.x answered within
    the 20 seconds the comparison gave it.
  - Balanced as before: of 27,278 configurations the strict default
    balanced, 26,952 got the same schedule byte for byte and 326 fell back.
    Every unmarked schedule compared with `BalancedRoleAssignment` named
    was its schedule (18,213).
  - 9,034 configurations that failed in 0.2.x complete with balanced
    roles; among the library's role limits alone (2,720, with and without a
    `Randomizer` and backtracking), 1,366 complete in both, 875 failed and
    complete, 479 fail in both, and none falls back.
- The search with a schedule in hand, held to the count alone, against the
  default as first merged: 31,164 configurations under default options
  (fields of 2 to 14, 16 and 20 with one to four legs, and of 16 to 40 with
  two to four; 18 sets of the library's constraints, none included, and 31
  of a caller's, as in the sweep above; the three strategies; with and
  without a `Randomizer` up to 24 participants; backtracking on and off in
  the small fields).
  - Nothing else moved: 23,002 complete before and after, 7,812 fail
    before and after with the same message, and 350, in fields of 18 and
    more, were not answered in the time the comparison gave them before or
    after. No schedule that was balanced changed, and no configuration of
    the library's own constraints changed in any way.
  - Of 22,185 configurations the strict role assignment balances, 349
    fell back as first merged. 265 of them are balanced now, each with the
    schedule `BalancedRoleAssignment` gives byte for byte: limits on a
    streak or a running total written as a closure (145), as a class of
    the caller's (30), as a subclass of the library's limit (30) and
    inside a subclass of `ConstraintSet` (60), in fields of 5 to 9 and of
    13. 84 still fall back, because their balanced roles are further than
    4,000 events away: a limit of two in a row at 10 participants in those
    four forms (54), and 30 in fields of 20 to 40 (the library's limit
    beside a metadata rule, which was never cut short by anything but the
    count, 12; one pinned pairing under repeated and shuffled legs, 12;
    limits under shuffled legs, 6).
  - 192 fall-backs changed their reason and nothing else, from
    `balanced_roles_rejected` to `no_balanced_roles`: rules that leave no
    balanced leg, in fields of three and four, where the search now goes
    through every choice inside the 4,000 events. The strict role
    assignment fails for each of them.
  - Every unmarked schedule is the one `BalancedRoleAssignment` gives,
    named (22,101 compared), and every marked one is the one
    `RoundParityRoleAssignment` gives (901 compared: 192 marked
    `no_balanced_roles` and 709 `balanced_roles_rejected`).
  - What a fall-back costs, for the fall-backs under a constraint of the
    caller's without backtracking that are fall-backs before and after
    (fastest of three runs, in milliseconds; "before" is the default as
    first merged, which cost little more than round parity):

    | Participants | Fall-backs | Median, before | Median, now | 90th percentile, before | 90th percentile, now |
    |---|---|---|---|---|---|
    | 10 | 54 | 2.5 | 33.3 | 4.9 | 49.3 |
    | 12 | 54 | 4.3 | 40.6 | 9.0 | 57.9 |
    | 14 | 54 | 7.0 | 50.6 | 14.8 | 71.0 |
    | 16 | 54 | 10.7 | 61.9 | 22.5 | 83.1 |
    | 18 | 36 | 15.9 | 71.0 | 33.1 | 97.9 |
    | 20 | 63 | 31.1 | 82.6 | 97.1 | 118.0 |
    | 24 | 46 | 45.8 | 105.2 | 121.9 | 159.9 |
    | 30 | 14 | 122.7 | 183.7 | 208.5 | 312.2 |
    | 32 | 18 | 80.0 | 194.4 | 252.8 | 396.2 |
    | 40 | 18 | 179.6 | 313.1 | 534.6 | 673.8 |

    The search adds 32 to 84 ms at the median and at most 156 ms (four
    mirrored legs of 32 under the library's limit in a constraint set of
    the caller's, 409 ms for 253), and never more than 3,990 questions to
    a constraint beyond what it was asked before. In fields of three and four a fall-back takes 0.5 and
    1.3 ms at the median where it took 0.3 and 0.7. Seven fall-backs
    under shuffled legs of 24 to 40 participants are left out of the
    table: they take 3 to 25 seconds before and after, of which round
    parity itself takes 2 to 19, the rest being the searches of the later
    legs' roles in the balanced attempts, which the count does not hold.
- Time, for the case of the review (two and four mirrored legs under a
  limit of two in a row written as a closure), for every field of even
  size from 6 to 40. Six and eight participants are balanced, in 2.5 and
  23 ms over two legs. From ten up the schedule is the one of round parity,
  marked, and the closure is asked what round parity asks it and 3,271 to
  5,481 questions more:

  | Participants | Two legs, 0.2.x | Two legs, default | Four legs, 0.2.x | Four legs, default |
  |---|---|---|---|---|
  | 12 | 2.2 ms | 54.0 ms | 8.8 ms | 62.7 ms |
  | 16 | 5.6 ms | 72.5 ms | 22.7 ms | 92.7 ms |
  | 20 | 11.3 ms | 90.0 ms | 47.7 ms | 129.4 ms |
  | 24 | 20.7 ms | 114.6 ms | 89.6 ms | 183.5 ms |
  | 32 | 50.5 ms | 189.3 ms | 225.4 ms | 367.5 ms |
  | 40 | 104.3 ms | 304.0 ms | 459.6 ms | 676.5 ms |

  (Fastest of five runs. The strict default failed these after 1 to 13
  seconds over two legs, and after 20 seconds to minutes over four. With
  the search cut short as it first was, the default took 3.8 ms with 12
  participants over two legs and 159 with 40.)

### What it costs, and what is short

- A schedule without constraints pays for the circle rule and nothing
  else. A constrained schedule that the roles as given complete in the
  first layout pays nothing more than it did.
- **The default is not always balanced, and a caller that does not read
  the mark does not learn it.** Under the library's own constraints a
  fall-back was not seen in what was measured. Under constraints of the
  caller's it happens in two cases: a rule that leaves no balanced leg, and
  a constraint whose balanced roles the search does not reach within 4,000
  events once round parity has completed the schedule. The second is a
  schedule that `BalancedRoleAssignment` may find: of the 22,185
  configurations of the second sweep that the strict role assignment
  balances, 22,101 get the same schedule byte for byte, and the other 84
  fall back ("What was measured" says which). A limit of two in a row
  written as a closure, over two mirrored legs, is balanced at 6 and 8
  participants and falls back at 10, where the strict role assignment
  finds balanced roles in some tenths of a second, and from 12 up, where it
  finds none within its bounds.
- **A fall-back under a constraint of the caller's costs up to 4,000
  events more than round parity does**, since that is what the search is
  given before the schedule in hand is returned: 30 to 85 ms more at the
  median for 10 to 40 participants, which is 14 times what round parity
  takes at 10 participants, 3 times at 20 and 1.3 times at 40 ("What was
  measured" has the table). That is the price of the 265 schedules of the
  sweep that are balanced for it, and it is paid by every league whose
  limit is written as a closure and is out of reach: such a league gets
  the schedule 0.2.x gave it, later than 0.2.x gave it.
- Under the library's own constraints a fall-back, or a failure, comes
  after the whole search for balanced roles: one allowance of 20,000
  rejected roles and 200,000 events put to the constraints for all the
  layouts of a call, and the step budget of a backtracking search. The
  passes that fix seatings count against the events: a closure that fixes
  the role of every pairing of 40 participants, with no balanced leg, put
  1.8 million events to the constraints in those passes before they were
  counted, and took 20 seconds to fail where it now takes 3 (under
  `BalancedRoleAssignment`; the default falls back or fails as round parity
  does).
- A schedule balanced by a search costs more than the round-parity schedule
  0.2.x gave for the same input, since every rotated order is tried with the
  roles as given first: two mirrored legs under the
  library's limit of two in a row take 14 ms for 2.3 with 12 participants,
  86 for 12 with 20 and 860 for 108 with 40, six to eight times the time of
  0.2.x. (The strict default took 32, 248 and 2,900 ms: a rotated order is
  now left at the first event the constraints reject.) Under a constraint
  of the caller's with `backtracking: true`, where the schedule in hand
  came from a backtracking search, the search for a balanced first leg can
  spend what is left of the 4,000 steps before the fall-back: a median of
  60 ms more at five and six participants, where 0.2.x took 4 to 9.
- The search is bounded, and beyond its bound it does not know. Two
  mirrored legs under a limit of two in a row: found for every field of
  even size from 6 to 30 and for 3 to 11 participants of odd size; stopped
  at the bound for every field of odd size from 13 to 29, after some
  seconds. Round parity breaks the limit in those fields as well, so they
  fail under every role assignment. Four participants have no first leg
  that keeps that limit, with any roles. A limit of three in a row is found
  for every field from 3 to 20 over one to four legs.
- Without backtracking only the circle layouts are searched. A limit that
  no balanced roles keep there, and that another way to play the leg would
  keep, needs `backtracking: true`.
- A constraint of unknown reach that rejects a seating both ways round is
  not proof that no roles exist, since it may read anything. Under
  `BalancedRoleAssignment` the search that follows goes back one event at a
  time for such a rejection, and for a rule that truly leaves no schedule (a
  role pinned in every leg, over two mirrored legs) it ends at its bound,
  not with a proof. Under the default with a schedule in hand that search
  has 4,000 events. Where it comes to its end inside them, every choice of
  balanced roles in the layouts was put to the constraint, and the reason
  is the one the strict role assignment's failure gives: no balanced
  roles. Where they run out, the reason says only that the balanced roles
  were rejected.
- A constraint that keeps state is asked more questions under the default
  than under round parity, before and after, and may answer differently
  for it. The usage guide has always asked for pure predicates; the
  guarantee that the default completes wherever round parity does holds for
  those.

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
  reverses those two seatings, which is the balanced leg its search finds
  (for the library's constraint; the same limit as a closure is not
  searched for once round parity has completed the schedule, see "Balanced
  where possible").
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
