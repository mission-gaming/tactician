<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/support/Example.php';

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Examples\Example;
use MissionGaming\Tactician\Repack\MovableEvent;
use MissionGaming\Tactician\Repack\PinnedEvent;
use MissionGaming\Tactician\Repack\RepackObjective;
use MissionGaming\Tactician\Repack\RepackOptions;
use MissionGaming\Tactician\Repack\RepackOutcome;
use MissionGaming\Tactician\Repack\RepackRequest;
use MissionGaming\Tactician\Repack\ScheduleRepacker;
use MissionGaming\Tactician\Repack\SessionGrid;
use MissionGaming\Tactician\Repack\ShortestGridFinder;

// A curling club's Thursday league: eight rinks play each other once, four
// sheets of ice, two draws a night. The first night has been played. Then
// the club loses the third night to a bonspiel and adds one at the end.
// The games of the lost night need a new time, and nobody else's game
// should move: every game that changes time is a rink to telephone.

$rinks = [];
foreach (['Aspen', 'Birch', 'Cedar', 'Dogwood', 'Elm', 'Fir', 'Hazel', 'Juniper'] as $name) {
    $rinks[] = new Participant(strtolower($name), "Rink {$name}");
}

// The calendar as it was published: two rounds a night, a round a draw
$timezone = new DateTimeZone('America/Winnipeg');
$published = ['2026-11-05', '2026-11-12', '2026-11-19', '2026-11-26'];
$draws = ['18:30', '20:45'];

/** @var list<array{id: string, a: Participant, b: Participant, at: DateTimeImmutable}> $games */
$games = [];
$seats = range(0, 7);
for ($round = 0; $round < 7; ++$round) {
    for ($sheet = 0; $sheet < 4; ++$sheet) {
        $games[] = [
            'id' => sprintf('g%02d', count($games) + 1),
            'a' => $rinks[$seats[$sheet]],
            'b' => $rinks[$seats[7 - $sheet]],
            'at' => new DateTimeImmutable($published[intdiv($round, 2)] . ' ' . $draws[$round % 2], $timezone),
        ];
    }
    // The circle method: the first seat stays, the others move round
    array_splice($seats, 1, 0, [array_pop($seats)]);
}

// The grid after the change: 19 November is gone, 3 December is new
$grid = SessionGrid::fromArray([
    'sessions' => ['2026-11-05 18:30', '2026-11-12 18:30', '2026-11-26 18:30', '2026-12-03 18:30'],
    'timezone' => 'America/Winnipeg',
    'slot_interval' => 'PT2H15M',
    'slots_per_session' => 2,
    'capacity_per_slot' => 4,
]);

// Each game says where it is now, as a position of the new grid. The grid
// finds the position of a time; a game of the lost night has none. The
// games of the first night were played, and are pinned where they were
$played = [];
$outstanding = [];
$was = [];
foreach ($games as $game) {
    $position = $grid->positionOf($game['at']);
    $was[$game['id']] = $position === null ? 'no time: its night was lost' : $game['at'];

    if ($position !== null && $position['session'] === 0) {
        $played[] = new PinnedEvent($game['id'], $game['a'], $game['b'], $position['session'], $position['slot']);
    } elseif ($position !== null) {
        $outstanding[] = new MovableEvent($game['id'], $game['a'], $game['b'], $position['session'], $position['slot']);
    } else {
        $outstanding[] = new MovableEvent($game['id'], $game['a'], $game['b']);
    }
}

// The objective is opt-in. Without it the repack is free to move any game
$repacker = new ScheduleRepacker();
$free = $repacker->repack(new RepackRequest($outstanding, $played, $grid));
$keeping = $repacker->repack(new RepackRequest(
    $outstanding,
    $played,
    $grid,
    new RepackOptions(objectives: [RepackObjective::LeastChurn])
));

// What an outcome is ranked by, read from the outcome alone
$figures = static fn(RepackOutcome $outcome): array => [
    'Unplaced' => $outcome->getUnplacedCount(),
    'Gap slots' => $outcome->getGapSlotCount(),
    'Lone-event sessions' => $outcome->getLoneEventSessionCount(),
    'Idle sessions' => $outcome->getIdleSessionCount(),
    'Moved' => $outcome->getMovedCount(),
];

// Could the club have done without the added night? The fewest nights
// that hold the rest of the season, for a grid of two draws on four sheets
$nights = ['2026-11-05 18:30', '2026-11-12 18:30', '2026-11-26 18:30', '2026-12-03 18:30', '2026-12-10 18:30'];
$shortest = (new ShortestGridFinder($repacker))->find(
    $outstanding,
    $played,
    static fn(int $sessions): SessionGrid => SessionGrid::fromArray([
        'sessions' => array_slice($nights, 0, $sessions),
        'timezone' => 'America/Winnipeg',
        'slot_interval' => 'PT2H15M',
        'slots_per_session' => 2,
        'capacity_per_slot' => 4,
    ]),
    maxSessions: count($nights)
);

$labels = [];
foreach ($games as $game) {
    $labels[$game['id']] = $game['a']->getLabel() . ' v ' . $game['b']->getLabel();
}

return Example::present(__FILE__, 'Rescheduling with the fewest moved games', 'A curling league loses a night and gains one. Every outstanding game says where it is; asked for the fewest moved games, the repack leaves each of them at its time and gives the eight games of the lost night the free draws. The figures of both outcomes are read from the outcomes alone, and the shortest grid says the added night was needed.', [
    'Games by id' => $labels,
    'Where each game was' => $was,
    'Repacked with the fewest moved games' => $keeping,
    'Moved, with the objective' => $keeping->getMovedEventIds(),
    'Moved, without it' => $free->getMovedEventIds(),
    'Figures' => [
        ['Repack' => 'With no objective'] + $figures($free),
        ['Repack' => 'Fewest moved games'] + $figures($keeping),
    ],
    'Fewest nights that hold the season' => [
        'Nights' => $shortest->getSessionCount(),
        'Ruled out by counting' => implode(', ', $shortest->getRuledOut()),
        'Repacks run' => count($shortest->getUnplacedBySessionCount()),
        'Proven the fewest' => $shortest->isProvenShortest(),
    ],
]);
