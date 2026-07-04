<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Quality;

use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;

/**
 * Composes quality metrics into one weighted score.
 *
 * Which metrics matter — and how much — is application policy; the
 * scorer is just the arithmetic. Because every metric is
 * lower-is-better with zero ideal, the score is a plain weighted sum,
 * and per-metric values are reported alongside it so a chosen schedule
 * is explainable rather than just "best".
 */
final readonly class ScheduleScorer
{
    /** @var array<array{metric: QualityMetric, weight: float}> */
    private array $weightedMetrics;

    /**
     * @param array<array{metric: QualityMetric, weight: float}> $weightedMetrics
     *
     * @throws InvalidConfigurationException When no metrics are given or a weight is not positive
     */
    public function __construct(array $weightedMetrics)
    {
        if ($weightedMetrics === []) {
            throw new InvalidConfigurationException('A scorer needs at least one metric', []);
        }

        $names = [];
        foreach ($weightedMetrics as $index => $entry) {
            if (!is_array($entry)) {
                throw new InvalidConfigurationException(
                    'Every scorer entry must be an array with metric and weight keys',
                    ['index' => $index, 'given' => get_debug_type($entry)]
                );
            }
            if (!($entry['metric'] ?? null) instanceof QualityMetric) {
                throw new InvalidConfigurationException(
                    'Every scorer entry needs a metric implementing QualityMetric',
                    ['index' => $index]
                );
            }

            // report() keys by metric name; a duplicate would silently
            // overwrite its twin's measurement
            $name = $entry['metric']->getName();
            if (isset($names[$name])) {
                throw new InvalidConfigurationException(
                    'Metric names must be unique within a scorer',
                    ['index' => $index, 'metric' => $name]
                );
            }
            $names[$name] = true;
            $weight = $entry['weight'] ?? null;
            if (!is_float($weight) && !is_int($weight)) {
                throw new InvalidConfigurationException(
                    'Every scorer entry needs a numeric weight',
                    ['index' => $index, 'metric' => $entry['metric']->getName()]
                );
            }
            if ($weight <= 0) {
                throw new InvalidConfigurationException(
                    'Metric weights must be positive',
                    ['index' => $index, 'metric' => $entry['metric']->getName(), 'weight' => $weight]
                );
            }
        }

        $this->weightedMetrics = array_map(fn (array $entry) => [
            'metric' => $entry['metric'],
            'weight' => (float) $entry['weight'],
        ], array_values($weightedMetrics));
    }

    /**
     * Equal-weight convenience constructor.
     *
     * @throws InvalidConfigurationException When no metrics are given
     */
    public static function of(QualityMetric ...$metrics): self
    {
        return new self(array_map(
            fn (QualityMetric $metric) => ['metric' => $metric, 'weight' => 1.0],
            $metrics
        ));
    }

    /**
     * The weighted defect score; lower is better, zero is ideal.
     */
    public function score(Schedule $schedule): float
    {
        $score = 0.0;
        foreach ($this->weightedMetrics as $entry) {
            $score += $entry['weight'] * $entry['metric']->measure($schedule);
        }

        return $score;
    }

    /**
     * Raw per-metric measurements, keyed by metric name.
     *
     * @return array<string, float>
     */
    public function report(Schedule $schedule): array
    {
        $report = [];
        foreach ($this->weightedMetrics as $entry) {
            $report[$entry['metric']->getName()] = $entry['metric']->measure($schedule);
        }

        return $report;
    }
}
