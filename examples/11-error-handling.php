<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/support/Example.php';

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Examples\Example;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\SchedulingException;
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

$participants = [
    new Participant('alpha', 'Alpha'),
    new Participant('beta', 'Beta'),
    new Participant('gamma', 'Gamma'),
    new Participant('delta', 'Delta'),
];

// The library fails loudly. A scheduler either returns a complete schedule
// or throws; it never returns a partial one. Both exceptions below extend
// SchedulingException, so one catch covers them when you do not need to
// tell them apart.

// 1. InvalidConfigurationException: the request itself is wrong, before any
//    scheduling is attempted. Here two participants share an id.
$invalid = null;
try {
    (new RoundRobinScheduler())->schedule([
        new Participant('alpha', 'Alpha'),
        new Participant('alpha', 'Alpha again'),
    ]);
} catch (InvalidConfigurationException $exception) {
    $invalid = $exception;
}

// 2. IncompleteScheduleException: the request is valid but the constraints
//    leave no complete schedule. This constraint rejects every event.
$rejectEverything = ConstraintSet::create()
    ->custom(static fn(): bool => false, 'Reject everything')
    ->build();

$incomplete = null;
try {
    (new RoundRobinScheduler($rejectEverything))->schedule($participants);
} catch (IncompleteScheduleException $exception) {
    $incomplete = $exception;
}

// The exception says how far generation got, and carries a diagnostic
// report written for a person to read
$counts = [
    'Expected events' => $incomplete?->getExpectedEventCount(),
    'Generated events' => $incomplete?->getActualEventCount(),
    'Missing events' => $incomplete?->getMissingEventCount(),
];
$report = $incomplete?->getDiagnosticReport();

// 3. Catching the base class: whatever went wrong inside the scheduler
$caughtAsBase = null;
try {
    (new RoundRobinScheduler($rejectEverything))->schedule($participants, new RoundRobinOptions(legs: 2));
} catch (SchedulingException $exception) {
    $caughtAsBase = $exception::class;
}

// With nothing in the way the same call simply returns the schedule
$schedule = (new RoundRobinScheduler())->schedule($participants);

return Example::present(__FILE__, 'Error handling', 'What the scheduler throws, and what the exceptions carry: an invalid request, and constraints that leave no complete schedule.', [
    'Duplicate participant ids' => $invalid,
    'The issue it names' => $invalid?->getConfigurationIssue(),
    'A constraint that rejects everything' => $incomplete,
    'How far generation got' => $counts,
    'Diagnostic report' => $report,
    'Class caught as SchedulingException' => $caughtAsBase,
    'A valid request' => $schedule,
]);
