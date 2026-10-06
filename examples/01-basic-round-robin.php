<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/support/Example.php';

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Examples\Example;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

// The smallest useful schedule: four participants who each meet every other
// participant once.
$participants = [
    new Participant('celtic', 'Celtic'),
    new Participant('athletic', 'Athletic Bilbao'),
    new Participant('livorno', 'AS Livorno'),
    new Participant('redstar', 'Red Star FC'),
];

// A single-leg round robin needs no constraints and no options
$scheduler = new RoundRobinScheduler();
$schedule = $scheduler->schedule($participants);

// A schedule is countable, and its metadata describes its shape
return Example::present(__FILE__, 'Basic round robin', 'Four participants, one leg: every pair meets exactly once, and every participant plays once in each round.', [
    'Participants' => $participants,
    'Schedule' => $schedule,
    'Shape' => [
        'Events' => count($schedule),
        'Rounds' => $schedule->getMetadataValue('total_rounds'),
        'Participants' => $schedule->getMetadataValue('participant_count'),
        'Legs' => $schedule->getMetadataValue('legs'),
        'Algorithm' => $schedule->getMetadataValue('algorithm'),
    ],
]);
