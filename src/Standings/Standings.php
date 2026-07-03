<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Standings;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use MissionGaming\Tactician\DTO\Participant;
use Override;

/**
 * An ordered standings table, best-placed participant first.
 *
 * @implements IteratorAggregate<int, StandingEntry>
 */
readonly class Standings implements IteratorAggregate, Countable
{
    /**
     * @param array<StandingEntry> $entries Entries ordered best-first
     */
    public function __construct(private array $entries)
    {
    }

    /**
     * @return array<StandingEntry>
     */
    public function getEntries(): array
    {
        return $this->entries;
    }

    public function getEntryFor(Participant $participant): ?StandingEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->getParticipant()->getId() === $participant->getId()) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Get a participant's 1-based position in the table, or null if absent.
     */
    public function getPosition(Participant $participant): ?int
    {
        foreach ($this->entries as $index => $entry) {
            if ($entry->getParticipant()->getId() === $participant->getId()) {
                return $index + 1;
            }
        }

        return null;
    }

    /**
     * @return ArrayIterator<int, StandingEntry>
     */
    #[Override]
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator(array_values($this->entries));
    }

    #[Override]
    public function count(): int
    {
        return count($this->entries);
    }
}
