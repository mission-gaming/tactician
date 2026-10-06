<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Quality;

use MissionGaming\Tactician\DTO\Schedule;

/**
 * The outcome of an optimization run: the winning schedule with its
 * score, its per-metric report, and the sample accounting — how many
 * candidates were generated and how many samples failed generation.
 *
 * @experimental
 */
final readonly class OptimizedSchedule
{
    /**
     * @param Schedule $schedule The best-scoring candidate
     * @param float $score The winner's weighted defect score (lower is better)
     * @param array<string, float> $report The winner's raw per-metric measurements
     * @param int $samplesGenerated Samples that produced a valid schedule
     * @param int $samplesFailed Samples skipped because generation threw IncompleteScheduleException
     */
    public function __construct(
        private Schedule $schedule,
        private float $score,
        private array $report,
        private int $samplesGenerated,
        private int $samplesFailed
    ) {}

    /**
     * The best-scoring candidate; of several with the same score, the one
     * sampled first.
     */
    public function getSchedule(): Schedule
    {
        return $this->schedule;
    }

    /**
     * The winner's weighted defect score: lower is better, zero is ideal.
     */
    public function getScore(): float
    {
        return $this->score;
    }

    /**
     * The winner's raw measurements keyed by metric name, before weighting,
     * in the order of the scorer's metrics.
     *
     * @return array<string, float>
     */
    public function getReport(): array
    {
        return $this->report;
    }

    /**
     * How many samples produced a schedule. With getSamplesFailed() it adds
     * up to the number of samples asked for.
     */
    public function getSamplesGenerated(): int
    {
        return $this->samplesGenerated;
    }

    /**
     * How many samples were skipped because generation threw
     * IncompleteScheduleException.
     */
    public function getSamplesFailed(): int
    {
        return $this->samplesFailed;
    }
}
