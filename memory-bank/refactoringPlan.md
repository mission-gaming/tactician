# Multi-Leg Architecture Refactoring Plan

## Executive Summary

This document outlines the comprehensive refactoring plan to address critical architectural flaws in the current multi-leg tournament system. The primary issue is silent event skipping when constraints fail during leg expansion, which is unacceptable for tournament scheduling reliability.

## Critical Problems Identified

### Silent Event Skipping
- Current `SupportsMultipleLegs` trait can silently skip events when constraints fail
- Post-processing approach to leg generation causes late constraint detection
- Events dropped without clear exception reporting when constraints conflict

### Architectural Flaws
- Multi-leg tournaments treated as optional extension rather than core system assumption
- SchedulingContext not inherently multi-leg aware
- Constraint validation occurs after leg expansion, not during generation
- Leg strategies designed for post-processing transformation, not integrated generation

## Core Architecture Changes

### Enhanced SchedulingContext
Replace the need for separate "MultiLegContext" by enhancing the existing `SchedulingContext` since multi-leg is the default assumption:

```php
class SchedulingContext {
    public function __construct(
        private readonly array $allParticipants,
        private readonly array $allEvents,           // Events from all legs
        private readonly int $currentLeg,
        private readonly int $totalLegs,
        private readonly int $participantsPerEvent = 2,
        private readonly array $metadata = []
    ) {}
    
    public function getEventsForLeg(int $leg): array;
    public function getEventsForParticipant(Participant $participant): array;
    public function getEventsInRound(int $round): array;
    public function hasEventBetween(array $participants): bool;
    public function getEventCount(): int;
    public function getExpectedEventCount(): int;
}
```

### Updated LegStrategy Interface
Complete redesign of the leg strategy system for integrated generation:

```php
interface LegStrategy {
    public function planGeneration(
        array $participants, 
        int $totalLegs, 
        int $participantsPerEvent,
        ConstraintSet $constraints
    ): GenerationPlan;
    
    public function generateEventForLeg(
        array $participants,
        int $leg, 
        int $round,
        SchedulingContext $context
    ): ?Event;
    
    public function canSatisfyConstraints(
        array $participants,
        int $legs,
        int $participantsPerEvent,
        ConstraintSet $constraints
    ): ConstraintSatisfiabilityReport;
}
```

### Enhanced SchedulerInterface
```php
interface SchedulerInterface {
    public function schedule(
        array $participants,
        int $participantsPerEvent = 2,  // Future-proofing for N-participant events
        int $legs = 1,                  // Standard parameter
        ?LegStrategy $strategy = null   // Standard parameter
    ): Schedule;
    
    public function validateConstraints(
        array $participants, 
        int $legs,
        int $participantsPerEvent = 2
    ): void;
    
    public function getExpectedEventCount(
        array $participants, 
        int $legs,
        int $participantsPerEvent = 2
    ): int;
}
```

### Core RoundRobinScheduler Refactor

**Architectural Changes:**

1. **Remove SupportsMultipleLegs trait** - multi-leg is integrated into core algorithm
2. **Unified generation loop** - all legs generated with full context visibility
3. **All-or-nothing guarantee** - complete schedule or detailed failure reporting
4. **Integrated constraint validation** - constraints see full tournament context during generation

```php
class RoundRobinScheduler implements SchedulerInterface {
    use ValidatesScheduleCompleteness;
    
    public function schedule(
        array $participants,
        int $participantsPerEvent = 2,
        int $legs = 1,
        ?LegStrategy $strategy = null
    ): Schedule {
        $this->validateInputs($participants, $participantsPerEvent, $legs);
        
        $strategy ??= new MirroredLegStrategy();
        $plan = $strategy->planGeneration($participants, $legs, $participantsPerEvent, $this->constraints);
        
        $allEvents = $this->generateIntegratedSchedule($participants, $participantsPerEvent, $legs, $strategy, $plan);
        
        return $this->finalizeSchedule($allEvents, $participants, $legs, $participantsPerEvent);
    }
    
    private function generateIntegratedSchedule(
        array $participants,
        int $participantsPerEvent,
        int $legs,
        LegStrategy $strategy,
        GenerationPlan $plan
    ): array {
        $allEvents = [];
        $context = new SchedulingContext($participants, [], 1, $legs, $participantsPerEvent);
        
        for ($leg = 1; $leg <= $legs; ++$leg) {
            $legEvents = $this->generateLegWithFullContext($participants, $participantsPerEvent, $leg, $strategy, $context);
            
            if (empty($legEvents) && $this->shouldHaveEvents($participants, $participantsPerEvent)) {
                throw new IncompleteScheduleException(
                    "Failed to generate complete schedule for leg {$leg}",
                    $this->buildDiagnosticData($context, $leg)
                );
            }
            
            $allEvents = [...$allEvents, ...$legEvents];
            $context = new SchedulingContext($participants, $allEvents, $leg + 1, $legs, $participantsPerEvent);
        }
        
        return $allEvents;
    }
}
```

## Constraint System Enhancement

### Updated ConstraintInterface
No separate multi-leg methods needed since `SchedulingContext` inherently contains multi-leg information:

```php
interface ConstraintInterface {
    public function isSatisfied(Event $event, SchedulingContext $context): bool;
    public function getViolationReason(Event $event, SchedulingContext $context): string;
}
```

## Diagnostic System Architecture

### Comprehensive Failure Analysis
```php
class SchedulingDiagnostics {
    public function analyzeSchedulingFailure(
        array $participants,
        ConstraintSet $constraints,
        array $partialEvents,
        int $legs
    ): DiagnosticReport;
    
    public function identifyConstraintConflicts(ConstraintSet $constraints): array;
    public function suggestConstraintAdjustments(DiagnosticReport $report): array;
}

class DiagnosticReport {
    public function __construct(
        public readonly array $missingEvents,
        public readonly array $constraintViolations,
        public readonly array $impossiblePairings,
        public readonly array $suggestions
    ) {}
}
```

### Enhanced Exception System
- `IncompleteScheduleException` enhanced with comprehensive diagnostic data
- `ImpossibleConstraintsException` enhanced with conflict analysis
- `ConstraintViolationException` for runtime constraint failures

## Implementation Sequence

### Phase 1: Core Foundation
- Enhance `SchedulingContext` with inherent multi-leg support
- Create new `LegStrategy` interface
- Create `GenerationPlan` and related value objects

### Phase 2: Algorithm Integration
- Completely refactor `RoundRobinScheduler` to integrate multi-leg generation
- Remove `SupportsMultipleLegs` trait
- Update existing leg strategy implementations

### Phase 3: Constraint Enhancement
- Update constraint interface if needed
- Ensure all constraints work with enhanced `SchedulingContext`
- Add constraint conflict detection

### Phase 4: Diagnostics & Error Handling
- Build comprehensive diagnostic system
- Enhance exception hierarchy with detailed reporting
- Add all-or-nothing validation

### Phase 5: Legacy Cleanup
- Remove old `LegStrategyInterface` 
- Remove `SupportsMultipleLegs` trait
- Clean up any remaining multi-leg naming

### Phase 6: Testing Implementation
- Unit tests for all new components
- Integration tests for real-world scenarios (Premier League, Champions League)
- Edge case testing (2 participants, 50 participants)
- Performance validation

## Key Architectural Principles

### Multi-leg as Default
Every component assumes multi-leg capability from the ground up, with single-leg as `legs=1` special case.

### Integrated Generation
Leg generation happens during core algorithm execution, not as post-processing.

### Full Context Visibility
Constraints see complete tournament state during generation, not partial context.

### All-or-Nothing Reliability
Complete schedule generation or detailed failure reporting with actionable diagnostics.

### Interface Flexibility
Support for future N-participant events built into interfaces without implementation complexity.

## Target Use Cases

The library is designed primarily for esports tournaments with these reference scenarios:

- **English Premier League**: 20 teams, 2 legs, round robin, 380 total matches
- **UEFA Champions League – League Stage**: 36 teams, each team plays 8 matches (4 home, 4 away), Swiss format, 144 total matches
- **Scottish Premiership – Pre-Split Stage**: 12 teams, 2 legs, round robin, 198 total matches

Maximum expected tournament size: ~500 events.

## Success Criteria

✅ **No silent event skipping** - All failures throw exceptions with diagnostics
✅ **Complete multi-leg integration** - No post-processing approach
✅ **Interface flexibility** - Support for future N-participant events
✅ **Comprehensive testing** - All real-world scenarios covered
✅ **Performance acceptable** - Under 1 second for 500-event tournaments

## Future Considerations

### N-Participant Events
The architecture supports future expansion to events with more than 2 participants:
- Job interviews (3-4 candidates + 2 interviewers)
- Team tournaments (3v3, 5v5 esports matches)
- Social events (dinner party table assignments)

### External Solver Integration
Interface design allows for future integration with external constraint solvers like Google CP-SAT when more complex combinatorial problems need solving.

---
*Created: 2025-10-01*
*Purpose: Technical specification for multi-leg architecture refactoring*
