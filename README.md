# Tactician

[![PHP Version](https://img.shields.io/badge/php-%5E8.2-blue)](https://packagist.org/packages/mission-gaming/tactician)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)
[![Build Status](https://github.com/mission-gaming/tactician/actions/workflows/ci.yml/badge.svg)](https://github.com/mission-gaming/tactician/actions/workflows/ci.yml)
[![codecov](https://codecov.io/github/mission-gaming/tactician/graph/badge.svg?token=B5QQBW434A)](https://codecov.io/github/mission-gaming/tactician)

## Overview

A modern PHP library for generating structured schedules between participants. Ideal for tournaments (round robin, Swiss, pools) but flexible enough for any scenario where entities need to be paired or grouped into events.

**Key Features:**

- 🏆 **Tournament Formats**: Round robin (single & multi-leg), Swiss pairing, single & double elimination, group stages
- 📊 **Results & Standings**: Points systems, league tables, and pluggable tiebreakers (wins, Buchholz, Sonneborn–Berger)
- 🔧 **Flexible Constraints**: Built-in and custom predicate-based constraint system
- ✅ **Schedule Validation**: Comprehensive validation prevents incomplete schedules
- 💾 **Serialization**: JSON round-tripping for schedules and participants
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
use MissionGaming\Tactician\Constraints\SeedProtectionConstraint;

// Create seeded participants
$participants = [
    new Participant('celtic', 'Celtic', 1),        // Top seed
    new Participant('athletic', 'Athletic Bilbao', 2),  // 2nd seed  
    new Participant('livorno', 'AS Livorno', 3),
    new Participant('redstar', 'Red Star FC', 4),
    new Participant('rayo', 'Rayo Vallecano', 5),
    new Participant('clapton', 'Clapton Community FC', 6),
];

// Configure constraints to protect top seeds from early meetings
$constraints = ConstraintSet::create()
    ->add(new SeedProtectionConstraint(2, 0.5))  // Protect top 2 seeds for 50% of tournament
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

## Beyond Round Robin

Results feed standings, and standings drive the incremental engines for Swiss
pairing, elimination brackets, and multi-stage tournaments:

```php
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Scheduling\SwissPairingEngine;
use MissionGaming\Tactician\Standings\StandingsCalculator;

$engine = new SwissPairingEngine();
$round1 = $engine->pairNextRound($participants, []);

// Record results as play completes...
$results = [new Result($round1->getEvents()[0], $winner) /* ... */];

// ...then pair the next round from the live standings
$round2 = $engine->pairNextRound($participants, $results);

// League table at any point
$standings = (new StandingsCalculator())->calculate($participants, $results);
```

Single and double elimination (`SingleEliminationEngine`,
`DoubleEliminationEngine`) and group stages feeding seeded knockouts
(`GroupStageEngine`) follow the same results-driven pattern. Schedules
serialize to JSON via `$schedule->toJson()` / `Schedule::fromJson()`.

## Key Features

- **🏆 Round Robin Tournaments**: Circle method algorithm with balanced home/away roles
- **♟️ Swiss Pairing**: Standings-aware Monrad pairing with repeat avoidance, bye rotation, home/away balancing, and withdrawal handling
- **🥊 Elimination Brackets**: Single and double elimination with fold seeding, byes, stage names, and optional grand-final reset
- **🏟️ Group Stages**: Serpentine-seeded groups with per-group standings and cross-group knockout qualification
- **📊 Results & Standings**: Configurable points systems with wins, Buchholz, and Sonneborn–Berger tiebreakers
- **🔧 Flexible Constraints**: Built-in constraints (rest periods, seed protection, role limits, role balance, metadata rules) plus custom predicates
- **🏠 Multi-Leg Support**: Home/away leagues with mirrored, repeated, or shuffled strategies and first-class byes
- **✅ Schedule Validation**: Mathematical validation prevents incomplete tournaments, with automatic retries over alternative orderings when constraints reject a schedule
- **💾 Serialization**: JSON round-tripping for schedules, events, and participants
- **🛡️ Production Ready**: PHPStan level 8 compliance, comprehensive test coverage
- **⚡ Memory Efficient**: Iterator-based patterns for large tournaments
- **🎯 Deterministic**: Seeded randomization for reproducible results

## Documentation

📚 **[Complete Usage Guide](docs/USAGE.md)** - Comprehensive examples and patterns  
🏗️ **[Architecture](docs/ARCHITECTURE.md)** - Technical design and core components  
🛣️ **[Roadmap](docs/ROADMAP.md)** - Detailed development phases and use cases  
📖 **[Contributing Guidelines](docs/CONTRIBUTING.md)** - Development setup and contribution process  
📚 **[Background](docs/BACKGROUND.md)** - Mission Gaming story and problem space details

## Sponsorship

<a href="https://www.tag1consulting.com" target="_blank">
  <img src="https://avatars.githubusercontent.com/u/386763?s=200&v=4" alt="Tag1 Consulting" width="200">
</a>

Initial development of this library was sponsored by **[Tag1 Consulting](https://www.tag1consulting.com)**, the absolute legends.  
[Tag1 blog](https://tag1.com/blog) & [Tag1TeamTalks Podcast](https://tag1.com/Tag1TeamTalks)

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.
