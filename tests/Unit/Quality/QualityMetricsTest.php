<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Quality\PairingSpacingMetric;
use MissionGaming\Tactician\Quality\RestSpreadMetric;
use MissionGaming\Tactician\Quality\RoleBalanceMetric;
use MissionGaming\Tactician\Quality\RoleStreakMetric;
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

/**
 * @return array<Participant>
 */
function qualityField(int $count): array
{
    $participants = [];
    for ($i = 1; $i <= $count; ++$i) {
        $participants[] = new Participant("q{$i}", "Player {$i}", $i);
    }

    return $participants;
}

describe('RoleBalanceMetric', function (): void {
    it('is zero for perfectly balanced roles and counts imbalance otherwise', function (): void {
        [$a, $b] = qualityField(2);

        $balanced = new Schedule([
            new Event([$a, $b], new Round(1)),
            new Event([$b, $a], new Round(2)),
        ]);
        expect((new RoleBalanceMetric())->measure($balanced))->toBe(0.0);

        // Both appearances first for A, second for B: imbalance 2 each
        $lopsided = new Schedule([
            new Event([$a, $b], new Round(1)),
            new Event([$a, $b], new Round(2)),
        ]);
        expect((new RoleBalanceMetric())->measure($lopsided))->toBe(2.0);
    });

    it('skips non-pairwise events and is zero for empty schedules', function (): void {
        [$a, $b, $c] = qualityField(3);

        expect((new RoleBalanceMetric())->measure(new Schedule([])))->toBe(0.0);
        expect((new RoleBalanceMetric())->measure(new Schedule([
            new Event([$a, $b, $c], new Round(1)),
        ])))->toBe(0.0);
    });
});

describe('RoleStreakMetric', function (): void {
    it('is zero for strict alternation and measures excess streaks', function (): void {
        [$a, $b, $c, $d] = qualityField(4);

        $alternating = new Schedule([
            new Event([$a, $b], new Round(1)),
            new Event([$b, $a], new Round(2)),
            new Event([$a, $b], new Round(3)),
        ]);
        expect((new RoleStreakMetric())->measure($alternating))->toBe(0.0);

        // A first three times in a row: streak 3, excess 2 (same for B)
        $streaky = new Schedule([
            new Event([$a, $b], new Round(1)),
            new Event([$a, $b], new Round(2)),
            new Event([$a, $b], new Round(3)),
        ]);
        expect((new RoleStreakMetric())->measure($streaky))->toBe(2.0);
    });
});

describe('RestSpreadMetric', function (): void {
    it('is zero for regular rhythms and measures irregular gaps', function (): void {
        [$a, $b, $c] = qualityField(3);

        // A plays rounds 1, 2, 3: gaps [1, 1], variance 0
        $regular = new Schedule([
            new Event([$a, $b], new Round(1)),
            new Event([$a, $c], new Round(2)),
            new Event([$a, $b], new Round(3)),
        ]);
        expect((new RestSpreadMetric())->measure($regular))->toBe(0.0);

        // A plays rounds 1, 2, 5: gaps [1, 3], mean 2, variance 1 -
        // averaged over the three appearing participants (B and C have
        // fewer than two gaps and contribute nothing)
        $irregular = new Schedule([
            new Event([$a, $b], new Round(1)),
            new Event([$a, $c], new Round(2)),
            new Event([$a, $b], new Round(5)),
        ]);
        expect((new RestSpreadMetric())->measure($irregular))->toEqualWithDelta(1 / 3, 1e-9);
    });
});

describe('PairingSpacingMetric', function (): void {
    it('is zero for evenly spaced repeats and for single meetings', function (): void {
        [$a, $b, $c, $d] = qualityField(4);

        // Mirrored two-leg round robin: every pair meets rounds r and r+3,
        // gap 3 = ideal 6/2 exactly
        $schedule = (new RoundRobinScheduler())->schedule(
            qualityField(4),
            new RoundRobinOptions(legs: 2)
        );
        expect((new PairingSpacingMetric())->measure($schedule))->toBe(0.0);

        // Single meetings have no spacing to judge
        $single = new Schedule([new Event([$a, $b], new Round(1))]);
        expect((new PairingSpacingMetric())->measure($single))->toBe(0.0);
    });

    it('measures bunched repeats', function (): void {
        [$a, $b] = qualityField(2);

        // Two meetings in rounds 1 and 2 of a 6-round schedule: gap 1,
        // ideal 3, deviation 2
        $schedule = new Schedule([
            new Event([$a, $b], new Round(1)),
            new Event([$b, $a], new Round(2)),
            new Event([$a, $b], new Round(6)), // third meeting fixes maxRound
        ]);
        // Meetings at 1, 2, 6: ideal gap 2; gaps [1, 4] -> deviations [1, 2] -> mean 1.5
        expect((new PairingSpacingMetric())->measure($schedule))->toBe(1.5);
    });
});
