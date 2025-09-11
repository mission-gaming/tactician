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
- ⚡ **Memory Efficient**: Generator-based iteration for large tournaments
- 🎯 **Modern PHP**: PHP 8.2+ with readonly classes and strict typing
- 🧪 **Test-Driven**: Comprehensive test suite with Pest framework
- 📐 **Mathematical Accuracy**: Circle method implementation for round-robin

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
    echo "Round {$event->getRound()}: ";
    echo "{$event->getParticipants()[0]->getLabel()} vs {$event->getParticipants()[1]->getLabel()}\n";
}
```

## Core Concepts

### Participants and Events

```php
// Create participants with unique identifiers
$player1 = new Participant('celtic', 'Celtic', 1, ['city' => 'Glasgow']);
$player2 = new Participant('athletic', 'Athletic Bilbao', 2, ['city' => 'Bilbao']);

// Events represent matches/games between participants
$event = new Event(
    participants: [$player1, $player2],
    round: 1,
    metadata: ['court' => 'A', 'time' => '10:00']
);
```

### Constraint System

```php
// Built-in constraints
$constraints = ConstraintSet::create()
    ->noRepeatPairings()
    ->custom(fn($event, $context) => 
        // Custom constraint logic
        !$this->participantHasConflict($event->getParticipants()[0], $context)
    )
    ->build();

// Use constraints in scheduler
$scheduler = new RoundRobinScheduler($constraints);
```

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

### 🔄 Swiss System (Coming Soon)
Ideal for tournaments where participants are paired based on performance.

### 🔄 Pool/Group Play (Coming Soon)
Perfect for group stages with standings and advancement rules.

## Documentation

📖 **[Contributing Guidelines](docs/CONTRIBUTING.md)** - Development setup and contribution process  
🏗️ **[Architecture](docs/ARCHITECTURE.md)** - Technical design and core components  
🛣️ **[Roadmap](docs/ROADMAP.md)** - Detailed development phases and use cases  
📚 **[Background](docs/BACKGROUND.md)** - Mission Gaming story and problem space details

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.
