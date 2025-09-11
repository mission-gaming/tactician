# Tactician

[![PHP Version](https://img.shields.io/badge/php-%5E8.2-blue)](https://packagist.org/packages/mission-gaming/tactician)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)
[![Build Status](https://github.com/mission-gaming/tactician/actions/workflows/ci.yml/badge.svg)](https://github.com/mission-gaming/tactician/actions/workflows/ci.yml)
[![codecov](https://codecov.io/github/mission-gaming/tactician/graph/badge.svg?token=B5QQBW434A)](https://codecov.io/github/mission-gaming/tactician)

## Overview

A modern PHP library for generating structured schedules between participants. Ideal for tournaments (round robin, Swiss, pools) but flexible enough for any scenario where entities need to be paired or grouped into events.

**Key Features:**

- 🏆 **Deterministic Algorithms**: Round Robin (complete), Swiss and Pool play (coming soon)
- 🔧 **Flexible Constraints**: Built-in and custom predicate-based constraint system
- ✅ **Schedule Validation**: Comprehensive validation prevents incomplete schedules
- ⚡ **Memory Efficient**: Generator-based iteration for large tournaments
- 🎯 **Modern PHP**: PHP 8.2+ with readonly classes and strict typing
- 🧪 **Test-Driven**: Comprehensive test suite with Pest framework
- 📐 **Mathematical Accuracy**: Circle method implementation for round-robin
- 🛡️ **Production Ready**: PHPStan level 8 compliance with zero errors

## Installation

Install via Composer:

```bash
composer require mission-gaming/tactician
```

**Requirements:**
- PHP 8.2+
- No external dependencies in production

## Quick Start

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

## Core Concepts

### Participants and Events

```php
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Round;

// Create participants with unique identifiers
$player1 = new Participant('celtic', 'Celtic', 1, ['city' => 'Glasgow']);
$player2 = new Participant('athletic', 'Athletic Bilbao', 2, ['city' => 'Bilbao']);

// Create a round object with metadata
$round = new Round(1, ['phase' => 'group-stage']);

// Events represent matches/games between participants
$event = new Event(
    participants: [$player1, $player2],
    round: $round,
    metadata: ['court' => 'A', 'time' => '10:00']
);
```

### Constraint System

Tactician provides a sophisticated constraint system for controlling tournament pairings:

```php
use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Constraints\MinimumRestPeriodsConstraint;
use MissionGaming\Tactician\Constraints\SeedProtectionConstraint;
use MissionGaming\Tactician\Constraints\ConsecutiveRoleConstraint;
use MissionGaming\Tactician\Constraints\MetadataConstraint;

// Advanced constraint configuration
$constraints = ConstraintSet::create()
    ->noRepeatPairings()  // Built-in: prevent duplicate pairings
    ->add(new MinimumRestPeriodsConstraint(2))  // 2 rounds minimum between repeat meetings
    ->add(new SeedProtectionConstraint(2, 0.4))  // Protect top 2 seeds for 40% of tournament
    ->add(ConsecutiveRoleConstraint::homeAway(3))  // Max 3 consecutive home/away games
    ->add(MetadataConstraint::requireSameValue('division'))  // Only pair within same division
    ->custom(fn($event, $context) => 
        // Custom constraint logic
        !$this->participantHasConflict($event->getParticipants()[0], $context)
    )
    ->build();

// Use constraints in scheduler
$scheduler = new RoundRobinScheduler($constraints);
```

#### Available Constraints

**Built-in Constraints:**
- 🚫 **NoRepeatPairings**: Prevents duplicate matches between participants
- ⏱️ **MinimumRestPeriodsConstraint**: Ensures minimum rounds between participant meetings
- 🏆 **SeedProtectionConstraint**: Prevents top seeds from meeting early in tournament
- 🏠 **ConsecutiveRoleConstraint**: Limits consecutive home/away or positional assignments
- 📊 **MetadataConstraint**: Flexible metadata-based pairing rules

**Metadata Constraint Examples:**
```php
// Teams from same region only
MetadataConstraint::requireSameValue('region')

// Teams from different skill levels
MetadataConstraint::requireDifferentValues('skill_level')

// Maximum 2 equipment types per match
MetadataConstraint::maxUniqueValues('equipment', 2)

// Adjacent skill levels only (3 can play 2 or 4, not 1 or 5)
MetadataConstraint::requireAdjacentValues('skill_level')
```

**Role Constraint Examples:**
```php
// Prevent more than 2 consecutive home games
ConsecutiveRoleConstraint::homeAway(2)

// Limit consecutive position assignments
ConsecutiveRoleConstraint::position(3)
```

### Schedule Validation

Tactician includes comprehensive schedule validation to ensure complete tournaments and prevent silent failures:

```php
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Constraints\ConsecutiveRoleConstraint;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;

// Create participants
$participants = [
    new Participant('celtic', 'Celtic'),
    new Participant('athletic', 'Athletic Bilbao'),
    new Participant('livorno', 'AS Livorno'),
    new Participant('redstar', 'Red Star FC'),
];

// Configure restrictive constraints
$constraints = ConstraintSet::create()
    ->add(ConsecutiveRoleConstraint::homeAway(2))  // Very restrictive with only 4 participants
    ->build();

try {
    $scheduler = new RoundRobinScheduler($constraints, null, 2);
    $schedule = $scheduler->schedule($participants);
    
    // If we get here, the schedule is complete and valid
    foreach ($schedule as $event) {
        echo "Round {$event->getRound()->getNumber()}: {$event->getParticipants()[0]->getLabel()} vs {$event->getParticipants()[1]->getLabel()}\n";
    }
} catch (IncompleteScheduleException $e) {
    // Schedule validation caught an incomplete tournament
    echo "Cannot generate complete schedule: " . $e->getMessage() . "\n";
    
    // Get diagnostic information
    $violations = $e->getConstraintViolations();
    foreach ($violations as $violation) {
        echo "Violation: " . $violation->getDescription() . "\n";
    }
}
```

**Validation Features:**
- ✅ **Mathematical Validation**: Verifies expected vs actual event counts
- ✅ **Constraint Violation Tracking**: Detailed reporting of constraint conflicts  
- ✅ **Exception-Based Errors**: Clear exceptions instead of silent incomplete schedules
- ✅ **Diagnostic Reporting**: Actionable information for resolving constraint issues

### Scheduling Context

The library maintains historical context for constraint validation:

```php
// Context tracks previous events for constraint checking
$context = new SchedulingContext();
$context->addEvent($previousEvent);

// Constraints can access this history
$isValid = $constraints->isSatisfied($newEvent, $context);
```

## Available Algorithms

### ✅ Round Robin (Complete)
Perfect for leagues where every participant plays every other participant exactly once.

```php
$scheduler = new RoundRobinScheduler($constraints);
$schedule = $scheduler->schedule($participants);

// Handles edge cases:
// - 2 participants: 1 round, 1 match
// - 3 participants: 3 rounds, 3 matches (with byes)
// - 4+ participants: n-1 rounds using circle method
```

#### Multi-Leg Tournaments
Round Robin scheduler supports multi-leg tournaments where the same participants play multiple times with different arrangements:

```php
use MissionGaming\Tactician\LegStrategies\MirroredLegStrategy;
use MissionGaming\Tactician\LegStrategies\RepeatedLegStrategy;
use MissionGaming\Tactician\LegStrategies\ShuffledLegStrategy;

// Home and away legs (participant order reversed in second leg)
$scheduler = new RoundRobinScheduler(
    legs: 2,
    legStrategy: new MirroredLegStrategy()
);

// Repeated encounters (same pairings each leg)
$scheduler = new RoundRobinScheduler(
    legs: 3,
    legStrategy: new RepeatedLegStrategy()
);

// Randomized encounters (shuffled participant order each leg)
$scheduler = new RoundRobinScheduler(
    legs: 2,
    legStrategy: new ShuffledLegStrategy()
);

$schedule = $scheduler->schedule($participants);

// Multi-leg schedules provide additional metadata
echo "Total legs: " . $schedule->getMetadataValue('legs') . "\n";
echo "Rounds per leg: " . $schedule->getMetadataValue('rounds_per_leg') . "\n";
echo "Total rounds: " . $schedule->getMetadataValue('total_rounds') . "\n";
```

**Available Leg Strategies:**
- 🏠 **MirroredLegStrategy**: Reverses participant order for home/away effect
- 🔄 **RepeatedLegStrategy**: Maintains identical pairings across all legs  
- 🎲 **ShuffledLegStrategy**: Randomizes participant order in each pairing per leg

### 🔄 Swiss System (Coming Soon)
Ideal for tournaments where participants are paired based on performance.

### 🔄 Pool/Group Play (Coming Soon)
Perfect for group stages with standings and advancement rules.

## Documentation

📖 **[Contributing Guidelines](docs/CONTRIBUTING.md)** - Development setup and contribution process  
🏗️ **[Architecture](docs/ARCHITECTURE.md)** - Technical design and core components  
🛣️ **[Roadmap](docs/ROADMAP.md)** - Detailed development phases and use cases  
📚 **[Background](docs/BACKGROUND.md)** - Mission Gaming story and problem space details

## Sponsorship

<a href="https://www.tag1consulting.com" target="_blank">
  <img src="https://avatars.githubusercontent.com/u/386763?s=200&v=4" alt="Tag1 Consulting" width="200">
</a>

Initial development of this library was sponsored by **[Tag1 Consulting](https://www.tag1consulting.com)**, the absolute legends.  
[Tag1 blog](https://tag1.com/blog) & [Tag1TeamTalks Podcast](https://tag1.com/Tag1TeamTalks)

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.
