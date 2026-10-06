<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/support/Example.php';

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Examples\Example;
use MissionGaming\Tactician\LegStrategies\MirroredLegStrategy;
use MissionGaming\Tactician\LegStrategies\RepeatedLegStrategy;
use MissionGaming\Tactician\LegStrategies\ShuffledLegStrategy;
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use Random\Engine\Mt19937;
use Random\Randomizer;

// A four-club league that plays everyone twice. The position of a
// participant in an event is its role: read the first-named participant as
// the home side.
$clubs = [
    new Participant('mci', 'Manchester City'),
    new Participant('ars', 'Arsenal'),
    new Participant('liv', 'Liverpool'),
    new Participant('che', 'Chelsea'),
];

$scheduler = new RoundRobinScheduler();

// Legs is the number of times each pair meets. Rounds are numbered
// continuously across legs: with 3 rounds per leg, leg 2 is rounds 4 to 6.
// A leg strategy decides how each later leg derives from the first.

// MirroredLegStrategy (the default): every pairing swaps roles in the next
// leg, so each pair meets once at each side's home
$mirrored = $scheduler->schedule($clubs, new RoundRobinOptions(legs: 2, strategy: new MirroredLegStrategy()));

// RepeatedLegStrategy: the next leg repeats the first, roles included
$repeated = $scheduler->schedule($clubs, new RoundRobinOptions(legs: 2, strategy: new RepeatedLegStrategy()));

// ShuffledLegStrategy: the roles in each later pairing are drawn at random.
// Pass a seeded Randomizer and the draw is the same on every run.
$shuffled = $scheduler->schedule(
    $clubs,
    new RoundRobinOptions(legs: 2, strategy: new ShuffledLegStrategy(new Randomizer(new Mt19937(2026))))
);

// How often each club is first-named ("at home") over the season
$homeGames = static function (Schedule $schedule): array {
    $count = [];
    foreach ($schedule as $event) {
        [$home, $away] = $event->getParticipants();
        $count[$home->getLabel()] = ($count[$home->getLabel()] ?? 0) + 1;
        $count[$away->getLabel()] ??= 0;
    }
    ksort($count);

    return $count;
};

return Example::present(__FILE__, 'Legs: home and away', 'Two legs with each of the three leg strategies. Every pair meets once per leg; the strategies differ in which participant is named first the second time.', [
    'Shape of the mirrored schedule' => [
        'legs' => $mirrored->getMetadataValue('legs'),
        'rounds_per_leg' => $mirrored->getMetadataValue('rounds_per_leg'),
        'total_rounds' => $mirrored->getMetadataValue('total_rounds'),
        'events' => count($mirrored),
    ],
    'Mirrored legs' => $mirrored,
    'Home games per club, mirrored legs' => $homeGames($mirrored),
    'Repeated legs' => $repeated,
    'Home games per club, repeated legs' => $homeGames($repeated),
    'Shuffled legs (seed 2026)' => $shuffled,
    'Home games per club, shuffled legs' => $homeGames($shuffled),
]);
