<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Scheduling\DoubleEliminationEngine;
use MissionGaming\Tactician\Scheduling\EliminationOptions;
use MissionGaming\Tactician\Scheduling\SingleEliminationEngine;
use MissionGaming\Tactician\Stage\BracketNodeStatus;
use MissionGaming\Tactician\Stage\BracketTie;
use MissionGaming\Tactician\Stage\MatchOutcomeSelector;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Stage\SlotSource;
use MissionGaming\Tactician\Stage\StageOutcome;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Stage\Withdrawal;

// A round drawn by hand is read as drawn
// (docs/adr/0023-a-round-drawn-by-hand-is-read-as-drawn.md).

/**
 * @return array<string, Participant>
 */
function handDrawnField(int $count): array
{
    $field = [];
    for ($i = 1; $i <= $count; ++$i) {
        $field["p{$i}"] = new Participant("p{$i}", "P{$i}");
    }

    return $field;
}

/**
 * The first-named entrant of every event wins it.
 *
 * @param array<Event> $events
 *
 * @return list<Result>
 */
function handDrawnFirstWins(array $events): array
{
    return array_values(array_map(static fn(Event $event): Result => new Result($event, array_values($event->getParticipants())[0]), $events));
}

/**
 * A round as text: its events, then its byes.
 */
function handDrawnShape(RoundPairing $round): string
{
    $events = array_map(
        static fn(Event $event): string => implode(' v ', array_map(static fn(Participant $participant): string => $participant->getId(), array_values($event->getParticipants()))),
        array_values($round->getEvents())
    );
    $byes = array_map(static fn(Participant $participant): string => $participant->getId(), array_values($round->getByes()));

    return implode(', ', $events) . ($byes === [] ? '' : '; byes ' . implode(', ', $byes));
}

/**
 * The tier of every participant of a table, by ID.
 *
 * @return array<string, int>
 *
 * @throws InvalidConfigurationException When the engine refuses the state
 */
function handDrawnTiers(SingleEliminationEngine|DoubleEliminationEngine $engine, StageState $state): array
{
    $table = $engine->getStandings($state);
    $tiers = [];
    foreach (array_values($table->getEntries()) as $index => $entry) {
        $tiers[$entry->getParticipant()->getId()] = $table->getTiers()[$index] ?? -1;
    }
    ksort($tiers, SORT_NATURAL);

    return $tiers;
}

describe('a round drawn by hand after a withdrawal (issue #172)', function (): void {
    /**
     * Sixteen entrants on a fixed path, the first-named winning every tie;
     * p1 withdraws after round 1, and the quarterfinals are drawn by hand
     * among the seven left: three ties and a bye.
     *
     * @return array{SingleEliminationEngine, StageState, array<string, Participant>}
     */
    $quarterfinalsDrawnByHand = static function (bool $fromHistory): array {
        $field = handDrawnField(16);
        $engine = new SingleEliminationEngine();
        $state = StageState::start(array_values($field));
        $first = $engine->pairNextRound($state);
        $state = $state->withRoundPlayed($first, handDrawnFirstWins($first->getEvents()));
        $state = $state->withoutParticipant($field['p1']);

        $quarterfinals = new RoundPairing(2, 'quarterfinal', [
            new Event([$field['p8'], $field['p4']], new Round(2)),
            new Event([$field['p5'], $field['p2']], new Round(2)),
            new Event([$field['p7'], $field['p3']], new Round(2)),
        ], [$field['p6']]);
        $results = handDrawnFirstWins($quarterfinals->getEvents());

        if ($fromHistory) {
            return [$engine, StageState::fromHistory(
                array_values($field),
                [$first, $quarterfinals],
                [...handDrawnFirstWins($first->getEvents()), ...$results],
                [new Withdrawal($field['p1'], 1)]
            ), $field];
        }

        return [$engine, $state->withRoundPlayed($quarterfinals, $results), $field];
    };

    it('gives the withdrawn entrant no place in the next round', function (bool $fromHistory) use ($quarterfinalsDrawnByHand): void {
        [$engine, $state] = $quarterfinalsDrawnByHand($fromHistory);

        // Then: The four the round sent on meet in two semifinals
        $semifinals = $engine->pairNextRound($state);
        expect($semifinals->getLabel())->toBe('semifinal')
            ->and(handDrawnShape($semifinals))->toBe('p8 v p5, p7 v p6');

        // And the bracket ends with the final, four rounds in all
        $state = $state->withRoundPlayed($semifinals, handDrawnFirstWins($semifinals->getEvents()));
        $final = $engine->pairNextRound($state);
        $state = $state->withRoundPlayed($final, handDrawnFirstWins($final->getEvents()));

        expect(handDrawnShape($final))->toBe('p8 v p7')
            ->and($final->getLabel())->toBe('final')
            ->and($engine->isComplete($state))->toBeTrue()
            ->and(array_map(static fn($round): bool => $round->isManual(), $engine->getRecordedRounds($state)))->toBe([false, true, false, false]);

        // And p1 went out in the quarterfinals, with the three who lost there
        $tiers = handDrawnTiers($engine, $state);
        expect([$tiers['p8'], $tiers['p7'], $tiers['p5'], $tiers['p6']])->toBe([0, 1, 2, 2])
            ->and([$tiers['p1'], $tiers['p4'], $tiers['p2'], $tiers['p3']])->toBe([3, 3, 3, 3])
            ->and($tiers['p9'])->toBe(4);
    })->with(['through the verbs' => [false], 'from the rows of an application' => [true]]);

    it('shows the withdrawn entrant in a node of its own that fills no slot', function () use ($quarterfinalsDrawnByHand): void {
        [$engine, $state] = $quarterfinalsDrawnByHand(false);

        $bracket = $engine->getBracket($state);
        $quarterfinals = $bracket->getRounds()[1];
        $alone = $quarterfinals->getNodes()[0];

        expect($quarterfinals->isManual())->toBeTrue()
            ->and($alone->getStatus())->toBe(BracketNodeStatus::Void)
            ->and(array_map(static fn(Participant $participant): string => $participant->getId(), $alone->getParticipants()))->toBe(['p1'])
            ->and($bracket->getRounds())->toHaveCount(4);

        // The semifinals are filled from the four nodes that sent somebody on
        $sources = [];
        foreach ($bracket->getRounds()[2]->getNodes() as $node) {
            foreach ($node->getSlots() as $slot) {
                expect($slot->getSource())->toBe(SlotSource::AdvancerOf);
                $sources = [...$sources, ...$slot->getSourceNodes()];
            }
        }
        expect($sources)->toBe(['main:2:2', 'main:2:3', 'main:2:4', 'main:2:5']);
    });

    it('still sends on an entrant the round leaves alone who is in the stage', function (): void {
        // Given: Eight entrants, and a second round drawn by hand that leaves
        // p1 and p3 out of its ties; nobody has withdrawn
        $field = handDrawnField(8);
        $engine = new SingleEliminationEngine();
        $state = StageState::start(array_values($field));
        $first = $engine->pairNextRound($state);
        $state = $state->withRoundPlayed($first, handDrawnFirstWins($first->getEvents()));
        $second = new RoundPairing(2, 'semifinal', [new Event([$field['p4'], $field['p2']], new Round(2))], [$field['p1'], $field['p3']]);
        $state = $state->withRoundPlayed($second, handDrawnFirstWins($second->getEvents()));

        // Then: All three it sent on are in the next round
        expect(handDrawnShape($engine->pairNextRound($state)))->toBe('p1 v p4; byes p3');
    });

    it('reads a stage of a double elimination bracket drawn so around a withdrawal, which it refused', function (): void {
        // Given: Eight entrants; p1 withdraws after winners round 1, and the
        // second winners round is drawn by hand among the three left in it
        $field = handDrawnField(8);
        $engine = new DoubleEliminationEngine(new EliminationOptions(grandFinalReset: false));
        $state = StageState::start(array_values($field));
        for ($stage = 0; $stage < 2; ++$stage) {
            $round = $engine->pairNextRound($state);
            $state = $state->withRoundPlayed($round, handDrawnFirstWins($round->getEvents()));
        }
        $state = $state->withoutParticipant($field['p1']);
        $winnersRound = $engine->pairNextRound($state);
        expect(handDrawnShape($winnersRound))->toBe('p2 v p3; byes p4');

        $drawn = new RoundPairing($winnersRound->getRoundNumber(), $winnersRound->getLabel(), [new Event([$field['p4'], $field['p2']], new Round($winnersRound->getRoundNumber()))], [$field['p3']]);
        $state = $state->withRoundPlayed($drawn, handDrawnFirstWins($drawn->getEvents()));

        // Then: The stage leaves two places, as it has, and the winners final
        // is between the two it sent on
        $next = $engine->pairNextRound($state);
        expect($engine->getRecordedRounds($state)[2]->isManual())->toBeTrue();
        while (!str_starts_with((string) $next->getLabel(), 'winners final')) {
            $state = $state->withRoundPlayed($next, handDrawnFirstWins($next->getEvents()));
            $next = $engine->pairNextRound($state);
        }
        expect(handDrawnShape($next))->toBe('p4 v p3');
    });
});

describe('a last round drawn by hand across the final and the third-place tie (issue #183)', function (): void {
    /**
     * Four entrants with a third-place tie: p1 and p2 win the semifinals,
     * and the last round is drawn by hand with the two ties given.
     *
     * @param list<string> $final
     * @param list<string> $thirdPlace
     *
     * @return array{SingleEliminationEngine, StageState, RoundPairing}
     */
    $lastRoundDrawnByHand = static function (array $final, array $thirdPlace, bool $marked = true): array {
        $field = handDrawnField(4);
        $engine = new SingleEliminationEngine(new EliminationOptions(thirdPlace: true));
        $state = StageState::start(array_values($field));
        $semifinals = $engine->pairNextRound($state);
        $state = $state->withRoundPlayed($semifinals, handDrawnFirstWins($semifinals->getEvents()));
        expect(handDrawnShape($engine->pairNextRound($state)))->toBe('p1 v p2, p4 v p3');

        $mark = static fn(BracketTie $tie): array => $marked ? [BracketTie::METADATA_KEY => $tie->value] : [];
        $last = new RoundPairing(2, 'final', [
            new Event([$field[$final[0]], $field[$final[1]]], new Round(2), $mark(BracketTie::Final)),
            new Event([$field[$thirdPlace[0]], $field[$thirdPlace[1]]], new Round(2), $mark(BracketTie::ThirdPlace)),
        ]);

        return [$engine, $state, $last];
    };

    it('reads the marked ties as the final and the third-place tie, whoever they hold', function (array $final, array $thirdPlace, array $tiers) use ($lastRoundDrawnByHand): void {
        [$engine, $state, $last] = $lastRoundDrawnByHand($final, $thirdPlace);
        $state = $state->withRoundPlayed($last, handDrawnFirstWins($last->getEvents()));

        // Then: The bracket is over, the final placing first and second and
        // the third-place tie third and fourth
        expect($engine->isComplete($state))->toBeTrue()
            ->and(handDrawnTiers($engine, $state))->toBe($tiers)
            ->and($engine->getRecordedRounds($state)[1]->isManual())->toBeTrue();

        $outcome = $engine->getOutcome($state);
        assert($outcome instanceof StageOutcome);
        expect(array_map(static fn(Participant $participant): string => $participant->getId(), MatchOutcomeSelector::winners()->select($outcome)))->toBe([$final[0]])
            ->and(array_map(static fn(Participant $participant): string => $participant->getId(), MatchOutcomeSelector::losers()->select($outcome)))->toBe([$final[1]]);

        // And the view has one last round, in which every entrant is once
        $bracket = $engine->getBracket($state);
        $rounds = $bracket->getRounds();
        expect($rounds)->toHaveCount(2);
        $named = [];
        foreach ($rounds[1]->getNodes() as $node) {
            expect($node->getStatus())->toBe(BracketNodeStatus::Decided);
            foreach ($node->getParticipants() as $participant) {
                $named[] = $participant->getId();
            }
        }
        sort($named);
        expect($named)->toBe(['p1', 'p2', 'p3', 'p4'])
            ->and($rounds[1]->getNodes()[0]->getKind())->toBe(BracketTie::Final)
            ->and($rounds[1]->getNodes()[1]->getKind())->toBe(BracketTie::ThirdPlace);
    })->with([
        'a semifinal winner and loser swapped' => [['p1', 'p4'], ['p2', 'p3'], ['p1' => 0, 'p2' => 2, 'p3' => 3, 'p4' => 1]],
        'the semifinal losers in the final' => [['p4', 'p3'], ['p1', 'p2'], ['p1' => 2, 'p2' => 3, 'p3' => 1, 'p4' => 0]],
    ]);

    it('keeps the final in play with both of its entrants in the bracket', function () use ($lastRoundDrawnByHand): void {
        [$engine, $state, $last] = $lastRoundDrawnByHand(['p1', 'p4'], ['p2', 'p3']);

        // Given: Only the third-place tie has its result
        $state = $state->withRoundPlayed($last, handDrawnFirstWins([array_values($last->getEvents())[1]]));

        // Then: The two of the final share the first tier, and the two of
        // the third-place tie share the one below, as in any bracket not over
        expect($engine->isComplete($state))->toBeFalse()
            ->and(handDrawnTiers($engine, $state))->toBe(['p1' => 0, 'p2' => 2, 'p3' => 2, 'p4' => 0]);
    });

    it('reads a last round without the marks as any round: nothing in it says which tie is which', function () use ($lastRoundDrawnByHand): void {
        [$engine, $state, $last] = $lastRoundDrawnByHand(['p1', 'p4'], ['p2', 'p3'], false);
        $state = $state->withRoundPlayed($last, handDrawnFirstWins($last->getEvents()));

        // Then: Its two ties are two ties of a round before the last, as in
        // a round drawn by hand anywhere else, and the last round follows
        expect($engine->isComplete($state))->toBeFalse()
            ->and(handDrawnShape($engine->pairNextRound($state)))->toBe('p1 v p2, p4 v p3');
    });
});
