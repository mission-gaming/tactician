<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

use MissionGaming\Tactician\DTO\Decider;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Exceptions\RejectedDecisionException;
use MissionGaming\Tactician\Exceptions\RejectedDecisionReason;
use MissionGaming\Tactician\Exceptions\UndecidedSelectionException;
use MissionGaming\Tactician\Serialization\PlainData;
use MissionGaming\Tactician\Standings\Adjustment;
use MissionGaming\Tactician\Standings\DecidedOrder;
use MissionGaming\Tactician\Standings\StandingEntry;
use MissionGaming\Tactician\Standings\Standings;
use Override;

/**
 * Standings-based progression across pools: the best few of the entrants
 * that hold one rank in their pools, best first. With the top two of every
 * pool it makes the format "the top two of each pool and the two best of
 * those placed third".
 *
 * Who holds the rank in a pool is read from that pool's own table, as a
 * per-group rank selection reads it. The holders are then compared with
 * each other by the figures the outcome's own table (the combined table of
 * StageOutcome::combining()) has for them, in the order a table is ordered
 * by and all higher first: the ranking value, each tiebreaker of the
 * calculator that built that table, the score difference and scores-for.
 * The tiebreaker values are the ones the combined table holds, calculated
 * from every result of the stage: a tiebreaker that has a meaning only
 * among entrants who met each other (a head-to-head measure) has none
 * between entrants of different pools, so give combining() a calculator
 * without it; the pools' own tables may still use it. The combined table
 * credits no bye: a bye credited in a pool's own
 * table (StandingsCalculator::withByesCredited()) decides who holds the
 * rank there and is not in the figures the holders are compared by.
 *
 * **An adjustment is compared, under every method.** The ranking value
 * compared is the total the table is ordered by
 * (StandingEntry::getRankingValue()): what the entrant earned plus its
 * adjustment, which combining() carries over from the pool's table. An
 * adjustment changes that one figure and no other.
 *
 * - PoolComparison::RawTotals compares the total.
 * - PoolComparison::PerEvent divides the total by the events the entrant
 *   has played, the adjustment with what was earned: an adjustment worth
 *   one win is worth one win in the comparison too, and the methods still
 *   agree where the entrants have played the same number of events. An
 *   entrant that has played no event has nothing to divide by, and is
 *   compared by its adjustment as it stands.
 * - PoolComparison::DiscardLowest calculates the table again from the
 *   results that are left and applies again the adjustment of every entrant
 *   that is kept, in full: it comes from no event, so no result that is
 *   left out takes any of it away. An entrant that is left out goes with
 *   its adjustment.
 *
 * An awarded result (DTO\Result::isAwarded()) is a result under every
 * method: it is one of the events played that PerEvent divides by, and
 * DiscardLowest leaves it out or keeps it with the other results of the
 * two entrants, counted as the outcome's calculator counts it
 * (AwardedResultPolicy).
 *
 * **Pools of unequal size need a comparison method.** Pools are unequal
 * when their tables do not have the same number of entries; the entrants
 * have then played different numbers of events, and the method changes who
 * is selected (PoolComparison). None is applied by default: select()
 * refuses such an outcome until comparedBy() has said which (reason
 * `PoolComparisonRequired`). With pools of one size no method is needed,
 * the figures are compared as the table has them, and a method that was
 * chosen is still applied: raw totals and the discard change nothing
 * there, and per event played divides by what each entrant has played.
 * The selector counts entries, not events: it does not see that pools of
 * one size have played different numbers of events (a pool with events to
 * play, pools of different leg counts).
 *
 * **A selection is refused when results do not decide it**, as
 * RankRangeSelector refuses one, with an UndecidedSelectionException:
 *
 * - a pool has no recorded result, or the rank in a pool is held by level
 *   entries, so the results do not say who holds it (the exception names
 *   the pool);
 * - holders that are level on every compared figure hold places on both
 *   sides of the cut, so the results do not say who is selected, or are all
 *   selected, so the order they are listed in is undecided (the exception
 *   names the rank, getRankAcrossPools(), and the places among the
 *   holders);
 * - under PoolComparison::DiscardLowest, level entries of a larger pool
 *   are on both sides of the place below which its entrants are left out.
 *
 * Going on regardless is the same choice in the same two steps:
 * withTiesInsideSelectionAccepted() accepts level holders that are all
 * selected, and withTableOrderAccepted() accepts everything. Level holders
 * are then in the order of the outcome's entrant list
 * (StageOutcome::getEntrants()) when it has one, and otherwise in the
 * order of the outcome's table: position decides where results do not.
 *
 * The list is ordered best first and nothing else. It is not placed for a
 * bracket: appended to a per-group selection it can pair an entrant with
 * one of its own pool in the first round, because where the entrant has to
 * go depends on which pools the selected ones come from.
 *
 * @experimental
 */
final readonly class BestOfRankSelector implements ProgressionSelector
{
    /**
     * @param int $rank The 1-based rank whose holders are compared
     * @param int $count How many of them are selected
     * @param PoolComparison|null $comparison How pools of unequal size are compared; null for
     *                                        none chosen
     * @param 'refuse'|'accept-inside-selection'|'accept-table-order' $ties What the selection does
     *                                                                     where results do not
     *                                                                     decide an order
     * @param list<Decider> $decisions The decisions that settle level entries of the orders read
     */
    private function __construct(
        private int $rank,
        private int $count,
        private ?PoolComparison $comparison = null,
        private string $ties = SelectionTies::REFUSED,
        private array $decisions = []
    ) {}

    /**
     * The best $count of the entrants placed $rank in their pools:
     * ofRank(3, 2) is the two best third-placed entrants.
     *
     * @throws InvalidConfigurationException When the rank or the count is below 1 (reason
     *                                       `ValueOutOfRange`)
     */
    public static function ofRank(int $rank, int $count): self
    {
        if ($rank < 1 || $count < 1) {
            throw new InvalidConfigurationException(
                'The rank and the count of a best-of-rank selection must be at least 1',
                ['rank' => $rank, 'count' => $count],
                reason: InvalidConfigurationReason::ValueOutOfRange
            );
        }

        return new self($rank, $count);
    }

    /**
     * The same selection with a method to compare pools of unequal size
     * by. Without one such pools are refused; the class docblock says what
     * a method does with pools of one size.
     */
    public function comparedBy(PoolComparison $comparison): self
    {
        return new self($this->rank, $this->count, $comparison, $this->ties, $this->decisions);
    }

    /**
     * The same selection, accepting holders that no result separates when
     * all of them are selected: they are listed in the order of the
     * outcome's entrant list when it has one, and otherwise in the order
     * of its table.
     *
     * Who is selected is still decided by results alone: a pool with no
     * result, a rank that level entries of a pool hold, and level holders
     * of which only some would be selected, are refused as before.
     */
    public function withTiesInsideSelectionAccepted(): self
    {
        return new self($this->rank, $this->count, $this->comparison, SelectionTies::INSIDE_ACCEPTED, $this->decisions);
    }

    /**
     * The same selection, accepting every order as it stands: nothing is
     * refused for being undecided, an unplayed pool included. The holder
     * of the rank in a pool is the entry at that position of the pool's
     * table, and level holders are in the order of the outcome's entrant
     * list when it has one, and otherwise in the order of its table.
     *
     * This is the choice to let position decide who is selected.
     */
    public function withTableOrderAccepted(): self
    {
        return new self($this->rank, $this->count, $this->comparison, SelectionTies::ORDER_ACCEPTED, $this->decisions);
    }

    /**
     * The same selection with decisions that settle the order of level
     * entries, in place of any the selector had; an empty list for none.
     *
     * A decision is a `DTO\Decider`: the IDs of the level participants,
     * best first, with an optional label and reason that the library
     * stores and never reads. The selection reads two kinds of order, and
     * a decision is for one of them by the participants it names:
     *
     * - participants of one pool: the decision orders one whole tied set
     *   of that pool's table (Standings::withDecisions() has the rules),
     *   which settles who holds the rank there and, under
     *   PoolComparison::DiscardLowest, who is left out of a larger pool;
     * - participants of more than one pool: the decision orders one whole
     *   group of holders of the rank that the comparison leaves level,
     *   whatever the comparison method. It orders the holders among
     *   themselves and moves none of them past a holder it is not level
     *   with.
     *
     * A set that a decision settled is not refused, whichever tie policy
     * the selector has; every other undecided order is refused or accepted
     * as before, and a pool with no result is refused whatever decisions
     * are given. A decision that fits neither order is rejected by
     * select(), and nothing is selected. A pool's table can carry
     * decisions of its own; they count as given here. The decisions of the
     * outcome's own table are not read: the selection takes figures from
     * that table and no order, and a tied set of it is not a group of
     * level holders.
     *
     * @param array<Decider> $decisions One for each set of level entries to settle, in any order
     *
     * @throws InvalidConfigurationException When an entry of the list is not a Decider (reason
     *                                       `WrongValueType`)
     */
    public function withDecisions(array $decisions): self
    {
        return new self(
            $this->rank,
            $this->count,
            $this->comparison,
            $this->ties,
            SelectionTies::decisions($decisions, 'best-of-rank')
        );
    }

    /**
     * Build from plain configuration data: ['rank' => 3, 'count' => 2],
     * the form toArray() gives. `rank` and `count` are required integers.
     * `comparison` is 'raw-totals', 'per-event' or 'discard-lowest'
     * (PoolComparison), and left out or null for none chosen. `ties` is
     * 'refuse' (the default), 'accept-inside-selection' or
     * 'accept-table-order'. `decisions` is a list of deciders in their
     * array form (withDecisions()), and left out or null for none. Any
     * other key is refused, so that a misspelt `comparison` is not
     * silently read as none.
     *
     * @param array<string, mixed> $config
     * @throws InvalidConfigurationException When the format version is not one this release reads
     *                                       (reason `UnsupportedFormatVersion`), a key is unknown
     *                                       (`UnknownOptionKey`), `rank` or `count` is not an
     *                                       integer, `comparison` is not a string or `decisions`
     *                                       is not a list of decider arrays
     *                                       (`WrongValueType`), `comparison` or
     *                                       `ties` is not a known identifier (`UnknownIdentifier`),
     *                                       or the rank or the count is below 1
     *                                       (`ValueOutOfRange`)
     */
    public static function fromArray(array $config): self
    {
        PlainData::checkOptions($config, ['rank', 'count', 'comparison', 'ties', 'decisions'], 'a best-of-rank selector');

        $rank = $config['rank'] ?? null;
        $count = $config['count'] ?? null;
        if (!is_int($rank) || !is_int($count)) {
            throw new InvalidConfigurationException(
                'The rank and the count of a best-of-rank selector must be integers',
                ['rank' => $rank, 'count' => $count],
                reason: InvalidConfigurationReason::WrongValueType
            );
        }

        $comparison = CrossPoolRanking::comparisonFrom($config['comparison'] ?? null, 'best-of-rank');
        $ties = SelectionTies::policyFrom($config['ties'] ?? null, 'best-of-rank');
        $selector = self::ofRank($rank, $count);

        return new self(
            $selector->rank,
            $selector->count,
            $comparison,
            $ties,
            SelectionTies::decisionsFrom($config['decisions'] ?? null, 'best-of-rank')
        );
    }

    /**
     * The plain-data form fromArray() accepts: `format_version`, the
     * version of this form (1), then the 1-based rank and the count.
     * `comparison` is written only when a method was chosen, `ties` only
     * for a selector that accepts an undecided order, and `decisions` only
     * for one that was given decisions, each written as the array of a
     * decider without a format version of its own.
     *
     * @return array{format_version: int, rank: int, count: int, comparison?: string, ties?: string, decisions?: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        $data = [PlainData::VERSION_KEY => PlainData::VERSION, 'rank' => $this->rank, 'count' => $this->count];
        if ($this->comparison !== null) {
            $data['comparison'] = $this->comparison->value;
        }
        if ($this->ties !== SelectionTies::REFUSED) {
            $data['ties'] = $this->ties;
        }
        if ($this->decisions !== []) {
            $data['decisions'] = SelectionTies::decisionsData($this->decisions);
        }

        return $data;
    }

    /**
     * The best holders of the rank across the pools of the outcome, best
     * first, as many as the count.
     *
     * The list is the same for every order the pools, their entrants and
     * their results were given in, as long as results decide it. It always
     * has exactly as many participants as the count; an outcome that
     * cannot give that many, or does not decide which, is refused.
     *
     * Things are checked in this order, and the first that fails is
     * reported: the outcome has pools; every pool has the rank; there are
     * at least as many pools as the count; pools of unequal size have a
     * comparison method; every decision is for a pool or for the holders;
     * each pool's table, with its decisions applied, decides who holds the
     * rank; the comparison, with its decisions applied, decides who is
     * selected and in which order.
     *
     * The list is that of selection(), which also says which places a
     * decision settled.
     *
     * @return array<Participant>
     * @throws InvalidConfigurationException When the outcome has no pools, a holder of the rank
     *                                       has no entry in the outcome's own table, the tables
     *                                       carry different tiebreakers, or the discard is asked
     *                                       of pools of unequal size in an outcome that has no
     *                                       calculator (reason `IncompatibleOutcome`); a pool has
     *                                       fewer entries than the rank, or there are fewer pools
     *                                       than the count (`RankUnavailable`); or the pools are
     *                                       of unequal size and no comparison method was chosen
     *                                       (`PoolComparisonRequired`)
     * @throws UndecidedSelectionException When results do not decide the selection and the selector
     *                                     does not accept that (`UnplayedStage`,
     *                                     `TieAcrossSelection`, `TieWithinSelection`)
     * @throws RejectedDecisionException When a decision the selector was given does not order one
     *                                   whole tied set of a pool's table, or one whole group of
     *                                   level holders of the rank
     */
    #[Override]
    public function select(StageOutcome $outcome): array
    {
        return $this->selection($outcome)->getParticipants();
    }

    /**
     * The selection with its marks: the participants select() returns, in
     * the same order, and for each of them the decisions that settled its
     * place, when any did (RankSelection).
     *
     * A selected participant is marked with the decision that settled who
     * holds the rank in its pool, when it holds it as one of a decided
     * tied set, and with the decision that settled its place among the
     * holders, in that order. Under PoolComparison::DiscardLowest a
     * decision can also settle who is left out of a larger pool (a decided
     * tied set on both sides of the place below which its entrants are
     * left out). Every holder is then compared on figures calculated
     * without those entrants, so every selected participant is marked with
     * that decision, between the other two. A participant that results
     * placed is not marked, and neither is one of an undecided order that
     * the selector accepted.
     *
     * @throws InvalidConfigurationException As select()
     * @throws UndecidedSelectionException As select()
     * @throws RejectedDecisionException As select()
     */
    public function selection(StageOutcome $outcome): RankSelection
    {
        if (!$outcome->hasPools()) {
            throw new InvalidConfigurationException(
                'A best-of-rank selection requires a pooled outcome',
                ['rank' => $this->rank, 'count' => $this->count],
                reason: InvalidConfigurationReason::IncompatibleOutcome
            );
        }

        $sizes = CrossPoolRanking::poolSizes($outcome);
        foreach ($sizes as $label => $size) {
            if ($size < $this->rank) {
                throw new InvalidConfigurationException(
                    "Pool {$label} has no rank {$this->rank}",
                    ['pool' => (string) $label, 'rank' => $this->rank, 'pool_size' => $size],
                    reason: InvalidConfigurationReason::RankUnavailable
                );
            }
        }

        if (count($sizes) < $this->count) {
            throw new InvalidConfigurationException(
                sprintf(
                    'The best %d of the entrants placed %d cannot be selected from %d pool(s)',
                    $this->count,
                    $this->rank,
                    count($sizes)
                ),
                ['count' => $this->count, 'rank' => $this->rank, 'pools' => count($sizes)],
                reason: InvalidConfigurationReason::RankUnavailable
            );
        }

        ['smallest' => $smallest, 'unequal' => $unequal] = CrossPoolRanking::spread($sizes);
        if ($unequal && $this->comparison === null) {
            throw CrossPoolRanking::comparisonRequired($sizes);
        }

        $decisions = SelectionTies::byPool($outcome, $this->decisions, $this->rank);

        $holders = [];
        $leftOut = [];
        $marks = [];
        $cutDecisions = [];
        foreach ($outcome->getPools() as $label => $poolOutcome) {
            $standings = SelectionTies::decidedTable(
                $poolOutcome->getStandings(),
                $decisions['pools'][(string) $label] ?? [],
                (string) $label
            );
            SelectionTies::requireTableDecided(
                $standings,
                $poolOutcome->getResults() !== [],
                (string) $label,
                $this->rank,
                $this->rank,
                $this->ties
            );

            $entries = array_values($standings->getEntries());
            $holders[] = $holder = $entries[$this->rank - 1]->getParticipant();
            $marks += SelectionTies::marksIn($standings, [$holder]);

            if ($this->comparison === PoolComparison::DiscardLowest && count($entries) > $smallest) {
                $this->requireCutDecided($standings, (string) $label, $smallest);
                foreach (array_slice($entries, $smallest) as $entry) {
                    $leftOut[$entry->getParticipant()->getId()] = true;
                }

                // A decided set on both sides of the cut: its decision says
                // whose results are left out of every figure compared below
                $lastKept = $entries[$smallest - 1]->getParticipant();
                $decision = $standings->getDecisionFor($lastKept);
                if (
                    $decision instanceof Decider
                    && in_array($entries[$smallest]->getParticipant()->getId(), $decision->getParticipantIds(), true)
                ) {
                    $cutDecisions[] = $decision;
                }
            }
        }

        $table = $leftOut === [] ? $outcome->getStandings() : $this->tableWithout($outcome, $leftOut);

        $compared = [];
        foreach ($holders as $holder) {
            $entry = $table->getEntryFor($holder);
            if ($entry === null) {
                throw new InvalidConfigurationException(
                    "Participant {$holder->getId()} holds rank {$this->rank} of a pool and has no entry in the standings of the outcome",
                    ['participant' => $holder->getId(), 'rank' => $this->rank],
                    reason: InvalidConfigurationReason::IncompatibleOutcome
                );
            }
            $compared[] = $entry;
        }

        // A decision across pools orders holders of the rank and nobody else
        $holding = [];
        foreach ($holders as $holder) {
            $holding[$holder->getId()] = true;
        }
        foreach ($decisions['across'] as $decision) {
            $others = array_values(array_filter(
                $decision->getParticipantIds(),
                static fn(string $id): bool => !isset($holding[$id])
            ));
            if ($others !== []) {
                throw new RejectedDecisionException(
                    RejectedDecisionReason::ParticipantsNotLevel,
                    $decision,
                    $others,
                    null,
                    $this->rank
                );
            }
        }

        $settled = DecidedOrder::apply(
            CrossPoolRanking::levelGroups(
                $compared,
                $this->comparison === PoolComparison::PerEvent,
                CrossPoolRanking::positionsIn($outcome)
            ),
            $decisions['across'],
            null,
            $this->rank
        );
        SelectionTies::requireOrderDecided($settled['groups'], 1, $this->count, $this->ties, $this->rank, $settled['decided']);

        // Every holder is compared on figures calculated without the entrants
        // a decision left out, so that decision is behind every place
        foreach ($cutDecisions as $decision) {
            foreach ($holders as $holder) {
                if (!in_array($decision, $marks[$holder->getId()] ?? [], true)) {
                    $marks[$holder->getId()][] = $decision;
                }
            }
        }

        foreach ($settled['decided'] as $index => $decision) {
            foreach ($settled['groups'][$index] as $entry) {
                $marks[$entry->getParticipant()->getId()][] = $decision;
            }
        }

        $selected = array_map(
            static fn(StandingEntry $entry): Participant => $entry->getParticipant(),
            array_slice(array_merge(...$settled['groups']), 0, $this->count)
        );

        $kept = [];
        foreach ($selected as $participant) {
            if (isset($marks[$participant->getId()])) {
                $kept[$participant->getId()] = $marks[$participant->getId()];
            }
        }

        return new RankSelection($selected, $kept);
    }

    /**
     * The count: how many are selected does not depend on the outcome.
     */
    #[Override]
    public function getSelectionSize(): int
    {
        return $this->count;
    }

    /**
     * Refuse a larger pool in which level entries are on both sides of the
     * place below which its entrants are left out: the results do not say
     * whose results are dropped.
     *
     * @throws UndecidedSelectionException When a tied set of the pool's table spans that place
     *                                     and the selector does not accept the table order. Its
     *                                     ranks run from the selected rank to the size of the
     *                                     smallest pool: the places that are kept
     */
    private function requireCutDecided(Standings $standings, string $label, int $smallest): void
    {
        if ($this->ties === SelectionTies::ORDER_ACCEPTED) {
            return;
        }

        foreach ($standings->getTiedSets() as $tiedSet) {
            if (
                !$tiedSet->getDecision() instanceof Decider
                && $tiedSet->getFirstPosition() <= $smallest
                && $tiedSet->getLastPosition() > $smallest
            ) {
                throw new UndecidedSelectionException(
                    array_map(
                        static fn(Participant $participant): string => $participant->getId(),
                        $tiedSet->getParticipants()
                    ),
                    $tiedSet->getFirstPosition(),
                    $tiedSet->getLastPosition(),
                    $label,
                    $this->rank,
                    $smallest
                );
            }
        }
    }

    /**
     * The outcome's table calculated again without the given participants
     * and without every result they are in. The adjustment the outcome's
     * table shows for a participant that is kept is applied to the new
     * table, whole.
     *
     * @param array<string, true> $leftOut By participant ID
     * @throws InvalidConfigurationException When the outcome has no calculator (reason
     *                                       `IncompatibleOutcome`)
     */
    private function tableWithout(StageOutcome $outcome, array $leftOut): Standings
    {
        $calculator = $outcome->getCalculator();
        if ($calculator === null) {
            throw new InvalidConfigurationException(
                'Comparing pools of unequal size without the results against their lowest-placed entrants'
                    . ' calculates the table again, and the outcome carries no calculator: build it with'
                    . ' StageOutcome::combining(), or pass the calculator to its constructor',
                ['comparison' => PoolComparison::DiscardLowest->value],
                reason: InvalidConfigurationReason::IncompatibleOutcome
            );
        }

        $participants = [];
        $adjustments = [];
        foreach ($outcome->getStandings()->getEntries() as $entry) {
            if (isset($leftOut[$entry->getParticipant()->getId()])) {
                continue;
            }

            $participants[] = $entry->getParticipant();
            if ($entry->getAdjustment() !== 0.0) {
                $adjustments[] = new Adjustment($entry->getParticipant()->getId(), $entry->getAdjustment());
            }
        }

        $results = array_filter($outcome->getResults(), static function (Result $result) use ($leftOut): bool {
            foreach ($result->getEvent()->getParticipants() as $participant) {
                if (isset($leftOut[$participant->getId()])) {
                    return false;
                }
            }

            return true;
        });

        // An adjustment comes from no event, so no discarded result takes it
        // away: calculate() knows none, and the ones the table shows for the
        // entrants that are kept are applied again
        return $calculator->calculateWith($participants, array_values($results), [], $adjustments);
    }
}
