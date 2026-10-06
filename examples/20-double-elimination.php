<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/support/Example.php';

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Examples\Example;
use MissionGaming\Tactician\Scheduling\DoubleEliminationEngine;
use MissionGaming\Tactician\Stage\MatchOutcomeSelector;
use MissionGaming\Tactician\Stage\StageState;

// Double elimination: a participant is out after its second loss, not its
// first. Losers of the winners bracket drop into the losers bracket, and
// the two bracket champions meet in the grand final.
//
// Entry is by list position: the first entrant is the top of the bracket.
// The seeds below are only read by this script, to decide who wins.
$entrants = [
    new Participant('magnus', 'Magnus', 1),
    new Participant('hikaru', 'Hikaru', 2),
    new Participant('fabiano', 'Fabiano', 3),
    new Participant('ding', 'Ding', 4),
];

$engine = new DoubleEliminationEngine();

// The same driver loop as every results-driven format: pair the next round
// from the state, play it, record the results
$state = StageState::start($entrants);
$rounds = [];
$upsetPlayed = false;

while (!$engine->isComplete($state)) {
    $pairing = $engine->pairNextRound($state);

    // "Play" the round: the better seed wins, except that the top seed
    // loses its opening match. That one upset sends the favourite the long
    // way round, through the losers bracket.
    $results = [];
    foreach ($pairing->getEvents() as $event) {
        [$first, $second] = $event->getParticipants();
        [$favourite, $underdog] = $first->getSeed() < $second->getSeed() ? [$first, $second] : [$second, $first];

        if (!$upsetPlayed && $favourite->getSeed() === 1) {
            $upsetPlayed = true;
            $results[] = new Result($event, $underdog);
        } else {
            $results[] = new Result($event, $favourite);
        }
    }

    $state = $state->withRoundPlayed($pairing, $results);
    $rounds['Round ' . $pairing->getRoundNumber() . ': ' . $pairing->getLabel()] = $results;
}

$outcome = $engine->getOutcome($state);
if ($outcome === null) {
    throw new RuntimeException('Bracket should be complete');
}

// There is no champion accessor: the title holder is the winner of the
// final round. Do not read it from rank 1 of the standings. The table orders
// the entrants by their results over the whole bracket, and an entrant who
// lost the grand final can have as many wins as the one who won it.
$champion = MatchOutcomeSelector::winners()->select($outcome)[0];

// The losers-bracket champion won the first grand final, which left both
// finalists on one loss, so the engine paired a deciding reset match.
// EliminationOptions(grandFinalReset: false) turns that off.
$losses = [];
foreach ($outcome->getStandings()->getEntries() as $entry) {
    $losses[$entry->getParticipant()->getLabel()] = $entry->getLosses();
}

return Example::present(__FILE__, 'Double elimination', 'A four-entrant double-elimination bracket driven to completion. The top seed loses its first match, comes back through the losers bracket, and wins the grand final at the second attempt.', [
    'Rounds as played' => $rounds,
    'Champion' => $champion,
    'Losses' => $losses,
    'Final standings' => $outcome->getStandings(),
]);
