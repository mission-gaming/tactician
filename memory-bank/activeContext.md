# Active Context: Tactician

## Current Work Focus
- **✅ PHASE 1 COMPLETE**: Multi-leg architecture foundation implemented (2025-10-01)
- **✅ PHASE 2 COMPLETE**: Algorithm Integration successfully implemented (2025-10-02)
- **✅ PHASE 2 COMPLETE**: Position-Based Architecture successfully implemented (2025-10-03)
- **✅ PHASE 3 COMPLETE**: Participant Ordering System successfully implemented (2025-10-03)
- **STATUS**: Ready for Phase 4 - Swiss Tournament Scheduler Implementation
- **QUALITY STANDARDS**: 313/313 tests passing, 1,245 assertions, 0 PHPStan errors, production-ready

## Recent Changes (2025-10-03)

### **Participant Ordering System Implementation** ✅ **NEW - COMPLETE**
- **✅ COMPLETE**: Core interface and context (2 files)
  - `ParticipantOrderer` - Strategy interface for ordering participants within events
  - `EventOrderingContext` - Context with roundNumber, eventIndexInRound, leg, schedulingContext

- **✅ COMPLETE**: Four built-in implementations (4 files)
  - `StaticParticipantOrderer` - Maintains array order (default, backward compatible)
  - `AlternatingParticipantOrderer` - Alternates based on event index (odd/even pattern)
  - `BalancedParticipantOrderer` - Balances home/away based on participant history (Swiss-ready!)
  - `SeededRandomParticipantOrderer` - Deterministic randomization per event using CRC32 hash

- **✅ COMPLETE**: Integration with scheduling system
  - Updated `PositionalRound.resolve()` to accept and apply ParticipantOrderer
  - Updated `RoundRobinScheduler` constructor with optional `participantOrderer` parameter
  - Default to `StaticParticipantOrderer` for full backward compatibility
  - Orderer applied at event creation time with full context

- **✅ COMPLETE**: Comprehensive testing (29 new tests)
  - 22 unit tests for all four orderer implementations
  - 7 integration tests demonstrating real-world usage and home/away distribution
  - Tests show ordering works independently of leg strategies
  - **Total: 313 tests passing, 1,245 assertions**

- **✅ COMPLETE**: Key features delivered
  - Separation of concerns (participant ordering ≠ leg strategies)
  - Universal applicability (works for round-robin, will work for Swiss)
  - Composability (mix any orderer with any leg strategy)
  - Swiss-ready (`BalancedParticipantOrderer` tracks participant history)
  - Fully backward compatible (defaults to static ordering)

### **Position-Based Architecture Implementation**
- **✅ COMPLETE**: Position system foundation (7 new classes)
  - `Position` - Abstract position reference with type and value
  - `PositionType` enum - SEED, STANDING, STANDING_AFTER_ROUND
  - `PositionalPairing` - Position-based pairing that resolves to participants
  - `PositionalRound` - Round structure in positional terms
  - `PositionalSchedule` - Complete tournament blueprint
  - `PositionResolver` interface - Strategy for resolving positions
  - `SeedBasedPositionResolver` - Resolves seed positions to participants

- **✅ COMPLETE**: Unified Scheduler API
  - `generateStructure(int $participantCount)` - Get tournament blueprint
  - `generateSchedule(array $participants)` - Single-leg complete generation
  - `generateRound(array $participants, int $round, ?PositionResolver)` - Round-by-round
  - `generateMultiLegSchedule(array $participants, int $legs, ?LegStrategy)` - Multi-leg (RR-specific)
  - `supportsCompleteGeneration()` - Capability check for algorithm
  - Removed `legs` from universal interface (now round-robin specific)

- **✅ COMPLETE**: RoundRobinScheduler refactoring (685 lines)
  - Implements all new unified interface methods
  - Position-based generation with seed resolution
  - Fixed odd participant round calculation (3 participants = 3 rounds, not 2)
  - All constraint validation maintained
  - Deterministic/random seeding preserved

- **✅ COMPLETE**: Test suite migration (284 tests passing)
  - 26 unit tests for position system
  - 10 unit tests for RoundRobinScheduler
  - 35 feature tests updated to new API
  - All tests passing with 1,115 assertions

- **✅ COMPLETE**: Value objects
  - `RoundSchedule` - Single round representation for round-by-round generation
  - `UnsupportedOperationException` - For operations not supported by scheduler
  - Enhanced `Schedule` with `getPositionalStructure()` and `isFullyResolved()`

### **Previous Milestones**
- **✅ 2025-10-02**: Algorithm Integration complete - integrated multi-leg generation
- **✅ 2025-10-02**: Documentation restructuring complete - API accuracy restored
- **✅ 2025-10-02**: Examples directory complete - 12 browser-based examples
- **✅ 2025-10-01**: Phase 1 foundation complete - SchedulingContext, LegStrategy interface
- **✅ 2025-09-11**: Schedule validation system, constraint system, CI pipeline

## Next Steps

### **PHASE 4: Swiss Tournament Scheduler** (Next)

**Goal**: Implement Swiss tournament scheduling with both pre-determined and dynamic pairing support.

**Components to Implement**:
1. **Swiss Scheduler** (~300 lines)
   - `SwissScheduler` implementing `SchedulerInterface`
   - Supports both complete and round-by-round generation
   - Uses position system for standings-based pairing

2. **Position Resolution** (~100 lines)
   - `StandingsBasedPositionResolver` - Resolves STANDING positions
   - `ParticipantStanding` value object - Wraps participant with standing data
   - `StandingsContext` - Holds current standings for resolver

3. **Pairing Algorithms** (~200 lines)
   - `SwissPairingAlgorithm` interface
   - `SeededSwissAlgorithm` - Pre-determined pairings (UEFA Champions League style)
   - `StandingsBasedSwissAlgorithm` - Dynamic pairing based on current standings

4. **Testing** (~300 lines)
   - Unit tests for all components
   - Integration tests for both Swiss variants
   - Composition tests with participant orderers

5. **Examples** (~100 lines)
   - Pre-determined Swiss example (UEFA CL format)
   - Dynamic Swiss example (traditional chess/esports)

**Total Estimated**: ~1,000 lines of well-tested, focused code

### **PHASE 5: Polish & Documentation** (Future)
- Update examples directory with comprehensive Swiss examples
- Integration tests for complete system (round-robin + Swiss + orderers)
- Performance benchmarks for large tournaments
- Complete API documentation
- Usage guides and tutorials

## Active Decisions and Considerations

### **Architecture Principles**
- **Modern PHP 8.2+**: Readonly classes, constructor property promotion, strict typing throughout
- **Immutable Value Objects**: All DTOs are immutable for thread safety and predictability
- **Interface Segregation**: Clean contracts for schedulers, constraints, position resolvers, and orderers
- **Strategy Pattern**: Pluggable algorithms (scheduling, leg strategies, position resolution, ordering)
- **Position-Based Scheduling**: Universal abstraction enabling both static and dynamic tournaments
- **Separation of Concerns**:
  - Scheduling algorithms generate structures
  - Position resolvers assign participants to positions
  - Participant orderers control event-level ordering
  - Leg strategies handle multi-leg variations (round-robin specific)
  - Constraints validate throughout

### **Design Decisions**
- **🆕 LEGS ARE ROUND-ROBIN SPECIFIC**: Not all algorithms have legs (Swiss doesn't)
- **🆕 POSITION SYSTEM IS UNIVERSAL**: All algorithms use positions that resolve to participants
- **🆕 PARTICIPANT ORDERING IS UNIVERSAL**: All algorithms can benefit from ordering strategies
- **🆕 STANDINGS CALCULATION IS EXTERNAL**: Schedulers don't calculate standings/points
- **ALL-OR-NOTHING GENERATION**: No silent event skipping - complete schedule or clear failure
- **INTEGRATED LEG GENERATION**: Legs generated during core algorithm, not post-processing
- **SWISS-READY ARCHITECTURE**: Position system designed for both static and dynamic pairing

## Important Patterns and Preferences
- **Test-Driven Development**: Comprehensive Pest test coverage for all components
- **Dependency Injection**: Constructor-based dependencies, no static methods
- **Iterator/Countable**: Memory-efficient traversal of schedules and events
- **Advanced Constraint System**: Sophisticated constraint validation with incremental context building
- **Factory Pattern**: Constraint factory methods for common use cases
- **Deterministic Randomization**: Seeded randomization for reproducible results
- **Fluent Builders**: ConstraintSet::create() pattern for configuration

## Learnings and Project Insights

### **Architectural Insights (2025-10-03)**
- **🆕 POSITION ABSTRACTION POWER**: Position-based scheduling enables projection and dynamic pairing
- **🆕 UNIFIED API BENEFIT**: Same interface works for static (round-robin) and dynamic (Swiss) algorithms
- **🆕 PARTICIPANT ORDERING NEED**: Essential for realistic tournament scheduling (home/away balance)
- **🆕 SCOPE MATTERS**: Legs are algorithm-specific (round-robin), not universal feature
- **🆕 SWISS REQUIREMENTS**: Dynamic Swiss needs standings input but same core architecture
- **🆕 PROJECTION VALUE**: Can inspect tournament structure before any matches played

### **Previous Learnings**
- **Architecture Quality**: Implementation follows excellent SOLID principles and modern PHP patterns
- **Algorithm Completeness**: Round-robin handles all edge cases (2, 3, 4+ participants, odd/even)
- **Test Coverage**: Comprehensive testing validates mathematical correctness
- **Memory Efficiency**: Iterator pattern enables efficient traversal without loading all events
- **Constraint System**: Sophisticated validation works across tournament legs
- **Schedule Validation**: Mathematical validation (expected vs actual) proves highly effective
- **PHPStan Value**: Level 8 static analysis catches subtle issues before production
- **Exception Design**: Hierarchical exceptions with diagnostics provide excellent DX
- **Documentation Quality**: Proper annotations and type specifications crucial for maintainability

### **Critical Discoveries**
- **🔴 MULTI-LEG EVENT SKIPPING** (Oct 1) → ✅ RESOLVED with integrated generation (Oct 2)
- **🔴 POST-PROCESSING CONSTRAINT VALIDATION** (Oct 1) → ✅ RESOLVED with generation-time validation (Oct 2)
- **🔴 FIXED PARTICIPANT ORDERING** (Oct 3) → ✅ RESOLVED with ParticipantOrderer system (Oct 3)

## Current Implementation Status
**Phase**: Participant Ordering Complete (Phase 3)
**Progress**: 100% (Phases 1, 2 & 3 complete, ready for Phase 4)
**Test Status**: 313 tests passing, 1,245 assertions, 0 failures

### **Core Components Implemented**
- ✅ **Position System**: 7 classes for position-based scheduling
- ✅ **Unified Scheduler API**: Works for static and dynamic algorithms
- ✅ **RoundRobinScheduler**: Complete refactor with position-based generation
- ✅ **Participant Ordering**: 4 orderer implementations (Static, Alternating, Balanced, SeededRandom)
- ✅ **Leg Strategies**: MirroredLegStrategy, RepeatedLegStrategy, ShuffledLegStrategy
- ✅ **Constraint System**: NoRepeatPairings, MinimumRestPeriods, SeedProtection, ConsecutiveRole, Metadata
- ✅ **Validation System**: ScheduleValidator with comprehensive diagnostics
- ✅ **Test Suite**: 313 tests with complete coverage (1,245 assertions)

### **Ready for Implementation**
- 🔄 **Swiss Tournament System** (Phase 4 - Next)
- ⏳ **Pool/Group Tournament System** (Phase 4)
- ⏳ **Timeline Assignment** (Phase 5)

### **Architecture Readiness**
- ✅ **Swiss Pre-determined**: Position system supports seed-based Swiss (UEFA CL style)
- ✅ **Swiss Dynamic**: Position system supports standings-based Swiss (traditional)
- ✅ **Round-by-Round Generation**: `generateRound()` method ready for dynamic scheduling
- ✅ **Projection/Inspection**: `generateStructure()` enables blueprint viewing
- ✅ **Extensibility**: Clean interfaces ready for new algorithms

---
*Last Updated: 2025-10-03 (Phase 3 Complete - Participant Ordering)*
