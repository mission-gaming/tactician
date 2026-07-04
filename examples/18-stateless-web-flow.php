<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Scheduling\SwissPairingEngine;
use MissionGaming\Tactician\Stage\StageState;

// The web-application integration pattern the framework guides describe
// (docs/integrations/): a results-driven stage lives across stateless
// request cycles, with StageState serialized between them. Nothing is
// shared between "requests" here except the persisted JSON string -
// every cycle constructs a fresh engine and rehydrates the state, exactly
// as a controller or queued job would.

/** @var array<string, string> $database A stand-in for your stage table */
$database = [];

// --- Request 1: the organizer opens the stage -------------------------
$entrants = [
    new Participant('ana', 'Ana', 1),
    new Participant('bea', 'Bea', 2),
    new Participant('cai', 'Cai', 3),
    new Participant('dia', 'Dia', 4),
    new Participant('eli', 'Eli', 5),
    new Participant('fio', 'Fio', 6),
];

$database['stage_42'] = StageState::start($entrants)->toJson();
echo 'Request 1: stage opened, state persisted (' . strlen($database['stage_42']) . " bytes)\n";

// --- Requests 2..N: pair a round, play it, record results -------------
$round = 0;
while (true) {
    // Each cycle: fresh engine, rehydrated state - no shared memory
    $engine = new SwissPairingEngine(plannedRounds: 3);
    $state = StageState::fromJson($database['stage_42']);

    if ($engine->isComplete($state)) {
        break;
    }

    $pairing = $engine->pairNextRound($state);
    ++$round;

    // "Play" the round: lower ID wins, as good a rule as any for a demo
    $results = [];
    foreach ($pairing->getEvents() as $event) {
        [$first, $second] = $event->getParticipants();
        $winner = strcmp($first->getId(), $second->getId()) < 0 ? $first : $second;
        $results[] = new Result($event, $winner);
    }

    $state = $state->withRoundPlayed($pairing, $results);
    $database['stage_42'] = $state->toJson();

    printf(
        "Request %d: round %d paired and recorded (%d events%s)\n",
        $round + 1,
        $pairing->getRoundNumber(),
        count($pairing->getEvents()),
        $pairing->getByes() === [] ? '' : ', ' . count($pairing->getByes()) . ' bye'
    );
}

// --- Final request: the stage is complete, read the outcome -----------
$engine = new SwissPairingEngine(plannedRounds: 3);
$state = StageState::fromJson($database['stage_42']);
$outcome = $engine->getOutcome($state);

echo "\nFinal standings:\n";
foreach ($outcome->getStandings()->getEntries() as $position => $entry) {
    printf("  %d. %s (%.1f)\n", $position + 1, $entry->getParticipant()->getLabel(), $entry->getRankingValue());
}
