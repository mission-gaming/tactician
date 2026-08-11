<?php

declare(strict_types=1);

use MissionGaming\Tactician\Repack\Internal\IntervalPlacement;
use MissionGaming\Tactician\Repack\Internal\StepBudget;

describe('IntervalPlacement', function (): void {
    it('scores parity-feasible load vectors zero', function (): void {
        // Two runs of length 1 in 2 slots pair up at either slot
        expect(IntervalPlacement::minOddScore(0, [1, 1], 2))->toBe(0);
        // Everyone plays every slot: always feasible in parity terms
        expect(IntervalPlacement::minOddScore(0, [4, 4, 4, 4], 4))->toBe(0);
    });

    it('detects load vectors no run placement can pack gap-free', function (): void {
        // Five runs of length 4 in 5 slots: every run sits out one end
        // slot, so the three interior slots always hold an odd count —
        // the K5 shape has no interval colouring
        expect(IntervalPlacement::minOddScore(0, [4, 4, 4, 4, 4], 5))->toBeGreaterThan(0);
    });

    it('enumerates cheapest total late-start first', function (): void {
        $seen = [];
        IntervalPlacement::enumerate(0, [2, 2], 3, new StepBudget(10_000), function (array $starts) use (&$seen): bool {
            $seen[] = $starts;

            return false;
        });

        // [0,0] (both from the top) must be tried before any late start
        expect($seen[0])->toBe([0, 0]);
        expect($seen)->toContain([1, 1]);
    });

    it('stops when a placement is accepted', function (): void {
        $calls = 0;
        $found = IntervalPlacement::enumerate(0, [1, 1], 2, new StepBudget(10_000), function (array $starts) use (&$calls): bool {
            ++$calls;

            return true;
        });

        expect($found)->toBeTrue();
        expect($calls)->toBe(1);
    });
});
