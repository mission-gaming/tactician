<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
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

echo "=== Swiss Tournament: 8 players, 3 rounds ===\n\n";

// The driver loop: pair, play, record - the state carries all the
// between-round bookkeeping and would serialize to persistence in a real
// platform (StageState::fromJson($stored) to resume).
$state = StageState::start($players);
while (!$engine->isComplete($state)) {
    $pairing = $engine->pairNextRound($state);
    echo "Round {$pairing->getRoundNumber()}\n";

    // "Play" each event: the higher-seeded player wins here
    $results = [];
    foreach ($pairing->getEvents() as $event) {
        [$first, $second] = $event->getParticipants();
        $winner = ($first->getSeed() ?? PHP_INT_MAX) < ($second->getSeed() ?? PHP_INT_MAX) ? $first : $second;
        $results[] = new Result($event, $winner);
        echo "  {$first->getLabel()} vs {$second->getLabel()} => {$winner->getLabel()}\n";
    }

    $state = $state->withRoundPlayed($pairing, $results);

    // Round-trip the state through JSON, as a platform persisting between
    // rounds would
    $state = StageState::fromJson($state->toJson());

    echo "\n";
}

// Every format finishes as an outcome you can select from
$outcome = $engine->getOutcome($state);
if ($outcome === null) {
    throw new RuntimeException('Stage should be complete');
}

echo "=== Final Standings ===\n";
foreach ($outcome->getStandings()->getEntries() as $position => $entry) {
    printf(
        "%d. %-8s %.1f pts (%dW %dD %dL)\n",
        $position + 1,
        $entry->getParticipant()->getLabel(),
        $entry->getRankingValue(),
        $entry->getWins(),
        $entry->getDraws(),
        $entry->getLosses()
    );
}

$finalRound = $outcome->getFinalRound();
echo "\nFinal round played: " . ($finalRound !== null ? "round {$finalRound->getRoundNumber()}" : 'none') . "\n";
echo 'Total results recorded: ' . count($outcome->getResults()) . "\n";
