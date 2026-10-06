<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/support/Example.php';

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Examples\Example;
use MissionGaming\Tactician\Repack\MovableEvent;
use MissionGaming\Tactician\Repack\PinnedEvent;
use MissionGaming\Tactician\Repack\RepackOptions;
use MissionGaming\Tactician\Repack\RepackRequest;
use MissionGaming\Tactician\Repack\ScheduleRepacker;
use MissionGaming\Tactician\Repack\SessionGrid;

// A league season two-thirds played has fallen behind: the outstanding
// fixtures no longer sit on the published match nights, and the loads are
// wildly unequal — exactly the situation generation cannot help with,
// because the events already exist. Repacking assigns every movable event
// a (session, slot) position on a declared grid so that nobody is
// double-booked and each participant's games in a session run back to
// back.

$celtic = new Participant('cel', 'Celtic');
$athletic = new Participant('ath', 'Athletic Bilbao');
$livorno = new Participant('liv', 'AS Livorno');
$redStar = new Participant('red', 'Red Star FC');
$rayo = new Participant('ray', 'Rayo Vallecano');
$clapton = new Participant('cla', 'Clapton Community FC');

// The grid is an explicit list of session starts — not a cadence — with
// staggered slots. A session has two slots, and the final session runs two
// slots deeper: overridable per session, because real recovery grids are
// irregular. Here the season does not fit without it.
$shape = [
    'sessions' => ['2026-08-12 20:00', '2026-08-19 20:00'],
    'timezone' => 'Europe/London',
    'slot_interval' => 'PT30M',
    'slots_per_session' => 2,
    'capacity_per_slot' => 3,
];
$grid = SessionGrid::fromArray($shape + ['slots_per_session_overrides' => [1 => 4]]);

// Two events were genuinely played at grid positions already: historical
// provenance, never to be moved. Pins block their positions for both
// participants and consume slot capacity.
$pinned = [
    new PinnedEvent('e01', $celtic, $athletic, 0, 0),
    new PinnedEvent('e02', $livorno, $redStar, 0, 0),
];

// The outstanding fixtures — a multigraph: Celtic meet Rayo twice.
$movable = [
    new MovableEvent('e03', $celtic, $livorno),
    new MovableEvent('e04', $celtic, $rayo),
    new MovableEvent('e05', $celtic, $clapton),
    new MovableEvent('e06', $athletic, $redStar),
    new MovableEvent('e07', $athletic, $rayo),
    new MovableEvent('e08', $athletic, $clapton),
    new MovableEvent('e09', $livorno, $rayo),
    new MovableEvent('e10', $livorno, $clapton),
    new MovableEvent('e11', $redStar, $rayo),
    new MovableEvent('e12', $rayo, $celtic),
];

// Infeasibility is an outcome, not an exception: every compromise comes
// back as structured data for the caller to render and judge
$outcome = (new ScheduleRepacker())->repack(new RepackRequest(
    $movable,
    $pinned,
    $grid,
    // The explicit trade between "fuller nights per participant" and "end
    // the season sooner". These are the default weights, written out:
    // consolidation dominates.
    new RepackOptions(consolidationWeight: 3, earlyFillWeight: 1)
));

// The same request on a grid whose final session has two slots like the
// first: some fixtures find no position, and the outcome says which
$withoutTheDeeperSession = (new ScheduleRepacker())->repack(
    new RepackRequest($movable, $pinned, SessionGrid::fromArray($shape))
);

// The outcome speaks in event ids; the fixture list below maps them back
$fixtures = [];
foreach ([...$pinned, ...$movable] as $event) {
    $fixtures[$event->getId()] = $event->getParticipantA()->getLabel() . ' v ' . $event->getParticipantB()->getLabel();
}

return Example::present(__FILE__, 'Repacking a season', 'Ten outstanding fixtures placed around two already-played ones on a grid of two sessions, the second deeper than the first. Nobody is double-booked and each participant plays back to back within a session; the late starts that could not be avoided come back itemised.', [
    'Fixtures by event id' => $fixtures,
    'Repacked schedule' => $outcome,
    'Clean' => $outcome->isClean(),
    'Without the deeper final session' => [
        'Placed' => count($withoutTheDeeperSession->getAssignments()),
        'Unplaced' => count($withoutTheDeeperSession->getUnplaced()),
    ],
]);
