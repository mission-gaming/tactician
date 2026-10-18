<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\InvalidInputException;
use MissionGaming\Tactician\Stage\BestOfRankSelector;
use MissionGaming\Tactician\Stage\CompositionValidator;
use MissionGaming\Tactician\Stage\MatchOutcomeSelector;
use MissionGaming\Tactician\Stage\ProgressionSelector;
use MissionGaming\Tactician\Stage\RankRangeSelector;
use MissionGaming\Tactician\Stage\SelectionYield;
use MissionGaming\Tactician\Stage\SelectionYieldKind;
use MissionGaming\Tactician\Stage\StageOutcome;
use MissionGaming\Tactician\Stage\StageTransition;

/**
 * A selector of an application's that states its yield and is not one of
 * the library's: the validator must count it by what it declares, as it
 * counts the library's own.
 */
final readonly class DeclaredYieldSelector implements ProgressionSelector
{
    public function __construct(private SelectionYield $yield) {}

    /**
     * @return array<Participant>
     */
    #[Override]
    public function select(StageOutcome $outcome): array
    {
        return [];
    }

    #[Override]
    public function getYield(): SelectionYield
    {
        return $this->yield;
    }

    #[Override]
    public function getSelectionSize(): ?int
    {
        return $this->yield->getKind() === SelectionYieldKind::Fixed ? $this->yield->getCount() : null;
    }
}

describe('SelectionYield', function (): void {
    it('counts each kind from the shape of the stage before', function (
        SelectionYield $yield,
        int $entrants,
        ?int $pools,
        bool $afterWholeBracket,
        ?int $expected
    ): void {
        expect($yield->countFrom($entrants, $pools, $afterWholeBracket))->toBe($expected);
    })->with([
        'fixed, whatever the shape' => [SelectionYield::fixed(6), 20, 4, true, 6],
        'per pool, with the pools' => [SelectionYield::perPool(2), 16, 4, false, 8],
        'per pool, without the pools' => [SelectionYield::perPool(2), 16, null, false, null],
        'winners of 9, one round: the bye goes on' => [SelectionYield::winnersOfLastRound(), 9, null, false, 5],
        'losers of 9, one round' => [SelectionYield::losersOfLastRound(), 9, null, false, 4],
        'winners after a whole bracket' => [SelectionYield::winnersOfLastRound(), 16, null, true, 1],
        'losers after a whole bracket' => [SelectionYield::losersOfLastRound(), 16, null, true, 1],
        'depends on the outcome' => [SelectionYield::dependsOnOutcome(), 16, 4, false, null],
    ]);

    it('states its kind and count', function (): void {
        expect(SelectionYield::fixed(3)->getKind())->toBe(SelectionYieldKind::Fixed)
            ->and(SelectionYield::fixed(3)->getCount())->toBe(3)
            ->and(SelectionYield::perPool(2)->getKind())->toBe(SelectionYieldKind::PerPool)
            ->and(SelectionYield::perPool(2)->getCount())->toBe(2)
            ->and(SelectionYield::winnersOfLastRound()->getCount())->toBeNull()
            ->and(SelectionYield::losersOfLastRound()->getKind())->toBe(SelectionYieldKind::LosersOfLastRound)
            ->and(SelectionYield::dependsOnOutcome()->getKind())->toBe(SelectionYieldKind::DependsOnOutcome);
    });

    it('refuses a negative count', function (Closure $build): void {
        expect($build)->toThrow(InvalidInputException::class, 'A selection yields 0 participants or more, -1 given');
    })->with([
        'fixed' => [fn() => SelectionYield::fixed(-1)],
        'per pool' => [fn() => SelectionYield::perPool(-1)],
    ]);

    // A stage is played in at least one pool. Counted with fewer, a per-pool
    // yield came out at 0 or below 0: a number no selection can be.
    it('refuses a stage before of fewer than 1 pool, whatever the kind', function (SelectionYield $yield, int $pools): void {
        expect(fn() => $yield->countFrom(16, $pools))
            ->toThrow(InvalidInputException::class, "A stage is played in at least 1 pool, {$pools} given");
    })->with([
        'per pool, none' => [SelectionYield::perPool(2), 0],
        'per pool, below none' => [SelectionYield::perPool(2), -1],
        'fixed, none' => [SelectionYield::fixed(4), 0],
        'winners, below none' => [SelectionYield::winnersOfLastRound(), -3],
    ]);

    it('counts a stage before of 1 pool', function (): void {
        expect(SelectionYield::perPool(2)->countFrom(8, 1))->toBe(2);
    });
});

describe('The yield every library selector declares', function (): void {
    it('is the statement of its mode and range', function (ProgressionSelector $selector, SelectionYieldKind $kind, ?int $count): void {
        $yield = $selector->getYield();

        expect($yield->getKind())->toBe($kind)
            ->and($yield->getCount())->toBe($count);
    })->with([
        'overall rank range' => [RankRangeSelector::overall(3, 8), SelectionYieldKind::Fixed, 6],
        'per-group rank range' => [RankRangeSelector::perGroup(2, 3), SelectionYieldKind::PerPool, 2],
        'top per group' => [RankRangeSelector::topPerGroup(2), SelectionYieldKind::PerPool, 2],
        'best of a rank' => [BestOfRankSelector::ofRank(3, 2), SelectionYieldKind::Fixed, 2],
        'match outcome, winners' => [MatchOutcomeSelector::winners(), SelectionYieldKind::WinnersOfLastRound, null],
        'match outcome, losers' => [MatchOutcomeSelector::losers(), SelectionYieldKind::LosersOfLastRound, null],
    ]);

    it('agrees with the selection size, which is its fixed count and nothing else', function (ProgressionSelector $selector): void {
        $yield = $selector->getYield();

        expect($selector->getSelectionSize())
            ->toBe($yield->getKind() === SelectionYieldKind::Fixed ? $yield->getCount() : null);
    })->with([
        'overall rank range' => [RankRangeSelector::overall(1, 8)],
        'per-group rank range' => [RankRangeSelector::topPerGroup(2)],
        'best of a rank' => [BestOfRankSelector::ofRank(3, 2)],
        'match outcome, winners' => [MatchOutcomeSelector::winners()],
        'match outcome, losers' => [MatchOutcomeSelector::losers()],
    ]);
});

describe('CompositionValidator reads the declared yield', function (): void {
    // Before the yield was declared, a per-group selection always passed:
    // its count depends on the pools, which the validator was never told.
    it('checks a per-group selection against the pools the transition declares', function (): void {
        $validator = new CompositionValidator();

        expect($validator->validateChain(16, [
            new StageTransition('knockout', 8, RankRangeSelector::topPerGroup(2), sourcePools: 4),
        ]))->toBe([])
            ->and($validator->validateChain(16, [
                new StageTransition('knockout', 8, RankRangeSelector::topPerGroup(2), sourcePools: 3),
            ]))->toBe(["Stage 'knockout' expects 8 entrants but its selection yields 6 from the previous stage's 16."])
            ->and($validator->validateChain(16, [
                new StageTransition('knockout', 8, RankRangeSelector::topPerGroup(2)),
            ]))->toBe([]);
    });

    it('reports a declared pool count below 1 and does not count with it', function (): void {
        expect((new CompositionValidator())->validateChain(16, [
            new StageTransition('knockout', 8, RankRangeSelector::topPerGroup(2), sourcePools: 0),
        ]))->toBe(["Stage 'knockout' declares 0 pools for the previous stage; a stage is played in at least 1."]);
    });

    // The validator used to recognise the match-outcome selector by its
    // class and read its mode from its plain data; a selector of an
    // application's was counted only when it had a fixed size.
    it('counts a selector of an application by the yield it declares', function (
        SelectionYield $yield,
        int $expected
    ): void {
        $selector = new DeclaredYieldSelector($yield);
        $validator = new CompositionValidator();

        expect($validator->validateChain(9, [new StageTransition('next', $expected, $selector, sourcePools: 3)]))->toBe([])
            ->and($validator->validateChain(9, [new StageTransition('next', $expected + 1, $selector, sourcePools: 3)]))
            ->toBe([sprintf(
                "Stage 'next' expects %d entrants but its selection yields %d from the previous stage's 9.",
                $expected + 1,
                $expected
            )]);
    })->with([
        'fixed' => [SelectionYield::fixed(4), 4],
        'per pool' => [SelectionYield::perPool(2), 6],
        'winners of the last round' => [SelectionYield::winnersOfLastRound(), 5],
        'losers of the last round' => [SelectionYield::losersOfLastRound(), 4],
    ]);

    it('passes a selector of an application whose yield depends on the outcome', function (): void {
        expect((new CompositionValidator())->validateChain(9, [
            new StageTransition('next', 7, new DeclaredYieldSelector(SelectionYield::dependsOnOutcome()), sourcePools: 3),
        ]))->toBe([]);
    });
});
