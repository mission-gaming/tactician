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
use MissionGaming\Tactician\Stage\Bracket;
use MissionGaming\Tactician\Stage\BracketNodeStatus;
use MissionGaming\Tactician\Stage\BracketTie;
use MissionGaming\Tactician\Stage\MatchOutcomeSelector;
use MissionGaming\Tactician\Stage\RecordedRound;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Stage\SlotSource;
use MissionGaming\Tactician\Stage\StageOutcome;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Stage\Withdrawal;

// A round drawn by hand is read as drawn
// (docs/adr/0025-a-round-drawn-by-hand-is-read-as-drawn.md).

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

/**
 * The slots of a view that name a node they are filled from and hold
 * somebody that node did not send there, as text: none in a view that names
 * its sources right.
 *
 * @return list<string>
 */
function handDrawnMisnamedSources(Bracket $bracket): array
{
    $byId = [];
    foreach ($bracket->getRounds() as $round) {
        foreach ($round->getNodes() as $node) {
            $byId[$node->getId()] = $node;
        }
    }

    $misnamed = [];
    foreach ($bracket->getRounds() as $round) {
        foreach ($round->getNodes() as $node) {
            foreach ($node->getSlots() as $slot) {
                $participant = $slot->getParticipant();
                $sources = $slot->getSourceNodes();
                if (!$participant instanceof Participant || count($sources) !== 1 || !isset($byId[$sources[0]])) {
                    continue;
                }
                $source = $byId[$sources[0]];
                $sent = match ($slot->getSource()) {
                    SlotSource::AdvancerOf => $source->getAdvancer()?->getId() === $participant->getId(),
                    SlotSource::LoserOf => in_array($participant->getId(), array_map(static fn(Participant $loser): string => $loser->getId(), $source->getLosers()), true),
                    default => true,
                };
                if (!$sent) {
                    $misnamed[] = "{$participant->getId()} in {$node->getId()} from {$slot->getSource()->value} {$sources[0]}";
                }
            }
        }
    }

    return $misnamed;
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
        expect($sources)->toBe(['main:2:2', 'main:2:3', 'main:2:4', 'main:2:5'])
            ->and(handDrawnMisnamedSources($bracket))->toBe([]);
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
        $losersRoundTwo = null;
        while (!str_starts_with((string) $next->getLabel(), 'winners final')) {
            if ($next->getLabel() === 'losers round 2') {
                $losersRoundTwo = $next;
            }
            $state = $state->withRoundPlayed($next, handDrawnFirstWins($next->getEvents()));
            $next = $engine->pairNextRound($state);
        }
        expect(handDrawnShape($next))->toBe('p4 v p3');

        // And the losers round after it takes the one entrant the drawn
        // round's results beat, p2, and nobody for p1 or for p3's bye
        // (docs/adr/0026): p8 stands alone
        assert($losersRoundTwo instanceof RoundPairing);
        expect(handDrawnShape($losersRoundTwo))->toBe('p2 v p7; byes p8');

        // And p1, which holds no place, drops nobody and goes out as the two
        // of a void winners tie do: with the entrant the next losers round
        // puts out (p7, beaten by p2 in losers round 2), above the two who
        // lost twice in losers round 1, as when the engine pairs the round
        // and gives p1's tie away
        while (!$engine->isComplete($state)) {
            $state = $state->withRoundPlayed($next, handDrawnFirstWins($next->getEvents()));
            if (!$engine->isComplete($state)) {
                $next = $engine->pairNextRound($state);
            }
        }
        $tiers = handDrawnTiers($engine, $state);

        // And the view names the node each entrant came from: the node
        // that holds no place is not counted among the places of its round
        // (it named the node before the one a slot was filled from)
        expect(handDrawnMisnamedSources($engine->getBracket($state)))->toBe([])
            ->and($tiers['p1'])->toBe($tiers['p7'])
            ->and($tiers['p1'])->toBeLessThan($tiers['p5'])
            ->and($tiers['p5'])->toBe($tiers['p6']);
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

    it('reads a tie marked as the final between two who are not in the round as no tie of the bracket', function (): void {
        // Given: Eight entrants with a third-place tie, the first-named
        // winning: p1 and p2 reach the final, p4 and p3 the third-place tie.
        // The last round is recorded with a tie marked as the final between
        // p8 and p5, who went out in the quarterfinals
        $field = handDrawnField(8);
        $engine = new SingleEliminationEngine(new EliminationOptions(thirdPlace: true));
        $state = StageState::start(array_values($field));
        for ($round = 0; $round < 2; ++$round) {
            $pairing = $engine->pairNextRound($state);
            $state = $state->withRoundPlayed($pairing, handDrawnFirstWins($pairing->getEvents()));
        }
        $last = new RoundPairing(3, 'final', [
            new Event([$field['p8'], $field['p5']], new Round(3), [BracketTie::METADATA_KEY => BracketTie::Final->value]),
            new Event([$field['p4'], $field['p3']], new Round(3), [BracketTie::METADATA_KEY => BracketTie::ThirdPlace->value]),
        ]);
        $state = $state->withRoundPlayed($last, handDrawnFirstWins($last->getEvents()));

        // Then: The two who went out stay out, level with the other
        // quarterfinal losers, and the final is still to be paired between
        // the two who are in it
        $tiers = handDrawnTiers($engine, $state);
        expect($engine->isComplete($state))->toBeFalse()
            ->and(handDrawnShape($engine->pairNextRound($state)))->toBe('p1 v p2')
            ->and([$tiers['p8'], $tiers['p5']])->toBe([$tiers['p6'], $tiers['p7']])
            ->and($tiers['p8'])->toBeGreaterThan($tiers['p3']);
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

describe('a round drawn by hand with an entrant who has gone out (issue #230)', function (): void {
    /**
     * Eight entrants in single elimination, round 1 played as paired with
     * the first-named winning: p1, p4, p2 and p3 go on, p8, p5, p7 and p6
     * go out.
     *
     * @return array{SingleEliminationEngine, StageState, array<string, Participant>}
     */
    $afterTheFirstRound = static function (EliminationOptions $options = new EliminationOptions()): array {
        $field = handDrawnField(8);
        $engine = new SingleEliminationEngine($options);
        $state = StageState::start(array_values($field));
        $first = $engine->pairNextRound($state);
        expect(handDrawnShape($first))->toBe('p1 v p8, p4 v p5, p2 v p7, p3 v p6');

        return [$engine, $state->withRoundPlayed($first, handDrawnFirstWins($first->getEvents())), $field];
    };

    it('gives the tie to the entrant who is in the round, and keeps the one who went out out', function (string $firstNamed, string $secondNamed) use ($afterTheFirstRound): void {
        [$engine, $state, $field] = $afterTheFirstRound();

        // Given: Round 2 drawn by hand as p8 v p1 and p2 v p3: p8 went out
        // in round 1, and p4 is in no tie. The first-named wins, whichever
        // that is
        $second = new RoundPairing(2, 'semifinal', [
            new Event([$field[$firstNamed], $field[$secondNamed]], new Round(2)),
            new Event([$field['p2'], $field['p3']], new Round(2)),
        ]);
        $state = $state->withRoundPlayed($second, handDrawnFirstWins($second->getEvents()));

        // Then: p1 goes on, and so does p4, alone in its slots; p8 is paired
        // no more and stays level with the others who went out in round 1.
        // Before, p8 went on when it won, to 'p8 v p4; byes p2'
        $tiers = handDrawnTiers($engine, $state);
        expect(handDrawnShape($engine->pairNextRound($state)))->toBe('p1 v p4; byes p2')
            ->and($engine->getRecordedRounds($state)[1]->isManual())->toBeTrue()
            ->and([$tiers['p8'], $tiers['p5'], $tiers['p6']])->toBe([$tiers['p7'], $tiers['p7'], $tiers['p7']])
            ->and($tiers['p1'])->toBe(0);

        // And the result of the tie is a result all the same: the table
        // counts it (p1's win of round 1, and the win of round 2)
        $wins = [];
        foreach ($engine->getStandings($state)->getEntries() as $entry) {
            $wins[$entry->getParticipant()->getId()] = $entry->getWins();
        }
        expect($wins['p1'] + $wins['p8'])->toBe(2)
            ->and($wins[$firstNamed])->toBeGreaterThanOrEqual(1);

        // And the view shows the tie given to p1, who alone is in it
        $node = $engine->getBracket($state)->getRounds()[1]->getNodes()[0];
        expect($node->getStatus())->toBe(BracketNodeStatus::Uncontested)
            ->and(array_map(static fn(Participant $participant): string => $participant->getId(), $node->getParticipants()))->toBe(['p1'])
            ->and($node->getAdvancer()?->getId())->toBe('p1')
            ->and($node->getLosers())->toBe([])
            ->and($node->getEvents())->toHaveCount(1);
    })->with([
        'the one who went out wins' => ['p8', 'p1'],
        'the one in the round wins' => ['p1', 'p8'],
    ]);

    it('reads the tie of one who went out in the place of an entrant who had withdrawn as any tie', function () use ($afterTheFirstRound): void {
        [$engine, $state, $field] = $afterTheFirstRound();

        // Given: p4 withdraws after round 1, and round 2 is drawn by hand
        // with p5, whom p4 beat, in its place against p1: a lucky loser
        $state = $state->withoutParticipant($field['p4']);
        $second = new RoundPairing(2, 'semifinal', [
            new Event([$field['p5'], $field['p1']], new Round(2)),
            new Event([$field['p2'], $field['p3']], new Round(2)),
        ]);
        $state = $state->withRoundPlayed($second, handDrawnFirstWins($second->getEvents()));

        // Then: p5 beat p1 and plays the final
        expect(handDrawnShape($engine->pairNextRound($state)))->toBe('p5 v p2')
            ->and($engine->getRecordedRounds($state)[1]->isManual())->toBeTrue();
    });

    // The lucky loser is read from recorded facts alone: the same rounds,
    // results and withdrawal supplied in another order read the same.
    it('reads the lucky loser the same from a history supplied in another order', function () use ($afterTheFirstRound): void {
        [$engine, $state, $field] = $afterTheFirstRound();
        $state = $state->withoutParticipant($field['p4']);
        $second = new RoundPairing(2, 'semifinal', [
            new Event([$field['p5'], $field['p1']], new Round(2)),
            new Event([$field['p2'], $field['p3']], new Round(2)),
        ]);
        $state = $state->withRoundPlayed($second, handDrawnFirstWins($second->getEvents()));

        $fromRows = StageState::fromHistory(
            array_values($field),
            array_reverse($state->getRoundsPlayed()),
            array_reverse($state->getResults()),
            [new Withdrawal($field['p4'], 1)]
        );

        expect(handDrawnShape($engine->pairNextRound($fromRows)))->toBe('p5 v p2')
            ->and(handDrawnTiers($engine, $fromRows))->toBe(handDrawnTiers($engine, $state))
            ->and($engine->getBracket($fromRows))->toEqual($engine->getBracket($state));
    });

    // The exception is for an entrant who had withdrawn when the round was
    // paired. Where the entrant left only after the round, it was still in
    // the bracket when the round was drawn, so the one who went out took
    // nobody's place: the tie is given to the entrant of the round.
    it('reads no lucky loser in the place of an entrant who withdrew only after the round', function () use ($afterTheFirstRound): void {
        [$engine, $state, $field] = $afterTheFirstRound();
        $second = new RoundPairing(2, 'semifinal', [
            new Event([$field['p5'], $field['p1']], new Round(2)),
            new Event([$field['p2'], $field['p3']], new Round(2)),
        ]);
        $state = StageState::fromHistory(
            array_values($field),
            [...$state->getRoundsPlayed(), $second],
            [...$state->getResults(), ...handDrawnFirstWins($second->getEvents())],
            [new Withdrawal($field['p4'], 2)]
        );

        // Then: p5 is paired no more and stays level with the others who
        // went out in round 1; the tie with p1 is given to p1
        $tiers = handDrawnTiers($engine, $state);
        $next = $engine->pairNextRound($state);
        $named = $next->getByes();
        foreach ($next->getEvents() as $event) {
            $named = [...$named, ...$event->getParticipants()];
        }
        $node = $engine->getBracket($state)->getRounds()[1]->getNodes()[0];
        expect(array_map(static fn(Participant $participant): string => $participant->getId(), $named))->not->toContain('p5')
            ->and([$tiers['p5'], $tiers['p6'], $tiers['p8']])->toBe([$tiers['p7'], $tiers['p7'], $tiers['p7']])
            ->and($node->getStatus())->toBe(BracketNodeStatus::Uncontested)
            ->and($node->getAdvancer()?->getId())->toBe('p1');
    });

    it('reads a third-place tie with one who went out earlier as given to the entrant who is in it', function () use ($afterTheFirstRound): void {
        [$engine, $state, $field] = $afterTheFirstRound(new EliminationOptions(thirdPlace: true));
        $semifinals = $engine->pairNextRound($state);
        $state = $state->withRoundPlayed($semifinals, handDrawnFirstWins($semifinals->getEvents()));
        expect(handDrawnShape($engine->pairNextRound($state)))->toBe('p1 v p2, p4 v p3');

        // Given: The third-place tie is drawn by hand as p8 v p4: p8 went
        // out in round 1, and p3 is in no tie
        $last = new RoundPairing(3, 'final', [
            new Event([$field['p1'], $field['p2']], new Round(3), [BracketTie::METADATA_KEY => BracketTie::Final->value]),
            new Event([$field['p8'], $field['p4']], new Round(3), [BracketTie::METADATA_KEY => BracketTie::ThirdPlace->value]),
        ]);
        $state = $state->withRoundPlayed($last, handDrawnFirstWins($last->getEvents()));

        // Then: The bracket is over, p4 third and p3 fourth, and p8 stays
        // with the others who went out in round 1. Before, p8 was third
        $tiers = handDrawnTiers($engine, $state);
        expect($engine->isComplete($state))->toBeTrue()
            ->and([$tiers['p1'], $tiers['p2'], $tiers['p4'], $tiers['p3']])->toBe([0, 1, 2, 3])
            ->and([$tiers['p8'], $tiers['p5'], $tiers['p6']])->toBe([$tiers['p7'], $tiers['p7'], $tiers['p7']])
            ->and($tiers['p7'])->toBe(4)
            ->and($engine->getBracket($state)->getRounds()[2]->getNodes()[1]->getStatus())->toBe(BracketNodeStatus::Uncontested);
    });

    it('reads a third-place tie between two who went out earlier as no tie of the bracket', function () use ($afterTheFirstRound): void {
        [$engine, $state, $field] = $afterTheFirstRound(new EliminationOptions(thirdPlace: true));
        $semifinals = $engine->pairNextRound($state);
        $state = $state->withRoundPlayed($semifinals, handDrawnFirstWins($semifinals->getEvents()));

        // Given: The tie marked as the third-place tie is between p8 and
        // p5, who went out in round 1
        $last = new RoundPairing(3, 'final', [
            new Event([$field['p1'], $field['p2']], new Round(3), [BracketTie::METADATA_KEY => BracketTie::Final->value]),
            new Event([$field['p8'], $field['p5']], new Round(3), [BracketTie::METADATA_KEY => BracketTie::ThirdPlace->value]),
        ]);
        $state = $state->withRoundPlayed($last, handDrawnFirstWins($last->getEvents()));

        // Then: The round holds no third-place tie: the final ends the
        // bracket, p4 and p3 share the tier below it, and p8 and p5 stay
        // with the others who went out in round 1. Before, p8 and p5 were
        // third and fourth
        $tiers = handDrawnTiers($engine, $state);
        expect($engine->isComplete($state))->toBeTrue()
            ->and([$tiers['p1'], $tiers['p2'], $tiers['p4'], $tiers['p3']])->toBe([0, 1, 2, 2])
            ->and([$tiers['p8'], $tiers['p5'], $tiers['p6'], $tiers['p7']])->toBe([3, 3, 3, 3])
            ->and($engine->getBracket($state)->getRounds()[2]->getNodes()[1]->getStatus())->toBe(BracketNodeStatus::NotPlayed);
    });

    it('gives a losers tie of double elimination with one who went out to the entrant who is in the stage', function (): void {
        // Given: Seven entrants, the first-named winning: p6 goes out in
        // round 2 (losers round 1), p4 and p3 drop in round 3, and p7
        // withdraws, which gives p4 a bye in losers round 2
        $field = handDrawnField(7);
        $engine = new DoubleEliminationEngine();
        $state = StageState::start(array_values($field));
        for ($round = 0; $round < 3; ++$round) {
            $pairing = $engine->pairNextRound($state);
            $state = $state->withRoundPlayed($pairing, handDrawnFirstWins($pairing->getEvents()));
        }
        $state = $state->withoutParticipant($field['p7']);
        $losersRound = $engine->pairNextRound($state);
        expect(handDrawnShape($losersRound))->toBe('p3 v p5; byes p4');

        // When: The round is drawn by hand with p6 against p4, and p6 wins
        $drawn = new RoundPairing(4, $losersRound->getLabel(), [
            ...array_values($losersRound->getEvents()),
            new Event([$field['p6'], $field['p4']], new Round(4)),
        ]);
        $state = $state->withRoundPlayed($drawn, handDrawnFirstWins($drawn->getEvents()));

        // Then: p4 goes on to losers round 3, and p6 is paired no more.
        // Before, p6 went on in p4's place
        expect(handDrawnShape($engine->pairNextRound($state)))->toBe('p3 v p4')
            ->and($engine->getRecordedRounds($state)[3]->isManual())->toBeTrue();
    });
});

describe('a stage of double elimination drawn by hand with an entrant of another stage (issue #236)', function (): void {
    /**
     * A double elimination bracket played with the first-named winning
     * every event, `$before` rounds as paired, then `$withdrawing`
     * withdraws, and the next stage is drawn by hand with one more event.
     *
     * @return array{DoubleEliminationEngine, StageState, RoundPairing}
     */
    $drawnIn = static function (int $fieldSize, int $before, string $withdrawing, string $shape, Event $extra, bool $fromHistory = false): array {
        $field = handDrawnField($fieldSize);
        $engine = new DoubleEliminationEngine();
        $state = StageState::start(array_values($field));
        for ($round = 0; $round < $before; ++$round) {
            $pairing = $engine->pairNextRound($state);
            $state = $state->withRoundPlayed($pairing, handDrawnFirstWins($pairing->getEvents()));
        }
        $state = $state->withoutParticipant($field[$withdrawing]);
        $paired = $engine->pairNextRound($state);
        expect(handDrawnShape($paired))->toBe($shape);

        $byes = array_values(array_filter(
            $paired->getByes(),
            static fn(Participant $bye): bool => !in_array($bye->getId(), array_map(static fn(Participant $participant): string => $participant->getId(), array_values($extra->getParticipants())), true)
        ));
        $drawn = new RoundPairing($paired->getRoundNumber(), $paired->getLabel(), [...array_values($paired->getEvents()), $extra], $byes);
        $state = $state->withRoundPlayed($drawn, handDrawnFirstWins($drawn->getEvents()));
        if ($fromHistory) {
            $state = StageState::fromHistory(
                array_values($field),
                array_reverse($state->getRoundsPlayed()),
                array_reverse($state->getResults()),
                $state->getWithdrawals()
            );
        }

        return [$engine, $state, $drawn];
    };

    it('gives a losers tie with an entrant still in the winners bracket to the entrant of the stage', function (string $firstNamed, string $secondNamed, bool $fromHistory) use ($drawnIn): void {
        // Given: Seven entrants; after three rounds p7 withdraws, which gives
        // p4 a bye in losers round 2. The stage is drawn by hand with p1,
        // who is in the winners bracket and waits for the winners final,
        // against p4, and the first-named wins
        $field = handDrawnField(7);
        [$engine, $state] = $drawnIn(7, 3, 'p7', 'p3 v p5; byes p4', new Event([$field[$firstNamed], $field[$secondNamed]], new Round(4)), $fromHistory);

        // Then: p4 goes on in the losers bracket, and p1 stays in the
        // winners bracket alone. Before, p1 went on in the losers bracket
        // too when it won, and was paired in both: 'p3 v p1', then the
        // winners final
        expect(handDrawnShape($engine->pairNextRound($state)))->toBe('p3 v p4')
            ->and($engine->getRecordedRounds($state)[3]->isManual())->toBeTrue();

        $node = $engine->getBracket($state)->getRounds()[3]->getNodes()[1];
        expect($node->getStatus())->toBe(BracketNodeStatus::Uncontested)
            ->and(array_map(static fn(Participant $participant): string => $participant->getId(), $node->getParticipants()))->toBe(['p4'])
            ->and($node->getAdvancer()?->getId())->toBe('p4');

        // And the bracket plays on with p1 once in each round it is in
        while (!$engine->isComplete($state)) {
            $pairing = $engine->pairNextRound($state);
            $state = $state->withRoundPlayed($pairing, handDrawnFirstWins($pairing->getEvents()));
        }
        $rounds = array_map(static fn(RecordedRound $round): string => handDrawnShape($round->getPairing()), $engine->getRecordedRounds($state));
        expect(array_slice($rounds, 4))->toBe(['p3 v p4', 'p1 v p2', 'p2 v p3', 'p1 v p2'])
            ->and(handDrawnTiers($engine, $state)['p1'])->toBe(0);
    })->with([
        'the entrant from the winners bracket wins' => ['p1', 'p4', false],
        'the entrant of the stage wins' => ['p4', 'p1', false],
        'read from rows in another order' => ['p1', 'p4', true],
    ]);

    it('gives a winners tie with an entrant of the losers bracket to the entrant of the stage, and drops nobody', function () use ($drawnIn): void {
        // Given: Eight entrants; after two rounds p4 withdraws, which gives
        // p1 a bye in winners round 2. The stage is drawn by hand with p8,
        // who is in the losers bracket, against p1, and p8 wins
        $field = handDrawnField(8);
        [$engine, $state] = $drawnIn(8, 2, 'p4', 'p2 v p3; byes p1', new Event([$field['p8'], $field['p1']], new Round(3)));

        // Then: p1 goes on to the winners final without a loss, nobody
        // drops from the tie, and p8 plays on in the losers bracket
        expect(handDrawnShape($engine->pairNextRound($state)))->toBe('p3 v p8; byes p7');
        while (!$engine->isComplete($state)) {
            $pairing = $engine->pairNextRound($state);
            $state = $state->withRoundPlayed($pairing, handDrawnFirstWins($pairing->getEvents()));
        }
        $rounds = array_map(static fn(RecordedRound $round): string => handDrawnShape($round->getPairing()), $engine->getRecordedRounds($state));
        expect(array_slice($rounds, 3))->toBe(['p3 v p8; byes p7', 'p3 v p7', 'p1 v p2', 'p2 v p3', 'p1 v p2']);
    });

    // Where the entrant drawn in from the losers bracket won the winners tie,
    // it held two places that met in one losers tie, and the engine paired it
    // against itself and threw (found by the sweep in
    // EliminationInvariantsTest).
    it('pairs nobody against itself when the entrant from the losers bracket wins a winners tie', function (string $firstNamed, string $secondNamed, bool $fromHistory) use ($drawnIn): void {
        // Given: Five entrants; p4 beats p5 in round 1, which drops p5 to
        // the losers bracket, and p3 withdraws, which gives p2 a bye in
        // winners round 2. The stage is drawn by hand with p5 against p2,
        // and the first-named wins
        $field = handDrawnField(5);
        [$engine, $state] = $drawnIn(5, 1, 'p3', 'p1 v p4; byes p2', new Event([$field[$firstNamed], $field[$secondNamed]], new Round(2)), $fromHistory);

        // Then: p2 goes on in the winners bracket, nobody drops from the tie,
        // and the bracket plays on to its end with p5 in the losers bracket
        // only, whoever won the tie
        $nodes = [];
        foreach ($engine->getBracket($state)->getRounds() as $bracketRound) {
            foreach ($bracketRound->getNodes() as $node) {
                if ($node->getRoundNumber() === 2) {
                    $nodes[] = $node;
                }
            }
        }
        $given = array_values(array_filter($nodes, static fn($node): bool => $node->getStatus() === BracketNodeStatus::Uncontested));
        expect($given)->toHaveCount(1)
            ->and(array_map(static fn(Participant $participant): string => $participant->getId(), $given[0]->getParticipants()))->toBe(['p2'])
            ->and($given[0]->getAdvancer()?->getId())->toBe('p2')
            ->and($given[0]->getLosers())->toBe([]);
        while (!$engine->isComplete($state)) {
            $pairing = $engine->pairNextRound($state);
            $state = $state->withRoundPlayed($pairing, handDrawnFirstWins($pairing->getEvents()));
        }
        $rounds = array_map(static fn(RecordedRound $round): string => handDrawnShape($round->getPairing()), $engine->getRecordedRounds($state));
        expect(array_slice($rounds, 2))->toBe(['p5 v p4', 'p1 v p2', 'p2 v p5', 'p1 v p2']);
    })->with([
        'the entrant from the losers bracket wins' => ['p5', 'p2', false],
        'the entrant of the stage wins' => ['p2', 'p5', false],
        'read from rows in another order' => ['p5', 'p2', true],
    ]);
});
