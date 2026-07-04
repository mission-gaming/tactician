<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Quality;

use MissionGaming\Tactician\DTO\Schedule;

/**
 * The outcome of an optimization run: the winning schedule with its
 * score, its per-metric report, and the sample accounting — how many
 * candidates were generated and how many samples failed generation.
 */
final readonly class OptimizedSchedule
{
    /**
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
    ) {
    }

    public function getSchedule(): Schedule
    {
        return $this->schedule;
    }

    public function getScore(): float
    {
        return $this->score;
    }

    /**
     * @return array<string, float>
     */
    public function getReport(): array
    {
        return $this->report;
    }

    public function getSamplesGenerated(): int
    {
        return $this->samplesGenerated;
    }

    public function getSamplesFailed(): int
    {
        return $this->samplesFailed;
    }
}
