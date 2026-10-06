<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Tests\Support\RoleCounts;
use PHPUnit\Framework\Assert;

// The roles the round-robin scheduler produces when nothing is said about
// roles, pinned as they are. The role of a participant in an event is its
// position: first-named or second-named.
//
// These are characterisation tests: the numbers are what the library has
// produced since roles began to alternate with round parity, not what it
// should produce. They exist so that a change to the default is a failing
// test and a decision, never a side effect. The golden fixtures pin the same
// output event by event; these say what is wrong with it in three numbers.

/**
 * @return list<Participant>
 */
function defaultRoleField(int $count): array
{
    $participants = [];
    for ($i = 1; $i <= $count; ++$i) {
        $participants[] = new Participant("p{$i}", "Participant {$i}", $i);
    }

    return $participants;
}

describe('the default role assignment', function (): void {
    it('ends a single leg 3 out of balance in an even field and 4 in an odd one', function (): void {
        for ($size = 2; $size <= 30; ++$size) {
            $expected = match (true) {
                $size === 2 => 1,
                $size === 3, $size === 5 => 2,
                $size % 2 === 0 => 3,
                default => 4,
            };

            $schedule = (new RoundRobinScheduler())->schedule(defaultRoleField($size));

            Assert::assertSame($expected, RoleCounts::worstEndImbalance($schedule), "n={$size}");
        }
    });

    it('names one of four participants second in all three of its events', function (): void {
        $schedule = (new RoundRobinScheduler())->schedule(defaultRoleField(4));

        expect(RoleCounts::differences($schedule))->toBe(['p1' => 1, 'p4' => 1, 'p2' => 1, 'p3' => -3]);
    });

    it('repeats a role up to four times in a row in a single leg', function (): void {
        $worst = [];
        for ($size = 2; $size <= 30; ++$size) {
            $worst[$size] = RoleCounts::worstStreak((new RoundRobinScheduler())->schedule(defaultRoleField($size)));
        }

        expect(array_slice($worst, 0, 7, true))->toBe([2 => 1, 3 => 2, 4 => 3, 5 => 2, 6 => 2, 7 => 4, 8 => 2]);
        for ($size = 9; $size <= 30; ++$size) {
            Assert::assertSame($size % 2 === 0 ? 2 : 3, $worst[$size], "n={$size}");
        }
    });
});
