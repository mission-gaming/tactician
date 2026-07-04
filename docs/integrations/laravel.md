# Using Tactician in a Laravel Application

Tactician has zero production dependencies and no framework awareness —
integration is plain object wiring. This guide mirrors the
[Symfony guide](symfony.md) with Laravel idioms: container bindings,
config files translated through `fromArray()`, Eloquent persistence of
serialized state, and a queue-friendly round-pairing flow. The
Tactician-facing patterns are identical in both guides — only the
wiring differs — and every snippet touching Tactician's API is executed
by the test suite's snippet harness before it ships.

## Container bindings

The schedulers and engines are stateless — bind them once in a service
provider. Per-competition choices (constraints, options) belong at call
sites, not in the container:

```php
// app/Providers/TacticianServiceProvider.php
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Scheduling\SwissPairingEngine;
use MissionGaming\Tactician\Timeline\TimelineAssigner;

public function register(): void
{
    $this->app->singleton(RoundRobinScheduler::class);
    $this->app->singleton(TimelineAssigner::class);

    // A competition with deploy-time rules can bind a preconfigured engine
    $this->app->when(SwissStageController::class)
        ->needs(SwissPairingEngine::class)
        ->give(fn () => new SwissPairingEngine(plannedRounds: 9));
}
```

## Configuration through fromArray()

Options, timelines, and time rules are plain-data constructible, so a
Laravel config file maps straight onto the library:

```php
// config/tactician.php
return [
    'league' => [
        'options' => [
            'legs' => 2,
            'strategy' => 'mirrored',
            'backtracking' => true,
        ],
        'timeline' => [
            'start' => '2026-08-01 18:00:00',
            'timezone' => 'Europe/London',
            'round_interval' => 'P7D',
            'slots_per_round' => 3,
            'slot_interval' => 'PT1H',
        ],
    ],
];
```

```php
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
use MissionGaming\Tactician\Timeline\TimelineDefinition;

$options = RoundRobinOptions::fromArray(config('tactician.league.options'));
$timeline = TimelineDefinition::fromArray(config('tactician.league.timeline'));
```

Invalid configuration throws `InvalidConfigurationException` with the
offending values in `getContext()` — report it, don't rescue it.

## Generating and persisting fixtures

Translate models to `Participant`s at the boundary (stringified keys),
generate, and either store the serialized schedule or hydrate your own
fixture models from the round-grouped view — keeping **round identity**,
which timeline assignment and per-round operations need later:

```php
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

$participants = $teams
    ->sortBy('seed') // position is authoritative: position 1 = seed 1
    ->map(fn ($team) => new Participant((string) $team->id, $team->name))
    ->values()
    ->all();

$schedule = (new RoundRobinScheduler())
    ->schedule($participants, RoundRobinOptions::fromArray(config('tactician.league.options')));

// Store whole (JSON column + accessor) ...
$stage->schedule_json = $schedule->toJson();

// ... or hydrate fixture models per round
foreach ($schedule->getEventsByRound() as $roundNumber => $events) {
    foreach ($events as $event) {
        [$home, $away] = $event->getParticipants();
        // Fixture::create([...: (int) $home->getId(), (int) $away->getId(), $roundNumber])
    }
}
```

Rehydration is symmetric: `Schedule::fromJson($stage->schedule_json)`.

## Driving a Swiss stage across requests and jobs

Results-driven engines pair one round at a time from recorded results.
`StageState` serializes to JSON, so the flow survives stateless HTTP
requests and queued jobs alike — the state column is the whole story:

```php
use MissionGaming\Tactician\Scheduling\SwissPairingEngine;
use MissionGaming\Tactician\Stage\StageState;

// Opening the stage (controller or job)
$state = StageState::start($participants);
$stage->state_json = $state->toJson();

// Pairing the next round (e.g. a queued job after results close)
$state = StageState::fromJson($stage->state_json);
$engine = new SwissPairingEngine(plannedRounds: 5);

if (!$engine->isComplete($state)) {
    $pairing = $engine->pairNextRound($state);
    // present $pairing->getEvents(), collect $results ...
    $state = $state->withRoundPlayed($pairing, $results);
    $stage->state_json = $state->toJson();
}

$outcome = $engine->getOutcome($state); // standings, qualifiers, pools
```

Record results against the events the engine produced — round numbers
are engine-assigned. Guard the load-pair-save cycle with a lock
(`Cache::lock()` or a `lockForUpdate()` transaction); pairing the same
round twice concurrently is a data race the library cannot see.

## Kickoff times

Assignment decorates events without mutating them — run it after
generation and store the UTC kickoffs as-is; convert for display with
your usual timezone middleware, never in storage:

```php
use MissionGaming\Tactician\Timeline\BlackoutRule;
use MissionGaming\Tactician\Timeline\TimelineAssigner;
use MissionGaming\Tactician\Timeline\TimelineDefinition;

$assigner = new TimelineAssigner([
    BlackoutRule::fromArray(['windows' => [[
        'from' => '2026-12-24 00:00:00',
        'to' => '2026-12-27 00:00:00',
        'timezone' => 'Europe/London',
        'label' => 'holidays',
    ]]]),
]);

$scheduled = $assigner->assign($schedule, TimelineDefinition::fromArray(config('tactician.league.timeline')));
foreach ($scheduled as $scheduledEvent) {
    $scheduledEvent->getKickoff();  // DateTimeImmutable, UTC
    $scheduledEvent->getResource(); // pitch/court/venue label, if declared
}
```

## The rules that keep this clean

The same four as the Symfony guide, because they are library rules, not
framework rules: translate at the boundary (models in, stringified-ID
participants through, models out); order entrants by seeding before
constructing participants (position is authoritative); persist round
identity; and log `IncompleteScheduleException::getDiagnosticReport()`
whole — it names which constraint blocks which pairing where.
