<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
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

/**
 * Play events with the better (lower position) side always winning.
 *
 * @param iterable<Event> $events
 * @return array<Result>
 */
function playEvents(iterable $events): array
{
    $results = [];
    foreach ($events as $event) {
        [$first, $second] = $event->getParticipants();
        $winner = ($first->getSeed() ?? PHP_INT_MAX) < ($second->getSeed() ?? PHP_INT_MAX) ? $first : $second;
        $results[] = new Result($event, $winner);
    }

    return $results;
}

// The declared structure telescopes before any fixture exists
$violations = (new CompositionValidator())->validateChain(8, [
    new StageTransition('knockout', 4, RankRangeSelector::topPerGroup(2)),
    new StageTransition('final', 2, MatchOutcomeSelector::winners()),
]);
if ($violations !== []) {
    throw new RuntimeException(implode(' ', $violations));
}

echo "=== Group stage: 2 serpentine pools of 4 ===\n\n";

$pools = PoolDistributor::serpentine($teams, pools: 2);
$calculator = new StandingsCalculator();
$scheduler = new RoundRobinScheduler();
$poolOutcomes = [];

foreach ($pools as $label => $poolTeams) {
    $schedule = $scheduler->schedule($poolTeams);
    $results = playEvents($schedule);

    // Never qualify from a partial table
    $unplayed = $scheduler->getPlan($poolTeams)->findUnplayedPairings($results);
    if ($unplayed !== []) {
        throw new RuntimeException("Pool {$label} incomplete: " . implode(', ', $unplayed));
    }

    $standings = $calculator->calculate($poolTeams, $results);
    $poolOutcomes[$label] = new StageOutcome($standings, $results);

    echo "Pool {$label}:\n";
    foreach ($standings->getEntries() as $position => $entry) {
        printf(
            "  %d. %-12s %.0f pts\n",
            $position + 1,
            $entry->getParticipant()->getLabel(),
            $entry->getRankingValue()
        );
    }
    echo "\n";
}

// The hand-off: one combined outcome, top 2 per pool, winners first -
// the ordering fold seeding wants for cross-pool semifinals
$combined = StageOutcome::combining($poolOutcomes, $calculator);
$qualifiers = RankRangeSelector::topPerGroup(2)->select($combined);

echo '=== Knockout: ' . implode(', ', array_map(fn (Participant $p) => $p->getLabel(), $qualifiers)) . " ===\n\n";

$knockout = new SingleEliminationEngine();
$state = StageState::start($qualifiers); // position 1 = seed 1

while (!$knockout->isComplete($state)) {
    $pairing = $knockout->pairNextRound($state);
    echo ucfirst((string) $pairing->getLabel()) . ":\n";

    $results = playEvents($pairing->getEvents());
    foreach ($results as $result) {
        [$first, $second] = $result->getEvent()->getParticipants();
        echo "  {$first->getLabel()} vs {$second->getLabel()} => {$result->getWinner()?->getLabel()}\n";
    }
    echo "\n";

    $state = $state->withRoundPlayed($pairing, $results);
}

$outcome = $knockout->getOutcome($state);
if ($outcome === null) {
    throw new RuntimeException('Knockout should be complete');
}

// "The champion" is the consumer's derivation of the outcome
$titleHolder = MatchOutcomeSelector::winners()->select($outcome)[0];
echo "Champions: {$titleHolder->getLabel()}\n";
