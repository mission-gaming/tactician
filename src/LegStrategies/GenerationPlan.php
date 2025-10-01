<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\LegStrategies;

/**
 * Plan for generating events across multiple tournament legs.
 *
 * This value object encapsulates the strategy's plan for how events
 * will be generated, including any optimization data or constraints
 * that affect the generation process.
 */
readonly class GenerationPlan
{
    /**
     * @param int $totalEvents Expected total number of events across all legs
     * @param int $eventsPerLeg Expected number of events per leg
     * @param int $roundsPerLeg Number of rounds in each leg
     * @param bool $requiresRandomization Whether the strategy needs randomization
     * @param array<string, mixed> $strategyData Strategy-specific data for generation
     * @param array<string> $warnings Any warnings about the generation plan
     */
    public function __construct(
        private int $totalEvents,
        private int $eventsPerLeg,
        private int $roundsPerLeg,
        private bool $requiresRandomization = false,
        private array $strategyData = [],
        private array $warnings = []
    ) {
    }

    /**
     * Get the expected total number of events across all legs.
     */
    public function getTotalEvents(): int
    {
        return $this->totalEvents;
    }

    /**
     * Get the expected number of events per leg.
     */
    public function getEventsPerLeg(): int
    {
        return $this->eventsPerLeg;
    }

    /**
     * Get the number of rounds in each leg.
     */
    public function getRoundsPerLeg(): int
    {
        return $this->roundsPerLeg;
    }

    /**
     * Check if the strategy requires randomization.
     */
    public function requiresRandomization(): bool
    {
        return $this->requiresRandomization;
    }

    /**
     * Get strategy-specific data.
     * @return array<string, mixed>
     */
    public function getStrategyData(): array
    {
        return $this->strategyData;
    }

    /**
     * Get a specific piece of strategy data.
     */
    public function getStrategyValue(string $key, mixed $default = null): mixed
    {
        return $this->strategyData[$key] ?? $default;
    }

    /**
     * Get any warnings about the generation plan.
     * @return array<string>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Check if the plan has any warnings.
     */
    public function hasWarnings(): bool
    {
        return !empty($this->warnings);
    }
}
