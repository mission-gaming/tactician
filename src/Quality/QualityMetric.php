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
 *
 * @experimental
 */
interface QualityMetric
{
    /**
     * Human-readable metric name. The scorer's report is keyed by it, so it
     * must differ from the name of every other metric of the same scorer,
     * which refuses a duplicate.
     */
    public function getName(): string;

    /**
     * Measure the schedule's defect on this dimension.
     *
     * The same schedule must always measure the same: the scorer measures a
     * schedule once for its score and again for its report.
     *
     * @return float Non-negative and finite; zero is ideal. ScheduleScorer
     *               refuses a measurement of NAN or INF
     */
    public function measure(Schedule $schedule): float;
}
