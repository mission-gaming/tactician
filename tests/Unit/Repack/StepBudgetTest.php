<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Repack\Internal\IntervalPlacement;
use MissionGaming\Tactician\Repack\Internal\LoadPlanner;
use MissionGaming\Tactician\Repack\Internal\SessionPacker;
use MissionGaming\Tactician\Repack\Internal\StepBudget;
use MissionGaming\Tactician\Repack\RepackOptions;

/**
 * The six events of a complete round robin of four, as participant index
 * pairs.
 *
 * @return array<int, array{int, int}>
 */
function fourParticipantEdges(): array
{
    return [[0, 1], [0, 2], [0, 3], [1, 2], [1, 3], [2, 3]];
}

describe('StepBudget', function (): void {
    it('has stopped nothing while steps remain', function (): void {
        $budget = new StepBudget(3);

        expect($budget->consume())->toBeTrue();
        expect($budget->consume(2))->toBeTrue();
        expect($budget->stoppedASearch())->toBeFalse();
    });

    it('does not count spending the last step as stopping a search', function (): void {
        // The search that took the last step finished without asking for more
        $budget = new StepBudget(1);
        $budget->consume();

        expect($budget->stoppedASearch())->toBeFalse();
    });

    it('says whether a step is left without recording anything', function (): void {
        $budget = new StepBudget(1);
        expect($budget->hasStepsLeft())->toBeTrue();

        $budget->consume();
        expect($budget->hasStepsLeft())->toBeFalse();
        // Asking this way is not a search being stopped.
        expect($budget->stoppedASearch())->toBeFalse();
    });

    it('records a refused step', function (): void {
        $budget = new StepBudget(1);
        $budget->consume();

        expect($budget->consume())->toBeFalse();
        expect($budget->stoppedASearch())->toBeTrue();
    });

    it('records a refused step larger than what is left', function (): void {
        $budget = new StepBudget(2);

        expect($budget->consume(3))->toBeFalse();
        expect($budget->stoppedASearch())->toBeTrue();
    });

    it('records a search told that nothing is left', function (): void {
        $budget = new StepBudget(1);
        $budget->consume();

        expect($budget->isExhausted())->toBeTrue();
        expect($budget->stoppedASearch())->toBeTrue();
    });

    it('records nothing when a search is told that steps remain', function (): void {
        $budget = new StepBudget(2);
        $budget->consume();

        expect($budget->isExhausted())->toBeFalse();
        expect($budget->stoppedASearch())->toBeFalse();
    });

    it('keeps the record once made', function (): void {
        $budget = new StepBudget(1);
        $budget->consume(5);
        $budget->isExhausted();
        $budget->consume();

        expect($budget->stoppedASearch())->toBeTrue();
    });
});

// Each phase of the repacker spends from the budget; each must leave the
// record behind when the budget stops it, and none when it does not.
describe('The step budget in each phase', function (): void {
    it('is recorded by the load planner', function (int $steps, bool $stopped): void {
        $participants = [];
        for ($i = 0; $i < 4; ++$i) {
            $participants[] = new Participant("p{$i}", "P{$i}");
        }
        $budget = new StepBudget($steps);

        (new LoadPlanner(new RepackOptions(), $budget))->plan(
            fourParticipantEdges(),
            [],
            [],
            [3, 3],
            2,
            ['e1', 'e2', 'e3', 'e4', 'e5', 'e6'],
            $participants
        );

        expect($budget->stoppedASearch())->toBe($stopped);
    })->with([
        'one step' => [1, true],
        'the default budget' => [200_000, false],
    ]);

    it('is recorded by the session packer', function (int $steps, bool $stopped): void {
        $budget = new StepBudget($steps);

        $packed = (new SessionPacker($budget))->pack(fourParticipantEdges(), [], [], 3, 2);

        expect($budget->stoppedASearch())->toBe($stopped);
        // Stopped or not, the packer accounts for every event
        expect(count($packed['assignments']) + count($packed['leftovers']))->toBe(6);
    })->with([
        'one step' => [1, true],
        'the default budget' => [200_000, false],
    ]);

    it('is recorded by the run placement search', function (int $steps, bool $stopped): void {
        $budget = new StepBudget($steps);

        // Four runs of three in three slots: one placement, all at the top
        $found = IntervalPlacement::enumerate(0, [3, 3, 3, 3], 3, $budget, static fn(array $starts): bool => true);

        expect($found)->toBe(!$stopped);
        expect($budget->stoppedASearch())->toBe($stopped);
    })->with([
        'one step' => [1, true],
        'the default budget' => [200_000, false],
    ]);
});
