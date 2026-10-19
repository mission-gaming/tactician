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
     *   of all the entrants, half of them, rounded up for the winners (the
     *   bye goes on) and down for the losers; after a whole bracket, whose
     *   last round is the final, 1, whatever the size of the field.
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
            SelectionYieldKind::WinnersOfLastRound => $afterWholeBracket ? 1 : intdiv($sourceEntrants + 1, 2),
            SelectionYieldKind::LosersOfLastRound => $afterWholeBracket ? 1 : intdiv($sourceEntrants, 2),
            SelectionYieldKind::DependsOnOutcome => null,
        };
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
