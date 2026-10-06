<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/support/Example.php';

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Examples\Example;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

// Five participants: an odd field, so one participant sits out each round
$participants = [
    new Participant('celtic', 'Celtic FC'),
    new Participant('athletic', 'Athletic Bilbao'),
    new Participant('livorno', 'AS Livorno'),
    new Participant('redstar', 'Red Star FC'),
    new Participant('stpauli', 'FC St. Pauli'),
];

$schedule = (new RoundRobinScheduler())->schedule($participants);

// A schedule holds all of its events in memory, as an array. Every way of
// reading it below returns the same event objects.

// 1. Iterate it directly
$iterated = [];
foreach ($schedule as $event) {
    $iterated[] = $event;
}

// 2. Count it
$total = count($schedule);

// 3. Take the events as a list, for random access
$events = $schedule->getEvents();
$firstEvent = $events[0];
$lastEvent = $events[count($events) - 1];

// 4. Group the events by round number, or ask for one round
$eventsByRound = $schedule->getEventsByRound();
$roundThree = $schedule->getEventsForRound(new Round(3));

$eventCountByRound = [];
foreach ($eventsByRound as $roundNumber => $roundEvents) {
    $eventCountByRound['Round ' . $roundNumber] = count($roundEvents);
}

// 5. Read the metadata: the shape of the schedule, and who has the bye in
//    each round (round number => participant id)
$byes = [];
foreach ($schedule->getMetadataValue('byes') as $roundNumber => $participantId) {
    $byes['Round ' . $roundNumber] = $participantId;
}

return Example::present(__FILE__, 'Reading a schedule', 'Five ways to read the same schedule: iterate it, count it, take the list of events, group it by round, and read its metadata.', [
    'Schedule' => $schedule,
    'Events seen by foreach' => count($iterated),
    'count($schedule)' => $total,
    'First event' => $firstEvent,
    'Last event' => $lastEvent,
    'Number of events in each round' => $eventCountByRound,
    'Round 3 only' => $roundThree,
    'Bye in each round' => $byes,
    'Metadata' => [
        'algorithm' => $schedule->getMetadataValue('algorithm'),
        'participant_count' => $schedule->getMetadataValue('participant_count'),
        'legs' => $schedule->getMetadataValue('legs'),
        'total_rounds' => $schedule->getMetadataValue('total_rounds'),
        'last round' => $schedule->getMaxRound()?->getNumber(),
    ],
]);
