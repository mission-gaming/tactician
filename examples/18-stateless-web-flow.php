<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/support/Example.php';

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Examples\Example;
use MissionGaming\Tactician\Scheduling\SwissPairingEngine;
use MissionGaming\Tactician\Stage\StageState;

// The web-application integration pattern the framework guides describe
// (docs/integrations/): a results-driven stage lives across stateless
// request cycles, with StageState serialized between them. Nothing is
// shared between "requests" here except the persisted JSON string -
// every cycle constructs a fresh engine and rehydrates the state, exactly
// as a controller or queued job would. Example 24 adds what a real stage
// also needs: the engine stamp, a level event and a corrected result.

/** @var array<string, string> $database A stand-in for your stage table */
$database = [];

/** @var list<array<string, int|string>> $requests What each request did */
$requests = [];

// --- Request 1: the organizer opens the stage -------------------------
// A Swiss round is paired in table order. Before any result exists the
// whole field is level, and the table then orders it by seed, then label,
// then id - not by position in this list. These entrants carry no seed
// and are listed in label order, so the first round pairs them as listed.
// Give every participant a seed when the opening order must be your own.
$entrants = [
    new Participant('ana', 'Ana'),
    new Participant('bea', 'Bea'),
    new Participant('cai', 'Cai'),
    new Participant('dia', 'Dia'),
    new Participant('eli', 'Eli'),
    new Participant('fio', 'Fio'),
];

$database['stage_42'] = StageState::start($entrants)->toJson();
$requests[] = ['Request' => 1, 'Did' => 'opened the stage', 'Events' => 0, 'State stored (bytes)' => strlen($database['stage_42'])];

// --- Requests 2..N: pair a round, play it, record results -------------
while (true) {
    // Each cycle: fresh engine, rehydrated state - no shared memory
    $engine = new SwissPairingEngine(plannedRounds: 3);
    $state = StageState::fromJson($database['stage_42']);

    if ($engine->isComplete($state)) {
        break;
    }

    $pairing = $engine->pairNextRound($state);

    // "Play" the round: lower ID wins, as good a rule as any for a demo
    $results = [];
    foreach ($pairing->getEvents() as $event) {
        [$first, $second] = $event->getParticipants();
        $winner = strcmp($first->getId(), $second->getId()) < 0 ? $first : $second;
        $results[] = new Result($event, $winner);
    }

    $state = $state->withRoundPlayed($pairing, $results);
    $database['stage_42'] = $state->toJson();

    $requests[] = [
        'Request' => count($requests) + 1,
        'Did' => 'paired and recorded round ' . $pairing->getRoundNumber(),
        'Events' => count($pairing->getEvents()),
        'State stored (bytes)' => strlen($database['stage_42']),
    ];
}

// --- Final request: the stage is complete, read the outcome -----------
$engine = new SwissPairingEngine(plannedRounds: 3);
$state = StageState::fromJson($database['stage_42']);
$outcome = $engine->getOutcome($state);
if ($outcome === null) {
    throw new RuntimeException('Stage should be complete');
}

return Example::present(__FILE__, 'A stage across stateless requests', 'A Swiss stage that lives only as a JSON string between requests: each request builds a fresh engine, restores the state, does one step and stores the state again.', [
    'Requests' => $requests,
    'Final standings, read by the last request' => $outcome->getStandings(),
]);
