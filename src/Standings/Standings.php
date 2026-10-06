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
 *
 * @experimental
 */
readonly class Standings implements IteratorAggregate, Countable
{
    /**
     * @param array<StandingEntry> $entries Entries ordered best-first
     */
    public function __construct(private array $entries) {}

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
     * The tied sets of the table: each group of adjacent entries that no
     * result separates, with the positions it spans, in table order.
     *
     * The table always gives every entry a position of its own. Where two or
     * more entries are level ({@see StandingEntry::isLevelWith()}: the same
     * ranking value, the same value for every tiebreaker, the same score
     * difference and the same scores-for), their order comes from the final
     * fallback alone (seed, then label, then participant ID) and reflects no
     * result. Each such group is one tied set. A set is as large as it can
     * be, no entry is in two sets, and an entry level with no neighbour is in
     * none. A table in which results decide every position returns an empty
     * list; a table with no results is one tied set of every entry.
     *
     * Read this before acting on a position: a tied set whose positions
     * straddle a cut (the last place that advances, say) means the results
     * do not say who is on which side of it.
     *
     * Reading the tied sets changes nothing: the entries and their order are
     * those of {@see getEntries()}. In a table built by `StandingsCalculator`
     * level entries are always adjacent. A table constructed by hand is read
     * as it is given: only adjacent level entries form a set.
     *
     * @return list<TiedSet>
     */
    public function getTiedSets(): array
    {
        $entries = array_values($this->entries);
        $tiedSets = [];
        $start = 0;

        foreach ($entries as $index => $entry) {
            $next = $entries[$index + 1] ?? null;
            if ($next !== null && $entry->isLevelWith($next)) {
                continue;
            }

            if ($index > $start) {
                $tiedSets[] = new TiedSet(array_slice($entries, $start, $index - $start + 1), $start + 1);
            }
            $start = $index + 1;
        }

        return $tiedSets;
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
