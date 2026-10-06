# Using Tactician in a Laravel Application

Tactician has no production dependencies and knows nothing about a
framework. What an application writes around it is an **adapter** that
translates between its own models and the library's participants, events
and results. That adapter is the same in every framework, and the
[Symfony guide](symfony.md) describes it in full:

- [The adapter](symfony.md#the-adapter): models in as participants, the
  output copied into models, and when storing the library's JSON is right.
- [Results-driven stages](symfony.md#results-driven-stages): the stage
  state between requests, the engine fingerprint, a level event, a
  corrected result.
- [Repacking](symfony.md#repacking-an-existing-schedule): what may move,
  the grid, reading the outcome, preview and confirmation by fingerprint.
- [Errors](symfony.md#errors), [Time](symfony.md#time) and
  [Service wiring](symfony.md#service-wiring).

Read that guide first. This page gives only the three places where the
framework shows, in Laravel's spelling. The blocks below are
**schematic**: they show where the library call goes and nothing
executes them. The library usage they stand around is shown, run and
checked in the examples the Symfony guide quotes
([`examples/23-application-adapter-and-repack.php`](../../examples/23-application-adapter-and-repack.php),
[`examples/24-recording-bracket-results.php`](../../examples/24-recording-bracket-results.php)).

## The adapter is a class of your own

There is no service provider to write and nothing to bind. The entry
points are plain objects that are cheap to build, so the adapter builds
them where it uses them.

<!-- schematic: framework code, not executed -->
```php
use Illuminate\Support\Facades\DB;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

final class LeagueFixtureGenerator
{
    public function generate(League $league): void
    {
        // In: models to participants, in the league's own order. The ID is
        // the primary key as a string
        $participants = $league->clubs()->orderBy('ranking')->get()
            ->map(fn (Club $club) => new Participant((string) $club->id, $club->name))
            ->values()
            ->all();

        $schedule = (new RoundRobinScheduler())->schedule(
            $participants,
            RoundRobinOptions::fromArray($league->schedule_options)
        );

        // Out: one Fixture per event with its round and both roles, one Bye
        // per bye. The models are the source of truth from here on
        DB::transaction(function () use ($league, $schedule): void {
            foreach ($schedule->getEventsByRound() as $roundNumber => $events) {
                foreach ($events as $event) {
                    [$home, $away] = $event->getParticipants();
                    $league->fixtures()->create([
                        'round' => $roundNumber,
                        'home_club_id' => (int) $home->getId(),
                        'away_club_id' => (int) $away->getId(),
                    ]);
                }
            }
            foreach ($schedule->getMetadataValue('byes') as $roundNumber => $participantId) {
                $league->byes()->create(['round' => $roundNumber, 'club_id' => (int) $participantId]);
            }
        });
    }
}
```

If the options come from `config()` or from a column, check their keys
yourself: most `fromArray()` methods ignore a key they do not know. The
Symfony guide says [which](symfony.md#the-call).

## One step of a stage, under a lock

A results-driven stage does one step in each request or queued job: load
the state, pair or record, store the state. Two of them on the same
stage at the same time are a race the library cannot see, so lock the
stage's row for the whole step.

<!-- schematic: framework code, not executed -->
```php
use Illuminate\Support\Facades\DB;
use MissionGaming\Tactician\Scheduling\SingleEliminationEngine;
use MissionGaming\Tactician\Stage\StageState;

DB::transaction(function () use ($stageId): void {
    $stage = Stage::query()->lockForUpdate()->findOrFail($stageId);

    $engine = new SingleEliminationEngine();
    $state = StageState::fromJson($stage->state_json);

    $pairing = $engine->pairNextRound($state);
    foreach ($pairing->getEvents() as $event) {
        // create one match model per event: round number, label, both roles
    }

    $stage->update(['state_json' => $state->withRoundPlayed($pairing, [])->toJson()]);
});
```

Keep `state_json` as a plain string column or cast, and let only
`StageState::toJson()` write it. A job that pairs a round is safe to
retry only if the lock and the write are in one transaction, as above.

## Errors at the boundary

Catch `TacticianException` once, where the adapter is called. Report the
diagnostic report as one field of the log context, and turn the reason
into your own message key.

<!-- schematic: framework code, not executed -->
```php
use Illuminate\Support\Facades\Log;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Exceptions\InvariantViolationException;
use MissionGaming\Tactician\Exceptions\SchedulingException;
use MissionGaming\Tactician\Exceptions\TacticianException;
use MissionGaming\Tactician\Exceptions\UnavailableValueException;

try {
    $generator->generate($league);
} catch (TacticianException $e) {
    if ($e instanceof UnavailableValueException || $e instanceof InvariantViolationException) {
        throw $e; // a bug: in the calling code or in the library
    }

    Log::error('Fixture generation failed', [
        'exception' => $e,
        'report' => $e instanceof SchedulingException ? $e->getDiagnosticReport() : $e->getMessage(),
    ]);

    $key = $e instanceof InvalidConfigurationException
        ? match ($e->getReason()) {
            InvalidConfigurationReason::TooFewParticipants => 'league.too_few_clubs',
            InvalidConfigurationReason::DuplicateParticipantIds => 'league.club_listed_twice',
            default => 'league.configuration_rejected',
        }
        : 'league.fixtures_not_generated';

    return back()->withErrors(['league' => __($key)]);
}
```

Which exception is whose to fix is in the
[Symfony guide](symfony.md#errors).
