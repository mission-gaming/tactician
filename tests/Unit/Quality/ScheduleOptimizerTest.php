<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\LegStrategies\ShuffledLegStrategy;
use MissionGaming\Tactician\Quality\PairingSpacingMetric;
use MissionGaming\Tactician\Quality\RoleBalanceMetric;
use MissionGaming\Tactician\Quality\RoleStreakMetric;
use MissionGaming\Tactician\Quality\ScheduleOptimizer;
use MissionGaming\Tactician\Quality\ScheduleScorer;
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Stage\RoundRobinPlan;
use MissionGaming\Tactician\Validation\ConstraintViolationCollector;
use Random\Engine\Mt19937;
use Random\Randomizer;

describe('ScheduleScorer', function (): void {
    it('weights metrics into one score and reports per metric', function (): void {
        $a = new Participant('q1', 'Alice');
        $b = new Participant('q2', 'Bob');
        // A first both rounds: role balance 2.0, streak excess 1.0
        $schedule = new Schedule([
            new Event([$a, $b], new Round(1)),
            new Event([$a, $b], new Round(2)),
        ]);

        $scorer = new ScheduleScorer([
            ['metric' => new RoleBalanceMetric(), 'weight' => 2.0],
            ['metric' => new RoleStreakMetric(), 'weight' => 1.0],
        ]);

        expect($scorer->score($schedule))->toBe(5.0); // 2*2 + 1*1
        expect($scorer->report($schedule))->toBe([
            'Role Balance' => 2.0,
            'Role Streaks' => 1.0,
        ]);
    });

    it('builds equal weights via of()', function (): void {
        $scorer = ScheduleScorer::of(new RoleBalanceMetric(), new RoleStreakMetric());
        $schedule = new Schedule([]);

        expect($scorer->score($schedule))->toBe(0.0);
    });

    it('rejects malformed composition', function (): void {
        // Config-driven wiring can produce any shape, so the runtime
        // validation is pinned with deliberately wrong entries the
        // docblock type would forbid
        $missingMetric = unserialize(serialize([['weight' => 1.0]]));
        $wordWeight = unserialize(serialize([['metric' => new RoleBalanceMetric(), 'weight' => 'heavy']]));

        expect(fn () => new ScheduleScorer([]))
            ->toThrow(InvalidConfigurationException::class, 'at least one metric');
        expect(fn () => new ScheduleScorer($missingMetric))
            ->toThrow(InvalidConfigurationException::class, 'implementing QualityMetric');
        expect(fn () => new ScheduleScorer($wordWeight))
            ->toThrow(InvalidConfigurationException::class, 'numeric weight');
        expect(fn () => new ScheduleScorer([['metric' => new RoleBalanceMetric(), 'weight' => 0]]))
            ->toThrow(InvalidConfigurationException::class, 'positive');
    });
});

describe('ScheduleOptimizer', function (): void {
    it('keeps the best-scoring sample', function (): void {
        $a = new Participant('q1', 'Alice');
        $b = new Participant('q2', 'Bob');
        $lopsided = new Schedule([
            new Event([$a, $b], new Round(1)),
            new Event([$a, $b], new Round(2)),
        ]);
        $balanced = new Schedule([
            new Event([$a, $b], new Round(1)),
            new Event([$b, $a], new Round(2)),
        ]);

        // The generator picks by coin flip; across 8 samples both appear
        $optimizer = new ScheduleOptimizer(
            ScheduleScorer::of(new RoleBalanceMetric()),
            new Randomizer(new Mt19937(42))
        );
        $result = $optimizer->optimize(
            fn (Randomizer $r) => $r->getInt(0, 1) === 0 ? $lopsided : $balanced,
            8
        );

        expect($result->getSchedule())->toBe($balanced);
        expect($result->getScore())->toBe(0.0);
        expect($result->getReport())->toBe(['Role Balance' => 0.0]);
        expect($result->getSamplesGenerated())->toBe(8);
        expect($result->getSamplesFailed())->toBe(0);
    });

    it('is deterministic for the same master seed', function (): void {
        $participants = [];
        for ($i = 1; $i <= 6; ++$i) {
            $participants[] = new Participant("q{$i}", "Player {$i}", $i);
        }

        $run = function () use ($participants): string {
            $optimizer = new ScheduleOptimizer(
                ScheduleScorer::of(new PairingSpacingMetric(), new RoleStreakMetric()),
                new Randomizer(new Mt19937(7))
            );
            // Every randomness source in the pipeline must use the child
            // randomizer - the shuffled strategy included
            $result = $optimizer->optimize(
                fn (Randomizer $r) => (new RoundRobinScheduler(null, $r))->schedule(
                    $participants,
                    new RoundRobinOptions(legs: 2, strategy: new ShuffledLegStrategy($r))
                ),
                5
            );

            return json_encode([$result->getScore(), $result->getReport()], JSON_THROW_ON_ERROR);
        };

        expect($run())->toBe($run());
    });

    it('never scores worse than any individual sample', function (): void {
        $participants = [];
        for ($i = 1; $i <= 6; ++$i) {
            $participants[] = new Participant("q{$i}", "Player {$i}", $i);
        }
        $scorer = ScheduleScorer::of(new PairingSpacingMetric());
        $generate = fn (Randomizer $r) => (new RoundRobinScheduler(null, $r))->schedule(
            $participants,
            new RoundRobinOptions(legs: 2, strategy: new ShuffledLegStrategy($r))
        );

        $optimizer = new ScheduleOptimizer($scorer, new Randomizer(new Mt19937(21)));
        $best = $optimizer->optimize($generate, 10);

        $single = new ScheduleOptimizer($scorer, new Randomizer(new Mt19937(21)));
        $first = $single->optimize($generate, 1);

        expect($best->getScore())->toBeLessThanOrEqual($first->getScore());
    });

    it('skips failing samples and accounts for them', function (): void {
        $a = new Participant('q1', 'Alice');
        $b = new Participant('q2', 'Bob');
        $schedule = new Schedule([new Event([$a, $b], new Round(1))]);
        $plan = new RoundRobinPlan([$a, $b], 1);

        $calls = 0;
        $generate = function () use (&$calls, $schedule, $plan, $a, $b): Schedule {
            ++$calls;
            if ($calls % 2 === 1) {
                throw new IncompleteScheduleException(1, 0, new ConstraintViolationCollector(), $plan, [$a, $b]);
            }

            return $schedule;
        };

        $optimizer = new ScheduleOptimizer(
            ScheduleScorer::of(new RoleBalanceMetric()),
            new Randomizer(new Mt19937(1))
        );
        $result = $optimizer->optimize($generate, 6);

        expect($result->getSamplesGenerated())->toBe(3);
        expect($result->getSamplesFailed())->toBe(3);
    });

    it('rethrows the last failure when every sample fails', function (): void {
        $a = new Participant('q1', 'Alice');
        $b = new Participant('q2', 'Bob');
        $plan = new RoundRobinPlan([$a, $b], 1);

        $optimizer = new ScheduleOptimizer(
            ScheduleScorer::of(new RoleBalanceMetric()),
            new Randomizer(new Mt19937(1))
        );

        expect(fn () => $optimizer->optimize(function () use ($plan, $a, $b): Schedule {
            throw new IncompleteScheduleException(1, 0, new ConstraintViolationCollector(), $plan, [$a, $b], 'nothing works');
        }, 3))->toThrow(IncompleteScheduleException::class, 'nothing works');
    });

    it('rejects a non-positive sample count', function (): void {
        $optimizer = new ScheduleOptimizer(
            ScheduleScorer::of(new RoleBalanceMetric()),
            new Randomizer(new Mt19937(1))
        );

        expect(fn () => $optimizer->optimize(fn () => new Schedule([]), 0))
            ->toThrow(InvalidConfigurationException::class, 'at least one sample');
    });
});
