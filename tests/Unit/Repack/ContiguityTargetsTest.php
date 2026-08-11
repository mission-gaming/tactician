<?php

declare(strict_types=1);

use MissionGaming\Tactician\Repack\Internal\ContiguityTargets;

describe('ContiguityTargets', function (): void {
    it('targets the top of the session when nothing is pinned', function (): void {
        expect(ContiguityTargets::movableTarget([], 3, 4))->toBe([0, 1, 2]);
        expect(ContiguityTargets::movableTarget([], 0, 4))->toBe([]);
    });

    it('bridges the free slots between pins before extending', function (): void {
        // Pinned at 1: one movable bridges nothing (no interior), extends down
        expect(ContiguityTargets::movableTarget([1], 3, 4))->toBe([0, 2, 3]);
        // Pinned at 1 and 3: the interior hole at 2 comes first
        expect(ContiguityTargets::movableTarget([1, 3], 1, 4))->toBe([2]);
    });

    it('extends downward toward the first slot before upward', function (): void {
        // Pinned at 3 with one movable: slot 2 keeps the run contiguous
        // (a late start is the softer compromise than an interior gap)
        expect(ContiguityTargets::movableTarget([3], 1, 4))->toBe([2]);
        // Pinned at 0: nothing below, extend upward
        expect(ContiguityTargets::movableTarget([0], 2, 4))->toBe([1, 2]);
    });

    it('fills the lowest interior holes when the load cannot bridge the pins', function (): void {
        // Pins at 0 and 3, one movable: the residual gap is unavoidable
        // and will be reported, not hidden
        expect(ContiguityTargets::movableTarget([0, 3], 1, 4))->toBe([1]);
    });
});
