<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Repack\Internal;

/**
 * The shared elementary-step counter bounding every repack search.
 *
 * A repack makes two instances, each of RepackOptions::$stepBudget
 * steps, so a whole repack spends at most twice the budget. The first is
 * threaded through the load planning and the packing of every session,
 * and bounds them together; the exact search of one session is given a
 * share of what is left of it (share()), so that one session cannot
 * spend it all. The second is for the steps after the packing: the last
 * placement step (LeftoverRecolourer) and the search for fewer gap slots
 * (GapSearch), so that they run even when the phases before them spent
 * the first. Steps, not wall clock: behaviour is reproducible.
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
     * A budget of its own for one search: a share of the steps that are
     * left (one in $divisor of them, and never none while a step is
     * left), so that a search that does not end cannot take every step
     * from the searches after it. Give it back with returnShare() when the
     * search is over.
     */
    public function share(int $divisor): self
    {
        return new self($this->remaining > 0 ? max(1, intdiv($this->remaining, $divisor)) : 0);
    }

    /**
     * Take back a share: the steps it spent are spent here, and a search
     * it stopped is a search this budget stopped.
     *
     * @param int $steps How many steps the share was made with
     */
    public function returnShare(self $share, int $steps): void
    {
        $this->remaining -= $steps - $share->remaining;
        $this->stoppedASearch = $this->stoppedASearch || $share->stoppedASearch;
    }

    /**
     * How many steps are left, for the caller that hands out a share and
     * has to say later how large it was.
     */
    public function stepsLeft(): int
    {
        return $this->remaining;
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
