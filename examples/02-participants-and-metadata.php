<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/support/Example.php';

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Examples\Example;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

// A participant is an id, a label, an optional seed and any metadata your
// application wants to carry along. The library never interprets the
// metadata; constraints and your own code can read it.
$participants = [
    new Participant('celtic', 'Celtic FC', 1, ['city' => 'Glasgow', 'country' => 'Scotland', 'founded' => 1887]),
    new Participant('athletic', 'Athletic Bilbao', 2, ['city' => 'Bilbao', 'country' => 'Spain', 'founded' => 1898]),
    new Participant('livorno', 'AS Livorno', 3, ['city' => 'Livorno', 'country' => 'Italy', 'founded' => 1915]),
    new Participant('rayo', 'Rayo Vallecano', 4, ['city' => 'Madrid', 'country' => 'Spain', 'founded' => 1924]),
    new Participant('stpauli', 'FC St. Pauli', 5, ['city' => 'Hamburg', 'country' => 'Germany', 'founded' => 1910]),
    new Participant('clapton', 'Clapton Community FC', 6, ['city' => 'London', 'country' => 'England', 'founded' => 2018]),
];

$schedule = (new RoundRobinScheduler())->schedule($participants);

// Reading a participant: metadata has a presence check and a default
$first = $participants[0];
$reading = [
    'getId()' => $first->getId(),
    'getLabel()' => $first->getLabel(),
    'getSeed()' => $first->getSeed(),
    "getMetadataValue('city')" => $first->getMetadataValue('city'),
    "hasMetadata('stadium')" => $first->hasMetadata('stadium'),
    "getMetadataValue('stadium', 'unknown')" => $first->getMetadataValue('stadium', 'unknown'),
];

// Your own grouping, straight from the metadata
$labelsByCountry = [];
foreach ($participants as $participant) {
    $labelsByCountry[(string) $participant->getMetadataValue('country')][] = $participant->getLabel();
}

// The events of a schedule hold the same participants, metadata included, so
// the opening round can be listed with each side's home city
$openingRound = [];
foreach ($schedule->getEventsByRound()[1] as $event) {
    [$firstNamed, $secondNamed] = $event->getParticipants();
    $openingRound[] = [
        'First named' => $firstNamed->getLabel(),
        'City' => $firstNamed->getMetadataValue('city'),
        'Second named' => $secondNamed->getLabel(),
        'Their city' => $secondNamed->getMetadataValue('city'),
    ];
}

return Example::present(__FILE__, 'Participants and metadata', 'Participants carry a seed and free-form metadata, and the events of a schedule hand the same participants back.', [
    'Participants' => $participants,
    'Reading the first participant' => $reading,
    'Labels by country' => $labelsByCountry,
    'Opening round, with cities from the metadata' => $openingRound,
    'Schedule' => $schedule,
]);
