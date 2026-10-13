<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/support/Example.php';

use MissionGaming\Tactician\DTO\Decider;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Examples\Example;
use MissionGaming\Tactician\Exceptions\RejectedDecisionException;
use MissionGaming\Tactician\Exceptions\UndecidedSelectionException;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Scheduling\SingleEliminationEngine;
use MissionGaming\Tactician\Stage\RankRangeSelector;
use MissionGaming\Tactician\Stage\StageOutcome;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Standings\StandingsCalculator;

// A handball cup in two groups of four. The top two of each group go to the
// semifinals, the winner of one group against the runner-up of the other.
// In the Fjord group the first two finish level on everything.

$calculator = new StandingsCalculator();
$scheduler = new RoundRobinScheduler();

// One group: its clubs in list order, and the score of every event by the
// ids of the two clubs. Returns the group's outcome.
$playGroup = static function (array $clubs, array $scores) use ($calculator, $scheduler): StageOutcome {
    $results = [];
    foreach ($scheduler->schedule($clubs) as $event) {
        [$first, $second] = $event->getParticipants();
        $score = $scores["{$first->getId()}|{$second->getId()}"] ?? $scores["{$second->getId()}|{$first->getId()}"];
        $winner = match ($score[$first->getId()] <=> $score[$second->getId()]) {
            1 => $first,
            -1 => $second,
            0 => null,
        };
        $results[] = new Result($event, $winner, $score);
    }

    return new StageOutcome($calculator->calculate($clubs, $results), $results, entrants: $clubs);
};

$fjord = [
    new Participant('orca', 'Orca'),
    new Participant('puffin', 'Puffin'),
    new Participant('seal', 'Seal'),
    new Participant('tern', 'Tern'),
];
$sund = [
    new Participant('gull', 'Gull'),
    new Participant('heron', 'Heron'),
    new Participant('ibis', 'Ibis'),
    new Participant('kite', 'Kite'),
];

$groups = [
    // Orca and Puffin draw with each other and beat the other two by the
    // same scores: 7 points each, 58 scored and 48 conceded
    'Fjord' => $playGroup($fjord, [
        'orca|puffin' => ['orca' => 18, 'puffin' => 18],
        'orca|seal' => ['orca' => 20, 'seal' => 15],
        'orca|tern' => ['orca' => 20, 'tern' => 15],
        'puffin|seal' => ['puffin' => 20, 'seal' => 15],
        'puffin|tern' => ['puffin' => 20, 'tern' => 15],
        'seal|tern' => ['seal' => 17, 'tern' => 16],
    ]),
    // Results decide every place
    'Sund' => $playGroup($sund, [
        'gull|heron' => ['gull' => 22, 'heron' => 19],
        'gull|ibis' => ['gull' => 24, 'ibis' => 17],
        'gull|kite' => ['gull' => 21, 'kite' => 14],
        'heron|ibis' => ['heron' => 19, 'ibis' => 18],
        'heron|kite' => ['heron' => 23, 'kite' => 20],
        'ibis|kite' => ['ibis' => 16, 'kite' => 15],
    ]),
];
$stage = StageOutcome::combining($groups, $calculator, [...$fjord, ...$sund]);

// What the table says about the tie
$fjordTable = $groups['Fjord']->getStandings();
$tied = [];
foreach ($fjordTable->getTiedSets() as $tiedSet) {
    $tied[] = [
        'Places' => "{$tiedSet->getFirstPosition()} to {$tiedSet->getLastPosition()}",
        'Level' => implode(', ', array_map(static fn(Participant $club): string => $club->getLabel(), $tiedSet->getParticipants())),
        'Decided' => $tiedSet->getDecision() === null ? 'no' : 'yes',
    ];
}

// Both go through, but who meets Heron and who meets Gull is the order of
// the two, and no result gives it: the selection is refused
$selector = RankRangeSelector::topPerGroup(2);
$refusal = null;
try {
    $selector->select($stage);
} catch (UndecidedSelectionException $exception) {
    $refusal = $exception;
}

// The tournament office settles it by its own rule and records the order,
// best first. The library is told what was decided and never how
$decision = new Decider(['puffin', 'orca'], 'drawing of lots', 'Drawn by the tournament office after the last group event');

// A decision travels as plain data, beside the results
$stored = $decision->toArray();
$decision = Decider::fromArray($stored);

$selection = $selector->withDecisions([$decision])->selection($stage);

$qualifiers = [];
foreach ($selection->getParticipants() as $club) {
    $settledBy = $selection->getDecisionsFor($club);
    $qualifiers[] = [
        'Club' => $club->getLabel(),
        'Placed by' => $settledBy === [] ? 'results' : (string) $settledBy[0]->getLabel(),
        'Reason' => $settledBy === [] ? '' : (string) $settledBy[0]->getReason(),
    ];
}

// The selected list seeds the bracket: position 1 meets position 4
$semifinals = (new SingleEliminationEngine())->pairNextRound(StageState::start($selection->getParticipants()));

// The same decision puts the table an application shows in the same order
$decidedTable = $fjordTable->withDecisions([$decision]);
$shown = [];
foreach ($decidedTable as $entry) {
    $settledBy = $decidedTable->getDecisionFor($entry->getParticipant());
    $shown[] = [
        'Place' => $decidedTable->getPosition($entry->getParticipant()),
        'Club' => $entry->getParticipant()->getLabel(),
        'Points' => $entry->getRankingValue(),
        'Score difference' => $entry->getScoreDifference(),
        'Settled by' => $settledBy === null ? 'results' : (string) $settledBy->getLabel(),
    ];
}

// A decision orders level entries and nothing else. One that would put Seal,
// who is four points behind, ahead of Orca is rejected
$rejected = null;
try {
    $fjordTable->withDecisions([new Decider(['seal', 'orca'])]);
} catch (RejectedDecisionException $exception) {
    $rejected = $exception;
}

return Example::present(__FILE__, 'Ties settled by decision', 'Two groups play a round robin each and the top two of each go to the semifinals. In one group the first two are level on every figure. Both qualify, but their order decides whom each meets, so the selection is refused until the organisers supply the order they decided. The selection and the table then mark the two places as settled by that decision.', [
    'Group tables' => array_map(static fn(StageOutcome $group) => $group->getStandings(), $groups),
    'Tied sets of the Fjord table' => $tied,
    'Without a decision' => [
        'Refused as' => $refusal?->getReason()?->value,
        'Group' => $refusal?->getPool(),
        'Level' => implode(', ', $refusal?->getParticipantIds() ?? []),
        'Places' => $refusal === null ? '' : "{$refusal->getFirstPlace()} to {$refusal->getLastPlace()}",
    ],
    'The decision as plain data' => $stored,
    'Qualifiers, in the order that seeds the semifinals' => $qualifiers,
    'Semifinals' => $semifinals,
    'The Fjord table as shown' => $shown,
    'A decision that would override a result' => [
        'Rejected as' => $rejected?->getReason()->value,
        'Not level with Seal' => implode(', ', $rejected?->getParticipantIds() ?? []),
    ],
]);
