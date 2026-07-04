<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\LegStrategies\ShuffledLegStrategy;
use MissionGaming\Tactician\Quality\PairingSpacingMetric;
use MissionGaming\Tactician\Quality\RoleBalanceMetric;
use MissionGaming\Tactician\Quality\RoleStreakMetric;
use MissionGaming\Tactician\Quality\ScheduleOptimizer;
use MissionGaming\Tactician\Quality\ScheduleScorer;
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use Random\Engine\Mt19937;
use Random\Randomizer;

// Constraints are hard filters; quality is graded. A shuffled two-leg
// league is valid however the coin flips land, but some samples alternate
// roles evenly and space repeat fixtures nicely - and some don't. The
// optimizer generates candidates from one master seed and keeps the best.

$players = [
    new Participant('p1', 'Magnus', 1),
    new Participant('p2', 'Hikaru', 2),
    new Participant('p3', 'Fabiano', 3),
    new Participant('p4', 'Ding', 4),
    new Participant('p5', 'Alireza', 5),
    new Participant('p6', 'Gukesh', 6),
];

// Policy: colour balance matters most, then alternation, then how the
// two meetings of each pair spread across the season
$scorer = new ScheduleScorer([
    ['metric' => new RoleBalanceMetric(), 'weight' => 3.0],
    ['metric' => new RoleStreakMetric(), 'weight' => 2.0],
    ['metric' => new PairingSpacingMetric(), 'weight' => 1.0],
]);

// Every randomness source uses the child randomizer, so the whole run
// reproduces from the single master seed
$generate = fn (Randomizer $r) => (new RoundRobinScheduler(null, $r))->schedule(
    $players,
    new RoundRobinOptions(legs: 2, strategy: new ShuffledLegStrategy($r))
);

$optimizer = new ScheduleOptimizer($scorer, new Randomizer(new Mt19937(2026)));

$single = $optimizer->optimize($generate, 1);
$best = (new ScheduleOptimizer($scorer, new Randomizer(new Mt19937(2026))))
    ->optimize($generate, 25);

echo "=== First sample vs best of 25 ===\n\n";
printf("%-18s %12s %12s\n", 'Metric', 'first', 'best');
foreach ($single->getReport() as $name => $value) {
    printf("%-18s %12.3f %12.3f\n", $name, $value, $best->getReport()[$name]);
}
printf("%-18s %12.3f %12.3f\n", 'Weighted score', $single->getScore(), $best->getScore());

printf(
    "\nBest sample chosen from %d generated candidates (%d failed).\n",
    $best->getSamplesGenerated(),
    $best->getSamplesFailed()
);
