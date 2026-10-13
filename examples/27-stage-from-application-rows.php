<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/support/Example.php';

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Examples\Example;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Scheduling\SingleEliminationEngine;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Stage\StageState;

// A badminton knockout whose fixtures and results live in the application's
// own tables. No stage state is stored anywhere: every request maps the rows
// to StageState::fromHistory() and asks the engine what follows. The rows
// are facts. The engine reads them as they are and derives only the round
// that does not exist yet.

// --- The application's tables ---------------------------------------------
// Players as the tournament entered them. The seeding column is the order of
// the entrant list; the rows are not in that order.
$playerRows = [
    ['id' => 15, 'name' => 'Emre', 'seeding' => 5],
    ['id' => 11, 'name' => 'Aiko', 'seeding' => 1],
    ['id' => 18, 'name' => 'Noor', 'seeding' => 8],
    ['id' => 12, 'name' => 'Bram', 'seeding' => 2],
    ['id' => 14, 'name' => 'Dara', 'seeding' => 4],
    ['id' => 13, 'name' => 'Chidi', 'seeding' => 3],
    ['id' => 17, 'name' => 'Goran', 'seeding' => 7],
    ['id' => 16, 'name' => 'Farah', 'seeding' => 6],
];

// The quarter-finals as the organiser drew them. The bracket has 1 v 8 and
// 4 v 5 in its upper half; Dara and Emre train at one club, so the organiser
// had them change places: 1 v 5 and 4 v 8. Three of the four are played.
$fixtureRows = [
    ['round' => 1, 'label' => 'quarterfinal', 'first' => 12, 'second' => 17, 'winner' => 12],
    ['round' => 1, 'label' => 'quarterfinal', 'first' => 11, 'second' => 15, 'winner' => 11],
    ['round' => 1, 'label' => 'quarterfinal', 'first' => 13, 'second' => 16, 'winner' => null],
    ['round' => 1, 'label' => 'quarterfinal', 'first' => 14, 'second' => 18, 'winner' => 18],
];

/** @var list<int> $withdrawalRows The ids of the players who have withdrawn */
$withdrawalRows = [];

// --- The adapter: rows in, a stage state out ------------------------------
// This is all the mapping there is. Nothing here pairs, ranks or replays.
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

$engine = new SingleEliminationEngine();

// --- Request 1: the bracket page, with one quarter-final still to play ----
$state = $stateFromRows($playerRows, $fixtureRows, $withdrawalRows);

// Nothing throws for a round that is still being played
$completeWhileUnplayed = $engine->isComplete($state);
$readiness = $engine->getReadiness($state);
$waitingFor = $readiness->getUnresolvedEvents();

// The round is not the one the engine would have drawn, and is not refused:
// it is reported, with the engine's own round beside it
$quarterFinals = $engine->getRecordedRounds($state)[0];
$asDrawnByTheEngine = $quarterFinals->getDerivation()?->getEvents() ?? [];

// --- Request 2: the last quarter-final is played; pair the semifinals -----
$fixtureRows[2]['winner'] = 16;
$state = $stateFromRows($playerRows, $fixtureRows, $withdrawalRows);

// The semifinals follow from the quarter-finals that were played: Aiko and
// Noor won the two ties of the upper half and meet now, where the engine's
// own draw had them meet a round earlier
$semifinals = $engine->pairNextRound($state);
foreach ($semifinals->getEvents() as $event) {
    [$first, $second] = $event->getParticipants();
    $fixtureRows[] = [
        'round' => $semifinals->getRoundNumber(),
        'label' => $semifinals->getLabel(),
        'first' => (int) $first->getId(),
        'second' => (int) $second->getId(),
        'winner' => null,
    ];
}

// --- Request 3: Noor withdraws before her semifinal -----------------------
$withdrawalRows[] = 18;
$state = $stateFromRows($playerRows, $fixtureRows, $withdrawalRows);

// Nothing is drawn again. Her semifinal is not played and awaits no result;
// the bracket waits for the other one only
$afterTheWithdrawal = $engine->getRecordedRounds($state)[1];
$notPlayed = $afterTheWithdrawal->getUncontestedEvents();
$stillWaitingFor = $engine->getReadiness($state)->getUnresolvedEvents();

// --- Request 4: the other semifinal is played; pair the final -------------
$fixtureRows[5]['winner'] = 16;
$state = $stateFromRows($playerRows, $fixtureRows, $withdrawalRows);

// Aiko is in the final without having played her semifinal
$final = $engine->pairNextRound($state);
[$first, $second] = $final->getEvents()[0]->getParticipants();
$fixtureRows[] = [
    'round' => $final->getRoundNumber(),
    'label' => $final->getLabel(),
    'first' => (int) $first->getId(),
    'second' => (int) $second->getId(),
    'winner' => 11,
];

// --- Request 5: the outcome ------------------------------------------------
$state = $stateFromRows($playerRows, $fixtureRows, $withdrawalRows);
$outcome = $engine->getOutcome($state);
if ($outcome === null) {
    throw new RuntimeException('The bracket should be complete');
}

// --- A row that cannot be a fact of this stage -----------------------------
// Rows can say what no engine paired, so the history is checked: a fixture
// for a player who was never entered is refused, with a reason to branch on.
$entrants = $state->getEntrants();
$notEntered = null;
try {
    StageState::fromHistory(
        entrants: $entrants,
        rounds: [new RoundPairing(1, 'quarterfinal', [
            new Event([$entrants[0], new Participant('99', 'Not entered')], new Round(1)),
        ])]
    );
} catch (InvalidConfigurationException $exception) {
    $notEntered = $exception;
}

return Example::present(__FILE__, 'A stage resolved from the application\'s own rows', 'A badminton knockout of eight whose fixtures, results and withdrawals are rows of the application. Each request builds a stage state from them with StageState::fromHistory(). The quarter-finals were drawn by hand and are read as drawn; one of them is still unplayed and nothing throws; a semifinalist withdraws and her opponent goes on without the bracket being drawn again.', [
    'Quarter-finals, as the organiser drew them' => $quarterFinals->getPairing()->getEvents(),
    'Quarter-finals, as the engine would have drawn them' => $asDrawnByTheEngine,
    'Reported as paired by hand' => $quarterFinals->isManual(),
    'With one quarter-final unplayed' => [
        'Complete' => $completeWhileUnplayed,
        'The next round can be paired' => $readiness->isReady(),
        'Waiting for round' => $readiness->getRoundNumber(),
    ],
    'The quarter-final it waits for' => $waitingFor,
    'Semifinals, from the quarter-finals that were played' => $semifinals->getEvents(),
    'After Noor withdraws: not played' => $notPlayed,
    'After Noor withdraws: still to play' => $stillWaitingFor,
    'The final' => $final->getEvents(),
    'Final standings' => $outcome->getStandings(),
    'A fixture for a player who was not entered' => $notEntered,
    'Its reason' => $notEntered?->getReason()?->value,
]);
