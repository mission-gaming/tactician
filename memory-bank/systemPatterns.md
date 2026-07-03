# System Patterns: Tactician

*The authoritative component inventory and diagrams live in
docs/ARCHITECTURE.md; this file records the patterns and the reasoning behind
them.*

## System Architecture

Layered, with clear separation of concerns:

```
src/DTO/           # Immutable value objects (Participant, Event, Round, Schedule, Result)
src/Scheduling/    # Whole-schedule generators + results-driven engines + SchedulingContext
src/LegStrategies/ # Multi-leg generation strategies (mirrored, repeated, shuffled)
src/Constraints/   # ConstraintSet builder + constraint implementations
src/Standings/     # StandingsCalculator, PointsSystem, tiebreakers
src/Validation/    # Completeness validation, expected-event calculators, violation tracking
src/Diagnostics/   # Failure analysis and reporting
src/Exceptions/    # Domain exception hierarchy with diagnostic reports
```

## Key Technical Decisions

### Two Generation Models
- **Whole-schedule generators** (`SchedulerInterface`): everything is known up front (round robin). Multi-leg is the default assumption; single leg is `legs=1`.
- **Results-driven engines** (`pairNextRound(participants, results)`): later rounds depend on outcomes (Swiss, brackets, group qualification). Engines are stateless — they re-resolve tournament state from the results on every call, which makes them trivially resumable and serialization-friendly.
- Unifying these behind one abstraction is deliberately deferred to Roadmap Phase 3.

### All-or-Nothing Generation
- Complete schedule or a diagnostic exception — never silently incomplete
- Constraints are hard filters evaluated during generation with full tournament context
- Post-generation integrity validation (pairing multiplicity, participant membership) backs up the count checks

### Immutability First
- All DTOs are `readonly`; methods return new instances (`Schedule::addEvent()`, `Participant::withSeed()`)
- `withSeed()` preserves identity (same ID) so reseeded qualifiers still match their earlier results

### Fairness Mechanics Are Structural
- Round-parity home/away alternation in the circle method bounds running role imbalance at a constant (3 even / 4 odd fields) — fairness is generated, not just constrained
- Swiss byes are credited as wins for pairing order; bye rotation favours the lowest-placed participant with the fewest byes
- Elimination fold seeding keeps top seeds apart until the latest possible round

## Design Patterns in Use
- **Strategy**: leg strategies (`LegStrategyInterface`); pluggable tiebreakers (`TiebreakerInterface`)
- **Builder**: `ConstraintSet::create()->...->build()`
- **Iterator**: `Schedule` implements `Iterator`/`Countable`; `Standings` is `IteratorAggregate`
- **Factory methods**: `ConsecutiveRoleConstraint::homeAway()`, `MetadataConstraint::requireSameValue()`, `PointsSystem::football()`, `ConstraintSatisfiabilityReport::success()/failure()`
- **Traits for shared mechanics**: `ValidatesScheduleCompleteness` (schedulers), `EliminationBracketSupport` (bracket engines)

## Critical Implementation Paths
- **Round-robin generation**: `RoundRobinScheduler::schedule()` → satisfiability preflight → `generateScheduleWithRetries()` (bounded rotated orderings) → per-leg generation → completeness + integrity validation
- **Swiss pairing**: standings (with bye credit and withdrawal inclusion) → Monrad adjacent pairing with backtracking → bye selection → home/away assignment
- **Bracket resolution**: fold-seeded slots → resolve each stage from indexed results (rejecting duplicates/draws/round-less results) → next unresolved stage or champion
- **Performance trap to remember**: context updates inside generation loops must be batched per round; per-event immutable copying is O(events²)

## Status
- **Last Updated**: 2026-07-03
