<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

use MissionGaming\Tactician\Exceptions\InvalidInputException;

/**
 * How many participants a progression selector yields, as the selector
 * states it ahead of any outcome (ProgressionSelector::getYield()).
 *
 * A selector yields either a count of its own, a count for each pool, a
 * count for each pool and a count on top, the winners or the losers of the
 * last round of the stage before, or a number only the outcome gives. A
 * yield can also state the fewest pools the selection can be made from
 * (getMinimumPools()). countFrom() turns the statement into a number for
 * a stage before of a given shape, which is what CompositionValidator
 * checks a chain with; an application that validates a composition of its
 * own reads the same statement.
 *
 * @experimental
 */
final readonly class SelectionYield
{
    private function __construct(
        private SelectionYieldKind $kind,
        private ?int $count,
        private ?int $addedCount = null,
        private int $minimumPools = 1
    ) {}

    /**
     * The selector yields this many, whatever the outcome.
     *
     * @throws InvalidInputException When the count is below 0 (unchecked)
     */
    public static function fixed(int $count): self
    {
        return new self(SelectionYieldKind::Fixed, self::counted($count));
    }

    /**
     * The selector yields this many from each pool of the stage before.
     *
     * @throws InvalidInputException When the count is below 0 (unchecked)
     */
    public static function perPool(int $count): self
    {
        return new self(SelectionYieldKind::PerPool, self::counted($count));
    }

    /**
     * The selector yields this many from each pool of the stage before and
     * $added more, taken across the pools; and it cannot be made from
     * fewer than $minimumPools pools (BracketQualifiersSelector: the top
     * of every pool and the best few of the next rank, which come from as
     * many pools and are kept apart from their pools in a bracket).
     *
     * @throws InvalidInputException When a count is below 0 or the minimum is below 1 (unchecked)
     */
    public static function perPoolPlus(int $perPool, int $added, int $minimumPools = 1): self
    {
        if ($minimumPools < 1) {
            throw new InvalidInputException("A selection is made from at least 1 pool, {$minimumPools} given");
        }

        return new self(SelectionYieldKind::PerPoolPlusFixed, self::counted($perPool), self::counted($added), $minimumPools);
    }

    /**
     * The selector yields the winners of the last round of the stage
     * before, then its byes.
     */
    public static function winnersOfLastRound(): self
    {
        return new self(SelectionYieldKind::WinnersOfLastRound, null);
    }

    /**
     * The selector yields the losers of the last round of the stage before.
     */
    public static function losersOfLastRound(): self
    {
        return new self(SelectionYieldKind::LosersOfLastRound, null);
    }

    /**
     * The selector cannot say how many it yields before it sees the outcome.
     */
    public static function dependsOnOutcome(): self
    {
        return new self(SelectionYieldKind::DependsOnOutcome, null);
    }

    /**
     * What the yield depends on.
     */
    public function getKind(): SelectionYieldKind
    {
        return $this->kind;
    }

    /**
     * The count the selector states: the whole yield for Fixed, the yield of
     * one pool for PerPool and PerPoolPlusFixed, and null for every other
     * kind.
     */
    public function getCount(): ?int
    {
        return $this->count;
    }

    /**
     * The count taken across the pools on top of the count of each pool,
     * for PerPoolPlusFixed; null for every other kind.
     */
    public function getAddedCount(): ?int
    {
        return $this->addedCount;
    }

    /**
     * The fewest pools of the stage before the selection can be made from:
     * the selector refuses an outcome of fewer. 1 unless the yield states
     * more, which only perPoolPlus() can.
     */
    public function getMinimumPools(): int
    {
        return $this->minimumPools;
    }

    /**
     * The number of participants yielded from a stage before of the given
     * shape, or null where the shape given does not decide it.
     *
     * - Fixed: the count.
     * - PerPool: the count times the pools; null when the pools are not
     *   given.
     * - PerPoolPlusFixed: the count times the pools, plus the added count;
     *   null when the pools are not given, or are fewer than the minimum
     *   (getMinimumPools()), from which the selection is not made.
     * - WinnersOfLastRound and LosersOfLastRound: after one knockout round
     *   of all the entrants, as the elimination engines pair the first
     *   round of a bracket: the field is filled with byes up to the next
     *   power of two, so the winners are half of that power (the winner of
     *   each tie and every entrant with a bye) and the losers are the
     *   entrants beyond that half (one for each tie). 5 entrants play 1 tie
     *   and have 3 byes: 4 winners and 1 loser. Where the field is a power
     *   of two, that is half of it each. After a whole bracket, whose last
     *   round is the final, 1, whatever the size of the field.
     * - DependsOnOutcome: null.
     *
     * @param int $sourceEntrants The number of entrants of the stage before
     * @param int|null $sourcePools The number of pools the stage before is played in; null
     *                              when not known
     * @param bool $afterWholeBracket Whether the stage before plays a whole elimination bracket
     *                                down to its final, and not one knockout round
     * @throws InvalidInputException When the pools are given and fewer than 1, whatever the kind:
     *                               a stage is played in at least 1 (unchecked: a mistake in the
     *                               calling code; CompositionValidator reports such a count and
     *                               does not pass it on)
     */
    public function countFrom(int $sourceEntrants, ?int $sourcePools = null, bool $afterWholeBracket = false): ?int
    {
        if ($sourcePools !== null && $sourcePools < 1) {
            throw new InvalidInputException("A stage is played in at least 1 pool, {$sourcePools} given");
        }

        return match ($this->kind) {
            SelectionYieldKind::Fixed => $this->count,
            SelectionYieldKind::PerPool => $sourcePools === null || $this->count === null
                ? null
                : $this->count * $sourcePools,
            SelectionYieldKind::PerPoolPlusFixed => $sourcePools === null || $sourcePools < $this->minimumPools || $this->count === null
                ? null
                : $this->count * $sourcePools + (int) $this->addedCount,
            SelectionYieldKind::WinnersOfLastRound => $afterWholeBracket ? 1 : $sourceEntrants - self::tiesOfFirstRound($sourceEntrants),
            SelectionYieldKind::LosersOfLastRound => $afterWholeBracket ? 1 : self::tiesOfFirstRound($sourceEntrants),
            SelectionYieldKind::DependsOnOutcome => null,
        };
    }

    /**
     * The ties of the first round of a bracket of this many entrants, as
     * the elimination engines pair it: the entrants beyond the half of the
     * next power of two meet as many of the others, and the rest have a
     * bye. A field of fewer than 2 has no tie.
     */
    private static function tiesOfFirstRound(int $entrants): int
    {
        if ($entrants < 2) {
            return 0;
        }

        // Half the next power of two, found without forming that power,
        // which is beyond the integers for a field of more than 2^62
        $half = 1;
        while ($half < $entrants - $half) {
            $half *= 2;
        }

        return $entrants - $half;
    }

    /**
     * @throws InvalidInputException When the count is below 0
     */
    private static function counted(int $count): int
    {
        if ($count < 0) {
            throw new InvalidInputException("A selection yields 0 participants or more, {$count} given");
        }

        return $count;
    }
}
