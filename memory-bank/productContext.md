# Product Context: Tactician

## Why This Project Exists

Tactician addresses the complexity of tournament scheduling in competitive gaming and sports. Tournament organizers need reliable, deterministic algorithms to create fair, balanced schedules that follow sport-specific rules and constraints. Existing PHP solutions are either too simple (basic round-robin without constraints) or too complex (enterprise tournament management systems with unnecessary overhead).

The library provides the algorithmic scheduling layer — pairing, progression, and standings — so developers can build tournament applications on solid mathematical foundations. It was created by Mission Gaming for the Metronome tournament platform (see docs/BACKGROUND.md).

## Problems It Solves

1. **Deterministic scheduling**: reproducible results with seeded randomization; round robin guarantees every pairing exactly once per leg; byes handled and recorded for odd fields
2. **Results-driven formats**: Swiss pairing, elimination brackets, and group qualification cannot be precomputed — the engines resolve each round from recorded results
3. **Fair play mechanics**: balanced home/away roles, seed protection, rest periods, bye rotation, cross-group knockout seeding, and standings with recognised tiebreakers
4. **Reliability**: constraints fail loudly with diagnostics instead of producing silently incomplete schedules; results are validated (no duplicates, no draws in brackets, no qualification from partial group play)

## How It Works

Two complementary models:

```php
// Whole-schedule generation (round robin): everything known up front
$schedule = (new RoundRobinScheduler($constraints))->schedule($participants, 2, $legs);

// Results-driven engines (Swiss, brackets, groups): one round at a time
$pairing = $engine->pairNextRound($participants, $results);
// ...play, record Results, repeat...
```

Standings sit between the two: `StandingsCalculator` turns `Result` objects
into ordered tables that drive Swiss pairing and group qualification.
docs/USAGE.md has executable examples for every format.

## User Experience Goals
- Fluent, discoverable APIs (`ConstraintSet::create()->...->build()`)
- Executable documentation: every example and docs snippet runs and is validated by tests
- Failures are actionable: exceptions carry diagnostic reports naming the constraint, participants, and rounds involved

## Status
- **Last Updated**: 2026-07-03
