<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\LegStrategies\MirroredLegStrategy;
use MissionGaming\Tactician\Ordering\BalancedParticipantOrderer;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

// Create participants for our tournament
$participants = [
    new Participant('celtic', 'Celtic'),
    new Participant('athletic', 'Athletic Bilbao'),
    new Participant('livorno', 'AS Livorno'),
    new Participant('redstar', 'Red Star FC'),
    new Participant('clapton', 'Clapton Community FC'),
    new Participant('rayo', 'Rayo Vallecano'),
    new Participant('lochar', 'Lochar Thistle'),
    new Participant('annan', 'Annan Athletic'),
    new Participant('dulwich', 'Dulwich Hamlet'),
    new Participant('boca', 'Boca Juniors'),
    new Participant('union', 'Union Berlin'),
    new Participant('bohemians', 'Bohemians 1905'),
];

$orderer = new BalancedParticipantOrderer();
$scheduler = new RoundRobinScheduler(constraints: null, randomizer: null, participantOrderer: $orderer);
$schedule = $scheduler->generateSchedule($participants, 2, new MirroredLegStrategy());
$events = $schedule->getEvents();

// Separate events by leg
$leg1Events = [];
$leg2Events = [];
foreach ($events as $index => $event) {
    if ($index < 66) { // First 66 events are Leg 1 (11 rounds × 6 matches)
        $leg1Events[] = $event;
    } else {
        $leg2Events[] = $event;
    }
}

// Track home/away counts per leg
$leg1Home = [];
$leg1Away = [];
$leg2Home = [];
$leg2Away = [];

foreach ($participants as $participant) {
    $id = $participant->getId();
    $leg1Home[$id] = 0;
    $leg1Away[$id] = 0;
    $leg2Home[$id] = 0;
    $leg2Away[$id] = 0;
}

foreach ($leg1Events as $event) {
    $teams = $event->getParticipants();
    $leg1Home[$teams[0]->getId()]++;
    $leg1Away[$teams[1]->getId()]++;
}

foreach ($leg2Events as $event) {
    $teams = $event->getParticipants();
    $leg2Home[$teams[0]->getId()]++;
    $leg2Away[$teams[1]->getId()]++;
}

echo "Home/Away Balance by Leg:\n";
echo str_repeat("=", 80) . "\n";
printf("%-25s | Leg 1: H/A  | Leg 2: H/A  | Total: H/A  | Balance\n", "Team");
echo str_repeat("-", 80) . "\n";

foreach ($participants as $participant) {
    $id = $participant->getId();
    $l1h = $leg1Home[$id];
    $l1a = $leg1Away[$id];
    $l2h = $leg2Home[$id];
    $l2a = $leg2Away[$id];
    $totalHome = $l1h + $l2h;
    $totalAway = $l1a + $l2a;
    $balance = $totalHome - $totalAway;

    printf("%-25s | %2d / %2d     | %2d / %2d     | %2d / %2d    | %+d\n",
        $participant->getLabel(), $l1h, $l1a, $l2h, $l2a, $totalHome, $totalAway, $balance);
}
