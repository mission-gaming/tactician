# Tactician

[![PHP Version](https://img.shields.io/badge/php-%5E8.3-blue)](https://packagist.org/packages/mission-gaming/tactician)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)
[![Build Status](https://github.com/mission-gaming/tactician/actions/workflows/ci.yml/badge.svg)](https://github.com/mission-gaming/tactician/actions/workflows/ci.yml)
[![codecov](https://codecov.io/github/mission-gaming/tactician/graph/badge.svg?token=B5QQBW434A)](https://codecov.io/github/mission-gaming/tactician)

## Overview

A PHP library that generates tournament schedules: who meets whom, in which
round, and, when you ask for it, at what time. It covers round robin, Swiss,
pot draws, single and double elimination and group stages, and it repairs a schedule that
has fallen behind. It has no production dependencies.

## Features

Each feature links to its section of the [usage guide](docs/USAGE.md), except
the last one, which is about the library as a whole.

**Formats**

- **Round robin**, single or multi-leg, by the circle method. Roles (first-named
  and second-named, read as home and away) alternate by round; the split is
  bounded, not equal. An opt-in balanced role assignment makes the split of a
  leg as even as the field allows. Legs after the first are mirrored, repeated
  or shuffled, and an odd field gets a bye in every round.
  [Basic usage](docs/USAGE.md#basic-usage),
  [multi-leg tournaments](docs/USAGE.md#multi-leg-tournaments),
  [role assignment](docs/USAGE.md#role-assignment)
- **Swiss**: each round is paired from the standings so far (Monrad pairing),
  avoiding repeat pairings, rotating byes, balancing roles, and allowing
  withdrawals. [Swiss tournaments](docs/USAGE.md#swiss-tournaments)
- **Pot draw**: the entrants are cut into pots of equal size in seeding order,
  and every entrant meets a fixed number of opponents from every pot, its own
  included, with no rematch and balanced roles. Every round is drawn up front
  from a seed. This is the league-phase format that is often called "Swiss";
  nothing in it is paired from results. [Pot draws](docs/USAGE.md#pot-draws)
- **Single and double elimination**: fold seeding by list position, byes, round
  labels, one- or two-legged ties; re-seeding each round for single
  elimination, and an optional grand-final reset for double elimination. An
  event or a tie that finishes level is decided by naming who advances.
  [Elimination brackets](docs/USAGE.md#elimination-brackets),
  [recording a level event](docs/USAGE.md#recording-a-level-event)
- **Group stages and multi-stage tournaments**, composed from serpentine pools
  and progression selectors. A declared chain of stages can be checked for
  matching entrant counts before any event exists. [Pools, progression, and multi-stage tournaments](docs/USAGE.md#pools-progression-and-multi-stage-tournaments)

**Rules and quality**

- **Constraints**: seed protection, a minimum number of rounds between repeat
  meetings, limits on consecutive roles, role balance, rules over participant
  metadata, and your own predicates. Constraints are hard filters.
  [Constraint system](docs/USAGE.md#constraint-system)
- **Loud failure**: a round robin that cannot be completed under its
  constraints throws, with a report that names the constraint and the pairings
  it blocks; a partial schedule is never returned. The scheduler first retries
  a bounded number of rotated participant orders, and an opt-in backtracking
  search covers the round layouts those cannot reach. Every exception the
  library throws on purpose implements one interface, and a configuration
  error carries a reason that code can match without reading the message.
  [Backtracking generation](docs/USAGE.md#backtracking-generation),
  [error handling](docs/USAGE.md#error-handling),
  [configuration errors](docs/USAGE.md#configuration-errors),
  [catching every library exception](docs/USAGE.md#catching-every-library-exception)
- **Schedule quality**: lower-is-better metrics, a weighted scorer, and an
  optimizer that keeps the best of N seeded samples.
  [Schedule quality and optimization](docs/USAGE.md#schedule-quality-and-optimization)

**Results, time and repair**

- **Results and standings**: a ranking strategy (3/1/0 and 1/½/0 presets), then
  a chain of tiebreakers (wins, Buchholz, Sonneborn–Berger). The table gives
  every entry its own position and reports the sets of entries that no result
  separates.
  [Results and standings](docs/USAGE.md#results-and-standings),
  [tied sets](docs/USAGE.md#tied-sets)
- **Stage state between rounds**: the state of a results-driven stage is
  plain data that an application stores between rounds. A recorded result of
  the last round can be replaced, and a state can be stamped with the
  fingerprint of the engine that pairs it, so that another engine or another
  configuration refuses it.
  [Correcting a recorded result](docs/USAGE.md#correcting-a-recorded-result),
  [recording which engine pairs a state](docs/USAGE.md#recording-which-engine-pairs-a-state)
- **Timeline assignment**: kickoff times and named resources for every event
  from a declarative slot pattern, with minimum-rest and blackout rules.
  Kickoffs are emitted in UTC.
  [Timeline assignment](docs/USAGE.md#timeline-assignment)
- **Schedule repacking**: existing events, some of them pinned, placed onto an
  irregular grid of sessions so that nobody is double-booked and each
  participant plays back to back within a session. What cannot be satisfied
  comes back as itemised violations instead of an exception. A grid can be
  given without times and with no limit on the events in a slot, and an
  outcome has a fingerprint that tells whether a plan computed again is the
  plan that was shown.
  [Schedule repacking](docs/USAGE.md#schedule-repacking),
  [grids without instants](docs/USAGE.md#grids-without-instants),
  [unbounded capacity](docs/USAGE.md#unbounded-capacity),
  [outcome fingerprints](docs/USAGE.md#outcome-fingerprints)

**Foundations**

- **Deterministic**: the library never reads the clock and uses no global
  random function. Randomness comes from a `Random\Randomizer` you pass in, and
  a freshly seeded one gives the same output for the same input
  (`ShuffledLegStrategy` creates an unseeded one when you give it none). A
  scheduler that holds a randomizer draws from it on every call, so its second
  call continues the sequence and returns a different schedule. A pot draw
  takes an integer seed in its options and builds its own seeded one.
  [Deterministic randomization](docs/USAGE.md#deterministic-randomization)
- **Serialization**: `Schedule`, `StageState` and `ScheduledSchedule`
  round-trip JSON, and the value objects convert to and from arrays.
  [Serialization](docs/USAGE.md#serialization)
- **Schedules are plain collections**: a schedule holds its events in memory
  and is iterable, countable, and groupable by round.
  [Iterating and counting](docs/USAGE.md#iterating-and-counting)
- **PHP 8.3+, strictly typed**: `strict_types` throughout, immutable value
  objects, and `src/` held to PHPStan level 9 with zero errors by the build. The library is at 0.x: see
  [Versioning and stability](#versioning-and-stability) for which namespaces
  are stable.

Runnable examples of most of the above are in [`examples/`](examples/README.md);
the usage guide describes the rest (role constraints, Swiss withdrawals,
two-legged ties and re-seeding).

## Installation

Install via Composer:

```bash
composer require mission-gaming/tactician
```

**Requirements:**
- PHP 8.3+
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
pairing, elimination brackets, and multi-stage tournaments. In the loop below,
`$participants` is the list from the Quick Start and `playRound()` stands for
your application playing a round and returning one `Result` per event:

<!-- snippet: setup
$participants = array_map(
    static fn (int $seed) => new \MissionGaming\Tactician\DTO\Participant("p{$seed}", "Participant {$seed}", $seed),
    range(1, 6)
);

/** @return list<\MissionGaming\Tactician\DTO\Result> */
function playRound(\MissionGaming\Tactician\Stage\RoundPairing $pairing): array
{
    return array_map(
        static fn (\MissionGaming\Tactician\DTO\Event $event) => new \MissionGaming\Tactician\DTO\Result($event, $event->getParticipants()[0]),
        $pairing->getEvents()
    );
}
-->

```php
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Scheduling\SwissPairingEngine;
use MissionGaming\Tactician\Stage\StageState;

$engine = new SwissPairingEngine(plannedRounds: 5);

// One driver loop covers every results-driven format
$state = StageState::start($participants);
while (!$engine->isComplete($state)) {
    $pairing = $engine->pairNextRound($state);
    $results = playRound($pairing); // application-side
    $state = $state->withRoundPlayed($pairing, $results);
}

// Every format finishes as an outcome you can select from
$outcome = $engine->getOutcome($state);
$table = $outcome->getStandings();
```

Single and double elimination (`SingleEliminationEngine`,
`DoubleEliminationEngine`) drive through the same loop, and group stages
compose from pools and progression selectors (`PoolDistributor`,
`RankRangeSelector`, `MatchOutcomeSelector`) — see the
[usage guide](docs/USAGE.md). Stage state and schedules serialize to JSON
(`StageState::toJson()`, `$schedule->toJson()`), so platforms persist
between rounds instead of re-deriving.

## Versioning and stability

Tactician follows [Semantic Versioning](https://semver.org/) with the 0.x
allowance: until `1.0.0`, a minor release may contain breaking changes. Every
release is recorded in the [changelog](CHANGELOG.md).

- **Patch releases (0.2.x)** never change a correct output for a fixed input
  and seed, and never change a public signature.
- **A patch may change an output that was itself broken**: one that reported
  its own failure, stated something false, or contradicted the documented
  contract of the component that produced it. The changelog marks every such
  case with the heading "Output change (fix)".
- **Minor releases (0.x)** may contain breaking changes. The changelog lists
  each one with a migration note.
- **Deprecations precede removals.** A deprecated method keeps working until
  `1.0.0`. The usage guide lists each one with what to use instead, under
  [Deprecations](docs/USAGE.md#deprecations). Any release may deprecate a
  method, a patch release included. That changes no output and no signature,
  but from PHP 8.4 a call to a deprecated method emits an `E_USER_DEPRECATED`
  notice, so an application that turns notices into exceptions can see such
  a call fail after a patch update. The library never calls a deprecated
  method itself.
- **Generated output is pinned.** Golden fixtures in
  [`tests/Fixtures/golden/`](tests/Fixtures/golden/) pin generated schedules,
  bracket pairings, repack assignments, and the JSON wire shapes for a set of
  fixed inputs and seeds, so a change to that output fails the test suite
  instead of shipping unnoticed. The fixtures cover those cases only; they are
  not a proof about every input.

**Supported PHP versions:** `^8.3` (the Composer constraint). CI runs the suite
on PHP 8.3, 8.4, and 8.5.

The rules above apply to every namespace. The two lists below say where
breaking changes are expected. Namespaces are relative to
`MissionGaming\Tactician`.

**Stable** (the API is settled and no breaking change is planned):

- `DTO`
- Round-robin scheduling: `Scheduling\RoundRobinScheduler`,
  `Scheduling\RoundRobinOptions`, and the leg strategies in `LegStrategies`
- `Repack`, excluding `Repack\Internal`
- `Exceptions`

**Experimental** (still being designed; expected to change in a minor release
before `1.0.0`):

- The Swiss and elimination engines: `Scheduling\SwissPairingEngine`,
  `Scheduling\SwissScheduler`, `Scheduling\SwissOptions`,
  `Scheduling\SingleEliminationEngine`, `Scheduling\DoubleEliminationEngine`,
  and `Scheduling\EliminationOptions`
- The pot draw: `Scheduling\PotDrawScheduler`, `Scheduling\PotDrawOptions`,
  and `Stage\PotDrawPlan`
- `Stage`
- `Standings`
- `Timeline`
- `Quality`
- `Constraints`
- `Validation`
- `Diagnostics`
- `RoleAssignment`

Anything not listed as stable is experimental, including the rest of
`Scheduling`. `Repack\Internal` is internal: it is not public API and carries
no compatibility guarantee. Neither does a class in any other namespace whose
docblock is marked `@internal` (`Stage\PairKey` and `Timeline\DateTimeString`,
for example).

Every class, interface, trait and enum in the library states its own status in
its docblock, with exactly one of three annotations:

- `@api`: stable surface. An entry of the stable list covers it: its
  namespace, or the class by name.
- `@experimental`: public, and expected to change in a minor release before
  `1.0.0`. It is everything else that is public: what an entry of the
  experimental list covers, and the rest of `Scheduling`.
- `@internal`: not public API. Do not call it, extend it or type against it;
  it can change or disappear in any release.

An architecture test (`tests/Feature/StabilityAnnotationsTest.php`) holds the
annotations to the two lists above. It fails when a type has no annotation or
more than one, when a type under a stable entry is `@experimental` or a type
under an experimental entry is `@api`, and when a namespace is in neither
list. A type under a stable entry cannot be `@internal` either, unless the
stable list excludes it, as it does `Repack\Internal`. An experimental type
can be, and the test pins which ones are.

Some stable signatures carry experimental types. For example,
`RoundRobinScheduler` accepts a `Constraints\ConstraintSet` and returns a
`Stage\RoundRobinPlan` from `getPlan()`, `RoundRobinOptions` carries a
`RoleAssignment\RoleAssignmentInterface`, the leg strategies receive a
`Constraints\ConstraintSet` and a `Scheduling\SchedulingContext`, and
`Exceptions\IncompleteScheduleException` exposes `Stage`, `Validation`, and
`Diagnostics` types. Those types are experimental, so the parts of a stable
class that use them can change with them. The same goes for the two
interfaces the stable round-robin classes implement,
`Scheduling\SchedulerInterface` and `Scheduling\SchedulerOptions`: what
`RoundRobinScheduler` and `RoundRobinOptions` offer is stable, and the
interfaces, as types to implement or to type against, are experimental.

No public signature hands out or asks for an `@internal` type. A public
method that a class takes from an `@internal` trait belongs to that class and
has its status: `getViolationCollector()` on a scheduler is one. The
protected members such a trait supplies are internal, like the trait: a
subclass of a scheduler must not rely on them.

Releases are cut with the [release checklist](docs/RELEASING.md).

## Documentation

📝 **[Changelog](CHANGELOG.md)** - Release history and output changes  
📚 **[Complete Usage Guide](docs/USAGE.md)** - Comprehensive examples and patterns  
🧩 **[Framework Integration](docs/integrations/symfony.md)** - The adapter an application writes around Tactician, with [Symfony](docs/integrations/symfony.md) and [Laravel](docs/integrations/laravel.md) code  
🏗️ **[Architecture](docs/ARCHITECTURE.md)** - Technical design and core components  
🛣️ **[Roadmap](docs/ROADMAP.md)** - What has shipped, known limitations and deferred work  
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
