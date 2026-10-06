<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/support/Example.php';

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Examples\Example;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Scheduling\SingleEliminationEngine;
use MissionGaming\Tactician\Stage\CompositionValidator;
use MissionGaming\Tactician\Stage\MatchOutcomeSelector;
use MissionGaming\Tactician\Stage\PoolDistributor;
use MissionGaming\Tactician\Stage\RankRangeSelector;
use MissionGaming\Tactician\Stage\StageOutcome;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Stage\StageTransition;
use MissionGaming\Tactician\Standings\StandingsCalculator;

// A World-Cup-style tournament composed from the generic primitives:
// serpentine pools -> round robin per pool -> combined outcome -> rank
// selection -> knockout preset. No group-stage monolith involved.

$teams = [
    new Participant('bra', 'Brazil', 1),
    new Participant('arg', 'Argentina', 2),
    new Participant('fra', 'France', 3),
    new Participant('eng', 'England', 4),
    new Participant('esp', 'Spain', 5),
    new Participant('ger', 'Germany', 6),
    new Participant('por', 'Portugal', 7),
    new Participant('ned', 'Netherlands', 8),
];

// "Play" events: the better (lower) seed always wins
$playEvents = static function (iterable $events): array {
    $results = [];
    foreach ($events as $event) {
        assert($event instanceof Event);
        [$first, $second] = $event->getParticipants();
        $winner = ($first->getSeed() ?? PHP_INT_MAX) < ($second->getSeed() ?? PHP_INT_MAX) ? $first : $second;
        $results[] = new Result($event, $winner);
    }

    return $results;
};

// The declared structure telescopes before any fixture exists
$violations = (new CompositionValidator())->validateChain(8, [
    new StageTransition('knockout', 4, RankRangeSelector::topPerGroup(2)),
    new StageTransition('final', 2, MatchOutcomeSelector::winners()),
]);
if ($violations !== []) {
    throw new RuntimeException(implode(' ', $violations));
}

// Group stage: 2 serpentine pools of 4, a round robin in each
$pools = PoolDistributor::serpentine($teams, pools: 2);
$calculator = new StandingsCalculator();
$scheduler = new RoundRobinScheduler();
$poolOutcomes = [];
$poolTables = [];

foreach ($pools as $label => $poolTeams) {
    $schedule = $scheduler->schedule($poolTeams);
    $results = $playEvents($schedule);

    // Never qualify from a partial table
    $unplayed = $scheduler->getPlan($poolTeams)->findUnplayedPairings($results);
    if ($unplayed !== []) {
        throw new RuntimeException("Pool {$label} incomplete: " . implode(', ', $unplayed));
    }

    $standings = $calculator->calculate($poolTeams, $results);
    $poolOutcomes[$label] = new StageOutcome($standings, $results);
    $poolTables['Pool ' . $label] = $standings;
}

// The hand-off: one combined outcome, top 2 per pool, winners first -
// the ordering fold seeding wants for cross-pool semifinals
$combined = StageOutcome::combining($poolOutcomes, $calculator);
$qualifiers = RankRangeSelector::topPerGroup(2)->select($combined);

// Knockout: position 1 of the qualifier list is seed 1
$knockout = new SingleEliminationEngine();
$state = StageState::start($qualifiers);
$knockoutRounds = [];

while (!$knockout->isComplete($state)) {
    $pairing = $knockout->pairNextRound($state);
    $results = $playEvents($pairing->getEvents());
    $state = $state->withRoundPlayed($pairing, $results);

    $knockoutRounds[ucfirst((string) $pairing->getLabel())] = $results;
}

$outcome = $knockout->getOutcome($state);
if ($outcome === null) {
    throw new RuntimeException('Knockout should be complete');
}

// "The champion" is the consumer's derivation of the outcome
$titleHolder = MatchOutcomeSelector::winners()->select($outcome)[0];

return Example::present(__FILE__, 'Groups into a knockout', 'Two pools play a round robin each, the top two of each pool qualify, and a single-elimination bracket decides the title. The group stage is a composition of the generic pieces, not a format of its own.', [
    'Pool tables' => $poolTables,
    'Qualifiers, in knockout seeding order' => $qualifiers,
    'Knockout' => $knockoutRounds,
    'Champion' => $titleHolder,
]);
