<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/support/Example.php';

use MissionGaming\Tactician\Constraints\CallableConstraint;
use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Constraints\SeedProtectionConstraint;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Examples\Example;
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

// An eight-entrant league season, home and away, with two rules from the
// organiser. Seeds follow the tiers: three S-tier, three A-tier, two B-tier.
$entrants = [
    new Participant('fnatic', 'Fnatic', 1, ['tier' => 'S', 'region' => 'Europe']),
    new Participant('tsm', 'Team SoloMid', 2, ['tier' => 'S', 'region' => 'North America']),
    new Participant('t1', 'T1', 3, ['tier' => 'S', 'region' => 'Asia']),
    new Participant('g2', 'G2 Esports', 4, ['tier' => 'A', 'region' => 'Europe']),
    new Participant('cloud9', 'Cloud9', 5, ['tier' => 'A', 'region' => 'North America']),
    new Participant('geng', 'Gen.G', 6, ['tier' => 'A', 'region' => 'Asia']),
    new Participant('mad', 'MAD Lions', 7, ['tier' => 'B', 'region' => 'Europe']),
    new Participant('thieves', '100 Thieves', 8, ['tier' => 'B', 'region' => 'North America']),
];

// Whether an event pairs an S-tier entrant with a B-tier one
$isMismatch = static function (Event $event): bool {
    $tiers = array_map(static fn(Participant $participant): mixed => $participant->getMetadataValue('tier'), $event->getParticipants());

    return in_array('S', $tiers, true) && in_array('B', $tiers, true);
};

// Two legs of 7 rounds: 14 rounds in all. Both rules below reject events of
// the season the scheduler would otherwise produce. A rule the finished
// season cannot break has no place in the set: noRepeatPairings() is one in
// a round robin (example 04), and so is a minimum gap between repeat
// meetings that the legs already give (example 06). With both of them
// added, this season comes out the same.
$constraints = ConstraintSet::create()
    // The top 2 seeds do not meet in the first 20% of the rounds (rounds 1 and 2)
    ->add(new SeedProtectionConstraint(2, 0.2))
    // No S-tier against B-tier in the opening two rounds
    ->add(new CallableConstraint(
        static fn(Event $event): bool => $event->getRound()?->getNumber() > 2 || !$isMismatch($event),
        'No S-tier against B-tier in rounds 1 and 2'
    ))
    ->build();

$homeAndAway = new RoundRobinOptions(legs: 2);

// For comparison: the same season with no rules
$unconstrained = (new RoundRobinScheduler())->schedule($entrants, $homeAndAway);

// The first participant order breaks both rules, so the scheduler retries
// with rotated orders and returns the first complete schedule that satisfies
// every constraint. Had none done so it would have thrown
// IncompleteScheduleException: see examples 11 and 16.
$schedule = (new RoundRobinScheduler($constraints))->schedule($entrants, $homeAndAway);

// Check the organiser's rules against a schedule
$check = static function (Schedule $schedule) use ($isMismatch): array {
    $topSeedsMeet = [];
    $mismatchRounds = [];
    foreach ($schedule as $event) {
        $round = (int) $event->getRound()?->getNumber();
        [$first, $second] = $event->getParticipants();
        if ($first->getSeed() <= 2 && $second->getSeed() <= 2) {
            $topSeedsMeet[] = $round;
        }
        if ($isMismatch($event)) {
            $mismatchRounds[] = $round;
        }
    }

    return [
        'Events' => count($schedule),
        'Rounds' => $schedule->getMetadataValue('total_rounds'),
        'Rounds in which seeds 1 and 2 meet' => implode(' and ', $topSeedsMeet),
        'Earliest round with S-tier against B-tier' => min($mismatchRounds),
    ];
};

return Example::present(__FILE__, 'Two rules over a two-leg season', 'A two-leg season under seed protection and a custom tier rule, in one constraint set. The season generated without them breaks both; with them both hold, and the season is still complete.', [
    'Entrants' => $entrants,
    'Constraints in the set' => count($constraints->getConstraints()),
    'Without constraints' => $check($unconstrained),
    'With the constraints' => $check($schedule),
    'Schedule with the constraints' => $schedule,
]);
