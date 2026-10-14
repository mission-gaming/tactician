# Design note: Backtracking Generation

**Status: IMPLEMENTED** in 0.1.0 for round robin, the first leg only; since
0.3.0 the search crosses the boundary of the first leg and searches roles
over every leg ([Roles across legs](#roles-across-legs)). It is
Phase 5's answer to the repository's longest-recorded known limitation: greedy
circle-method generation retries bounded rotated orderings when
constraints reject a schedule, and throws `IncompleteScheduleException`
even when a valid schedule exists in principle. The circle method fixes
which pairings share a round purely by list order, so only n of the many
possible round decompositions are ever tried.

## Position

Backtracking search treats round-robin construction as what it is — a
constraint-satisfaction problem over perfect matchings:

- A leg is a decomposition of all pairings into rounds; each round is a
  perfect matching of the field (odd fields include a bye seat).
- The search builds rounds in order. Within a round it picks the lowest
  unmatched seat and tries each unused opponent, and for each pairing
  tries both orientations (role-parity-preferred first, so unconstrained
  searches reproduce the greedy generator's balanced roles); constraints
  are checked against the full context exactly as during greedy
  generation. Dead ends backtrack — first within the round, then into
  earlier rounds.

## Settled decisions

- **Opt-in, not a silent fallback.** `RoundRobinOptions(backtracking:
  true)` (config key `'backtracking'`). Greedy stays the default: it is
  fast, it fails after at most min(participants, 25) orderings and one
  failure analysis, and most configurations never need more. Enabling backtracking changes one thing — configurations the
  rotations cannot satisfy get an exhaustive (budgeted) search before the
  loud failure.
- **Greedy first, always.** With backtracking enabled the scheduler still
  runs the rotation retries first; the search only runs when they fail.
  Satisfiable-by-rotation configurations pay nothing.
- **A fixed step budget** (200,000 pairing attempts) bounds the
  exponential worst case. Exhausting it fails loudly with a
  budget-exhausted diagnostic, distinct from the search space being
  exhausted (genuinely unsatisfiable). The budget is deliberately not
  configurable in this cut.
- **The budget counts attempts, not time.** An attempt is one candidate
  event and one evaluation of the constraints, so what the budget costs
  depends on the constraints. Measured (PHP 8.4, no OPcache, one core):
  spending all of it takes 0.5 to 0.8 seconds with a constraint that
  answers in constant time, at 8 participants and at 24, and 1.8 seconds
  with `RoleBalanceConstraint::homeAway(2)` at 20 participants. The search
  keeps one path's state and changes it in place: it does not copy the
  placed events for every attempt, and a constraint that reads the history
  reads it through the context's index (see
  [ARCHITECTURE.md](../ARCHITECTURE.md#performance-considerations)).
  Before that the same role balance search took 18 seconds. The order of
  attempts and the count of steps are pinned against the search as first
  written (`tests/Unit/Scheduling/BacktrackingSearchOrderTest.php`).
- **Deterministic.** Seat order, opponent order, and orientation order
  are fixed (the optional `Randomizer` shuffles the initial field order,
  matching greedy). The same inputs, and for a scheduler with a
  `Randomizer` the same state of it, always produce the same schedule or
  the same failure.
- **Multi-leg scope**: the round decompositions of leg 1 are searched;
  later legs derive from leg 1's *actual* rounds through the leg strategy
  (the greedy path re-derives from circle order, which a backtracked leg 1
  no longer has). A first leg whose later legs the constraints reject is a
  dead end like any other, and the search goes on to the next first leg:
  see [Roles across legs](#roles-across-legs). Until 0.3.0 it failed
  there, at the first leg it found.
- **Round robin only.** Swiss pairing already backtracks per round; the
  elimination presets have no search space. Extending the searched
  generation to other whole-schedule formats is future work if any grow
  one.

## Roles across legs

A constraint on roles (a limit on same-role events in a row, a limit on how
far the two role counts drift apart, a rule that pins the role of one
pairing) reads every leg. A leg that keeps it by itself can break it where
two legs meet, and whether it does depends on what the leg strategy does to
the roles. Until 0.3.0 the scheduler never reconsidered a role: two shuffled
or two repeated legs of eight participants failed a limit of two in a row
although schedules exist, with and without backtracking.

### What each contract leaves free

A role may be changed only where the contract of the piece that decided it
says so. Anything else would return a schedule that is not what was asked
for.

| Roles of | Decided by | Free? |
|----------|-----------|-------|
| A later leg, mirrored or repeated | The leg strategy, as a **rule**: the first-leg event, reversed in the even-numbered legs of a mirrored schedule and kept everywhere else | No. Another role would not be a mirrored or a repeated leg |
| A later leg, shuffled | The leg strategy, as a **preference**: a draw | Yes: the reverse is a role it could have drawn |
| The first leg, under a role assignment that states a rule (`BalancedRoleAssignment`, and the default, `BalancedWherePossibleRoleAssignment`) | The role assignment, which says with its rule which legs it keeps | Yes, within the rule: any leg it keeps is a leg that was asked for ([role-assignment.md](role-assignment.md#the-default)). The default also accepts the leg the generator proposes where no leg within the rule is found, and marks the schedule |
| The first leg, under a role assignment that states none (`RoundParityRoleAssignment`), without backtracking | The role assignment | No. Constraints are hard filters on the roles that were asked for |
| The first leg, under a role assignment that states none, with backtracking | The search, which proposes; the role assignment, which is asked about the leg | Yes, as far as the role assignment keeps the result |

A strategy states which of the two it is with
`LegPlanContribution::$rolesArePreferences`. The default is a rule, so a
strategy written before the flag existed is never reversed. A role
assignment states its rule with `RoleAssignmentInterface::rule()`, and one
that returns null is never searched around without backtracking.

### Three searches

- **Without backtracking: the roles of the first leg, within the rule of
  the role assignment.** When every rotated order has failed with the roles
  as given, the same layouts are gone through again. In each, a seating
  whose event a constraint of unknown reach rejects is reversed and fixed,
  and the role assignment is asked for roles around the fixed seatings;
  where that does not end with a schedule, the roles of the first leg are
  searched with every event free, each choice held to the rule, the later
  legs following by the leg strategy. The rotations come first, so a
  schedule a rotated order completes is the schedule it was. A layout in
  which a constraint that reads no position rejects an event
  (`NoRepeatPairings`, `MinimumRestPeriodsConstraint`,
  `SeedProtectionConstraint`: `ConstraintPurity::constraintIgnoresRoles()`)
  is not searched, because a layout fixes who meets whom and when, and no
  roles change such a verdict; a schedule that fails for that reason costs
  what it cost before there was a search. Added in 0.3.0
  with the change of default ([role-assignment.md](role-assignment.md#the-default)).

- **Without backtracking: the roles of the later legs, for a strategy of
  preferences.** The layout stays what the circle method gives. The events
  of legs 2 and up are built in order; the role the strategy returns is
  tried first and the reverse second, and when both are rejected the search
  goes back to an earlier event of the later legs. It does not reach into
  the first leg. This is in the default path because it costs nothing until
  a role is rejected, which without it was a failure, and because a
  schedule that every constraint accepts as drawn is the schedule it always
  was. It runs once for every rotated order tried.
- **With backtracking: the roles of every leg, the first included.** For
  each first leg the generator finds, the pairings and rounds are held and
  the roles of all legs are searched together: every event of the first leg
  may be reversed, a later leg under a rule follows the first-leg event it
  derives from, and a later leg under a preference is free as above. This
  is what repeated and mirrored legs need, because the only thing that can
  repair them is the first leg. The generator then goes on to the next
  round decomposition, and never searches the roles of a decomposition
  twice, although it reaches each one once for every choice of roles the
  constraints allow the first leg.

  One layout is searched out of turn: the circle layout of the field, once,
  after the first leg the generator finds has failed (or when the generator
  spends its budget without finding one). A constraint on roles seldom needs
  other pairings in a round, and the generator, which changes the last
  round first, comes to the roles of the early rounds of a large field
  last: for two repeated legs of ten, thirteen, twenty and twenty-four
  participants under a limit of two in a row it finds nothing within its
  budget, and the roles of the circle layout are found at once. The first
  leg the generator finds is still tried first, so a schedule that a
  backtracking search returned before 0.3.0 is the one it returns now,
  under a role assignment that states no rule. Under one that does, the
  roles of each first leg are searched within the rule, as in the first
  search above, and the circle layout has been searched before the
  generator starts.

All three are `LegRoleSearch`, and all are deterministic: the order of the
events, of the two roles of an event and of the events gone back to is
fixed.

### Going back

Going back one event at a time is hopeless in a large field. The event
before the rejected one is in the same round, shares no participant with
it, and cannot change the verdict; a round of twelve events is 4,096
combinations to get through before the search reaches an event that
matters.

For a constraint set whose verdict on an event can depend only on the
events of that event's own participants, the search goes straight back to
the latest event that shares a participant with the rejected one, and
passes the remaining candidates on to it (graph-based backjumping). That is
complete: nothing between the two events can change the verdict. The sets
are those of `NoRepeatPairings`, `MinimumRestPeriodsConstraint`,
`RoleBalanceConstraint`, `SeedProtectionConstraint` and
`ConsecutiveRoleConstraint` objects (`ConstraintPurity::readsOwnEventsOnly()`).
A set that holds anything else (a closure, a metadata rule, a class of the
caller's) is searched one event at a time, which finds the same roles or
none and takes longer.

In a search of first-leg roles within a rule, the unit is the rejection and
not the set. The rule of the balanced role assignment reads the two
participants' own events, so a role it rejects sends the search straight
back. A set that holds both kinds of constraint is asked one constraint at
a time about an event it rejected, and where one of the constraints above
rejects the event by itself, that is the reason: the search goes straight
back for it as well. Only a rejection that no such constraint explains (a
closure's), and a first leg that the role assignment turns out not to keep,
send it back one event at a time. Two mirrored legs of fourteen with the
role counts never more than 1 apart, beside a closure that accepts
everything: the search shows that no balanced first leg has it in 3,387
rejected roles, as it does without the closure, where asking the set as a
whole stops at the bound without an answer. The searches that existed
before 0.3.0 ask a set as a whole, as they did.

### Bounds

- **Rejected roles: 20,000 for each layout** (`LegRoleSearch::REJECTION_LIMIT`).
  An event accepted at once costs nothing against it, so a schedule that
  needs no search is never stopped, whatever its size.
- **For the first-leg searches without backtracking: 20,000 rejected roles
  and 200,000 events put to the constraints, for all the layouts of a call
  together.** One allowance and not one for each rotated order, because the
  rotated orders are the same schedule under other names for a limit on
  roles: a search that spent the allowance on the first has little to find
  in the second, and a schedule that cannot be completed would otherwise
  pay it up to 25 times. Fixing the seatings a constraint rejects costs
  nothing against it.
- **The step budget**, with backtracking: every event a role search puts to
  the constraints is a step of the same 200,000. When the generator spends
  the budget before it finds one first leg, the roles of the circle layout
  are still searched, with 200,000 attempts of their own.

Measured for the later legs of a shuffled schedule, 50 seeds each, 8 to 40
participants, 2 and 4 legs (PHP 8.4, no OPcache, one core):

| Constraints | Found | Attempts beyond one for each event, at most | Time, at most |
|-------------|-------|---------------------------------------------|---------------|
| At most two in a row | Every seed, up to 40 participants | 626 at 24 participants and 4 legs; 2,215 at 40 | 0.12 s at 24 and 4 legs; 1.2 s at 40 |
| Role counts at most 2 apart | Every seed, up to 40 participants | 1,292 at 24 and 4 legs; 6,031 at 40 | 0.04 s at 24 and 4 legs; 0.22 s at 40 |
| At most two in a row, in a set that also holds a closure (one event at a time) | Every seed up to 24 participants; 49 of 50 at 30 and 2 legs, 41 of 50 at 30 and 4 legs, 4 of 50 at 40 and 2 legs, none at 40 and 4 legs | 9,737 at 24 and 4 legs | 0.8 s at 24 and 4 legs; 1.2 to 4.4 s where the bound stops it |

The time is mostly the constraint's: `ConsecutiveRoleConstraint` reads and
sorts a participant's whole history for every question. A step of a role
search with backtracking also costs more than a pairing attempt of the
generator, because its context holds every leg and not one: spending the
whole step budget in role searches took 5.5 seconds for four repeated legs
of 16 participants under `RoleBalanceConstraint::homeAway(3)`, which no
roles satisfy there.

Without backtracking a failing schedule pays the bound once for every
rotated order tried, before the failure analysis that follows a failure.
Measured for two shuffled legs under a limit of two in a row, in a set
that also holds a closure which leaves one pairing no round in the second
leg (so that no roles exist, and every rotated order is searched one event
at a time up to the bound): 14 seconds for 16 participants, where the same
failure took 1.8 seconds before the roles were searched; 49 seconds for 24
participants (17 before), and 103 seconds with four legs. With
backtracking on, the search that follows adds 3 seconds to the 49. A
closure alone, with no role constraint to read a history for every
question, fails in under a second at 24 participants. The cost is a count
of rejected roles times a number of rotated orders, so it is bounded and
does not depend on the machine's clock; it is the price of trying every
rotated order, which is where some of those schedules are found.

Where the first leg has to change (repeated legs, measured with the
round-parity roles, one run each for 4 to 24 participants): under a limit of two in a
row a backtracking search found two, three and four repeated legs for every
size. Under `RoleBalanceConstraint` it found two repeated legs up to 16
participants (limits of 2 and of 3) and stopped at its budget for 20 and
24; three repeated legs under a limit of 3 up to 10 participants and not
from 12. Those schedules exist: `BalancedRoleAssignment` gives a first leg
that satisfies them without a search. The role search starts from the roles
it is handed and changes as few as it must, from the last event back, which
is a poor way to reach a leg whose roles differ in most places.

**Memory.** A role search keeps the context of every event it has placed,
to go back to: one for each round without backtracking, and one for each
event with it, each holding the events before it. Memory therefore grows
with the square of the number of events, where generation without a role
search keeps one context. Measured as PHP's peak allocation for a schedule
that was found: four shuffled legs under a limit of two in a row take 16 MB
for 24 participants, 52 MB for 40 and 120 MB for 56 (6 MB for 40 with
no constraints); four repeated legs under the same limit with
backtracking take 32 MB for 24 participants and 84 MB for 32. Nothing
bounds this but the size of the schedule, and PHP's memory limit ends a
process that exceeds it with an error no caller can catch: a very large
field with several legs and a role constraint needs a limit that allows
for it.

A search that a bound stopped says so in the exception message, and does
not say that no roles exist. A backtracking search that spent its budget
says of how many first legs it went through every choice of roles and of
how many a bound stopped it: three repeated legs of twelve under role
counts at most 3 apart stop at a bound in both first legs tried, and one
of the two, the circle layout, has roles that satisfy the limit (the
balanced ones). "Unsatisfiable" is said only by a
backtracking search that went through every round decomposition and every
role without a bound stopping any part of it.

### The role assignment

No search overrules a role assignment. A first leg is returned only when
the role assignment keeps it as it is.

- Under a role assignment that states a rule (`BalancedRoleAssignment`
  keeps a leg that is balanced, and says so), the roles of the first leg
  are searched within the rule, without backtracking in the circle layouts
  and with it in every layout the generator finds. The leg a search returns
  is put to the role assignment once more, so a rule that says more than
  the role assignment keeps cannot let a leg through.
- Under one that states none, the first leg has the roles the role
  assignment decided unless backtracking is on. With backtracking, the role
  assignment is asked about every first leg the generator finds, as it
  always was, and a first leg whose roles the role search changed is put to
  it again: the schedule is returned only when the role assignment keeps
  that leg, and the failure says so when roles exist that it would change.
  `RoundParityRoleAssignment` keeps every leg.
- The default, `BalancedWherePossibleRoleAssignment`, is searched as the
  balanced role assignment is, and a balanced leg that a search returns is
  the leg `BalancedRoleAssignment` gets. It is the one role assignment
  under which a first leg outside its rule is returned: where no balanced
  leg is found, the scheduler starts the generation again with the roles
  the generator proposes, exactly as under `RoundParityRoleAssignment`
  (with backtracking, the leg the search chooses), and writes the reason
  into the metadata of the schedule. That is not a search overruling a role
  assignment: it is what this one asks for. Under constraints the role
  search cannot reason about exactly, the roles the generator proposes are
  tried before the searches of this section, and with their schedule in
  hand those searches are held, together, to 4,000 events put to the
  constraints.

[role-assignment.md](role-assignment.md#the-default) has the decision and
what was measured.

### What is not searched

- The roles of the first leg without backtracking, under a role assignment
  that states no rule.
- The pairings and rounds of the first leg without backtracking: they are
  the circle method's, in each rotated order.
- The pairings and rounds of a later leg: it has those of the first.
- More than one first leg for a strategy that returns no event for a
  pairing: nothing says another first leg would get one.
- Other draws. A shuffled leg is drawn once; the search changes roles, it
  does not draw again.
