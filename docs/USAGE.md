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
- [Pools, Progression, and Multi-Stage Tournaments](#pools-progression-and-multi-stage-tournaments)
- [Timeline Assignment](#timeline-assignment)
- [Schedule Quality and Optimization](#schedule-quality-and-optimization)
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
| **Stage** | One phase of a multi-stage tournament (e.g. a group stage feeding a knockout) — Tactician's unit of work: participants in, a schedule or round-by-round pairings out, a `StageOutcome` when play completes. Stages compose via pools and progression selectors. |
| **Pool** | A bucket of participants (`PoolDistributor::serpentine()`): what format the bucket plays, how it is scored, and how it progresses are separate, configurable concerns. |
| **Progression selector** | The hand-off between stages (`ProgressionSelector`): consumes a `StageOutcome`, returns the ordered entrant list of the destination stage. Standings-based (`RankRangeSelector`) or outcome-based (`MatchOutcomeSelector`) — never winners reconstructed through points arithmetic. Optional machinery: a bare ordered list is always a valid stage entry. |
| **Tie (elimination)** | One knockout pairing, played over one event or two mirrored legs (`legsPerTie`). A level two-legged tie is decided by the application's aggregate rules and recorded as `tie_winner` metadata on a leg result. Ties are not legs: brackets have no legs concept. |
| **Options** | The typed per-algorithm configuration object a scheduler accepts (`RoundRobinOptions`, `SwissOptions`): legs mean legs, rounds mean rounds, and passing another algorithm's options fails loudly. All options are plain-data constructible (`fromArray()`/`toArray()`) with stable identifiers for config-driven platforms. |
| **Stage engine** | A results-driven pairing engine (`StageEngineInterface`): it consumes a `StageState` and produces the next `RoundPairing`, reports structural completion (`isComplete()`), and yields the `StageOutcome`. One driver loop covers every engine-based format. |
| **Stage state** | The serializable record of a results-driven stage between rounds (`StageState`): active participants, recorded pairings (with byes), and results. Pairings count as played even without results; withdrawals are `withoutParticipant()`. |
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
$options->toArray(); // ['legs' => 2, 'strategy' => 'mirrored', 'backtracking' => false]
```

### Backtracking Generation

The greedy generator retries bounded rotated orderings when constraints
reject a schedule — but the circle method fixes which pairings share a
round purely by list order, so it only ever sees a handful of round
decompositions, and some satisfiable constraint sets fail every one of
them. **Backtracking generation** searches the decompositions the
rotations cannot reach:

```php
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

`SwissPairingEngine` is a **stage engine** (`StageEngineInterface`): it
pairs one round at a time from the recorded `StageState`. Participants are
ordered by standings and paired adjacently (Monrad style), backtracking
past repeat pairings. Byes rotate to the lowest-placed participant with
the fewest so far and are credited as wins when ordering the next round.

Every results-driven format shares one driver loop — the single
integration a platform writes:

```php
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Scheduling\SwissPairingEngine;
use MissionGaming\Tactician\Stage\StageState;

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

## Elimination Brackets

The elimination engines are **presets** — canned compositions of
single-round knockout stages behind the same stage driver loop as Swiss.
Entry pairing folds by **list position** (position 1 is the top entrant;
positions 1 and 2 land in opposite halves), fields that are not a power of
two give byes to the top positions, and every round carries a label.

```php
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Scheduling\SingleEliminationEngine;
use MissionGaming\Tactician\Stage\MatchOutcomeSelector;
use MissionGaming\Tactician\Stage\StageState;

$engine = new SingleEliminationEngine();

$state = StageState::start($participants); // list order = seeding order
while (!$engine->isComplete($state)) {
    $pairing = $engine->pairNextRound($state);
    echo "{$pairing->getLabel()}\n"; // 'quarterfinal', 'semifinal', 'final', ...

    $results = [];
    foreach ($pairing->getEvents() as $event) {
        // ...play the match; single-leg elimination results cannot be draws...
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
  result. Per-leg results remain ordinary results feeding standings.

`DoubleEliminationEngine` adds a losers bracket and a grand final: everyone
must lose twice to be eliminated, so when the losers champion wins the grand
final a reset match decides the title (disable with
`new DoubleEliminationEngine(new EliminationOptions(grandFinalReset: false))`).
Conflicting, duplicate, or round-less results are rejected with clear
errors; partially recorded rounds are completed with
`$state->withAdditionalResults([...])`.

## Pools, Progression, and Multi-Stage Tournaments

A **pool** is just a bucket of participants: what format the bucket plays,
how it is scored, and how it progresses are separate concerns. The retired
group-stage monolith is now a composition of generic primitives:

```php
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Scheduling\SingleEliminationEngine;
use MissionGaming\Tactician\Stage\PoolDistributor;
use MissionGaming\Tactician\Stage\RankRangeSelector;
use MissionGaming\Tactician\Stage\StageOutcome;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Standings\StandingsCalculator;

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
use MissionGaming\Tactician\Timeline\TimelineAssigner;
use MissionGaming\Tactician\Timeline\TimelineDefinition;

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
use MissionGaming\Tactician\Quality\PairingSpacingMetric;
use MissionGaming\Tactician\Quality\RoleBalanceMetric;
use MissionGaming\Tactician\Quality\ScheduleScorer;

$scorer = new ScheduleScorer([
    ['metric' => new RoleBalanceMetric(), 'weight' => 3.0],
    ['metric' => new PairingSpacingMetric(), 'weight' => 1.0],
]);

$score = $scorer->score($schedule);   // weighted defect score
$report = $scorer->report($schedule); // ['Role Balance' => 0.667, ...]
```

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

Programmatic access goes through the attached report:

```php
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;

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

```php
use MissionGaming\Tactician\Exceptions\SchedulingException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;

try {
    $scheduler = new RoundRobinScheduler($constraints);
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
use MissionGaming\Tactician\Scheduling\SwissScheduler;
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
