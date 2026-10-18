<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

use MissionGaming\Tactician\Exceptions\InvalidInputException;

/**
 * How many participants a progression selector yields, as the selector
 * states it ahead of any outcome (ProgressionSelector::getYield()).
 *
 * A selector yields either a count of its own, a count for each pool, the
 * winners or the losers of the last round of the stage before, or a number
 * only the outcome gives. countFrom() turns the statement into a number for
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
        private ?int $count
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
     * one pool for PerPool, and null for every other kind.
     */
    public function getCount(): ?int
    {
        return $this->count;
    }

    /**
     * The number of participants yielded from a stage before of the given
     * shape, or null where the shape given does not decide it.
     *
     * - Fixed: the count.
     * - PerPool: the count times the pools; null when the pools are not
     *   given.
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
     */
    public function countFrom(int $sourceEntrants, ?int $sourcePools = null, bool $afterWholeBracket = false): ?int
    {
        return match ($this->kind) {
            SelectionYieldKind::Fixed => $this->count,
            SelectionYieldKind::PerPool => $sourcePools === null || $this->count === null
                ? null
                : $this->count * $sourcePools,
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
