<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/support/Example.php';

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Examples\Example;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\TacticianException;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

$participants = [
    new Participant('alpha', 'Alpha'),
    new Participant('beta', 'Beta'),
    new Participant('gamma', 'Gamma'),
    new Participant('delta', 'Delta'),
];

// The library fails loudly. A scheduler either returns a complete schedule
// or throws; it never returns a partial one. Every exception the library
// throws on purpose implements TacticianException, so one catch covers them
// all when you do not need to tell them apart.

// 1. InvalidConfigurationException: the request itself is wrong, before any
//    scheduling is attempted. Here two participants share an id. Its reason
//    is an enum case with a stable string value: code that has to tell one
//    mistake from another reads that, never the message.
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

// 3. One catch for everything. A rejected argument is an
//    InvalidInputException, which is not a scheduling failure and does not
//    extend SchedulingException; the marker interface covers both.
$attempts = [
    'A schedule the constraints rule out' => static fn() => (new RoundRobinScheduler($rejectEverything))->schedule($participants),
    'A round numbered zero' => static fn() => new Round(0),
];

$caught = [];
foreach ($attempts as $what => $attempt) {
    try {
        $attempt();
    } catch (TacticianException $exception) {
        $caught[$what] = $exception::class;
    }
}

// With nothing in the way the same call simply returns the schedule
$schedule = (new RoundRobinScheduler())->schedule($participants);

return Example::present(__FILE__, 'Error handling', 'What the library throws, and what the exceptions carry: an invalid request with its reason, constraints that leave no complete schedule, and the one catch that covers every library exception.', [
    'Duplicate participant ids' => $invalid,
    'The issue it names' => $invalid?->getConfigurationIssue(),
    'Its reason' => $invalid?->getReason()?->value,
    'A constraint that rejects everything' => $incomplete,
    'How far generation got' => $counts,
    'Diagnostic report' => $report,
    'Classes caught as TacticianException' => $caught,
    'A valid request' => $schedule,
]);
