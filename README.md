# Tactician

A modern PHP library for generating structured tournament schedules with deterministic algorithms like Round Robin, Swiss, and Pool play.

[![PHP Version](https://img.shields.io/badge/php-%5E8.2-blue)](https://packagist.org/packages/mission-gaming/tactician)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)
[![Tests](https://img.shields.io/badge/tests-Pest-orange)](https://pestphp.com)

## Sponsorship

Initial development of this library was sponsored by **Tag1 Consulting**.

<a href="https://www.tag1consulting.com" target="_blank">
  <img src="https://avatars.githubusercontent.com/u/386763?s=200&v=4" alt="Tag1 Consulting" width="200">
</a>

**[Tag1 Consulting](https://www.tag1consulting.com)**
**<Tag1> Development & Infrastructure**
[Tag1 blog](https://tag1.com/blog) & [Tag1TeamTalks Podcast](https://tag1.com/Tag1TeamTalks)

## Overview

Tactician addresses the complexity of tournament scheduling in competitive gaming and sports. It provides reliable, deterministic algorithms to create fair, balanced schedules that follow sport-specific rules and constraints.

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
- PHP 8.2, 8.3, or 8.4
- No external dependencies in production

## Quick Start

```php
<?php

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Constraints\ConstraintSet;

// Create participants
$participants = [
    new Participant('Alice'),
    new Participant('Bob'),
    new Participant('Charlie'),
    new Participant('Diana'),
];

// Configure constraints
$constraints = ConstraintSet::create()
    ->withNoRepeatPairings();

// Generate schedule
$scheduler = new RoundRobinScheduler($constraints);
$schedule = $scheduler->schedule($participants);

// Iterate through matches
foreach ($schedule as $event) {
    echo "Round {$event->round}: ";
    echo "{$event->participants[0]->name} vs {$event->participants[1]->name}\n";
}
```

## Core Concepts

### Participants and Events

```php
// Create participants with unique identifiers
$player1 = new Participant('Alice', 'alice@example.com');
$player2 = new Participant('Bob', 'bob@example.com');

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
    ->withNoRepeatPairings()
    ->withCustom(fn($event, $context) => 
        // Custom constraint logic
        !$this->participantHasConflict($event->participants[0], $context)
    );

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
$isValid = $constraints->satisfiedBy($newEvent, $context);
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

## Development

### Setup

```bash
# Clone the repository
git clone git@github.com:mission-gaming/tactician.git
cd tactician

# Install dependencies
composer install
```

### Quality Assurance

```bash
# Run all quality checks
composer ci

# Individual commands
composer test              # Run tests
composer phpstan          # Static analysis
composer cs-fixer         # Code style check
composer rector           # Modernization check

# Fix issues automatically
composer cs-fixer-fix     # Fix code style
composer rector-fix       # Apply modernization
composer norm-fix         # Normalize composer.json
```

### Testing

```bash
# Run test suite
composer test

# Run with coverage
composer test-coverage

# Run specific tests
./vendor/bin/pest tests/Unit/RoundRobinSchedulerTest.php
```

## Architecture

### Core Components

- **DTOs**: Immutable value objects (`Participant`, `Event`, `Schedule`)
- **Schedulers**: Algorithm implementations (`RoundRobinScheduler`)
- **Constraints**: Flexible predicate system (`ConstraintSet`, `NoRepeatPairings`)
- **Context**: Historical state management (`SchedulingContext`)

### Design Principles

- **Immutability**: All DTOs are readonly and return new instances
- **Composability**: Constraints and schedulers combine flexibly
- **Performance**: Iterator pattern enables efficient memory usage
- **Determinism**: Seeded randomization produces reproducible results
- **Extensibility**: Clean interfaces for adding new algorithms

## Roadmap

### Current Status: Phase 1 Complete ✅
- Round-robin scheduler with circle method algorithm
- Comprehensive DTO system with modern PHP features
- Flexible constraint system with builder pattern
- Full test suite covering edge cases and mathematical correctness

### Phase 2: Additional Algorithms 🔄
- Swiss Tournament Scheduler implementation
- Pool/Group Scheduler with standings calculation
- Multi-stage tournament support

### Phase 3: Timeline Assignment 📅
- Time and venue assignment system
- Slot-based scheduling with patterns
- Blackout periods and availability constraints

### Phase 4: Advanced Features 🚀
- Schedule optimization algorithms
- Conflict resolution and quality metrics
- Integration examples with popular frameworks

## Use Cases

- **Gaming Tournaments**: Esports, board games, card games
- **Sports Leagues**: Round-robin leagues, swiss tournaments
- **Academic Competitions**: Debate tournaments, quiz bowls
- **Corporate Events**: Team building competitions, hackathons

## Performance

Tactician is designed for tournaments up to ~50 participants with efficient memory usage through:
- Generator-based iteration
- Lazy evaluation of constraints
- Immutable data structures preventing memory leaks

## Contributing

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/amazing-feature`)
3. Ensure tests pass (`composer ci`)
4. Commit your changes (`git commit -m 'Add amazing feature'`)
5. Push to the branch (`git push origin feature/amazing-feature`)
6. Open a Pull Request

### Development Guidelines

- Follow PSR-12 coding standards
- Write tests for all new features
- Update documentation for API changes
- Ensure PHPStan level 9 compliance
- Use meaningful commit messages

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Author

**Jamie Hollern** - [jamie@missiongaming.com](mailto:jamie@missiongaming.com)

---

**Mission Gaming** © 2025
