# Project Brief: Tactician

## Project Overview
*This is the foundation document for the Tactician project. All other memory bank files build upon this brief.*

Tactician is a modern PHP library for generating structured schedules between participants. It provides deterministic algorithms such as Round Robin, Swiss, and Pool play to decide who is paired with whom, along with a flexible constraint system for rules like “no repeat pairings” or “participants should not be scheduled at the same time.” Fixture generation is kept separate from time assignment, allowing events to be mapped onto dates and slots using simple timeline patterns. Designed for PHP 8.2+, it is lightweight, Composer-compatible, and built with test-driven development to ensure reliability and extensibility.


## Core Requirements
- PHP library, Composer-compatible, targeting PHP 8.2, 8.3, and 8.4
- Provides deterministic tournament scheduling algorithms (Round Robin, Swiss, Pools)
- Supports constraints via a flexible predicate DSL (hard boolean, soft scoring)
- Generates schedules as iterables/generators for efficiency
- Test-driven development using Pest, PHPStan, Rector, PHP-CS-Fixer
- Licensed under MIT, open source, with GitHub Actions CI

## Goals
- Deliver a modern, extensible scheduling engine usable in PHP projects projects
- Ensure deterministic, reproducible fixture generation with seeded randomness
- Provide a clean separation between pairing (who vs who) and timeline (when/where)
- Offer high developer ergonomics: fluent APIs, runnable examples, strong documentation
- Keep performance suitable for competitions up to ~50 participants

## Scope
- In-scope:
  - Core DTOs: Participant, Event, Schedule, MatchContext
  - ConstraintSet with built-in and custom predicates
  - Scheduling algorithms: CircleScheduler (Round Robin), SwissScheduler, PoolScheduler
  - Timeline assignment: PatternTimeline and TimeAssigner for slot-based scheduling
  - Examples, CI, and documentation
- Out of scope (for v1, may come later):
  - External solver integrations (CP-SAT, MILP)
  - Venue routing and advanced travel minimization
  - Blackout dates, iCal RRULEs, or complex recurrence handling
  - Full Social Golfer Problem/generalized k-participant match solving

## Status
- **Project Stage**: Initial setup
- **Created**: 2025-09-10
- **Last Updated**: 2025-09-10

## Notes
This project brief will be expanded as the project requirements become clear through development and user input.
