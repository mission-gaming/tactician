<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/support/Example.php';

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Examples\Example;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Scheduling\SingleEliminationEngine;
use MissionGaming\Tactician\Stage\BestOfRankSelector;
use MissionGaming\Tactician\Stage\BracketQualifiersSelector;
use MissionGaming\Tactician\Stage\CompositionValidator;
use MissionGaming\Tactician\Stage\PoolComparison;
use MissionGaming\Tactician\Stage\RankRangeSelector;
use MissionGaming\Tactician\Stage\StageOutcome;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Stage\StageTransition;
use MissionGaming\Tactician\Standings\StandingsCalculator;

// A floorball cup in three pools. The top two of every pool go through, and
// so do the two best of the clubs placed third.

$calculator = new StandingsCalculator();
$scheduler = new RoundRobinScheduler();

// One pool: its clubs in list order, and the score of every event by the
// ids of the two clubs. Returns the pool's outcome.
$playPool = static function (array $clubs, array $scores) use ($calculator, $scheduler): StageOutcome {
    $results = [];
    foreach ($scheduler->schedule($clubs) as $event) {
        [$first, $second] = $event->getParticipants();
        $score = $scores["{$first->getId()}|{$second->getId()}"] ?? $scores["{$second->getId()}|{$first->getId()}"];
        $winner = match ($score[$first->getId()] <=> $score[$second->getId()]) {
            1 => $first,
            -1 => $second,
            0 => null,
        };
        $results[] = new Result($event, $winner, $score);
    }

    // The entrant list goes with the table: position in it decides where
    // results leave two clubs level
    return new StageOutcome($calculator->calculate($clubs, $results), $results, entrants: $clubs);
};

$north = [
    new Participant('alta', 'Alta'),
    new Participant('bodo', 'Bodo'),
    new Participant('gavle', 'Gavle'),
    new Participant('kemi', 'Kemi'),
];
$east = [
    new Participant('lahti', 'Lahti'),
    new Participant('narva', 'Narva'),
    new Participant('tartu', 'Tartu'),
    new Participant('vantaa', 'Vantaa'),
];
$west = [
    new Participant('bergen', 'Bergen'),
    new Participant('floro', 'Floro'),
    new Participant('molde', 'Molde'),
    new Participant('voss', 'Voss'),
];

$pools = [
    // Gavle are third on 4 points with a score difference of +1
    'North' => $playPool($north, [
        'alta|bodo' => ['alta' => 2, 'bodo' => 0],
        'alta|gavle' => ['alta' => 1, 'gavle' => 0],
        'alta|kemi' => ['alta' => 1, 'kemi' => 0],
        'bodo|gavle' => ['bodo' => 1, 'gavle' => 1],
        'bodo|kemi' => ['bodo' => 4, 'kemi' => 0],
        'gavle|kemi' => ['gavle' => 2, 'kemi' => 0],
    ]),
    // Tartu are third on 4 points with a score difference of 0
    'East' => $playPool($east, [
        'lahti|narva' => ['lahti' => 1, 'narva' => 1],
        'lahti|tartu' => ['lahti' => 1, 'tartu' => 0],
        'lahti|vantaa' => ['lahti' => 1, 'vantaa' => 0],
        'narva|tartu' => ['narva' => 0, 'tartu' => 1],
        'narva|vantaa' => ['narva' => 4, 'vantaa' => 0],
        'tartu|vantaa' => ['tartu' => 0, 'vantaa' => 0],
    ]),
    // Molde are third on 3 points
    'West' => $playPool($west, [
        'bergen|floro' => ['bergen' => 2, 'floro' => 0],
        'bergen|molde' => ['bergen' => 1, 'molde' => 0],
        'bergen|voss' => ['bergen' => 1, 'voss' => 0],
        'floro|molde' => ['floro' => 2, 'molde' => 1],
        'floro|voss' => ['floro' => 1, 'voss' => 0],
        'molde|voss' => ['molde' => 1, 'voss' => 0],
    ]),
];

// One outcome for the whole stage. Its calculator orders the combined
// table, which is where the clubs placed third are compared
$stage = StageOutcome::combining($pools, $calculator, [...$north, ...$east, ...$west]);

$topTwo = RankRangeSelector::topPerGroup(2)->select($stage);
$bestThirds = BestOfRankSelector::ofRank(3, 2)->select($stage);

// The eight into a knockout: the two selections placed together so that no
// two clubs of one pool meet in the first round, wherever the best thirds
// come from. Composition validation checks the hand-off before any pool is
// played: three pools, two from each and two best thirds, make eight
$qualifiers = BracketQualifiersSelector::of(RankRangeSelector::topPerGroup(2), BestOfRankSelector::ofRank(3, 2));
$violations = (new CompositionValidator())->validateChain(12, [
    new StageTransition('quarterfinals', 8, $qualifiers, sourcePools: 3),
]);
$knockoutEntrants = $qualifiers->select($stage);
$firstRound = array_map(
    static fn(Event $event): string => implode(' v ', array_map(
        static fn(Participant $participant): string => $participant->getLabel(),
        $event->getParticipants()
    )),
    (new SingleEliminationEngine())->pairNextRound(StageState::start($knockoutEntrants))->getEvents()
);

// Another year, one club fewer: the west pool has three clubs, who each
// play one event less than the clubs of the other pools. Each of the three
// won once, and Molde are third on 3 points from 2 events
$westOfThree = array_slice($west, 0, 3);
$unequal = StageOutcome::combining([
    'North' => $pools['North'],
    'East' => $pools['East'],
    'West' => $playPool($westOfThree, [
        'bergen|floro' => ['bergen' => 0, 'floro' => 1],
        'bergen|molde' => ['bergen' => 3, 'molde' => 0],
        'floro|molde' => ['floro' => 0, 'molde' => 1],
    ]),
], $calculator, [...$north, ...$east, ...$westOfThree]);

// With pools of unequal size nothing is compared until the method is named
$refusal = null;
try {
    BestOfRankSelector::ofRank(3, 2)->select($unequal);
} catch (InvalidConfigurationException $exception) {
    $refusal = $exception;
}

$labels = static fn(array $participants): string => implode(', ', array_map(
    static fn(Participant $participant): string => $participant->getLabel(),
    $participants
));
$byMethod = [];
foreach (PoolComparison::cases() as $comparison) {
    $byMethod[$comparison->value] = $labels(BestOfRankSelector::ofRank(3, 2)->comparedBy($comparison)->select($unequal));
}

return Example::present(__FILE__, 'Best next-placed qualifiers', 'Three pools play a round robin each. The top two of every pool qualify, and the two best of the clubs placed third join them, placed for a knockout in which no two clubs of one pool meet in the first round. With pools of unequal size the clubs have played different numbers of events, and who is best depends on the comparison the caller names.', [
    'Pool tables' => array_map(static fn(StageOutcome $pool) => $pool->getStandings(), $pools),
    'Top two of every pool' => $topTwo,
    'The two best third-placed clubs, best first' => $bestThirds,
    'Violations of the hand-off to the quarterfinals' => $violations,
    'The eight placed for the knockout' => $knockoutEntrants,
    'Quarterfinals' => $firstRound,
    'With a west pool of three' => [
        'West table' => $unequal->getPool('West')?->getStandings(),
        'No method chosen' => $refusal?->getReason()?->value,
        'Pool sizes named by the refusal' => $refusal?->getContext()['pool_sizes'] ?? [],
        'Best two by raw totals' => $byMethod['raw-totals'],
        'Best two per event played' => $byMethod['per-event'],
        'Best two without the results against the last-placed of the larger pools' => $byMethod['discard-lowest'],
    ],
]);
