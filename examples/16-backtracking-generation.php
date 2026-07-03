<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

// A satisfiable configuration the greedy generator cannot solve. The
// circle method fixes which pairings share a round purely by list order,
// so its bounded rotations only ever see a handful of round
// decompositions - and this fixture-placement policy defeats all of them:
// the club demands its derby in round 1 and its two marquee fixtures in
// rounds 2 and 3.

$clubs = [
    new Participant('unt', 'FC United', 1),
    new Participant('cit', 'City Rovers', 2),
    new Participant('ath', 'Athletic Reds', 3),
    new Participant('wan', 'Wanderers', 4),
];

$fixturePolicy = ConstraintSet::create()->custom(static function (Event $event): bool {
    $ids = array_map(fn (Participant $p) => $p->getId(), $event->getParticipants());
    sort($ids);
    $round = $event->getRound()?->getNumber();
    if ($round === null) {
        return true;
    }

    return match (implode('|', $ids)) {
        'cit|unt' => $round === 1, // the derby opens the season
        'ath|unt' => $round === 2,
        'unt|wan' => $round === 3,
        default => true,
    };
}, 'Fixture Placement Policy')->build();

echo "=== Greedy generation (default) ===\n";
try {
    (new RoundRobinScheduler($fixturePolicy))->schedule($clubs);
    echo "Unexpectedly succeeded.\n";
} catch (IncompleteScheduleException $exception) {
    echo "Failed as expected: every rotated ordering violates the policy.\n\n";
}

echo "=== Backtracking generation (opt-in) ===\n";
$schedule = (new RoundRobinScheduler($fixturePolicy))
    ->schedule($clubs, new RoundRobinOptions(backtracking: true));

foreach ($schedule->getEventsByRound() as $round => $events) {
    echo "Round {$round}\n";
    foreach ($events as $event) {
        [$home, $away] = $event->getParticipants();
        echo "  {$home->getLabel()} vs {$away->getLabel()}\n";
    }
}

// Genuinely unsatisfiable configurations still fail loudly - the search
// proves it by exhausting the space rather than guessing
echo "\n=== Unsatisfiable configuration ===\n";
try {
    (new RoundRobinScheduler(ConstraintSet::create()->custom(fn () => false, 'Reject Everything')->build()))
        ->schedule($clubs, new RoundRobinOptions(backtracking: true));
} catch (IncompleteScheduleException $exception) {
    echo "Proven unsatisfiable: the search exhausted every round decomposition.\n";
}
