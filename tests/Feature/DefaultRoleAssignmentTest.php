<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\LegStrategies\LegStrategyInterface;
use MissionGaming\Tactician\LegStrategies\MirroredLegStrategy;
use MissionGaming\Tactician\LegStrategies\RepeatedLegStrategy;
use MissionGaming\Tactician\LegStrategies\ShuffledLegStrategy;
use MissionGaming\Tactician\RoleAssignment\RoundParityRoleAssignment;
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
// Two groups. The first holds the default: the balanced role assignment
// since 0.3.0 (issue #51). The second holds the roles of the round-parity
// role assignment, which was the default up to 0.2.x and is what a caller
// names to keep the roles of those releases; its figures are the ones this
// file pinned for the default then, unchanged.
//
// These are characterisation tests: the numbers are what the library
// produces, counted in its events. They exist so that a change to either
// set of roles is a failing test and a decision, never a side effect. The
// golden fixtures pin the same output event by event; these say what it
// amounts to in three numbers. What the balanced role assignment promises
// (and not only produces) is held in tests/Feature/RoleAssignmentTest.php.

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
 * after a single leg of round-parity roles.
 */
function roundParitySingleLegImbalance(int $size): int
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

/**
 * Options that name the round-parity role assignment.
 *
 * @throws InvalidConfigurationException When the number of legs is below 1
 */
function roundParityOptions(int $legs = 1, ?LegStrategyInterface $strategy = null): RoundRobinOptions
{
    return new RoundRobinOptions(legs: $legs, strategy: $strategy, roleAssignment: new RoundParityRoleAssignment());
}

describe('the default role assignment', function (): void {
    // Each participant plays one event fewer than the field has
    // participants: an odd number in a field of even size, where 1 apart is
    // the least there is, and an even number in a field of odd size.
    it('ends a single leg 1 out of balance in an even field and level in an odd one', function (): void {
        for ($size = 2; $size <= 30; ++$size) {
            $schedule = (new RoundRobinScheduler())->schedule(defaultRoleField($size));

            Assert::assertSame($size % 2 === 0 ? 1 : 0, RoleCounts::worstEndImbalance($schedule), "n={$size}");
        }
    });

    it('names every one of four participants first once or twice in its three events', function (): void {
        $schedule = (new RoundRobinScheduler())->schedule(defaultRoleField(4));

        expect(RoleCounts::differences($schedule))->toBe(['p4' => 1, 'p1' => -1, 'p2' => -1, 'p3' => 1]);
    });

    // The small case the 0.3.0 changelog prints for the change of default:
    // four participants, one leg, no seed, before and after. The pairings
    // and rounds are the same; three of the six events name the other
    // participant first.
    it('gives four participants the roles the 0.3.0 changelog prints, and round parity the ones before', function (): void {
        $print = static function (RoundRobinOptions $options): array {
            $rounds = [];
            foreach ((new RoundRobinScheduler())->schedule(defaultRoleField(4), $options) as $event) {
                [$first, $second] = $event->getParticipants();
                $rounds[$event->getRound()?->getNumber()][] = substr($first->getId(), 1) . '-' . substr($second->getId(), 1);
            }

            return array_map(static fn(array $events): string => implode(' ', $events), $rounds);
        };

        expect($print(roundParityOptions()))->toBe([1 => '1-4 2-3', 2 => '2-1 4-3', 3 => '1-3 4-2'])
            ->and($print(new RoundRobinOptions()))->toBe([1 => '4-1 2-3', 2 => '1-2 3-4', 3 => '3-1 4-2']);
    });

    it('repeats a role at most twice in a row in a single leg, and is never further than 2 apart on the way', function (): void {
        for ($size = 2; $size <= 30; ++$size) {
            $schedule = (new RoundRobinScheduler())->schedule(defaultRoleField($size));

            Assert::assertSame($size <= 3 ? 1 : 2, RoleCounts::worstStreak($schedule), "n={$size}");
            // An odd field from five up: the bye shifts one parity
            Assert::assertSame($size % 2 === 1 && $size >= 5 ? 2 : 1, RoleCounts::worstRunningImbalance($schedule), "n={$size}");
        }
    });

    // Several legs. The end imbalance follows from the single leg by
    // counting, as it does for any roles: mirrored legs cancel two by two,
    // and repeated legs add up.
    it('ends several legs out of balance by the single-leg figure times what the leg strategy makes of it', function (
        string $strategyName,
        int $legs
    ): void {
        for ($size = 2; $size <= 30; ++$size) {
            $factor = $strategyName === 'mirrored' ? $legs % 2 : $legs;

            $schedule = (new RoundRobinScheduler())->schedule(
                defaultRoleField($size),
                new RoundRobinOptions(legs: $legs, strategy: defaultRoleLegStrategy($strategyName))
            );

            Assert::assertSame(
                ($size % 2 === 0 ? 1 : 0) * $factor,
                RoleCounts::worstEndImbalance($schedule),
                "{$strategyName}, {$legs} legs, n={$size}"
            );
        }
    })
        ->with([['mirrored'], ['repeated']])
        ->with([[1], [2], [3], [4], [5], [6]]);

    // The longest run of one role over several legs, pinned as it is. Where
    // one mirrored leg ends and the next begins a role repeats more often
    // than inside a leg: three times in a field of even size, and four
    // times in a field of odd size whose size is one more than a multiple
    // of four. That is one more than round parity reaches in a field of
    // even size from six up (the second group of this file), and it is why
    // a limit of two in a row over mirrored legs makes the scheduler search
    // for other balanced roles (RoleConstraintsAcrossLegsTest). Repeated
    // legs do not have it: the last round of a leg is followed by the first
    // of the same roles.
    it('repeats a role across the legs as often as pinned here', function (string $strategyName, int $legs): void {
        for ($size = 2; $size <= 30; ++$size) {
            $expected = match (true) {
                $strategyName === 'repeated' && $size === 2 => $legs,
                $strategyName === 'repeated' => $size === 3 ? 1 : 2,
                $size <= 3 => $size - 1,
                $size % 4 === 1 => 4,
                default => 3,
            };

            Assert::assertSame($expected, RoleCounts::worstStreak((new RoundRobinScheduler())->schedule(
                defaultRoleField($size),
                new RoundRobinOptions(legs: $legs, strategy: defaultRoleLegStrategy($strategyName))
            )), "{$strategyName}, {$legs} legs, n={$size}");
        }
    })
        ->with([['mirrored'], ['repeated']])
        ->with([[2], [3], [4], [5], [6]]);
});

describe('the round-parity role assignment', function (): void {
    it('ends a single leg 3 out of balance in an even field and 4 in an odd one', function (): void {
        for ($size = 2; $size <= 30; ++$size) {
            $schedule = (new RoundRobinScheduler())->schedule(defaultRoleField($size), roundParityOptions());

            Assert::assertSame(roundParitySingleLegImbalance($size), RoleCounts::worstEndImbalance($schedule), "n={$size}");
        }
    });

    it('names one of four participants second in all three of its events', function (): void {
        $schedule = (new RoundRobinScheduler())->schedule(defaultRoleField(4), roundParityOptions());

        expect(RoleCounts::differences($schedule))->toBe(['p1' => 1, 'p4' => 1, 'p2' => 1, 'p3' => -3]);
    });

    it('repeats a role up to four times in a row in a single leg', function (): void {
        $worst = [];
        for ($size = 2; $size <= 30; ++$size) {
            $worst[$size] = RoleCounts::worstStreak((new RoundRobinScheduler())->schedule(defaultRoleField($size), roundParityOptions()));
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
    // - mirrored: the odd-numbered legs one way, the even-numbered legs the
    //   other way. Two legs cancel, so an even number of legs ends 0 apart
    //   and an odd number ends as a single leg does: the factor is the leg
    //   count modulo 2.
    // - repeated: every leg as leg 1, so legs times the single-leg figure.
    //
    // A change in either direction fails: these are exact values. Before
    // 0.3.0 the mirrored factor was |legs - 2|, because every leg after the
    // first was reversed.
    it('ends several legs out of balance by the single-leg figure times what the leg strategy makes of it', function (
        string $strategyName,
        int $legs
    ): void {
        for ($size = 2; $size <= 30; ++$size) {
            $factor = $strategyName === 'mirrored' ? $legs % 2 : $legs;

            $schedule = (new RoundRobinScheduler())->schedule(
                defaultRoleField($size),
                roundParityOptions($legs, defaultRoleLegStrategy($strategyName))
            );

            Assert::assertSame(
                roundParitySingleLegImbalance($size) * $factor,
                RoleCounts::worstEndImbalance($schedule),
                "{$strategyName}, {$legs} legs, n={$size}"
            );
        }
    })
        ->with([['mirrored'], ['repeated']])
        ->with([[1], [2], [3], [4], [5], [6]]);

    // The longest run of one role over several legs, pinned as it is: where
    // one leg ends and the next begins a role can repeat more often than
    // inside a leg. From twelve participants on the figure is regular.
    //
    // For mirrored legs the figure of two legs is the figure of any larger
    // number, and that is counted, not measured: every leg is leg 1 or its
    // reverse, by turns, so every boundary between two legs is the one
    // between legs 1 and 2 or that one with both roles exchanged, and a run
    // cannot be longer than a leg and its neighbour allow (a participant
    // with one role throughout a leg has the other role throughout the next).
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
                roundParityOptions($legs, defaultRoleLegStrategy($strategyName))
            ));
        }

        expect(array_slice($worst, 0, 10))->toBe($upToEleven);
        for ($size = 12; $size <= 30; ++$size) {
            Assert::assertSame($fromTwelve($size), $worst[$size], "{$strategyName}, {$legs} legs, n={$size}");
        }
    })->with([
        'mirrored, 2 legs' => ['mirrored', 2, [1, 2, 3, 4, 2, 4, 2, 3, 2, 3], fn(int $size): int => $size % 2 === 0 ? 2 : 3],
        'mirrored, 3 legs' => ['mirrored', 3, [1, 2, 3, 4, 2, 4, 2, 3, 2, 3], fn(int $size): int => $size % 2 === 0 ? 2 : 3],
        'mirrored, 4 legs' => ['mirrored', 4, [1, 2, 3, 4, 2, 4, 2, 3, 2, 3], fn(int $size): int => $size % 2 === 0 ? 2 : 3],
        'mirrored, 5 legs' => ['mirrored', 5, [1, 2, 3, 4, 2, 4, 2, 3, 2, 3], fn(int $size): int => $size % 2 === 0 ? 2 : 3],
        'mirrored, 6 legs' => ['mirrored', 6, [1, 2, 3, 4, 2, 4, 2, 3, 2, 3], fn(int $size): int => $size % 2 === 0 ? 2 : 3],
        'repeated, 2 legs' => ['repeated', 2, [2, 4, 6, 3, 4, 5, 3, 4, 3, 3], fn(int $size): int => 3],
        'repeated, 3 legs' => ['repeated', 3, [3, 6, 9, 3, 4, 5, 3, 4, 3, 3], fn(int $size): int => 3],
        'repeated, 4 legs' => ['repeated', 4, [4, 8, 12, 3, 4, 5, 3, 4, 3, 3], fn(int $size): int => 3],
    ]);

    // The shuffled strategy draws the roles of the later legs, so only its
    // first leg has figures to pin: the ones of a single leg.
    it('gives the first leg of a shuffled schedule the roles of a single leg', function (int $legs): void {
        for ($size = 2; $size <= 30; ++$size) {
            $single = (new RoundRobinScheduler())->schedule(defaultRoleField($size), roundParityOptions());
            $shuffled = (new RoundRobinScheduler())->schedule(
                defaultRoleField($size),
                roundParityOptions($legs, new ShuffledLegStrategy(new Randomizer(new Mt19937($size))))
            );

            $firstLeg = RoleCounts::leg($shuffled, 1, $size % 2 === 0 ? $size - 1 : $size);

            Assert::assertSame(RoleCounts::differences($single), RoleCounts::differences($firstLeg), "n={$size}");
            Assert::assertSame(roundParitySingleLegImbalance($size), RoleCounts::worstEndImbalance($firstLeg), "n={$size}");
            Assert::assertSame(RoleCounts::worstStreak($single), RoleCounts::worstStreak($firstLeg), "n={$size}");
        }
    })->with([[2], [3], [4]]);
});
