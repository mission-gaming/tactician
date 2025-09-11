# Architecture

## Core Components

- **DTOs**: Immutable value objects (`Participant`, `Event`, `Round`, `Schedule`)
- **Schedulers**: Algorithm implementations (`RoundRobinScheduler`)
- **Constraints**: Comprehensive constraint system (`ConstraintSet`, `NoRepeatPairings`, `MinimumRestPeriodsConstraint`, `SeedProtectionConstraint`, `ConsecutiveRoleConstraint`, `MetadataConstraint`)
- **Context**: Historical state management (`SchedulingContext`)
- **Leg Strategies**: Multi-leg tournament behavior (`LegStrategyInterface`, `MirroredLegStrategy`, `RepeatedLegStrategy`, `ShuffledLegStrategy`)

## Multi-Leg Tournament Architecture

The library supports multi-leg tournaments through a strategy pattern:

### LegStrategyInterface
Defines how participant pairings are transformed for subsequent legs:
```php
interface LegStrategyInterface
{
    public function generateLegPairings(
        array $basePairings,
        int $legNumber,
        int $totalLegs,
        ?Randomizer $randomizer = null
    ): array;
}
```

### Available Strategies
- **MirroredLegStrategy**: Reverses participant order (home/away tournaments)
- **RepeatedLegStrategy**: Maintains identical pairings across legs
- **ShuffledLegStrategy**: Randomizes participant order in each pairing

### SupportsMultipleLegs Trait
Provides common multi-leg functionality:
- Expands single-leg schedules using leg strategies
- Maintains continuous round numbering across legs
- Extracts and transforms participant pairings
- Handles proper event creation with round metadata

## Design Principles

- **Immutability**: All DTOs are readonly and return new instances
- **Composability**: Constraints and schedulers combine flexibly
- **Strategy Pattern**: Pluggable leg strategies for different tournament types
- **Performance**: Iterator pattern enables efficient memory usage
- **Determinism**: Seeded randomization produces reproducible results
- **Extensibility**: Clean interfaces for adding new algorithms

## Advanced Constraint System

The library includes several sophisticated constraint types for complex tournament requirements:

### Time-Based Constraints
- **MinimumRestPeriodsConstraint**: Enforces minimum rounds between participant encounters
- **SeedProtectionConstraint**: Prevents high-seeded participants from meeting early

### Positional Constraints  
- **ConsecutiveRoleConstraint**: Limits consecutive home/away or positional assignments
- Factory methods for common patterns (home/away, position-based)

### Metadata-Based Constraints
- **MetadataConstraint**: Flexible constraint system using participant metadata
- Built-in factory methods for common patterns:
  - Same value requirements (division, skill level)
  - Different value requirements (regions, equipment)
  - Adjacent value constraints (skill ratings)
  - Maximum unique value limits

### Multi-Leg Constraint Application

Constraints work seamlessly across multiple legs through incremental context building:
- Each leg's events are added to the scheduling context
- Constraints evaluate against the complete tournament history
- Continuous round numbering maintains proper constraint evaluation
- Complex scenarios (rest periods, seed protection) work across leg boundaries

## Performance Considerations

Tactician is designed for tournaments up to ~50 participants with efficient memory usage through:
- Generator-based iteration
- Lazy evaluation of constraints
- Immutable data structures preventing memory leaks
- Efficient pairing extraction and transformation for multi-leg tournaments
- Incremental constraint validation with historical context tracking
