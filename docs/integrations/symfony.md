# Using Tactician in a Symfony Application

Tactician has no production dependencies and knows nothing about a
framework, a database or a container. What an application has to write
is therefore not wiring. It is an **adapter**: code the application owns,
which translates between its own model and the library's, in both
directions.

This guide is about that adapter. It covers:

1. [The adapter](#the-adapter): entities in as participants, a call, the
   output copied into entities.
2. [Results-driven stages](#results-driven-stages): Swiss and elimination,
   where the application records results between rounds.
3. [Repacking](#repacking-an-existing-schedule): moving a schedule that
   already exists onto the sessions that are really available.
4. [Errors](#errors): one catch clause, and who has to fix what.
5. [Time](#time): UTC instants, positions, and local wall-clock time.
6. [Service wiring](#service-wiring): the little there is to say.

The same adapter works in any framework. The few places where Symfony or
Doctrine appears are marked. The [Laravel guide](laravel.md) gives the
same places in Laravel's spelling and refers back here for the rest.

**How to read the code.** A block headed *From `examples/...`* is an
excerpt of a runnable example. The test suite runs that example, checks
its results, and fails if the excerpt here no longer matches the file
(`tests/Feature/IntegrationGuidesTest.php`). A block headed *Schematic*
is framework code. It shows where the library call goes and is not
executed by anything: read it for its shape and write your own. The
examples use plain PHP arrays where an application has entities.

The words used here are the ones in the
[glossary](../USAGE.md#terminology): participant, role, round, leg,
session, position.

## The adapter

### Participants in

A `Participant` is an ID, a label, an optional seed and optional
metadata. The adapter builds one for each entity that competes.

*From `examples/23-application-adapter-and-repack.php`:*

<!-- excerpt: examples/23-application-adapter-and-repack.php -->
```php
// In: one participant per club. The id is the primary key as a string, which
// is stable and unique; a name can change and can repeat. The scheduler works
// from the order of the list, so the adapter sorts the rows into the
// application's order and passes no seed.
usort($clubRows, static fn(array $a, array $b): int => $a['ranking'] <=> $b['ranking']);

$participantsByClubId = [];
foreach ($clubRows as $clubRow) {
    $participantsByClubId[$clubRow['id']] = new Participant((string) $clubRow['id'], $clubRow['name']);
}
$participants = array_values($participantsByClubId);
```

**The ID.** It is a string, and two participants are the same
participant only when their IDs are the same string. `'01'` and `'1'`
are two participants. Use the entity's primary key, cast to a string:

- It must be unique in the list you pass, and it must not change while
  the stage is alive. The ID is what the library stores in a stage state
  and what a repack outcome reports, so a changed ID is another
  participant.
- A name or a slug is a poor ID, because an operator can edit it. The
  label is for display. Nothing identifies a participant by its label,
  and a repack fingerprint does not cover it.
- PHP turns an array key such as `'42'` into the integer `42`. Where the
  library returns an array keyed by participant ID
  (`StageState::getByeCounts()`, the scores of a `Result`), cast the key
  with `(string)` before you compare it with an ID.
- Writing a schedule or a stage state as JSON needs every ID to be valid
  UTF-8.

**A participant is its ID.** Everything matches participants by ID, the
constraints included, so a second `Participant` built from the same row
is the same participant. Building each one once for a call, as the
excerpt does with its map by club ID, saves the work and nothing else.

**The order.** Put the list in the order your application means, the
strongest entrant first. Every format works from that order:

| Format | What decides the order the format works from |
|---|---|
| Round robin (`RoundRobinScheduler`) | List position. |
| Pot draw (`PotDrawScheduler`) | List position: the first block of the list is pot 1. |
| Elimination (`SingleEliminationEngine`, `DoubleEliminationEngine`) | List position: the first entrant is the top of the bracket, and byes go to the first positions. |
| Pools (`PoolDistributor::serpentine()`) | List position. |
| Swiss (`SwissPairingEngine`) | The standings, and list position between entrants that no result separates. Before a result exists every entrant is level: the first round pairs position 1 with 2 and 3 with 4, and its bye goes to the last position. |

So a seed attribute changes nothing where a format places or orders an
entrant, and the adapter above passes none. With a `Randomizer` the Swiss
engine shuffles entrants that are level, so the first round is drawn at
random.

The same holds wherever a table decides and results leave entrants
level (a bracket that re-seeds its survivors after each round, the table
of a stage's outcome, a selection by rank): their position in the list
orders them, and no label, seed or ID does. Only
`SeedProtectionConstraint` reads the seed, and a standings table you
calculate on its own, with no entrant list.

### The call

A format that needs no results is a **scheduler**: it returns the whole
`Schedule` at once (`RoundRobinScheduler`, `PotDrawScheduler`,
`SwissScheduler`). A format that pairs each round from the results of
the rounds before is a **stage engine** and is the subject of the
[next section](#results-driven-stages).

The options of a format are plain data (`RoundRobinOptions::fromArray()`,
`toArray()`, with stable string identifiers), so a row of your own
settings table can hold them. Two things to know when you do that. Every
`fromArray()` refuses a key it does not know (`InvalidConfigurationException`,
reason `UnknownOptionKey`): a misspelt key (`'legz' => 2`) fails loudly and
is not read as the default, and a row cannot be passed whole with its `id`
and timestamps in it, so pass the library's keys only. And what
`toArray()` writes starts with `format_version`, the version of the form;
store it with the rest. A row stored before that key existed still loads,
as version 1 (see [Format Versions](../USAGE.md#format-versions)). Also,
`'strategy' => 'shuffled'` in plain data needs a
`'strategy_seed'` beside it, an integer: the roles it draws are then the
same on every call, and `fromArray()` refuses the strategy without one.
And a row without the `role_assignment` key names no role assignment and
gets the default of the release that reads it: roles that are balanced
where possible since 0.3.0 (balanced, or the roles of 0.2.x with a
`role_fallback` entry in the schedule metadata where the constraints leave
no balanced schedule), where 0.2.x gave the roles that alternate with round
parity. A league that
was generated by 0.2.x and has to come out the same when it is generated
again needs `'role_assignment' => 'round_parity'` in its row
([Role Assignment](../USAGE.md#role-assignment)).

### The output copied into entities

*From `examples/23-application-adapter-and-repack.php`:*

<!-- excerpt: examples/23-application-adapter-and-repack.php -->
```php
// Out: one fixture row per event, with the round and both roles kept. The
// application gives each row an id of its own and is the source of truth from
// here on: the schedule object is not stored.
$fixtureRows = [];
foreach ($schedule->getEventsByRound() as $roundNumber => $events) {
    foreach ($events as $event) {
        [$firstNamed, $secondNamed] = $event->getParticipants();
        $fixtureRows[] = [
            'id' => sprintf('fx%02d', count($fixtureRows) + 1),
            'round' => $roundNumber,
            'home_club_id' => (int) $firstNamed->getId(),
            'away_club_id' => (int) $secondNamed->getId(),
            'night' => null,
            'slot' => null,
            'local_kickoff' => null,
            'state' => 'scheduled',
        ];
    }
}

// A bye is not an event. With five clubs one sits out each round, and the
// schedule's metadata says which (round number => participant id).
$byeRows = [];
foreach ($schedule->getMetadataValue('byes') as $roundNumber => $participantId) {
    $byeRows[] = ['round' => $roundNumber, 'club_id' => (int) $participantId];
}
```

Copy these four things, because the application needs each of them later
and cannot get it back from anywhere else:

- **The round number.** Rounds are numbered from 1 and continue across
  legs: with three rounds in a leg, the second leg is rounds 4 to 6.
- **Both roles.** The participant at index 0 of `getParticipants()` is
  first-named (home, white), the one at index 1 second-named. Store them
  as two columns, not as an unordered pair.
- **The byes.** A bye is not an event. A round robin of an odd number of
  participants records them in the schedule's `byes` metadata, as round
  number to participant ID. For an even number the list is empty.
- **An ID of your own for every fixture.** The library gives an event no
  ID. The one you assign is the ID a repack will know the fixture by.

**Do not keep `Schedule::toJson()` as the source of truth.** Once the
fixtures exist they get results, are postponed, moved and cancelled. All
of that happens in your entities, and a stored copy of the generated
schedule can only disagree with them. The library does not need the
schedule back: standings are computed from results on events you build
from your own rows (`new Event([$home, $away], new Round($number))`), and
a repack takes your fixture IDs and participants.

Build one `Event` for each fixture when you do that. `StandingsCalculator`
refuses two results on the same `Event` object, and that is the only
duplicate it can see: two results on two `Event` objects built from the
same row are both counted. One result for each fixture is the
application's to guarantee.

Storing the library's JSON is right in three cases:

- **A results-driven stage between rounds.** `StageState::toJson()` is
  the input of the next call, and only the library's own methods write
  it. See below.
- **A preview.** A generated schedule shown to an operator who has not
  confirmed it yet can be kept as `Schedule::toJson()` and read back with
  `Schedule::fromJson()`, then copied into entities on confirmation.
- **Assigned kickoffs you have not copied yet.**
  `ScheduledSchedule::toJson()` serves the same way.

### In Symfony

*Schematic: a service that owns the three steps. The repositories and
entities are your own.*

<!-- schematic: framework code, not executed -->
```php
use Doctrine\ORM\EntityManagerInterface;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

final readonly class LeagueFixtureGenerator
{
    public function __construct(
        private ClubRepository $clubs,
        private EntityManagerInterface $entityManager,
    ) {}

    public function generate(League $league): void
    {
        // In: entities to participants, in the league's own order
        $participants = [];
        foreach ($this->clubs->findInRankingOrder($league) as $club) {
            $participants[] = new Participant((string) $club->getId(), $club->getName());
        }

        // The call. The scheduler is built here: it is a plain object
        $schedule = (new RoundRobinScheduler())->schedule(
            $participants,
            RoundRobinOptions::fromArray($league->getScheduleOptions())
        );

        // Out: one Fixture entity per event, one Bye entity per bye
        $this->entityManager->wrapInTransaction(function () use ($league, $schedule): void {
            foreach ($schedule->getEventsByRound() as $roundNumber => $events) {
                foreach ($events as $event) {
                    [$home, $away] = $event->getParticipants();
                    $this->entityManager->persist(new Fixture(
                        $league,
                        $roundNumber,
                        $this->entityManager->getReference(Club::class, (int) $home->getId()),
                        $this->entityManager->getReference(Club::class, (int) $away->getId()),
                    ));
                }
            }
            foreach ($schedule->getMetadataValue('byes') as $roundNumber => $participantId) {
                $this->entityManager->persist(new Bye(
                    $league,
                    $roundNumber,
                    $this->entityManager->getReference(Club::class, (int) $participantId),
                ));
            }
        });
    }
}
```

## Results-driven stages

A Swiss stage and an elimination bracket cannot be generated up front:
each round depends on the results of the rounds before. A **stage
engine** (`SwissPairingEngine`, `SingleEliminationEngine`,
`DoubleEliminationEngine`) pairs one round at a time from a
`StageState`, which records the entrants, the rounds paired so far and
their results. The engine keeps nothing between calls. Every request
builds an engine, obtains the state, does one step and stores the state.

*From `examples/24-recording-bracket-results.php`:*

<!-- excerpt: examples/24-recording-bracket-results.php -->
```php
// --- Request 1: open the stage -------------------------------------------
// The stamp says which engine pairs this state. It is stored with the state
// and comes back with it.
$engine = new SingleEliminationEngine();
$database['bracket'] = StageState::start($entrants)
    ->withEngineFingerprint($engine->getFingerprint())
    ->toJson();

// --- Request 2: pair the semifinals and record them -----------------------
// A fresh engine and the stored state, as in every request
$engine = new SingleEliminationEngine();
$state = StageState::fromJson($database['bracket']);
$semifinals = $engine->pairNextRound($state);
[$anaVsDia, $beaVsCai] = $semifinals->getEvents();
```

[`examples/18-stateless-web-flow.php`](../../examples/18-stateless-web-flow.php)
runs a whole Swiss stage this way.

### Where the state lives

There are two ways, and both give the engine the same thing.

- **Store `StageState::toJson()`** in a column of the stage's row and
  read it back with `StageState::fromJson()`. This is the simple way and
  the one the examples use. You still copy each round's events into your
  own match entities to show them and to collect results. The JSON starts
  with `format_version`; a state stored by a release that wrote none
  loads as version 1, and one written by a later release that changed
  the form is refused and not guessed at
  ([Format Versions](../USAGE.md#format-versions)).
- **Build the state from your own rows** on each request, with
  `StageState::fromHistory()`, and store no state at all. Your match
  entities are then the only record of the stage, and a corrected result
  is simply another row on the next request. The adapter is mapping and
  nothing else:

*From `examples/27-stage-from-application-rows.php`:*

<!-- excerpt: examples/27-stage-from-application-rows.php -->
```php
$stateFromRows = static function (array $playerRows, array $fixtureRows, array $withdrawalRows): StageState {
    usort($playerRows, static fn(array $one, array $other): int => $one['seeding'] <=> $other['seeding']);

    $players = [];
    foreach ($playerRows as $row) {
        // An ID is a string: cast the integer key
        $players[$row['id']] = new Participant((string) $row['id'], $row['name']);
    }

    $events = [];
    $labels = [];
    $results = [];
    foreach ($fixtureRows as $row) {
        $event = new Event(
            [$players[$row['first']], $players[$row['second']]],
            new Round($row['round'], ['label' => $row['label']])
        );
        $events[$row['round']][] = $event;
        $labels[$row['round']] = $row['label'];

        if ($row['winner'] !== null) {
            $results[] = new Result($event, $players[$row['winner']]);
        }
    }

    $rounds = [];
    foreach ($events as $number => $ofRound) {
        $rounds[] = new RoundPairing($number, $labels[$number], $ofRound);
    }

    return StageState::fromHistory(
        entrants: array_values($players),
        rounds: $rounds,
        results: $results,
        withdrawals: array_map(static fn(int $id): Participant => $players[$id], $withdrawalRows)
    );
};
```

  The rounds you give it are facts. The engine reads them as they are and
  derives only the round that does not exist yet, so a new release of the
  library cannot disagree with fixtures you have already published. What
  your rows have to give back:
  - **The entrants the first round was paired from, in that order**, also
    those who have withdrawn since, whom you name under `withdrawals`.
    The order places the entrants of a bracket and decides every order
    that results leave open.
  - **Their IDs, and nothing else about them, for the pairing order.**
    No stage reads a label or a seed attribute to order entrants, so a
    club renamed in mid-stage changes no pairing. (A constraint the
    engine was given reads what it reads: seed protection the seed
    attribute, a metadata constraint its key.)
  - **For every round**, its number and label, the two participants of
    every event, the event's metadata (a two-legged tie carries its leg
    number there as `tie_leg`, a third-place tie its mark as
    `bracket_tie`, and the engine reads both) and the participants who
    had a bye, which a Swiss table counts. The rows of a round may come
    back in any order.
  - **Every result whole**: its event, its winner or none, its scores
    and its metadata, which is where a level event names who advanced.
    A result finds its event by round number, participants and leg.
  - **Adjustments**, where you make any.

  `fromHistory()` checks what rows can get wrong and a stored state
  cannot: round numbers that are not 1, 2, 3 and so on
  (`RoundOutOfSequence`), a participant who is not an entrant
  (`ParticipantNotAnEntrant`), a participant in a round twice
  (`ParticipantTwiceInRound`) and two results for one event
  (`DuplicateResult`). A round that only pairs the entrants otherwise than
  the engine would have, because an organiser drew it by hand, is not an
  error: `getRecordedRounds()` reports it as manual
  (`RecordedRound::isManual()`), with the engine's own round beside it
  for your audit trail, and the later rounds follow from what was played.

`RoundPairing::getByes()` returns the participants who sit a round out.
They are not events.

### The engine fingerprint

A state does not say which engine paired it, and an engine reads
whatever it is given as its own history. `getFingerprint()` returns a
string that stands for the format and for the options that change which
rounds exist or how they are paired. Stamp the state with it when you
open the stage, as request 1 does above. From then on an engine with
another fingerprint refuses the state on every call:

*From `examples/24-recording-bracket-results.php`:*

<!-- excerpt: examples/24-recording-bracket-results.php -->
```php
// 1. An engine configured differently from the one that stamped the state.
//    Two-legged ties are another bracket, and this state was not paired as one.
$wrongEngine = null;
try {
    (new SingleEliminationEngine(new EliminationOptions(legsPerTie: 2)))->isComplete($state);
} catch (InvalidConfigurationException $exception) {
    $wrongEngine = $exception;
}
```

The exception's reason is `EngineFingerprintMismatch`. It means that the
code now builds another engine than the one the stage was started with:
a deployment changed an option, or a stage was loaded as the wrong
format. It is not an operator's mistake, and it is not something to
retry. If the change of configuration is intended, stamp the state again
with the new engine's fingerprint; otherwise fix the code that builds the
engine.

- Compare the string for equality and treat it as opaque.
- An option left at its default is not part of it, so an option added to
  an engine does not invalidate the states you have stored.
- A patch release keeps every fingerprint. The engines are experimental,
  so a minor release may change how fingerprints are written and require
  stored states to be stamped again; the changelog of that release says
  how.
- It does not cover the constraints or the `Randomizer` of a Swiss
  engine. Restore those yourself.
- A state with no stamp is accepted by every engine.
  [Recording Which Engine Pairs a State](../USAGE.md#recording-which-engine-pairs-a-state)
  has the full contract.

### Recording results

Record results against the events the engine produced, or against the
same events as they come back in a restored state. Round numbers are the
engine's.

`pairNextRound()` does not change the state: asked twice, it returns the
same round. The round becomes part of the state when you call
`withRoundPlayed($pairing, $results)`, or when its fixtures are among the
rows you build the state from. When results arrive one at a time, record
the pairing as soon as you show it, with the results you have (none is
allowed), and add the others with `withAdditionalResults()` as they
come. Three things follow from that:

- **Every engine waits for the round to finish.** While an event of a
  recorded round has no result, `pairNextRound()` is refused with an
  `UnresolvedRoundException`: an `InvalidConfigurationException` whose
  reason is `RoundPartiallyResolved`, and which carries the events it is
  waiting for (`getUnresolvedEvents()`). That is the round still being
  played, not an error to show. A recorded round is not offered again:
  read it from the state (`getLastRound()`) or from your own rows.
- **Reading never throws for it.** `isComplete()` is false,
  `getOutcome()` is null, and `getStandings()` gives the table so far.
  Ask `getReadiness()` before you pair: it says whether the next round
  can be paired and, when it cannot, lists the events that await a
  result, without an exception to catch. A page that shows the bracket
  needs nothing else.
- **Pairing a Swiss round early is said in so many words.** Call
  `pairNextRoundOnIncompleteResults()` to pair from the table as it
  stands; the round it returns is marked
  (`isPairedOnIncompleteResults()`), and the mark is stored with it.
  With a `Randomizer`, pairing the same state again gives another
  round, so record the pairing before you show it.

An entrant who withdraws from a bracket under way keeps its slot
(`withoutParticipant()`, or the `withdrawals` of a history). The tie it
was in is not played, its opponent advances, and no fixture you have
published moves; the events of that tie which you had created are listed
by `RecordedRound::getUncontestedEvents()`, for you to cancel.

**One result for each event is yours to guarantee.**
`withAdditionalResults()` adds what it is given and does not look for a
result the event already has. A request that is sent twice therefore
records the result twice. A bracket engine then refuses the state on
every call (reason `DuplicateResult`) until `withResultReplaced()` has
put one result in the place of the two. A Swiss engine refuses it too,
when it reads its table (`InvalidInputException`), and does so whether or
not the state has been through JSON since: the two results are recognised
by their round, participants and `tie_leg`, not by the event object.
Before adding a result, look in `getResults()` for one on the same round
and participants (and the same `tie_leg`), and use `withResultReplaced()`
when there is one.

Two requests that pair the same round at the same time are a race the
library cannot see. Take a lock on the stage for the read, the step and
the write.

*Schematic: one step of a stage under a row lock.*

<!-- schematic: framework code, not executed -->
```php
use Doctrine\DBAL\LockMode;
use MissionGaming\Tactician\Scheduling\SingleEliminationEngine;
use MissionGaming\Tactician\Stage\StageState;

$this->entityManager->wrapInTransaction(function () use ($stageId): void {
    $stage = $this->stages->find($stageId, LockMode::PESSIMISTIC_WRITE);

    $engine = new SingleEliminationEngine();
    $state = StageState::fromJson($stage->getStateJson());

    $pairing = $engine->pairNextRound($state);
    foreach ($pairing->getEvents() as $event) {
        // create one Match entity per event: round number, label, both roles
    }

    $stage->setStateJson($state->withRoundPlayed($pairing, [])->toJson());
});
```

### An event that finishes level

A bracket must send one participant on from every tie. When an event
finishes level, who advances is decided by the application under its own
rules. Record the result as the draw it was and give it a `Decider` that
names the participant who advances, by ID:

*From `examples/24-recording-bracket-results.php`:*

<!-- excerpt: examples/24-recording-bracket-results.php -->
```php
// Ana and Dia finish level at 1-1. A bracket must send one of them on, and
// who that is belongs to the application's rules (here, a shoot-out that Dia
// won). The result is recorded as the draw it was, with a decider that names
// the participant who advances by id. The label is the application's own
// word for how it decided; the library stores it and never reads it.
//
// The other semifinal is entered as a win for Cai. That is a mistake: Bea won.
$state = $state->withRoundPlayed($semifinals, [
    new Result($anaVsDia, null, ['ana' => 1, 'dia' => 1], decider: new Decider([$dia->getId()], 'shoot-out')),
    new Result($beaVsCai, $cai, ['bea' => 2, 'cai' => 0]),
]);
$database['bracket'] = $state->toJson();
```

- The decider takes the participant's ID as the string `getId()` returns.
  An integer primary key that was not cast is refused when the decider is
  built (`InvalidInputException`), and so is the ID of a participant who
  is not in the event when the result is built.
- The label and the reason are yours: the library stores them with the
  result, hands them back and never reads them. Keep the method there, or
  in a column of your own; the library never needs it.
- The result stays a draw at the score it was played to: `isDraw()` is
  true, `getWinner()` is null and no score changes.
  `$result->getDecider()` gives the decider back, and
  `TieDecision::advancer()` reads who went on.
- The decider is stored with the state, under `decider` in the result's
  array, and comes back from `StageState::fromJson()`.
- The engine's own table counts the event as a win for the participant
  who advanced.
- A decider settles a level tie and nothing else: one on an event that has
  a winner is refused by the engine with the reason `InvalidResult`.
- A level event with no decider awaits one: the bracket is not complete,
  `getReadiness()` lists the event under `getUndecidedEvents()`, and
  `pairNextRound()` refuses with the reason `UndecidedTie`; the context
  of the exception holds the round and the two participants. Show it to
  the operator as "choose who advances".
- A two-legged tie (`EliminationOptions(legsPerTie: 2)`) that is level
  after both legs is decided the same way, by a decider on one leg's
  result: the second, where it is recorded when the tie ends.
- Before 0.3.0 the decision was `tie_winner` metadata on the result. That
  key is deprecated and still read until 1.0.0
  ([Deprecations](../USAGE.md#the-tie_winner-metadata-key)).

### Correcting a result

*From `examples/24-recording-bracket-results.php`:*

<!-- excerpt: examples/24-recording-bracket-results.php -->
```php
// --- Request 3: correct the result ---------------------------------------
// withResultReplaced() replaces one result of the last recorded round, which
// is the only round nothing has been paired from yet. The event is found by
// its round and participants, so the one that came back from storage will do.
$state = StageState::fromJson($database['bracket']);
$storedBeaVsCai = $state->getRoundsPlayed()[0]->getEvents()[1];
$state = $state->withResultReplaced(new Result($storedBeaVsCai, $bea, ['bea' => 2, 'cai' => 0]));
$database['bracket'] = $state->toJson();
```

`withResultReplaced()` works on the **last recorded round** only. A
result of an earlier round is refused with the reason `RoundSuperseded`,
because a later round was paired from it. To change one, build the state
again from `StageState::start()` with `withRoundPlayed()` for every round
that still stands, and pair again; the matches of the rounds you drop are
yours to cancel. An event with no result yet is refused with
`ResultNotRecorded`: add its first result with `withAdditionalResults()`.

### Reading the stage outcome

`getOutcome()` returns null until the stage is complete, and then a
`StageOutcome` with the standings and the results.

*From `examples/24-recording-bracket-results.php`:*

<!-- excerpt: examples/24-recording-bracket-results.php -->
```php
// Who took the title is read from the results of the final round
$titleHolder = MatchOutcomeSelector::winners()->select($outcome)[0];

// The level semifinal is still a draw in the record, at the score it was
// played to, and its decider came back from storage with it; advancer() is
// how the engines read who went on
$levelResult = $state->getResults()[0];
$advanced = TieDecision::advancer([$levelResult], $ana, $dia, 1);
```

The standings of a bracket's outcome are its placement: rank 1 is the
entrant who won the final round, read above from its results, rank 2 the
one it beat, and the others follow by the round that put them out. The
entrants who went out in one round share a tier of the table, in which
their records order them and, where those are level, their position in
the list.

The entrants of the next stage are an ordered list that you pass on, and
[the order matters](#participants-in). A `RankRangeSelector` refuses to
take them from a table by position where no result decides who holds
that position (`UndecidedSelectionException`, which names the level
entrants and their places); `Standings::getTiedSets()` reports the same
positions. Deciding such a tie is the application's rule, and going on
without one is a choice made on the selector.

## Repacking an existing schedule

Generation invents events. A **repack** places events that already
exist: your fixtures, known by your IDs, onto a grid of **sessions**
(match nights) and **slots** within them, so that no participant is in
two events at once and each participant's events in a session run back
to back where that is possible. It is the tool for a season that has
fallen behind, for a lost night, and for moving a generated schedule
onto the nights a venue really has.

### What may move is the application's decision

The library does not know why a fixture has to stay where it is: it was
played, it is being broadcast, a referee is booked. The adapter decides,
and hands over three sets:

*From `examples/23-application-adapter-and-repack.php`:*

<!-- excerpt: examples/23-application-adapter-and-repack.php -->
```php
// --- The adapter, the other way: fixture rows into a repack request -------
// The application decides what may move. The library is told the answer and
// never the reason:
//   - a played or locked fixture on one of the nights is pinned;
//   - a played or locked fixture on a night that is not on the grid is left
//     out, because it cannot collide with anything on it;
//   - every other fixture is movable, wherever it sits now.
//
// The grid is shape-only: the application has no UTC instants to give and
// needs positions back, which it turns into its own times. Capacity is
// unbounded because each fixture is played at a club's own ground, so the
// only limit is that no club is in two fixtures at once.
$buildRequest = static function (array $fixtureRows, array $nightRows) use ($participantsByClubId): array {
    $sessionOfNight = array_flip(array_column($nightRows, 'id'));

    $slotCounts = array_map(static fn(array $night): int => count($night['kickoffs']), $nightRows);
    $grid = SessionGrid::shapeOnly(
        sessions: count($nightRows),
        slotsPerSession: $slotCounts[0],
        slotsPerSessionOverrides: array_filter($slotCounts, static fn(int $slots): bool => $slots !== $slotCounts[0]),
        capacityPerSlot: null
    );

    $movable = [];
    $pinned = [];
    $leftOut = [];
    foreach ($fixtureRows as $row) {
        $home = $participantsByClubId[$row['home_club_id']];
        $away = $participantsByClubId[$row['away_club_id']];
        $mustStay = in_array($row['state'], ['played', 'locked'], true);

        if (!$mustStay) {
            $movable[] = new MovableEvent($row['id'], $home, $away);
        } elseif (isset($sessionOfNight[$row['night']])) {
            $pinned[] = new PinnedEvent($row['id'], $home, $away, $sessionOfNight[$row['night']], $row['slot']);
        } else {
            $leftOut[] = $row['id'];
        }
    }

    return [new RepackRequest($movable, $pinned, $grid), $leftOut];
};
```

- A **movable event** is an ID and two participants. It has no position
  going in: where it sits now plays no part.
- A **pinned event** has a position, keeps it, and blocks it for both of
  its participants. It must be on the grid: a pin at a position the grid
  does not have is rejected (reason `PinOffGrid`), it is not ignored.
- So an event that must stay and is **not on the grid** is left out by
  the adapter. It cannot collide with anything on the grid, because a
  collision is two events of one participant at the same session and
  slot, and nothing else. The library does not compare times. If an
  event off the grid is near enough in time to one of its sessions to
  matter (the same evening, an hour before the first slot), nothing in
  the outcome says so: check it in the adapter, or, where the grid can
  hold that time as a slot, pin the event there.
- One ID may appear once in the request, movable or pinned (reason
  `DuplicateEventId`).

### The grid

A `SessionGrid` is a list of sessions, each with a number of slots, and
a capacity for one slot. There are two forms.

**Instant-based.** The grid knows when every position is, and every
assignment comes back with a UTC kickoff:

*From `examples/19-repacking-a-season.php`:*

<!-- excerpt: examples/19-repacking-a-season.php -->
```php
$shape = [
    'sessions' => ['2026-08-12 20:00', '2026-08-19 20:00'],
    'timezone' => 'Europe/London',
    'slot_interval' => 'PT30M',
    'slots_per_session' => 2,
    'capacity_per_slot' => 3,
];
$grid = SessionGrid::fromArray($shape + ['slots_per_session_overrides' => [1 => 4]]);
```

**Shape-only.** `SessionGrid::shapeOnly()` takes a session count and the
slot counts and nothing about time. It is for an application that keeps
its times in another form, such as a local wall-clock time on each
fixture, and needs only positions back. The adapter above builds one
from its own night rows and turns each position back into its own time.
An assignment made on a shape-only grid has no kickoff: ask
`hasKickoff()` before `getKickoff()`. The repacker gives every event the
same position on both forms of a grid of the same shape.

`ordinalOf($session, $slot)` numbers the positions of either form from 0
in grid order, and `positionOf($ordinal)` reverses it, for an
application that keeps its slots as one list. On an instant-based grid
`positionOf()` also takes an instant and returns its position, or null
when the grid has no slot at that instant. That is one way to sort
existing fixtures into "on the grid" and "off it".

**Capacity.** `capacityPerSlot` is how many events may share one slot.
The default is 1, which is right when every event needs the one shared
venue. When events share nothing, pass `null` (`'unbounded'` in plain
data): the only limit left is that no participant is in two events at
once. Do not pass a very large number to mean "no limit".

### Reading the repack outcome

`repack()` does not throw when the events do not fit. It returns a
`RepackOutcome` with what it placed, what it could not place, and every
compromise as a **violation**. An operator who is repairing a season
needs the plan and its defects together.

Each kind of violation is a class with getters for what it is about, and
`RepackOutcome` has one accessor for each kind that returns that class:

*From `examples/23-application-adapter-and-repack.php`:*

<!-- excerpt: examples/23-application-adapter-and-repack.php -->
```php
$overCapacity = [];
foreach ($tooFewSlots->getCapacityExceededViolations() as $violation) {
    $overCapacity[] = [
        'Club' => $violation->getParticipant()?->getLabel() ?? '(the grid as a whole)',
        'Fixtures to place' => $violation->getDemand(),
        'Free positions' => $violation->getCapacity(),
        'Short by' => $violation->getShortfall(),
    ];
}

$notPlaced = [];
foreach ($tooFewSlots->getEventUnplacedViolations() as $violation) {
    $notPlaced[] = [
        'Fixture' => $violation->getEventId(),
        'Reason' => $violation->getReason()->value,
        'Club over capacity' => $violation->getParticipant()?->getLabel(),
    ];
}
```

| Accessor | What it reports | What it usually means for the operator |
|---|---|---|
| `getEventUnplacedViolations()` | A movable event that got no position. `getUnplaced()` lists the same events. | The plan is incomplete. |
| `getCapacityExceededViolations()` | A participant with more events than free positions, or (null participant) a grid that is too small. `getShortfall()` is how many positions are missing. | Add a session or a slot. |
| `getContiguityBrokenViolations()` | A participant whose slots in one session have a gap. | A quality defect to show. |
| `getLateStartViolations()` | A participant whose first slot in a session is not the session's first. | A quality defect to show. |
| `getParticipantDoubleBookedViolations()` | A participant at one position twice. The repacker never produces it; the outcome is audited so that its absence is a fact. | Nothing, if empty. |

Every movable event is either in `getAssignments()` or in
`getUnplaced()`. `getAssignmentFor($eventId)` returns one event's
position, or null. `isClean()` is true only when nothing is unplaced and
there is no violation of any kind, late starts included. Which
violations stop a plan from being applied is the application's policy:
the library returns them all and judges none.
`RepackOptions(throwOnViolations: true)` asks for a
`RepackViolationsException` instead, which still carries the outcome
(`getOutcome()`).

### Preview, then confirm by fingerprint

An operator looks at a plan before it is written. Between the look and
the confirmation the fixtures can change: a result comes in, somebody
locks another fixture. Do not store the previewed plan and apply it
later. Keep its **fingerprint**, and when the operator confirms, build
the request again from the rows as they are now, repack again, and
compare.

On a shape-only grid, keep one more thing: what the positions stood for.
Such a grid is a number of sessions and slots and nothing else, so the
fingerprint covers which fixture is at which session and slot, and not
which night that session is or when that slot kicks off. A night that is
swapped for another, or a kickoff that is moved, leaves the shape and so
the fingerprint as they were.

*From `examples/23-application-adapter-and-repack.php`:*

<!-- excerpt: examples/23-application-adapter-and-repack.php -->
```php
// What is kept of the preview: the fingerprint of the plan, and the nights
// the operator saw it on. A shape-only grid is a number of sessions and
// slots. It does not know which night a session is or when a slot kicks off,
// so the same fixtures on two calendars of the same shape give the same
// fingerprint.
$previewed = ['fingerprint' => $preview->fingerprint(), 'nights' => $nightsWithTwoKickoffs];
```

When the operator confirms:

<!-- excerpt: examples/23-application-adapter-and-repack.php -->
```php
// --- Confirming -----------------------------------------------------------
// A preview is not stored as a plan to apply later. When the operator
// confirms, the request is built again from the rows as they are now and
// repacked again: the repacker is deterministic, so the same rows give the
// same plan and the same fingerprint. A different fingerprint means the rows
// changed after the preview, and nothing is written.
$confirm = static function (array $fixtureRows, array $nightRows, array $previewed) use ($buildRequest, $repacker): array {
    [$request] = $buildRequest($fixtureRows, $nightRows);
    $plan = $repacker->repack($request);

    // Fingerprints of different schemes are not comparable
    $sameScheme = str_starts_with($previewed['fingerprint'], RepackOutcome::FINGERPRINT_SCHEME . ':');
    $samePlan = $sameScheme && $plan->fingerprint() === $previewed['fingerprint'];

    // The positions must also mean what they meant when the plan was shown:
    // the same nights in the same order, with the same kickoffs
    if (!$samePlan || $nightRows !== $previewed['nights']) {
        return [false, $fixtureRows];
    }

    // Apply: copy each position into the fixture row, and turn it into the
    // application's own time. The grid's sessions are the nights in order.
    foreach ($fixtureRows as $index => $row) {
        $assignment = $plan->getAssignmentFor($row['id']);
        if ($assignment === null) {
            continue;
        }
        $night = $nightRows[$assignment->getSession()];
        $fixtureRows[$index]['night'] = $night['id'];
        $fixtureRows[$index]['slot'] = $assignment->getSlot();
        $fixtureRows[$index]['local_kickoff'] = $night['kickoffs'][$assignment->getSlot()];
    }

    return [true, $fixtureRows];
};
```

This works because a repack is deterministic: the same request gives the
same outcome on every machine, whatever the order of the two lists.
`fingerprint()` is equal for two outcomes with the same assignments,
unplaced events and violations, and different when any of them differs.

- It covers the movable events: the position of each one that was
  placed (with its UTC kickoff on an instant-based grid), each one that
  was not, and the violations. A pinned event is not in the outcome, so
  a pin is covered only through what it does to the others.
- It covers a participant by its ID only. A renamed club does not change
  it.
- It begins with a scheme (`v1:`,
  `RepackOutcome::FINGERPRINT_SCHEME`). Compare two fingerprints only
  when their schemes are equal.
- A different fingerprint means "show the operator the new plan". The
  rows changed, the shape of the grid changed, or the library was
  upgraded to a version that packs this request differently.
- An equal fingerprint means the same plan, and says nothing about the
  rest of what the operator was shown. Compare that yourself, as the
  example compares its nights.
- The check and the write belong in one transaction that locks the
  fixtures it reads. Otherwise a fixture can still change between the
  comparison and the write, which is the change the comparison was there
  to catch.

### The step budget, time and memory

A repack's searches are bounded by `RepackOptions(stepBudget: ...)`,
counted in steps and not in seconds. That is what makes the outcome the
same everywhere. There are two budgets of that size: the packing searches
share one, and the last placement step, which runs only when an event is
still unplaced, has one of its own, so a repack can spend up to twice
`stepBudget`. `isBudgetExhausted()` says whether a budget stopped a
search:

- **True**: a larger budget may give another outcome for the same
  request. It is not a promise of a better one.
- **False**: a larger budget gives the same outcome. It does not mean
  the outcome is the best possible.

The flag says nothing about violations, and it is not part of the
fingerprint. The budget is not a time limit. A session of very many
slots can cost seconds, and memory beyond PHP's default `memory_limit`,
that the budget does not count; running out of memory is not a failure
a `catch` can handle. [The Step Budget](../USAGE.md#the-step-budget) in
the usage guide gives the measured figures and the slot counts they
start at. If a person waits for the answer, give a wide grid a time
limit and a memory limit of your own, or run it in a worker.

## Errors

Every exception the library throws on purpose implements
`Exceptions\TacticianException`. One clause catches them all:

*From `examples/11-error-handling.php`:*

<!-- excerpt: examples/11-error-handling.php -->
```php
// 3. One catch for everything. A rejected argument is an
//    InvalidInputException, which is not a scheduling failure and does not
//    extend SchedulingException; the marker interface covers both.
$attempts = [
    'A schedule the constraints rule out' => static fn() => (new RoundRobinScheduler($rejectEverything))->schedule($participants),
    'A round numbered zero' => static fn() => new Round(0),
];

$caught = [];
foreach ($attempts as $what => $attempt) {
    try {
        $attempt();
    } catch (TacticianException $exception) {
        $caught[$what] = $exception::class;
    }
}
```

`SchedulingException` is not that clause. It is the parent of the
scheduling failures only, and a rejected argument
(`InvalidInputException`) or unreadable JSON (`JsonConversionException`)
does not extend it.

What the adapter does with an exception depends on who can fix it:

| Exception | Whose it is | What to do |
|---|---|---|
| `IncompleteScheduleException` | The operator's: the constraints leave no complete schedule. | Log `getDiagnosticReport()`. Show which rule blocks which pairing: `getAnalysis()?->getImpossiblePairings()`. |
| `NoValidPairingException` | The operator's: no complete Swiss round can be paired without a rematch or against the constraints. | Log `getDiagnosticReport()`. End the stage there, or relax the constraints. |
| `InvalidConfigurationException` | Depends on its reason, see below. | Branch on `getReason()`. |
| `PinConflictException` | The operator's: one participant is pinned in two events at one position. | Show the two fixtures: `getEventIds()`. One of them has to be unpinned or moved. |
| `RepackViolationsException` | Yours, by choice: only thrown when `throwOnViolations` is set. | Read `getOutcome()`. |
| `JsonConversionException` | The data's: a stored state or schedule is not valid JSON. | Report it; do not retry. |
| `InvalidInputException` | A programmer's, or the data's: an argument out of range, or stored data with a missing field or an unknown participant ID. | Report it. |
| `UnavailableValueException` | A programmer's: a kickoff was asked of a shape-only grid, for example. | Fix the calling code. Do not catch it. |
| `InvariantViolationException` | The library's: it reached a state its own logic rules out. | Report it to the library. |

Let the last two reach your error handler as the bugs they are, and do
not turn them into a message for an operator. Test for the two classes
by name. Both extend PHP's `\LogicException`, but so does
`InvalidInputException` (through `\InvalidArgumentException`), and that
one can be the fault of stored data.

**Branch on the reason, never on the message.**
`InvalidConfigurationException::getReason()` returns a case of the
`InvalidConfigurationReason` enum. It says which mistake was made, and
its string value (`$reason->value`) is a stable identifier for logs and
translations. The message is a sentence for a person. Some reasons are
an operator's to fix: `TooFewParticipants`,
`UndecidedTie`, `TimelineCapacityExceeded`, `TimeRuleViolation`,
`UnparseableTime` for a date that was typed in. Some are a programmer's:
`UnsupportedOptions`, `WrongValueType`, `EngineFingerprintMismatch`,
`EventNotInRound`, and the reasons a history built from your rows is
refused with (`ParticipantNotAnEntrant`, `ParticipantTwiceInRound`). One
is neither: `RoundPartiallyResolved` is a round still in play, and
`getReadiness()` tells you so before you ask for the next one. A `match`
over the reason needs a `default` arm,
because a release may add a case.
[Configuration Errors](../USAGE.md#configuration-errors) lists every
reason.

`getContext()` holds the values involved (IDs, counts, the setting that
was rejected), keyed by name. `getDiagnosticReport()` is the whole of it
as text of several lines. **Log the report whole**, as one field. It is
written for the person who has to fix the configuration.

`PinConflictException` extends `InvalidConfigurationException` and adds
`getEventIds()`, `getParticipantId()`, `getSession()` and `getSlot()`.
The IDs are your fixture IDs, so the adapter can name the two fixtures.
On a grid whose slots hold one event, the same two pins exceed the slot
first and are reported as the reason `PinCapacityExceeded`, with the
session and slot in the context and no event ID.

*Schematic: the boundary of the adapter.*

<!-- schematic: framework code, not executed -->
```php
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Exceptions\InvariantViolationException;
use MissionGaming\Tactician\Exceptions\SchedulingException;
use MissionGaming\Tactician\Exceptions\TacticianException;
use MissionGaming\Tactician\Exceptions\UnavailableValueException;

try {
    $this->fixtureGenerator->generate($league);
} catch (TacticianException $e) {
    if ($e instanceof UnavailableValueException || $e instanceof InvariantViolationException) {
        throw $e; // a bug: in the calling code or in the library
    }

    // One field, several lines
    $this->logger->error('Fixture generation failed', [
        'exception' => $e,
        'report' => $e instanceof SchedulingException ? $e->getDiagnosticReport() : $e->getMessage(),
    ]);

    $key = match (true) {
        $e instanceof InvalidConfigurationException => match ($e->getReason()) {
            InvalidConfigurationReason::TooFewParticipants => 'league.too_few_clubs',
            InvalidConfigurationReason::DuplicateParticipantIds => 'league.club_listed_twice',
            default => 'league.configuration_rejected',
        },
        default => 'league.fixtures_not_generated',
    };

    throw new FixtureGenerationFailed($key, previous: $e);
}
```

What the marker does not cover reaches you unchanged: an exception from
code you supplied (a constraint predicate, a ranking strategy, a
tiebreaker), a failure of the random source, and PHP's `\Error` family.

## Time

The library works in one of two things: **instants**, which it gives
back in UTC, or **positions**, which have no time at all. It never asks
for the current time. An application that stores local wall-clock time
converts at its own boundary, in the adapter.

**Instants.** A timeline or an instant-based grid is declared in a
timezone. An interval in days or weeks is wall-clock time in that zone:
a weekly 19:00 kickoff (`P7D`) stays 19:00 local when the clocks change.
An interval in hours is elapsed time: `PT168H` is 18:00 local the week
after the clocks go back. What comes back
(`ScheduledEvent::getKickoff()`, `SlotAssignment::getKickoff()`) is a
`DateTimeImmutable` in UTC.

*From `examples/15-timeline-assignment.php`:*

<!-- excerpt: examples/15-timeline-assignment.php -->
```php
// The application translates its competition config into the declarative
// slot model - Tactician owns the mechanism, never the policy
$timeline = TimelineDefinition::fromArray([
    'start' => '2026-08-01 18:00:00',
    'timezone' => 'Europe/London',   // wall-clock kickoffs survive DST
    'round_interval' => 'P7D',       // one match day per week
    'slots_per_round' => 2,          // 18:00 and 20:00 kickoffs...
    'slot_interval' => 'PT2H',
    'resources' => ['North Pitch', 'South Pitch'], // ...on two pitches
]);
```

Store the UTC instant, or convert it with
`$kickoff->setTimezone(new DateTimeZone('Europe/London'))` and store the
local time, whichever your schema holds. Do the conversion in one place.

`TimelineAssigner` fills slots and checks the rules it was given. With
no rule it does not stop one participant being in two events at the same
instant; a `MinimumRestRule` does, as in example 15.

**Positions.** If the application has no instants to give, it does not
have to make any up. A shape-only grid takes and returns positions, and
the adapter maps a position to a time through its own records, as the
repack example does.

**What a datetime in plain data may be.** Wherever a datetime is read
from plain data (the `start` of a timeline, the `sessions` of a grid,
the `from` and `to` of a blackout window):

- It states its date in full, year, month and day: `2026-08-01 18:00`.
  The time of day is optional and defaults to midnight.
- The zone comes from the `timezone` field. Write no offset and no zone
  into the string. One that is not the field's own name is rejected
  (reason `TimezoneMismatch`), even when it is the right offset for that
  date: `+01:00` under `Europe/London` in summer.
- A string that depends on the clock is rejected (reason
  `UnparseableTime`): `tomorrow 19:00`, `+1 week`, a time with no date.
  So is a date that does not exist, such as `2026-02-30`. Compute a
  relative date in the application and pass the result.
- A local time that a clock change skips is moved forward by the hour
  that was skipped.

[Timeline Assignment](../USAGE.md#timeline-assignment) has the full
rules. Constructed in code, a timeline or a grid takes
`DateTimeImmutable` objects, and each carries its own zone.

## Service wiring

There is nothing to register. The entry points (`RoundRobinScheduler`,
`PotDrawScheduler`, the three engines, `TimelineAssigner`,
`ScheduleRepacker`, `StandingsCalculator`) are plain objects with no
dependency on a container, a connection or a clock, and they are cheap
to build. Build them in the adapter, where the constraints and options
of the competition at hand are known. The adapter is the service.

Two things to know if you register one as a shared service anyway:

- **A scheduler or engine built with a `Random\Randomizer` is not
  repeatable as a shared service.** Each call advances the randomizer,
  so the second call on the same object gives another schedule than the
  first. Build it for each use, from a seed you store, when a schedule
  must be reproducible.
- **Constraints belong to a competition, not to the container.** A
  `ConstraintSet` is given to the constructor, so a shared scheduler
  applies one set of rules to every league.

## The short version

- The application owns an adapter. Entities go in as participants with
  the primary key as a string ID; events come out and are copied into
  entities with their round, both roles, the byes and an ID of your own.
- Your entities are the source of truth. The library's JSON is stored
  for a stage state between rounds and for a preview.
- Order the list. For a Swiss stage also set the seed.
- Stamp a stage state with the engine's fingerprint. Record results on
  the engine's events; a level bracket event names who advances.
- A repack is told what may move. Read its violations by kind, preview
  it, and apply it only if computing it again gives the same
  fingerprint and the sessions still stand for what was shown.
- Catch `TacticianException`, branch on the reason, log the report
  whole.
- UTC instants or positions out; local time is converted in the adapter.
