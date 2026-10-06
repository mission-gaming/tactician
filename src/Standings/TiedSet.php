<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Standings;

use Countable;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\InvalidInputException;
use Override;

/**
 * Two or more adjacent entries of a standings table that no result separates,
 * with the positions they occupy.
 *
 * The entries of a tied set are level with each other
 * ({@see StandingEntry::isLevelWith()}): the table lists them in the order of
 * its final fallback (seed, then label, then participant ID), which says
 * nothing about how they performed. An application that must not act on such
 * an order (who advances, who is relegated, who is seeded where) reads the
 * tied sets from {@see Standings::getTiedSets()} and decides the tie by its
 * own rule: a play-off, a drawing of lots, a shared rank.
 *
 * A set of three entries whose first position is 2 spans positions 2, 3 and
 * 4. Positions are the 1-based positions of {@see Standings::getPosition()}.
 *
 * @experimental
 */
final readonly class TiedSet implements Countable
{
    /** @var list<StandingEntry> */
    private array $entries;

    /**
     * @param array<StandingEntry> $entries The level entries in table order, at least two
     * @param int $firstPosition The 1-based table position of the first entry
     *
     * @throws InvalidInputException When there are fewer than two entries or the
     *                               first position is below 1
     */
    public function __construct(array $entries, private int $firstPosition)
    {
        if (count($entries) < 2) {
            throw new InvalidInputException('A tied set needs at least 2 entries');
        }

        if ($firstPosition < 1) {
            throw new InvalidInputException('The first position of a tied set must be 1 or higher');
        }

        $this->entries = array_values($entries);
    }

    /**
     * The level entries in table order: the first is the one the table
     * places at {@see getFirstPosition()}.
     *
     * @return list<StandingEntry>
     */
    public function getEntries(): array
    {
        return $this->entries;
    }

    /**
     * The participants of the level entries, in table order.
     *
     * @return list<Participant>
     */
    public function getParticipants(): array
    {
        return array_map(
            static fn(StandingEntry $entry): Participant => $entry->getParticipant(),
            $this->entries
        );
    }

    /**
     * The 1-based table position of the first entry of the set.
     */
    public function getFirstPosition(): int
    {
        return $this->firstPosition;
    }

    /**
     * The 1-based table position of the last entry of the set.
     */
    public function getLastPosition(): int
    {
        return $this->firstPosition + count($this->entries) - 1;
    }

    /**
     * The number of level entries, always two or more.
     */
    #[Override]
    public function count(): int
    {
        return count($this->entries);
    }
}
