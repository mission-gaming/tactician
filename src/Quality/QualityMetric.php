<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Quality;

use MissionGaming\Tactician\DTO\Schedule;

/**
 * A graded measure of schedule quality.
 *
 * Constraints are hard filters — a schedule satisfies them or fails
 * generation. Metrics measure the graded properties two valid schedules
 * can still differ on: role balance, alternation, appearance rhythm,
 * repeat spacing. One convention for all of them: **lower is better and
 * zero is ideal** — metrics measure defects, so weighted composition
 * needs no per-metric direction flags.
 */
interface QualityMetric
{
    /**
     * Human-readable metric name for reports.
     */
    public function getName(): string;

    /**
     * Measure the schedule's defect on this dimension.
     *
     * @return float Non-negative; zero is ideal
     */
    public function measure(Schedule $schedule): float;
}
