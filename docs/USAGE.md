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
- [Pot Draws](#pot-draws)
- [Elimination Brackets](#elimination-brackets)
- [Pools, Progression, and Multi-Stage Tournaments](#pools-progression-and-multi-stage-tournaments)
- [Timeline Assignment](#timeline-assignment)
- [Schedule Repacking](#schedule-repacking)
- [Schedule Quality and Optimization](#schedule-quality-and-optimization)
- [Serialization](#serialization)
- [Framework Integration](#framework-integration)
- [Schedule Validation](#schedule-validation)
- [Error Handling](#error-handling)
- [Advanced Patterns](#advanced-patterns)
- [Real-World Examples](#real-world-examples)
- [Performance Considerations](#performance-considerations)

## Terminology

Tactician uses these terms consistently across the API, documentation, and
diagnostics. Note that the library deliberately says **participant** rather
than "team" — participants can be players, clubs, squads, debate teams, or
anything else that competes.

| Term | Meaning |
|------|---------|
| **Participant** | An entity that competes. Identified by a unique string ID, with a display label, optional seed, and metadata. The ID is any string and is compared exactly: see [Participant IDs](#participant-ids). |
| **Event** | A single match/fixture between participants (usually two). Sports platforms often call this a fixture. |
| **Pairing** | The unordered combination of participants in an event — "Alice vs Bob" regardless of who is home. |
| **Round** | A set of events played at the same stage of the tournament. Round numbers are 1-based and continuous across legs (a two-leg, 4-participant round robin has rounds 1–6). |
| **Leg** | One complete cycle of pairings. The leg count is *the number of times each participant meets each other participant*: a home-and-away league is 2 legs. Swiss and elimination formats have no legs concept. |
| **Home/Away roles** | The participant order within an event: the first participant is home, the second away. The round-robin generator alternates roles with round parity, which bounds how far a participant's home and away counts can drift apart; it does not make them equal. |
| **Bye** | A participant sitting out a round (odd participant counts). Byes are never emitted as events — round robin records them in the `byes` schedule metadata, and the Swiss/elimination engines report them on the round pairing. |
| **Seed** | A participant's ranking, used for bracket placement, serpentine group distribution, and seed-protection constraints. Lower numbers are better; 1 is the top seed. |
| **Schedule** | The complete, validated collection of generated events plus metadata. |
| **Result** | The recorded outcome of a played event: a winner or a draw, with optional per-participant scores. |
| **Standings** | The ordered table computed from results by `StandingsCalculator` — ranking values, records, and tiebreakers. |
| **Ranking strategy** | The pluggable rule ordering a standings table (`RankingStrategy`): it computes each participant's primary ranking value from their results, higher is better. `WinDrawLossRanking` (points from wins/draws/losses) is the built-in implementation; placement- or score-aggregating strategies slot in without touching the calculator. |
| **Tied set** | Two or more adjacent entries of a standings table that are **level**: equal on the ranking value, on every configured tiebreaker, on score difference and on scores-for, so that only the final fallback (seed, then label, then ID) orders them. `Standings::getTiedSets()` reports each one (`TiedSet`) with the positions it spans. |
| **Constraint** | A hard rule evaluated during generation (rest periods, seed protection, role limits...). Constraints either hold or generation fails loudly with diagnostics — there are no soft preferences. |
| **Stage** | One phase of a multi-stage tournament (e.g. a group stage feeding a knockout) — Tactician's unit of work: participants in, a schedule or round-by-round pairings out, a `StageOutcome` when play completes. Stages compose via pools and progression selectors. |
| **Pool** | A bucket of participants (`PoolDistributor::serpentine()`): what format the bucket plays, how it is scored, and how it progresses are separate, configurable concerns. |
| **Pot** | One of the equal blocks a seeded list of entrants is cut into for a pot draw, in list order: the first block is pot 1, the next pot 2, and so on. A pot is not a pool: the members of a pool play each other and are ranked together, while a pot only limits who an entrant can draw. |
| **Pot draw** | A format in which every round is drawn before any event is played (`PotDrawScheduler`): every entrant meets the same number of opponents from every pot, its own included, with no rematch, and is in every round. Often called "Swiss", but nothing is paired from results. See [Pot Draws](#pot-draws). |
| **Progression selector** | The hand-off between stages (`ProgressionSelector`): consumes a `StageOutcome`, returns the ordered entrant list of the destination stage. Standings-based (`RankRangeSelector`) or outcome-based (`MatchOutcomeSelector`) — never winners reconstructed through points arithmetic. Optional machinery: a bare ordered list is always a valid stage entry. |
| **Tie (elimination)** | One knockout pairing, played over one event or two mirrored legs (`legsPerTie`). A tie that finishes level (a drawn single event, or two legs that do not decide) is decided by the application under its own rules, and the participant who advances is recorded as `tie_winner` metadata on the event's result, or on a leg's result. Ties are not legs: brackets have no legs concept. |
| **Options** | The typed per-algorithm configuration object a scheduler accepts (`RoundRobinOptions`, `SwissOptions`, `PotDrawOptions`): legs mean legs, rounds mean rounds, and passing another algorithm's options fails loudly. All options are plain-data constructible (`fromArray()`/`toArray()`) with stable identifiers for config-driven platforms. |
| **Stage engine** | A results-driven pairing engine (`StageEngineInterface`): it consumes a `StageState` and produces the next `RoundPairing`, reports structural completion (`isComplete()`), and yields the `StageOutcome`. One driver loop covers every engine-based format. |
| **Stage state** | The serializable record of a results-driven stage between rounds (`StageState`): active participants, recorded pairings (with byes), and results. Pairings count as played even without results; withdrawals are `withoutParticipant()`, and a result of the last recorded round is corrected with `withResultReplaced()`. |
| **Engine fingerprint** | An optional stamp on a stage state saying which engine pairs it (`StageState::withEngineFingerprint()`), as each engine's `getFingerprint()` gives it (`FingerprintedEngine`). An opaque string, compared for equality: it stands for the format and for the options that shape which rounds the format has or how they are paired, and an option at its default is not part of it, so an engine that gains an option still accepts the states stamped before. An engine refuses a stamped state whose fingerprint is not its own; an unstamped state is accepted by every engine. |
| **Score group** | In a Swiss stage, the participants who are level on ranking value. The engine pairs within the table order and shuffles within a score group when it has a randomizer. Level means equal; two `WinDrawLossRanking` totals that differ only by the rounding of a float sum are level too, so the same results added in another order do not split a group. |
| **Stage outcome** | The uniform completion product (`StageOutcome`): standings, results, bye counts, and the structural final round. Deliberately free of champion/winner vocabulary — those are consumer interpretations of the outcome. |
| **Round pairing** | One round's product from a stage engine (`RoundPairing`): round number, optional label ('semifinal'; null for Swiss), events, and byes. |
| **Timeline** | A stage's declarative slot model (`TimelineDefinition`): a zoned start, a round interval, and optionally several slots per round. Round-aligned scheduling is the one-slot case; staggered kickoffs are more slots. One timeline per stage. |
| **Slot** | One kickoff time within a round, holding one event per resource (one event total when no resources are declared). A round's events fill its slots deterministically: schedule order against slot time order, resource by resource within a slot. |
| **Kickoff** | The assigned time of a scheduled event, always emitted in UTC (`ScheduledEvent::getKickoff()`); the timeline's wall-clock arithmetic happens in the stage's declared timezone. |
| **Resource** | A named host of concurrent events within a slot (venue, pitch, court, board...). A slot holds one event per resource; no declared resources means one anonymous resource. Each scheduled event carries its assigned resource. |
| **Quality metric** | A graded, lower-is-better measure of a valid schedule (`QualityMetric`): role balance, alternation streaks, rest rhythm, repeat spacing. Metrics measure defects — zero is ideal. Composed with weights by `ScheduleScorer`; policy (which metrics, what weights) stays application-side. |
| **Optimization** | Best-of-N sampling (`ScheduleOptimizer`): generate N candidate schedules from one master seed, score each, keep the best. Deterministic when every randomness source uses the supplied child randomizer. Whole-schedule generators only. |
| **Backtracking generation** | An opt-in round-robin search (`RoundRobinOptions(backtracking: true)`) over the round decompositions the circle method's rotations cannot reach. Greedy always runs first; the search is deterministic and step-bounded, and failing it distinguishes a proven-unsatisfiable configuration from an exhausted budget. |
| **Timeline rule** | A time-aware rule (`TimelineRule`) validated over the assigned kickoffs — minimum rest in hours (`MinimumRestRule`), blackout windows (`BlackoutRule`). Assignment is deterministic, so a violated rule fails loudly rather than being routed around; rules are not generation constraints. |
| **Session** | One match night (or day) on a repack grid: an ordered position in the grid's explicit session list, holding a fixed number of slots. Deliberately not a "round" — a round is a set of concurrent events, a session is a container of consecutive slots. |
| **Session grid** | The declarative position model repacking assigns onto (`SessionGrid`): an explicit ordered list of zoned session starts, a slot interval, a per-session slot count (overridable — final sessions often run deeper), and a per-slot concurrency capacity. Irregular by design, unlike `TimelineDefinition`'s cadence. A grid built this way is instant-based; see **Shape-only grid** for the other form. |
| **Position** | One assignable place on a session grid: a 0-based session and a 0-based slot within it. A position hosts as many concurrent events as the grid's capacity allows. |
| **Position ordinal** | A position's 0-based index when the positions of a grid are counted in grid order, session by session and slot by slot (`SessionGrid::ordinalOf()`, reversed by `positionOf()`). What an application indexes its own slot records by. |
| **Shape-only grid** | A session grid that knows which positions exist and not when they are (`SessionGrid::shapeOnly()`): a session count, the slot counts and a capacity. For an application that keeps its own times. It is repacked exactly as the instant-based grid of the same shape; its assignments carry no kickoff, and every accessor that would return a time throws an `UnavailableValueException`. |
| **Unbounded capacity** | A session grid whose slots host any number of concurrent events (`capacityPerSlot: null`; `'unbounded'` in plain data), so that the only limit is that no participant is in two events at once. Not the default, which is 1. |
| **Repack** | Repairing an existing schedule (`ScheduleRepacker`): assigning every movable event a (session, slot) position so nobody is double-booked and each participant's events within a session run back to back where possible. Returns a `RepackOutcome` carrying the schedule plus itemised violations rather than throwing. |
| **Movable event** | An existing event the repack may place (`MovableEvent`): an opaque caller-supplied stable id and two participants. The movable set is a multigraph — the same pairing may occur more than once. |
| **Pinned event** | An event already on the grid that must not move (`PinnedEvent`). It occupies its position for both participants, consumes slot capacity, and may name participants absent from the movable set. Which events are pinned is caller policy about historical provenance. |
| **Repack violation** | One structured compromise in a repack outcome (`RepackViolation`): double-booking (audited, never produced), unplaced events, interior gaps, late starts, exceeded capacity — data with participant/session/magnitude, never prose. The caller renders its own messages and decides what is fatal. |
| **Unplaced event** | A movable event the repack gave no position (`UnplacedEvent`), with the reason (`UnplacedReason`). Every movable event is either assigned or unplaced. |
| **Step budget** | The number of search steps a repack may spend (`RepackOptions(stepBudget: ...)`), shared by all of its searches. Steps, not time, so the result is the same on every machine. `RepackOutcome::isBudgetExhausted()` says whether the budget stopped a search. |
| **Outcome fingerprint** | A short string identifying what a repack outcome holds (`RepackOutcome::fingerprint()`): equal for the same assignments, unplaced events and violations, different when any differs, and the same on every PHP version and platform. For detecting that a plan computed again is not the plan that was shown. |
| **Pin conflict** | One participant pinned in two events at the same session and slot of a repack request. The request is rejected with a `PinConflictException`, which carries the IDs of the two events; the caller moves or unpins one of them. Where the two events also exceed the capacity of the slot, the request reports that instead (`PinCapacityExceeded`). |
| **Configuration error reason** | The kind of mistake behind an `InvalidConfigurationException`, as a case of the `InvalidConfigurationReason` enum (`getReason()`). It says what was wrong, not which component found it, and its backing string is a stable identifier. Code branches on the reason and never on the message text. See [Configuration Errors](#configuration-errors). |
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

### Participant IDs

An ID is any string, and two participants are the same participant only
when their IDs are the same string. `'01'` and `'1'` are two participants,
and so are `'1e3'` and `'1000'`, although PHP compares each pair as equal
numbers. An ID may contain any character, including the `|`, `:` and `\`
that the library uses inside its own lookup keys. The IDs of one field must
be unique; nothing else is required of them to generate a schedule or to
pair a stage. Writing a schedule or a stage state as JSON also needs every
ID to be valid UTF-8.

```php
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

// Entry numbers as they were typed: two of them are the number one
$entries = [
    new Participant('01', 'Entry 01'),
    new Participant('1', 'Entry 1'),
    new Participant('a|b', 'Entry A and B'),
    new Participant('a', 'Entry A'),
];

$entrySchedule = (new RoundRobinScheduler())->schedule($entries);

echo count($entrySchedule) . " events\n"; // 6 events: every pair meets once
```

Two things to know when the IDs are numbers:

- PHP turns an array key such as `'42'` into the integer `42`. Where the
  library returns an array keyed by participant ID (`getByeCounts()`, the
  scores of a `Result`), cast the key back with `(string)` before you
  compare it with an ID.
- The last fallback of the standings order compares IDs as PHP compares two
  strings, so `'9'` sorts before `'10'`. It is reached only when ranking
  value, tiebreakers, scores, seed and label are all equal.

### Working with Events and Rounds

```php
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Round;

// Create a round with metadata
$round = new Round(1, ['phase' => 'group-stage', 'court' => 'A']);

// Create an event between two of the participants created above
$event = new Event(
    participants: [$participant, $seededPlayer],
    round: $round,
    metadata: ['start_time' => '10:00', 'referee' => 'John Smith']
);

// Access event properties
$participants = $event->getParticipants();
$roundNumber = $event->getRound()->getNumber();
$startTime = $event->getMetadataValue('start_time');

// Check if a participant is in the event
if ($event->hasParticipant($participant)) {
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

// Maximum 2 equipment types per match (this can only reject an event
// with more than two participants)
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

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\Scheduling\SchedulingContext;

// Custom constraint: a predicate that receives the candidate event and
// the scheduling context, and returns whether the event is allowed
$customConstraint = ConstraintSet::create()
    ->custom(
        function (Event $event, SchedulingContext $context): bool {
            $participants = $event->getParticipants();

            // Ensure participants have compatible equipment
            $equipment1 = $participants[0]->getMetadataValue('equipment');
            $equipment2 = $participants[1]->getMetadataValue('equipment');

            return $equipment1 === $equipment2;
        },
        'Compatible Equipment'
    )
    ->build();

// A custom constraint that reads the schedule built so far
$advancedConstraint = ConstraintSet::create()
    ->custom(
        function (Event $event, SchedulingContext $context): bool {
            $participants = $event->getParticipants();

            // Allow each participant at most three events in total
            $firstParticipantEvents = $context->getEventsForParticipant($participants[0]);
            $secondParticipantEvents = $context->getEventsForParticipant($participants[1]);

            return count($firstParticipantEvents) < 3 && count($secondParticipantEvents) < 3;
        },
        'Maximum Three Events'
    )
    ->build();
```

### Role-Based Constraints

```php
use MissionGaming\Tactician\Constraints\RoleBalanceConstraint;

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
use MissionGaming\Tactician\DTO\Participant;
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
$options->toArray(); // ['legs' => 2, 'strategy' => 'mirrored', 'backtracking' => false]
```

### Backtracking Generation

The greedy generator retries bounded rotated orderings when constraints
reject a schedule — but the circle method fixes which pairings share a
round purely by list order, so it only ever sees a handful of round
decompositions, and some satisfiable constraint sets fail every one of
them. **Backtracking generation** searches the decompositions the
rotations cannot reach. The fixture-placement policy below is one such
set: every rotation the greedy generator tries violates it, and the
search finds the schedule that satisfies it:

```php
use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Event;

// Celtic must meet Athletic in round 1, Livorno in round 2 and Red Star
// in round 3
$placement = ['athletic|celtic' => 1, 'celtic|livorno' => 2, 'celtic|redstar' => 3];

$constraints = ConstraintSet::create()
    ->custom(
        function (Event $event) use ($placement): bool {
            $ids = array_map(fn (Participant $p) => $p->getId(), $event->getParticipants());
            sort($ids);
            $requiredRound = $placement[implode('|', $ids)] ?? null;
            $round = $event->getRound()?->getNumber();

            return $requiredRound === null || $round === null || $round === $requiredRound;
        },
        'Fixture Placement'
    )
    ->build();

$schedule = (new RoundRobinScheduler($constraints))
    ->schedule($participants, new RoundRobinOptions(backtracking: true));
```

The rules of the search (see `docs/design/backtracking-generation.md`):

- **Opt-in, greedy first.** Greedy remains the default and always runs
  first; the search only starts when every rotation has failed, so
  satisfiable-by-rotation configurations pay nothing.
- **Deterministic and bounded.** Seat, opponent, and orientation order
  are fixed, and a step budget bounds the exponential worst case. The
  failure diagnostic distinguishes a proven-unsatisfiable configuration
  (search space exhausted) from one that ran out of budget.
- **Leg scope.** Leg 1 is searched; later legs derive from its actual
  rounds through the leg strategy. A later leg rejected by constraints
  fails loudly — the search does not cross leg boundaries.

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
        [$home, $away] = $event->getParticipants();
        echo "  {$home->getLabel()} vs {$away->getLabel()}\n";
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
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Standings\BuchholzTiebreaker;
use MissionGaming\Tactician\Standings\StandingsCalculator;
use MissionGaming\Tactician\Standings\WinDrawLossRanking;
use MissionGaming\Tactician\Standings\WinsTiebreaker;

$alice = new Participant('alice', 'Alice');
$bob = new Participant('bob', 'Bob');
$carol = new Participant('carol', 'Carol');
$dave = new Participant('dave', 'Dave');
$participants = [$alice, $bob, $carol, $dave];

// Results are recorded against events - normally the ones a scheduler
// or engine produced
$eventOne = new Event([$alice, $bob]);
$eventTwo = new Event([$alice, $carol]);
$eventThree = new Event([$carol, $dave]);

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

### Tied sets

A standings table gives every entry a position of its own, also when no
result separates two entries: the calculator then orders them by seed, by
label and finally by ID, so that the same results always give the same table.
That order says nothing about how the participants performed. An application
that advances, relegates or seeds by position needs to know where it applies,
and `Standings::getTiedSets()` reports it:

```php
// Before any result exists nothing separates the four participants
$unplayed = $calculator->calculate($participants, []);

$advancing = 2;
foreach ($unplayed->getTiedSets() as $tiedSet) {
    $labels = array_map(
        fn(Participant $participant): string => $participant->getLabel(),
        $tiedSet->getParticipants()
    );
    echo "Positions {$tiedSet->getFirstPosition()} to {$tiedSet->getLastPosition()}: "
        . implode(', ', $labels) . "\n";

    // A set that starts at or above the cut and ends below it
    if ($tiedSet->getFirstPosition() <= $advancing && $tiedSet->getLastPosition() > $advancing) {
        echo "The results do not decide who takes the top {$advancing} positions\n";
    }
}

// In the table of the three results above, results decide every position
echo count($standings->getTiedSets()) . " tied sets\n";
```

That prints:

```
Positions 1 to 4: Alice, Bob, Carol, Dave
The results do not decide who takes the top 2 positions
0 tied sets
```

Two entries are **level** when every figure the calculator compares before
the fallback is equal: the ranking value, the value of each configured
tiebreaker, the score difference and scores-for
(`StandingEntry::isLevelWith()` is that comparison). A **tied set**
(`TiedSet`) is a group of two or more level entries, as large as it can be.
In a table the calculator built, level entries are always next to each other
(a `Standings` constructed by hand is read in the order it was given, and
only adjacent level entries form a set), so a set spans the
consecutive positions from `getFirstPosition()` to `getLastPosition()`, and
`getEntries()` and `getParticipants()` list its members in table order. The
sets are returned in table order; a table with two separate ties returns two
sets, each with its own positions, and a table in which results decide every
position returns an empty list. An entry is never in two sets.

A tie that a configured tiebreaker breaks is not reported: add a tiebreaker
to the calculator and the entries it separates leave the set. The record
(played, wins, draws, losses) is not compared unless a tiebreaker compares
it, because the table is not ordered by it.

"Equal" means the same number, with no tolerance, because that is the
comparison that orders the table: two entries are reported as tied exactly
when the calculator found nothing to order them by. The built-in rankings
(3/1/0 and 1/½/0) and tiebreakers add whole numbers and halves, which are
exact. A custom ranking value such as 0.1 is not: three wins at 0.1 add up to
slightly more than one draw at 0.3, so the table places the first entry above
the second and does not report them as tied. Use values with an exact binary
form (whole numbers, halves, quarters) where ties must be recognised.

Reading the tied sets changes nothing in the table: the entries, their order
and `getPosition()` are what they were. Deciding a tie (a play-off, a drawing
of lots, a shared rank) is the application's rule; apply it to the entrant
list passed to the next stage, where position is authoritative.

## Swiss Tournaments

`SwissPairingEngine` is a **stage engine** (`StageEngineInterface`): it
pairs one round at a time from the recorded `StageState`. Participants are
ordered by standings and paired adjacently (Monrad style), backtracking
past repeat pairings. Byes rotate to the lowest-placed participant with
the fewest so far and are credited as wins when ordering the next round.

Participants who are level form a **score group**. With a `Randomizer` the
engine shuffles the order within each group, and a credited bye is level
with the win it stands for. Level means equal, with one allowance. A
`WinDrawLossRanking` value is a float sum, and with a scale floats cannot
hold exactly (0.1 for a draw) the same results added in another order
differ in the last digits; the engine reads two such totals as level when
they are no further apart than that rounding can put them, and never when a
real result separates them. With 3/1/0 or 1/0.5/0 scoring the sums are
exact, so level means equal. The values of a ranking strategy of your own
are always compared exactly: return equal values for participants you mean
to be level.

Every results-driven format shares one driver loop — the single
integration a platform writes:

```php
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Scheduling\SwissPairingEngine;
use MissionGaming\Tactician\Stage\StageState;

$participants = array_map(
    fn (string $name) => new Participant(strtolower($name), $name),
    ['Alice', 'Bob', 'Carol', 'Dave', 'Erin', 'Frank', 'Grace', 'Heidi']
);

// plannedRounds lets length-aware constraints (e.g. seed protection) size
// their windows correctly, and tells isComplete() when the stage ends
$engine = new SwissPairingEngine(plannedRounds: 5);

$state = StageState::start($participants);
while (!$engine->isComplete($state)) {
    $pairing = $engine->pairNextRound($state);

    // ...play the round, then record the pairing with its results...
    $results = [];
    foreach ($pairing->getEvents() as $event) {
        $results[] = new Result($event, $event->getParticipants()[0]);
    }

    $state = $state->withRoundPlayed($pairing, $results);
}

// The uniform completion product: standings, results, byes, final round
$outcome = $engine->getOutcome($state);
$leader = $outcome->getStandings()->getEntries()[0];
```

`StageState` absorbs the between-round bookkeeping (bye threading, round
numbers, played pairings) and serializes — `toArray()`/`fromArray()` and
`toJson()`/`fromJson()` — so platforms persist it between rounds instead
of re-deriving it. Withdrawals are a first-class verb:
`$state->withoutParticipant($p)` removes a participant from pairing while
their recorded games still count toward standings. When repeat avoidance
leaves no complete pairing, a `NoValidPairingException` is thrown with a
diagnostic report.

For a whole Swiss schedule without recorded results — random non-repeat
pairing over N rounds — use the `SwissScheduler` preset, which drives this
engine through the same loop while recording no results:

```php
use MissionGaming\Tactician\Scheduling\SwissOptions;
use MissionGaming\Tactician\Scheduling\SwissScheduler;
use Random\Randomizer;

$schedule = (new SwissScheduler(null, new Randomizer()))
    ->schedule($participants, new SwissOptions(rounds: 3));
```

### Correcting a Recorded Result

`withResultReplaced()` replaces the result of one event of the **last
recorded round**, for a result that was entered wrongly. It corrects the
record; it does not decide an event (an elimination event that finished
level is decided by the `tie_winner` it is recorded with, see
[Recording a Level Event](#recording-a-level-event)). The event is found by its round
number, its participants in either order and its tie leg, so the state may
have come back from storage. The replacement takes the place of the old
result; nothing else in the state changes.

```php
// Round 5 is the last recorded round. Its first event was entered as a
// win for the first participant; the second participant won it.
$event = $pairing->getEvents()[0];
[$enteredWinner, $actualWinner] = $event->getParticipants();

$corrected = $state->withResultReplaced(new Result($event, $actualWinner));

echo count($state->getResults()) . ' results before, '
    . count($corrected->getResults()) . " after\n"; // 20 results before, 20 after
```

A result of an earlier round is refused. The rounds after it were paired
from it (a bracket's next round holds the winners, a Swiss round follows
the table), and a state that kept them over a changed result would hold
pairings no engine made from it:

```php
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;

$firstRoundEvent = $state->getRoundsPlayed()[0]->getEvents()[0];

try {
    $state->withResultReplaced(new Result($firstRoundEvent, $firstRoundEvent->getParticipants()[1]));
    echo "Replaced\n";
} catch (InvalidConfigurationException $e) {
    echo "Refused: round {$e->getContext()['round']} is not the last recorded round "
        . "({$e->getContext()['last_round']})\n"; // Refused: round 1 is not the last recorded round (5)
}
```

To correct an earlier round, rebuild the state: `StageState::start()`, then
`withRoundPlayed()` for each round that still stands, with the corrected
result, and ask the engine for the next round again. An event that has no
recorded result is refused as well: add a first result with
`withAdditionalResults()`.

### Recording Which Engine Pairs a State

A stage state does not say which engine paired its rounds, and an engine
replays whatever it is handed. A Swiss state restored into a bracket
engine, or a two-legged bracket into an engine built for one leg, is read
as that engine's own history. Stamp the state with the engine's
**fingerprint** and every engine with another fingerprint refuses it:

```php
use MissionGaming\Tactician\Scheduling\SingleEliminationEngine;

$engine = new SwissPairingEngine(plannedRounds: 5);

$stamped = StageState::start($participants)
    ->withEngineFingerprint($engine->getFingerprint());

// The stamp is stored with the state and comes back with it
$restored = StageState::fromJson($stamped->toJson());

echo count($engine->pairNextRound($restored)->getEvents()) . " events\n"; // 4 events

try {
    (new SingleEliminationEngine())->pairNextRound($restored);
    echo "Paired as a bracket\n";
} catch (InvalidConfigurationException $e) {
    echo 'Refused: ' . implode('; ', $e->getContext()['differences']) . "\n";
    // Refused: format: recorded swiss, this engine single-elimination
}
```

The stamp is optional. A state without one is accepted by every engine,
serializes without the `engine_fingerprint` key, and data stored before the
stamp existed loads as an unstamped state. A stamped state keeps its stamp
through every verb.

**What a fingerprint covers.** Two engines have the same fingerprint when
they are the same format and agree on every option that shapes which rounds
the format has or how they are paired. An option at its default value is
not part of the fingerprint. An engine that gains an option in a later
release therefore keeps the fingerprint of every configuration that leaves
the option alone, and the states stamped before are still accepted. For one
configuration the fingerprint is the same in every later release.

| Engine | Part of the fingerprint when not at the default | Not part of it |
| --- | --- | --- |
| `SwissPairingEngine` | The standings rules a round is paired from: the ranking scale (default 3/1/0), the tiebreakers in order (default none) | `plannedRounds`, the constraints, the randomizer |
| `SingleEliminationEngine` | `legsPerTie` (default 1), `reseedEachRound` (default off); in a re-seeded bracket also the standings rules, as for Swiss | `grandFinalReset`; the standings calculator of a bracket on a fixed path |
| `DoubleEliminationEngine` | `legsPerTie` (default 1), `grandFinalReset` (default on) | The standings calculator |

```php
use MissionGaming\Tactician\Standings\StandingsCalculator;
use MissionGaming\Tactician\Standings\WinDrawLossRanking;

$default = (new SwissPairingEngine())->getFingerprint();

// The length of a Swiss stage is not part of it: a stage that is extended
// by a round keeps its stamp
var_dump((new SwissPairingEngine(plannedRounds: 6))->getFingerprint() === $default); // bool(true)

// 3/1/0 is the default scale, stated or not
$threeOneZero = new StandingsCalculator(WinDrawLossRanking::threeOneZero());
var_dump((new SwissPairingEngine(standingsCalculator: $threeOneZero))->getFingerprint() === $default); // bool(true)

// Another scale pairs the same results differently
$chess = new SwissPairingEngine(standingsCalculator: new StandingsCalculator(WinDrawLossRanking::oneHalfZero()));
var_dump($chess->getFingerprint() === $default); // bool(false)

try {
    $chess->pairNextRound($restored);
    echo "Paired on the chess scale\n";
} catch (InvalidConfigurationException $e) {
    echo 'Refused: ' . implode('; ', $e->getContext()['differences']) . "\n";
    // Refused: ranking: recorded the default, this engine win-draw-loss,1,0.5,0
}
```

The planned rounds of a Swiss stage say when it ends; no round is paired or
read differently for them. The standings calculator of a bracket that is not
re-seeded orders the outcome and pairs nothing.

**What it cannot cover.** A fingerprint describes what the library can
describe, and these limits follow from that:

- The constraints and the randomizer of a Swiss engine change pairings and
  are not part of the fingerprint: a closure and a seed have no name.
  Restore them with the state, as you do today.
- A `RankingStrategy` of your own is stated as "custom". The engine tells it
  from every `WinDrawLossRanking` scale and not from another strategy of
  your own. A subclass of `StandingsCalculator` is stated as "custom" too,
  whatever ranking strategy and tiebreakers it was built with: it may order
  the table by rules of its own, so it differs from the library's
  calculator and not from another subclass. A tiebreaker is stated by its
  `getName()`, so one that orders differently needs a name of its own,
  including a subclass of one of the library's.
- A subclass of `SwissPairingEngine` (the class is not final) does not have
  the fingerprint of the engine it extends, because it may pair differently.
  Two subclasses have the same one unless they override `getFingerprint()`.

**Treat the string as opaque.** Compare it for equality with the one an
engine gives. Do not parse it, write one by hand or rely on how it is
spelled: only its stability for one configuration is promised. A
fingerprint that begins with `tactician:` is the library's. An engine of
your own can use any non-empty string that does not begin with it, and
call `$state->requireEngineFingerprint()` with that string.

All four engine methods (`getPlan()`, `pairNextRound()`, `isComplete()`,
`getOutcome()`) refuse a state stamped with another fingerprint, with an
`InvalidConfigurationException`. Its message, and the list `differences` in
its context, say where the two differ: the format, or each option with its
value on either side. That text is for a person and may change; the context
also holds the two fingerprints as `recorded` and `engine`. To change the
configuration on purpose in mid-stage, stamp the state again:
`$state->withEngineFingerprint($newEngine->getFingerprint())`; passing
`null` removes the stamp.

The three engines implement `Stage\FingerprintedEngine`. `getFingerprint()`
is not part of `StageEngineInterface`, so that an engine written against
that interface keeps working; code that holds a `StageEngineInterface`
tests for it before it stamps:

```php
use MissionGaming\Tactician\Stage\FingerprintedEngine;
use MissionGaming\Tactician\Stage\StageEngineInterface;

function startStage(StageEngineInterface $engine, array $participants): StageState
{
    $state = StageState::start($participants);

    return $engine instanceof FingerprintedEngine
        ? $state->withEngineFingerprint($engine->getFingerprint())
        : $state;
}

var_dump(startStage(new SingleEliminationEngine(), $participants)->getEngineFingerprint() !== null); // bool(true)
```

## Pot Draws

A pot draw is a format for a league phase or a seeding stage. The entrants
are ordered by seed and cut into **pots** of equal size, in order: the first
block of the list is pot 1, the next pot 2, and so on. Every entrant meets a
fixed number of opponents from every pot, its own included, never the same
opponent twice, and every entrant is in every round. Every round is drawn
before any event is played. The UEFA Champions League league phase since
2024/25 is the best-known example: 36 entrants, 4 pots of 9, two opponents
from every pot, so 8 events each.

Operators often call this format "Swiss". It is not Swiss pairing: nothing is
paired from results, and no standings exist while it is drawn. It is a
partial round robin constrained by pots. For pairing from results, use the
[Swiss engine](#swiss-tournaments).

`PotDrawScheduler` is a whole-schedule generator, like `RoundRobinScheduler`.
Its options are the number of pots, the number of opponents an entrant meets
from each pot, and the seed of the draw. The number of rounds is derived:
pots × opponents per pot.

```php
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\PotDrawOptions;
use MissionGaming\Tactician\Scheduling\PotDrawScheduler;

// 36 entrants in seeding order. List position is the seeding: the first
// nine are pot 1. No seed attribute is read.
$entrants = [];
for ($position = 1; $position <= 36; ++$position) {
    $entrants[] = new Participant("e{$position}", "Entrant {$position}");
}

$drawOptions = new PotDrawOptions(pots: 4, opponentsPerPot: 2, seed: 2026);
$scheduler = new PotDrawScheduler();

// The plan knows the shape before anything is drawn
$plan = $scheduler->getPlan($entrants, $drawOptions);
echo $plan->getAlgorithm() . "\n";          // pot-draw
echo $plan->getPotSize() . "\n";            // 9
echo $plan->getTotalRounds() . "\n";        // 8
echo $plan->getEventsPerRound() . "\n";     // 18
echo $plan->getExpectedEventCount() . "\n"; // 144

$schedule = $scheduler->schedule($entrants, $drawOptions);
echo count($schedule) . "\n";               // 144
```

The result is an ordinary `Schedule`: iterate it, group it by round,
serialize it, or hand it to the [timeline assigner](#timeline-assignment).
`$plan->getPotOf($participant)` gives the 1-based pot of an entrant and
`$plan->getPotMembers(1)` the members of a pot. With one pot the format is a
draw of non-repeat pairings over the whole field.

The generator is pairwise: every event has two participants. It takes no
`ConstraintSet`; pairs of entrants that must not meet are not an input yet.

### Roles

A role is the position of a participant in an event: the first-named
participant is in the first role (home, white, server) and the other in the
second. A pot draw balances them:

- the two role counts of an entrant differ by at most one, and are equal when
  the entrant has an even number of events;
- with an even number of opponents per pot, an entrant is in each role
  exactly half the time against every pot.

In the draw above every entrant has four events in each role, one of each
against every pot:

```php
$topSeed = $entrants[0];
$rolesByPot = [];
foreach ($schedule as $event) {
    if (!$event->hasParticipant($topSeed)) {
        continue;
    }

    [$first, $second] = $event->getParticipants();
    $isFirst = $first->getId() === $topSeed->getId();
    $rolesByPot[$plan->getPotOf($isFirst ? $second : $first)][] = $isFirst ? 'first' : 'second';
}

ksort($rolesByPot);
foreach ($rolesByPot as $pot => $roles) {
    sort($roles);
    echo "Pot {$pot}: " . implode(', ', $roles) . "\n";
}
```

```text
Pot 1: first, second
Pot 2: first, second
Pot 3: first, second
Pot 4: first, second
```

### The seed and determinism

The seed is an option, not a `Random\Randomizer` handed to the scheduler, so
that a draw can be stored as plain data and repeated. The same entrants, pots,
opponents per pot and seed give the same schedule on every call, on one
scheduler or on a new one; the scheduler keeps no state between calls. Another
seed gives another schedule. Any integer is a seed, and the default is 0.
This seed names a draw. It has nothing to do with the seed of a participant,
which is a ranking.

```php
use MissionGaming\Tactician\DTO\Schedule;

$stored = $drawOptions->toArray(); // ['pots' => 4, 'opponents_per_pot' => 2, 'seed' => 2026]

$again = (new PotDrawScheduler())->schedule($entrants, PotDrawOptions::fromArray($stored));
$other = $scheduler->schedule($entrants, new PotDrawOptions(pots: 4, opponentsPerPot: 2, seed: 2027));

$events = fn (Schedule $drawn): array => $drawn->toArray()['events'];
var_dump($events($again) === $events($schedule)); // bool(true)
var_dump($events($other) === $events($schedule)); // bool(false)
```

`PotDrawOptions::fromArray()` refuses a key it does not know (reason
`UnknownOptionKey`), so a misspelt option is not silently read as its default.
A key that is left out takes its default: one pot, one opponent per pot,
seed 0.

The seed chooses the order of the members inside each pot, the order of the
pots, which matchings are used, which side is first, how the events are
spread over the rounds, and the order of the rounds and of the events in
them. The engine behind it (`Random\Engine\Xoshiro256StarStar`) is part of
the output, and the golden fixtures pin one draw of each worked case.

Who meets whom is drawn evenly: between two given pots, or inside one, every
pairing is as likely as any other. The generator first builds rounds in which
whole pots meet each other, and then mixes them: two rounds at a time trade
events in a way that keeps every entrant in both rounds exactly once, eight
times the number of rounds over. No event is changed by this, so the
opponents and the roles are the ones first built. After it a round mixes the
pots, the events inside the pots are spread over the rounds, and which of
two pots is first varies from event to event within a round.

The draw is still not uniform over every schedule the format allows:

- the pairings between two pots follow one pattern (a rotation of the two
  pots' members against each other), and no seed draws a schedule outside it;
- the mixing is a fixed amount of work, so now and then a round is left in
  which every pot meets one other pot only (about one round in ten for 16
  entrants in 4 pots with two opponents per pot, and fewer than one in
  twenty for the larger cases measured);
- the smallest fields leave the mixing nothing to trade: with 6 entrants in
  3 pots of 2, every round has one pot playing inside itself and the other
  two meeting each other.

### Feasible, supported and refused configurations

A configuration can exist only if all four hold:

1. the number of entrants is even, because every entrant is in every round
   and no bye is issued;
2. the entrants divide into pots of equal size, because pots of unequal size
   cannot give every entrant the same number of opponents from every pot;
3. opponents per pot is at most pot size minus one, because an entrant cannot
   meet more members of its own pot than the pot has;
4. pot size × opponents per pot is even, because the events inside one pot
   number half of that product.

Of the configurations that can exist, this version draws those it can build
directly, without a search:

| Pot size | Opponents per pot | |
|------|------|------|
| Even | Any, up to pot size minus one | Drawn |
| Odd | 2 | Drawn (the number of pots is then even) |
| Odd | 4, 6, ... | Refused as not yet supported |

Everything else is refused before anything is drawn, by `getPlan()` and
`schedule()` alike, with an `InvalidConfigurationException` whose reason says
which rule failed and whose context carries the numbers:

```php
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;

$attempts = [
    '19 entrants, 1 pot, 2 per pot' => [19, 1, 2],
    '20 entrants, 3 pots, 1 per pot' => [20, 3, 1],
    '20 entrants, 5 pots of 4, 4 per pot' => [20, 5, 4],
    '20 entrants, 4 pots of 5, 1 per pot' => [20, 4, 1],
    '36 entrants, 4 pots of 9, 4 per pot' => [36, 4, 4],
];

foreach ($attempts as $name => [$count, $pots, $opponentsPerPot]) {
    try {
        $scheduler->getPlan(array_slice($entrants, 0, $count), new PotDrawOptions($pots, $opponentsPerPot));
    } catch (InvalidConfigurationException $e) {
        echo "{$name}: {$e->getReason()?->value} " . json_encode($e->getContext()) . "\n";
    }
}
```

```text
19 entrants, 1 pot, 2 per pot: odd_participant_count {"participant_count":19}
20 entrants, 3 pots, 1 per pot: unequal_pots {"participant_count":20,"pots":3,"remainder":2}
20 entrants, 5 pots of 4, 4 per pot: too_many_opponents_per_pot {"opponents_per_pot":4,"pot_size":4,"maximum_opponents_per_pot":3}
20 entrants, 4 pots of 5, 1 per pot: odd_pot_with_odd_opponents {"pot_size":5,"opponents_per_pot":1,"participant_slots_inside_one_pot":5}
36 entrants, 4 pots of 9, 4 per pot: configuration_not_yet_supported {"participant_count":36,"pots":4,"pot_size":9,"opponents_per_pot":4,"supported_opponents_per_pot_for_odd_pot_size":[2]}
```

| Reason | The configuration |
|------|------|
| `OddParticipantCount` | Has an odd number of entrants. It is checked first, so an odd field is reported as odd whatever the pots |
| `UnequalPots` | Does not divide into the pots asked for |
| `TooManyOpponentsPerPot` | Asks for more opponents from a pot than a pot has other members |
| `OddPotWithOddOpponents` | Has pots of odd size and an odd number of opponents per pot |
| `ConfigurationNotYetSupported` | Can exist, and has no direct construction yet: pots of odd size with four or more opponents per pot |

`ConfigurationNotYetSupported` is raised by the scheduler. `Stage\PotDrawPlan`
itself describes every configuration that can exist, so a schedule made
elsewhere can be checked with `validateIntegrity()`. That check is of the
format and not only of the counts: every entrant once in every round, the
exact number of opponents from every pot, no rematch, and the role balance.
The scheduler runs it on every schedule it returns.

Generation takes time proportional to the number of events (entrants × pots ×
opponents per pot / 2), the mixing included: a few milliseconds for 60
entrants in 6 pots with three opponents per pot.

## Elimination Brackets

The elimination engines are **presets** — canned compositions of
single-round knockout stages behind the same stage driver loop as Swiss.
Entry pairing folds by **list position** (position 1 is the top entrant;
positions 1 and 2 land in opposite halves), fields that are not a power of
two give byes to the top positions, and every round carries a label.

```php
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Scheduling\SingleEliminationEngine;
use MissionGaming\Tactician\Stage\MatchOutcomeSelector;
use MissionGaming\Tactician\Stage\StageState;

$participants = array_map(
    fn (string $name) => new Participant(strtolower($name), $name),
    ['Alice', 'Bob', 'Carol', 'Dave', 'Erin', 'Frank', 'Grace', 'Heidi']
);

$engine = new SingleEliminationEngine();

$state = StageState::start($participants); // list order = seeding order
while (!$engine->isComplete($state)) {
    $pairing = $engine->pairNextRound($state);
    echo "{$pairing->getLabel()}\n"; // 'quarterfinal', 'semifinal', 'final', ...

    $results = [];
    foreach ($pairing->getEvents() as $event) {
        // ...play the match; an event that finishes level needs a decision
        // (see "Recording a Level Event" below)...
        $results[] = new Result($event, $event->getParticipants()[0]);
    }
    $state = $state->withRoundPlayed($pairing, $results);
}

$outcome = $engine->getOutcome($state);

// "The champion" is your derivation of the outcome: rank 1 of the
// standings, or the winners of the final round
$titleHolder = MatchOutcomeSelector::winners()->select($outcome)[0];
```

The outcome's win/loss standings reproduce conventional bracket placement
with no special cases — champion 3-0, runner-up 2-1, semifinal losers
joint 1-1, quarter-final losers joint 0-1 in an 8-entrant bracket.

`EliminationOptions` configures the preset (plain-data constructible via
`fromArray()`):

- `reseedEachRound: true` re-ranks survivors by standings and re-folds
  every round, instead of the default fixed bracket path.
- `legsPerTie: 2` plays every tie over two mirrored legs (annotated with
  `tie_leg` metadata). Whoever wins more legs advances; when the legs are
  level, the aggregate is **yours** to resolve — away goals, extra time,
  penalties are rules Tactician never owns — and you record the decision
  as `TieDecision::TIE_WINNER_KEY` (`'tie_winner'`) metadata on one leg's
  result. Per-leg results remain ordinary results feeding standings: the
  table counts each leg as it was recorded, and a decision changes nothing
  in it.

`DoubleEliminationEngine` adds a losers bracket and a grand final: everyone
must lose twice to be eliminated, so when the losers champion wins the grand
final a reset match decides the title (disable with
`new DoubleEliminationEngine(new EliminationOptions(grandFinalReset: false))`).
Conflicting, duplicate, or round-less results are rejected with clear
errors; partially recorded rounds are completed with
`$state->withAdditionalResults([...])`.

### Recording a Level Event

An elimination tie must send one participant on, and an event can still
finish level. Who advances is then **your** decision, under rules Tactician
never owns and never asks about. Record the event as the draw it was, and
name the participant who advances as `TieDecision::TIE_WINNER_KEY`
(`'tie_winner'`) metadata on its result, by ID. This is the key and the
reading a level two-legged tie has, in both engines and in every round of
them (the losers bracket, the grand final and its reset included).

```php
use MissionGaming\Tactician\Stage\TieDecision;

[$alice, $bob, $carol, $dave] = $participants;

$state = StageState::start([$alice, $bob, $carol, $dave]);
$semifinals = $engine->pairNextRound($state);
[$aliceVsDave, $bobVsCarol] = $semifinals->getEvents();

$state = $state->withRoundPlayed($semifinals, [
    // Level at 1-1; Dave is the one who advances
    new Result(
        $aliceVsDave,
        null,
        ['alice' => 1, 'dave' => 1],
        [TieDecision::TIE_WINNER_KEY => $dave->getId()]
    ),
    new Result($bobVsCarol, $bob, ['bob' => 2, 'carol' => 0]),
]);

foreach ($engine->pairNextRound($state)->getEvents() as $event) {
    [$first, $second] = $event->getParticipants();
    echo "{$first->getLabel()} v {$second->getLabel()}\n"; // Dave v Bob
}
```

The recorded result is not changed: `isDraw()` is true, `getWinner()` is
null and the scores are the scores. To read who advanced from a result
yourself, read the metadata key, or ask `TieDecision::advancer()`, which
is the reading the engines and `MatchOutcomeSelector` use:

```php
$recorded = $state->getResults()[0];

echo ($recorded->isDraw() ? 'drawn' : 'won') . ', '
    . TieDecision::advancer([$recorded], $alice, $dave, 1)?->getLabel() . " advances\n"; // drawn, Dave advances
```

In the outcome's standings a level event that was decided this way counts
as a win for the participant who advanced and a loss for the other, with
the scores as recorded. The table of a single-leg bracket therefore places
it as it does when every event has a winner: in single elimination the
participant who advanced from a level final is rank 1 and not level with
the runner-up, and re-seeding (`reseedEachRound`) ranks the survivor of a
level event with the other winners of the round.

Only the table the engine computes reads the decision. The outcome's
results are the results as recorded, so a table you compute from them
yourself counts the level event as a draw, and so does
`StageOutcome::combining()`, which computes its combined table from the
results of the outcomes it is given.

A level event that names nobody is refused, and so is one that names a
participant who is not in the event. The state itself accepts the result,
because it does not know the format; the engine then rejects the state on
every call (`isComplete()`, `pairNextRound()`, `getOutcome()`), with a
message that names the key:

```php
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;

$undecided = StageState::start([$alice, $bob, $carol, $dave])
    ->withRoundPlayed($semifinals, [
        new Result($aliceVsDave, null, ['alice' => 1, 'dave' => 1]),
        new Result($bobVsCarol, $bob),
    ]);

try {
    $engine->pairNextRound($undecided);
    echo "Paired\n";
} catch (InvalidConfigurationException $e) {
    echo "{$e->getReason()?->value}: {$e->getMessage()}\n";
    // undecided_tie: Invalid scheduler configuration: Elimination events cannot end in a draw
    // (Alice vs Dave): the event is level, so record who advances as 'tie_winner' metadata on its result
}
```

| The level event's result | Reason (`getReason()`) |
|--------------------------|------------------------|
| carries no `tie_winner`, or carries `null` | `UndecidedTie` |
| names a participant who is not one of the two in the event (or a value that is not an ID) | `InvalidResult` |

The decision is read only when the results leave the tie level. On an
event that has a winner, and on a two-legged tie that the leg wins decide,
`tie_winner` is not read at all: it cannot overturn the result, and a value
that names nobody in the tie goes unnoticed there.

Record the decision with the result, in the `withRoundPlayed()` call that
records the round. `$state->withResultReplaced(...)` is not how a level
event is decided: it corrects a record that was entered wrongly (see
[Correcting a Recorded Result](#correcting-a-recorded-result)). A state
that is stuck on a level event was recorded incompletely or wrongly, and
the correction says which. If the event did finish level, the decision is
what the record lacks: replace the result with the same draw, carrying
`tie_winner`. If the draw was entered by mistake, replace it with the
result the event had. Do not replace a level event with a win it was not.

```php
$decided = $undecided->withResultReplaced(new Result(
    $aliceVsDave,
    null,
    ['alice' => 1, 'dave' => 1],
    [TieDecision::TIE_WINNER_KEY => $alice->getId()]
));

[$final] = $engine->pairNextRound($decided)->getEvents();
echo implode(' v ', array_map(fn (Participant $p) => $p->getLabel(), $final->getParticipants())) . "\n"; // Alice v Bob
```

## Pools, Progression, and Multi-Stage Tournaments

A **pool** is just a bucket of participants: what format the bucket plays,
how it is scored, and how it progresses are separate concerns. The retired
group-stage monolith is now a composition of generic primitives
(`playPool()` below stands for your application playing a pool's schedule
and returning one `Result` per event):

<!-- snippet: setup
/** @return list<\MissionGaming\Tactician\DTO\Result> */
function playPool(\MissionGaming\Tactician\DTO\Schedule $schedule): array
{
    return array_map(
        static fn (\MissionGaming\Tactician\DTO\Event $event) => new \MissionGaming\Tactician\DTO\Result($event, $event->getParticipants()[0]),
        $schedule->getEvents()
    );
}
-->

```php
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Scheduling\SingleEliminationEngine;
use MissionGaming\Tactician\Stage\PoolDistributor;
use MissionGaming\Tactician\Stage\RankRangeSelector;
use MissionGaming\Tactician\Stage\StageOutcome;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Standings\StandingsCalculator;

// Eight entrants in seeding order
$participants = array_map(
    fn (string $name) => new Participant(strtolower($name), $name),
    ['Alice', 'Bob', 'Carol', 'Dave', 'Erin', 'Frank', 'Grace', 'Heidi']
);

// Serpentine pools by list position: with two pools, positions 1, 4, 5, 8
// land in pool A and 2, 3, 6, 7 in B
$pools = PoolDistributor::serpentine($participants, pools: 2);

// Each pool plays ANY per-stage format - round robin here
$calculator = new StandingsCalculator();
$scheduler = new RoundRobinScheduler();
$poolOutcomes = [];
foreach ($pools as $label => $poolParticipants) {
    $schedule = $scheduler->schedule($poolParticipants);
    $results = playPool($schedule); // application-side

    // Progressing from a partial table promotes the wrong participants:
    // check every pairing has a result before qualifying
    assert($scheduler->getPlan($poolParticipants)->findUnplayedPairings($results) === []);

    $poolOutcomes[$label] = new StageOutcome(
        $calculator->calculate($poolParticipants, $results),
        $results
    );
}

// One combined outcome, optionally carrying the pool structure
$combined = StageOutcome::combining($poolOutcomes, $calculator);

// The hand-off: top 2 per pool, pool winners first - exactly the ordering
// fold seeding wants for cross-pool pairings (A1 vs B2, B1 vs A2)
$qualifiers = RankRangeSelector::topPerGroup(2)->select($combined);

$knockout = new SingleEliminationEngine();
$knockoutState = StageState::start($qualifiers); // position 1 = seed 1
```

**Progression selectors** are the hand-off between stages: they consume a
`StageOutcome` and produce the ordered entrant list of the next stage.
Order is authoritative — a stage seeds from list position — so library
selectors and consumer-derived lists behave identically by construction.
Two families cover the two legitimate substrates (and mixing them within
one decision invites contradictory qualification — pick one ranking
authority per decision):

```php
use MissionGaming\Tactician\Stage\MatchOutcomeSelector;

// Standings-based (rank slices):
RankRangeSelector::topPerGroup(2);            // the classic qualifiers
RankRangeSelector::perGroup(from: 3, to: 4);  // a losers' route
RankRangeSelector::overall(from: 1, to: 8);   // best 8 across all pools

// Outcome-based (recorded match results - never points arithmetic):
MatchOutcomeSelector::winners();              // knockout round -> next round
MatchOutcomeSelector::losers();               // knockout round -> repechage
```

All selectors are plain-data constructible (`fromArray()`/`toArray()`)
with stable mode identifiers. Selectors are optional machinery, not a
gate: a consumer computing its own qualification hands the next stage an
ordered list directly, with no penalty.

**Ahead-of-time composition validation** checks that a declared
multi-stage structure telescopes before any fixture exists:

```php
use MissionGaming\Tactician\Stage\CompositionValidator;
use MissionGaming\Tactician\Stage\MatchOutcomeSelector;
use MissionGaming\Tactician\Stage\StageTransition;

$violations = (new CompositionValidator())->validateChain(16, [
    new StageTransition('quarterfinals', 8, MatchOutcomeSelector::winners()),
    new StageTransition('semifinals', 4, MatchOutcomeSelector::winners()),
    new StageTransition('final', 2, MatchOutcomeSelector::winners()),
]);
// [] - the chain telescopes: 16 -> 8 -> 4 -> 2
```

Consumer-derived selections participate by declaring expected entrant
counts (a transition without a selector); concurrent routes (winners
forward, losers to a repechage) validate as separate chains from the same
source.

## Timeline Assignment

`TimelineAssigner` maps a generated schedule onto real dates and times.
The mechanism is Tactician's; the policy is yours — you translate your
competition config into a declarative **slot model**, and the assigner
produces timestamped events deterministically. Round-aligned scheduling
("everyone plays round N at time T") is the one-slot case; staggered
kickoffs are the same model with more slots:

```php
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Timeline\TimelineAssigner;
use MissionGaming\Tactician\Timeline\TimelineDefinition;

// Any generated schedule: here a four-participant round robin
$participants = [
    new Participant('celtic', 'Celtic'),
    new Participant('athletic', 'Athletic Bilbao'),
    new Participant('livorno', 'AS Livorno'),
    new Participant('redstar', 'Red Star FC'),
];
$schedule = (new RoundRobinScheduler())->schedule($participants);

// Weekly match days with three staggered kickoffs, an hour apart
$timeline = new TimelineDefinition(
    start: new DateTimeImmutable('2026-08-01 18:00', new DateTimeZone('Europe/London')),
    roundInterval: new DateInterval('P7D'),
    slotsPerRound: 3,
    slotInterval: new DateInterval('PT1H'),
);

$scheduled = (new TimelineAssigner())->assign($schedule, $timeline);

foreach ($scheduled->getEventsByRound() as $round => $scheduledEvents) {
    foreach ($scheduledEvents as $scheduledEvent) {
        // ScheduledEvent wraps the untouched Event with its UTC kickoff
        $when = $scheduledEvent->getKickoff();   // DateTimeImmutable, UTC
        $event = $scheduledEvent->getEvent();
    }
}
```

The rules of the mechanism:

- **Timezone-explicit in, UTC out.** The definition's start carries the
  stage's timezone; interval arithmetic is wall-clock in that zone (a
  weekly 19:00 kickoff stays 19:00 across DST transitions), and assigned
  kickoffs are emitted in UTC. Display-timezone policy stays app-side.
- **Deterministic filling.** A round's events fill its slots in schedule
  order against slot time order — the same schedule and timeline always
  produce the same kickoffs.
- **Loud validation.** A round with more events than the timeline can
  hold (slots × resources) fails with diagnostics, and a schedule
  carrying round-less events is refused rather than silently dropping
  fixtures.
- **Round numbers are absolute offsets.** Round N lands at
  start + (N−1) round intervals whether or not earlier rounds exist, so
  cross-leg-continuous numbering maps stably.
- **Decoration, not mutation.** `ScheduledEvent`/`ScheduledSchedule`
  wrap events without touching them; re-assignment against a different
  timeline is cheap, and the decorated view serializes
  (`toArray()`/`fromArray()`/JSON) so platforms persist assigned
  kickoffs.

**Resources** host concurrent events within a slot — venue, pitch,
court, board, station; the name is generic because the concept is.
Declaring them lifts the one-event-per-slot default: each slot hosts one
event per resource, filled slot by slot, resource by resource in
declared order, and every `ScheduledEvent` carries its assigned resource
(`getResource()`, serialized alongside the kickoff):

```php
$timeline = new TimelineDefinition(
    start: new DateTimeImmutable('2026-08-01 15:00', new DateTimeZone('Europe/London')),
    roundInterval: new DateInterval('P7D'),
    resources: ['Pitch 1', 'Pitch 2'],   // two concurrent kickoffs per slot
);
```

Timelines are **per stage** — a group stage playing weekly slots and a
finals weekend are two `TimelineDefinition`s. For results-driven stages,
assign round by round through the engine bridge:

```php
use MissionGaming\Tactician\Scheduling\SwissPairingEngine;
use MissionGaming\Tactician\Stage\StageState;

$engine = new SwissPairingEngine(plannedRounds: 3);
$state = StageState::start($participants);

$pairing = $engine->pairNextRound($state);
$scheduledEvents = (new TimelineAssigner())->assignRound($pairing, $timeline);
```

Definitions are plain-data constructible for config-driven platforms,
with ISO 8601 durations:

```php
$timeline = TimelineDefinition::fromArray([
    'start' => '2026-08-01 18:00:00',
    'timezone' => 'Europe/London',
    'round_interval' => 'P7D',
    'slots_per_round' => 3,
    'slot_interval' => 'PT1H',
]);
```

**A datetime in plain data states its date in full**: the year, the month
and the day, as in `2026-08-01 18:00` or `2026-08-01T18:00:00`. The time of
day is optional and defaults to midnight (`2026-11-09` is
`2026-11-09 00:00:00`); so do the seconds and a fraction of a second. The
zone comes from the `timezone` field, and a zone or offset written into the
string must not contradict it. This holds wherever a datetime is read from
plain data: the `start` of a timeline, the `from` and `to` of a blackout
window, the `sessions` of a session grid and the `kickoff` of a serialized
scheduled event (which is UTC unless the string carries a zone).

Two kinds of string are rejected, with the reason `UnparseableTime` (the
kickoff with an `InvalidInputException`):

- A string whose answer would come from the clock. It is relative to the
  current time (`now`, `tomorrow`, `+1 week`, `next monday 20:00`, the
  empty string), or it has no date (`20:00`), or its date has no year
  (`August 1 20:00`).
- A string that does not mean what it writes. It names a date or a time
  that does not exist (`2026-02-30 20:00`, `2026-08-01 24:00`,
  `2026-08-01 23:59:60`), a weekday that is not the weekday of its date
  (`Mon, 01 Aug 2026 19:00:00`: 1 August 2026 is a Saturday), or a second
  timezone that is not the first (`2026-08-01 19:00 +01:00 +05:00`).

PHP's date parser accepts all of these. It resolves the first kind against
the clock, so the same configuration would give a different timeline each
time it is loaded, and the library never asks for the current time. It
reads the second kind to another instant than the one written: 30 February
as 2 March, `24:00` as the next day, the Saturday as the Monday after it,
and the first of two timezones.

<!-- snippet: throws="MissionGaming\Tactician\Exceptions\InvalidConfigurationException" -->
```php
// "tomorrow" is a different day each time the configuration is loaded
TimelineDefinition::fromArray([
    'start' => 'tomorrow 18:00',
    'timezone' => 'Europe/London',
    'round_interval' => 'P7D',
]);
```

Every other string PHP reads is accepted, and read as PHP reads it: a month
name (`1 August 2026 19:00`), RFC 2822 with the right weekday, an ISO 8601
week date (`2026-W31-6T19:00`) or ordinal date (`2026-213T19:00`), a Unix
timestamp (`@1785610800`), a timezone written twice (`+0000 (UTC)`). That
includes an offset from a date the string states, which is counted from that
date and not from today: `2026-08-01 20:00 +1 week` is 8 August at 20:00,
and `first monday of August 2026 19:00` is 3 August. A relative date with no
date to count from is for the application to compute and pass on.

One local time is still read to another: a time that a clock change skips
in the declared timezone (`2026-03-29 01:30` in `Europe/London`) is moved
forward by the hour that was skipped.

### Time-Aware Rules

Assignment is deterministic slot arithmetic, so a violated time rule
cannot be routed around — it can only be reported, loudly. **Timeline
rules** (`TimelineRule`) judge the assigned kickoffs; they are
deliberately not generation constraints, which filter pairings during a
search before any time exists:

```php
use MissionGaming\Tactician\Timeline\BlackoutRule;
use MissionGaming\Tactician\Timeline\MinimumRestRule;
use MissionGaming\Tactician\Timeline\TimelineAssigner;

$assigner = new TimelineAssigner([
    // Rest measured in hours rather than rounds, compared as UTC
    // instants (DST cannot shrink it); any positive rest also forbids
    // double-booking
    MinimumRestRule::fromArray(['rest' => 'PT48H']),

    // Half-open windows [from, to); translating policy (breaks,
    // closures, holidays) into windows is the application's job
    BlackoutRule::fromArray(['windows' => [[
        'from' => '2026-11-09 00:00:00',
        'to' => '2026-11-17 00:00:00',
        'timezone' => 'Europe/London',
        'label' => 'international break',
    ]]]),
]);

// Violating any rule fails assignment with every violation in the
// exception context
$scheduled = $assigner->assign($schedule, $timeline);
```

Rules also validate standalone — `$rule->validate($scheduled)` returns
violation strings — so an application driving a results-driven stage
round by round can check the timeline it accumulates. Both built-in
rules are plain-data constructible with `fromArray()`/`toArray()`, and
custom rules implement the two-method `TimelineRule` interface.

## Schedule Repacking

Generation invents events; repacking repairs a schedule whose events
already exist. Given movable events, pinned events that must not move,
and a **session grid**, `ScheduleRepacker` assigns every movable event a
`(session, slot)` position such that no participant is ever in two
events at once and each participant's events within a session run back
to back where possible. The canonical case is a season behind schedule:
the outstanding fixtures are fixed facts with wildly unequal
per-participant loads, and the operator needs them landed on declared
match nights.

The grid is an explicit ordered list of session starts — deliberately
not `TimelineDefinition`'s regular cadence, because recovery grids are
irregular and the last session routinely runs deeper than the rest:

```php
use MissionGaming\Tactician\Repack\SessionGrid;

$grid = SessionGrid::fromArray([
    'sessions' => ['2026-08-12 20:00', '2026-08-19 20:00'],
    'timezone' => 'Europe/London',       // authoritative, like the timeline family
    'slot_interval' => 'PT30M',
    'slots_per_session' => 3,
    'slots_per_session_overrides' => [1 => 4],  // final session runs deeper
    'capacity_per_slot' => 3,            // concurrent events per slot
]);
```

Events carry an opaque caller-supplied id — the library never invents
one, never parses it, and orders results by nothing else. The same pair
may meet more than once (the input is a multigraph); only the id is
unique. Pinned events occupy their position for both participants and
are never assigned a new one; a pin may name a participant that appears
nowhere in the movable set (a withdrawn participant's played fixtures
still block their opponents' positions):

```php
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Repack\MovableEvent;
use MissionGaming\Tactician\Repack\PinnedEvent;
use MissionGaming\Tactician\Repack\RepackOptions;
use MissionGaming\Tactician\Repack\RepackRequest;
use MissionGaming\Tactician\Repack\ScheduleRepacker;

$celtic = new Participant('celtic', 'Celtic');
$athletic = new Participant('athletic', 'Athletic Bilbao');
$livorno = new Participant('livorno', 'AS Livorno');
$rayo = new Participant('rayo', 'Rayo Vallecano');

$request = new RepackRequest(
    movableEvents: [
        new MovableEvent('e03', $celtic, $livorno),
        new MovableEvent('e04', $celtic, $rayo),
        // ...
    ],
    pinnedEvents: [
        // Played at session 0, slot 0 — historical provenance, immovable
        new PinnedEvent('e01', $celtic, $athletic, 0, 0),
    ],
    grid: $grid,
    options: new RepackOptions(consolidationWeight: 3, earlyFillWeight: 1)
);

$outcome = (new ScheduleRepacker())->repack($request);

foreach ($outcome->getAssignments() as $assignment) {
    // $assignment->getEventId(), ->getSession(), ->getSlot(),
    // ->getKickoff() — the position's UTC kickoff from the grid
}
```

**Repacking returns compromises instead of throwing.** This is a
deliberate deviation from the generation contract: an operator repairing
a broken season the week of the games needs "here is the schedule, and
here are the compromises in it", not an exception. The outcome carries
every compromise as structured data (participant, session, kind,
magnitude — never pre-formatted prose), and the caller decides what is
fatal. `RepackOptions(throwOnViolations: true)` opts into a
`RepackViolationsException` that still carries the full outcome.

The contract, in order:

1. **Hard, never traded** — no participant occupies one position twice,
   counting pins; and pins do not move. `ParticipantDoubleBooked` exists
   as a violation kind only so the final audit can prove it never
   happens.
2. **Hard, or reported** — no interior gap in a participant's slots
   within a session (`ContiguityBroken`, with the gap size). Interval
   edge colouring is not always possible, so an instance that cannot be
   gap-free is reported, never silently relaxed.
3. **Objectives** — start each participant's run at the session's first
   slot (`LateStart` reports the depth when impossible), concentrate
   each participant's events into fewer sessions, and fill early
   sessions first. The last two pull against each other;
   `consolidationWeight` and `earlyFillWeight` make the trade explicit,
   and consolidation dominates by default.

Capacity problems surface as `CapacityExceeded` — most usefully scoped
to a participant whose outstanding events outnumber its free positions
once pins are respected (`getShortfall()` says how many positions the
operator must add). The shortfall events come back in
`getUnplaced()` with reasons, and every movable event is either assigned
or listed there — the counts reconcile exactly.

The repacker is deterministic (same input, same output, independent of
input list order), pure (no clock reads, no I/O), and bounded (every
search spends from `RepackOptions(stepBudget: ...)`, steps not
wall-clock, so behaviour is reproducible behind an HTTP preview
request). Events that fall entirely outside the grid cannot collide with
it and are the caller's to filter before the request — collision is
exact position identity, by design; there is no fuzzy time-overlap
detection.

### Grids Without Instants

`SessionGrid` as built above is **instant-based**: it knows when every
position is, and each assignment carries a UTC kickoff. An application
that keeps its times in another form (local wall-clock times, a list of
its own slot records) has no instants to give and no use for the ones it
would get back. For that, `SessionGrid::shapeOnly()` builds a
**shape-only grid**: how many sessions, how many slots each, and the
capacity.

```php
$shape = SessionGrid::shapeOnly(
    sessions: 2,
    slotsPerSession: 3,
    slotsPerSessionOverrides: [1 => 4],
    capacityPerSlot: 3
);

$shaped = (new ScheduleRepacker())->repack(new RepackRequest(
    $request->getMovableEvents(),
    $request->getPinnedEvents(),
    $shape
));

foreach ($shaped->getAssignments() as $assignment) {
    $ordinal = $shape->ordinalOf($assignment->getSession(), $assignment->getSlot());
    echo "{$assignment->getEventId()}: session {$assignment->getSession()}, "
        . "slot {$assignment->getSlot()}, ordinal {$ordinal}\n";
}
// e03: session 0, slot 1, ordinal 1
// e04: session 0, slot 2, ordinal 2

echo json_encode($shape->positionOf(5)), "\n";   // {"session":1,"slot":2}
echo json_encode($shape->positionOf(7)), "\n";   // null: the grid has 7 positions, 0 to 6
echo json_encode($shape->toArray()), "\n";
// {"session_count":2,"slots_per_session":3,"capacity_per_slot":3,"slots_per_session_overrides":{"1":4}}
```

The repacker reads positions and nothing else, so a shape-only grid gets
every event the same `(session, slot)` as the instant-based grid of the
same shape. What differs is what the library can say about time:

- `hasInstants()` is false, and `getSessionStart()`, `getSlotInterval()`,
  `getSlotTime()` and `positionOf()` given an instant throw an
  `UnavailableValueException`. A shape-only grid never invents an
  instant.
- An assignment has no kickoff: `SlotAssignment::hasKickoff()` is false,
  `getKickoff()` throws the same exception, and `toArray()` carries
  `'kickoff' => null`.
- The exception reports a mistake in the calling code, not in its data,
  and is not one to catch: code that may be given either form of grid
  asks `hasInstants()` or `hasKickoff()` first. It extends
  `\LogicException`, so code that reads `getKickoff()` from the
  assignments of an instant-based grid has nothing new to handle. See
  [Exception Hierarchy](#exception-hierarchy).
- As plain data the grid has `session_count` in place of `sessions`,
  `timezone` and `slot_interval`. `fromArray()` reads the shape-only form
  only when there is no `sessions` key; a shape-only grid that also gives
  `timezone` or `slot_interval` is rejected (`IncompatibleOptions`). An
  instant-based grid serializes exactly as it did before shape-only grids
  existed.

<!-- snippet: throws="MissionGaming\Tactician\Exceptions\UnavailableValueException" -->
```php
$shape->getSlotTime(0, 0);
// A shape-only grid has no instants, so it cannot give a slot time.
// Check hasInstants() before asking for a time.
```

Two lookups turn a position into something an application can index its
own records by, and back. They work on both forms of grid. A position's
**ordinal** is its 0-based index in grid order, session by session and
slot by slot: `ordinalOf($session, $slot)` returns it (and throws
`PositionOutOfRange` for a position the grid does not have), and
`positionOf($ordinal)` returns `['session' => ..., 'slot' => ...]`, or
null when no position has that ordinal. On an instant-based grid
`positionOf()` also takes an instant and returns the position whose slot
time it is, or null:

```php
$halfPastEight = new DateTimeImmutable('2026-08-19 20:30', new DateTimeZone('Europe/London'));

echo json_encode($grid->positionOf($halfPastEight)), "\n";   // {"session":1,"slot":1}
echo $grid->ordinalOf(1, 1), "\n";                           // 4
var_dump($grid->hasInstants());                              // bool(true)
```

The instants are compared, so the timezone the argument is written in
does not matter. Where one session runs on past the start of the next,
two positions can share an instant, and the first in grid order is
returned.

### Unbounded Capacity

`capacityPerSlot` is how many events may share one slot, and its default
is 1: one event at a time. That default is right for one shared resource
and wrong for events that need nothing shared, where the only limit is
that no participant is in two events at once. Pass `null` for
**unbounded capacity** (`'capacity_per_slot' => SessionGrid::UNBOUNDED`,
the string `'unbounded'`, in plain data). It is the way to say "no
limit": do not pass a very large integer for that, because the planner
multiplies the capacity by the number of slots.

```php
$events = [
    new MovableEvent('e03', $celtic, $livorno),
    new MovableEvent('e04', $celtic, $rayo),
    new MovableEvent('e05', $celtic, $athletic),
    new MovableEvent('e06', $livorno, $rayo),
    new MovableEvent('e07', $athletic, $rayo),
];
$pins = [new PinnedEvent('e01', $celtic, new Participant('napoli', 'Napoli'), 0, 1)];

// One session of three slots. With the default capacity of 1 the pin
// fills a slot, and the grid has two positions for five events
$crowded = (new ScheduleRepacker())->repack(
    new RepackRequest($events, $pins, SessionGrid::shapeOnly(sessions: 1, slotsPerSession: 3))
);
echo count($crowded->getAssignments()), ' placed, ', count($crowded->getUnplaced()), " unplaced\n";
// 2 placed, 3 unplaced

$open = SessionGrid::shapeOnly(sessions: 1, slotsPerSession: 3, capacityPerSlot: null);
$roomy = (new ScheduleRepacker())->repack(new RepackRequest($events, $pins, $open));
echo count($roomy->getAssignments()), ' placed, ', count($roomy->getUnplaced()), " unplaced\n";
// 4 placed, 1 unplaced

var_dump($open->hasUnboundedCapacity());   // bool(true)
var_dump($open->getCapacityLimit());       // NULL
echo $open->toArray()['capacity_per_slot'], "\n";   // unbounded
```

Unbounded capacity removes the limit on a slot and nothing else:

- The grid as a whole can no longer be too small, so the outcome never
  carries a `CapacityExceeded` with a null participant.
- A participant can still have more events than free positions, which no
  capacity changes. That is the one event still unplaced above: Celtic
  has three events and, with the pin, two free slots. It is reported as a
  `CapacityExceeded` naming the participant.
- Any number of events may be pinned at one position.
- `getCapacityPerSlot()` returns an integer, and an unbounded grid has
  none to return: it throws an `UnavailableValueException`.
  `getCapacityLimit()` returns the integer or null and never throws; code
  that may be given either kind of grid reads that, or asks
  `hasUnboundedCapacity()` first.

A missing or null `capacity_per_slot` in plain data still means 1, as it
always has. That is why the plain-data form of unbounded is a word.

### Reading the Outcome

`getViolations()` returns every compromise as a `RepackViolation`, each
with a `getKind()` (a `ViolationKind` case) and a `toArray()`. There are
five kinds, each a class of its own with getters for what it is about.
`getViolationsOfKind(ViolationKind $kind)` filters the list and returns
it typed as the interface. The five accessors named after the kinds
return the same lists typed as their classes, so the getters can be read
without an `instanceof` check:

```php
foreach ($crowded->getCapacityExceededViolations() as $violation) {
    $who = $violation->getParticipant()?->getLabel() ?? 'the grid';
    echo "{$who}: {$violation->getDemand()} needed, {$violation->getCapacity()} available, "
        . "{$violation->getShortfall()} short\n";
}
// Celtic: 3 needed, 2 available, 1 short
// the grid: 4 needed, 2 available, 2 short

foreach ($crowded->getLateStartViolations() as $violation) {
    echo "{$violation->getParticipant()->getLabel()} starts at slot {$violation->getFirstSlot()} "
        . "of session {$violation->getSession()}\n";
}
// Rayo Vallecano starts at slot 2 of session 0

foreach ($crowded->getUnplaced() as $unplaced) {
    $because = $unplaced->getParticipant()?->getLabel() ?? 'nobody in particular';
    echo "{$unplaced->getEventId()}: {$unplaced->getReason()->value} ({$because})\n";
}
// e05: participant_over_capacity (Celtic)
// e06: no_slot_available (nobody in particular)
// e07: no_slot_available (nobody in particular)

echo count($crowded->getEventUnplacedViolations()), "\n";      // 3
echo count($crowded->getContiguityBrokenViolations()), "\n";   // 0
echo count($crowded->getParticipantDoubleBookedViolations()), "\n";   // 0
```

| Kind (`ViolationKind`) | Class and typed accessor | What it reports |
|---|---|---|
| `ParticipantDoubleBooked` (`participant_double_booked`) | `ParticipantDoubleBooked`, `getParticipantDoubleBookedViolations()` | A participant at one position twice. The repacker never produces it; the final audit checks for it so that its absence is a fact. |
| `EventUnplaced` (`event_unplaced`) | `EventUnplaced`, `getEventUnplacedViolations()` | A movable event that received no position. One for each entry of `getUnplaced()`, so code that reads violations alone misses nothing. |
| `ContiguityBroken` (`contiguity_broken`) | `ContiguityBroken`, `getContiguityBrokenViolations()` | A participant whose slots in one session have a gap between them. |
| `LateStart` (`late_start`) | `LateStart`, `getLateStartViolations()` | A participant whose first slot in a session is not the session's first. |
| `CapacityExceeded` (`capacity_exceeded`) | `CapacityExceeded`, `getCapacityExceededViolations()` | More events than positions: for a participant, or (null participant) for the grid. |

`ContiguityBroken` and `LateStart` are reported for a participant and a
session only where the participant has a movable event in that session; a
session holding only that participant's pins is history the repack did
not arrange. `isClean()` is true when there is no violation of any kind
and nothing unplaced, late starts included.

An unplaced event (`UnplacedEvent`) has a reason, an `UnplacedReason`
case: `ParticipantOverCapacity` (`participant_over_capacity`) when one of
its participants has more events than free positions, and then
`getParticipant()` is that participant; `NoSlotAvailable`
(`no_slot_available`) when no position on the whole grid had capacity
left with both participants free, and then `getParticipant()` is null.

### The Step Budget

Every search the repacker runs spends from one budget,
`RepackOptions(stepBudget: ...)`, counted in search steps, not in time:
the same request and budget give the same outcome on every machine. When
the budget runs out the repacker stops searching and returns what it has
reached, with everything that is left reported as usual.
`isBudgetExhausted()` says whether that happened:

```php
var_dump($outcome->isBudgetExhausted());   // bool(false)

$starved = (new ScheduleRepacker())->repack(new RepackRequest(
    $request->getMovableEvents(),
    $request->getPinnedEvents(),
    $grid,
    new RepackOptions(stepBudget: 1)
));
var_dump($starved->isBudgetExhausted());   // bool(true)
```

- **True**: at least one search wanted another step and was refused. A
  larger budget may give a different outcome for the same request. It is
  not a promise of a better one: the request may have no better packing,
  and the searches are heuristics.
- **False**: the budget stopped nothing, so a larger budget gives the
  same outcome. It does not mean the outcome is the best possible; the
  searches have limits of their own that the budget does not lift.

The flag says nothing about violations: an outcome can be clean with the
flag true, and can carry violations with it false. It is not part of
`toArray()` or of the fingerprint, because it describes how the outcome
was reached and not the outcome.

### Outcome Fingerprints

`fingerprint()` returns a short string that is equal for two outcomes
holding the same assignments, unplaced events and violations, and
different when any of them differs. Its use is detecting that a plan has
changed: show an operator a preview, keep its fingerprint, and when the
operator confirms, repack again and compare before writing anything.

```php
echo $outcome->fingerprint(), "\n";
// v1:170a5b5c2b8386a00fa959d26afcd76fab43b8521e08106b3bdbbf2334dbc945

$recomputed = (new ScheduleRepacker())->repack($request);
var_dump($recomputed->fingerprint() === $outcome->fingerprint());   // bool(true)

// The same positions with no kickoffs are not the same plan
var_dump($shaped->fingerprint() === $outcome->fingerprint());       // bool(false)
```

The format is a contract. A fingerprint is a scheme, a colon and 64
lowercase hexadecimal digits: `v1:` (`RepackOutcome::FINGERPRINT_SCHEME`)
followed by the SHA-256 of the canonical document below. A change to the
canonical document is a new scheme, so compare two fingerprints only when
their schemes are equal, and recompute a stored fingerprint of another
scheme.

The canonical document of scheme `v1` is these bytes:

```
"tactician.repack.outcome.v1\n" SET(assignments) SET(unplaced) SET(violations)

SET(records)  = "l" COUNT ":" the VALUE of every record, in ascending byte order ";"
VALUE(null)   = "n;"
VALUE(bool)   = "b1;" for true, "b0;" for false
VALUE(int)    = "i" DECIMAL ";"
VALUE(float)  = "f" the 16 hexadecimal digits of the IEEE 754 double, big-endian ";"
VALUE(string) = "s" LENGTH-IN-BYTES ":" the bytes ";"
VALUE(list)   = "l" COUNT ":" the VALUE of every item, in the list's order ";"
VALUE(map)    = "m" COUNT ":" VALUE(key) VALUE(item) for every entry,
                the entries in ascending byte order ";"
```

Each of the three is a set of records, and a record is a map with exactly
these keys:

| Record | Keys |
|---|---|
| An assignment | `event_id`, `session`, `slot`, `kickoff` |
| An unplaced event | `event_id`, `reason`, `participant` |
| A `ParticipantDoubleBooked` | `kind`, `participant`, `session`, `slot`, `event_ids` |
| An `EventUnplaced` | `kind`, `event_id`, `reason`, `participant` |
| A `ContiguityBroken` | `kind`, `participant`, `session`, `gap_slots`, `occupied_slots` |
| A `LateStart` | `kind`, `participant`, `session`, `first_slot` |
| A `CapacityExceeded` | `kind`, `participant`, `demand`, `capacity`, `shortfall` |

Each key holds what `toArray()` of the record holds under it: an event ID
as a string, a session, a slot and a count as integers, a participant as
its ID or null, a kind and a reason as their backing strings, the lists
as lists, and a kickoff as the string `2026-08-12T19:00:00Z` or null. The
keys are named because they are the scheme: a key that a `toArray()`
gains in a later version is not part of scheme `v1` and does not change a
`v1` fingerprint.

`COUNT`, `LENGTH-IN-BYTES` and `DECIMAL` are ASCII decimal digits with no
leading zeros, `DECIMAL` with a leading `-` when negative. A list is an
array whose keys are 0, 1, 2 and so on in order; any other array is a
map. What is put in byte order is the encoded bytes: of each record in a
set, and of each entry (its key and item together) in a map, so the entry
of the key `slot` (`s4:slot;...`) comes before that of `kickoff`
(`s7:kickoff;...`). Byte order compares unsigned bytes, a shorter string
before a longer one that begins with it. An outcome with nothing in it
has the document `"tactician.repack.outcome.v1\nl0:;l0:;l0:;"`, and one
assignment of the event `e1` to session 0, slot 2 with no kickoff is the
record `m4:s4:slot;i2;s7:kickoff;n;s7:session;i0;s8:event_id;s2:e1;;`.

What follows from that:

- The order of the three lists does not matter, and neither does the
  order of the keys of a record. The order of a list inside a record does
  (the occupied slots of a `ContiguityBroken`, the event IDs of a
  `ParticipantDoubleBooked`); the repacker always writes those ascending.
- Every field the records have today is covered. A participant is covered
  by its ID and by nothing else of it, so a changed label or changed
  metadata leaves the fingerprint as it was. A kickoff is covered to the
  second and is null for a shape-only grid.
- `isBudgetExhausted()` is not covered: two outcomes holding the same
  plan are the same plan, however each search ended.
- Nothing in it depends on the PHP version, the platform, the locale or
  an ini setting.
- A violation of a class of your own is covered through its `toArray()`,
  the whole of it, so its fingerprint is as stable as that method's
  result. If that returns something the encoding has no form for (an
  object, a resource), `fingerprint()` throws an `InvalidInputException`.

### Weight Bounds

The repacker scores a move as an integer of at most
`earlyFillWeight × (sessions − 1) + 2 × consolidationWeight`. Weights for
which that is larger than `PHP_INT_MAX` are rejected, because PHP would
compute the score as a float, in which a large weight swallows a small
one and the result is no longer the trade the weights state:

- `RepackOptions` rejects a consolidation weight above
  `RepackOptions::MAX_CONSOLIDATION_WEIGHT` (half of `PHP_INT_MAX`,
  rounded down) with the reason `ValueOutOfRange`.
- `RepackRequest`, which has the grid, rejects weights for which the
  whole expression is too large for that grid's number of sessions, with
  the reason `IncompatibleOptions`.

Weights of ordinary size are nowhere near either bound: on a 64-bit
build the defaults would need a grid of more than nine quintillion
sessions.

### Repack API Reference

Every public class of the `MissionGaming\Tactician\Repack` namespace and
every public method on it. The classes under `Repack\Internal` are not
public API.

#### `ScheduleRepacker`

| Method | Returns |
|---|---|
| `repack(RepackRequest $request)` | The `RepackOutcome`. Throws a `RepackViolationsException` only when the options ask for it and the outcome is not clean. |

#### `RepackRequest`

`new RepackRequest(array $movableEvents, array $pinnedEvents, SessionGrid $grid, RepackOptions $options = new RepackOptions())`.
The order of the two lists carries no meaning. Input that contradicts
itself throws an `InvalidConfigurationException`; see
[Configuration Errors](#configuration-errors).

| Method | Returns |
|---|---|
| `getMovableEvents()` | The movable events, as given |
| `getPinnedEvents()` | The pinned events, as given |
| `getGrid()` | The `SessionGrid` |
| `getOptions()` | The `RepackOptions` |

#### `MovableEvent`

`new MovableEvent(string $id, Participant $participantA, Participant $participantB)`.
The ID is not empty and the two participants differ.

| Method | Returns |
|---|---|
| `getId()` | The caller's ID of the event |
| `getParticipantA()` | The first participant |
| `getParticipantB()` | The second participant |
| `getParticipants()` | Both, as a list of two |

#### `PinnedEvent`

`new PinnedEvent(string $id, Participant $participantA, Participant $participantB, int $session, int $slot)`.

| Method | Returns |
|---|---|
| `getId()` | The caller's ID of the event |
| `getParticipantA()` | The first participant |
| `getParticipantB()` | The second participant |
| `getParticipants()` | Both, as a list of two |
| `getSession()` | The 0-based session the event is pinned in |
| `getSlot()` | The 0-based slot within that session |

#### `SessionGrid`

`new SessionGrid(array|int $sessionStarts, ?DateInterval $slotInterval, int $slotsPerSession = 1, array $slotsPerSessionOverrides = [], ?int $capacityPerSlot = 1)`
builds an instant-based grid from a list of session starts and a slot
interval. The integer and the null in the first two types are how
`shapeOnly()` reaches the constructor (a session count and no interval);
do not pass them yourself, call `shapeOnly()`. The constant `SessionGrid::UNBOUNDED` is
the plain-data word for an unbounded capacity.

| Method | Returns |
|---|---|
| `shapeOnly(int $sessions, int $slotsPerSession = 1, array $slotsPerSessionOverrides = [], ?int $capacityPerSlot = 1)` | A shape-only grid (static) |
| `fromArray(array $config)` | A grid of either form from plain data (static) |
| `toArray()` | The plain data `fromArray()` accepts |
| `hasInstants()` | False for a shape-only grid |
| `getSessionCount()` | How many sessions |
| `getSlotCount(int $session)` | How many slots that session has, overrides applied |
| `getPositionCount()` | How many positions the grid has in all |
| `hasPosition(int $session, int $slot)` | Whether the grid has that position |
| `ordinalOf(int $session, int $slot)` | The position's 0-based index in grid order |
| `positionOf(DateTimeImmutable\|int $at)` | `['session' => ..., 'slot' => ...]` for an ordinal or an instant, or null |
| `getCapacityLimit()` | Events per slot, or null when unbounded |
| `hasUnboundedCapacity()` | Whether the capacity is unbounded |
| `getCapacityPerSlot()` | Events per slot as an integer; throws an `UnavailableValueException` when unbounded |
| `getSessionStart(int $session)` | The session's start as declared; throws an `UnavailableValueException` on a shape-only grid |
| `getSlotInterval()` | The time between slots; throws an `UnavailableValueException` on a shape-only grid |
| `getSlotTime(int $session, int $slot)` | The slot's time in UTC; throws an `UnavailableValueException` on a shape-only grid |

#### `RepackOptions`

`new RepackOptions(int $consolidationWeight = 3, int $earlyFillWeight = 1, int $stepBudget = 200_000, bool $throwOnViolations = false)`.
The four are public read-only properties: `$consolidationWeight`,
`$earlyFillWeight`, `$stepBudget` and `$throwOnViolations`. The constant
`RepackOptions::MAX_CONSOLIDATION_WEIGHT` is the largest consolidation
weight.

| Method | Returns |
|---|---|
| `fromArray(array $config)` | Options from plain data with the keys `consolidation_weight`, `early_fill_weight`, `step_budget` and `throw_on_violations` (static) |
| `toArray()` | The same plain data |

#### `RepackOutcome`

The constant `RepackOutcome::FINGERPRINT_SCHEME` is the scheme
`fingerprint()` writes.

| Method | Returns |
|---|---|
| `getAssignments()` | A `SlotAssignment` for every placed movable event, ordered by event ID. Pinned events are not in it. |
| `getAssignmentFor(string $eventId)` | That event's `SlotAssignment`, or null when it is unplaced or unknown |
| `getUnplaced()` | An `UnplacedEvent` for every movable event with no position, ordered by event ID |
| `getViolations()` | Every `RepackViolation`, ordered by kind |
| `getViolationsOfKind(ViolationKind $kind)` | The violations of that kind |
| `getParticipantDoubleBookedViolations()` | The `ParticipantDoubleBooked` violations |
| `getEventUnplacedViolations()` | The `EventUnplaced` violations |
| `getContiguityBrokenViolations()` | The `ContiguityBroken` violations |
| `getLateStartViolations()` | The `LateStart` violations |
| `getCapacityExceededViolations()` | The `CapacityExceeded` violations |
| `isClean()` | Whether nothing is unplaced and there is no violation |
| `isBudgetExhausted()` | Whether the step budget stopped a search |
| `fingerprint()` | The outcome's fingerprint |
| `toArray()` | `['assignments' => ..., 'unplaced' => ..., 'violations' => ...]`, each the `toArray()` of its records |

Building one by hand takes the three lists and, optionally, the budget
flag: `new RepackOutcome(array $assignments, array $unplaced, array $violations, bool $budgetExhausted = false)`.
A list that holds anything other than the objects it is for throws an
`InvalidInputException`.

#### `SlotAssignment`

`new SlotAssignment(string $eventId, int $session, int $slot, ?DateTimeImmutable $kickoff)`.
The repacker builds these; the constructor is for an outcome built by
hand, in a test for example.

| Method | Returns |
|---|---|
| `getEventId()` | The movable event's ID |
| `getSession()` | The 0-based session |
| `getSlot()` | The 0-based slot within the session |
| `hasKickoff()` | False when the grid is shape-only |
| `getKickoff()` | The position's time in UTC; throws an `UnavailableValueException` when there is none |
| `toArray()` | `event_id`, `session`, `slot`, and `kickoff` as `2026-08-12T19:30:00Z` or null |

#### `UnplacedEvent`

`new UnplacedEvent(string $eventId, UnplacedReason $reason, ?Participant $participant = null)`.

| Method | Returns |
|---|---|
| `getEventId()` | The movable event's ID |
| `getReason()` | The `UnplacedReason` |
| `getParticipant()` | The participant with too many events, or null |
| `toArray()` | `event_id`, `reason` and `participant` (its ID, or null) |

#### `UnplacedReason`

An enum. Cases: `ParticipantOverCapacity` (`participant_over_capacity`)
and `NoSlotAvailable` (`no_slot_available`).

#### `ViolationKind`

An enum. Cases: `ParticipantDoubleBooked`, `EventUnplaced`,
`ContiguityBroken`, `LateStart` and `CapacityExceeded`, with the backing
strings in the table under [Reading the Outcome](#reading-the-outcome).

#### `RepackViolation`

The interface the five violation classes implement. A class of your own
may implement it too, in an outcome built by hand; the repacker returns
the five only. Such a violation is in `getViolations()` and in
`getViolationsOfKind()` for the kind it states, and in none of the five
typed accessors, each of which returns objects of its own class.

| Method | Returns |
|---|---|
| `getKind()` | The `ViolationKind` |
| `toArray()` | Plain data, always with a `kind` key holding the kind's backing string |

#### `ParticipantDoubleBooked`

`new ParticipantDoubleBooked(Participant $participant, int $session, int $slot, array $eventIds)`.

| Method | Returns |
|---|---|
| `getKind()` | `ViolationKind::ParticipantDoubleBooked` |
| `getParticipant()` | The participant booked twice |
| `getSession()` | The session of the position |
| `getSlot()` | The slot of the position |
| `getEventIds()` | The IDs of the events that collide, sorted |
| `toArray()` | `kind`, `participant`, `session`, `slot` and `event_ids` |

#### `EventUnplaced`

`new EventUnplaced(string $eventId, UnplacedReason $reason, ?Participant $participant = null)`.

| Method | Returns |
|---|---|
| `getKind()` | `ViolationKind::EventUnplaced` |
| `getEventId()` | The movable event's ID |
| `getReason()` | The `UnplacedReason` |
| `getParticipant()` | The participant with too many events, or null |
| `toArray()` | `kind`, `event_id`, `reason` and `participant` |

#### `ContiguityBroken`

`new ContiguityBroken(Participant $participant, int $session, int $gapSlots, array $occupiedSlots)`.

| Method | Returns |
|---|---|
| `getKind()` | `ViolationKind::ContiguityBroken` |
| `getParticipant()` | The participant whose slots have a gap |
| `getSession()` | The session |
| `getGapSlots()` | How many slots between the participant's first and last are empty |
| `getOccupiedSlots()` | The slots the participant occupies in the session, pins included, ascending |
| `toArray()` | `kind`, `participant`, `session`, `gap_slots` and `occupied_slots` |

#### `LateStart`

`new LateStart(Participant $participant, int $session, int $firstSlot)`.

| Method | Returns |
|---|---|
| `getKind()` | `ViolationKind::LateStart` |
| `getParticipant()` | The participant who starts late |
| `getSession()` | The session |
| `getFirstSlot()` | The first slot the participant occupies in it |
| `toArray()` | `kind`, `participant`, `session` and `first_slot` |

#### `CapacityExceeded`

`new CapacityExceeded(?Participant $participant, int $demand, int $capacity)`.

| Method | Returns |
|---|---|
| `getKind()` | `ViolationKind::CapacityExceeded` |
| `getParticipant()` | The participant with more events than free positions, or null when it is the grid that is too small |
| `getDemand()` | How many positions were needed |
| `getCapacity()` | How many there are |
| `getShortfall()` | How many events do not fit: the demand less the capacity, never below zero |
| `toArray()` | `kind`, `participant`, `demand`, `capacity` and `shortfall` |

See `examples/19-repacking-a-season.php` for a complete runnable
walkthrough, and `docs/design/schedule-repack.md` for the algorithm and
its design decisions.

## Schedule Quality and Optimization

Constraints are hard filters — a schedule satisfies them or generation
fails. **Quality metrics** measure the graded properties two valid
schedules can still differ on. Every metric follows one convention:
**lower is better, zero is ideal** — metrics measure defects:

| Metric | Measures |
|---|---|
| `RoleBalanceMetric` | How unevenly appearances split between the first role (home, white, server...) and the second |
| `RoleStreakMetric` | Longest run of consecutive same-role appearances beyond strict alternation |
| `RestSpreadMetric` | How irregularly the gaps between each participant's playing rounds vary |
| `PairingSpacingMetric` | How far each pair's repeat meetings deviate from an even spread across the schedule |

`ScheduleScorer` composes metrics with weights — which metrics matter is
your policy, the scorer is the arithmetic — and reports per-metric
values so a chosen schedule is explainable:

```php
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Quality\PairingSpacingMetric;
use MissionGaming\Tactician\Quality\RoleBalanceMetric;
use MissionGaming\Tactician\Quality\ScheduleScorer;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

// The schedule to grade: a single-leg round robin, in which each of the
// four participants has three events and so cannot split roles evenly
$participants = [
    new Participant('celtic', 'Celtic'),
    new Participant('athletic', 'Athletic Bilbao'),
    new Participant('livorno', 'AS Livorno'),
    new Participant('redstar', 'Red Star FC'),
];
$schedule = (new RoundRobinScheduler())->schedule($participants);

$scorer = new ScheduleScorer([
    ['metric' => new RoleBalanceMetric(), 'weight' => 3.0],
    ['metric' => new PairingSpacingMetric(), 'weight' => 1.0],
]);

$score = $scorer->score($schedule);   // 4.5: the weighted defect score (3.0 x 1.5 + 1.0 x 0.0)
$report = $scorer->report($schedule); // ['Role Balance' => 1.5, 'Pairing Spacing' => 0.0]
```

A weight is a positive, finite number, and every score is finite (zero is
the ideal one). The scorer
throws `InvalidConfigurationException` for a weight of `NAN` or `INF` when
it is built, and for a metric of your own that measures `NAN` or `INF`, or a
weighted sum that overflows, when it scores: a score that cannot be
compared cannot be ranked.

`ScheduleOptimizer` generates N candidates and keeps the best-scoring
one. The generators are deterministic given a randomizer, so sampling
schedules is sampling seeds — one master randomizer derives a child per
sample, and the same master seed always reproduces the same winner:

```php
use MissionGaming\Tactician\LegStrategies\ShuffledLegStrategy;
use MissionGaming\Tactician\Quality\ScheduleOptimizer;
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use Random\Engine\Mt19937;
use Random\Randomizer;

$optimizer = new ScheduleOptimizer($scorer, new Randomizer(new Mt19937(2026)));

$result = $optimizer->optimize(
    fn (Randomizer $r) => (new RoundRobinScheduler(null, $r))->schedule(
        $participants,
        new RoundRobinOptions(legs: 2, strategy: new ShuffledLegStrategy($r))
    ),
    25
);

$result->getSchedule();         // the winner
$result->getScore();            // its weighted score
$result->getReport();           // its per-metric breakdown
$result->getSamplesGenerated(); // candidates that generated successfully
```

Two rules of the mechanism:

- **Thread the child randomizer everywhere.** Determinism holds only if
  every randomness source in the generation pipeline uses the supplied
  child — the scheduler *and* anything else that randomizes (note the
  `ShuffledLegStrategy($r)` above). An unseeded source anywhere makes
  sampling unrepeatable.
- **Failed samples are skipped, not fatal.** A sample whose generation
  throws (a shuffled ordering no retry can fix) is counted in
  `getSamplesFailed()`; only zero valid candidates is an error, in which
  case the last generation failure is rethrown with its diagnostics.

Optimization applies to whole-schedule generators only — results-driven
engines (Swiss, elimination) pair from results that do not exist yet, so
there is nothing to sample up front. Custom metrics implement the
two-method `QualityMetric` interface.

## Serialization

Schedules round-trip through JSON; participants are listed once and
referenced by ID, so restored schedules share participant instances:

```php
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

$schedule = (new RoundRobinScheduler())->schedule([
    new Participant('celtic', 'Celtic'),
    new Participant('athletic', 'Athletic Bilbao'),
    new Participant('livorno', 'AS Livorno'),
    new Participant('redstar', 'Red Star FC'),
]);

$json = $schedule->toJson();
$restored = Schedule::fromJson($json);
```

`Participant`, `Round`, `Event`, and `Schedule` all expose
`toArray()`/`fromArray()` for custom persistence.

Text that is not valid JSON is reported by a `JsonConversionException` (a
`\JsonException`), and valid JSON or an array of the wrong shape by an
`InvalidInputException` (an `\InvalidArgumentException`). Both are covered by
`catch (TacticianException)`: see [Error Handling](#exception-hierarchy).

## Schedule Validation

Tactician includes comprehensive validation to ensure complete tournaments and prevent silent failures.

### Basic Validation

```php
use MissionGaming\Tactician\Constraints\ConsecutiveRoleConstraint;
use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

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
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;

try {
    $scheduler = new RoundRobinScheduler($constraints);
    $schedule = $scheduler->schedule($participants);
    
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

## Framework Integration

Tactician has zero production dependencies, so framework integration is
plain object wiring. Dedicated guides cover the full consumption
patterns — service registration, config translated through
`fromArray()`, persisting schedules and stage state, driving
results-driven engines across stateless requests, and kickoff
assignment:

- **[Symfony](integrations/symfony.md)**
- **[Laravel](integrations/laravel.md)**

The framework-free core of the pattern — a stage living across request
cycles with `StageState` serialized between them — is runnable as
[`examples/18-stateless-web-flow.php`](../examples/18-stateless-web-flow.php).

## Error Handling

### Constraint Attribution

A generation failure carries a probed analysis of *which constraint
blocks which pairing where* — not a guess from constraint names, an
evaluation: every missing pairing is tested against every configured
constraint in each candidate round and both orientations, against the
schedule that was actually generated. The diagnostic report renders it
directly:

```text
=== BLOCKED PAIRINGS ===
• Team 1 vs Team 2 cannot join the generated schedule in any round (blocked by: Derby Ban)

=== CONSTRAINT ATTRIBUTION ===
• Derby Ban rejects Team 1 vs Team 2 in 3 of 3 rounds
```

Programmatic access goes through the attached report. The configuration
below is the one that produces the report above — a round robin needs
every pair to meet, so banning one pairing cannot be satisfied:

```php
use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

$participants = [
    new Participant('team1', 'Team 1'),
    new Participant('team2', 'Team 2'),
    new Participant('team3', 'Team 3'),
    new Participant('team4', 'Team 4'),
];

// Team 1 and Team 2 may never meet
$derbyBan = ConstraintSet::create()
    ->custom(
        fn (Event $event) => !($event->hasParticipant($participants[0]) && $event->hasParticipant($participants[1])),
        'Derby Ban'
    )
    ->build();

$scheduler = new RoundRobinScheduler($derbyBan);

try {
    $schedule = $scheduler->schedule($participants);
} catch (IncompleteScheduleException $e) {
    $analysis = $e->getAnalysis(); // ?DiagnosticReport
    $analysis?->getImpossiblePairings();   // blocked everywhere, culprits named
    $analysis?->getConstraintViolations(); // per-constraint attribution
    $analysis?->getSuggestions();          // includes structural-fullness notes
}
```

Three findings, three vocabularies: **blocked pairings** no round will
accept (culprit constraints named — or "a combination of constraints"
when no single one rejects everywhere), per-constraint **attribution**
("rejects Alice vs Bob in 6 of 6 rounds" — a constraint is only charged
with rounds it rejects in both orientations), and **structural**
conflicts (a pairing whose only allowed rounds are already at capacity
is blocked by arithmetic, not by any constraint). Custom constraints
are attributed exactly like built-ins, because probing evaluates the
real predicates. The analysis answers "could this pairing join what was
built?" — it does not claim global unsatisfiability except where the
backtracking search proved it.

### Exception Hierarchy

Every exception the library throws on purpose implements the marker
interface `Exceptions\TacticianException`. It adds no method; each class
below it keeps a PHP parent type, so a catch clause written against that
parent type matches too.

| Class (in `MissionGaming\Tactician\Exceptions`) | Extends | Thrown when |
|------|------|------|
| `SchedulingException` (abstract) | `\Exception` | A scheduling failure. Its subclasses carry a diagnostic report (`getDiagnosticReport()`). |
| `InvalidConfigurationException` | `SchedulingException` | A scheduler, engine, stage, timeline or grid is configured in a way that cannot work. `getReason()` says which mistake it was: see [Configuration Errors](#configuration-errors). |
| `PinConflictException` | `InvalidConfigurationException` | A repack request pins one participant in two events at the same session and slot. It carries the two event IDs (`getEventIds()`). |
| `IncompleteScheduleException` | `SchedulingException` | Constraints leave a schedule that cannot be completed. |
| `NoValidPairingException` | `SchedulingException` | No complete pairing exists for a Swiss round. |
| `RepackViolationsException` | `SchedulingException` | A repack leaves violations and `RepackOptions(throwOnViolations: true)` asked for an exception instead of the outcome. |
| `InvalidInputException` | `\InvalidArgumentException` | An argument is outside its allowed range, or the data given to a `fromArray()` or `fromJson()` method is malformed: a missing field, a value of the wrong type, an unknown participant ID. |
| `JsonConversionException` | `\JsonException` | A `fromJson()` method is given text that is not valid JSON, or a `toJson()` method meets a value JSON cannot represent. The message and code are PHP's; the PHP exception is the previous one. |
| `InvariantViolationException` | `\LogicException` | The library reached a state its own logic rules out. It reports a defect in the library, not a mistake in the input. |
| `UnavailableValueException` | `\LogicException` | An object was asked for a value it does not hold: a time from a shape-only `SessionGrid`, the kickoff of an assignment made on one, the capacity of an unbounded grid as a number. It reports a mistake in the calling code, which the object's `has...()` method (named in the message) would have prevented. It is not a configuration error and not a library defect. |

`SchedulingException` is the base of the scheduling failures only. A rejected
argument or malformed data is an `InvalidInputException`, which is not a
`SchedulingException`: catch `TacticianException` for both.

The scheduling failures are told apart by class:

```php
use MissionGaming\Tactician\Exceptions\SchedulingException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;

try {
    $schedule = $scheduler->schedule($participants);
    
} catch (InvalidConfigurationException $e) {
    // Handle configuration errors
    handleConfigurationError($e);
    
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

### Configuration Errors

An `InvalidConfigurationException` says what is wrong in four ways, each for
a different reader:

| Accessor | Returns | For |
|------|------|------|
| `getReason()` | An `Exceptions\InvalidConfigurationReason` case, or null for an exception that code outside the library built without one | Code that has to tell one mistake from another. Do not match the message text. |
| `getContext()` | The values involved, keyed by name | Code that needs the values: the IDs, the counts, the setting that was rejected |
| `getRequirements()` | What the failing component requires, one statement per entry; empty when it states none | A screen that lists the rules next to the error |
| `getDiagnosticReport()` | The issue, every context value and the requirements, as text | An operator, a log |

The message (`getMessage()`) is a sentence for a person. Most messages are
the same for every instance of a mistake and name no event and no
participant (every repack request error is like that); a few write a value
into the sentence, such as the labels of a drawn tie. Either way, read the
values from the context and the report, and tell mistakes apart by the
reason.

A repack request that pins one participant in two events at the same session
and slot throws `PinConflictException`, a subclass that carries the two event
IDs. The participants are the four from
[Constraint Attribution](#constraint-attribution):

```php
use MissionGaming\Tactician\Exceptions\PinConflictException;
use MissionGaming\Tactician\Repack\PinnedEvent;
use MissionGaming\Tactician\Repack\RepackRequest;
use MissionGaming\Tactician\Repack\SessionGrid;

$grid = SessionGrid::fromArray([
    'sessions' => ['2026-08-12 20:00'],
    'timezone' => 'UTC',
    'slot_interval' => 'PT25M',
    'slots_per_session' => 4,
    'capacity_per_slot' => 2,
]);

try {
    // Team 1 is pinned twice at session 0, slot 3
    new RepackRequest(
        movableEvents: [],
        pinnedEvents: [
            new PinnedEvent('e1', $participants[0], $participants[1], 0, 3),
            new PinnedEvent('e2', $participants[2], $participants[0], 0, 3),
        ],
        grid: $grid
    );
} catch (PinConflictException $e) {
    echo 'Unpin one of: ' . implode(', ', $e->getEventIds()) . "\n";
    echo 'Reason: ' . $e->getReason()?->value . "\n";
    echo $e->getDiagnosticReport() . "\n";
}
```

```text
Unpin one of: e1, e2
Reason: pin_conflict
=== INVALID CONFIGURATION DIAGNOSTIC REPORT ===

Issue: A participant is pinned twice at one position

=== CONFIGURATION DETAILS ===
• participant: team1
• session: 0
• slot: 3
• event_ids: ["e1", "e2"]
```

The grid above holds two events per slot. A request checks the capacity of a
slot before it checks participants, so on a grid whose slots hold one event
(the default) the same two pins are reported as `PinCapacityExceeded`, by an
`InvalidConfigurationException` whose context has the session, the slot and
the capacity, and no event ID.

`PinConflictException` also has `getParticipantId()`, `getSession()` and
`getSlot()`. It is an `InvalidConfigurationException`, so a catch clause
written for that class matches it, and its message is the one that class
gave before the subclass existed.

For every other configuration error, branch on the reason. A `match` over it
needs a `default` arm, because a release may add a case:

```php
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Timeline\TimelineDefinition;

try {
    TimelineDefinition::fromArray([
        'start' => '2026-08-01 19:00:00',
        'timezone' => 'Europe/Edinburg',
        'round_interval' => 'P7D',
    ]);
} catch (InvalidConfigurationException $e) {
    echo match ($e->getReason()) {
        InvalidConfigurationReason::UnparseableTime => 'Check the date, the timezone name and the durations.',
        InvalidConfigurationReason::TimezoneMismatch => 'Remove the offset from the date: the timezone field decides.',
        default => 'See the report.',
    } . "\n";
    echo $e->getDiagnosticReport() . "\n";
}
```

```text
Check the date, the timezone name and the durations.
=== INVALID CONFIGURATION DIAGNOSTIC REPORT ===

Issue: start or its timezone is not parseable

=== CONFIGURATION DETAILS ===
• start: 2026-08-01 19:00:00
• timezone: Europe/Edinburg
```

A timezone string that PHP cannot use at all (one that holds a NUL byte, as
JSON-decoded configuration can) is reported the same way, with the reason
`UnparseableTime`. So is a datetime that does not state an instant by
itself, such as `tomorrow`, `20:00` or `2026-02-30 20:00` (see
[Timeline Assignment](#timeline-assignment) for the form a datetime takes).

The report writes a list value out in full, in its own order, with strings in
double quotes (`event_ids: ["e1", "e2"]`) and with keys where the array is not
a list (`[from: "2026-01-01", to: "2026-01-02"]`). Inside the quotes a double
quote and a backslash are written with a backslash before them and a control
character as its C escape (`\n`, `\000`), so one entry is one quoted run on
one line. An object is written as its
class name. Two bounds keep it readable: a list of more than 20 entries is cut
after the twentieth and followed by the number left out
(`... 80 more of 100`), and a list nested more than three levels deep is
written as its size (`[2 items]`). An empty list is `[0 items]`.

The "REQUIREMENTS" block of the report belongs to the component that failed.
An error from the round-robin scheduler, its options or its plan lists the
five round-robin requirements
(`InvalidConfigurationException::ROUND_ROBIN_REQUIREMENTS`). An error from a
component that states no requirements, such as the two above, has no such
block. An `InvalidConfigurationException` that your own code builds with
neither a reason nor requirements keeps the round-robin block it carried
before reasons existed; pass `requirements: []` or a list of your own to
change that.

The reasons, with the string each is backed by (`$reason->value`, a stable
identifier for logs and stored data):

| Case | Value | The mistake |
|------|------|------|
| `TooFewParticipants` | `too_few_participants` | Fewer participants than the format, or one pool of it, needs |
| `DuplicateParticipantIds` | `duplicate_participant_ids` | Two participants in one list share an ID |
| `InvalidLegCount` | `invalid_leg_count` | The number of legs is not an integer the format accepts |
| `InvalidRoundCount` | `invalid_round_count` | The number of rounds is not a positive integer, or is more than the format can provide |
| `UnsupportedOptions` | `unsupported_options` | A scheduler was given the options object of another format |
| `IncompatibleOptions` | `incompatible_options` | Two valid settings cannot be used together, or one needs another that is missing |
| `UnknownIdentifier` | `unknown_identifier` | A strategy, algorithm or mode string is not a known identifier |
| `WrongValueType` | `wrong_value_type` | A value has the wrong type, or a required key of plain-data configuration is missing |
| `ValueOutOfRange` | `value_out_of_range` | A number is outside the range allowed for it |
| `EmptyList` | `empty_list` | A list that needs at least one entry is empty |
| `DuplicateName` | `duplicate_name` | Two entries of one list carry the same name |
| `NotSerializable` | `not_serializable` | The configuration has no plain-data form to serialize to |
| `UnsatisfiableLegStrategy` | `unsatisfiable_leg_strategy` | The leg strategy cannot produce the legs asked for |
| `UnknownOptionKey` | `unknown_option_key` | Plain-data configuration holds a key the options do not have |
| `OddParticipantCount` | `odd_participant_count` | The format has every participant in every round, issues no bye, and was given an odd number of participants |
| `UnequalPots` | `unequal_pots` | The participants do not divide into the number of pots asked for |
| `TooManyOpponentsPerPot` | `too_many_opponents_per_pot` | More opponents are asked from one pot than a pot has other members |
| `OddPotWithOddOpponents` | `odd_pot_with_odd_opponents` | A pot of odd size cannot hold an odd number of events per member inside itself |
| `ConfigurationNotYetSupported` | `configuration_not_yet_supported` | The configuration is feasible and the library has no construction for it yet |
| `BracketComplete` | `bracket_complete` | A further round was asked of a finished bracket |
| `RoundPartiallyResolved` | `round_partially_resolved` | The next round was asked for while ties of the current one have no complete result |
| `EventWithoutRoundNumber` | `event_without_round_number` | An event has no round number where one is required |
| `InvalidResult` | `invalid_result` | A result cannot belong to the stage it was recorded in |
| `DuplicateResult` | `duplicate_result` | Two results were recorded for the same match |
| `UndecidedTie` | `undecided_tie` | A tie that must produce a winner has none |
| `IncompatibleOutcome` | `incompatible_outcome` | A progression selector was given an outcome of a shape it cannot read |
| `RankUnavailable` | `rank_unavailable` | A progression selector asked for a rank the standings do not have |
| `RoundOutOfSequence` | `round_out_of_sequence` | A round was recorded out of play order: its number is not above the last recorded one |
| `EventNotInRound` | `event_not_in_round` | An event, or the event of a result, does not belong to the round it was recorded with (one with no round number at all is `EventWithoutRoundNumber`) |
| `NoRoundRecorded` | `no_round_recorded` | Results were added or replaced in a stage with no recorded round |
| `ResultNotRecorded` | `result_not_recorded` | A result was to be replaced for an event that has no recorded result |
| `RoundSuperseded` | `round_superseded` | A result was to be replaced in a round that a later round was paired from |
| `EmptyEngineFingerprint` | `empty_engine_fingerprint` | A stage state was to be stamped with an empty engine fingerprint |
| `EngineFingerprintMismatch` | `engine_fingerprint_mismatch` | A stage state carries the fingerprint of another engine or configuration than the one reading it |
| `EmptyEventId` | `empty_event_id` | A movable or pinned event has an empty ID |
| `IdenticalParticipants` | `identical_participants` | An event names the same participant on both sides |
| `DuplicateEventId` | `duplicate_event_id` | Two events of one repack request share an ID |
| `PinOffGrid` | `pin_off_grid` | A pinned event sits at a position the grid does not have |
| `PinCapacityExceeded` | `pin_capacity_exceeded` | More events are pinned at one position than a slot can hold |
| `PinConflict` | `pin_conflict` | One participant is pinned in two events at one position (`PinConflictException`) |
| `PositionOutOfRange` | `position_out_of_range` | A session, slot, round or resource index is outside the grid or timeline |
| `UnparseableTime` | `unparseable_time` | A datetime, its timezone or an ISO 8601 duration cannot be parsed, or the datetime is relative to the current time, has no date or no year, or does not mean what it writes |
| `TimezoneMismatch` | `timezone_mismatch` | A time carries a timezone that contradicts the declared one |
| `NonAdvancingTime` | `non_advancing_time` | An interval, a window or a sequence of starts does not move time forward |
| `TimeRuleViolation` | `time_rule_violation` | The assigned timeline breaks a time rule |
| `TimelineCapacityExceeded` | `timeline_capacity_exceeded` | A round has more events than the timeline has places for |
| `ConstraintViolation` | `constraint_violation` | Built by `SchedulingException::constraintViolation()` |
| `InvalidSchedule` | `invalid_schedule` | Built by `SchedulingException::invalidSchedule()` |

A case says what kind of mistake was made, not which component found it:
`TooFewParticipants` comes from the round-robin scheduler, the Swiss engine
and the elimination engines alike. Every configuration error the library
raises states one; `getReason()` returns null only for an exception that
code outside the library builds without a reason.

### Catching Every Library Exception

One clause catches everything in the table above. The three calls below fail
in three different ways (the scheduler is the one from
[Constraint Attribution](#constraint-attribution), whose constraint no
schedule can satisfy):

```php
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\TacticianException;

$attempts = [
    'A schedule the constraints rule out' => fn () => $scheduler->schedule($participants),
    'A round numbered zero' => fn () => new Round(0),
    'JSON that is cut short' => fn () => Schedule::fromJson('{"participants": ['),
];

foreach ($attempts as $what => $attempt) {
    try {
        $attempt();
    } catch (TacticianException $e) {
        echo $what . ': ' . $e::class . "\n";
    }
}
```

```text
A schedule the constraints rule out: MissionGaming\Tactician\Exceptions\IncompleteScheduleException
A round numbered zero: MissionGaming\Tactician\Exceptions\InvalidInputException
JSON that is cut short: MissionGaming\Tactician\Exceptions\JsonConversionException
```

A catch clause for the PHP parent type still matches, with the same message.
Code written before the marker existed needs no change:

```php
try {
    new Round(0);
} catch (\InvalidArgumentException $e) {
    echo $e->getMessage() . "\n"; // Round number must be positive
}

try {
    Schedule::fromJson('{"participants": [');
} catch (\JsonException $e) {
    echo $e->getMessage() . "\n"; // Syntax error
}
```

#### What the marker does not cover

`TacticianException` marks what the library reports. These reach the caller
unchanged, because the library does not raise them:

- **An exception from code the caller supplied**: a custom constraint
  predicate, a role extractor or metadata validator, a `RankingStrategy`,
  tiebreaker, leg strategy or quality metric of the caller's own, and the
  `jsonSerialize()` method of an object placed in metadata.
- **A failure of the random source**: `Random\RandomException` or
  `Random\BrokenRandomEngineError` from the `Random\Randomizer` passed to a
  scheduler, engine or optimizer (or from the unseeded one
  `ShuffledLegStrategy` creates when it is given none).
- **PHP's `\Error` family**: a `\TypeError` for an argument of the wrong
  type, for example.

## Advanced Patterns

### Custom Schedulers

The class below is a sketch of the shape, not a complete scheduler: it
leaves out `getPlan()` and the generation itself.

<!-- snippet: skip reason="A sketch of a custom scheduler: it omits getPlan() and generateCustomEvents(), so the class cannot be declared." -->
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
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Scheduling\SwissScheduler;
use MissionGaming\Tactician\Scheduling\SwissOptions;

$participants = [
    new Participant('celtic', 'Celtic'),
    new Participant('athletic', 'Athletic Bilbao'),
    new Participant('livorno', 'AS Livorno'),
    new Participant('redstar', 'Red Star FC'),
];

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
$swissPlan = (new SwissScheduler())->getPlan($participants, new SwissOptions(rounds: 3));
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
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Scheduling\SchedulingContext;

$existingEvents = [new Event([$participants[0], $participants[1]], new Round(1))];

// Create context with participants, the stage plan, and existing events
$context = new SchedulingContext(
    $participants,
    $plan,
    $existingEvents,
    currentLeg: 1,
    participantsPerEvent: 2
);

// Shape facts come from the plan
$totalRounds = $context->getPlan()->getTotalRounds();

// Check if participants have played together
$havePlayed = $context->haveParticipantsPlayed($participants[0], $participants[1]); // true

// Get events for a specific participant
$playerEvents = $context->getEventsForParticipant($participants[0]);

// Add new events to context (contexts are immutable: this returns a new one)
$newEvent = new Event([$participants[2], $participants[3]], new Round(1));
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

Each example below is complete in itself and repeats the imports it needs.

### Premier League Style Tournament

```php
use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Constraints\MinimumRestPeriodsConstraint;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\LegStrategies\MirroredLegStrategy;
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

// 20 teams in seeding order, home and away legs
$clubs = [
    'Manchester City', 'Arsenal', 'Liverpool', 'Aston Villa', 'Tottenham',
    'Chelsea', 'Newcastle', 'Manchester United', 'West Ham', 'Crystal Palace',
    'Brighton', 'Bournemouth', 'Fulham', 'Wolves', 'Everton',
    'Brentford', 'Nottingham Forest', 'Leicester', 'Ipswich', 'Southampton',
];

$teams = [];
foreach ($clubs as $position => $club) {
    $teams[] = new Participant(strtolower(str_replace(' ', '-', $club)), $club, $position + 1);
}

$constraints = ConstraintSet::create()
    ->noRepeatPairings()
    ->add(new MinimumRestPeriodsConstraint(5))  // A pair's two meetings are at least 5 rounds apart
    ->build();

$scheduler = new RoundRobinScheduler($constraints);

// Generate full season: 2 legs (home and away)
$season = $scheduler->schedule(
    $teams,
    new RoundRobinOptions(legs: 2, strategy: new MirroredLegStrategy())
);

echo "Premier League season: " . count($season) . " matches\n";
// 380 matches: each of the 20 teams hosts each of the other 19 once
```

### Gaming Tournament with Skill Brackets

A rule that forbids some pairings outright cannot be combined with a round
robin, which needs every pair to meet. Swiss pairing has no such
requirement, so it is the format for a bracketed field:

```php
use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Constraints\MetadataConstraint;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\SwissOptions;
use MissionGaming\Tactician\Scheduling\SwissScheduler;

// Entrants in sign-up order, which is the order Swiss pairing works from.
// It mixes the tiers: left to itself, Swiss pairing would put a
// professional against an amateur four times in three rounds
$players = [
    new Participant('pro1', 'ProGamer1', 1, ['tier' => 3]),
    new Participant('am1', 'Amateur1', 2, ['tier' => 1]),
    new Participant('pro2', 'ProGamer2', 3, ['tier' => 3]),
    new Participant('am2', 'Amateur2', 4, ['tier' => 1]),
    new Participant('semi1', 'SemiPro1', 5, ['tier' => 2]),
    new Participant('semi2', 'SemiPro2', 6, ['tier' => 2]),
    new Participant('semi3', 'SemiPro3', 7, ['tier' => 2]),
    new Participant('semi4', 'SemiPro4', 8, ['tier' => 2]),
];

// Only players of the same or adjacent skill tiers may meet, so the
// professionals (tier 3) never play the amateurs (tier 1). The values
// must be numeric: requireAdjacentValues() ignores non-numeric values
$constraints = ConstraintSet::create()
    ->add(MetadataConstraint::requireAdjacentValues('tier'))
    ->build();

$tournament = (new SwissScheduler($constraints))->schedule($players, new SwissOptions(rounds: 3));

echo count($tournament) . " matches\n"; // 12 matches: 4 per round, none of them professional v amateur
```

Asking a round robin for the same rule fails loudly instead of dropping
the four professional-versus-amateur matches (this block is expected to
throw, and the documentation test asserts that it does):

<!-- snippet: throws="MissionGaming\Tactician\Exceptions\IncompleteScheduleException" -->
```php
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

// Throws IncompleteScheduleException
(new RoundRobinScheduler($constraints))->schedule($players);
```

### Corporate Team Building Tournament

Pairing departments across buildings only is another rule a round robin
cannot honour — Engineering and Sales share a building and would have to
meet — so this is Swiss as well:

```php
use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Constraints\MetadataConstraint;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\SwissOptions;
use MissionGaming\Tactician\Scheduling\SwissScheduler;

$departments = [
    new Participant('eng', 'Engineering', 1, ['location' => 'Building A']),
    new Participant('mark', 'Marketing', 2, ['location' => 'Building B']),
    new Participant('sales', 'Sales', 3, ['location' => 'Building A']),
    new Participant('hr', 'Human Resources', 4, ['location' => 'Building B']),
];

// Cross-building pairings only
$constraints = ConstraintSet::create()
    ->add(MetadataConstraint::requireDifferentValues('location'))
    ->build();

// Each department has two possible opponents, so the tournament is two rounds
$teamBuilding = (new SwissScheduler($constraints))->schedule($departments, new SwissOptions(rounds: 2));

foreach ($teamBuilding as $event) {
    [$first, $second] = $event->getParticipants();
    echo "Round {$event->getRound()?->getNumber()}: {$first->getLabel()} vs {$second->getLabel()}\n";
}
```

### Home-and-Away League with Spaced Return Fixtures

```php
use MissionGaming\Tactician\Constraints\ConsecutiveRoleConstraint;
use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Constraints\MinimumRestPeriodsConstraint;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\LegStrategies\MirroredLegStrategy;
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

$teams = [
    new Participant('team1', 'Team Alpha'),
    new Participant('team2', 'Team Beta'),
    new Participant('team3', 'Team Gamma'),
    new Participant('team4', 'Team Delta'),
    new Participant('team5', 'Team Epsilon'),
    new Participant('team6', 'Team Zeta'),
];

// MinimumRestPeriodsConstraint spaces out repeat meetings of the same
// pair: it only matters when there is more than one leg
$constraints = ConstraintSet::create()
    ->add(new MinimumRestPeriodsConstraint(4))  // A pair's return fixture is at least 4 rounds later
    ->add(ConsecutiveRoleConstraint::homeAway(2))  // Max 2 consecutive home/away
    ->build();

try {
    $scheduler = new RoundRobinScheduler($constraints);
    $tournament = $scheduler->schedule(
        $teams,
        new RoundRobinOptions(legs: 2, strategy: new MirroredLegStrategy())
    );

    echo "Tournament scheduled successfully!\n";
    echo "Total matches: " . count($tournament) . "\n";                             // 30
    echo "Total rounds: " . $tournament->getMetadataValue('total_rounds') . "\n";   // 10

} catch (IncompleteScheduleException $e) {
    echo "Could not schedule with current constraints\n";
    echo "Try reducing the minimum rest period or relaxing the consecutive role limit\n";
}
```

## Performance Considerations

### Iterating and Counting

A `Schedule` holds every one of its events in memory, as an array:
generation builds the whole schedule before returning it, and nothing is
loaded lazily or released as you iterate. A schedule is iterable and
countable, so you can loop over it and count it directly — that is a
convenience, not a memory saving. Schedules are small in practice (a
20-participant double round robin is 380 events); if memory does matter,
it is a question of how your application stores and pages events, not of
how you iterate.

```php
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

$manyParticipants = [];
for ($i = 1; $i <= 16; ++$i) {
    $manyParticipants[] = new Participant("p{$i}", "Player {$i}");
}

$largeSchedule = (new RoundRobinScheduler())->schedule($manyParticipants);

// Iterate the schedule directly, in generated order
foreach ($largeSchedule as $event) {
    // ...process the event...
}

// Count the events: 16 participants meeting once each
$totalEvents = count($largeSchedule); // 120

// The same events as a plain array, or grouped by round number
$events = $largeSchedule->getEvents();
$eventsByRound = $largeSchedule->getEventsByRound();
```

### Constraint Optimization

Constraints are evaluated in the order they were added, and evaluation
stops at the first one that rejects an event:

```php
use MissionGaming\Tactician\Constraints\ConsecutiveRoleConstraint;
use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Constraints\MetadataConstraint;
use MissionGaming\Tactician\Constraints\MinimumRestPeriodsConstraint;

// Put the constraints most likely to reject an event first, so the
// later ones are evaluated less often
$optimizedConstraints = ConstraintSet::create()
    ->add(new MinimumRestPeriodsConstraint(3))  // The one you expect to reject most often
    ->add(ConsecutiveRoleConstraint::homeAway(2))
    ->add(MetadataConstraint::requireSameValue('division'))
    ->noRepeatPairings()  // The one you expect to reject least often
    ->build();
```

This guide covers the essential patterns for using Tactician effectively. For more advanced use cases or when contributing to the library, see the [Architecture documentation](ARCHITECTURE.md) and [Contributing guidelines](CONTRIBUTING.md).
