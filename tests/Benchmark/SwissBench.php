<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Tests\Benchmark;

use MissionGaming\Tactician\Constraints\ConsecutiveRoleConstraint;
use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Scheduling\SwissOptions;
use MissionGaming\Tactician\Scheduling\SwissScheduler;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\OutputTimeUnit;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Timeout;
use PhpBench\Attributes\Warmup;
use RuntimeException;

/**
 * Swiss pairing under a constraint.
 *
 * Run with `composer bench`.
 */
#[BeforeMethods('setUp')]
#[OutputTimeUnit('milliseconds', precision: 3)]
#[Timeout(30.0)]
final class SwissBench
{
    /** @var list<Participant> */
    private array $field = [];

    public function setUp(): void
    {
        $this->field = [];
        for ($i = 1; $i <= 24; ++$i) {
            $this->field[] = new Participant(sprintf('p%02d', $i), "Participant {$i}", $i);
        }
    }

    /**
     * 24 participants, five rounds, nobody at home or away three times
     * running. The limit rules out many pairings of the later rounds, so
     * the search meets dead ends it has to back out of.
     *
     * @throws IncompleteScheduleException
     * @throws InvalidConfigurationException
     * @throws RuntimeException When the benchmark did not produce what it measures
     */
    #[Revs(3)]
    #[Iterations(5)]
    #[Warmup(1)]
    public function benchSchedule24UnderARoleStreakLimit(): void
    {
        $schedule = (new SwissScheduler(new ConstraintSet([ConsecutiveRoleConstraint::homeAway(2)])))
            ->schedule($this->field, new SwissOptions(5));

        if (count($schedule) !== 60) {
            throw new RuntimeException('The benchmark did not produce a Swiss schedule of 60 events.');
        }
    }

    /**
     * 24 participants, all 23 rounds a field of 24 has, no constraints: the
     * late rounds have few pairings left to choose from.
     *
     * @throws IncompleteScheduleException
     * @throws InvalidConfigurationException
     * @throws RuntimeException When the benchmark did not produce what it measures
     */
    #[Revs(3)]
    #[Iterations(5)]
    #[Warmup(1)]
    public function benchSchedule24ToTheLastRound(): void
    {
        $schedule = (new SwissScheduler())->schedule($this->field, new SwissOptions(23));

        if (count($schedule) !== 276) {
            throw new RuntimeException('The benchmark did not produce a Swiss schedule of 276 events.');
        }
    }
}
