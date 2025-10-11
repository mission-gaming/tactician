<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\LegStrategies\MirroredLegStrategy;
use MissionGaming\Tactician\Ordering\BalancedParticipantOrderer;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

$participants = [
    new Participant('celtic', 'Celtic'),
    new Participant('athletic', 'Athletic'),
    new Participant('livorno', 'Livorno'),
    new Participant('redstar', 'RedStar'),
];

$orderer = new BalancedParticipantOrderer();
$scheduler = new RoundRobinScheduler(constraints: null, randomizer: null, participantOrderer: $orderer);
$schedule = $scheduler->generateSchedule($participants, 2, new MirroredLegStrategy());
$events = $schedule->getEvents();

echo "4 teams, 2 legs:\n";
echo "Leg 1 should have 3*2 = 6 events\n";
echo "Leg 2 should have 3*2 = 6 events\n\n";

for ($i = 0; $i < 12; $i++) {
    $event = $events[$i];
    $teams = $event->getParticipants();
    $leg = $i < 6 ? 1 : 2;
    printf("Event %2d (Leg %d): %s vs %s\n",
        $i, $leg, $teams[0]->getLabel(), $teams[1]->getLabel());
}

echo "\n\nCeltic's games:\n";
foreach ($events as $i => $event) {
    $teams = $event->getParticipants();
    $leg = $i < 6 ? 1 : 2;
    if ($teams[0]->getId() === 'celtic') {
        printf("Event %2d (Leg %d): Celtic (HOME) vs %s (away)\n", $i, $leg, $teams[1]->getLabel());
    } elseif ($teams[1]->getId() === 'celtic') {
        printf("Event %2d (Leg %d): %s (home) vs Celtic (AWAY)\n", $i, $leg, $teams[0]->getLabel());
    }
}
