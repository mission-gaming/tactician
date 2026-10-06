<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/support/Example.php';

use MissionGaming\Tactician\Constraints\CallableConstraint;
use MissionGaming\Tactician\Constraints\ConstraintInterface;
use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Examples\Example;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Scheduling\SchedulingContext;

$participants = [
    new Participant('dragons', 'Fire Dragons', 1, ['experience' => 'veteran', 'budget' => 'high']),
    new Participant('phoenix', 'Ice Phoenix', 2, ['experience' => 'rookie', 'budget' => 'medium']),
    new Participant('hawks', 'Storm Hawks', 3, ['experience' => 'veteran', 'budget' => 'low']),
    new Participant('wolves', 'Thunder Wolves', 4, ['experience' => 'intermediate', 'budget' => 'high']),
    new Participant('serpents', 'Shadow Serpents', 5, ['experience' => 'intermediate', 'budget' => 'medium']),
    new Participant('lions', 'Lightning Lions', 6, ['experience' => 'rookie', 'budget' => 'low']),
];

$unconstrained = (new RoundRobinScheduler())->schedule($participants);

// 1. A closure. The scheduler calls it for every event it is about to place,
//    with the event (participants and round) and the scheduling context
//    (the events placed so far). Return false to reject the event.
//
//    This rule depends on the round, so it can be satisfied: the two
//    veterans still meet, only not in the first three rounds.
$veteransApartEarly = new CallableConstraint(
    static function (Event $event, SchedulingContext $context): bool {
        if ($event->getRound()?->getNumber() > 3) {
            return true;
        }
        [$first, $second] = $event->getParticipants();

        return !($first->getMetadataValue('experience') === 'veteran' && $second->getMetadataValue('experience') === 'veteran');
    },
    'Veterans apart in rounds 1 to 3'
);

$withVeteransApart = (new RoundRobinScheduler(
    ConstraintSet::create()->add($veteransApartEarly)->build()
))->schedule($participants);

// 2. A class, for a rule with its own configuration that you want to name,
//    reuse and unit test.
$budgetSeparation = new class ('high', 'low') implements ConstraintInterface {
    public function __construct(private readonly string $upper, private readonly string $lower) {}

    public function isSatisfied(Event $event, SchedulingContext $context): bool
    {
        $budgets = array_map(
            static fn(Participant $participant): mixed => $participant->getMetadataValue('budget'),
            $event->getParticipants()
        );

        return !(in_array($this->upper, $budgets, true) && in_array($this->lower, $budgets, true));
    }

    public function getName(): string
    {
        return 'Budget tier separation';
    }
};

// A custom constraint is a hard filter like any other: it cannot express a
// preference. This rule forbids four pairings in every round, a complete
// round robin needs them, so generation fails and names the constraint.
$budgetFailure = null;
try {
    (new RoundRobinScheduler(ConstraintSet::create()->add($budgetSeparation)->build()))->schedule($participants);
} catch (IncompleteScheduleException $exception) {
    $budgetFailure = $exception;
}

// The round in which the two veterans meet
$veteransMeet = static function (Schedule $schedule): ?int {
    foreach ($schedule as $event) {
        [$first, $second] = $event->getParticipants();
        if ($first->getMetadataValue('experience') === 'veteran' && $second->getMetadataValue('experience') === 'veteran') {
            return $event->getRound()?->getNumber();
        }
    }

    return null;
};

return Example::present(__FILE__, 'Custom constraints', 'Your own rule as a closure or as a class implementing ConstraintInterface. A rule that depends on the round can be satisfied by moving a pairing; a rule that forbids a pairing in every round cannot.', [
    'Round in which the two veterans meet' => [
        'Without constraints' => $veteransMeet($unconstrained),
        'With the closure constraint' => $veteransMeet($withVeteransApart),
    ],
    'Schedule with the veterans apart in rounds 1 to 3' => $withVeteransApart,
    'Budget tier separation' => $budgetFailure,
    'Pairings the budget rule blocks' => $budgetFailure?->getAnalysis()?->getImpossiblePairings() ?? [],
]);
