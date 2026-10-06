<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\LegStrategies\LegStrategyInterface;
use MissionGaming\Tactician\LegStrategies\MirroredLegStrategy;
use MissionGaming\Tactician\LegStrategies\RepeatedLegStrategy;
use MissionGaming\Tactician\LegStrategies\ShuffledLegStrategy;
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Tests\Support\RoleCounts;
use PHPUnit\Framework\Assert;
use Random\Engine\Mt19937;
use Random\Randomizer;

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

/**
 * How far apart the two role counts of the worst-placed participant are
 * after a single leg of the default round robin.
 */
function defaultSingleLegImbalance(int $size): int
{
    return match (true) {
        $size === 2 => 1,
        $size === 3, $size === 5 => 2,
        $size % 2 === 0 => 3,
        default => 4,
    };
}

/**
 * @throws UnexpectedValueException When the name is not one of the two strategies that draw nothing
 */
function defaultRoleLegStrategy(string $name): LegStrategyInterface
{
    return match ($name) {
        'mirrored' => new MirroredLegStrategy(),
        'repeated' => new RepeatedLegStrategy(),
        default => throw new UnexpectedValueException($name),
    };
}

describe('the default role assignment', function (): void {
    it('ends a single leg 3 out of balance in an even field and 4 in an odd one', function (): void {
        for ($size = 2; $size <= 30; ++$size) {
            $schedule = (new RoundRobinScheduler())->schedule(defaultRoleField($size));

            Assert::assertSame(defaultSingleLegImbalance($size), RoleCounts::worstEndImbalance($schedule), "n={$size}");
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

    // Several legs. The end imbalance follows from the single leg by
    // counting, because a leg strategy only decides which way round each
    // pairing of leg 1 is played again:
    //
    // - mirrored: leg 1 one way, every later leg the other way. A
    //   participant d apart after leg 1 is d - (legs - 1) * d apart at the
    //   end: |legs - 2| times the single-leg figure, so 0 for two legs.
    // - repeated: every leg as leg 1, so legs times the single-leg figure.
    //
    // A change in either direction fails: these are exact values.
    it('ends several legs out of balance by the single-leg figure times what the leg strategy makes of it', function (
        string $strategyName,
        int $legs
    ): void {
        for ($size = 2; $size <= 30; ++$size) {
            $factor = $strategyName === 'mirrored' ? abs($legs - 2) : $legs;

            $schedule = (new RoundRobinScheduler())->schedule(
                defaultRoleField($size),
                new RoundRobinOptions(legs: $legs, strategy: defaultRoleLegStrategy($strategyName))
            );

            Assert::assertSame(
                defaultSingleLegImbalance($size) * $factor,
                RoleCounts::worstEndImbalance($schedule),
                "{$strategyName}, {$legs} legs, n={$size}"
            );
        }
    })
        ->with([['mirrored'], ['repeated']])
        ->with([[1], [2], [3], [4]]);

    // The longest run of one role over several legs, pinned as it is: where
    // one leg ends and the next begins a role can repeat more often than
    // inside a leg. From twelve participants on the figure is regular.
    it('repeats a role across the legs as often as pinned here', function (
        string $strategyName,
        int $legs,
        array $upToEleven,
        Closure $fromTwelve
    ): void {
        $worst = [];
        for ($size = 2; $size <= 30; ++$size) {
            $worst[$size] = RoleCounts::worstStreak((new RoundRobinScheduler())->schedule(
                defaultRoleField($size),
                new RoundRobinOptions(legs: $legs, strategy: defaultRoleLegStrategy($strategyName))
            ));
        }

        expect(array_slice($worst, 0, 10))->toBe($upToEleven);
        for ($size = 12; $size <= 30; ++$size) {
            Assert::assertSame($fromTwelve($size), $worst[$size], "{$strategyName}, {$legs} legs, n={$size}");
        }
    })->with([
        'mirrored, 2 legs' => ['mirrored', 2, [1, 2, 3, 4, 2, 4, 2, 3, 2, 3], fn(int $size): int => $size % 2 === 0 ? 2 : 3],
        'mirrored, 3 legs' => ['mirrored', 3, [2, 4, 6, 4, 4, 5, 3, 4, 3, 3], fn(int $size): int => 3],
        'mirrored, 4 legs' => ['mirrored', 4, [3, 6, 9, 4, 4, 5, 3, 4, 3, 3], fn(int $size): int => 3],
        'repeated, 2 legs' => ['repeated', 2, [2, 4, 6, 3, 4, 5, 3, 4, 3, 3], fn(int $size): int => 3],
        'repeated, 3 legs' => ['repeated', 3, [3, 6, 9, 3, 4, 5, 3, 4, 3, 3], fn(int $size): int => 3],
        'repeated, 4 legs' => ['repeated', 4, [4, 8, 12, 3, 4, 5, 3, 4, 3, 3], fn(int $size): int => 3],
    ]);

    // The shuffled strategy draws the roles of the later legs, so only its
    // first leg has figures to pin: the ones of a single leg.
    it('gives the first leg of a shuffled schedule the roles of a single leg', function (int $legs): void {
        for ($size = 2; $size <= 30; ++$size) {
            $single = (new RoundRobinScheduler())->schedule(defaultRoleField($size));
            $shuffled = (new RoundRobinScheduler())->schedule(
                defaultRoleField($size),
                new RoundRobinOptions(legs: $legs, strategy: new ShuffledLegStrategy(new Randomizer(new Mt19937($size))))
            );

            $firstLeg = RoleCounts::leg($shuffled, 1, $size % 2 === 0 ? $size - 1 : $size);

            Assert::assertSame(RoleCounts::differences($single), RoleCounts::differences($firstLeg), "n={$size}");
            Assert::assertSame(defaultSingleLegImbalance($size), RoleCounts::worstEndImbalance($firstLeg), "n={$size}");
            Assert::assertSame(RoleCounts::worstStreak($single), RoleCounts::worstStreak($firstLeg), "n={$size}");
        }
    })->with([[2], [3], [4]]);
});
