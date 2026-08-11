<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Repack\Internal;

/**
 * The shared elementary-step counter bounding every repack search.
 *
 * One budget instance is threaded through all phases, so the documented
 * RepackOptions::$stepBudget is a genuine whole-run bound. Steps, not
 * wall clock: behaviour is reproducible.
 *
 * @internal
 */
final class StepBudget
{
    private int $remaining;

    public function __construct(int $steps)
    {
        $this->remaining = $steps;
    }

    /**
     * Spend steps. Returns false once the budget is exhausted — callers
     * abandon their search and fall back to reporting what is left.
     */
    public function consume(int $steps = 1): bool
    {
        if ($this->remaining < $steps) {
            $this->remaining = 0;

            return false;
        }

        $this->remaining -= $steps;

        return true;
    }

    public function isExhausted(): bool
    {
        return $this->remaining <= 0;
    }
}
