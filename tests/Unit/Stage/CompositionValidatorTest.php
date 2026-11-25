<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Scheduling\DoubleEliminationEngine;
use MissionGaming\Tactician\Scheduling\SingleEliminationEngine;
use MissionGaming\Tactician\Stage\CompositionValidator;
use MissionGaming\Tactician\Stage\MatchOutcomeSelector;
use MissionGaming\Tactician\Stage\RankRangeSelector;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Stage\StageTransition;

describe('CompositionValidator', function (): void {
    it('accepts a knockout chain that telescopes correctly', function (): void {
        $violations = new CompositionValidator()->validateChain(16, [
            new StageTransition('quarterfinals', 8, MatchOutcomeSelector::winners()),
            new StageTransition('semifinals', 4, MatchOutcomeSelector::winners()),
            new StageTransition('final', 2, MatchOutcomeSelector::winners()),
        ]);

        expect($violations)->toBe([]);
    });

    it('reports a chain that does not telescope', function (): void {
        $violations = new CompositionValidator()->validateChain(16, [
            new StageTransition('semifinals', 4, MatchOutcomeSelector::winners()),
        ]);

        expect($violations)->toHaveCount(1);
        expect($violations[0])->toContain("'semifinals' expects 4 entrants but its selection yields 8");
    });

    it('derives winners and losers yields from the source size, byes included', function (): void {
        $validator = new CompositionValidator();

        // 9 entrants: the bracket has 16 slots, so 1 tie and 7 byes -> 8
        // winners forward, 1 loser to a repechage
        expect($validator->validateChain(9, [
            new StageTransition('winners route', 8, MatchOutcomeSelector::winners()),
        ]))->toBe([]);

        expect($validator->validateChain(9, [
            new StageTransition('repechage', 2, MatchOutcomeSelector::losers()),
        ]))->toBe(["Stage 'repechage' expects 2 entrants but its selection yields 1 from the previous stage's 9."]);
    });

    // Issue #146: the validator counted half the field, rounded up for the
    // winners and down for the losers, where the engines fill the field with
    // byes up to the next power of two. 5 entrants play 1 tie and have 3
    // byes, so 4 go on, and a next stage declared for 3 passed unchecked.
    it('counts one knockout round as the elimination engines pair it, byes up to the next power of two', function (): void {
        $validator = new CompositionValidator();

        expect($validator->validateChain(5, [new StageTransition('next', 3, MatchOutcomeSelector::winners())]))
            ->toBe(["Stage 'next' expects 3 entrants but its selection yields 4 from the previous stage's 5."])
            ->and($validator->validateChain(5, [new StageTransition('next', 4, MatchOutcomeSelector::winners())]))->toBe([])
            ->and($validator->validateChain(12, [new StageTransition('repechage', 4, MatchOutcomeSelector::losers())]))->toBe([])
            ->and($validator->validateChain(12, [new StageTransition('next', 8, MatchOutcomeSelector::winners())]))->toBe([]);

        // Each count is what the first round of either engine sends on and
        // puts out, worked out from its pairing: a loser for each tie, and a
        // winner for each tie and each bye
        foreach (range(2, 40) as $size) {
            $entrants = [];
            for ($i = 1; $i <= $size; ++$i) {
                $entrants[] = new Participant("p{$i}", "Participant {$i}");
            }
            foreach ([new SingleEliminationEngine(), new DoubleEliminationEngine()] as $engine) {
                $round = $engine->pairNextRound(StageState::start($entrants));
                $ties = count($round->getEvents());
                $byes = count($round->getByes());

                expect(MatchOutcomeSelector::winners()->getYield()->countFrom($size))->toBe($ties + $byes, "{$size} entrants")
                    ->and(MatchOutcomeSelector::losers()->getYield()->countFrom($size))->toBe($ties, "{$size} entrants");
            }
        }
    });

    it('checks fixed-cardinality selectors against declared entrants', function (): void {
        $validator = new CompositionValidator();

        expect($validator->validateChain(20, [
            new StageTransition('knockout', 8, RankRangeSelector::overall(1, 8)),
        ]))->toBe([]);

        $violations = $validator->validateChain(20, [
            new StageTransition('knockout', 16, RankRangeSelector::overall(1, 8)),
        ]);
        expect($violations)->toHaveCount(1);
        expect($violations[0])->toContain('yields 8');
    });

    it('trusts declared counts when the selection size is unknowable', function (): void {
        $validator = new CompositionValidator();

        // Per-group yields depend on the outcome's pool count; consumer-declared
        // transitions carry no selector at all - both validate on declarations
        expect($validator->validateChain(16, [
            new StageTransition('knockout', 8, RankRangeSelector::topPerGroup(2)),
            new StageTransition('final four', 4, MatchOutcomeSelector::winners()),
        ]))->toBe([]);

        expect($validator->validateChain(16, [
            new StageTransition('consumer-derived stage', 6),
            new StageTransition('next', 4, MatchOutcomeSelector::winners()),
        ]))->toBe([]);
    });

    it('rejects stages too small to play', function (): void {
        $validator = new CompositionValidator();

        expect($validator->validateChain(1, []))->toHaveCount(1);

        $violations = $validator->validateChain(4, [
            new StageTransition('degenerate', 1, MatchOutcomeSelector::winners()),
        ]);
        expect($violations)->toHaveCount(2); // too small AND yields 2, not 1
    });

    // A negative opening count was reported, and then counted: the winners
    // of a stage of -3 entrants came out at -3 and gave a second violation
    // built on the first.
    it('reports a count of entrants once, and computes no yield from it', function (): void {
        $validator = new CompositionValidator();

        expect($validator->validateChain(-3, [
            new StageTransition('final', 2, MatchOutcomeSelector::winners()),
        ]))->toBe(['The opening stage needs at least 2 entrants, -3 declared.'])
            ->and($validator->validateChain(1, [
                new StageTransition('final', 2, MatchOutcomeSelector::losers()),
            ]))->toBe(['The opening stage needs at least 2 entrants, 1 declared.'])
            ->and($validator->validateChain(8, [
                new StageTransition('degenerate', -1, RankRangeSelector::overall(1, 4)),
                new StageTransition('final', 2, MatchOutcomeSelector::winners()),
            ]))->toBe([
                "Stage 'degenerate' needs at least 2 entrants, -1 declared.",
                "Stage 'degenerate' expects -1 entrants but its selection yields 4 from the previous stage's 8.",
            ]);
    });

    // The validator runs nothing, so it cannot see that a stage plays a whole
    // bracket. It counted every match-outcome selection as the hand-off after
    // one round, and had no way to be told otherwise: 16 entrants, the
    // selector returns the 1 winner of the final, the validator computed 8.
    it('counts a match-outcome selection after a whole bracket as the one participant the selector returns', function (
        MatchOutcomeSelector $selector
    ): void {
        $entrants = [];
        for ($i = 1; $i <= 16; ++$i) {
            $entrants[] = new Participant("p{$i}", "Participant {$i}");
        }

        // Given: A whole bracket of 16, played to its final
        $engine = new SingleEliminationEngine();
        $state = StageState::start($entrants);
        while (!$engine->isComplete($state)) {
            $pairing = $engine->pairNextRound($state);
            $state = $state->withRoundPlayed($pairing, array_map(
                fn(Event $event): Result => new Result($event, $event->getParticipants()[0]),
                $pairing->getEvents()
            ));
        }
        $outcome = $engine->getOutcome($state);
        assert($outcome !== null);
        $selected = count($selector->select($outcome));

        // Then: The validator counts what the selector returns
        $validator = new CompositionValidator();
        expect($selected)->toBe(1)
            ->and($validator->validateChain(16, [
                new StageTransition('next', 8, $selector, afterWholeBracket: true),
            ]))->toBe(["Stage 'next' expects 8 entrants but its selection yields 1 from the previous stage's 16."]);
    })->with([
        'winners' => [MatchOutcomeSelector::winners()],
        'losers' => [MatchOutcomeSelector::losers()],
    ]);

    it('counts one knockout round where a transition does not say otherwise', function (): void {
        $transition = new StageTransition('quarterfinals', 8, MatchOutcomeSelector::winners());

        expect($transition->afterWholeBracket)->toBeFalse()
            ->and(new CompositionValidator()->validateChain(16, [$transition]))->toBe([]);
    });

    it('reads a whole bracket only for a match-outcome selection', function (): void {
        $validator = new CompositionValidator();

        // A selector that states its size is taken at its word, and a
        // transition with no selector is not counted at all
        expect($validator->validateChain(16, [
            new StageTransition('knockout', 8, RankRangeSelector::overall(1, 8), afterWholeBracket: true),
        ]))->toBe([])
            ->and($validator->validateChain(16, [
                new StageTransition('consumer-derived stage', 6, null, afterWholeBracket: true),
            ]))->toBe([]);
    });
});
