# Usage Guide

This comprehensive guide covers all aspects of using Tactician for tournament scheduling, from basic round-robin tournaments to complex multi-leg scenarios with advanced constraints.

## Table of Contents

- [Basic Usage](#basic-usage)
- [Participants and Events](#participants-and-events)
- [Constraint System](#constraint-system)
- [Multi-Leg Tournaments](#multi-leg-tournaments)
- [Schedule Validation](#schedule-validation)
- [Error Handling](#error-handling)
- [Advanced Patterns](#advanced-patterns)
- [Real-World Examples](#real-world-examples)

## Basic Usage

### Simple Round-Robin Tournament

```php
<?php

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Constraints\ConstraintSet;

// Create participants
$participants = [
    new Participant('celtic', 'Celtic'),
    new Participant('athletic', 'Athletic Bilbao'),
    new Participant('livorno', 'AS Livorno'),
    new Participant('redstar', 'Red Star FC'),
];

// Configure constraints
$constraints = ConstraintSet::create()
    ->noRepeatPairings()
    ->build();

// Generate schedule
$scheduler = new RoundRobinScheduler($constraints);
$schedule = $scheduler->schedule($participants);

// Iterate through matches
foreach ($schedule as $event) {
    $round = $event->getRound();
    echo ($round ? "Round {$round->getNumber()}" : "No Round") . ": ";
    echo "{$event->getParticipants()[0]->getLabel()} vs {$event->getParticipants()[1]->getLabel()}\n";
}
```

### Tournament with Seeded Participants

```php
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

// Create seeded participants with metadata
$participants = [
    new Participant('celtic', 'Celtic', 1, ['city' => 'Glasgow', 'division' => 'Premier']),
    new Participant('athletic', 'Athletic Bilbao', 2, ['city' => 'Bilbao', 'division' => 'Premier']),
    new Participant('livorno', 'AS Livorno', 3, ['city' => 'Livorno', 'division' => 'Serie A']),
    new Participant('redstar', 'Red Star FC', 4, ['city' => 'Belgrade', 'division' => 'SuperLiga']),
];

$scheduler = new RoundRobinScheduler();
$schedule = $scheduler->schedule($participants);

// Access schedule metadata
echo "Algorithm: " . $schedule->getMetadataValue('algorithm') . "\n";
echo "Participant count: " . $schedule->getMetadataValue('participant_count') . "\n";
echo "Total rounds: " . $schedule->getMetadataValue('total_rounds') . "\n";
```

## Participants and Events

### Creating Participants

```php
use MissionGaming\Tactician\DTO\Participant;

// Basic participant (ID and label are required)
$participant = new Participant('player1', 'John Doe');

// Participant with seeding
$seededPlayer = new Participant('player2', 'Jane Smith', 1); // Seed 1 (top seed)

// Participant with metadata
$detailedPlayer = new Participant(
    id: 'player3',
    label: 'Team Alpha',
    seed: 2,
    metadata: [
        'skill_level' => 'Advanced',
        'region' => 'Europe',
        'equipment' => 'Standard',
        'availability' => ['monday', 'wednesday', 'friday']
    ]
);

// Access participant properties
echo $detailedPlayer->getId() . "\n";        // 'player3'
echo $detailedPlayer->getLabel() . "\n";     // 'Team Alpha'
echo $detailedPlayer->getSeed() . "\n";      // 2
echo $detailedPlayer->getMetadataValue('region', 'Unknown') . "\n"; // 'Europe'
```

### Working with Events and Rounds

```php
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Round;

// Create a round with metadata
$round = new Round(1, ['phase' => 'group-stage', 'court' => 'A']);

// Create an event between participants
$event = new Event(
    participants: [$participant1, $participant2],
    round: $round,
    metadata: ['start_time' => '10:00', 'referee' => 'John Smith']
);

// Access event properties
$participants = $event->getParticipants();
$roundNumber = $event->getRound()->getNumber();
$startTime = $event->getMetadataValue('start_time');

// Check if a participant is in the event
if ($event->hasParticipant($participant1)) {
    echo "Participant is in this event\n";
}
```

## Constraint System

### Built-In Constraints

```php
use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Constraints\MinimumRestPeriodsConstraint;
use MissionGaming\Tactician\Constraints\SeedProtectionConstraint;
use MissionGaming\Tactician\Constraints\ConsecutiveRoleConstraint;
use MissionGaming\Tactician\Constraints\MetadataConstraint;

// Comprehensive constraint configuration
$constraints = ConstraintSet::create()
    ->noRepeatPairings()  // Prevent duplicate pairings
    ->add(new MinimumRestPeriodsConstraint(2))  // 2 rounds minimum between meetings
    ->add(new SeedProtectionConstraint(2, 0.4))  // Protect top 2 seeds for 40% of tournament
    ->add(ConsecutiveRoleConstraint::homeAway(3))  // Max 3 consecutive home/away games
    ->add(MetadataConstraint::requireSameValue('division'))  // Only pair within same division
    ->build();
```

### Metadata-Based Constraints

```php
// Teams from same region only
$sameRegion = MetadataConstraint::requireSameValue('region');

// Teams from different skill levels
$differentSkills = MetadataConstraint::requireDifferentValues('skill_level');

// Maximum 2 equipment types per match
$equipmentLimit = MetadataConstraint::maxUniqueValues('equipment', 2);

// Adjacent skill levels only (skill level 3 can play 2 or 4, not 1 or 5)
$adjacentSkills = MetadataConstraint::requireAdjacentValues('skill_level');

// Combine multiple metadata constraints
$metadataConstraints = ConstraintSet::create()
    ->add($sameRegion)
    ->add($differentSkills)
    ->add($equipmentLimit)
    ->build();
```

### Custom Constraints

```php
use MissionGaming\Tactician\Constraints\ConstraintSet;

// Custom constraint with lambda function
$customConstraint = ConstraintSet::create()
    ->custom(
        fn($event, $context) => {
            $participants = $event->getParticipants();
            // Ensure participants have compatible equipment
            $equipment1 = $participants[0]->getMetadataValue('equipment');
            $equipment2 = $participants[1]->getMetadataValue('equipment');
            return $equipment1 === $equipment2;
        },
        'Compatible Equipment'
    )
    ->build();

// Complex custom constraint
$advancedConstraint = ConstraintSet::create()
    ->custom(
        function($event, $context) {
            $participants = $event->getParticipants();
            
            // Don't allow same team to play more than twice per day
            $team1Events = $context->getEventsForParticipant($participants[0]);
            $team2Events = $context->getEventsForParticipant($participants[1]);
            
            return count($team1Events) < 3 && count($team2Events) < 3;
        },
        'Maximum Games Per Day'
    )
    ->build();
```

### Role-Based Constraints

```php
// Prevent more than 2 consecutive home games
$homeAwayConstraint = ConsecutiveRoleConstraint::homeAway(2);

// Limit consecutive position assignments
$positionConstraint = ConsecutiveRoleConstraint::position(3);

// Use in scheduler
$constraints = ConstraintSet::create()
    ->add($homeAwayConstraint)
    ->add($positionConstraint)
    ->build();
```

## Multi-Leg Tournaments

Multi-leg tournaments allow the same participants to play multiple times with different arrangements, perfect for home/away leagues or repeated encounters.

### Available Leg Strategies

```php
use MissionGaming\Tactician\LegStrategies\MirroredLegStrategy;
use MissionGaming\Tactician\LegStrategies\RepeatedLegStrategy;
use MissionGaming\Tactician\LegStrategies\ShuffledLegStrategy;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

$participants = [
    new Participant('celtic', 'Celtic'),
    new Participant('athletic', 'Athletic Bilbao'),
    new Participant('livorno', 'AS Livorno'),
    new Participant('redstar', 'Red Star FC'),
];

$scheduler = new RoundRobinScheduler();

// Home and away legs (participant order reversed in second leg)
$mirroredSchedule = $scheduler->schedule(
    participants: $participants,
    participantsPerEvent: 2,
    legs: 2,
    strategy: new MirroredLegStrategy()
);

// Repeated encounters (same pairings each leg)
$repeatedSchedule = $scheduler->schedule(
    participants: $participants,
    participantsPerEvent: 2,
    legs: 3,
    strategy: new RepeatedLegStrategy()
);

// Randomized encounters (shuffled participant order each leg)
$shuffledSchedule = $scheduler->schedule(
    participants: $participants,
    participantsPerEvent: 2,
    legs: 2,
    strategy: new ShuffledLegStrategy()
);
```

### Multi-Leg Schedule Analysis

```php
// Multi-leg schedules provide additional metadata
echo "Total legs: " . $mirroredSchedule->getMetadataValue('legs') . "\n";
echo "Rounds per leg: " . $mirroredSchedule->getMetadataValue('rounds_per_leg') . "\n";
echo "Total rounds: " . $mirroredSchedule->getMetadataValue('total_rounds') . "\n";

// Iterate through events with leg awareness
foreach ($mirroredSchedule as $event) {
    $round = $event->getRound();
    $participants = $event->getParticipants();
    
    // Calculate which leg this event belongs to
    $roundsPerLeg = $mirroredSchedule->getMetadataValue('rounds_per_leg');
    $leg = (int) ceil($round->getNumber() / $roundsPerLeg);
    
    echo "Leg {$leg}, Round {$round->getNumber()}: ";
    echo "{$participants[0]->getLabel()} vs {$participants[1]->getLabel()}\n";
}
```

### Multi-Leg with Constraints

```php
use MissionGaming\Tactician\Constraints\MinimumRestPeriodsConstraint;

// Constraints work across multiple legs
$constraints = ConstraintSet::create()
    ->noRepeatPairings()
    ->add(new MinimumRestPeriodsConstraint(3))  // 3 rounds between encounters (across legs)
    ->build();

$scheduler = new RoundRobinScheduler($constraints);

$schedule = $scheduler->schedule(
    participants: $participants,
    participantsPerEvent: 2,
    legs: 2,
    strategy: new MirroredLegStrategy()
);
```

## Schedule Validation

Tactician includes comprehensive validation to ensure complete tournaments and prevent silent failures.

### Basic Validation

```php
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Constraints\ConsecutiveRoleConstraint;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;

$participants = [
    new Participant('celtic', 'Celtic'),
    new Participant('athletic', 'Athletic Bilbao'),
    new Participant('livorno', 'AS Livorno'),
    new Participant('redstar', 'Red Star FC'),
];

// Configure restrictive constraints that might prevent complete scheduling
$constraints = ConstraintSet::create()
    ->add(ConsecutiveRoleConstraint::homeAway(1))  // Very restrictive
    ->build();

try {
    $scheduler = new RoundRobinScheduler($constraints);
    $schedule = $scheduler->schedule($participants);
    
    // If we get here, the schedule is complete and valid
    echo "Schedule generated successfully with " . count($schedule) . " events\n";
    
} catch (IncompleteScheduleException $e) {
    // Schedule validation caught an incomplete tournament
    echo "Cannot generate complete schedule: " . $e->getMessage() . "\n";
    
    // Get diagnostic information
    $violations = $e->getConstraintViolations();
    foreach ($violations as $violation) {
        echo "Violation: " . $violation->getDescription() . "\n";
    }
    
    // Get expected vs actual event counts
    echo "Expected events: " . $e->getExpectedEventCount() . "\n";
    echo "Generated events: " . $e->getGeneratedEventCount() . "\n";
}
```

### Validation Features

```php
use MissionGaming\Tactician\Exceptions\ImpossibleConstraintsException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;

try {
    $scheduler = new RoundRobinScheduler($constraints);
    $schedule = $scheduler->schedule($participants);
    
} catch (ImpossibleConstraintsException $e) {
    // Constraints are mathematically impossible
    echo "Impossible constraints: " . $e->getMessage() . "\n";
    echo "Constraint analysis: " . $e->getConstraintAnalysis() . "\n";
    
} catch (InvalidConfigurationException $e) {
    // Invalid scheduler configuration
    echo "Configuration error: " . $e->getMessage() . "\n";
    echo "Context: " . json_encode($e->getContext()) . "\n";
    
} catch (IncompleteScheduleException $e) {
    // Schedule incomplete due to constraint conflicts
    echo "Incomplete schedule: " . $e->getMessage() . "\n";
    
    // Generate diagnostic report
    $diagnostics = $e->getDiagnosticReport();
    echo $diagnostics . "\n";
}
```

## Error Handling

### Exception Hierarchy

```php
use MissionGaming\Tactician\Exceptions\SchedulingException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\Exceptions\ImpossibleConstraintsException;

try {
    $scheduler = new RoundRobinScheduler($constraints);
    $schedule = $scheduler->schedule($participants);
    
} catch (InvalidConfigurationException $e) {
    // Handle configuration errors
    handleConfigurationError($e);
    
} catch (ImpossibleConstraintsException $e) {
    // Handle impossible constraints
    handleImpossibleConstraints($e);
    
} catch (IncompleteScheduleException $e) {
    // Handle incomplete schedules
    handleIncompleteSchedule($e);
    
} catch (SchedulingException $e) {
    // Handle any other scheduling exception
    handleGenericSchedulingError($e);
}

function handleIncompleteSchedule(IncompleteScheduleException $e): void
{
    echo "Schedule could not be completed:\n";
    echo "Reason: " . $e->getMessage() . "\n";
    
    // Analyze constraint violations
    $violations = $e->getConstraintViolations();
    if (!empty($violations)) {
        echo "\nConstraint violations:\n";
        foreach ($violations as $violation) {
            echo "- " . $violation->getDescription() . "\n";
        }
    }
    
    // Show completion statistics
    echo "\nCompletion: {$e->getGeneratedEventCount()}/{$e->getExpectedEventCount()} events\n";
}
```

## Advanced Patterns

### Custom Schedulers

```php
use MissionGaming\Tactician\Scheduling\SchedulerInterface;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\LegStrategies\LegStrategyInterface;

class CustomScheduler implements SchedulerInterface
{
    public function schedule(
        array $participants,
        int $participantsPerEvent = 2,
        int $legs = 1,
        ?LegStrategyInterface $strategy = null
    ): Schedule {
        // Custom scheduling logic
        $events = $this->generateCustomEvents($participants);
        
        return new Schedule($events, [
            'algorithm' => 'custom',
            'participant_count' => count($participants),
        ]);
    }
    
    // Implement other required interface methods...
}
```

### Scheduling Context

```php
use MissionGaming\Tactician\Scheduling\SchedulingContext;

// Create context with participants and existing events
$context = new SchedulingContext(
    $participants,
    $existingEvents,
    $currentLeg = 1,
    $totalLegs = 2,
    $participantsPerEvent = 2
);

// Check if participants have played together
$havePlayed = $context->haveParticipantsPlayedTogether($participant1, $participant2);

// Get events for a specific participant
$playerEvents = $context->getEventsForParticipant($participant1);

// Add new events to context
$newContext = $context->withEvents([$newEvent]);
```

### Deterministic Randomization

```php
use Random\Randomizer;
use Random\Engine\Mt19937;

// Create seeded randomizer for reproducible results
$randomizer = new Randomizer(new Mt19937(12345));

$scheduler = new RoundRobinScheduler(null, $randomizer);
$schedule = $scheduler->schedule($participants);

// Same seed will always produce the same schedule
```

## Real-World Examples

### Premier League Style Tournament

```php
// 20 teams, home and away legs
$teams = [
    new Participant('mci', 'Manchester City', 1),
    new Participant('ars', 'Arsenal', 2),
    new Participant('liv', 'Liverpool', 3),
    // ... 17 more teams
];

$constraints = ConstraintSet::create()
    ->noRepeatPairings()
    ->add(new MinimumRestPeriodsConstraint(5))  // Teams don't play again soon
    ->build();

$scheduler = new RoundRobinScheduler($constraints);

// Generate full season: 2 legs (home and away)
$season = $scheduler->schedule(
    participants: $teams,
    participantsPerEvent: 2,
    legs: 2,
    strategy: new MirroredLegStrategy()
);

echo "Premier League season: " . count($season) . " matches\n";
// Expected: 380 matches (20 teams × 19 opponents × 2 legs)
```

### Gaming Tournament with Skill Brackets

```php
$players = [
    new Participant('pro1', 'ProGamer1', 1, ['skill' => 'professional']),
    new Participant('pro2', 'ProGamer2', 2, ['skill' => 'professional']),
    new Participant('semi1', 'SemiPro1', 3, ['skill' => 'semi-professional']),
    new Participant('semi2', 'SemiPro2', 4, ['skill' => 'semi-professional']),
    new Participant('am1', 'Amateur1', 5, ['skill' => 'amateur']),
    new Participant('am2', 'Amateur2', 6, ['skill' => 'amateur']),
];

// Only allow players of adjacent skill levels to play
$constraints = ConstraintSet::create()
    ->noRepeatPairings()
    ->add(MetadataConstraint::requireAdjacentValues('skill'))
    ->add(new SeedProtectionConstraint(2, 0.3))  // Protect top 2 for 30% of tournament
    ->build();

$scheduler = new RoundRobinScheduler($constraints);
$tournament = $scheduler->schedule($players);
```

### Corporate Team Building Tournament

```php
$departments = [
    new Participant('eng', 'Engineering', 1, ['location' => 'Building A', 'size' => 'large']),
    new Participant('mark', 'Marketing', 2, ['location' => 'Building B', 'size' => 'medium']),
    new Participant('sales', 'Sales', 3, ['location' => 'Building A', 'size' => 'large']),
    new Participant('hr', 'Human Resources', 4, ['location' => 'Building B', 'size' => 'small']),
];

// Mix departments from different buildings, balance team sizes
$constraints = ConstraintSet::create()
    ->noRepeatPairings()
    ->add(MetadataConstraint::requireDifferentValues('location'))  // Cross-building teams
    ->add(MetadataConstraint::maxUniqueValues('size', 2))  // Limit size variety per match
    ->build();

$scheduler = new RoundRobinScheduler($constraints);
$teamBuilding = $scheduler->schedule($departments);
```

### Multi-Day Tournament with Rest Periods

```php
$teams = [
    new Participant('team1', 'Team Alpha'),
    new Participant('team2', 'Team Beta'),
    new Participant('team3', 'Team Gamma'),
    new Participant('team4', 'Team Delta'),
    new Participant('team5', 'Team Epsilon'),
    new Participant('team6', 'Team Zeta'),
];

// Ensure teams get adequate rest between matches
$constraints = ConstraintSet::create()
    ->noRepeatPairings()
    ->add(new MinimumRestPeriodsConstraint(4))  // 4 rounds minimum between encounters
    ->add(ConsecutiveRoleConstraint::homeAway(2))  // Max 2 consecutive home/away
    ->build();

try {
    $scheduler = new RoundRobinScheduler($constraints);
    $tournament = $scheduler->schedule($teams);
    
    echo "Tournament scheduled successfully!\n";
    echo "Total matches: " . count($tournament) . "\n";
    echo "Total rounds: " . $tournament->getMetadataValue('total_rounds') . "\n";
    
} catch (IncompleteScheduleException $e) {
    echo "Could not schedule with current constraints\n";
    echo "Try reducing minimum rest periods or removing consecutive role limits\n";
}
```

## Performance Considerations

### Memory Efficiency

```php
// For large tournaments, iterate efficiently
$largeSchedule = $scheduler->schedule($manyParticipants);

// Memory-efficient iteration (doesn't load all events at once)
foreach ($largeSchedule as $event) {
    processEvent($event);
    // Each event is garbage collected after processing
}

// Count events without loading them all
$totalEvents = count($largeSchedule);
```

### Constraint Optimization

```php
// Order constraints from most restrictive to least restrictive
// for better performance
$optimizedConstraints = ConstraintSet::create()
    ->add(new MinimumRestPeriodsConstraint(3))  // Most restrictive first
    ->add(ConsecutiveRoleConstraint::homeAway(2))
    ->add(MetadataConstraint::requireSameValue('division'))
    ->noRepeatPairings()  // Least restrictive last
    ->build();
```

This guide covers the essential patterns for using Tactician effectively. For more advanced use cases or when contributing to the library, see the [Architecture documentation](ARCHITECTURE.md) and [Contributing guidelines](CONTRIBUTING.md).
