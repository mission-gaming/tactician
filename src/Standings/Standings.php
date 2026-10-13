<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Standings;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\InvalidInputException;
use Override;

/**
 * An ordered standings table, best-placed participant first.
 *
 * The table holds the order it is given; StandingsCalculator is what
 * computes one. Every entry has a position of its own, also where no result
 * separates it from its neighbour: see getTiedSets().
 *
 * A table can have tiers: a criterion that orders it before any record is
 * compared. The table of an elimination stage has them. A tier there is the
 * round an entrant went out in, so the entrant who won the final is alone in
 * the first tier, the one it beat alone in the second, and the two who lost
 * the semifinals share the third. Entries of different tiers are never
 * level, whatever their records.
 *
 * @implements IteratorAggregate<int, StandingEntry>
 *
 * @experimental
 */
readonly class Standings implements IteratorAggregate, Countable
{
    /** @var list<int> */
    private array $tiers;

    /**
     * The entries are kept as given and are not sorted.
     *
     * @param array<StandingEntry> $entries Entries ordered best-first. Pass a list (keys 0,
     *                                      1, ...): getPosition() reads a position from the key
     * @param array<int> $tiers The tier of each entry, in the order of the entries and
     *                          never falling from one entry to the next; empty for a
     *                          table without tiers
     *
     * @throws InvalidInputException When tiers are given and there is not one for each entry,
     *                               or a tier is lower than the one before it
     */
    public function __construct(private array $entries, array $tiers = [])
    {
        $tiers = array_values($tiers);

        if ($tiers !== []) {
            if (count($tiers) !== count($entries)) {
                throw new InvalidInputException('A standings table with tiers needs one tier for each entry');
            }

            foreach ($tiers as $index => $tier) {
                if ($index > 0 && $tier < $tiers[$index - 1]) {
                    throw new InvalidInputException('The tiers of a standings table must not fall from one entry to the next');
                }
            }
        }

        $this->tiers = $tiers;
    }

    /**
     * Every entry in table order, best-placed first.
     *
     * @return array<StandingEntry>
     */
    public function getEntries(): array
    {
        return $this->entries;
    }

    /**
     * The entry of the participant with this ID, or null when the table has
     * none.
     */
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
     * The 1-based position of the participant with this ID, or null when the
     * table has no entry for it.
     *
     * Positions are never shared: two entries that no result separates have
     * consecutive positions (see getTiedSets()).
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
     * difference and the same scores-for; and, in a table with tiers, the
     * same tier), their order reflects no result: it is the order of the
     * entrant list in the table of a stage, and the calculator's fallback
     * (seed, then participant ID) in a table calculated on its own. Each
     * such group is one tied set. A set is as large as it can be, no entry
     * is in two sets, and an entry level with no neighbour is in none. A
     * table in which results decide every position returns an empty list; a
     * table with no results is one tied set of every entry.
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
            if (
                $next !== null
                && ($this->tiers[$index] ?? 0) === ($this->tiers[$index + 1] ?? 0)
                && $entry->isLevelWith($next)
            ) {
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
     * The tier of each entry, in table order: the same number for entries
     * of one tier, rising down the table. Empty for a table without tiers
     * (see the class docblock). The numbers say which entries share a tier
     * and nothing else.
     *
     * @return list<int>
     */
    public function getTiers(): array
    {
        return $this->tiers;
    }

    /**
     * The table with the entries of every tied set in the order of the
     * given entrant list: where results do not decide between entries, the
     * position in the list does.
     *
     * Only the order inside a tied set changes. Every entry keeps the
     * positions its set spans, the sets are the same sets, and an entry
     * that results place is where it was. Of the entries of one set, those
     * whose participant is in the list come first, in list order; the
     * others follow in the order they had. Participants are matched by ID.
     *
     * The engines do this to every table they build, with the participant
     * list of the stage. Do it to a table of your own when its participants
     * are an entrant list whose order means something:
     *
     *     $table = $calculator->calculate($pool, $results)->withTiedSetsOrderedBy($pool);
     *
     * @param array<Participant> $entrants The entrant list; position 1 first
     */
    public function withTiedSetsOrderedBy(array $entrants): self
    {
        $positions = [];
        foreach (array_values($entrants) as $position => $entrant) {
            $positions[$entrant->getId()] ??= $position;
        }

        $entries = array_values($this->entries);
        foreach ($this->getTiedSets() as $tiedSet) {
            $members = $tiedSet->getEntries();
            $order = [];
            foreach ($members as $index => $member) {
                $order[$index] = [$positions[$member->getParticipant()->getId()] ?? PHP_INT_MAX, $index];
            }
            asort($order);

            $offset = $tiedSet->getFirstPosition() - 1;
            foreach (array_keys($order) as $index) {
                $entries[$offset++] = $members[$index];
            }
        }

        return new self($entries, $this->tiers);
    }

    /**
     * The entries in table order, keyed from 0: the key is the position
     * minus one.
     *
     * @return ArrayIterator<int, StandingEntry>
     */
    #[Override]
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator(array_values($this->entries));
    }

    /**
     * The number of entries.
     */
    #[Override]
    public function count(): int
    {
        return count($this->entries);
    }
}
