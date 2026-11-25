<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Tests\Benchmark;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\UnavailableValueException;
use MissionGaming\Tactician\Repack\SessionGrid;
use RuntimeException;

/**
 * Converting every position of a session grid, which a caller that indexes
 * its own slot records by ordinal does: a lookup that walked the grid made
 * that loop cost the square of the positions (#225).
 *
 * The grids are 10,000 positions, a tenth of SessionGrid::MAX_POSITIONS, so
 * that the code before the change, which the CI job also runs, finishes
 * inside the time limit of phpbench.json.
 *
 * Run with `composer bench`. RoundRobinBench says how the classes and the
 * tool that runs them are kept apart.
 */
final readonly class SessionGridBench
{
    private SessionGrid $oneSlotSessions;

    private SessionGrid $dailySessions;

    /** @var list<DateTimeImmutable> */
    private array $slotTimes;

    /**
     * @throws InvalidConfigurationException
     * @throws UnavailableValueException
     */
    public function __construct()
    {
        $this->oneSlotSessions = SessionGrid::shapeOnly(10_000);

        $starts = [];
        $first = new DateTimeImmutable('2026-01-01 08:00', new DateTimeZone('UTC'));
        for ($day = 0; $day < 100; ++$day) {
            $starts[] = $first->modify("+{$day} days");
        }
        $this->dailySessions = new SessionGrid($starts, new DateInterval('PT5M'), 100);

        $times = [];
        for ($session = 0; $session < 100; ++$session) {
            for ($slot = 0; $slot < 100; ++$slot) {
                $times[] = $this->dailySessions->getSlotTime($session, $slot);
            }
        }
        $this->slotTimes = $times;
    }

    /**
     * 10,000 sessions of one slot: every ordinal to its position and back.
     *
     * @throws InvalidConfigurationException
     * @throws RuntimeException When the benchmark did not produce what it measures
     */
    public function benchOrdinalRoundTripOf10000Sessions(): void
    {
        $grid = $this->oneSlotSessions;
        for ($ordinal = 0; $ordinal < $grid->getPositionCount(); ++$ordinal) {
            $position = $grid->positionOf($ordinal);
            if ($position === null || $grid->ordinalOf($position['session'], $position['slot']) !== $ordinal) {
                throw new RuntimeException("The benchmark did not find ordinal {$ordinal} again.");
            }
        }
    }

    /**
     * 100 daily sessions of 100 slots: the position of every slot time.
     *
     * @throws RuntimeException When the benchmark did not produce what it measures
     * @throws UnavailableValueException
     */
    public function benchInstantLookupOf10000Positions(): void
    {
        foreach ($this->slotTimes as $ordinal => $time) {
            if ($this->dailySessions->positionOf($time) !== ['session' => intdiv($ordinal, 100), 'slot' => $ordinal % 100]) {
                throw new RuntimeException("The benchmark did not find the position of slot time {$ordinal}.");
            }
        }
    }
}
