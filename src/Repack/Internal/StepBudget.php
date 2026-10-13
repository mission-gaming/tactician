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
 * the first. The third schedule the repacker makes, where the first two
 * leave something to gain, is made on what the two have left. Steps, not
 * wall clock: behaviour is reproducible.
 *
 * A step is not a fixed amount of work: one step of the last placement
 * step or of the search for fewer gap slots looks at every position, the
 * round layout (RoundColouring) runs before any step is spent, and the
 * planner's search for the events two over-capacity participants share
 * (LoadPlanner::sharedDrops()) spends none. Those searches count their
 * own work (position checks, or nodes) against a limit of their own, and
 * the budget governs that limit too: workLimit() gives a search its
 * whole limit when the caller's budget is DEFAULT_STEPS or more, and the
 * same share of it as the caller's budget is of DEFAULT_STEPS when it is
 * less. So the default budget and any larger one give every such search
 * exactly the work it has always had, and a smaller one shortens all of
 * them in proportion. A repack makes a third instance for the round
 * layout, with steps that never run out and the caller's budget for the
 * limits. The order of the participants (StructuralOrder) has a work
 * limit too and is given no budget: see there for why.
 *
 * The budget also records whether it ever stopped a search
 * ({@see self::stoppedASearch()}), which the outcome reports. Three ways
 * a search learns that it has to stop set the record: consume()
 * refusing, isExhausted() answering true, and the search reporting that
 * it reached a work limit this budget made smaller
 * (stoppedAtWorkLimit()). Every caller asks isExhausted() only while it
 * still has something to search, so a true answer is always a search cut
 * short; a caller that asks for another purpose must not use
 * isExhausted(). A search that reaches its whole work limit is not
 * recorded: no larger budget lifts that limit, so no larger budget would
 * have taken the search further.
 *
 * @internal
 */
final class StepBudget
{
    /**
     * The caller's budget at and above which every work limit is whole:
     * the default of RepackOptions::$stepBudget, which the limits were
     * sized with (a test holds the two equal).
     */
    public const int DEFAULT_STEPS = 200_000;

    private int $remaining;

    private bool $stoppedASearch = false;

    /**
     * @param int $steps How many steps there are to spend
     * @param int $callersBudget The RepackOptions::$stepBudget the work limits
     *                           follow (workLimit()). The repacker passes it;
     *                           left out, every limit is whole, which is what
     *                           the unit tests of the searches want when they
     *                           hand a search a few steps
     */
    public function __construct(int $steps, private readonly int $callersBudget = self::DEFAULT_STEPS)
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
        return new self($this->remaining > 0 ? max(1, intdiv($this->remaining, $divisor)) : 0, $this->callersBudget);
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
     * The work a search may do under this budget, given the limit it has
     * at the default budget: all of it when the caller's budget is
     * DEFAULT_STEPS or more, and otherwise the share of it that the
     * caller's budget is of DEFAULT_STEPS, rounded down. A budget of one
     * step leaves next to nothing, and the search then stops at its
     * first look at the limit.
     *
     * @param int $whole The limit at the default budget; at most a few
     *                   million, so the product below is an integer
     */
    public function workLimit(int $whole): int
    {
        if ($this->callersBudget >= self::DEFAULT_STEPS) {
            return $whole;
        }

        return intdiv($whole * $this->callersBudget, self::DEFAULT_STEPS);
    }

    /**
     * Told by a search that it stopped at its work limit while it still
     * had something to search. Recorded as a search the budget stopped
     * when the budget made that limit smaller than the whole one, because
     * a larger budget would then have let the search go on; at the whole
     * limit nothing is recorded, because no budget lifts it.
     */
    public function stoppedAtWorkLimit(): void
    {
        if ($this->callersBudget < self::DEFAULT_STEPS) {
            $this->stoppedASearch = true;
        }
    }

    /**
     * Whether the budget has stopped a search at any point: a step was
     * refused, a search asked whether to go on and was told no, or a
     * search reached a work limit this budget had made smaller. Spending
     * the last step on a search that then finishes without asking for
     * another does not count, and neither does a search that reaches its
     * whole work limit.
     */
    public function stoppedASearch(): bool
    {
        return $this->stoppedASearch;
    }
}
