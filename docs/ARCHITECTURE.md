# Architecture

## Core Components

- **DTOs**: Immutable value objects (`Participant`, `Event`, `Schedule`)
- **Schedulers**: Algorithm implementations (`RoundRobinScheduler`)
- **Constraints**: Flexible predicate system (`ConstraintSet`, `NoRepeatPairings`)
- **Context**: Historical state management (`SchedulingContext`)

## Design Principles

- **Immutability**: All DTOs are readonly and return new instances
- **Composability**: Constraints and schedulers combine flexibly
- **Performance**: Iterator pattern enables efficient memory usage
- **Determinism**: Seeded randomization produces reproducible results
- **Extensibility**: Clean interfaces for adding new algorithms

## Performance Considerations

Tactician is designed for tournaments up to ~50 participants with efficient memory usage through:
- Generator-based iteration
- Lazy evaluation of constraints
- Immutable data structures preventing memory leaks
