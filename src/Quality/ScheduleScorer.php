<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Quality;

use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;

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
     * @throws InvalidConfigurationException When no metrics are given, or a weight is not
     *                                       positive or not finite (NAN, INF)
     */
    public function __construct(array $weightedMetrics)
    {
        if ($weightedMetrics === []) {
            throw new InvalidConfigurationException('A scorer needs at least one metric', [], reason: InvalidConfigurationReason::EmptyList);
        }

        $names = [];
        foreach ($weightedMetrics as $index => $entry) {
            if (!is_array($entry)) {
                throw new InvalidConfigurationException(
                    'Every scorer entry must be an array with metric and weight keys',
                    ['index' => $index, 'given' => get_debug_type($entry)],
                    reason: InvalidConfigurationReason::WrongValueType
                );
            }
            if (!($entry['metric'] ?? null) instanceof QualityMetric) {
                throw new InvalidConfigurationException(
                    'Every scorer entry needs a metric implementing QualityMetric',
                    ['index' => $index],
                    reason: InvalidConfigurationReason::WrongValueType
                );
            }

            // report() keys by metric name; a duplicate would silently
            // overwrite its twin's measurement
            $name = $entry['metric']->getName();
            if (isset($names[$name])) {
                throw new InvalidConfigurationException(
                    'Metric names must be unique within a scorer',
                    ['index' => $index, 'metric' => $name],
                    reason: InvalidConfigurationReason::DuplicateName
                );
            }
            $names[$name] = true;
            $weight = $entry['weight'] ?? null;
            if (!is_float($weight) && !is_int($weight)) {
                throw new InvalidConfigurationException(
                    'Every scorer entry needs a numeric weight',
                    ['index' => $index, 'metric' => $entry['metric']->getName()],
                    reason: InvalidConfigurationReason::WrongValueType
                );
            }
            if ($weight <= 0) {
                throw new InvalidConfigurationException(
                    'Metric weights must be positive',
                    ['index' => $index, 'metric' => $entry['metric']->getName(), 'weight' => $weight],
                    reason: InvalidConfigurationReason::ValueOutOfRange
                );
            }
            // NAN fails no comparison and INF is positive, so both pass the
            // check above; a score they touch can never be compared.
            if (!is_finite((float) $weight)) {
                throw new InvalidConfigurationException(
                    'Metric weights must be finite',
                    ['index' => $index, 'metric' => $entry['metric']->getName(), 'weight' => self::nameOf((float) $weight)],
                    reason: InvalidConfigurationReason::ValueOutOfRange
                );
            }
        }

        $this->weightedMetrics = array_map(fn(array $entry) => [
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
            fn(QualityMetric $metric) => ['metric' => $metric, 'weight' => 1.0],
            $metrics
        ));
    }

    /**
     * The weighted defect score; lower is better, zero is ideal.
     *
     * Always a finite number: a score that is NAN or INF cannot be compared
     * with another, so it is refused here and not handed on.
     *
     * @throws InvalidConfigurationException When a metric measures NAN or INF, or the
     *                                       weighted sum overflows
     */
    public function score(Schedule $schedule): float
    {
        $score = 0.0;
        foreach ($this->weightedMetrics as $entry) {
            $score += $entry['weight'] * $this->measure($entry['metric'], $schedule);
        }

        if (!is_finite($score)) {
            throw new InvalidConfigurationException(
                'The weighted score is not finite',
                ['score' => self::nameOf($score)],
                reason: InvalidConfigurationReason::ValueOutOfRange
            );
        }

        return $score;
    }

    /**
     * Raw per-metric measurements, keyed by metric name.
     *
     * @return array<string, float>
     *
     * @throws InvalidConfigurationException When a metric measures NAN or INF
     */
    public function report(Schedule $schedule): array
    {
        $report = [];
        foreach ($this->weightedMetrics as $entry) {
            $report[$entry['metric']->getName()] = $this->measure($entry['metric'], $schedule);
        }

        return $report;
    }

    /**
     * @throws InvalidConfigurationException When the metric measures NAN or INF
     */
    private function measure(QualityMetric $metric, Schedule $schedule): float
    {
        $measurement = $metric->measure($schedule);

        if (!is_finite($measurement)) {
            throw new InvalidConfigurationException(
                "Metric {$metric->getName()} measured a value that is not finite",
                ['metric' => $metric->getName(), 'value' => self::nameOf($measurement)],
                reason: InvalidConfigurationReason::ValueOutOfRange
            );
        }

        return $measurement;
    }

    /**
     * The name of a float that is not finite, for the context of a
     * failure. Written out because a NAN cannot be cast to a string
     * without a warning from PHP 8.5 on, nor encoded as JSON.
     */
    private static function nameOf(float $notFinite): string
    {
        if (is_nan($notFinite)) {
            return 'NAN';
        }

        return $notFinite > 0 ? 'INF' : '-INF';
    }
}
