<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/support/Example.php';

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Examples\Example;
use MissionGaming\Tactician\Repack\MovableEvent;
use MissionGaming\Tactician\Repack\PinnedEvent;
use MissionGaming\Tactician\Repack\RepackOutcome;
use MissionGaming\Tactician\Repack\RepackRequest;
use MissionGaming\Tactician\Repack\ScheduleRepacker;
use MissionGaming\Tactician\Repack\SessionGrid;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

// What an application writes around the library: an adapter. Its own records
// go in as participants, the library's output is copied into its own records,
// and later a repack moves those records onto the match nights that are left.
// Plain arrays stand in for the application's database rows; the integration
// guide (docs/integrations/symfony.md) walks through the same flow.

// --- The application's clubs --------------------------------------------
// The integer id is the application's primary key. The ranking is its own
// idea of strength and the library never sees it.
$clubRows = [
    ['id' => 31, 'name' => 'Harbour Athletic', 'ranking' => 3],
    ['id' => 7, 'name' => 'Northgate Rovers', 'ranking' => 1],
    ['id' => 52, 'name' => 'Millbrook Town', 'ranking' => 5],
    ['id' => 18, 'name' => 'Castle Park', 'ranking' => 2],
    ['id' => 44, 'name' => 'Eastfield United', 'ranking' => 4],
];

// In: one participant per club. The id is the primary key as a string, which
// is stable and unique; a name can change and can repeat. The scheduler works
// from the order of the list, so the adapter sorts the rows into the
// application's order and passes no seed.
usort($clubRows, static fn(array $a, array $b): int => $a['ranking'] <=> $b['ranking']);

$participantsByClubId = [];
foreach ($clubRows as $clubRow) {
    $participantsByClubId[$clubRow['id']] = new Participant((string) $clubRow['id'], $clubRow['name']);
}
$participants = array_values($participantsByClubId);

$schedule = (new RoundRobinScheduler())->schedule($participants);

// Out: one fixture row per event, with the round and both roles kept. The
// application gives each row an id of its own and is the source of truth from
// here on: the schedule object is not stored.
$fixtureRows = [];
foreach ($schedule->getEventsByRound() as $roundNumber => $events) {
    foreach ($events as $event) {
        [$firstNamed, $secondNamed] = $event->getParticipants();
        $fixtureRows[] = [
            'id' => sprintf('fx%02d', count($fixtureRows) + 1),
            'round' => $roundNumber,
            'home_club_id' => (int) $firstNamed->getId(),
            'away_club_id' => (int) $secondNamed->getId(),
            'night' => null,
            'slot' => null,
            'local_kickoff' => null,
            'state' => 'scheduled',
        ];
    }
}

// A bye is not an event. With five clubs one sits out each round, and the
// schedule's metadata says which (round number => participant id).
$byeRows = [];
foreach ($schedule->getMetadataValue('byes') as $roundNumber => $participantId) {
    $byeRows[] = ['round' => $roundNumber, 'club_id' => (int) $participantId];
}
$fixturesAsCopied = $fixtureRows;

// --- Some weeks later ---------------------------------------------------
// The application keeps local wall-clock times, per night. One round was to
// be played on each of five nights. Rounds 1 and 2 were played; the third
// night was lost to the weather and is gone from the calendar; one fixture of
// round 4 is locked to the start of the fourth night by the organiser.
$placeRound = static function (array $rows, int $round, string $night, string $kickoff, string $state): array {
    foreach ($rows as $index => $row) {
        if ($row['round'] === $round) {
            $rows[$index]['night'] = $night;
            $rows[$index]['slot'] = 0;
            $rows[$index]['local_kickoff'] = $kickoff;
            $rows[$index]['state'] = $state;
        }
    }

    return $rows;
};
$fixtureRows = $placeRound($fixtureRows, 1, 'night-1', '2026-09-03 19:00', 'played');
$fixtureRows = $placeRound($fixtureRows, 2, 'night-2', '2026-09-10 19:00', 'played');
$fixtureRows = $placeRound($fixtureRows, 4, 'night-4', '2026-09-24 19:00', 'scheduled');
$fixtureRows = $placeRound($fixtureRows, 5, 'night-5', '2026-10-01 19:00', 'scheduled');

$lock = static function (array $rows, string $fixtureId): array {
    foreach ($rows as $index => $row) {
        if ($row['id'] === $fixtureId) {
            $rows[$index]['state'] = 'locked';
        }
    }

    return $rows;
};
$fixtureRows = $lock($fixtureRows, 'fx07');

// The nights that are left, as the application stores them
$nightsAsPlanned = [
    ['id' => 'night-4', 'kickoffs' => ['2026-09-24 19:00']],
    ['id' => 'night-5', 'kickoffs' => ['2026-10-01 19:00']],
];

// --- The adapter, the other way: fixture rows into a repack request -------
// The application decides what may move. The library is told the answer and
// never the reason:
//   - a played or locked fixture on one of the nights is pinned;
//   - a played or locked fixture on a night that is not on the grid is left
//     out, because it cannot collide with anything on it;
//   - every other fixture is movable, wherever it sits now.
//
// The grid is shape-only: the application has no UTC instants to give and
// needs positions back, which it turns into its own times. Capacity is
// unbounded because each fixture is played at a club's own ground, so the
// only limit is that no club is in two fixtures at once.
$buildRequest = static function (array $fixtureRows, array $nightRows) use ($participantsByClubId): array {
    $sessionOfNight = array_flip(array_column($nightRows, 'id'));

    $slotCounts = array_map(static fn(array $night): int => count($night['kickoffs']), $nightRows);
    $grid = SessionGrid::shapeOnly(
        sessions: count($nightRows),
        slotsPerSession: $slotCounts[0],
        slotsPerSessionOverrides: array_filter($slotCounts, static fn(int $slots): bool => $slots !== $slotCounts[0]),
        capacityPerSlot: null
    );

    $movable = [];
    $pinned = [];
    $leftOut = [];
    foreach ($fixtureRows as $row) {
        $home = $participantsByClubId[$row['home_club_id']];
        $away = $participantsByClubId[$row['away_club_id']];
        $mustStay = in_array($row['state'], ['played', 'locked'], true);

        if (!$mustStay) {
            $movable[] = new MovableEvent($row['id'], $home, $away);
        } elseif (isset($sessionOfNight[$row['night']])) {
            $pinned[] = new PinnedEvent($row['id'], $home, $away, $sessionOfNight[$row['night']], $row['slot']);
        } else {
            $leftOut[] = $row['id'];
        }
    }

    return [new RepackRequest($movable, $pinned, $grid), $leftOut];
};

$repacker = new ScheduleRepacker();

// --- First preview: the nights as they are ------------------------------
// One kickoff per night leaves a club two positions at most. The repack does
// not throw: it returns what fits and itemises what does not, and the typed
// accessors hand each kind of violation back as its own class.
[$request, $leftOut] = $buildRequest($fixtureRows, $nightsAsPlanned);
$tooFewSlots = $repacker->repack($request);

$requestHolds = [
    'Movable' => count($request->getMovableEvents()),
    'Pinned' => count($request->getPinnedEvents()),
    'Left out, not on the grid' => count($leftOut),
    'Positions on the grid' => $request->getGrid()->getPositionCount(),
];

$overCapacity = [];
foreach ($tooFewSlots->getCapacityExceededViolations() as $violation) {
    $overCapacity[] = [
        'Club' => $violation->getParticipant()?->getLabel() ?? '(the grid as a whole)',
        'Fixtures to place' => $violation->getDemand(),
        'Free positions' => $violation->getCapacity(),
        'Short by' => $violation->getShortfall(),
    ];
}

$notPlaced = [];
foreach ($tooFewSlots->getEventUnplacedViolations() as $violation) {
    $notPlaced[] = [
        'Fixture' => $violation->getEventId(),
        'Reason' => $violation->getReason()->value,
        'Club over capacity' => $violation->getParticipant()?->getLabel(),
    ];
}

// --- Second preview: a second kickoff on each night ----------------------
// The operator answers the shortfall by adding a kickoff to both nights. This
// is the plan the operator is shown, and its fingerprint is kept with it.
$nightsWithTwoKickoffs = [
    ['id' => 'night-4', 'kickoffs' => ['2026-09-24 19:00', '2026-09-24 20:30']],
    ['id' => 'night-5', 'kickoffs' => ['2026-10-01 19:00', '2026-10-01 20:30']],
];

[$request] = $buildRequest($fixtureRows, $nightsWithTwoKickoffs);
$preview = $repacker->repack($request);
$previewedFingerprint = $preview->fingerprint();

$lateStarts = [];
foreach ($preview->getLateStartViolations() as $violation) {
    $lateStarts[] = [
        'Club' => $violation->getParticipant()->getLabel(),
        'Night' => $nightsWithTwoKickoffs[$violation->getSession()]['id'],
        'First slot' => $violation->getFirstSlot(),
    ];
}

// --- Confirming -----------------------------------------------------------
// A preview is not stored as a plan to apply later. When the operator
// confirms, the request is built again from the rows as they are now and
// repacked again: the repacker is deterministic, so the same rows give the
// same plan and the same fingerprint. A different fingerprint means the rows
// changed after the preview, and nothing is written.
$confirm = static function (array $fixtureRows, array $nightRows, string $previewed) use ($buildRequest, $repacker): array {
    [$request] = $buildRequest($fixtureRows, $nightRows);
    $plan = $repacker->repack($request);

    // Fingerprints of different schemes are not comparable
    $sameScheme = str_starts_with($previewed, RepackOutcome::FINGERPRINT_SCHEME . ':');
    if (!$sameScheme || $plan->fingerprint() !== $previewed) {
        return [false, $fixtureRows];
    }

    // Apply: copy each position into the fixture row, and turn it into the
    // application's own time. The grid's sessions are the nights in order.
    foreach ($fixtureRows as $index => $row) {
        $assignment = $plan->getAssignmentFor($row['id']);
        if ($assignment === null) {
            continue;
        }
        $night = $nightRows[$assignment->getSession()];
        $fixtureRows[$index]['night'] = $night['id'];
        $fixtureRows[$index]['slot'] = $assignment->getSlot();
        $fixtureRows[$index]['local_kickoff'] = $night['kickoffs'][$assignment->getSlot()];
    }

    return [true, $fixtureRows];
};

// Between the preview and the confirmation the organiser locks another
// fixture where it sits. The plan computed now is not the plan that was
// shown, so the confirmation is refused.
$rowsAfterAnotherLock = $lock($fixtureRows, 'fx10');
[$appliedAfterChange] = $confirm($rowsAfterAnotherLock, $nightsWithTwoKickoffs, $previewedFingerprint);

// With the rows as they were previewed, the confirmation applies the plan
[$applied, $fixtureRows] = $confirm($fixtureRows, $nightsWithTwoKickoffs, $previewedFingerprint);

return Example::present(__FILE__, 'An application adapter, and a repack', 'Application records go in as participants, the schedule is copied into fixture rows, and a repack moves the fixtures that may move onto the nights that are left. The plan is previewed, and applied only if computing it again gives the same fingerprint.', [
    'Participants, in the order of the ranking' => $participants,
    'Fixture rows copied from the schedule' => $fixturesAsCopied,
    'Bye rows, from the schedule metadata' => $byeRows,
    'What the repack request holds' => $requestHolds,
    'First preview: one kickoff per night' => $tooFewSlots,
    'Clubs with more fixtures than free positions' => $overCapacity,
    'Fixtures it could not place' => $notPlaced,
    'Second preview: two kickoffs per night' => $preview,
    'Late starts in the second preview' => $lateStarts,
    'Reading the second preview' => [
        'Clean' => $preview->isClean(),
        'Everything placed' => $preview->getUnplaced() === [],
        'Step budget ran out' => $preview->isBudgetExhausted(),
        'Fingerprint' => $previewedFingerprint,
    ],
    'Confirming' => [
        'Applied after another fixture was locked' => $appliedAfterChange,
        'Applied with the rows as previewed' => $applied,
    ],
    'Fixture rows after the plan was applied' => $fixtureRows,
]);
