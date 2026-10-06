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
 * The budget also records whether it ever stopped a search
 * ({@see self::stoppedASearch()}), which the outcome reports. Both ways a
 * search learns that the budget is gone set the record: consume()
 * refusing, and isExhausted() answering true. Every caller asks
 * isExhausted() only while it still has something to search, so a true
 * answer is always a search cut short; a caller that asks for another
 * purpose must not use isExhausted().
 *
 * @internal
 */
final class StepBudget
{
    private int $remaining;

    private bool $stoppedASearch = false;

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
            $this->stoppedASearch = true;

            return false;
        }

        $this->remaining -= $steps;

        return true;
    }

    /**
     * Whether no step is left. Asked by a search that would otherwise go
     * on, so a true answer is recorded as a search the budget stopped.
     */
    public function isExhausted(): bool
    {
        if ($this->remaining <= 0) {
            $this->stoppedASearch = true;

            return true;
        }

        return false;
    }

    /**
     * Whether a step is left, read without recording anything: for a
     * caller that wants to know before it does work it may not need, and
     * that has not yet reached a search the budget could stop. Deciding to
     * stop a search goes through isExhausted(), which records it.
     */
    public function hasStepsLeft(): bool
    {
        return $this->remaining > 0;
    }

    /**
     * Whether the budget has stopped a search at any point: a step was
     * refused, or a search asked whether to go on and was told no. Spending
     * the last step on a search that then finishes without asking for
     * another does not count.
     */
    public function stoppedASearch(): bool
    {
        return $this->stoppedASearch;
    }
}
