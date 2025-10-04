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

$rand = new Random\Randomizer();
$orderer = new BalancedParticipantOrderer();
$scheduler = new RoundRobinScheduler(constraints: null, randomizer: null, participantOrderer: $orderer);
$schedule = $scheduler->generateSchedule($participants, 2, new MirroredLegStrategy());
$events = $schedule->getEvents();

foreach ($events as $index => $event) {
    $teams = $event->getParticipants();
    $homeTeam = $teams[0];
    $awayTeam = $teams[1];
    $homeTeamLabel = $homeTeam->getLabel();
    $awayTeamLabel = $awayTeam->getLabel();
    echo $index . ': ' . $homeTeamLabel . ' vs ' . $awayTeamLabel . '<br>';
}
