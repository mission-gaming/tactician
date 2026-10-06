<?php

declare(strict_types=1);

use MissionGaming\Tactician\Repack\Internal\ContiguityTargets;

// Cases the mutation run showed no test to notice. The rule is the one the
// class states: the target bridges the free slots between the pins first,
// then extends downward toward slot 0, then upward, and is returned in
// ascending order with as many slots as there are movable events, or fewer
// only when the session has no more free slots.
describe('ContiguityTargets, where the load is more than the pins leave between them', function (): void {
    it('bridges, then takes one slot below and one above, in ascending order', function (): void {
        // Pins at 1 and 3 of six slots, three movable events: the hole at 2,
        // then slot 0 below, then slot 4 above. Not slot 5: three are enough.
        expect(ContiguityTargets::movableTarget([1, 3], 3, 6))->toBe([0, 2, 4]);
    });

    it('takes every slot below the pins before any slot above them', function (): void {
        // Pins at 3 and 5 of eight: the hole at 4, then 2, 1 and 0 going down
        expect(ContiguityTargets::movableTarget([3, 5], 4, 8))->toBe([0, 1, 2, 4])
            // One more, and the first slot above is next
            ->and(ContiguityTargets::movableTarget([3, 5], 5, 8))->toBe([0, 1, 2, 4, 6]);
    });

    it('stops at the last slot of the session when the load is more than the free slots', function (): void {
        // One pin at 0 of two slots and two movable events: slot 1 is all
        // there is. A slot 2 does not exist.
        expect(ContiguityTargets::movableTarget([0], 2, 2))->toBe([1])
            ->and(ContiguityTargets::movableTarget([1], 3, 2))->toBe([0]);
    });
});
