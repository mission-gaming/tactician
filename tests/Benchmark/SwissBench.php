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
use RuntimeException;

/**
 * Swiss pairing under a constraint.
 *
 * Run with `composer bench`. RoundRobinBench says how the classes and the
 * tool that runs them are kept apart.
 */
final class SwissBench
{
    /** @var list<Participant> */
    private array $field = [];

    public function __construct()
    {
        $this->field = [];
        for ($i = 1; $i <= 24; ++$i) {
            $this->field[] = new Participant(sprintf('p%02d', $i), "Participant {$i}", $i);
        }
    }

    /**
     * 24 participants, five rounds, nobody at home or away three times
     * running. The limit rules out many pairings of the later rounds, so
     * the search meets dead ends it has to back out of. Five times over.
     *
     * @throws IncompleteScheduleException
     * @throws InvalidConfigurationException
     * @throws RuntimeException When the benchmark did not produce what it measures
     */
    public function benchSchedule24UnderARoleStreakLimit(): void
    {
        for ($repeat = 0; $repeat < 5; ++$repeat) {
            $schedule = (new SwissScheduler(new ConstraintSet([ConsecutiveRoleConstraint::homeAway(2)])))
                ->schedule($this->field, new SwissOptions(5));

            if (count($schedule) !== 60) {
                throw new RuntimeException('The benchmark did not produce a Swiss schedule of 60 events.');
            }
        }
    }

    /**
     * 24 participants, all 23 rounds a field of 24 has, no constraints: the
     * late rounds have few pairings left to choose from. Three times over.
     *
     * @throws IncompleteScheduleException
     * @throws InvalidConfigurationException
     * @throws RuntimeException When the benchmark did not produce what it measures
     */
    public function benchSchedule24ToTheLastRound(): void
    {
        for ($repeat = 0; $repeat < 3; ++$repeat) {
            $schedule = (new SwissScheduler())->schedule($this->field, new SwissOptions(23));

            if (count($schedule) !== 276) {
                throw new RuntimeException('The benchmark did not produce a Swiss schedule of 276 events.');
            }
        }
    }
}
