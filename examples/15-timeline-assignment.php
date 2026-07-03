<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Timeline\BlackoutRule;
use MissionGaming\Tactician\Timeline\MinimumRestRule;
use MissionGaming\Tactician\Timeline\ScheduledSchedule;
use MissionGaming\Tactician\Timeline\TimelineAssigner;
use MissionGaming\Tactician\Timeline\TimelineDefinition;

// A six-team home-and-away league mapped onto real dates: weekly match
// days with three staggered kickoffs, declared once and assigned
// deterministically. Round-aligned scheduling is the same model with a
// single slot per round.

$teams = [
    new Participant('cel', 'Celtic', 1),
    new Participant('ath', 'Athletic Bilbao', 2),
    new Participant('liv', 'AS Livorno', 3),
    new Participant('red', 'Red Star FC', 4),
    new Participant('ray', 'Rayo Vallecano', 5),
    new Participant('cla', 'Clapton Community FC', 6),
];

$schedule = (new RoundRobinScheduler())->schedule($teams, new RoundRobinOptions(legs: 2));

// The application translates its competition config into the declarative
// slot model - Tactician owns the mechanism, never the policy
$timeline = TimelineDefinition::fromArray([
    'start' => '2026-08-01 18:00:00',
    'timezone' => 'Europe/London',   // wall-clock kickoffs survive DST
    'round_interval' => 'P7D',       // one match day per week
    'slots_per_round' => 2,          // 18:00 and 20:00 kickoffs...
    'slot_interval' => 'PT2H',
    'resources' => ['North Pitch', 'South Pitch'], // ...on two pitches
]);

// Time-aware rules guard the assignment: weekly rounds comfortably give
// every team 48 hours' rest, and no match day falls in the late-August
// representative break - a violated rule would fail loudly instead
$assigner = new TimelineAssigner([
    MinimumRestRule::fromArray(['rest' => 'PT48H']),
    BlackoutRule::fromArray(['windows' => [[
        'from' => '2026-08-31 00:00:00',
        'to' => '2026-09-01 00:00:00',
        'timezone' => 'Europe/London',
        'label' => 'representative break',
    ]]]),
]);

$scheduled = $assigner->assign($schedule, $timeline);

echo "=== Season calendar (kickoffs in UTC) ===\n\n";
foreach ($scheduled->getEventsByRound() as $round => $scheduledEvents) {
    $matchDay = $scheduledEvents[0]->getKickoff()->format('D j M Y');
    echo "Round {$round} - {$matchDay}\n";
    foreach ($scheduledEvents as $scheduledEvent) {
        [$home, $away] = $scheduledEvent->getEvent()->getParticipants();
        printf(
            "  %s  %-11s  %s vs %s\n",
            $scheduledEvent->getKickoff()->format('H:i'),
            (string) $scheduledEvent->getResource(),
            $home->getLabel(),
            $away->getLabel()
        );
    }
    echo "\n";
}

// The decorated view serializes; platforms persist assigned kickoffs and
// restore them without re-running assignment
$restored = ScheduledSchedule::fromJson($scheduled->toJson());
echo 'Serialized and restored ' . count($restored) . " scheduled events.\n";

// Kickoffs were declared as 18:00-20:00 London wall-clock; the whole
// season falls inside BST, so they emit uniformly as 17:00-19:00 UTC
$lastRound = array_key_last($scheduled->getEventsByRound());
$lastKickoff = $scheduled->getEventsByRound()[$lastRound][0]->getKickoff();
echo "Final round kicks off {$lastKickoff->format('D j M Y H:i')} UTC.\n";
