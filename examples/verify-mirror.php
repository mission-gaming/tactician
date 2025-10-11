<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\LegStrategies\MirroredLegStrategy;
use MissionGaming\Tactician\Ordering\BalancedParticipantOrderer;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

$participants = [
    new Participant('celtic', 'Celtic'),
    new Participant('athletic', 'Athletic Bilbao'),
    new Participant('livorno', 'AS Livorno'),
    new Participant('redstar', 'Red Star FC'),
    new Participant('clapton', 'Clapton Community FC'),
    new Participant('rayo', 'Rayo Vallecano'),
];

$orderer = new BalancedParticipantOrderer();
$scheduler = new RoundRobinScheduler(constraints: null, randomizer: null, participantOrderer: $orderer);
$schedule = $scheduler->generateSchedule($participants, 2, new MirroredLegStrategy());
$events = $schedule->getEvents();

$leg1Events = array_slice($events, 0, 15);
$leg2Events = array_slice($events, 15, 15);

echo "Checking if Leg 2 is a mirror of Leg 1:\n\n";

for ($i = 0; $i < count($leg1Events); $i++) {
    $leg1 = $leg1Events[$i];
    $leg2 = $leg2Events[$i];

    $leg1Teams = $leg1->getParticipants();
    $leg2Teams = $leg2->getParticipants();

    $leg1Home = $leg1Teams[0]->getLabel();
    $leg1Away = $leg1Teams[1]->getLabel();
    $leg2Home = $leg2Teams[0]->getLabel();
    $leg2Away = $leg2Teams[1]->getLabel();

    $isMirror = ($leg1Home === $leg2Away && $leg1Away === $leg2Home);

    printf("Event %2d: L1: %-25s vs %-25s | L2: %-25s vs %-25s | %s\n",
        $i,
        $leg1Home,
        $leg1Away,
        $leg2Home,
        $leg2Away,
        $isMirror ? '✓ MIRRORED' : '✗ NOT MIRRORED'
    );
}
