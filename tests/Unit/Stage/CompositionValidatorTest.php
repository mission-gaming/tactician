<?php

declare(strict_types=1);

use MissionGaming\Tactician\Stage\CompositionValidator;
use MissionGaming\Tactician\Stage\MatchOutcomeSelector;
use MissionGaming\Tactician\Stage\RankRangeSelector;
use MissionGaming\Tactician\Stage\StageTransition;

describe('CompositionValidator', function (): void {
    it('accepts a knockout chain that telescopes correctly', function (): void {
        $violations = (new CompositionValidator())->validateChain(16, [
            new StageTransition('quarterfinals', 8, MatchOutcomeSelector::winners()),
            new StageTransition('semifinals', 4, MatchOutcomeSelector::winners()),
            new StageTransition('final', 2, MatchOutcomeSelector::winners()),
        ]);

        expect($violations)->toBe([]);
    });

    it('reports a chain that does not telescope', function (): void {
        $violations = (new CompositionValidator())->validateChain(16, [
            new StageTransition('semifinals', 4, MatchOutcomeSelector::winners()),
        ]);

        expect($violations)->toHaveCount(1);
        expect($violations[0])->toContain("'semifinals' expects 4 entrants but its selection yields 8");
    });

    it('derives winners and losers yields from the source size, byes included', function (): void {
        $validator = new CompositionValidator();

        // 9 entrants: 4 ties + 1 bye -> 5 winners forward, 4 losers to a repechage
        expect($validator->validateChain(9, [
            new StageTransition('winners route', 5, MatchOutcomeSelector::winners()),
        ]))->toBe([]);

        expect($validator->validateChain(9, [
            new StageTransition('repechage', 4, MatchOutcomeSelector::losers()),
        ]))->toBe([]);
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
            new StageTransition('next', 3, MatchOutcomeSelector::winners()),
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
});
