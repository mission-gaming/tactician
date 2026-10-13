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

    it('hands one search a share of what is left, and takes back what the search did not spend', function (): void {
        $budget = new StepBudget(100);
        $budget->consume(20);

        $share = $budget->share(4);
        expect($share->stepsLeft())->toBe(20);

        $share->consume(5);
        $budget->returnShare($share, 20);

        expect($budget->stepsLeft())->toBe(75)
            ->and($budget->stoppedASearch())->toBeFalse();
    });

    it('counts a search its share stopped as a search it stopped, and keeps the steps past the share', function (): void {
        $budget = new StepBudget(100);

        $share = $budget->share(4);
        expect($share->consume(25))->toBeTrue()
            ->and($share->consume())->toBeFalse();
        $budget->returnShare($share, 25);

        expect($budget->stepsLeft())->toBe(75)
            ->and($budget->stoppedASearch())->toBeTrue();
    });

    it('gives a share of at least one step while a step is left, and of none when none is', function (): void {
        $budget = new StepBudget(2);
        expect($budget->share(4)->stepsLeft())->toBe(1);

        $budget->consume(2);
        expect($budget->share(4)->stepsLeft())->toBe(0)
            ->and($budget->stoppedASearch())->toBeFalse();
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

    it('takes the default of the options as the budget at which every work limit is whole', function (): void {
        // The limits were sized with the default budget, and a budget below
        // it gets a share of them: the two numbers must not drift apart
        expect(StepBudget::DEFAULT_STEPS)->toBe((new RepackOptions())->stepBudget);
    });

    it('gives a search its whole work limit at the default budget and above, and the same share as the budget below', function (int $callersBudget, int $whole, int $limit): void {
        expect((new StepBudget(1_000, $callersBudget))->workLimit($whole))->toBe($limit);
    })->with([
        'the default budget' => [200_000, 3_000_000, 3_000_000],
        'one step more than the default' => [200_001, 3_000_000, 3_000_000],
        'the largest budget there is' => [PHP_INT_MAX, 3_000_000, 3_000_000],
        'one step fewer than the default' => [199_999, 3_000_000, 2_999_985],
        'a tenth of the default' => [20_000, 3_000_000, 300_000],
        'a hundredth of the default' => [2_000, 500_000, 5_000],
        'one step, rounded down' => [1, 500_000, 2],
        'one step of the smallest limit' => [1, 200_000, 1],
        'a share that rounds down to nothing' => [1, 199_999, 0],
    ]);

    it('gives every search its whole work limit when it is made without a budget of the caller', function (): void {
        // How the unit tests of the searches make one: a few steps to spend
        // are not a small budget of the caller
        expect((new StepBudget(3))->workLimit(500_000))->toBe(500_000);
    });

    it('records a search stopped at a work limit the budget made smaller, and not one stopped at its whole limit', function (int $callersBudget, bool $recorded): void {
        // At the whole limit no larger budget would have taken the search
        // further, so the outcome is the one every larger budget gives
        $budget = new StepBudget(1_000, $callersBudget);
        $budget->stoppedAtWorkLimit();

        expect($budget->stoppedASearch())->toBe($recorded);
    })->with([
        'one step' => [1, true],
        'one step fewer than the default' => [199_999, true],
        'the default budget' => [200_000, false],
        'the largest budget there is' => [PHP_INT_MAX, false],
    ]);

    it('hands a share the same work limits, and takes back what the share recorded at one', function (): void {
        $budget = new StepBudget(100, 20_000);

        $share = $budget->share(4);
        expect($share->workLimit(200_000))->toBe(20_000);

        $share->stoppedAtWorkLimit();
        $budget->returnShare($share, 25);

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
