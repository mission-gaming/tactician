<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/support/Example.php';

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Examples\Example;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

$participants = [
    new Participant('celtic', 'Celtic FC', 1),
    new Participant('athletic', 'Athletic Bilbao', 2),
    new Participant('livorno', 'AS Livorno', 3),
    new Participant('redstar', 'Red Star FC', 4),
    new Participant('stpauli', 'FC St. Pauli', 5),
    new Participant('clapton', 'Clapton Community FC', 6),
];

// Without constraints the first and the last seed open the season against
// each other
$unconstrained = (new RoundRobinScheduler())->schedule($participants);

// A constraint is a rule every event must satisfy. ConstraintSet::create()
// starts a builder; build() returns the set the scheduler takes.
//
// noRepeatPairings() forbids a pairing from occurring twice within a leg. A
// round robin never does that anyway, so here it changes nothing: it is the
// rule to add when your own constraints or a custom generator could.
$noRepeats = ConstraintSet::create()
    ->noRepeatPairings()
    ->build();
$withNoRepeats = (new RoundRobinScheduler($noRepeats))->schedule($participants);

// custom() takes any predicate over the event being placed. This one keeps
// seed 1 and seed 6 apart for the first two rounds.
$isTopAgainstBottom = static function (Event $event): bool {
    $seeds = array_map(static fn (Participant $participant): ?int => $participant->getSeed(), $event->getParticipants());

    return in_array(1, $seeds, true) && in_array(6, $seeds, true);
};

$constraints = ConstraintSet::create()
    ->noRepeatPairings()
    ->custom(
        static fn (Event $event): bool => $event->getRound()?->getNumber() > 2 || !$isTopAgainstBottom($event),
        'Seeds 1 and 6 apart in rounds 1 and 2'
    )
    ->build();

// A constraint is a hard filter: the scheduler finds a schedule that
// satisfies it or throws, it never drops the pairing
$constrained = (new RoundRobinScheduler($constraints))->schedule($participants);

// The round in which seed 1 meets seed 6
$meetingRound = static function (Schedule $schedule) use ($isTopAgainstBottom): ?int {
    foreach ($schedule as $event) {
        if ($isTopAgainstBottom($event)) {
            return $event->getRound()?->getNumber();
        }
    }

    return null;
};

return Example::present(__FILE__, 'Basic constraints', 'A constraint set is built once and given to the scheduler. A custom constraint moves one pairing out of the opening rounds; the schedule stays complete.', [
    'Round in which seed 1 meets seed 6' => [
        'Without constraints' => $meetingRound($unconstrained),
        'With noRepeatPairings() only' => $meetingRound($withNoRepeats),
        'With the custom constraint' => $meetingRound($constrained),
    ],
    'Constraints in the set' => array_map(
        static fn ($constraint): string => $constraint->getName(),
        $constraints->getConstraints()
    ),
    'Schedule without constraints' => $unconstrained,
    'Schedule with noRepeatPairings() only' => $withNoRepeats,
    'Schedule with the custom constraint' => $constrained,
]);
