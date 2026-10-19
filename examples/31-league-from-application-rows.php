<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/support/Example.php';

use MissionGaming\Tactician\DTO\Award;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Examples\Example;
use MissionGaming\Tactician\Scheduling\RoundRobinEngine;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Stage\Withdrawal;
use MissionGaming\Tactician\Standings\StandingsCalculator;
use MissionGaming\Tactician\Standings\WinDrawLossRanking;

// A chess club's rapid league of five, one round a week. The fixtures,
// results and withdrawals live in the club's own tables; no stage state is
// stored anywhere. On every request the rows are mapped to
// StageState::fromHistory() and the round-robin engine is asked what
// follows. Its rounds are those of the schedule RoundRobinScheduler
// publishes for the same players and options.

// --- The club's tables ------------------------------------------------------
// Players in the order the league was drawn from (rating order).
$playerRows = [
    ['id' => 'mira', 'name' => 'Mira'],
    ['id' => 'tomas', 'name' => 'Tomas'],
    ['id' => 'ines', 'name' => 'Ines'],
    ['id' => 'kofi', 'name' => 'Kofi'],
    ['id' => 'lena', 'name' => 'Lena'],
];

/** @var list<array{round: int, white: string, black: string, result: string|null, awarded?: string}> $gameRows */
$gameRows = [];

/** @var list<array{round: int, player: string}> $byeRows Who sat a round out: five players, one rests each week */
$byeRows = [];

/** @var list<array{round: int, paired_early: bool}> $roundRows */
$roundRows = [];

/** @var list<array{player: string, after_round: int}> $withdrawalRows */
$withdrawalRows = [];

// --- The adapter: rows in, a stage state out --------------------------------
$stateFromRows = static function (array $playerRows, array $gameRows, array $byeRows, array $roundRows, array $withdrawalRows): StageState {
    $players = [];
    foreach ($playerRows as $row) {
        $players[$row['id']] = new Participant($row['id'], $row['name']);
    }

    $events = [];
    $results = [];
    foreach ($gameRows as $row) {
        [$white, $black] = [$players[$row['white']], $players[$row['black']]];
        $event = new Event([$white, $black], new Round($row['round']));
        $events[$row['round']][] = $event;

        $award = isset($row['awarded']) ? new Award('awarded', $row['awarded']) : null;
        $results[] = match ($row['result']) {
            '1-0' => new Result($event, $white, award: $award),
            '0-1' => new Result($event, $black, award: $award),
            '½-½' => new Result($event, null, award: $award),
            null => null,
        };
    }

    $byes = [];
    foreach ($byeRows as $row) {
        $byes[$row['round']][] = $players[$row['player']];
    }

    $rounds = [];
    foreach ($roundRows as $row) {
        $rounds[] = new RoundPairing($row['round'], null, $events[$row['round']] ?? [], $byes[$row['round']] ?? [], $row['paired_early']);
    }

    return StageState::fromHistory(
        entrants: array_values($players),
        rounds: $rounds,
        results: array_values(array_filter($results)),
        withdrawals: array_map(
            static fn(array $row): Withdrawal => new Withdrawal($players[$row['player']], $row['after_round']),
            $withdrawalRows
        )
    );
};

// A round the engine pairs is stored whole: its games, who sits it out, and
// whether it was paired while a game of an earlier round was still to play
$store = static function (RoundPairing $round, array &$gameRows, array &$byeRows, array &$roundRows): void {
    foreach ($round->getEvents() as $event) {
        [$white, $black] = $event->getParticipants();
        $gameRows[] = ['round' => $round->getRoundNumber(), 'white' => $white->getId(), 'black' => $black->getId(), 'result' => null];
    }
    foreach ($round->getByes() as $bye) {
        $byeRows[] = ['round' => $round->getRoundNumber(), 'player' => $bye->getId()];
    }
    $roundRows[] = ['round' => $round->getRoundNumber(), 'paired_early' => $round->isPairedOnIncompleteResults()];
};

// Results as the arbiter enters them: white wins, unless told otherwise
$enter = static function (array &$gameRows, int $round, array $results = []): void {
    foreach ($gameRows as $index => $row) {
        if ($row['round'] === $round && $row['result'] === null) {
            $gameRows[$index]['result'] = $results[$row['white']] ?? '1-0';
        }
    }
};

// The club scores 1, ½ and 0, and resting earns nothing
$engine = new RoundRobinEngine(
    standingsCalculator: new StandingsCalculator(WinDrawLossRanking::oneHalfZero()->withByeValue(0.0))
);

// --- Weeks 1 and 2: pair, play, enter ---------------------------------------
foreach ([1, 2] as $week) {
    $state = $stateFromRows($playerRows, $gameRows, $byeRows, $roundRows, $withdrawalRows);
    $store($engine->pairNextRound($state), $gameRows, $byeRows, $roundRows);
    $enter($gameRows, $week, ['tomas' => '½-½']);
}
$state = $stateFromRows($playerRows, $gameRows, $byeRows, $roundRows, $withdrawalRows);
$roundOne = $engine->getRecordedRounds($state)[0]->getPairing();

// --- Week 3: a game is postponed, and the league plays on -------------------
$store($engine->pairNextRound($state), $gameRows, $byeRows, $roundRows);
$postponedGame = null;
foreach ($gameRows as $index => $row) {
    if ($row['round'] === 3 && $postponedGame === null) {
        $postponedGame = $index;   // the first game of the week is put off
        continue;
    }
    if ($row['round'] === 3) {
        $gameRows[$index]['result'] = '0-1';
    }
}
$state = $stateFromRows($playerRows, $gameRows, $byeRows, $roundRows, $withdrawalRows);
$readiness = $engine->getReadiness($state);
$waitingFor = $readiness->getUnresolvedEvents();

// The club pairs week 4 regardless, and the round says so
$weekFour = $engine->pairNextRoundOnIncompleteResults($state);
$store($weekFour, $gameRows, $byeRows, $roundRows);
$enter($gameRows, 4);

// --- After week 4: the postponed game is played, and Lena withdraws -------
// The result of week 3 arrives after week 4 was recorded: it is a row like
// any other, and the history takes the results of any round
$gameRows[$postponedGame]['result'] = '½-½';
$withdrawalRows[] = ['player' => 'lena', 'after_round' => 4];
$state = $stateFromRows($playerRows, $gameRows, $byeRows, $roundRows, $withdrawalRows);

// Week 5 is the draw's week 5: Lena's game is still in it, and awaits nothing
$weekFive = $engine->pairNextRound($state);
$store($weekFive, $gameRows, $byeRows, $roundRows);
$state = $stateFromRows($playerRows, $gameRows, $byeRows, $roundRows, $withdrawalRows);
$notToBePlayed = $engine->getRecordedRounds($state)[4]->getUncontestedEvents();
$awaitedInWeekFive = $engine->getReadiness($state)->getUnresolvedEvents();

// The club's rule awards a game a withdrawn player cannot play to the
// opponent; the library records the award, it does not make it
foreach ($gameRows as $index => $row) {
    if ($row['round'] === 5 && in_array('lena', [$row['white'], $row['black']], true)) {
        $gameRows[$index]['result'] = $row['white'] === 'lena' ? '0-1' : '1-0';
        $gameRows[$index]['awarded'] = 'Lena withdrew';
    }
}
$enter($gameRows, 5);

// --- The end of the league ---------------------------------------------------
$state = $stateFromRows($playerRows, $gameRows, $byeRows, $roundRows, $withdrawalRows);
$outcome = $engine->getOutcome($state);
if ($outcome === null) {
    throw new RuntimeException('The league should be complete');
}

// Every round is the round the scheduler publishes for the same players
$published = (new RoundRobinScheduler())->schedule($state->getEntrants());
$asPublished = true;
foreach ($state->getRoundsPlayed() as $round) {
    $asPublished = $asPublished && $round->getEvents() == $published->getEventsByRound()[$round->getRoundNumber()];
}

return Example::present(__FILE__, 'A league run from the application\'s own rows', 'A chess club\'s rapid league of five whose games, rests, rounds and withdrawals are rows of the application, read with StageState::fromHistory() on every request. The round-robin engine pairs one week at a time, the rounds of the schedule RoundRobinScheduler publishes; it waits for a postponed game until the club pairs on in so many words, and a player who withdraws keeps her games in the draw, which await nothing until the club awards them.', [
    'Week 1' => $roundOne->getEvents(),
    'Resting in week 1' => implode(', ', array_map(static fn(Participant $bye): string => $bye->getLabel(), $roundOne->getByes())),
    'Week 3, one game postponed' => [
        'The next week can be paired' => $readiness->isReady(),
        'Waiting for week' => $readiness->getRoundNumber(),
    ],
    'The game it waits for' => $waitingFor,
    'Week 4 paired while a game was postponed' => $weekFour->isPairedOnIncompleteResults(),
    'Week 5, after Lena withdraws: not to be played' => $notToBePlayed,
    'Week 5: the games still to play' => $awaitedInWeekFive,
    'Every week as the scheduler publishes it' => $asPublished,
    'Final standings' => $outcome->getStandings(),
]);
