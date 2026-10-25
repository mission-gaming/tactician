<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Exceptions\InvalidInputException;
use MissionGaming\Tactician\Scheduling\SingleEliminationEngine;
use MissionGaming\Tactician\Stage\BestOfRankSelector;
use MissionGaming\Tactician\Stage\BracketQualifiersSelector;
use MissionGaming\Tactician\Stage\CompositionValidator;
use MissionGaming\Tactician\Stage\MatchOutcomeSelector;
use MissionGaming\Tactician\Stage\ProgressionSelector;
use MissionGaming\Tactician\Stage\RankRangeSelector;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Stage\SelectionYield;
use MissionGaming\Tactician\Stage\SelectionYieldKind;
use MissionGaming\Tactician\Stage\StageOutcome;
use MissionGaming\Tactician\Stage\StageState;
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
        'per pool plus a count across them, with the pools' => [SelectionYield::perPoolPlus(2, 2, 2), 12, 3, false, 8],
        'per pool plus a count across them, without the pools' => [SelectionYield::perPoolPlus(2, 2, 2), 12, null, false, null],
        'per pool plus a count across them, from the fewest pools it is made from' => [SelectionYield::perPoolPlus(1, 3, 3), 9, 3, false, 6],
        'per pool plus a count across them, from fewer pools than it is made from' => [SelectionYield::perPoolPlus(2, 1, 2), 4, 1, false, null],
    ]);

    it('states the count across the pools and the fewest pools for a count per pool plus a count', function (): void {
        $yield = SelectionYield::perPoolPlus(2, 3, 4);

        expect($yield->getKind())->toBe(SelectionYieldKind::PerPoolPlusFixed)
            ->and($yield->getCount())->toBe(2)
            ->and($yield->getAddedCount())->toBe(3)
            ->and($yield->getMinimumPools())->toBe(4)
            ->and(SelectionYield::perPoolPlus(2, 3)->getMinimumPools())->toBe(1);
    });

    // Every other kind states no count across the pools, and can be made
    // from a stage of a single pool
    it('states no count across the pools and a minimum of 1 pool for every other kind', function (SelectionYield $yield): void {
        expect($yield->getAddedCount())->toBeNull()
            ->and($yield->getMinimumPools())->toBe(1);
    })->with([
        'fixed' => [SelectionYield::fixed(3)],
        'per pool' => [SelectionYield::perPool(2)],
        'winners' => [SelectionYield::winnersOfLastRound()],
        'losers' => [SelectionYield::losersOfLastRound()],
        'depends on the outcome' => [SelectionYield::dependsOnOutcome()],
    ]);

    it('refuses a minimum below 1 pool', function (): void {
        expect(fn() => SelectionYield::perPoolPlus(2, 2, 0))
            ->toThrow(InvalidInputException::class, 'A selection is made from at least 1 pool, 0 given');
    });

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
        'per pool plus, the count per pool' => [fn() => SelectionYield::perPoolPlus(-1, 2)],
        'per pool plus, the count across the pools' => [fn() => SelectionYield::perPoolPlus(2, -1)],
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
        'qualifiers placed for a bracket' => [
            BracketQualifiersSelector::of(RankRangeSelector::perGroup(1, 3), BestOfRankSelector::ofRank(4, 2)),
            SelectionYieldKind::PerPoolPlusFixed,
            3,
        ],
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
        'qualifiers placed for a bracket' => [BracketQualifiersSelector::of(RankRangeSelector::topPerGroup(2), BestOfRankSelector::ofRank(3, 2))],
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
        'per pool plus a count across them' => [SelectionYield::perPoolPlus(2, 1, 3), 7],
        'winners of the last round' => [SelectionYield::winnersOfLastRound(), 5],
        'losers of the last round' => [SelectionYield::losersOfLastRound(), 4],
    ]);

    // The top two of every pool and the best of those placed third come
    // from at least 2 pools, and from at least as many as the best third-placed:
    // with fewer the selection is refused, and its count is not checked
    it('reports fewer pools than the selection is made from, and does not count with them', function (): void {
        $qualifiers = BracketQualifiersSelector::of(RankRangeSelector::topPerGroup(2), BestOfRankSelector::ofRank(3, 3));
        $validator = new CompositionValidator();

        expect($validator->validateChain(4, [new StageTransition('knockout', 5, $qualifiers, sourcePools: 1)]))
            ->toBe(["Stage 'knockout' declares 1 pool for the previous stage; its selection is made from at least 3."])
            ->and($validator->validateChain(8, [new StageTransition('knockout', 7, $qualifiers, sourcePools: 2)]))
            ->toBe(["Stage 'knockout' declares 2 pools for the previous stage; its selection is made from at least 3."])
            ->and($validator->validateChain(12, [new StageTransition('knockout', 9, $qualifiers, sourcePools: 3)]))->toBe([])
            ->and($validator->validateChain(12, [new StageTransition('knockout', 8, $qualifiers, sourcePools: 3)]))
            ->toBe(["Stage 'knockout' expects 8 entrants but its selection yields 9 from the previous stage's 12."])
            ->and($validator->validateChain(12, [new StageTransition('knockout', 8, $qualifiers)]))->toBe([]);
    });

    it('reports one pool for the best of a single rank placed for a bracket', function (): void {
        $qualifiers = BracketQualifiersSelector::of(RankRangeSelector::topPerGroup(2), BestOfRankSelector::ofRank(3, 1));

        expect((new CompositionValidator())->validateChain(4, [new StageTransition('knockout', 3, $qualifiers, sourcePools: 1)]))
            ->toBe(["Stage 'knockout' declares 1 pool for the previous stage; its selection is made from at least 2."])
            ->and((new CompositionValidator())->validateChain(8, [new StageTransition('knockout', 5, $qualifiers, sourcePools: 2)]))
            ->toBe([]);
    });

    it('passes a selector of an application whose yield depends on the outcome', function (): void {
        expect((new CompositionValidator())->validateChain(9, [
            new StageTransition('next', 7, new DeclaredYieldSelector(SelectionYield::dependsOnOutcome()), sourcePools: 3),
        ]))->toBe([]);
    });
});

describe('A withdrawal and the declared yield', function (): void {
    // The yield is stated before any outcome, and a withdrawal is not known
    // then. Where both entrants of a semifinal withdraw before it, the tie is
    // void and sends nobody on (ADR 0011): the other finalist stands alone in
    // the final, goes on without it being played, and nobody lost it.
    it('makes the losers of a final fewer than declared when a void semifinal left one finalist', function (): void {
        $entrants = [];
        for ($i = 1; $i <= 8; ++$i) {
            $entrants[] = new Participant("p{$i}", "Participant {$i}");
        }
        $firstNamedWins = fn(RoundPairing $round): array => array_map(
            fn(Event $event): Result => new Result($event, array_values($event->getParticipants())[0]),
            $round->getEvents()
        );

        // Given: A bracket of 8 whose quarterfinals are played, and both
        // entrants of the first semifinal withdraw
        $engine = new SingleEliminationEngine();
        $quarterfinals = $engine->pairNextRound(StageState::start($entrants));
        $state = StageState::start($entrants)->withRoundPlayed($quarterfinals, $firstNamedWins($quarterfinals));
        $semifinals = $engine->pairNextRound($state);
        [$first, $second] = array_values($semifinals->getEvents()[0]->getParticipants());
        $state = $state->withoutParticipant($first)->withoutParticipant($second);
        while (!$engine->isComplete($state)) {
            $round = $engine->pairNextRound($state);
            $state = $state->withRoundPlayed($round, $firstNamedWins($round));
        }
        $outcome = $engine->getOutcome($state);
        assert($outcome !== null);

        // Then: The winner is as declared, and the losers are none where 1 is declared
        $winners = MatchOutcomeSelector::winners();
        $losers = MatchOutcomeSelector::losers();
        expect($winners->getYield()->countFrom(8, null, true))->toBe(1)
            ->and($winners->select($outcome))->toHaveCount(1)
            ->and($losers->getYield()->countFrom(8, null, true))->toBe(1)
            ->and($losers->select($outcome))->toBe([]);
    });
});
