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
use MissionGaming\Tactician\Stage\Withdrawal;

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
    ['id' => 18, 'name' => 'Noor', 'seeding' => 7],
    ['id' => 12, 'name' => 'Bram', 'seeding' => 2],
    ['id' => 14, 'name' => 'Dara', 'seeding' => 4],
    ['id' => 13, 'name' => 'Chidi', 'seeding' => 3],
    ['id' => 16, 'name' => 'Farah', 'seeding' => 6],
];

// The quarter-finals as the organiser drew them. With seven players the top
// of the list sits the round out, and the bracket has 4 v 5 and 2 v 7; Dara
// and Emre train at one club, so the organiser had Emre and Noor change
// places: 4 v 7 and 2 v 5. Two of the three are played.
$fixtureRows = [
    ['round' => 1, 'label' => 'quarterfinal', 'first' => 12, 'second' => 15, 'winner' => 12],
    ['round' => 1, 'label' => 'quarterfinal', 'first' => 14, 'second' => 18, 'winner' => 18],
    ['round' => 1, 'label' => 'quarterfinal', 'first' => 13, 'second' => 16, 'winner' => null],
];

// Who went on from a round without a fixture. A bye is no event, so it has
// no fixture row, and it is a fact of the round all the same: the engine
// reads a round from its fixtures and its byes together.
$byeRows = [
    ['round' => 1, 'player' => 11],
];

/** @var list<array{player: int, after_round: int}> $withdrawalRows Who has withdrawn, and after which round, in the order they did */
$withdrawalRows = [];

// --- The adapter: rows in, a stage state out ------------------------------
// This is all the mapping there is. Nothing here pairs, ranks or replays.
$stateFromRows = static function (array $playerRows, array $fixtureRows, array $byeRows, array $withdrawalRows): StageState {
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

    $byes = [];
    foreach ($byeRows as $row) {
        $byes[$row['round']][] = $players[$row['player']];
    }

    $rounds = [];
    foreach ($events as $number => $ofRound) {
        $rounds[] = new RoundPairing($number, $labels[$number], $ofRound, $byes[$number] ?? []);
    }

    return StageState::fromHistory(
        entrants: array_values($players),
        rounds: $rounds,
        results: $results,
        // Each withdrawal with the round it happened after, so that the
        // engine reads every round from who was in the stage at the time
        withdrawals: array_map(
            static fn(array $row): Withdrawal => new Withdrawal($players[$row['player']], $row['after_round']),
            $withdrawalRows
        )
    );
};

$engine = new SingleEliminationEngine();

// --- Request 1: the bracket page, with one quarter-final still to play ----
$state = $stateFromRows($playerRows, $fixtureRows, $byeRows, $withdrawalRows);

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
$state = $stateFromRows($playerRows, $fixtureRows, $byeRows, $withdrawalRows);

// The semifinals follow from the quarter-finals that were played: Noor won
// the tie the organiser moved her to and meets Aiko now, where the engine's
// own draw had her in the lower half
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
// A round the engine pairs is stored whole: its byes with its fixtures
foreach ($semifinals->getByes() as $bye) {
    $byeRows[] = ['round' => $semifinals->getRoundNumber(), 'player' => (int) $bye->getId()];
}

// --- Request 3: Noor withdraws before her semifinal -----------------------
// The semifinals are the last round on record, and the row says so
$withdrawalRows[] = ['player' => 18, 'after_round' => $semifinals->getRoundNumber()];
$state = $stateFromRows($playerRows, $fixtureRows, $byeRows, $withdrawalRows);

// Nothing is drawn again. Her semifinal is not played and awaits no result;
// the bracket waits for the other one only
$afterTheWithdrawal = $engine->getRecordedRounds($state)[1];
$notPlayed = $afterTheWithdrawal->getUncontestedEvents();
$stillWaitingFor = $engine->getReadiness($state)->getUnresolvedEvents();

// --- Request 4: the other semifinal is played; pair the final -------------
$fixtureRows[4]['winner'] = 16;
$state = $stateFromRows($playerRows, $fixtureRows, $byeRows, $withdrawalRows);

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
foreach ($final->getByes() as $bye) {
    $byeRows[] = ['round' => $final->getRoundNumber(), 'player' => (int) $bye->getId()];
}

// --- Request 5: the outcome ------------------------------------------------
$state = $stateFromRows($playerRows, $fixtureRows, $byeRows, $withdrawalRows);
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

return Example::present(__FILE__, 'A stage resolved from the application\'s own rows', 'A badminton knockout of seven whose fixtures, byes, results and withdrawals are rows of the application. Each request builds a stage state from them with StageState::fromHistory(). The quarter-finals were drawn by hand and are read as drawn, with the bye of the round beside them; one of them is still unplayed and nothing throws; a semifinalist withdraws and her opponent goes on without the bracket being drawn again.', [
    'Quarter-finals, as the organiser drew them' => $quarterFinals->getPairing()->getEvents(),
    'Went on without a quarter-final' => implode(', ', array_map(static fn(Participant $bye): string => $bye->getLabel(), $quarterFinals->getPairing()->getByes())),
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
