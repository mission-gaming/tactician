<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/support/Example.php';

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Examples\Example;
use MissionGaming\Tactician\Scheduling\DoubleEliminationEngine;
use MissionGaming\Tactician\Scheduling\EliminationOptions;
use MissionGaming\Tactician\Scheduling\SingleEliminationEngine;
use MissionGaming\Tactician\Stage\Bracket;
use MissionGaming\Tactician\Stage\BracketNode;
use MissionGaming\Tactician\Stage\BracketNodeStatus;
use MissionGaming\Tactician\Stage\BracketSlot;
use MissionGaming\Tactician\Stage\BracketTie;
use MissionGaming\Tactician\Stage\SlotPlaceholder;
use MissionGaming\Tactician\Stage\StageEngineInterface;
use MissionGaming\Tactician\Stage\StageState;

// A bracket drawn from its view, and from nothing else. getBracket() gives
// every round of every side, the rounds still to come included: each node
// with its slots, where it stands and who went through. The closure below
// turns a view into rows, one for each node: it reads labels, slots,
// placeholders and statuses, and never the engine, the state or a result.
// The rows are shown as tables.
//
// The engines label the rounds they pair from the same view, so the labels
// here are the ones on the rounds the engines pair.
$drawBracket = static function (Bracket $bracket): array {
    // What a node is called where a slot refers to it: its round and place
    $nameOf = static function (string $id) use ($bracket): string {
        $node = $bracket->getNode($id);
        if (!$node instanceof BracketNode) {
            return $id;
        }
        $round = $bracket->getRoundsOn($node->getSide())[$node->getRound() - 1];

        return $round->getLabel() . (count($round->getNodes()) > 1 ? ' ' . $node->getPosition() : '');
    };

    $slotText = static function (BracketSlot $slot) use ($nameOf): string {
        $participant = $slot->getParticipant();
        if ($participant instanceof Participant) {
            return $participant->getLabel() . ($slot->isWithdrawn() ? ' (withdrawn)' : '');
        }

        $from = $slot->getSourceNodes()[0] ?? '';

        return match ($slot->getPlaceholder()) {
            SlotPlaceholder::AdvancerOf => 'winner of ' . $nameOf($from),
            SlotPlaceholder::LoserOf => 'loser of ' . $nameOf($from),
            SlotPlaceholder::Reseeded => 'to be re-seeded',
            SlotPlaceholder::Bye => 'bye',
            SlotPlaceholder::Void => 'nobody (void)',
            default => 'not played',
        };
    };

    $rows = [];
    foreach ($bracket->getRounds() as $round) {
        foreach ($round->getNodes() as $node) {
            $legs = count($node->getEvents());
            $advancer = $node->getAdvancer()?->getLabel();
            $slots = array_map($slotText, $node->getSlots());
            $rows[] = [
                'Round' => $round->getLabel(),
                'Played as' => match (true) {
                    $round->getRoundNumber() !== null => "round {$round->getRoundNumber()}",
                    $round->isNext() => 'paired next',
                    default => '',
                },
                'Tie' => $node->getKind() === BracketTie::ThirdPlace ? 'third place' : (string) $node->getPosition(),
                'First' => $slots[0] ?? '',
                'Second' => $slots[1] ?? '',
                'Where it stands' => match ($node->getStatus()) {
                    BracketNodeStatus::Decided => "{$advancer} through" . ($legs > 1 ? " over {$legs} legs" : ''),
                    BracketNodeStatus::Uncontested => "{$advancer} through, uncontested",
                    BracketNodeStatus::Bye => "{$advancer} through on a bye",
                    BracketNodeStatus::Void => 'void, nobody through',
                    BracketNodeStatus::InPlay => 'in play',
                    BracketNodeStatus::AwaitingDecision => 'level, awaiting a decision',
                    BracketNodeStatus::ToBePaired => 'to be paired',
                    BracketNodeStatus::NotPlayed => 'not played',
                    BracketNodeStatus::Empty => 'empty',
                    default => 'waiting',
                },
            ];
        }
    }

    return $rows;
};

// Pair the next round and record it, then the results: the entrant named
// first in a tie's first leg wins every leg, unless the other is one of the
// upsets
$playRound = static function (StageEngineInterface $engine, StageState $state, array $upsets = []): StageState {
    $pairing = $engine->pairNextRound($state);
    $state = $state->withRoundPaired($pairing);

    $results = [];
    foreach ($pairing->getEvents() as $event) {
        [$first, $second] = $event->getParticipants();
        if ($event->getMetadataValue('tie_leg') === 2) {
            [$first, $second] = [$second, $first];
        }
        $results[] = new Result($event, in_array($second->getId(), $upsets, true) ? $second : $first);
    }

    return $state->withAdditionalResults($results);
};

// 1. Six players, two legs a tie, and a third-place tie. The top two have
//    byes into the semifinals; the semifinals have their first legs in.
$players = [
    new Participant('ines', 'Ines'),
    new Participant('tomas', 'Tomas'),
    new Participant('olu', 'Olu'),
    new Participant('wren', 'Wren'),
    new Participant('kasia', 'Kasia'),
    new Participant('raf', 'Raf'),
];
$cup = new SingleEliminationEngine(new EliminationOptions(legsPerTie: 2, thirdPlace: true));
$cupState = $playRound($cup, StageState::start($players), ['kasia']);
$semifinals = $cup->pairNextRound($cupState);
$cupState = $cupState->withRoundPaired($semifinals);
$firstLegs = [];
foreach ($semifinals->getEvents() as $event) {
    if ($event->getMetadataValue('tie_leg') === 1) {
        $firstLegs[] = new Result($event, $event->getParticipants()[0]);
    }
}
$cupState = $cupState->withAdditionalResults($firstLegs);

// 2. Five squads, double elimination. The Merlins lose their first tie and
//    then withdraw: their slot in the losers bracket sends nobody on, the
//    squad that would have met them goes through on a bye, and nothing is
//    drawn again.
$squads = [
    new Participant('harriers', 'Harriers'),
    new Participant('kestrels', 'Kestrels'),
    new Participant('ospreys', 'Ospreys'),
    new Participant('merlins', 'Merlins'),
    new Participant('swifts', 'Swifts'),
];
$league = new DoubleEliminationEngine();
$leagueState = $playRound($league, StageState::start($squads), ['swifts']);
$leagueState = $leagueState->withoutParticipant($squads[3]);
$leagueState = $playRound($league, $leagueState);
$nextRound = $league->pairNextRound($leagueState);
$inPlay = $drawBracket($league->getBracket($leagueState));

while (!$league->isComplete($leagueState)) {
    $leagueState = $playRound($league, $leagueState, ['ospreys']);
}
$outcome = $league->getOutcome($leagueState);
if ($outcome === null) {
    throw new RuntimeException('The bracket should be complete');
}

// 3. A re-seeded bracket before its first round: who meets whom after it is
//    known only once the round is settled.
$reseeded = new SingleEliminationEngine(new EliminationOptions(reseedEachRound: true));

return Example::present(__FILE__, 'Drawing a bracket', 'Brackets drawn from the structured view alone: byes, two-legged ties and a third-place tie; a losers bracket with a withdrawal, in play and finished; and a re-seeded bracket whose later rounds wait for the draw.', [
    'Single elimination, two legs, third place' => $drawBracket($cup->getBracket($cupState)),
    'The semifinal first legs' => $firstLegs,
    'Double elimination, Merlins withdrawn, in play' => $inPlay,
    'The round its engine pairs next' => $nextRound,
    'The same bracket, finished' => $drawBracket($league->getBracket($leagueState)),
    'Final standings' => $outcome->getStandings(),
    'Re-seeded, before the first round' => $drawBracket($reseeded->getBracket(StageState::start(array_slice($players, 0, 4)))),
]);
