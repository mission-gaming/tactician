<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Award;
use MissionGaming\Tactician\DTO\Decider;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidInputException;
use MissionGaming\Tactician\Exceptions\NoValidPairingException;
use MissionGaming\Tactician\Scheduling\DoubleEliminationEngine;
use MissionGaming\Tactician\Scheduling\EliminationOptions;
use MissionGaming\Tactician\Scheduling\SingleEliminationEngine;
use MissionGaming\Tactician\Stage\Bracket;
use MissionGaming\Tactician\Stage\BracketNode;
use MissionGaming\Tactician\Stage\BracketNodeStatus;
use MissionGaming\Tactician\Stage\BracketSide;
use MissionGaming\Tactician\Stage\BracketSlot;
use MissionGaming\Tactician\Stage\BracketTie;
use MissionGaming\Tactician\Stage\BracketViewEngine;
use MissionGaming\Tactician\Stage\RoundLabel;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Stage\SlotPlaceholder;
use MissionGaming\Tactician\Stage\SlotSource;
use MissionGaming\Tactician\Stage\StageEngineInterface;
use MissionGaming\Tactician\Stage\StageState;

// The structured bracket view (issue #57, and the third-place slot of #93),
// case by case, with every participant named. The sweeps over field sizes,
// legs, re-seeding, withdrawals and resets are in EliminationInvariantsTest
// ("shows the bracket ..."). Each case reads the view as text: a line for
// every round (label, side and structural round, the recorded round number,
// "next" for the round paired next) and one for every node (ID, kind,
// slots, status, advancer). A slot is the entrant's ID, with "*" where it
// has withdrawn, or its placeholder in brackets with the nodes it is filled
// from.

/**
 * Entrants p1, p2, ... in list order.
 *
 * @return list<Participant>
 */
function bracketViewField(int $count): array
{
    $field = [];
    for ($i = 1; $i <= $count; ++$i) {
        $field[] = new Participant("p{$i}", "Entrant {$i}");
    }

    return $field;
}

/**
 * The bracket as lines of text (see the comment at the top of the file).
 *
 * @return list<string>
 */
function bracketViewText(Bracket $bracket): array
{
    $slot = static function (BracketSlot $slot): string {
        $participant = $slot->getParticipant();
        if ($participant instanceof Participant) {
            return $participant->getId() . ($slot->isWithdrawn() ? '*' : '');
        }

        $nodes = $slot->isReseeded() ? [] : $slot->getSourceNodes();

        return '[' . trim(($slot->getPlaceholder()->value ?? '?') . ' ' . implode(',', $nodes)) . ']';
    };

    $lines = [];
    foreach ($bracket->getRounds() as $round) {
        $lines[] = "{$round->getLabel()} ({$round->getSide()->value} {$round->getRound()})"
            . ($round->getRoundNumber() === null ? '' : " #{$round->getRoundNumber()}")
            . ($round->isNext() ? ' next' : '')
            . ($round->isManual() ? ' manual' : '');
        foreach ($round->getNodes() as $node) {
            $lines[] = "  {$node->getId()}" . ($node->getKind() === null ? '' : " {$node->getKind()->value}") . ': '
                . implode(' v ', array_map($slot, $node->getSlots()))
                . " => {$node->getStatus()->value}"
                . ($node->getAdvancer() instanceof Participant ? " -> {$node->getAdvancer()->getId()}" : '');
        }
    }
    $lines[] = $bracket->isComplete() ? 'complete' : 'not complete';

    return $lines;
}

/**
 * Pair the next round, record it as paired, and record a result for each
 * of its ties: the entrant the closure names wins every leg. A tie the
 * closure names nobody for is left without results.
 *
 * @param Closure(Participant, Participant): ?Participant $winner Given the two of a tie in
 *                                                                the order of its first leg
 *
 * @throws InvalidConfigurationException When the engine refuses the state
 * @throws NoValidPairingException When the engine finds no pairing
 */
function bracketViewPlay(StageEngineInterface $engine, StageState $state, Closure $winner): StageState
{
    $pairing = $engine->pairNextRound($state);
    $state = $state->withRoundPaired($pairing);

    $results = [];
    foreach ($pairing->getEvents() as $event) {
        [$first, $second] = array_values($event->getParticipants());
        if ($event->getMetadataValue('tie_leg') === 2) {
            [$first, $second] = [$second, $first];
        }
        $advancer = $winner($first, $second);
        if ($advancer instanceof Participant) {
            $results[] = new Result($event, $advancer);
        }
    }

    return $state->withAdditionalResults($results);
}

/**
 * The first entrant of every tie wins.
 *
 * @return Closure(Participant, Participant): Participant
 */
function bracketViewTopWins(): Closure
{
    return static fn(Participant $first, Participant $second): Participant => $first;
}

describe('a single elimination bracket', function (): void {
    it('is drawn whole before a round is played, byes and placeholders included', function (): void {
        $engine = new SingleEliminationEngine();
        $bracket = $engine->getBracket(StageState::start(bracketViewField(5)));

        // The fold of a bracket of 8: 1 v 8, 4 v 5, 2 v 7, 3 v 6, with no entrant at 6, 7 and 8
        expect(bracketViewText($bracket))->toBe([
            'quarterfinal (main 1) next',
            '  main:1:1: p1 v [bye] => bye -> p1',
            '  main:1:2: p4 v p5 => to-be-paired',
            '  main:1:3: p2 v [bye] => bye -> p2',
            '  main:1:4: p3 v [bye] => bye -> p3',
            'semifinal (main 2)',
            '  main:2:1: p1 v [advancer-of main:1:2] => awaiting-entrants',
            '  main:2:2: p2 v p3 => to-be-paired',
            'final (main 3)',
            '  main:3:1 final: [advancer-of main:2:1] v [advancer-of main:2:2] => awaiting-entrants',
            'not complete',
        ]);

        $first = $bracket->getRounds()[0]->getNodes();
        expect(array_map(
            static fn(BracketSlot $slot): ?int => $slot->getDrawPosition(),
            [...$first[0]->getSlots(), ...$first[1]->getSlots(), ...$first[2]->getSlots(), ...$first[3]->getSlots()]
        ))->toBe([1, 8, 4, 5, 2, 7, 3, 6])
            ->and($first[0]->getSlots()[1]->getSource())->toBe(SlotSource::Draw)
            ->and($first[0]->getSlots()[1]->isPending())->toBeFalse()
            ->and($bracket->getRounds()[1]->getNodes()[0]->getSlots()[1]->isPending())->toBeTrue();
    });

    it('shows each round as it is recorded, a round in play included, and agrees with the engine', function (): void {
        $engine = new SingleEliminationEngine();
        $state = bracketViewPlay($engine, StageState::start(bracketViewField(5)), static fn(Participant $first, Participant $second): Participant => $second);

        // The semifinals are paired and one result is in
        $semifinals = $engine->pairNextRound($state);
        $state = $state->withRoundPaired($semifinals)->withAdditionalResults([new Result($semifinals->getEvents()[0], $semifinals->getEvents()[0]->getParticipants()[0])]);

        expect(bracketViewText($engine->getBracket($state)))->toBe([
            'quarterfinal (main 1) #1',
            '  main:1:1: p1 v [bye] => bye -> p1',
            '  main:1:2: p4 v p5 => decided -> p5',
            '  main:1:3: p2 v [bye] => bye -> p2',
            '  main:1:4: p3 v [bye] => bye -> p3',
            'semifinal (main 2) #2',
            '  main:2:1: p1 v p5 => decided -> p1',
            '  main:2:2: p2 v p3 => in-play',
            'final (main 3)',
            '  main:3:1 final: p1 v [advancer-of main:2:2] => awaiting-entrants',
            'not complete',
        ]);

        $bracket = $engine->getBracket($state);
        $inPlay = $bracket->getNode('main:2:2');
        assert($inPlay instanceof BracketNode);
        expect($inPlay->getEvents())->toBe([$semifinals->getEvents()[1]])
            ->and($inPlay->getRoundNumber())->toBe(2)
            ->and($bracket->getNextRound())->toBeNull()
            ->and($bracket->getNode('main:1:2')?->getEvents())->toHaveCount(1)
            ->and($bracket->getNode('main:1:1')?->getEvents())->toBe([]);
    });

    it('labels every round as the engine labels the round it pairs', function (): void {
        $engine = new SingleEliminationEngine();
        $state = StageState::start(bracketViewField(20));

        while (!$engine->isComplete($state)) {
            $next = $engine->getBracket($state)->getNextRound();
            $pairing = $engine->pairNextRound($state);
            expect($next?->getLabel())->toBe($pairing->getLabel());
            $state = bracketViewPlay($engine, $state, bracketViewTopWins());
        }

        expect(array_map(
            static fn($round): string => $round->getLabel(),
            $engine->getBracket($state)->getRounds()
        ))->toBe(['round of 32', 'round of 16', 'quarterfinal', 'semifinal', 'final']);
    });

    it('shows the third-place tie as its own node of the last round, two-legged like the final (#93)', function (): void {
        $engine = new SingleEliminationEngine(new EliminationOptions(legsPerTie: 2, thirdPlace: true));
        $start = StageState::start(bracketViewField(4));

        expect(bracketViewText($engine->getBracket($start)))->toBe([
            'semifinal (main 1) next',
            '  main:1:1: p1 v p4 => to-be-paired',
            '  main:1:2: p2 v p3 => to-be-paired',
            'final (main 2)',
            '  main:2:1 final: [advancer-of main:1:1] v [advancer-of main:1:2] => awaiting-entrants',
            '  main:2:2 third-place: [loser-of main:1:1] v [loser-of main:1:2] => awaiting-entrants',
            'not complete',
        ]);

        // p1 and p2 go through: the final is p1 v p2 and the third-place tie p4 v p3
        $state = bracketViewPlay($engine, $start, bracketViewTopWins());
        $bracket = $engine->getBracket($state);
        expect(bracketViewText($bracket))->toBe([
            'semifinal (main 1) #1',
            '  main:1:1: p1 v p4 => decided -> p1',
            '  main:1:2: p2 v p3 => decided -> p2',
            'final (main 2) next',
            '  main:2:1 final: p1 v p2 => to-be-paired',
            '  main:2:2 third-place: p4 v p3 => to-be-paired',
            'not complete',
        ]);

        $thirdPlace = $bracket->getNode('main:2:2');
        assert($thirdPlace instanceof BracketNode);
        expect($thirdPlace->getKind())->toBe(BracketTie::ThirdPlace)
            ->and(array_map(static fn(BracketSlot $slot): SlotSource => $slot->getSource(), $thirdPlace->getSlots()))
            ->toBe([SlotSource::LoserOf, SlotSource::LoserOf])
            ->and($bracket->getRounds()[0]->getNodes()[0]->getEvents())->toHaveCount(2);

        // The final is played and the third-place tie has one leg of two: the bracket waits for it
        $last = $engine->pairNextRound($state);
        $state = $state->withRoundPaired($last);
        [$finalFirst, $finalSecond, $thirdFirst] = $last->getEvents();
        $state = $state->withAdditionalResults([
            new Result($finalFirst, $finalFirst->getParticipants()[1]),
            new Result($finalSecond, $finalFirst->getParticipants()[1]),
            new Result($thirdFirst, $thirdFirst->getParticipants()[0]),
        ]);

        expect(array_slice(bracketViewText($engine->getBracket($state)), 3))->toBe([
            'final (main 2) #2',
            '  main:2:1 final: p1 v p2 => decided -> p2',
            '  main:2:2 third-place: p4 v p3 => in-play',
            'not complete',
        ]);
    });

    it('has no third-place node in a field of 3, as the engine plays no third-place tie there', function (): void {
        $engine = new SingleEliminationEngine(new EliminationOptions(thirdPlace: true));

        expect(bracketViewText($engine->getBracket(StageState::start(bracketViewField(3)))))->toBe([
            'semifinal (main 1) next',
            '  main:1:1: p1 v [bye] => bye -> p1',
            '  main:1:2: p2 v p3 => to-be-paired',
            'final (main 2)',
            '  main:2:1 final: p1 v [advancer-of main:1:2] => awaiting-entrants',
            'not complete',
        ]);
    });

    it('shows a level tie that awaits its decider, and reads the bracket without an exception', function (): void {
        $engine = new SingleEliminationEngine();
        $state = StageState::start(bracketViewField(2));
        $final = $engine->pairNextRound($state);
        $state = $state->withRoundPlayed($final, [new Result($final->getEvents()[0])]);

        expect(bracketViewText($engine->getBracket($state)))->toBe([
            'final (main 1) #1',
            '  main:1:1 final: p1 v p2 => awaiting-decision',
            'not complete',
        ]);
    });

    it('shows a withdrawal as an uncontested tie and a tie both entrants left as void', function (): void {
        $engine = new SingleEliminationEngine();
        [$p1, $p2, $p3, $p4] = bracketViewField(4);
        $state = StageState::start([$p1, $p2, $p3, $p4]);
        $state = $state->withRoundPaired($engine->pairNextRound($state))
            ->withoutParticipant($p4)
            ->withoutParticipant($p2)
            ->withoutParticipant($p3);

        // Nobody goes on from p2 v p3: p1 stands alone in the final and goes on from it
        expect(bracketViewText($engine->getBracket($state)))->toBe([
            'semifinal (main 1) #1',
            '  main:1:1: p1 v p4* => uncontested -> p1',
            '  main:1:2: p2* v p3* => void',
            'final (main 2)',
            '  main:2:1 final: p1 v [void main:1:2] => bye -> p1',
            'complete',
        ])->and($engine->isComplete($state))->toBeTrue();

        $void = $engine->getBracket($state)->getNode('main:1:2');
        assert($void instanceof BracketNode);
        expect(array_map(static fn(Participant $participant): string => $participant->getId(), $void->getLosers()))->toBe(['p2', 'p3'])
            ->and($void->getStatus()->isSettled())->toBeTrue();
    });

    it('shows the next round with a tie given away before it is paired', function (): void {
        $engine = new SingleEliminationEngine();
        [$p1, $p2, $p3, $p4, $p5, $p6, $p7, $p8] = bracketViewField(8);
        $state = bracketViewPlay($engine, StageState::start([$p1, $p2, $p3, $p4, $p5, $p6, $p7, $p8]), bracketViewTopWins())
            ->withoutParticipant($p4);

        $pairing = $engine->pairNextRound($state);
        $bracket = $engine->getBracket($state);

        // p1 v p4 is not played: p1 is among the byes of the pairing, and the node is uncontested
        expect(array_slice(bracketViewText($bracket), 5))->toBe([
            'semifinal (main 2) next',
            '  main:2:1: p1 v p4* => uncontested -> p1',
            '  main:2:2: p2 v p3 => to-be-paired',
            'final (main 3)',
            '  main:3:1 final: p1 v [advancer-of main:2:2] => awaiting-entrants',
            'not complete',
        ])->and(array_map(static fn(Participant $participant): string => $participant->getId(), $pairing->getByes()))->toBe(['p1'])
            ->and($pairing->getEvents())->toHaveCount(1);
    });

    it('settles a tie still to come that an entrant has left, as the pairing of its round will', function (): void {
        $engine = new SingleEliminationEngine();
        [$p1, $p2, $p3, $p4, $p5, $p6, $p7, $p8] = bracketViewField(8);
        $state = StageState::start([$p1, $p2, $p3, $p4, $p5, $p6, $p7, $p8]);
        $quarterfinals = $engine->pairNextRound($state);
        [$first, $second] = $quarterfinals->getEvents();
        $state = $state->withRoundPaired($quarterfinals)
            ->withAdditionalResults([new Result($first, $p1), new Result($second, $p4)])
            ->withoutParticipant($p4);

        // The quarterfinals are in play, so the semifinals are a round to come: p4
        // has left, so p1 v p4 will be given away when it is paired, and p1 is
        // known to stand in the final already
        expect(array_slice(bracketViewText($engine->getBracket($state)), 5))->toBe([
            'semifinal (main 2)',
            '  main:2:1: p1 v p4* => uncontested -> p1',
            '  main:2:2: [advancer-of main:1:3] v [advancer-of main:1:4] => awaiting-entrants',
            'final (main 3)',
            '  main:3:1 final: p1 v [advancer-of main:2:2] => awaiting-entrants',
            'not complete',
        ]);

        $state = $state->withoutParticipant($p1);

        expect(array_slice(bracketViewText($engine->getBracket($state)), 6, 4))->toBe([
            '  main:2:1: p1* v p4* => void',
            '  main:2:2: [advancer-of main:1:3] v [advancer-of main:1:4] => awaiting-entrants',
            'final (main 3)',
            '  main:3:1 final: [void main:2:1] v [advancer-of main:2:2] => awaiting-entrants',
        ]);
    });

    it('waits for the re-seeding of a third-place tie when one semifinal is settled before the other', function (): void {
        $engine = new SingleEliminationEngine(new EliminationOptions(reseedEachRound: true, thirdPlace: true));
        [$p1, $p2, $p3, $p4] = bracketViewField(4);
        $state = StageState::start([$p1, $p2, $p3, $p4]);
        $semifinals = $engine->pairNextRound($state);
        $state = $state->withRoundPaired($semifinals)
            ->withAdditionalResults([new Result($semifinals->getEvents()[0], $p1)]);

        // p4 is out and will play for third place, in whichever slot the
        // re-seeding puts it: the slot is still to be filled, not a slot for nobody
        $third = $engine->getBracket($state)->getNode('main:2:2');
        assert($third instanceof BracketNode);

        expect(array_map(static fn(BracketSlot $slot): ?SlotPlaceholder => $slot->getPlaceholder(), $third->getSlots()))
            ->toBe([SlotPlaceholder::Reseeded, SlotPlaceholder::Reseeded])
            ->and($third->getSlots()[0]->isPending())->toBeTrue()
            ->and($third->getStatus())->toBe(BracketNodeStatus::AwaitingEntrants);
    });

    it('leaves a slot of a re-seeded round it has reached empty, not waiting, where a void tie sent nobody', function (): void {
        $engine = new SingleEliminationEngine(new EliminationOptions(reseedEachRound: true, thirdPlace: true));
        [$p1, $p2, $p3, $p4, $p5, $p6, $p7, $p8] = bracketViewField(8);
        $state = StageState::start([$p1, $p2, $p3, $p4, $p5, $p6, $p7, $p8]);
        $quarterfinals = $engine->pairNextRound($state);
        $state = $state->withRoundPaired($quarterfinals)->withoutParticipant($p3)->withoutParticipant($p6);
        $results = [];
        foreach ($quarterfinals->getEvents() as $event) {
            $first = $event->getParticipants()[0];
            if ($first->getId() !== 'p3') {
                $results[] = new Result($event, $first);
            }
        }
        $state = $state->withAdditionalResults($results);

        // Three entrants go on from the quarterfinals, so the re-seeded
        // semifinals give p1 a bye: its other slot will hold nobody
        $next = $engine->getBracket($state)->getNextRound();
        assert($next !== null);
        $bye = $next->getNodes()[0];

        expect($bye->getStatus())->toBe(BracketNodeStatus::Bye)
            ->and(array_map(static fn(BracketSlot $slot): bool => $slot->isPending(), $bye->getSlots()))->toBe([false, false])
            ->and(array_map(static fn(Participant $participant): string => $participant->getId(), $engine->pairNextRound($state)->getByes()))
            ->toBe([$bye->getAdvancer()?->getId()]);
    });

    it('places a re-seeded round only once the round before it is settled', function (): void {
        $engine = new SingleEliminationEngine(new EliminationOptions(reseedEachRound: true));
        $start = StageState::start(bracketViewField(4));

        expect(bracketViewText($engine->getBracket($start)))->toBe([
            'semifinal (main 1) next',
            '  main:1:1: p1 v p4 => to-be-paired',
            '  main:1:2: p2 v p3 => to-be-paired',
            'final (main 2)',
            '  main:2:1 final: [reseeded] v [reseeded] => awaiting-entrants',
            'not complete',
        ]);

        $state = bracketViewPlay($engine, $start, static fn(Participant $first, Participant $second): Participant => $second);
        $final = $engine->getBracket($state)->getNode('main:2:1');
        assert($final instanceof BracketNode);

        expect(array_map(static fn(Participant $participant): string => $participant->getId(), $final->getParticipants()))
            ->toBe(array_map(
                static fn(Participant $participant): string => $participant->getId(),
                array_values($engine->pairNextRound($state)->getEvents()[0]->getParticipants())
            ))
            ->and($final->getSlots()[0]->isReseeded())->toBeTrue()
            ->and($final->getSlots()[0]->getSourceNodes())->toBe(['main:1:1', 'main:1:2'])
            ->and($final->getStatus())->toBe(BracketNodeStatus::ToBePaired);
    });

    it('keeps a round drawn by hand as recorded, a node for each of its ties', function (): void {
        $engine = new SingleEliminationEngine();
        [$p1, $p2, $p3, $p4] = bracketViewField(4);
        $round = new Round(1, ['label' => 'semifinal']);
        $drawn = new RoundPairing(1, 'semifinal', [new Event([$p1, $p2], $round), new Event([$p3, $p4], $round)]);
        $state = StageState::fromHistory([$p1, $p2, $p3, $p4], [$drawn]);

        // A tie stands where the earlier of its two entrants stood: p4 is in the slot before p3
        expect(bracketViewText($engine->getBracket($state)))->toBe([
            'semifinal (main 1) #1 manual',
            '  main:1:1: p1 v p2 => in-play',
            '  main:1:2: p4 v p3 => in-play',
            'final (main 2)',
            '  main:2:1 final: [advancer-of main:1:1] v [advancer-of main:1:2] => awaiting-entrants',
            'not complete',
        ]);

        $node = $engine->getBracket($state)->getNode('main:1:1');
        assert($node instanceof BracketNode);
        expect(array_map(static fn(BracketSlot $slot): ?int => $slot->getDrawPosition(), $node->getSlots()))->toBe([1, 2]);
    });
});

describe('a double elimination bracket', function (): void {
    it('has a winners side, a losers side and a grand final with its reset', function (): void {
        $engine = new DoubleEliminationEngine();
        $bracket = $engine->getBracket(StageState::start(bracketViewField(4)));

        expect(bracketViewText($bracket))->toBe([
            'winners round 1 (winners 1) next',
            '  winners:1:1: p1 v p4 => to-be-paired',
            '  winners:1:2: p2 v p3 => to-be-paired',
            'losers round 1 (losers 1)',
            '  losers:1:1: [loser-of winners:1:1] v [loser-of winners:1:2] => awaiting-entrants',
            'winners final (winners 2)',
            '  winners:2:1: [advancer-of winners:1:1] v [advancer-of winners:1:2] => awaiting-entrants',
            'losers final (losers 2)',
            '  losers:2:1: [loser-of winners:2:1] v [advancer-of losers:1:1] => awaiting-entrants',
            'grand final (grand-final 1)',
            '  grand-final:1:1: [advancer-of winners:2:1] v [advancer-of losers:2:1] => awaiting-entrants',
            'grand final reset (grand-final 2)',
            '  grand-final:2:1: [loser-of grand-final:1:1] v [advancer-of grand-final:1:1] => awaiting-entrants',
            'not complete',
        ])->and($bracket->getSides())->toBe([BracketSide::Winners, BracketSide::Losers, BracketSide::GrandFinal])
            ->and($bracket->getRoundsOn(BracketSide::Losers))->toHaveCount(2);
    });

    it('plays the reset when the losers champion wins the grand final, and shows it as not played otherwise', function (): void {
        $engine = new DoubleEliminationEngine();

        // The first-named entrant always wins: p1 wins everything and the reset is not due
        $state = StageState::start(bracketViewField(4));
        while (!$engine->isComplete($state)) {
            $state = bracketViewPlay($engine, $state, bracketViewTopWins());
        }
        expect(array_slice(bracketViewText($engine->getBracket($state)), 9))->toBe([
            'grand final (grand-final 1) #5',
            '  grand-final:1:1: p1 v p2 => decided -> p1',
            'grand final reset (grand-final 2)',
            '  grand-final:2:1: [not-played grand-final:1:1] v [not-played grand-final:1:1] => not-played',
            'complete',
        ]);

        // The second-named always wins: the losers champion takes the grand final, and the reset decides
        $state = StageState::start(bracketViewField(4));
        while (!$engine->isComplete($state)) {
            $state = bracketViewPlay($engine, $state, static fn(Participant $first, Participant $second): Participant => $second);
        }
        expect(bracketViewText($engine->getBracket($state)))->toBe([
            'winners round 1 (winners 1) #1',
            '  winners:1:1: p1 v p4 => decided -> p4',
            '  winners:1:2: p2 v p3 => decided -> p3',
            'losers round 1 (losers 1) #2',
            '  losers:1:1: p1 v p2 => decided -> p2',
            'winners final (winners 2) #3',
            '  winners:2:1: p4 v p3 => decided -> p3',
            'losers final (losers 2) #4',
            '  losers:2:1: p4 v p2 => decided -> p2',
            'grand final (grand-final 1) #5',
            '  grand-final:1:1: p3 v p2 => decided -> p2',
            'grand final reset (grand-final 2) #6',
            '  grand-final:2:1: p3 v p2 => decided -> p2',
            'complete',
        ]);
    });

    it('passes a bye on to the losers side as a bye, not as an entrant', function (): void {
        $engine = new DoubleEliminationEngine(new EliminationOptions(grandFinalReset: false));

        expect(bracketViewText($engine->getBracket(StageState::start(bracketViewField(3)))))->toBe([
            'winners round 1 (winners 1) next',
            '  winners:1:1: p1 v [bye] => bye -> p1',
            '  winners:1:2: p2 v p3 => to-be-paired',
            'losers round 1 (losers 1)',
            '  losers:1:1: [bye winners:1:1] v [loser-of winners:1:2] => awaiting-entrants',
            'winners final (winners 2)',
            '  winners:2:1: p1 v [advancer-of winners:1:2] => awaiting-entrants',
            'losers final (losers 2)',
            '  losers:2:1: [loser-of winners:2:1] v [advancer-of losers:1:1] => awaiting-entrants',
            'grand final (grand-final 1)',
            '  grand-final:1:1: [advancer-of winners:2:1] v [advancer-of losers:2:1] => awaiting-entrants',
            'not complete',
        ]);
    });

    it('feeds the losers rounds of a field of 8 from the droppers in the order the engine pairs them', function (): void {
        $engine = new DoubleEliminationEngine();
        $bracket = $engine->getBracket(StageState::start(bracketViewField(8)));

        $sources = [];
        foreach ($bracket->getRoundsOn(BracketSide::Losers) as $round) {
            foreach ($round->getNodes() as $node) {
                $sources[$node->getId()] = array_map(
                    static fn(BracketSlot $slot): string => $slot->getSource()->value . ' ' . implode(',', $slot->getSourceNodes()),
                    $node->getSlots()
                );
            }
        }

        // After winners round 2, an even round, the droppers come in reverse order
        expect($sources)->toBe([
            'losers:1:1' => ['loser-of winners:1:1', 'loser-of winners:1:2'],
            'losers:1:2' => ['loser-of winners:1:3', 'loser-of winners:1:4'],
            'losers:2:1' => ['loser-of winners:2:2', 'advancer-of losers:1:1'],
            'losers:2:2' => ['loser-of winners:2:1', 'advancer-of losers:1:2'],
            'losers:3:1' => ['advancer-of losers:2:1', 'advancer-of losers:2:2'],
            'losers:4:1' => ['loser-of winners:3:1', 'advancer-of losers:3:1'],
        ])->and(array_map(static fn($round): string => $round->getLabel(), $bracket->getRounds()))->toBe([
            'winners round 1', 'losers round 1', 'winners round 2', 'losers round 2', 'losers round 3',
            'winners final', 'losers final', 'grand final', 'grand final reset',
        ]);
    });
});

describe('the results of a node (#181)', function (): void {
    it('shows the result of each leg in leg order, a tie with only its first leg recorded included', function (): void {
        $engine = new SingleEliminationEngine(new EliminationOptions(legsPerTie: 2, thirdPlace: true));
        [$p1, $p2, $p3, $p4] = bracketViewField(4);
        $state = bracketViewPlay($engine, StageState::start([$p1, $p2, $p3, $p4]), bracketViewTopWins());

        // The final is played to its end; the third-place tie has its first leg only
        $last = $engine->pairNextRound($state);
        $state = $state->withRoundPaired($last);
        [$finalFirst, $finalSecond, $thirdFirst, $thirdSecond] = $last->getEvents();
        $finalLeg1 = new Result($finalFirst, $p2, ['p1' => 1, 'p2' => 2]);
        $finalLeg2 = new Result($finalSecond, null, ['p1' => 0, 'p2' => 0]);
        $thirdLeg1 = new Result($thirdFirst, $p4, ['p3' => 0, 'p4' => 3]);
        $state = $state->withAdditionalResults([$finalLeg1, $finalLeg2, $thirdLeg1]);

        $bracket = $engine->getBracket($state);
        $final = $bracket->getNode('main:2:1');
        $third = $bracket->getNode('main:2:2');
        assert($final instanceof BracketNode && $third instanceof BracketNode);

        expect($final->getEvents())->toBe([$finalFirst, $finalSecond])
            ->and($final->getResults())->toBe([$finalLeg1, $finalLeg2])
            ->and($final->getStatus())->toBe(BracketNodeStatus::Decided)
            ->and($third->getEvents())->toBe([$thirdFirst, $thirdSecond])
            ->and($third->getResults())->toBe([$thirdLeg1, null])
            ->and($third->getStatus())->toBe(BracketNodeStatus::InPlay)
            ->and($third->getResults()[0]?->getScoreFor($p4))->toBe(3)
            // The semifinals were played whole, with no scores
            ->and(array_map(
                static fn(?Result $result): ?string => $result?->getWinner()?->getId(),
                $bracket->getNode('main:1:2')?->getResults() ?? []
            ))->toBe(['p2', 'p2']);
    });

    it('has a null for every event of a round recorded when it was paired, and no result where there is no event', function (): void {
        $engine = new SingleEliminationEngine();
        $state = StageState::start(bracketViewField(5));
        $state = $state->withRoundPaired($engine->pairNextRound($state));
        $bracket = $engine->getBracket($state);

        $results = [];
        foreach ($bracket->getNodes() as $node) {
            $results[$node->getId()] = [count($node->getEvents()), $node->getResults()];
        }

        // main:1:2 (p4 v p5) is the round's one event; the byes and the rounds to come have none
        expect($results)->toBe([
            'main:1:1' => [0, []],
            'main:1:2' => [1, [null]],
            'main:1:3' => [0, []],
            'main:1:4' => [0, []],
            'main:2:1' => [0, []],
            'main:2:2' => [0, []],
            'main:3:1' => [0, []],
        ])->and($bracket->getNode('main:1:1')?->getStatus())->toBe(BracketNodeStatus::Bye)
            ->and($bracket->getNode('main:1:4')?->getStatus())->toBe(BracketNodeStatus::Bye);

        // Two byes meet in the first losers round of a double elimination bracket of 5: an empty node
        $double = new DoubleEliminationEngine();
        $state = StageState::start(bracketViewField(5));
        $state = $state->withRoundPaired($double->pairNextRound($state));
        $empty = $double->getBracket($state)->getNode('losers:1:2');
        expect($empty?->getStatus())->toBe(BracketNodeStatus::Empty)
            ->and($empty?->getResults())->toBe([]);
    });

    it('shows what was recorded of a tie an entrant left while it was played, and of a void tie', function (): void {
        $engine = new SingleEliminationEngine(new EliminationOptions(legsPerTie: 2));
        [$p1, $p2, $p3, $p4] = bracketViewField(4);
        $state = StageState::start([$p1, $p2, $p3, $p4]);
        $pairing = $engine->pairNextRound($state);
        $state = $state->withRoundPaired($pairing);

        // p1 v p4 has its first leg, then p4 leaves; p2 and p3 both leave before either leg
        $legs = array_values(array_filter(
            $pairing->getEvents(),
            static fn(Event $event): bool => $event->hasParticipant($p1)
        ));
        $first = new Result($legs[0], $p1, ['p1' => 1, 'p4' => 0]);
        $state = $state->withAdditionalResults([$first])
            ->withoutParticipant($p4)
            ->withoutParticipant($p2)
            ->withoutParticipant($p3);

        $bracket = $engine->getBracket($state);
        $uncontested = $bracket->getNode('main:1:1');
        $void = $bracket->getNode('main:1:2');
        assert($uncontested instanceof BracketNode && $void instanceof BracketNode);

        expect($uncontested->getStatus())->toBe(BracketNodeStatus::Uncontested)
            ->and($uncontested->getResults())->toBe([$first, null])
            ->and($void->getStatus())->toBe(BracketNodeStatus::Void)
            ->and($void->getEvents())->toHaveCount(2)
            ->and($void->getResults())->toBe([null, null])
            ->and($bracket->getNode('main:2:1')?->getResults())->toBe([]);
    });

    it('shows an awarded result and a decider as recorded, and the result that replaced another', function (): void {
        $engine = new SingleEliminationEngine();
        [$p1, $p2, $p3, $p4] = bracketViewField(4);
        $state = StageState::start([$p1, $p2, $p3, $p4]);
        $pairing = $engine->pairNextRound($state);
        [$top, $bottom] = $pairing->getEvents();

        // The top tie is awarded to p1; the bottom one is level and awaits its decider
        $awarded = new Result($top, $p1, [], [], new Award('walkover'));
        $level = new Result($bottom, null, ['p2' => 1, 'p3' => 1]);
        $state = $state->withRoundPlayed($pairing, [$awarded, $level]);
        $node = static fn(StageState $state, string $id): BracketNode => $engine->getBracket($state)->getNode($id) ?? throw new LogicException($id);

        expect($node($state, 'main:1:1')->getResults())->toBe([$awarded])
            ->and($node($state, 'main:1:1')->getResults()[0]?->isAwarded())->toBeTrue()
            ->and($node($state, 'main:1:2')->getResults())->toBe([$level])
            ->and($node($state, 'main:1:2')->getStatus())->toBe(BracketNodeStatus::AwaitingDecision);

        // The level result is replaced by the same scores with a decider for p3
        $decided = new Result($bottom, null, ['p2' => 1, 'p3' => 1], [], null, new Decider(['p3'], 'penalties'));
        $state = $state->withResultReplaced($decided);

        expect($node($state, 'main:1:2')->getResults())->toBe([$decided])
            ->and($node($state, 'main:1:2')->getResults()[0]?->getDecider()?->getLabel())->toBe('penalties')
            ->and($node($state, 'main:1:2')->getAdvancer())->toBe($p3)
            ->and($node($state, 'main:1:2')->getStatus())->toBe(BracketNodeStatus::Decided);
    });

    it('shows the results of a grand final and of its reset, and none for a reset not played', function (): void {
        $engine = new DoubleEliminationEngine();

        // The second-named always wins: the losers champion takes the grand final, and the reset is played
        $state = StageState::start(bracketViewField(4));
        while (!$engine->isComplete($state)) {
            $state = bracketViewPlay($engine, $state, static fn(Participant $first, Participant $second): Participant => $second);
        }
        $results = array_values($state->getResults());
        $bracket = $engine->getBracket($state);
        expect($bracket->getNode('grand-final:1:1')?->getResults())->toBe([$results[count($results) - 2]])
            ->and($bracket->getNode('grand-final:2:1')?->getResults())->toBe([$results[count($results) - 1]])
            ->and($bracket->getNode('grand-final:2:1')?->getResults()[0]?->getWinner()?->getId())->toBe('p2');

        // The first-named always wins: the reset is not played and has no result
        $state = StageState::start(bracketViewField(4));
        while (!$engine->isComplete($state)) {
            $state = bracketViewPlay($engine, $state, bracketViewTopWins());
        }
        $bracket = $engine->getBracket($state);
        expect($bracket->getNode('grand-final:1:1')?->getResults())->toHaveCount(1)
            ->and($bracket->getNode('grand-final:2:1')?->getStatus())->toBe(BracketNodeStatus::NotPlayed)
            ->and($bracket->getNode('grand-final:2:1')?->getResults())->toBe([]);
    });
});

describe('the parts of the view', function (): void {
    it('renders the labels of the rounds from where they stand', function (): void {
        $text = static fn(BracketSide $side, int $round, int $roundsOnSide, int $slotCount): string => RoundLabel::ofBracketRound($side, $round, $roundsOnSide, $slotCount)->toString();

        expect($text(BracketSide::Main, 1, 0, 2))->toBe('final')
            ->and($text(BracketSide::Main, 1, 0, 3))->toBe('semifinal')
            ->and($text(BracketSide::Main, 1, 0, 8))->toBe('quarterfinal')
            ->and($text(BracketSide::Main, 1, 0, 9))->toBe('round of 16')
            ->and($text(BracketSide::Winners, 2, 3, 4))->toBe('winners round 2')
            ->and($text(BracketSide::Winners, 3, 3, 2))->toBe('winners final')
            ->and($text(BracketSide::Losers, 3, 4, 4))->toBe('losers round 3')
            ->and($text(BracketSide::Losers, 4, 4, 2))->toBe('losers final')
            ->and($text(BracketSide::GrandFinal, 1, 2, 2))->toBe('grand final')
            ->and($text(BracketSide::GrandFinal, 2, 2, 2))->toBe('grand final reset');
    });

    it('holds an entrant or a placeholder in a slot, exactly one of the two', function (): void {
        $participant = new Participant('p1', 'Entrant 1');

        expect(fn() => new BracketSlot($participant, SlotPlaceholder::Bye, SlotSource::Draw))->toThrow(InvalidInputException::class)
            ->and(fn() => new BracketSlot(null, null, SlotSource::Draw))->toThrow(InvalidInputException::class)
            ->and((new BracketSlot(null, SlotPlaceholder::AdvancerOf, SlotSource::AdvancerOf, ['main:1:1']))->isPending())->toBeTrue()
            ->and((new BracketSlot(null, SlotPlaceholder::Void, SlotSource::AdvancerOf, ['main:1:1']))->isPending())->toBeFalse()
            ->and((new BracketSlot($participant, null, SlotSource::Draw, [], false, 1))->isPending())->toBeFalse();
    });

    it('says which statuses are settled', function (): void {
        $settled = array_values(array_filter(
            BracketNodeStatus::cases(),
            static fn(BracketNodeStatus $status): bool => $status->isSettled()
        ));

        expect($settled)->toBe([
            BracketNodeStatus::Decided,
            BracketNodeStatus::Uncontested,
            BracketNodeStatus::Bye,
            BracketNodeStatus::Void,
            BracketNodeStatus::Empty,
            BracketNodeStatus::NotPlayed,
        ])->and(array_values(array_filter(
            SlotPlaceholder::cases(),
            static fn(SlotPlaceholder $placeholder): bool => $placeholder->isPending()
        )))->toBe([SlotPlaceholder::AdvancerOf, SlotPlaceholder::LoserOf, SlotPlaceholder::Reseeded]);
    });

    it('is offered by both elimination engines and finds a node by its ID', function (): void {
        $bracket = (new SingleEliminationEngine())->getBracket(StageState::start(bracketViewField(4)));

        expect(new SingleEliminationEngine())->toBeInstanceOf(BracketViewEngine::class)
            ->and(new DoubleEliminationEngine())->toBeInstanceOf(BracketViewEngine::class)
            ->and($bracket->getNode('main:2:1')?->getKind())->toBe(BracketTie::Final)
            ->and($bracket->getNode('main:3:1'))->toBeNull()
            ->and(BracketNode::idOf(BracketSide::Losers, 3, 2))->toBe('losers:3:2')
            ->and($bracket->getNodes())->toHaveCount(3);
    });
});
