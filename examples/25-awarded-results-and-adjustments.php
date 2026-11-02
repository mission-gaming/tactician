<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/support/Example.php';

use MissionGaming\Tactician\DTO\Award;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Examples\Example;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Standings\Adjustment;
use MissionGaming\Tactician\Standings\AwardedResultPolicy;
use MissionGaming\Tactician\Standings\StandingEntry;
use MissionGaming\Tactician\Standings\Standings;
use MissionGaming\Tactician\Standings\StandingsCalculator;

// Four squads of an online league play each other once, best of three maps
$participants = [
    new Participant('aurora', 'Aurora'),
    new Participant('basilisk', 'Basilisk'),
    new Participant('cinder', 'Cinder'),
    new Participant('drift', 'Drift'),
];

$schedule = new RoundRobinScheduler()->schedule($participants);

// The maps won in each series, by the ids of the two squads. Cinder v Drift
// has none: Drift did not check in, and the series was never played.
$maps = [
    'aurora|basilisk' => ['aurora' => 2, 'basilisk' => 0],
    'aurora|cinder' => ['aurora' => 2, 'cinder' => 1],
    'aurora|drift' => ['aurora' => 2, 'drift' => 1],
    'basilisk|cinder' => ['basilisk' => 1, 'cinder' => 2],
    'basilisk|drift' => ['basilisk' => 2, 'drift' => 0],
    'cinder|drift' => null,
];

$played = [];
$unplayed = null;
foreach ($schedule as $event) {
    [$first, $second] = $event->getParticipants();
    $ids = [$first->getId(), $second->getId()];
    sort($ids);
    $score = $maps[implode('|', $ids)];

    if ($score === null) {
        $unplayed = $event;

        continue;
    }

    $played[] = new Result($event, $score[$first->getId()] > $score[$second->getId()] ? $first : $second, $score);
}
assert($unplayed !== null);

// The league's decision, which the library records and does not make: the
// series is awarded to Cinder with a nominal 2-0. The label and the reason
// are the league's own words; nothing reads them.
[$cinder] = array_values(array_filter(
    $unplayed->getParticipants(),
    static fn(Participant $participant): bool => $participant->getId() === 'cinder'
));
$awarded = new Result(
    $unplayed,
    $cinder,
    ['cinder' => 2, 'drift' => 0],
    award: new Award('no-show', 'Drift did not check in before the deadline')
);
$results = [...$played, $awarded];

// An awarded result counts in the table like any other. What its nominal
// score does is the league's choice, set on the calculator: here it counts
// (the default), and here it does not.
$calculator = new StandingsCalculator();
$withoutNominalScore = new StandingsCalculator(
    awardedResults: AwardedResultPolicy::fromArray(['count_scores' => false])
);

$asPlayed = $calculator->calculate($participants, $played);
$withAward = $calculator->calculate($participants, $results);
$withAwardNoScore = $withoutNominalScore->calculate($participants, $results);

// A second decision of the league, unrelated to any series: Aurora has four
// points removed. An adjustment is an amount of the ranking value, kept
// apart from what the results earned.
$adjustment = new Adjustment('aurora', -4.0, 'ruling', 'Fielded an unregistered substitute');
$adjusted = $calculator->withAdjustments($withAward, [$adjustment]);

$line = static function (Standings $table, string $id): StandingEntry {
    foreach ($table->getEntries() as $entry) {
        if ($entry->getParticipant()->getId() === $id) {
            return $entry;
        }
    }

    throw new LogicException("No entry for {$id}.");
};

return Example::present(__FILE__, 'Awarded results and adjustments', 'A series that was never played is awarded, and a squad has points removed. The table counts the awarded result as a win, leaves the choice about its nominal score to the league, and shows the adjustment beside what was earned.', [
    'Results' => $results,
    'Table of the series played' => $asPlayed,
    'Table with the awarded series' => $withAward,
    'Cinder, by what the nominal score does' => [
        [
            'Nominal score' => 'counts',
            'Played' => $line($withAward, 'cinder')->getPlayed(),
            'Of which awarded' => $line($withAward, 'cinder')->getAwarded(),
            'Won' => $line($withAward, 'cinder')->getWins(),
            'Ranking value' => $line($withAward, 'cinder')->getRankingValue(),
            'Score difference' => $line($withAward, 'cinder')->getScoreDifference(),
        ],
        [
            'Nominal score' => 'does not count',
            'Played' => $line($withAwardNoScore, 'cinder')->getPlayed(),
            'Of which awarded' => $line($withAwardNoScore, 'cinder')->getAwarded(),
            'Won' => $line($withAwardNoScore, 'cinder')->getWins(),
            'Ranking value' => $line($withAwardNoScore, 'cinder')->getRankingValue(),
            'Score difference' => $line($withAwardNoScore, 'cinder')->getScoreDifference(),
        ],
    ],
    'Table with the adjustment' => $adjusted,
    'Earned, adjusted and total' => array_map(static fn(StandingEntry $entry): array => [
        'Participant' => $entry->getParticipant()->getLabel(),
        'Earned' => $entry->getEarnedRankingValue(),
        'Adjustment' => $entry->getAdjustment(),
        'Total' => $entry->getRankingValue(),
    ], array_values($adjusted->getEntries())),
    'The award as plain data' => $awarded->toArray()['award'] ?? [],
    'The adjustment as plain data' => $adjustment->toArray(),
]);
