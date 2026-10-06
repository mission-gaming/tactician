<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/support/Example.php';

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Examples\Example;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Standings\BuchholzTiebreaker;
use MissionGaming\Tactician\Standings\SonnebornBergerTiebreaker;
use MissionGaming\Tactician\Standings\StandingsCalculator;
use MissionGaming\Tactician\Standings\WinDrawLossRanking;
use MissionGaming\Tactician\Standings\WinsTiebreaker;

$participants = [
    new Participant('ajax', 'Ajax'),
    new Participant('boca', 'Boca Juniors'),
    new Participant('celtic', 'Celtic'),
    new Participant('dynamo', 'Dynamo Kyiv'),
];

$schedule = (new RoundRobinScheduler())->schedule($participants);

// The scores of the six events, by the ids of the two participants. A
// result is recorded against the event the scheduler produced.
$scores = [
    'ajax|boca' => ['ajax' => 2, 'boca' => 1],
    'ajax|celtic' => ['ajax' => 0, 'celtic' => 3],
    'ajax|dynamo' => ['ajax' => 1, 'dynamo' => 0],
    'boca|celtic' => ['boca' => 1, 'celtic' => 0],
    'boca|dynamo' => ['boca' => 2, 'dynamo' => 0],
    'celtic|dynamo' => ['celtic' => 1, 'dynamo' => 1],
];

$results = [];
foreach ($schedule as $event) {
    [$first, $second] = $event->getParticipants();
    $ids = [$first->getId(), $second->getId()];
    sort($ids);
    $score = $scores[implode('|', $ids)];

    // The winner is whoever scored more; null records a draw
    $winner = match ($score[$first->getId()] <=> $score[$second->getId()]) {
        1 => $first,
        -1 => $second,
        0 => null,
    };
    $results[] = new Result($event, $winner, $score);
}

// The ranking strategy turns results into the primary value: 3 points for a
// win, 1 for a draw, 0 for a loss (the default). Ajax and Boca Juniors both
// finish on 6.
$ranking = WinDrawLossRanking::threeOneZero();

// With no tiebreakers a tie on the ranking value falls through to score
// difference: Boca Juniors (+2) finish above Ajax (-1)
$plain = (new StandingsCalculator($ranking))->calculate($participants, $results);

// A tiebreaker chain is applied in order, before score difference. Wins
// cannot separate the two (2 each), nor can Buchholz, the sum of the
// opponents' ranking values (11 each). Sonneborn-Berger, the sum of the
// ranking values of the opponents a participant beat, can: Ajax beat Boca
// Juniors, so Ajax finish first.
$chain = [new WinsTiebreaker(), new BuchholzTiebreaker(), new SonnebornBergerTiebreaker()];
$withChain = (new StandingsCalculator($ranking, $chain))->calculate($participants, $results);

return Example::present(__FILE__, 'Standings and tiebreakers', 'One set of results, two tables. Two participants tie on points; a chain of tiebreakers (wins, then Buchholz, then Sonneborn-Berger) orders them differently from the default fall-back to score difference.', [
    'Results' => $results,
    'Table without tiebreakers' => $plain,
    'Table with the tiebreaker chain' => $withChain,
    'Order at the top' => [
        'Without tiebreakers' => implode(', ', array_map(
            static fn ($entry): string => $entry->getParticipant()->getLabel(),
            array_slice($plain->getEntries(), 0, 2)
        )),
        'With the chain' => implode(', ', array_map(
            static fn ($entry): string => $entry->getParticipant()->getLabel(),
            array_slice($withChain->getEntries(), 0, 2)
        )),
    ],
]);
