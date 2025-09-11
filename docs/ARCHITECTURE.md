# Architecture

## Core Components

- **DTOs**: Immutable value objects (`Participant`, `Event`, `Round`, `Schedule`)
- **Schedulers**: Algorithm implementations (`RoundRobinScheduler`)
- **Constraints**: Comprehensive constraint system (`ConstraintSet`, `NoRepeatPairings`, `MinimumRestPeriodsConstraint`, `SeedProtectionConstraint`, `ConsecutiveRoleConstraint`, `MetadataConstraint`)
- **Context**: Historical state management (`SchedulingContext`)
- **Leg Strategies**: Multi-leg tournament behavior (`LegStrategyInterface`, `MirroredLegStrategy`, `RepeatedLegStrategy`, `ShuffledLegStrategy`)
- **Validation System**: Schedule completeness validation (`ScheduleValidator`, `IncompleteScheduleException`, `ImpossibleConstraintsException`)
- **Exception Hierarchy**: Comprehensive error handling with diagnostic capabilities

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

## Schedule Validation System

Tactician includes a comprehensive validation system to ensure tournament completeness and prevent silent failures:

### Validation Components

- **ScheduleValidator**: Core validation logic with mathematical verification
- **ExpectedEventCalculator**: Calculates theoretical event counts for completeness checking
- **ConstraintViolationCollector**: Tracks and reports constraint violations
- **Exception Hierarchy**: Structured error handling with diagnostic information

### Validation Process

1. **Mathematical Validation**: Verifies expected vs actual event counts using round-robin mathematics
2. **Constraint Violation Tracking**: Monitors constraint failures during schedule generation
3. **Completeness Verification**: Ensures all required pairings are present
4. **Exception Generation**: Throws structured exceptions with diagnostic information

### Exception Types

- **IncompleteScheduleException**: Thrown when constraints prevent complete schedule generation
  - Includes constraint violation details for debugging
  - Provides actionable information for resolving issues
- **ImpossibleConstraintsException**: Thrown when constraints are mathematically impossible
  - Includes mathematical analysis of why constraints cannot be satisfied
- **InvalidConfigurationException**: Thrown for invalid scheduler configuration

### Integration

The validation system is automatically integrated into all schedulers:
- Runs after schedule generation to verify completeness
- No performance impact during generation - validation occurs at the end
- Provides immediate feedback on constraint conflicts
- Enables fail-fast behavior instead of silent incomplete schedules

## Performance Considerations

Tactician is designed for tournaments up to ~50 participants with efficient memory usage through:
- Generator-based iteration
- Lazy evaluation of constraints
- Immutable data structures preventing memory leaks
- Efficient pairing extraction and transformation for multi-leg tournaments
- Incremental constraint validation with historical context tracking
- Post-generation validation with minimal performance overhead
