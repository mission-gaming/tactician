<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/support/Example.php';

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Constraints\MetadataConstraint;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Examples\Example;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

$participants = [
    new Participant('wolves', 'Nordic Wolves', 1, ['region' => 'Europe', 'platform' => 'PC', 'utc_offset' => 1]),
    new Participant('thunder', 'Tokyo Thunder', 2, ['region' => 'Asia', 'platform' => 'PC', 'utc_offset' => 9]),
    new Participant('kings', 'California Kings', 3, ['region' => 'North America', 'platform' => 'Console', 'utc_offset' => -8]),
    new Participant('lions', 'London Lions', 4, ['region' => 'Europe', 'platform' => 'PC', 'utc_offset' => 0]),
    new Participant('squad', 'São Paulo Squad', 5, ['region' => 'South America', 'platform' => 'Console', 'utc_offset' => -3]),
    new Participant('sharks', 'Sydney Sharks', 6, ['region' => 'Oceania', 'platform' => 'PC', 'utc_offset' => 10]),
];

// A MetadataConstraint judges an event by one metadata key of its
// participants. The factories cover the common rules.

// maxUniqueValues('region', 2): an event may span at most two regions. Every
// event here has two participants, so it always holds and the schedule is
// the unconstrained one. It starts to matter for events with more
// participants.
$regionCap = ConstraintSet::create()
    ->add(MetadataConstraint::maxUniqueValues('region', 2))
    ->build();
$capped = (new RoundRobinScheduler($regionCap))->schedule($participants);

// requireSameValue('platform'): only participants on the same platform may
// meet. A complete round robin needs every pair to meet, PC against Console
// included, so this cannot be satisfied. The scheduler says so: it throws
// IncompleteScheduleException, it never returns a schedule with the
// forbidden pairings left out.
$samePlatform = ConstraintSet::create()
    ->add(MetadataConstraint::requireSameValue('platform', 'Same platform'))
    ->build();

$platformFailure = null;
try {
    (new RoundRobinScheduler($samePlatform))->schedule($participants);
} catch (IncompleteScheduleException $exception) {
    $platformFailure = $exception;
}

// The constructor takes your own validator over the values of the key. This
// one allows a pairing only when the two UTC offsets are within 6 hours.
$closeTimezones = ConstraintSet::create()
    ->add(new MetadataConstraint(
        'utc_offset',
        static fn (array $offsets): bool => max($offsets) - min($offsets) <= 6,
        'Within 6 hours'
    ))
    ->build();

$timezoneFailure = null;
try {
    (new RoundRobinScheduler($closeTimezones))->schedule($participants);
} catch (IncompleteScheduleException $exception) {
    $timezoneFailure = $exception;
}

// The failure carries an analysis that names each pairing no round can take
// and the constraint that blocks it
$blockedByPlatform = $platformFailure?->getAnalysis()?->getImpossiblePairings() ?? [];

return Example::present(__FILE__, 'Metadata constraints', 'MetadataConstraint judges an event by a metadata key of its participants. A rule that forbids a pairing outright makes a complete round robin impossible, and the scheduler reports that instead of dropping the pairing.', [
    'Participants' => $participants,
    'At most two regions per event: schedule' => $capped,
    'Same platform only' => $platformFailure,
    'Pairings the platform rule blocks' => $blockedByPlatform,
    'UTC offsets within 6 hours only' => $timezoneFailure,
    'Events under the timezone rule' => [
        'Generated before it failed' => $timezoneFailure?->getActualEventCount(),
        'Needed for a complete schedule' => $timezoneFailure?->getExpectedEventCount(),
    ],
]);
