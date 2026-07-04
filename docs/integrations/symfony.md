# Using Tactician in a Symfony Application

Tactician has zero production dependencies and no framework awareness —
integration is plain object wiring. This guide shows the patterns a
Symfony application actually needs: service registration, configuration
translated through `fromArray()`, persisting schedules and stage state,
driving a results-driven engine across stateless requests, and assigning
kickoff times on the way out.

Everything framework-side here (YAML, attributes, Doctrine mappings) is
inert wiring; every snippet that touches Tactician's API is executed by
the test suite's snippet harness before it ships (the repo rule for all
documentation).

## Service registration

The schedulers and engines are stateless services — register them once
and autowire them. Constraints and options vary per competition, so
inject them at call sites rather than baking them into the container:

```yaml
# config/services.yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true

    MissionGaming\Tactician\Scheduling\RoundRobinScheduler: ~
    MissionGaming\Tactician\Scheduling\SwissPairingEngine: ~
    MissionGaming\Tactician\Timeline\TimelineAssigner: ~
    MissionGaming\Tactician\Standings\StandingsCalculator: ~
```

A competition whose rules are fixed at deploy time can instead define a
preconfigured engine:

```yaml
    app.swiss_engine.nine_rounds:
        class: MissionGaming\Tactician\Scheduling\SwissPairingEngine
        arguments:
            $plannedRounds: 9
```

## Configuration through fromArray()

Everything a competition organizer chooses is plain data — options, leg
strategies, timelines, and time rules are all constructible from arrays,
so a Symfony parameter block (or a row in your own settings table) maps
straight onto the library:

```yaml
# config/packages/app_competitions.yaml
parameters:
    app.league.options:
        legs: 2
        strategy: mirrored
        backtracking: true
    app.league.timeline:
        start: '2026-08-01 18:00:00'
        timezone: 'Europe/London'
        round_interval: 'P7D'
        slots_per_round: 3
        slot_interval: 'PT1H'
```

```php
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
use MissionGaming\Tactician\Timeline\TimelineDefinition;

$options = RoundRobinOptions::fromArray($parameters['app.league.options']);
$timeline = TimelineDefinition::fromArray($parameters['app.league.timeline']);
```

Invalid configuration throws `InvalidConfigurationException` with the
offending values in `getContext()` — surface it in your form or console
error, don't swallow it.

## Generating fixtures in a service

Wrap generation in an application service that translates your domain
entities to `Participant`s and back. Tactician participants are
identified by string IDs — use your entity IDs stringified, and keep a
map for hydration:

```php
use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

final readonly class FixtureGenerator
{
    /**
     * @param array<array{id: int, name: string}> $entrants Ordered by seed -
     *        stage entry is position-authoritative (position 1 = seed 1)
     * @param array<string, mixed> $optionsConfig
     */
    public function generate(array $entrants, array $optionsConfig): Schedule
    {
        $participants = array_map(
            fn (array $entrant) => new Participant((string) $entrant['id'], $entrant['name']),
            $entrants
        );

        $scheduler = new RoundRobinScheduler(
            ConstraintSet::create()->noRepeatPairings()->build()
        );

        try {
            return $scheduler->schedule($participants, RoundRobinOptions::fromArray($optionsConfig));
        } catch (IncompleteScheduleException $e) {
            // The report names which constraint blocks which pairing where;
            // log it verbatim - it is written for the person fixing the config
            throw new \DomainException($e->getDiagnosticReport(), previous: $e);
        }
    }
}
```

## Persisting schedules

`Schedule` round-trips JSON with participants listed once and referenced
by ID — store it in a `JSON` column and rebuild without regenerating:

```php
use MissionGaming\Tactician\DTO\Schedule;

// Persist (e.g. into a Doctrine JSON column on your Stage entity)
$stage->setScheduleJson($schedule->toJson());

// Rehydrate in a later request
$schedule = Schedule::fromJson($stage->getScheduleJson());
```

If your fixture entities are the source of truth instead, hydrate them
from the round-grouped view once and keep **round identity** — staggered
kickoff assignment and per-round operations need it later:

```php
foreach ($schedule->getEventsByRound() as $roundNumber => $events) {
    foreach ($events as $event) {
        [$home, $away] = $event->getParticipants();
        // create your fixture entity: (int) $home->getId(), (int) $away->getId(), $roundNumber
    }
}
```

## Driving a Swiss stage across requests

Results-driven engines pair one round at a time from recorded results —
in a web application that means one round per request cycle, with
`StageState` serialized between them. The engine is stateless; the state
column is the whole story:

```php
use MissionGaming\Tactician\Scheduling\SwissPairingEngine;
use MissionGaming\Tactician\Stage\StageState;

// Request 1: open the stage
$state = StageState::start($participants);
$stage->setStateJson($state->toJson());

// Request N: pair the next round
$state = StageState::fromJson($stage->getStateJson());
$engine = new SwissPairingEngine(plannedRounds: 5);

if (!$engine->isComplete($state)) {
    $pairing = $engine->pairNextRound($state);
    // ... present $pairing->getEvents() for play, collect $results ...
    $state = $state->withRoundPlayed($pairing, $results);
    $stage->setStateJson($state->toJson());
}

// When complete: standings, qualifiers, pools
$outcome = $engine->getOutcome($state);
$outcome->getStandings();
```

Record results against the events the engine produced — round numbers
are engine-assigned. Wrap the load-pair-save cycle in one transaction
(or a lock on the stage row); two concurrent pairings of the same round
is a data race the library cannot see.

## Assigning kickoff times

Timeline assignment decorates events without mutating them, so it runs
after generation — typically in the same service, before hydration:

```php
use MissionGaming\Tactician\Timeline\MinimumRestRule;
use MissionGaming\Tactician\Timeline\TimelineAssigner;
use MissionGaming\Tactician\Timeline\TimelineDefinition;

$timeline = TimelineDefinition::fromArray($timelineConfig);
$assigner = new TimelineAssigner([MinimumRestRule::fromArray(['rest' => 'PT48H'])]);

$scheduled = $assigner->assign($schedule, $timeline);
foreach ($scheduled as $scheduledEvent) {
    $scheduledEvent->getKickoff();  // DateTimeImmutable, UTC - store as-is
    $scheduledEvent->getResource(); // pitch/court/venue label, if declared
}
```

Kickoffs are UTC; convert at the presentation edge, never in storage.

## Console entry point

Generation is a natural console command — the same service, loud
failures on stderr:

```php
#[AsCommand(name: 'app:fixtures:generate')]
final class GenerateFixturesCommand extends Command
{
    public function __construct(private readonly FixtureGenerator $generator)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // load entrants, call $this->generator->generate(...), persist
        return Command::SUCCESS;
    }
}
```

## The rules that keep this clean

- **Translate at the boundary.** Your entities in, `Participant`s with
  stringified IDs through the library, your entities out. Nothing
  framework-shaped crosses into Tactician.
- **Position is authoritative.** Order the entrant list by seeding
  before creating participants; carried seed attributes are not used
  for pairing.
- **Persist round identity.** The round-grouped view is the bridge to
  timelines, standings-by-round, and per-round operations. Discarding
  round numbers at hydration forecloses all three.
- **Let failures be loud.** `IncompleteScheduleException::getDiagnosticReport()`
  names which constraint blocks which pairing where — it is written for
  the human fixing the configuration. Log it whole.
