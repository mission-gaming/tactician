<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/support/Example.php';

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Examples\Example;
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

// Greedy generation (the default) fails: every rotated ordering violates
// the policy
$greedyFailure = null;
try {
    (new RoundRobinScheduler($fixturePolicy))->schedule($clubs);
} catch (IncompleteScheduleException $exception) {
    $greedyFailure = $exception;
}

// Backtracking generation (opt-in) searches the round decompositions the
// rotations cannot reach
$schedule = (new RoundRobinScheduler($fixturePolicy))
    ->schedule($clubs, new RoundRobinOptions(backtracking: true));

// Genuinely unsatisfiable configurations still fail loudly - the search
// proves it by exhausting the space rather than guessing
$rejectEverything = ConstraintSet::create()->custom(fn () => false, 'Reject Everything')->build();

$unsatisfiable = null;
$blocked = [];
try {
    (new RoundRobinScheduler($rejectEverything))->schedule($clubs, new RoundRobinOptions(backtracking: true));
} catch (IncompleteScheduleException $exception) {
    $unsatisfiable = $exception;

    // The failure carries a probed analysis naming which constraint
    // blocks which pairing where - not a guess, an evaluation
    $blocked = $exception->getAnalysis()?->getImpossiblePairings() ?? [];
}

return Example::present(__FILE__, 'Backtracking generation', 'A fixture policy that can be satisfied, but not by the default generator. RoundRobinOptions(backtracking: true) finds the schedule; a configuration with no solution still fails, with the blocked pairings named.', [
    'Default generation' => $greedyFailure,
    'Backtracking generation' => $schedule,
    'A constraint that rejects everything, with backtracking' => $unsatisfiable,
    'Blocked pairings it names' => $blocked,
]);
