<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/support/Example.php';

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Examples\Example;
use MissionGaming\Tactician\Scheduling\SwissPairingEngine;
use MissionGaming\Tactician\Stage\StageState;

// A Swiss chess-club tournament driven round by round with the stage
// driver loop - the single integration a platform writes for every
// results-driven format.
$players = [
    new Participant('magnus', 'Magnus', 1),
    new Participant('hikaru', 'Hikaru', 2),
    new Participant('fabiano', 'Fabiano', 3),
    new Participant('ding', 'Ding', 4),
    new Participant('alireza', 'Alireza', 5),
    new Participant('ian', 'Ian', 6),
    new Participant('wesley', 'Wesley', 7),
    new Participant('anish', 'Anish', 8),
];

$engine = new SwissPairingEngine(plannedRounds: 3);

// The driver loop: pair, play, record - the state carries all the
// between-round bookkeeping and would serialize to persistence in a real
// platform (StageState::fromJson($stored) to resume).
$state = StageState::start($players);
$rounds = [];
while (!$engine->isComplete($state)) {
    $pairing = $engine->pairNextRound($state);

    // "Play" each event: the higher-seeded player wins here
    $results = [];
    foreach ($pairing->getEvents() as $event) {
        [$first, $second] = $event->getParticipants();
        $winner = ($first->getSeed() ?? PHP_INT_MAX) < ($second->getSeed() ?? PHP_INT_MAX) ? $first : $second;
        $results[] = new Result($event, $winner);
    }

    $state = $state->withRoundPlayed($pairing, $results);

    // Round-trip the state through JSON, as a platform persisting between
    // rounds would
    $state = StageState::fromJson($state->toJson());

    $rounds['Round ' . $pairing->getRoundNumber()] = $results;
}

// Every format finishes as an outcome you can select from
$outcome = $engine->getOutcome($state);
if ($outcome === null) {
    throw new RuntimeException('Stage should be complete');
}

return Example::present(__FILE__, 'Swiss, round by round', 'Eight players over three Swiss rounds. Each round is paired from the results so far, so players on the same score meet and nobody meets the same opponent twice.', [
    'Rounds as played' => $rounds,
    'Final standings' => $outcome->getStandings(),
    'Outcome' => [
        'Last round played' => $outcome->getFinalRound()?->getRoundNumber(),
        'Results recorded' => count($outcome->getResults()),
    ],
]);
