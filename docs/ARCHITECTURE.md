# Architecture

## Core Components

### Data Transfer Objects (DTOs)
- **Participant**: Immutable participant representation with ID, label, seed, and metadata
- **Event**: Immutable match/event representation with participants, round, and metadata
- **Round**: Immutable round representation with number and metadata
- **Schedule**: Iterator/Countable collection of events with metadata support

### Scheduling System
- **SchedulerInterface**: Contract for all schedulers with integrated multi-leg support
- **RoundRobinScheduler**: Circle method algorithm with integrated multi-leg generation
- **SchedulingContext**: Multi-leg aware historical state management

### Multi-Leg Architecture
- **LegStrategyInterface**: Strategy contract for integrated leg generation
- **MirroredLegStrategy**: Home/away role reversal strategy
- **RepeatedLegStrategy**: Identical leg repetition strategy  
- **ShuffledLegStrategy**: Randomized pairing order strategy
- **GenerationPlan**: Comprehensive generation planning with pairings and constraints
- **ConstraintSatisfiabilityReport**: Detailed constraint satisfaction analysis

### Constraint System
- **ConstraintInterface**: Constraint contract for validation
- **ConstraintSet**: Builder pattern container with fluent API
- **NoRepeatPairings**: Built-in constraint preventing duplicate pairings
- **MinimumRestPeriodsConstraint**: Time-based rest period enforcement
- **SeedProtectionConstraint**: Tournament seeding protection
- **ConsecutiveRoleConstraint**: Positional/role-based constraints
- **MetadataConstraint**: Flexible metadata-based rules
- **CallableConstraint**: Custom predicate constraints

### Validation & Diagnostics
- **ScheduleValidator**: Core validation logic with mathematical verification
- **ConstraintViolationCollector**: Tracks and reports constraint violations
- **DiagnosticReport**: Rich failure analysis and reporting system
- **SchedulingDiagnostics**: Comprehensive diagnostic infrastructure

### Exception Hierarchy
- **SchedulingException**: Base class for all scheduling exceptions
- **InvalidConfigurationException**: Invalid scheduler configuration
- **IncompleteScheduleException**: Schedule incomplete due to constraint conflicts
- **ImpossibleConstraintsException**: Mathematically impossible constraints

## Integrated Multi-Leg Tournament Architecture

The library uses an **integrated multi-leg generation approach** where multi-leg tournaments are the default assumption (single-leg is `legs=1`):

### LegStrategyInterface
Defines integrated generation strategies for multi-leg tournaments:
```php
interface LegStrategyInterface
{
    /**
     * Plan the generation strategy for a multi-leg tournament.
     */
    public function planGeneration(
        array $participants,
        int $totalLegs,
        int $participantsPerEvent,
        ConstraintSet $constraints
    ): GenerationPlan;

    /**
     * Generate a specific event for a given leg and round.
     */
    public function generateEventForLeg(
        array $participants,
        int $leg,
        int $round,
        SchedulingContext $context
    ): ?Event;

    /**
     * Check if the strategy can satisfy the given constraints.
     */
    public function canSatisfyConstraints(
        array $participants,
        int $legs,
        int $participantsPerEvent,
        ConstraintSet $constraints
    ): ConstraintSatisfiabilityReport;
}
```

### Core Architectural Principles

#### Multi-Leg First Principle
- Multi-leg tournaments are the default assumption throughout the system
- Single-leg tournaments are handled as the special case where `legs=1`
- All components are inherently multi-leg aware

#### Integrated Generation
- Leg generation happens during core algorithm execution, not as post-processing
- Leg strategies participate in real-time constraint validation
- All-or-nothing schedule generation prevents silent event skipping

#### Full Context Visibility
- Constraints see complete tournament state during generation
- SchedulingContext provides cross-leg event visibility
- Enhanced constraint validation with complete tournament context

#### All-or-Nothing Reliability
- Complete schedule generation or detailed failure reporting with diagnostics
- No silent event skipping when constraints fail
- Comprehensive diagnostic reporting for constraint conflicts

## Design Principles

### Modern PHP Architecture
- **Immutability**: All DTOs are readonly classes preventing mutation after construction
- **Strict Typing**: `declare(strict_types=1)` throughout with union types and nullable types
- **Constructor Property Promotion**: Cleaner code with modern PHP 8.2+ features
- **Interface Segregation**: Clean contracts for schedulers and constraints

### Architectural Patterns
- **Strategy Pattern**: Pluggable leg strategies and scheduling algorithms
- **Builder Pattern**: Fluent constraint configuration with ConstraintSet
- **Iterator Pattern**: Memory-efficient traversal of schedules and events  
- **Factory Pattern**: Constraint factory methods for common use cases
- **All-or-Nothing Pattern**: Complete generation or clear failure with diagnostics

### Core Architectural Decisions
- **Multi-Leg First**: All schedulers assume multi-leg capability by default
- **Integrated Generation**: Legs generated during core algorithm, not post-processing
- **Real-Time Validation**: Constraints validated during generation with full context
- **Deterministic Results**: Seeded randomization for reproducible outcomes
- **Memory Efficiency**: Iterator patterns for large tournaments
- **Extensibility**: Clean extension points for additional tournament formats

## Advanced Constraint System

The library provides a comprehensive constraint system supporting complex tournament requirements:

### Built-In Constraints

#### Time-Based Constraints
- **MinimumRestPeriodsConstraint**: Enforces minimum rounds between participant encounters across legs
- **SeedProtectionConstraint**: Prevents high-seeded participants from meeting early in tournament

#### Positional Constraints  
- **ConsecutiveRoleConstraint**: Limits consecutive home/away or positional assignments
- Factory methods: `homeAway(limit)`, `position(limit)`

#### Metadata-Based Constraints
- **MetadataConstraint**: Flexible constraint system using participant metadata
- Factory methods for common patterns:
  - `requireSameValue(field)`: Teams from same division/region only
  - `requireDifferentValues(field)`: Teams from different skill levels  
  - `requireAdjacentValues(field)`: Adjacent skill levels (3 can play 2 or 4)
  - `maxUniqueValues(field, max)`: Maximum variety per match

#### Core Constraints
- **NoRepeatPairings**: Prevents duplicate matches between participants
- **CallableConstraint**: Custom predicate constraints with user-defined logic

### Multi-Leg Constraint Integration

Constraints work seamlessly across multiple legs through enhanced context management:
- **Incremental Context Building**: Each leg's events added to complete tournament context
- **Cross-Leg Awareness**: Constraints see events from all legs during validation
- **Continuous Validation**: Real-time constraint checking during generation
- **Comprehensive Reporting**: Detailed violation tracking with actionable diagnostics

## Comprehensive Validation & Diagnostics System

Tactician includes a sophisticated validation system ensuring tournament completeness and providing detailed failure analysis:

### Validation Architecture

#### Core Validation Components
- **ValidatesScheduleCompleteness**: Trait providing common validation functionality
- **ExpectedEventCalculator**: Interface for calculating theoretical event counts
- **RoundRobinEventCalculator**: Round-robin specific event count calculation
- **ConstraintViolationCollector**: Tracks and aggregates constraint violations during generation

#### Diagnostic Infrastructure  
- **DiagnosticReport**: Rich failure analysis with completion statistics and suggestions
- **SchedulingDiagnostics**: Comprehensive diagnostic analysis and reporting system
- **ConstraintViolation**: Detailed violation information with context and descriptions

### Validation Process

#### 1. Pre-Generation Validation
- Input parameter validation (participant counts, leg counts, configuration)
- Constraint satisfiability analysis using `ConstraintSatisfiabilityReport`
- Early detection of impossible constraint combinations

#### 2. Real-Time Generation Validation
- Continuous constraint checking during event generation
- Full tournament context available for constraint evaluation
- Immediate violation recording with detailed context

#### 3. Post-Generation Verification
- Mathematical validation: expected vs actual event counts
- Schedule completeness verification
- Comprehensive diagnostic report generation

### Exception Hierarchy with Diagnostics

#### SchedulingException (Abstract Base)
```php
abstract class SchedulingException extends Exception
{
    abstract public function getDiagnosticReport(): DiagnosticReport;
}
```

#### Specific Exception Types
- **InvalidConfigurationException**: Invalid scheduler configuration with context data
- **ImpossibleConstraintsException**: Mathematically impossible constraints with analysis
- **IncompleteScheduleException**: Schedule incomplete due to constraint conflicts with diagnostics

### Integration Features

#### Automatic Integration
- All schedulers automatically include validation without performance impact
- Validation occurs throughout generation process, not just at the end
- Fail-fast behavior prevents wasted computation on impossible schedules

#### Diagnostic Reporting
- Detailed failure analysis with root cause identification
- Actionable suggestions for resolving constraint conflicts
- Comprehensive violation tracking with participant and round information
- Mathematical analysis of why constraints cannot be satisfied

## Supporting Value Objects

The architecture includes several value objects that support the core scheduling functionality:

### GenerationPlan
Comprehensive planning object created by leg strategies:
```php
readonly class GenerationPlan
{
    public function __construct(
        public int $totalEvents,
        public int $eventsPerLeg,
        public int $roundsPerLeg,
        public bool $requiresRandomization,
        public array $strategyData = [],
        public array $warnings = []
    ) {}
}
```

### ConstraintSatisfiabilityReport
Analysis of whether constraints can be satisfied:
```php
readonly class ConstraintSatisfiabilityReport
{
    public function __construct(
        public bool $canSatisfy,
        public array $reasons = [],
        public array $suggestedModifications = []
    ) {}
}
```

### DiagnosticReport
Rich diagnostic information for troubleshooting:
```php
readonly class DiagnosticReport
{
    public function __construct(
        public int $expectedEvents,
        public int $generatedEvents,
        public array $missingPairings,
        public array $constraintViolations,
        public string $suggestions,
        public array $metadata = []
    ) {}
}
```

## Performance Considerations

Tactician is optimized for tournaments up to ~50 participants with several performance features:

### Memory Efficiency
- **Iterator Pattern**: Schedule implements Iterator/Countable for memory-efficient traversal
- **Lazy Evaluation**: Events not loaded into memory until needed
- **Immutable Data Structures**: Readonly classes prevent memory leaks and unexpected mutations
- **Generator-Based Patterns**: Efficient iteration without loading entire schedules

### Constraint Optimization
- **Early Termination**: Constraints fail-fast when violations detected
- **Context Caching**: SchedulingContext maintains efficient event lookups
- **Incremental Validation**: Constraints validated during generation, not post-processing
- **Minimal Overhead**: Validation integrated without performance impact

### Algorithm Efficiency
- **Circle Method**: Mathematically optimal round-robin generation (O(n²) complexity)
- **Integrated Multi-Leg**: Single-pass generation for all legs with full context
- **Efficient Pairing**: Direct pairing calculation without unnecessary data transformation
- **Optimized Context**: Minimal context updates during generation

### Scalability Characteristics
- **Target Size**: Optimized for tournaments up to ~50 participants (~1,250 events)
- **Memory Usage**: Linear memory growth with participant count
- **Generation Time**: Sub-second generation for typical tournament sizes
- **Constraint Complexity**: Performance scales with constraint complexity, not just participant count

## Component Relationships

```
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│ SchedulerInterface│    │ ConstraintSet   │    │LegStrategyInterface│
└─────────────────┘    └─────────────────┘    └─────────────────┘
         │                        │                        │
         ▼                        ▼                        ▼
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│RoundRobinScheduler│◄──│ConstraintInterface│   │MirroredLegStrategy│
└─────────────────┘    └─────────────────┘    └─────────────────┘
         │                        │                        │
         ▼                        ▼                        ▼
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│ SchedulingContext│    │NoRepeatPairings │    │RepeatedLegStrategy│
└─────────────────┘    └─────────────────┘    └─────────────────┘
         │                        │                        │
         ▼                        ▼                        ▼
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│    Schedule     │    │MinimumRestPeriods│    │ShuffledLegStrategy│
└─────────────────┘    └─────────────────┘    └─────────────────┘
         │                        │
         ▼                        ▼
┌─────────────────┐    ┌─────────────────┐
│     Event       │    │MetadataConstraint│
└─────────────────┘    └─────────────────┘
         │                        │
         ▼                        ▼
┌─────────────────┐    ┌─────────────────┐
│  Participant    │    │ConsecutiveRole  │
└─────────────────┘    └─────────────────┘
         │
         ▼
┌─────────────────┐
│     Round       │
└─────────────────┘
```

### Data Flow
1. **Input**: Participants, constraints, leg strategy
2. **Planning**: Leg strategy creates GenerationPlan
3. **Generation**: Integrated multi-leg event generation with real-time validation
4. **Validation**: Mathematical verification and constraint satisfaction checking
5. **Output**: Complete Schedule or detailed exception with diagnostics

### Key Integration Points
- **SchedulerInterface**: Entry point supporting multi-leg as first-class feature
- **SchedulingContext**: Central state management with cross-leg visibility
- **ConstraintSet**: Flexible constraint composition with builder pattern
- **LegStrategyInterface**: Integrated generation strategies with constraint planning
- **Validation System**: Comprehensive validation with diagnostic reporting
