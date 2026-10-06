<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\LegStrategies\ShuffledLegStrategy;
use MissionGaming\Tactician\Quality\PairingSpacingMetric;
use MissionGaming\Tactician\Quality\QualityMetric;
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

/**
 * A metric that measures the same value for every schedule.
 */
function constantMetric(float $value, string $name = 'Constant'): QualityMetric
{
    return new readonly class ($value, $name) implements QualityMetric {
        public function __construct(private float $value, private string $name) {}

        #[Override]
        public function getName(): string
        {
            return $this->name;
        }

        #[Override]
        public function measure(Schedule $schedule): float
        {
            return $this->value;
        }
    };
}

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
        $notAnArray = unserialize(serialize(['just a metric name']));

        expect(fn() => new ScheduleScorer([]))
            ->toThrow(InvalidConfigurationException::class, 'at least one metric');
        expect(fn() => new ScheduleScorer($notAnArray))
            ->toThrow(InvalidConfigurationException::class, 'must be an array');
        expect(fn() => new ScheduleScorer([
            ['metric' => new RoleBalanceMetric(), 'weight' => 1.0],
            ['metric' => new RoleBalanceMetric(), 'weight' => 2.0],
        ]))->toThrow(InvalidConfigurationException::class, 'unique');
        expect(fn() => new ScheduleScorer($missingMetric))
            ->toThrow(InvalidConfigurationException::class, 'implementing QualityMetric');
        expect(fn() => new ScheduleScorer($wordWeight))
            ->toThrow(InvalidConfigurationException::class, 'numeric weight');
        expect(fn() => new ScheduleScorer([['metric' => new RoleBalanceMetric(), 'weight' => 0]]))
            ->toThrow(InvalidConfigurationException::class, 'positive');
    });

    // NAN is neither positive nor not positive, and INF is positive, so
    // both passed the weight check; every score they touch is NAN or INF.
    it('rejects a weight that is not a finite number', function (float $weight): void {
        new ScheduleScorer([['metric' => new RoleBalanceMetric(), 'weight' => $weight]]);
    })->with([
        'NAN' => [NAN],
        'INF' => [INF],
    ])->throws(InvalidConfigurationException::class, 'Metric weights must be finite');

    it('still calls a weight of minus infinity not positive', function (): void {
        new ScheduleScorer([['metric' => new RoleBalanceMetric(), 'weight' => -INF]]);
    })->throws(InvalidConfigurationException::class, 'Metric weights must be positive');

    it('rejects a measurement that is not a finite number', function (float $measurement): void {
        $scorer = new ScheduleScorer([
            ['metric' => new RoleBalanceMetric(), 'weight' => 1.0],
            ['metric' => constantMetric($measurement, 'Broken'), 'weight' => 1.0],
        ]);

        expect(fn() => $scorer->score(new Schedule([])))
            ->toThrow(InvalidConfigurationException::class, 'Metric Broken measured a value that is not finite');
        expect(fn() => $scorer->report(new Schedule([])))
            ->toThrow(InvalidConfigurationException::class, 'Metric Broken measured a value that is not finite');
    })->with([
        'NAN' => [NAN],
        'INF' => [INF],
        '-INF' => [-INF],
    ]);

    it('names the metric and the value in the context of the failure', function (float $measurement, string $name): void {
        $scorer = ScheduleScorer::of(constantMetric($measurement, 'Broken'));

        try {
            $scorer->score(new Schedule([]));
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getContext())->toBe(['metric' => 'Broken', 'value' => $name])
                ->and($exception->getReason())->toBe(InvalidConfigurationReason::ValueOutOfRange)
                ->and($exception->getDiagnosticReport())->toContain("• value: {$name}")
                ->not->toContain('REQUIREMENTS');

            return;
        }

        throw new LogicException('The measurement was scored.');
    })->with([
        'NAN' => [NAN, 'NAN'],
        'INF' => [INF, 'INF'],
        '-INF' => [-INF, '-INF'],
    ]);

    it('names a weight that is not finite in the context of the failure', function (float $weight, string $name): void {
        try {
            new ScheduleScorer([['metric' => new RoleBalanceMetric(), 'weight' => $weight]]);
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getContext())->toBe(['index' => 0, 'metric' => 'Role Balance', 'weight' => $name])
                ->and($exception->getReason())->toBe(InvalidConfigurationReason::ValueOutOfRange)
                ->and($exception->getDiagnosticReport())->toContain("• weight: {$name}")
                ->not->toContain('REQUIREMENTS');

            return;
        }

        throw new LogicException('The weight was accepted.');
    })->with([
        'NAN' => [NAN, 'NAN'],
        'INF' => [INF, 'INF'],
    ]);

    it('rejects a weighted sum that overflows', function (): void {
        $scorer = new ScheduleScorer([
            ['metric' => constantMetric(1.0e200, 'Large'), 'weight' => 1.0e200],
        ]);

        // The raw measurements are finite, so the report still stands.
        expect($scorer->report(new Schedule([])))->toBe(['Large' => 1.0e200]);
        expect(fn() => $scorer->score(new Schedule([])))
            ->toThrow(InvalidConfigurationException::class, 'The weighted score is not finite');

        try {
            $scorer->score(new Schedule([]));
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getContext())->toBe(['score' => 'INF'])
                ->and($exception->getReason())->toBe(InvalidConfigurationReason::ValueOutOfRange);
        }
    });

    // INF and -INF are each a finite weight times a finite measurement that
    // overflowed; their sum is NAN, which no comparison orders either.
    it('rejects a weighted sum that is not a number', function (): void {
        $scorer = new ScheduleScorer([
            ['metric' => constantMetric(1.0e200, 'Large'), 'weight' => 1.0e200],
            ['metric' => constantMetric(-1.0e200, 'Negative'), 'weight' => 1.0e200],
        ]);

        try {
            $scorer->score(new Schedule([]));
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getMessage())->toContain('The weighted score is not finite')
                ->and($exception->getContext())->toBe(['score' => 'NAN']);

            return;
        }

        throw new LogicException('A score of NAN was returned.');
    });

    it('accepts an integer weight and the largest finite weight', function (): void {
        $scorer = new ScheduleScorer([
            ['metric' => constantMetric(2.0, 'Two'), 'weight' => 3],
            ['metric' => constantMetric(0.0, 'Zero'), 'weight' => PHP_FLOAT_MAX],
        ]);

        expect($scorer->score(new Schedule([])))->toBe(6.0);
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
            fn(Randomizer $r) => $r->getInt(0, 1) === 0 ? $lopsided : $balanced,
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
                fn(Randomizer $r) => (new RoundRobinScheduler(null, $r))->schedule(
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
        $generate = fn(Randomizer $r) => (new RoundRobinScheduler(null, $r))->schedule(
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
        $generate = function (Randomizer $randomizer) use (&$calls, $schedule, $plan, $a, $b): Schedule {
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

        expect(fn() => $optimizer->optimize(function (Randomizer $randomizer) use ($plan, $a, $b): Schedule {
            throw new IncompleteScheduleException(1, 0, new ConstraintViolationCollector(), $plan, [$a, $b], 'nothing works');
        }, 3))->toThrow(IncompleteScheduleException::class, 'nothing works');
    });

    // With a NAN score no candidate was ever "better than the best so
    // far", so the optimizer ended with no winner and no failure to
    // rethrow: an AssertionError, or `throw null` with assertions off.
    it('reports a measurement that is not finite instead of ending without a winner', function (float $measurement): void {
        $optimizer = new ScheduleOptimizer(
            ScheduleScorer::of(constantMetric($measurement, 'Broken')),
            new Randomizer(new Mt19937(1))
        );

        $optimizer->optimize(fn(Randomizer $randomizer): Schedule => new Schedule([]), 3);
    })->with([
        'NAN' => [NAN],
        'INF' => [INF],
    ])->throws(InvalidConfigurationException::class, 'Metric Broken measured a value that is not finite');

    it('keeps the only candidate, however poor its score', function (): void {
        $optimizer = new ScheduleOptimizer(
            ScheduleScorer::of(constantMetric(PHP_FLOAT_MAX, 'Poor')),
            new Randomizer(new Mt19937(1))
        );
        $schedule = new Schedule([]);

        $optimized = $optimizer->optimize(fn(Randomizer $randomizer): Schedule => $schedule, 2);

        expect($optimized->getSchedule())->toBe($schedule)
            ->and($optimized->getScore())->toBe(PHP_FLOAT_MAX)
            ->and($optimized->getSamplesGenerated())->toBe(2);
    });

    it('rejects a non-positive sample count', function (): void {
        $optimizer = new ScheduleOptimizer(
            ScheduleScorer::of(new RoleBalanceMetric()),
            new Randomizer(new Mt19937(1))
        );

        expect(fn() => $optimizer->optimize(fn(Randomizer $randomizer) => new Schedule([]), 0))
            ->toThrow(InvalidConfigurationException::class, 'at least one sample');
    });
});
