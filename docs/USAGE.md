# Usage Guide

This comprehensive guide covers all aspects of using Tactician for tournament scheduling, from basic round-robin tournaments to complex multi-leg scenarios with advanced constraints.

## Table of Contents

- [Terminology](#terminology)
- [Basic Usage](#basic-usage)
- [Participants and Events](#participants-and-events)
- [Constraint System](#constraint-system)
- [Multi-Leg Tournaments](#multi-leg-tournaments)
- [Results and Standings](#results-and-standings)
- [Swiss Tournaments](#swiss-tournaments)
- [Elimination Brackets](#elimination-brackets)
- [Group Stages and Multi-Stage Tournaments](#group-stages-and-multi-stage-tournaments)
- [Serialization](#serialization)
- [Schedule Validation](#schedule-validation)
- [Error Handling](#error-handling)
- [Advanced Patterns](#advanced-patterns)
- [Real-World Examples](#real-world-examples)

## Terminology

Tactician uses these terms consistently across the API, documentation, and
diagnostics. Note that the library deliberately says **participant** rather
than "team" — participants can be players, clubs, squads, debate teams, or
anything else that competes.

| Term | Meaning |
|------|---------|
| **Participant** | An entity that competes. Identified by a unique string ID, with a display label, optional seed, and metadata. |
| **Event** | A single match/fixture between participants (usually two). Metronome-style platforms would call this a fixture. |
| **Pairing** | The unordered combination of participants in an event — "Alice vs Bob" regardless of who is home. |
| **Round** | A set of events played at the same stage of the tournament. Round numbers are 1-based and continuous across legs (a two-leg, 4-participant round robin has rounds 1–6). |
| **Leg** | One complete cycle of pairings. The leg count is *the number of times each participant meets each other participant*: a home-and-away league is 2 legs. Swiss and elimination formats have no legs concept. |
| **Home/Away roles** | The participant order within an event: the first participant is home, the second away. The round-robin generator alternates roles with round parity to keep them balanced. |
| **Bye** | A participant sitting out a round (odd participant counts). Byes are never emitted as events — round robin records them in the `byes` schedule metadata, and the Swiss/elimination engines report them on the round pairing. |
| **Seed** | A participant's ranking, used for bracket placement, serpentine group distribution, and seed-protection constraints. Lower numbers are better; 1 is the top seed. |
| **Schedule** | The complete, validated collection of generated events plus metadata. |
| **Result** | The recorded outcome of a played event: a winner or a draw, with optional per-participant scores. |
| **Standings** | The ordered table computed from results by `StandingsCalculator` — ranking values, records, and tiebreakers. |
| **Ranking strategy** | The pluggable rule ordering a standings table (`RankingStrategy`): it computes each participant's primary ranking value from their results, higher is better. `WinDrawLossRanking` (points from wins/draws/losses) is the built-in implementation; placement- or score-aggregating strategies slot in without touching the calculator. |
| **Constraint** | A hard rule evaluated during generation (rest periods, seed protection, role limits...). Constraints either hold or generation fails loudly with diagnostics — there are no soft preferences. |
| **Stage** | One phase of a multi-stage tournament (e.g. a group stage feeding a knockout) — Tactician's unit of work: participants in, a schedule or round-by-round pairings out. Composed via `GroupStageEngine` qualifiers and the elimination engines. |
| **Options** | The typed per-algorithm configuration object a scheduler accepts (`RoundRobinOptions`, `SwissOptions`): legs mean legs, rounds mean rounds, and passing another algorithm's options fails loudly. All options are plain-data constructible (`fromArray()`/`toArray()`) with stable identifiers for config-driven platforms. |
| **Stage plan** | An algorithm's declaration of a stage's shape (`StagePlan`): stable algorithm identifier, total rounds, legs, rounds per leg, and expected event count, plus format-specific integrity validation. Built before generation; context, validation, diagnostics, and constraints read shape facts from it instead of inferring them. Null values are meaningful — legs are null where the concept does not apply (Swiss), totals are null when unknowable up front. |

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
    ->noRepeatPairings()  // Prevent duplicate pairings within a leg
    ->add(new MinimumRestPeriodsConstraint(2))  // 2 rounds minimum between meetings
    ->add(new SeedProtectionConstraint(2, 0.4))  // Protect top 2 seeds for 40% of tournament
    ->add(ConsecutiveRoleConstraint::homeAway(3))  // Max 3 consecutive home/away games
    ->add(MetadataConstraint::requireSameValue('division'))  // Only pair within same division
    ->build();
```

> **Note:** `noRepeatPairings()` is scoped to the current leg by default, because
> multi-leg tournaments intentionally repeat every pairing once per leg. Pass
> `noRepeatPairings(acrossLegs: true)` to forbid repeats anywhere in the
> tournament — which by design makes complete multi-leg round robins
> impossible, so use it only as a hard invariant check. Also note that a
> single-leg round robin never repeats a pairing by construction, so the
> constraint is only load-bearing for generators without that structural
> guarantee.

### Metadata-Based Constraints

```php
// Participants from the same region only
$sameRegion = MetadataConstraint::requireSameValue('region');

// Participants from different skill levels
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
            
            // Don't allow the same participant to play more than twice per day
            $firstParticipantEvents = $context->getEventsForParticipant($participants[0]);
            $secondParticipantEvents = $context->getEventsForParticipant($participants[1]);
            
            return count($firstParticipantEvents) < 3 && count($secondParticipantEvents) < 3;
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

// Keep home/away totals within 3 of each other as the schedule builds.
// The round-robin generator alternates roles with round parity, so limits
// of 3 (even fields) or 4 (odd fields) are always satisfiable.
$balanceConstraint = RoleBalanceConstraint::homeAway(3);

// Use in scheduler
$constraints = ConstraintSet::create()
    ->add($homeAwayConstraint)
    ->add($positionConstraint)
    ->add($balanceConstraint)
    ->build();
```

## Multi-Leg Tournaments

Multi-leg tournaments allow the same participants to play multiple times with different arrangements, perfect for home/away leagues or repeated encounters.

### Available Leg Strategies

```php
use MissionGaming\Tactician\LegStrategies\MirroredLegStrategy;
use MissionGaming\Tactician\LegStrategies\RepeatedLegStrategy;
use MissionGaming\Tactician\LegStrategies\ShuffledLegStrategy;
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
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
    $participants,
    new RoundRobinOptions(legs: 2, strategy: new MirroredLegStrategy())
);

// Repeated encounters (same pairings each leg)
$repeatedSchedule = $scheduler->schedule(
    $participants,
    new RoundRobinOptions(legs: 3, strategy: new RepeatedLegStrategy())
);

// Randomized encounters (shuffled participant order each leg)
$shuffledSchedule = $scheduler->schedule(
    $participants,
    new RoundRobinOptions(legs: 2, strategy: new ShuffledLegStrategy())
);
```

Options are plain-data constructible for config-driven platforms, with the
stable strategy identifiers `mirrored`, `repeated`, and `shuffled`:

```php
$options = RoundRobinOptions::fromArray(['legs' => 2, 'strategy' => 'mirrored']);
$options->toArray(); // ['legs' => 2, 'strategy' => 'mirrored']
```

### Multi-Leg Schedule Analysis

```php
// Multi-leg schedules provide additional metadata
echo "Total legs: " . $mirroredSchedule->getMetadataValue('legs') . "\n";
echo "Rounds per leg: " . $mirroredSchedule->getMetadataValue('rounds_per_leg') . "\n";
echo "Total rounds: " . $mirroredSchedule->getMetadataValue('total_rounds') . "\n";

// Process the schedule round by round - the natural shape for assigning
// dates per round or rendering matchday views
$roundsPerLeg = $mirroredSchedule->getMetadataValue('rounds_per_leg');
foreach ($mirroredSchedule->getEventsByRound() as $roundNumber => $events) {
    $leg = (int) ceil($roundNumber / $roundsPerLeg);
    echo "Leg {$leg}, Round {$roundNumber}:\n";

    foreach ($events as $event) {
        $participants = $event->getParticipants();
        echo "  {$participants[0]->getLabel()} vs {$participants[1]->getLabel()}\n";
    }
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
    $participants,
    new RoundRobinOptions(legs: 2, strategy: new MirroredLegStrategy())
);
```

## Results and Standings

Record outcomes with `Result` and build league tables with `StandingsCalculator`:

```php
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Standings\BuchholzTiebreaker;
use MissionGaming\Tactician\Standings\StandingsCalculator;
use MissionGaming\Tactician\Standings\WinDrawLossRanking;
use MissionGaming\Tactician\Standings\WinsTiebreaker;

// A win, a draw (no winner), and a scored win
$results = [
    new Result($eventOne, $alice),
    new Result($eventTwo),
    new Result($eventThree, $carol, ['carol' => 3, 'dave' => 1]),
];

// The 3/1/0 convention with tiebreakers applied in order
$calculator = new StandingsCalculator(
    WinDrawLossRanking::threeOneZero(),
    [new WinsTiebreaker(), new BuchholzTiebreaker()]
);
$standings = $calculator->calculate($participants, $results);

foreach ($standings as $entry) {
    $position = $standings->getPosition($entry->getParticipant());
    echo "{$position}. {$entry->getParticipant()->getLabel()}: "
        . "{$entry->getRankingValue()} pts "
        . "({$entry->getWins()}W {$entry->getDraws()}D {$entry->getLosses()}L)\n";
}
```

The table is ordered by a pluggable **ranking strategy**: "points from
wins, draws, and losses" is one way to order a table, not the definition
of ordering, so `StandingsCalculator` takes a `RankingStrategy` and each
entry exposes the strategy-computed value via `getRankingValue()` alongside
its W/D/L record. `WinDrawLossRanking` is the built-in implementation, with
the sport conventions as named constructors — `threeOneZero()`
(association football) and `oneHalfZero()` (chess) — and plain-data
construction via `WinDrawLossRanking::fromArray(['win' => 3, 'draw' => 1,
'loss' => 0])` for config-driven platforms. `SonnebornBergerTiebreaker` is
also available. Ties beyond the configured tiebreakers fall back to score
difference, score for, seed, and natural-order label comparison. Each event
may have at most one result; recording two results for the same event throws.

## Swiss Tournaments

`SwissPairingEngine` pairs one round at a time from recorded results:
participants are ordered by standings and paired adjacently (Monrad style),
backtracking past repeat pairings. Byes rotate to the lowest-placed
participant with the fewest so far and are credited as wins when ordering
the next round.

```php
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Scheduling\SwissPairingEngine;

// plannedRounds lets length-aware constraints (e.g. seed protection) size
// their windows correctly
$engine = new SwissPairingEngine(plannedRounds: 5);

$results = [];
$byeIds = [];
for ($round = 1; $round <= 5; ++$round) {
    $pairing = $engine->pairNextRound($participants, $results, $byeIds);

    if ($pairing->hasBye()) {
        $byeIds[] = $pairing->getBye()->getId();
    }

    foreach ($pairing->getEvents() as $event) {
        // ...play the match, then record the outcome...
        $results[] = new Result($event, $event->getParticipants()[0]);
    }
}
```

Withdrawals are supported: pass only the still-active participants to
`pairNextRound()` — results involving withdrawn participants still count
toward standings. When repeat avoidance leaves no complete pairing, a
`NoValidPairingException` is thrown with a diagnostic report.

## Elimination Brackets

`SingleEliminationEngine` resolves the bracket from recorded results on each
call. Round 1 uses fold seeding (seeds 1 and 2 in opposite halves), fields
that are not a power of two give byes to the top seeds, and every round
carries a stage name.

```php
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Scheduling\SingleEliminationEngine;

$engine = new SingleEliminationEngine();

$results = [];
$champion = $engine->getChampion($participants, $results);
while ($champion === null) {
    $pairing = $engine->pairNextRound($participants, $results);
    echo "{$pairing->getStage()}\n"; // 'quarterfinal', 'semifinal', 'final', ...

    foreach ($pairing->getEvents() as $event) {
        // ...play the match; elimination results cannot be draws...
        $results[] = new Result($event, $event->getParticipants()[0]);
    }
    $champion = $engine->getChampion($participants, $results);
}

echo "Champion: {$champion->getLabel()}\n";
```

`DoubleEliminationEngine` adds a losers bracket and a grand final: everyone
must lose twice to be eliminated, so when the losers champion wins the grand
final a reset match decides the title (disable with
`new DoubleEliminationEngine(grandFinalReset: false)`). Conflicting,
duplicate, or round-less results are rejected with clear errors.

## Group Stages and Multi-Stage Tournaments

`GroupStageEngine` composes with the elimination engines for
groups-into-knockout tournaments:

```php
use MissionGaming\Tactician\Scheduling\GroupStageEngine;
use MissionGaming\Tactician\Scheduling\SingleEliminationEngine;

$groupEngine = new GroupStageEngine();

// Serpentine-seeded groups keyed 'A', 'B', ... with round robin per group
$groups = $groupEngine->createGroups($participants, 2);
$schedules = $groupEngine->scheduleGroups($groups);

// ...play the groups and record results...

$groupStandings = $groupEngine->calculateGroupStandings($groups, $groupResults);

// Qualifiers are reseeded so fold seeding produces cross-group pairings
// (A1 vs B2, B1 vs A2). Requires complete group play.
$qualifiers = $groupEngine->getQualifiers($groups, $groupResults, 2);

$knockout = new SingleEliminationEngine();
$semifinals = $knockout->pairNextRound($qualifiers, []);
```

## Serialization

Schedules round-trip through JSON; participants are listed once and
referenced by ID, so restored schedules share participant instances:

```php
use MissionGaming\Tactician\DTO\Schedule;

$json = $schedule->toJson();
$restored = Schedule::fromJson($json);
```

`Participant`, `Round`, `Event`, and `Schedule` all expose
`toArray()`/`fromArray()` for custom persistence.

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
    $violations = $e->getViolationCollector()->getViolations();
    foreach ($violations as $violation) {
        echo "Violation: " . $violation->getDescription() . "\n";
    }
    
    // Get expected vs actual event counts
    echo "Expected events: " . $e->getExpectedEventCount() . "\n";
    echo "Generated events: " . $e->getActualEventCount() . "\n";
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
    $violations = $e->getViolationCollector()->getViolations();
    if (!empty($violations)) {
        echo "\nConstraint violations:\n";
        foreach ($violations as $violation) {
            echo "- " . $violation->getDescription() . "\n";
        }
    }
    
    // Show completion statistics
    echo "\nCompletion: {$e->getActualEventCount()}/{$e->getExpectedEventCount()} events\n";
}
```

## Advanced Patterns

### Custom Schedulers

```php
use MissionGaming\Tactician\Scheduling\SchedulerInterface;
use MissionGaming\Tactician\Scheduling\SchedulerOptions;
use MissionGaming\Tactician\DTO\Schedule;

class CustomScheduler implements SchedulerInterface
{
    public function schedule(
        array $participants,
        ?SchedulerOptions $options = null
    ): Schedule {
        // Accept exactly one options type (define your own SchedulerOptions
        // implementation) and reject any other loudly; null means your
        // documented defaults
        $events = $this->generateCustomEvents($participants);

        return new Schedule($events, [
            'algorithm' => 'custom',
            'participant_count' => count($participants),
        ]);
    }

    // Implement getPlan() — the plan declares your algorithm's shape
    // (rounds, legs, expected events) so validation and diagnostics work
    // without round-robin assumptions, and it fails loudly for
    // unsatisfiable configurations before any event exists...
}
```

### Stage Plans

Every scheduler can declare the shape of a stage before generating it. The
plan is what validation, diagnostics, and shape-aware constraints (e.g.
`SeedProtectionConstraint`) consume — no component infers tournament shape:

```php
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Scheduling\SimpleSwissScheduler;
use MissionGaming\Tactician\Scheduling\SwissOptions;

$plan = (new RoundRobinScheduler())->getPlan($participants, new RoundRobinOptions(legs: 2));

$plan->getAlgorithm();          // 'round-robin' — stable identifier
$plan->getTotalRounds();        // 6 for 4 participants over 2 legs
$plan->getLegs();               // 2
$plan->getRoundsPerLeg();       // 3 (bye-aware: 5 participants would need 5)
$plan->getExpectedEventCount(); // 12

// Round-robin-family plans also guarantee pairwise meeting counts:
$plan->getExpectedMeetings($participants[0], $participants[1]); // 2

// Swiss has rounds but no legs: the legs accessors are null because the
// concept does not apply — never a fabricated 1
$swissPlan = (new SimpleSwissScheduler())->getPlan($participants, new SwissOptions(rounds: 3));
$swissPlan->getAlgorithm();     // 'swiss'
$swissPlan->getTotalRounds();   // 3
$swissPlan->getLegs();          // null
```

A plan also validates a complete schedule against its declared shape:
`$plan->validateIntegrity($schedule)` returns human-readable violation
strings (duplicate pairings, foreign participants, short rounds), and the
schedulers run it automatically before returning a schedule.

### Scheduling Context

```php
use MissionGaming\Tactician\Scheduling\SchedulingContext;

// Create context with participants, the stage plan, and existing events
$context = new SchedulingContext(
    $participants,
    $plan,
    $existingEvents,
    $currentLeg = 1,
    $participantsPerEvent = 2
);

// Shape facts come from the plan
$totalRounds = $context->getPlan()->getTotalRounds();

// Check if participants have played together
$havePlayed = $context->haveParticipantsPlayed($participants[0], $participants[1]);

// Get events for a specific participant
$playerEvents = $context->getEventsForParticipant($participants[0]);

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
    ->add(new MinimumRestPeriodsConstraint(5))  // Participants don't meet again soon
    ->build();

$scheduler = new RoundRobinScheduler($constraints);

// Generate full season: 2 legs (home and away)
$season = $scheduler->schedule(
    $teams,
    new RoundRobinOptions(legs: 2, strategy: new MirroredLegStrategy())
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
