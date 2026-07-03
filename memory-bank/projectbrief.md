# Project Brief: Tactician

## Project Overview
*This is the foundation document for the Tactician project. All other memory bank files build upon this brief.*

Tactician is a modern PHP library for generating structured schedules between participants. It provides deterministic tournament formats — round robin (single and multi-leg), Swiss pairing, single and double elimination, and group stages — plus results, standings with pluggable tiebreakers, and a flexible constraint system for rules like "no repeat pairings" or "protect top seeds from early meetings". Fixture generation is kept separate from time assignment (timeline assignment is future work). Designed for PHP 8.3+, it is dependency-free in production, Composer-compatible, and built test-first.

## Core Requirements
- PHP library, Composer-compatible, targeting PHP 8.3, 8.4, and 8.5
- Deterministic scheduling: same input always produces the same schedule (seeded randomness where randomization is wanted)
- Whole-schedule generators for formats that can be precomputed; results-driven engines for formats whose later rounds depend on outcomes (Swiss, brackets, group qualification)
- Constraints via a flexible predicate system with loud, diagnostic failure (never silently incomplete schedules)
- Test-driven development using Pest, PHPStan level 8, Rector, PHP-CS-Fixer
- Licensed under MIT, open source, with GitHub Actions CI

## Goals
- Deliver a modern, extensible scheduling engine usable in PHP projects
- Clean separation between pairing (who vs who) and timeline (when/where)
- High developer ergonomics: fluent APIs, runnable (and tested) examples, executable documentation
- Performance suitable for competitions into the hundreds of participants
- Backwards compatibility is not a consideration currently — breaking changes are acceptable (unreleased, no tags)

## Scope
- In scope (shipped): core DTOs (Participant, Event, Round, Schedule, Result), ConstraintSet with built-in and custom predicates, RoundRobinScheduler, SimpleSwissScheduler, SwissPairingEngine, Single/DoubleEliminationEngine, GroupStageEngine, standings/tiebreakers, JSON serialization, examples, CI, documentation
- In scope (next): algorithm-neutral core abstraction (`ExpectedSchedule`/`AlgorithmPlan`) — see docs/ROADMAP.md Phase 3
- Out of scope for now: timeline/venue assignment (Phase 4), external solver integrations, venue routing, iCal recurrence, generalized k-participant matching

## Misc
- **CRITICAL**: Date formats in the memory bank files must ALWAYS follow the ISO 8601 format (YYYY-MM-DD).
- Project conventions for agents live in AGENTS.md (CLAUDE.md symlinks to it).

## Status
- **Project Stage**: Feature-complete through Roadmap Phase 2 (all major tournament formats shipped)
- **Created**: 2025-09-10
- **Last Updated**: 2026-07-03
