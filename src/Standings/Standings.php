<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Standings;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use MissionGaming\Tactician\DTO\Decider;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\InvalidInputException;
use MissionGaming\Tactician\Exceptions\RejectedDecisionException;
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
 * A table can carry decisions: the order of a tied set, settled outside
 * the results by the application (withDecisions()). The entries of such a
 * set are in the order of its decision, and the table says of every
 * position whether a decision settled it (getDecisionFor()).
 *
 * @implements IteratorAggregate<int, StandingEntry>
 *
 * @experimental
 */
readonly class Standings implements IteratorAggregate, Countable
{
    /** @var array<StandingEntry> */
    private array $entries;

    /** @var list<int> */
    private array $tiers;

    /** @var list<Decider> In table order */
    private array $decisions;

    /**
     * The entries are kept as given and are not sorted, with one exception:
     * the entries of a tied set that a decision names are put in the order
     * of that decision, in the places the set holds.
     *
     * @param array<StandingEntry> $entries Entries ordered best-first. The order of the array
     *                                      is the order of the table; its keys decide nothing
     *                                      (getEntries() returns them as given)
     * @param array<int> $tiers The tier of each entry, in the order of the entries and
     *                          never falling from one entry to the next; empty for a
     *                          table without tiers
     * @param array<Decider> $decisions The decisions that settle tied sets of the table, in any
     *                                  order, under the rules of withDecisions(); empty for a
     *                                  table without any
     *
     * @throws InvalidInputException When tiers are given and there is not one for each entry,
     *                               or a tier is lower than the one before it
     * @throws RejectedDecisionException When a decision does not order one whole tied set of the
     *                                   table, or two decisions name one participant
     */
    public function __construct(array $entries, array $tiers = [], array $decisions = [])
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

        if ($decisions === []) {
            $this->entries = $entries;
            $this->decisions = [];

            return;
        }

        // The keys stay at the places they were given at; the entries of a
        // decided set change places among themselves
        $settled = DecidedOrder::apply(self::levelGroups(array_values($entries), $tiers), $decisions);
        $inOrder = array_merge(...$settled['groups']);
        $this->entries = array_combine(array_keys($entries), $inOrder);
        $this->decisions = array_values($settled['decided']);
    }

    /**
     * The entries as groups of adjacent level entries of one tier, in
     * table order: a group of one is an entry that results place.
     *
     * @param list<StandingEntry> $entries
     * @param list<int> $tiers
     * @return list<list<StandingEntry>>
     */
    private static function levelGroups(array $entries, array $tiers): array
    {
        $groups = [];
        $group = [];
        foreach ($entries as $index => $entry) {
            $group[] = $entry;

            $next = $entries[$index + 1] ?? null;
            if (
                $next === null
                || ($tiers[$index] ?? 0) !== ($tiers[$index + 1] ?? 0)
                || !$entry->isLevelWith($next)
            ) {
                $groups[] = $group;
                $group = [];
            }
        }

        return $groups;
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
     * The position is the entry's place in the order of the table, as
     * getTiedSets() and iteration count it; the array keys the table was
     * built with are not read. Positions are never shared: two entries that
     * no result separates have consecutive positions (see getTiedSets()).
     */
    public function getPosition(Participant $participant): ?int
    {
        $position = 0;
        foreach ($this->entries as $entry) {
            ++$position;
            if ($entry->getParticipant()->getId() === $participant->getId()) {
                return $position;
            }
        }

        return null;
    }

    /**
     * The 1-based rank of the participant with this ID, or null when the
     * table has no entry for it.
     *
     * A rank is shared where results do not decide: every entry of a tied
     * set (getTiedSets()) has the rank of the set's first position, and
     * the entry after the set has the rank of its own position. Three
     * entries level behind a leader are ranked 1, 2, 2, 2, and the next is
     * ranked 5. An entry in no tied set has its position as its rank.
     *
     * Positions are never shared (getPosition()); ranks say which
     * positions the results do decide. The rank of a tied set stays the
     * same when its entries are put in another order
     * (withTiedSetsOrderedBy()).
     */
    public function getRank(Participant $participant): ?int
    {
        $position = $this->getPosition($participant);

        return $position === null ? null : $this->getRanks()[$position - 1];
    }

    /**
     * The rank of each entry, in table order: shared by the entries of a
     * tied set, as getRank() describes. A list as long as the table, never
     * falling, in which a number that is in it n times is followed by one
     * at least n higher.
     *
     * @return list<int>
     */
    public function getRanks(): array
    {
        $shared = [];
        foreach ($this->getTiedSets() as $tiedSet) {
            for ($position = $tiedSet->getFirstPosition(); $position <= $tiedSet->getLastPosition(); ++$position) {
                $shared[$position] = $tiedSet->getFirstPosition();
            }
        }

        $ranks = [];
        for ($position = 1, $last = count($this->entries); $position <= $last; ++$position) {
            $ranks[] = $shared[$position] ?? $position;
        }

        return $ranks;
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
     * A set whose order a decision settled is still reported: its entries
     * are level, and no result orders them. {@see TiedSet::getDecision()}
     * tells it from a set that is still undecided.
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
                $tiedSets[] = new TiedSet(
                    array_slice($entries, $start, $index - $start + 1),
                    $start + 1,
                    $this->decisions === [] ? null : $this->getDecisionFor($entries[$start]->getParticipant())
                );
            }
            $start = $index + 1;
        }

        return $tiedSets;
    }

    /**
     * The table with the given decisions applied: the entries of each
     * tied set a decision names are in the order of that decision, and the
     * table carries the decision as the mark of those positions.
     *
     * A decision is a `DTO\Decider`: the IDs of the participants of one
     * tied set, best first, with an optional label and reason that the
     * library stores and never reads. It says that the order was settled
     * outside the results and never how.
     *
     * **A decision never overrides a result.** It orders one whole tied
     * set ({@see getTiedSets()}) and nothing else: the set keeps the
     * positions it spans, every entry outside it stays where it is, and no
     * entry passes one it is not level with or leaves its tier. A decision
     * is rejected, and the table is not changed, when it
     *
     * - names a participant the table has no entry for (`UnknownParticipant`);
     * - names a participant another decision names as well, one given in
     *   the same call or one the table already carries
     *   (`SetAlreadyDecided`);
     * - names participants that are not level with each other, or one
     *   participant that is level with nobody (`ParticipantsNotLevel`);
     * - names only some of the entries of a tied set (`PartOfLevelSet`):
     *   a decision orders the whole set, also where only its first places
     *   matter to the caller, so that the table has one order whatever is
     *   read from it.
     *
     * The decisions given are added to those the table carries. To change
     * a decision, apply the new one to the table as it was calculated.
     * The result is the same for every order the decisions are given in.
     *
     * Apply decisions last, after everything that changes a ranking value:
     * `StandingsCalculator::withAdjustments()` and `withByesCredited()`
     * refuse a table whose decisions no longer match it (`Stale`).
     * {@see withTiedSetsOrderedBy()} leaves a decided set as it is.
     *
     * @param array<Decider> $decisions One for each tied set to settle, in any order
     *
     * @throws RejectedDecisionException When a decision is rejected; its reason says why
     */
    public function withDecisions(array $decisions): self
    {
        if ($decisions === []) {
            return $this;
        }

        return new self($this->entries, $this->tiers, [...$this->decisions, ...array_values($decisions)]);
    }

    /**
     * The decisions the table carries, in table order: the decision of the
     * highest-placed decided set first. Empty for a table in which no
     * position was settled by a decision.
     *
     * @return list<Decider>
     */
    public function getDecisions(): array
    {
        return $this->decisions;
    }

    /**
     * The decision that settled the position of the participant with this
     * ID, or null when results placed it, when its tied set is undecided,
     * and when the table has no entry for it.
     *
     * A position with a decision was not decided by a result: show it as
     * such, with the label and the reason the decision carries.
     */
    public function getDecisionFor(Participant $participant): ?Decider
    {
        foreach ($this->decisions as $decision) {
            if (in_array($participant->getId(), $decision->getParticipantIds(), true)) {
                return $decision;
            }
        }

        return null;
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
     * A tied set that a decision settled keeps the order of its decision,
     * and the table keeps its decisions: the list decides only where
     * nothing else has.
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

        // The decisions go with the table, and the constructor puts every
        // decided set back in the order of its decision
        return new self($entries, $this->tiers, $this->decisions);
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
