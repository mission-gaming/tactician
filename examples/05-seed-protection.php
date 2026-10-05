<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/support/Example.php';

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Constraints\SeedProtectionConstraint;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Examples\Example;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

// Eight seeded participants: seven rounds
$participants = [
    new Participant('celtic', 'Celtic FC', 1),
    new Participant('athletic', 'Athletic Bilbao', 2),
    new Participant('livorno', 'AS Livorno', 3),
    new Participant('redstar', 'Red Star FC', 4),
    new Participant('stpauli', 'FC St. Pauli', 5),
    new Participant('clapton', 'Clapton Community FC', 6),
    new Participant('rayo', 'Rayo Vallecano', 7),
    new Participant('union', 'Union Berlin', 8),
];

// Without protection the top two seeds meet in round 2
$unprotected = (new RoundRobinScheduler())->schedule($participants);

// SeedProtectionConstraint(2, 0.25): the top 2 seeds may not meet during the
// first quarter of the rounds. The window is the whole number of rounds in
// that fraction: a quarter of 7 rounds is round 1 only, so the schedule
// above is already allowed and comes out unchanged.
$quarter = ConstraintSet::create()
    ->add(new SeedProtectionConstraint(2, 0.25))
    ->build();
$protectedForAQuarter = (new RoundRobinScheduler($quarter))->schedule($participants);

// Half of 7 rounds is rounds 1 to 3. The first ordering breaks that, so the
// scheduler retries with rotated participant orders until one satisfies it.
$half = ConstraintSet::create()
    ->add(new SeedProtectionConstraint(2, 0.5))
    ->build();
$protectedForHalf = (new RoundRobinScheduler($half))->schedule($participants);

// The round in which seeds 1 and 2 meet
$topSeedsMeet = static function (Schedule $schedule): ?int {
    foreach ($schedule as $event) {
        [$first, $second] = $event->getParticipants();
        if ($first->getSeed() <= 2 && $second->getSeed() <= 2) {
            return $event->getRound()?->getNumber();
        }
    }

    return null;
};

return Example::present(__FILE__, 'Seed protection', 'SeedProtectionConstraint keeps the top seeds apart for a fraction of the rounds. The schedule is still a complete round robin: the meeting moves later, it is not removed.', [
    'Round in which seeds 1 and 2 meet' => [
        'No protection' => $topSeedsMeet($unprotected),
        'Top 2 protected for 25% of the rounds (round 1)' => $topSeedsMeet($protectedForAQuarter),
        'Top 2 protected for 50% of the rounds (rounds 1 to 3)' => $topSeedsMeet($protectedForHalf),
    ],
    'Schedule without protection' => $unprotected,
    'Schedule protected for 25% of the rounds' => $protectedForAQuarter,
    'Schedule protected for 50% of the rounds' => $protectedForHalf,
]);
