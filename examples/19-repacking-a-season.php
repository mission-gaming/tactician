<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use MissionGaming\Tactician\DTO\Participant;
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
// staggered slots. The final session runs one slot deeper: overridable
// per session, because real recovery grids are irregular.
$grid = SessionGrid::fromArray([
    'sessions' => ['2026-08-12 20:00', '2026-08-19 20:00'],
    'timezone' => 'Europe/London',
    'slot_interval' => 'PT30M',
    'slots_per_session' => 3,
    'slots_per_session_overrides' => [1 => 4],
    'capacity_per_slot' => 3,
]);

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

$outcome = (new ScheduleRepacker())->repack(new RepackRequest(
    $movable,
    $pinned,
    $grid,
    // The explicit trade between "fuller nights per participant" and "end
    // the season sooner"; consolidation dominates by default.
    new RepackOptions(consolidationWeight: 3, earlyFillWeight: 1)
));

$names = [];
foreach ([$celtic, $athletic, $livorno, $redStar, $rayo, $clapton] as $participant) {
    $names[$participant->getId()] = $participant->getLabel();
}
$pairings = [];
foreach ($movable as $event) {
    $pairings[$event->getId()] = sprintf(
        '%s v %s',
        $names[$event->getParticipantA()->getId()],
        $names[$event->getParticipantB()->getId()]
    );
}

echo "Repacked schedule (kickoffs in UTC):\n";
foreach ($outcome->getAssignments() as $assignment) {
    printf(
        "  session %d slot %d  %s  %s (%s)\n",
        $assignment->getSession(),
        $assignment->getSlot(),
        $assignment->getKickoff()->format('Y-m-d H:i'),
        $pairings[$assignment->getEventId()],
        $assignment->getEventId()
    );
}

// Infeasibility is an outcome, not an exception: every compromise comes
// back as structured data for the caller to render and judge.
if ($outcome->isClean()) {
    echo "\nClean: every event placed, nobody double-booked, no gaps, no late starts.\n";
} else {
    echo "\nCompromises, itemised:\n";
    foreach ($outcome->getViolations() as $violation) {
        echo '  ' . json_encode($violation->toArray()) . "\n";
    }
    foreach ($outcome->getUnplaced() as $unplaced) {
        printf("  unplaced: %s (%s)\n", $unplaced->getEventId(), $unplaced->getReason()->value);
    }
}
