<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/support/Example.php';

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Examples\Example;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Scheduling\EliminationOptions;
use MissionGaming\Tactician\Scheduling\SingleEliminationEngine;
use MissionGaming\Tactician\Stage\MatchOutcomeSelector;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Stage\TieDecision;

// A results-driven stage as an application keeps it: the state is stored as
// JSON between requests, stamped with the fingerprint of the engine that
// pairs it. Along the way an event finishes level, a result is entered
// wrongly and corrected, and an engine with another configuration is handed
// the stored state.

// List order is the seeding: the first entrant is the top of the bracket
$entrants = [
    new Participant('ana', 'Ana'),
    new Participant('bea', 'Bea'),
    new Participant('cai', 'Cai'),
    new Participant('dia', 'Dia'),
];
[$ana, $bea, $cai, $dia] = $entrants;

/** @var array<string, string> $database A stand-in for the application's stage table */
$database = [];

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

// Ana and Dia finish level at 1-1. A bracket must send one of them on, and
// who that is belongs to the application's rules (here, a shoot-out that Dia
// won). The result is recorded as the draw it was, with the participant who
// advances named by id under the 'tie_winner' key.
//
// The other semifinal is entered as a win for Cai. That is a mistake: Bea won.
$state = $state->withRoundPlayed($semifinals, [
    new Result($anaVsDia, null, ['ana' => 1, 'dia' => 1], [TieDecision::TIE_WINNER_KEY => $dia->getId()]),
    new Result($beaVsCai, $cai, ['bea' => 2, 'cai' => 0]),
]);
$database['bracket'] = $state->toJson();

// The final this state leads to, before the mistake is noticed
$finalAsEntered = $engine->pairNextRound($state)->getEvents()[0];

// --- Request 3: correct the result ---------------------------------------
// withResultReplaced() replaces one result of the last recorded round, which
// is the only round nothing has been paired from yet. The event is found by
// its round and participants, so the one that came back from storage will do.
$state = StageState::fromJson($database['bracket']);
$storedBeaVsCai = $state->getRoundsPlayed()[0]->getEvents()[1];
$state = $state->withResultReplaced(new Result($storedBeaVsCai, $bea, ['bea' => 2, 'cai' => 0]));
$database['bracket'] = $state->toJson();

// --- Request 4: pair the final and record it ------------------------------
$engine = new SingleEliminationEngine();
$state = StageState::fromJson($database['bracket']);
$final = $engine->pairNextRound($state);
$finalEvent = $final->getEvents()[0];
$state = $state->withRoundPlayed($final, [new Result($finalEvent, $bea, ['dia' => 0, 'bea' => 1])]);
$database['bracket'] = $state->toJson();

// --- Request 5: read the outcome -----------------------------------------
$engine = new SingleEliminationEngine();
$state = StageState::fromJson($database['bracket']);
$outcome = $engine->getOutcome($state);
if ($outcome === null) {
    throw new RuntimeException('The bracket should be complete');
}

// Who took the title is read from the results of the final round
$titleHolder = MatchOutcomeSelector::winners()->select($outcome)[0];

// The level semifinal is still a draw in the record; advancer() is how the
// engines read who went on
$levelResult = $state->getResults()[0];
$advanced = TieDecision::advancer([$levelResult], $ana, $dia, 1);

// --- What the engine refuses, and how the application tells why -----------
// Each refusal is an InvalidConfigurationException. Its reason is an enum
// case with a stable string value: branch on that, not on the message.

// 1. An engine configured differently from the one that stamped the state.
//    Two-legged ties are another bracket, and this state was not paired as one.
$wrongEngine = null;
try {
    (new SingleEliminationEngine(new EliminationOptions(legsPerTie: 2)))->isComplete($state);
} catch (InvalidConfigurationException $exception) {
    $wrongEngine = $exception;
}

// 2. A level event recorded without naming who advances. The state accepts
//    the result, because it does not know the format; the engine refuses it.
$undecided = StageState::start($entrants)->withRoundPlayed($semifinals, [
    new Result($anaVsDia, null, ['ana' => 1, 'dia' => 1]),
    new Result($beaVsCai, $bea, ['bea' => 2, 'cai' => 0]),
]);
$undecidedTie = null;
try {
    $engine->pairNextRound($undecided);
} catch (InvalidConfigurationException $exception) {
    $undecidedTie = $exception;
}

// 3. Correcting a round that a later round was paired from. The final holds
//    the semifinal winners, so a changed semifinal would leave a final no
//    engine paired from it.
$tooLate = null;
try {
    $state->withResultReplaced(new Result($storedBeaVsCai, $cai));
} catch (InvalidConfigurationException $exception) {
    $tooLate = $exception;
}

return Example::present(__FILE__, 'Recording the results of a bracket', 'A four-entrant bracket kept as stamped JSON between requests. One semifinal finishes level and the application names who advances; the other is entered wrongly and corrected before the final is paired; an engine with another configuration is refused the stored state.', [
    'Semifinals, as first recorded' => [
        new Result($anaVsDia, null, ['ana' => 1, 'dia' => 1], [TieDecision::TIE_WINNER_KEY => $dia->getId()]),
        new Result($beaVsCai, $cai, ['bea' => 2, 'cai' => 0]),
    ],
    'The level semifinal' => [
        'Recorded as a draw' => $levelResult->isDraw(),
        'Recorded winner' => $levelResult->getWinner()?->getLabel(),
        'Who advanced' => $advanced?->getLabel(),
    ],
    'The final' => [
        'As the wrong result paired it' => $finalAsEntered,
        'After the correction' => $finalEvent,
    ],
    'Results in the stored state' => $state->getResults(),
    'Title holder' => $titleHolder,
    'Final standings' => $outcome->getStandings(),
    'The stamp' => [
        'Stored with the state' => $state->getEngineFingerprint() === $engine->getFingerprint(),
        'Same for an engine built again with the same options' => (new SingleEliminationEngine())->getFingerprint() === $engine->getFingerprint(),
    ],
    'An engine for two-legged ties' => $wrongEngine,
    'Its reason' => $wrongEngine?->getReason()?->value,
    'A level event with nobody named' => $undecidedTie,
    'The reason for that' => $undecidedTie?->getReason()?->value,
    'Correcting a semifinal after the final' => $tooLate,
    'The reason for that one' => $tooLate?->getReason()?->value,
]);
