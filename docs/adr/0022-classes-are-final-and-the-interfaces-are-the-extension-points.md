# 0022. Classes are final, and the interfaces are the extension points

Status: accepted

## Context

Every way the library documents for an application to change what it does
is an interface: a constraint (`Constraints\ConstraintInterface`), a leg
strategy, a role assignment, a ranking strategy, a tiebreaker, a stage
engine, a quality metric, a timeline rule, a progression selector. The
concrete classes were open all the same, and an open class had to answer
for subclasses it never asked for:

- An immutable with-er returns a new object of the class it is written in,
  so on a subclass it returned an object of the parent: the subclass was
  sliced off.
- A subclass could skip the parent's constructor. `StandingsCalculator` and
  the bracket engines read its properties through reflection first, so that
  such a calculator was not an `Error`.
- The scheduler, the role search and the constraint purity rules compared
  class names (`$x::class === ConstraintSet::class`), because a subclass of
  a built-in constraint could override `isSatisfied()` and keep state.
- The Swiss engine and the standings rules wrote a subclass into the engine
  fingerprint as `subclass=yes` or `standings=custom`, and could not tell two
  subclasses apart.
- `Schedule` was its own iterator with one cursor, so a loop over a schedule
  inside a loop over the same schedule ended the outer one early (issue
  #54).

## Decision

**Every concrete class in `src/` is `final`.** The extension points are the
interfaces, and an application that wants a class to behave otherwise
implements the interface, or wraps the class in one of its own.

The one class left open is one the library extends itself:
`Exceptions\InvalidConfigurationException`, which is thrown as it is and is
the parent of `PinConflictException`, `UnresolvedRoundException` and
`UndecidedSelectionException`. An abstract class (`SchedulingException`)
stays abstract. `tests/Feature/FinalClassesTest.php` holds `src/` to this.

`Schedule` becomes `final readonly` and an `IteratorAggregate`: every
`foreach` gets an iterator of its own, so loops over one schedule nest.

The code that existed only for a subclass is removed: each piece gave the
same answer for the final class, so no output and no fingerprint changes.

## Consequences

- Making a class final is a breaking change. 0.3.0 makes it, with a
  migration note in the changelog; a class added later is final from the
  start, and a class is opened only where the library itself extends it.
- A with-er returns exactly its class, and a class name check is a type
  check: `instanceof` and `::class ===` agree on every object of the
  library.
- The engine fingerprint no longer has a `subclass` option for the Swiss
  engine or a `standings=custom` value for the calculator. No configuration
  of the library wrote either, so no stamp it wrote changes.
- `Event` cannot be subclassed, so no event of the library's API lists a
  participant twice. The plans, the event index and `NoRepeatPairings`
  keep their guards against such an event; their tests build one past the
  constructor (`tests/Support/UncheckedEvent.php`).
