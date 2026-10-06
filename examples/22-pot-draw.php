<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/support/Example.php';

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Examples\Example;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Scheduling\PotDrawOptions;
use MissionGaming\Tactician\Scheduling\PotDrawScheduler;

// A league phase of 36 clubs. The list is in seeding order, and that order
// is all the scheduler reads: the first nine clubs are pot 1, the next nine
// pot 2, and so on.
$clubs = [];
for ($seed = 1; $seed <= 36; ++$seed) {
    $clubs[] = new Participant(sprintf('club%02d', $seed), "Club {$seed}");
}

// Four pots, two opponents from every pot (a club's own pot included), so
// eight rounds. The seed names the draw: store it, and the same schedule
// comes back.
$options = new PotDrawOptions(pots: 4, opponentsPerPot: 2, seed: 2026);
$scheduler = new PotDrawScheduler();

// The plan knows the shape before anything is drawn
$plan = $scheduler->getPlan($clubs, $options);

$schedule = $scheduler->schedule($clubs, $options);

// Who the top seed meets, pot by pot. The first-named participant of an
// event is in the first role (home, say) and the other in the second.
$topSeed = $clubs[0];
$opponents = [];
foreach ($schedule as $event) {
    if (!$event->hasParticipant($topSeed)) {
        continue;
    }

    [$first, $second] = $event->getParticipants();
    $topSeedIsFirst = $first->getId() === $topSeed->getId();
    $opponent = $topSeedIsFirst ? $second : $first;
    $opponents['Pot ' . $plan->getPotOf($opponent)][] = ($topSeedIsFirst ? 'first role against ' : 'second role against ')
        . $opponent->getLabel();
}
ksort($opponents);
$opponentsByPot = [];
foreach ($opponents as $pot => $meetings) {
    sort($meetings);
    $opponentsByPot[$pot] = implode(', ', $meetings);
}

// The same options draw the same schedule; another seed draws another
$again = $scheduler->schedule($clubs, $options);
$other = $scheduler->schedule($clubs, new PotDrawOptions(pots: 4, opponentsPerPot: 2, seed: 2027));

// Numbers that cannot form a pot draw are refused before anything is
// drawn: in a pot of five, five clubs cannot each have one opponent inside
// the pot.
$refused = null;
try {
    $scheduler->schedule(array_slice($clubs, 0, 20), new PotDrawOptions(pots: 4, opponentsPerPot: 1));
} catch (InvalidConfigurationException $exception) {
    $refused = $exception;
}

return Example::present(__FILE__, 'Pot draw', 'A league phase drawn up front: 36 clubs in four pots of nine, and every club meets two opponents from every pot, once in each role. Nothing is paired from results, so this is not Swiss pairing.', [
    'Plan, before the draw' => [
        'Algorithm' => $plan->getAlgorithm(),
        'Pots' => $plan->getPots(),
        'Clubs per pot' => $plan->getPotSize(),
        'Rounds' => $plan->getTotalRounds(),
        'Events per round' => $plan->getEventsPerRound(),
        'Events' => $plan->getExpectedEventCount(),
    ],
    'The draw' => $schedule,
    'Opponents of the top seed' => $opponentsByPot,
    'Seeds' => [
        'Seed 2026 drawn again gives the same schedule' => $again->toArray()['events'] === $schedule->toArray()['events'],
        'Seed 2027 gives another schedule' => $other->toArray()['events'] !== $schedule->toArray()['events'],
    ],
    'Twenty clubs in four pots of five, one opponent per pot' => $refused,
    'Its reason' => $refused?->getReason()?->value,
]);
