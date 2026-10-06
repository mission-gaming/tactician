<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/support/Example.php';

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Constraints\MinimumRestPeriodsConstraint;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Examples\Example;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

$participants = [
    new Participant('hawks', 'Thunder Hawks'),
    new Participant('bolts', 'Lightning Bolts'),
    new Participant('eagles', 'Storm Eagles'),
    new Participant('runners', 'Wind Runners'),
    new Participant('phoenixes', 'Fire Phoenixes'),
    new Participant('wolves', 'Ice Wolves'),
];

// MinimumRestPeriodsConstraint(n) is about the same two participants meeting
// again: at least n rounds must separate one meeting of a pair from the
// next. It says nothing about how often a participant plays. In a round
// robin with an even field every participant plays in every round.
//
// So it only matters when pairs meet more than once: here, over two legs.
// Six participants play five rounds per leg, and the second leg repeats the
// first leg's rounds in order, so each pair meets again exactly 5 rounds
// later.
$twoLegs = new RoundRobinOptions(legs: 2);

$fiveRounds = ConstraintSet::create()
    ->add(new MinimumRestPeriodsConstraint(5))
    ->build();
$schedule = (new RoundRobinScheduler($fiveRounds))->schedule($participants, $twoLegs);

// Asking for 6 rounds between meetings cannot be met by this structure. A
// constraint is a hard filter, so generation fails loudly instead of
// returning a schedule that is missing the second leg.
$sixRounds = ConstraintSet::create()
    ->add(new MinimumRestPeriodsConstraint(6))
    ->build();

$failure = null;
try {
    (new RoundRobinScheduler($sixRounds))->schedule($participants, $twoLegs);
} catch (IncompleteScheduleException $exception) {
    $failure = $exception;
}

// Measure the gap between the two meetings of every pair
$roundsBetweenMeetings = static function (Schedule $schedule): array {
    $rounds = [];
    foreach ($schedule as $event) {
        $ids = array_map(static fn(Participant $participant): string => $participant->getId(), $event->getParticipants());
        sort($ids);
        $rounds[implode(' and ', $ids)][] = (int) $event->getRound()?->getNumber();
    }

    return array_map(static fn(array $meetings): int => $meetings[1] - $meetings[0], $rounds);
};
$gaps = $roundsBetweenMeetings($schedule);

return Example::present(__FILE__, 'Rest between repeat meetings', 'MinimumRestPeriodsConstraint sets how many rounds must pass before the same two participants meet again. It applies to schedules in which pairs meet more than once.', [
    'Rounds between the two meetings of a pair' => [
        'Smallest' => min($gaps),
        'Largest' => max($gaps),
        'Pairs' => count($gaps),
    ],
    'Two legs with at least 5 rounds between meetings' => $schedule,
    'Asking for at least 6 rounds' => $failure,
    'Events when asking for 6 rounds' => [
        'Generated before it failed' => $failure?->getActualEventCount(),
        'Needed for a complete schedule' => $failure?->getExpectedEventCount(),
    ],
]);
