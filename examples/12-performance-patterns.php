<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/support/Example.php';

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Examples\Example;
use MissionGaming\Tactician\Examples\Measured;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

// How the size of a round robin grows with the field, and what it costs to
// generate and to read. The counts are exact and the same on every run. The
// durations are measured here and now, so they are not: each one is wrapped
// in Measured with the reason, which is how the page and the test suite know
// not to expect a fixed value.
$whyMeasured = 'A wall-clock duration depends on the machine and differs on every run.';

$field = static function (int $size): array {
    $participants = [];
    for ($number = 1; $number <= $size; ++$number) {
        $participants[] = new Participant('p' . $number, 'Participant ' . $number, $number);
    }

    return $participants;
};

// Generation: n participants play n(n-1)/2 events, so the work grows with
// the square of the field
$scheduler = new RoundRobinScheduler();
$generation = [];
foreach ([6, 12, 20, 30] as $size) {
    $participants = $field($size);

    $started = hrtime(true);
    $schedule = $scheduler->schedule($participants);
    $elapsed = (hrtime(true) - $started) / 1_000_000;

    $generation[$size . ' participants'] = [
        'Events' => count($schedule),
        'Rounds' => $schedule->getMetadataValue('total_rounds'),
        'Generated in' => new Measured($elapsed, 'ms', $whyMeasured),
    ];
}

// Reading: a schedule holds every event in memory already. The three ways
// below see the same events; they differ in convenience, and count() does no
// work per event.
$schedule = $scheduler->schedule($field(30));

$started = hrtime(true);
$seenByForeach = 0;
foreach ($schedule as $event) {
    ++$seenByForeach;
}
$foreachTime = (hrtime(true) - $started) / 1_000_000;

$started = hrtime(true);
$events = $schedule->getEvents();
$listTime = (hrtime(true) - $started) / 1_000_000;

$started = hrtime(true);
$counted = count($schedule);
$countTime = (hrtime(true) - $started) / 1_000_000;

return Example::present(__FILE__, 'Size and cost', 'How many events and rounds a round robin has as the field grows, how long generation takes on this machine, and what the ways of reading a schedule cost.', [
    'Generating a single-leg round robin' => $generation,
    'Reading the 30-participant schedule' => [
        'foreach' => ['Events seen' => $seenByForeach, 'Took' => new Measured($foreachTime, 'ms', $whyMeasured)],
        'getEvents()' => ['Events seen' => count($events), 'Took' => new Measured($listTime, 'ms', $whyMeasured)],
        'count()' => ['Events seen' => $counted, 'Took' => new Measured($countTime, 'ms', $whyMeasured)],
    ],
]);
