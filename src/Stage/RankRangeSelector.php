<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

use MissionGaming\Tactician\DTO\Decider;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Exceptions\RejectedDecisionException;
use MissionGaming\Tactician\Exceptions\UndecidedSelectionException;
use MissionGaming\Tactician\Serialization\PlainData;
use MissionGaming\Tactician\Standings\DecidedOrder;
use MissionGaming\Tactician\Standings\StandingEntry;
use MissionGaming\Tactician\Standings\Standings;
use Override;

/**
 * Standings-based progression: selects rank slices from an outcome.
 *
 * Three modes, all reading Tactician's standings (the ranking authority
 * for this selection decision):
 *
 * - overall: ranks from..to of the combined standings ('best 8 across all
 *   pools', or simply 'top 8' of an unpooled stage). Across pools of
 *   unequal size it needs a comparison method (comparedBy())
 * - per-group: ranks from..to of each pool, emitted block by block —
 *   every pool's first selected rank, then every pool's second, and so on.
 *   The first block is in pool order, and each later block is ordered so
 *   that a bracket folded from the list keeps the entrants of one pool
 *   apart for as long as it can (see select())
 * - topPerGroup(n) is the per-group 1..n convenience
 *
 * **A selection is refused when results do not decide it.** A table has a
 * place for every entry, also where no result separates two entries
 * (Standings::getTiedSets()). By default select() throws an
 * UndecidedSelectionException, which names the level participants, the
 * places they hold between them and the pool, when:
 *
 * - the table has no recorded result (an unplayed pool);
 * - level entries hold places on both sides of a boundary of the selected
 *   ranks, so the results do not say who is selected;
 * - level entries are all inside the selected ranks, so who is selected is
 *   decided and the order they are listed in, which seeds the next stage,
 *   is not.
 *
 * Going on regardless is a choice made on the selector, in two steps:
 *
 * - withTiesInsideSelectionAccepted() accepts the third case only: every
 *   selected participant is selected by results, and level ones are listed
 *   in table order;
 * - withTableOrderAccepted() accepts all three: the selection is the table
 *   as it stands.
 *
 * In an accepted selection level entries are in the order of the entrant
 * list of the outcome (StageOutcome::getEntrants()), when it has one: the
 * position in that list decides where results do not. A tie that touches
 * none of the selected ranks is never refused.
 *
 * **The third way on is to settle the tie.** The application decides the
 * order of the level entries by its own rule and gives it to the selector
 * as a decision (withDecisions()): the selection then reads that order,
 * and selection() says which of the selected places a decision settled.
 * A decision orders level entries only and never overrides a result. It
 * does not lift the refusal of a table with no result.
 *
 * What the selector can see is the table. It recognises a pool with no
 * result at all; it cannot know that a pool with some results has events
 * still to play, so check that before selecting
 * (RoundRobinPlan::findUnplayedPairings(), or the engine's isComplete()).
 *
 * @experimental
 */
final readonly class RankRangeSelector implements ProgressionSelector
{
    private const string MODE_OVERALL = RankRangeMode::Overall->value;
    private const string MODE_PER_GROUP = RankRangeMode::PerGroup->value;

    private const string TIES_REFUSED = SelectionTies::REFUSED;
    private const string TIES_INSIDE_ACCEPTED = SelectionTies::INSIDE_ACCEPTED;
    private const string TABLE_ORDER_ACCEPTED = SelectionTies::ORDER_ACCEPTED;

    /**
     * @param 'overall'|'per-group' $mode
     * @param int $from 1-based first rank, inclusive
     * @param int $to 1-based last rank, inclusive
     * @param 'refuse'|'accept-inside-selection'|'accept-table-order' $ties What the selection does
     *                                                                     where results do not
     *                                                                     decide an order
     * @param PoolComparison|null $comparison How an overall selection compares pools of unequal
     *                                        size; null for none chosen
     * @param list<Decider> $decisions The decisions that settle level entries of the tables read
     */
    private function __construct(
        private string $mode,
        private int $from,
        private int $to,
        private string $ties = self::TIES_REFUSED,
        private ?PoolComparison $comparison = null,
        private array $decisions = []
    ) {}

    /**
     * @param 'overall'|'per-group' $mode
     * @param 'refuse'|'accept-inside-selection'|'accept-table-order' $ties
     * @throws InvalidConfigurationException When the range is not 1 <= from <= to
     */
    private static function ofRange(string $mode, int $from, int $to, string $ties = self::TIES_REFUSED): self
    {
        if ($from < 1 || $to < $from) {
            throw new InvalidConfigurationException(
                'Rank range must satisfy 1 <= from <= to',
                ['from' => $from, 'to' => $to],
                reason: InvalidConfigurationReason::ValueOutOfRange
            );
        }

        return new self($mode, $from, $to, $ties);
    }

    /**
     * The same overall selection with a method to compare pools of unequal
     * size by.
     *
     * An overall selection reads the outcome's own table, which for a
     * pooled outcome ranks every pool's entrants together. When the pools
     * are not of one size (their tables do not have the same number of
     * entries) the entrants have played different numbers of events, and
     * the selection is refused until a method is chosen here (reason
     * `PoolComparisonRequired`):
     *
     * - PoolComparison::RawTotals takes the table as it stands;
     * - PoolComparison::PerEvent puts the table in the order of every
     *   figure divided by the events played. Entries that are equal on
     *   every divided figure are level, whatever their totals, and are
     *   refused or accepted as level entries of a table are; accepted,
     *   they are in the order of the outcome's entrant list when it has
     *   one, and otherwise in the order of its table. It is applied to
     *   every pooled outcome, also one whose pools are of one size.
     *
     * An outcome without pools is one table, and the method is not read.
     *
     * @throws InvalidConfigurationException When the selection is per-group, which compares no
     *                                       entrants of different pools, or the method is
     *                                       PoolComparison::DiscardLowest, which says nothing
     *                                       about the entrants it leaves out and so cannot order
     *                                       a whole table (reason `IncompatibleOptions`)
     */
    public function comparedBy(PoolComparison $comparison): self
    {
        if ($this->mode !== self::MODE_OVERALL || $comparison === PoolComparison::DiscardLowest) {
            throw new InvalidConfigurationException(
                $this->mode !== self::MODE_OVERALL
                    ? 'A per-group rank selection compares no entrants of different pools and takes no pool comparison'
                    : 'An overall rank selection cannot compare pools by discarding results: the entrants left out'
                        . ' have no place in the table that is left',
                ['mode' => $this->mode, 'comparison' => $comparison->value],
                reason: InvalidConfigurationReason::IncompatibleOptions
            );
        }

        return new self($this->mode, $this->from, $this->to, $this->ties, $comparison, $this->decisions);
    }

    /**
     * The top N of each pool, every pool's rank 1 first: the usual
     * "qualifiers" selection. The same as perGroup(1, $count).
     *
     * @throws InvalidConfigurationException When count is below 1
     */
    public static function topPerGroup(int $count): self
    {
        return self::ofRange(self::MODE_PER_GROUP, 1, $count);
    }

    /**
     * Ranks from..to of each pool — e.g. perGroup(from: 3, to: 4) feeds a
     * losers' route.
     *
     * @throws InvalidConfigurationException When the range is not 1 <= from <= to
     */
    public static function perGroup(int $from, int $to): self
    {
        return self::ofRange(self::MODE_PER_GROUP, $from, $to);
    }

    /**
     * Ranks from..to of the combined standings — the best N across all
     * pools, or a slice of an unpooled table.
     *
     * @throws InvalidConfigurationException When the range is not 1 <= from <= to
     */
    public static function overall(int $from, int $to): self
    {
        return self::ofRange(self::MODE_OVERALL, $from, $to);
    }

    /**
     * The same selection, accepting entries that no result separates when
     * all of them are selected: they are listed in table order, which is
     * the order of the outcome's entrant list when it has one.
     *
     * Who is selected is still decided by results alone: a table with no
     * result, and level entries of which only some would be selected, are
     * refused as before.
     */
    public function withTiesInsideSelectionAccepted(): self
    {
        return new self($this->mode, $this->from, $this->to, self::TIES_INSIDE_ACCEPTED, $this->comparison, $this->decisions);
    }

    /**
     * The same selection, accepting the table as it stands: nothing is
     * refused for being undecided, an unplayed table included. Level
     * entries are in the order of the outcome's entrant list when it has
     * one, and otherwise in the order the table was given in.
     *
     * This is the choice to let position decide who is selected. Make it
     * when the entrant order is itself a ranking the stage may fall back
     * on, or when the application has already settled every tie in the
     * order of the table it built.
     */
    public function withTableOrderAccepted(): self
    {
        return new self($this->mode, $this->from, $this->to, self::TABLE_ORDER_ACCEPTED, $this->comparison, $this->decisions);
    }

    /**
     * The same selection with decisions that settle the order of level
     * entries, in place of any the selector had; an empty list for none.
     *
     * A decision is a `DTO\Decider`: the IDs of the level participants,
     * best first, with an optional label and reason that the library
     * stores and never reads. select() applies each decision to the order
     * it is for before it reads a rank from it:
     *
     * - a per-group selection reads each pool's table, and a decision is
     *   for the table of the pool its participants are in, where it orders
     *   one whole tied set (Standings::withDecisions() has the rules);
     * - an overall selection reads the outcome's own table, and a decision
     *   orders one whole tied set of it. With a comparison per event played
     *   (PoolComparison::PerEvent) a decision orders one whole group of
     *   entries that are level on their figures per event, which is what
     *   that selection refuses.
     *
     * A set that a decision settled is not refused, whichever tie policy
     * the selector has; every other undecided order is refused or accepted
     * as before. A decision for a tie that touches no selected rank is
     * applied and changes nothing in the list. One that fits no order the
     * selection reads is rejected by select(), and nothing is selected.
     *
     * A table the outcome was built with can carry decisions of its own
     * (Standings::withDecisions()); they count as given here. The one
     * selection that does not read them is the overall one per event
     * played of a pooled outcome: it puts the entries in an order of its
     * own, in which a tied set of the table need not be level, so it takes
     * the decisions given to the selector and no other.
     *
     * @param array<Decider> $decisions One for each set of level entries to settle, in any order
     *
     * @throws InvalidConfigurationException When an entry of the list is not a Decider (reason
     *                                       `WrongValueType`)
     */
    public function withDecisions(array $decisions): self
    {
        return new self(
            $this->mode,
            $this->from,
            $this->to,
            $this->ties,
            $this->comparison,
            SelectionTies::decisions($decisions, 'rank range')
        );
    }

    /**
     * Build from plain configuration data:
     * ['mode' => 'per-group', 'from' => 1, 'to' => 2], the form toArray()
     * gives. The mode is 'overall' or 'per-group'; `from` defaults to 1
     * and `to` is required. `ties` is 'refuse' (the default),
     * 'accept-inside-selection' or 'accept-table-order'. `comparison` is
     * the method an overall selection compares pools of unequal size by,
     * 'raw-totals' or 'per-event' (comparedBy()), and left out or null for
     * none chosen. `decisions` is a list of deciders in their array form
     * (withDecisions()), and left out or null for none. Any other key is
     * refused, so that a misspelt `from` is not silently read as 1.
     *
     * @param array<string, mixed> $config
     * @throws InvalidConfigurationException When the format version is not one this release reads
     *                                       (reason `UnsupportedFormatVersion`), a key is unknown
     *                                       (`UnknownOptionKey`), the mode is missing or unknown,
     *                                       or `ties` or `comparison` is unknown
     *                                       (`UnknownIdentifier`), a bound is not an integer,
     *                                       `comparison` is not a string or `decisions` is not a
     *                                       list of decider arrays (`WrongValueType`), the
     *                                       range is not 1 <= from <= to (`ValueOutOfRange`), or
     *                                       the mode does not take the comparison
     *                                       (`IncompatibleOptions`)
     */
    public static function fromArray(array $config): self
    {
        PlainData::checkOptions($config, ['mode', 'from', 'to', 'ties', 'comparison', 'decisions'], 'a rank range selector');

        $mode = $config['mode'] ?? null;
        if (!in_array($mode, [self::MODE_OVERALL, self::MODE_PER_GROUP], true)) {
            throw new InvalidConfigurationException(
                'Unknown rank range mode',
                ['mode' => $mode, 'known' => [self::MODE_OVERALL, self::MODE_PER_GROUP]],
                reason: InvalidConfigurationReason::UnknownIdentifier
            );
        }

        $ties = SelectionTies::policyFrom($config['ties'] ?? null, 'rank range');

        $from = $config['from'] ?? 1;
        $to = $config['to'] ?? null;
        if (!is_int($from) || !is_int($to)) {
            throw new InvalidConfigurationException(
                'Rank range bounds must be integers',
                ['from' => $from, 'to' => $to],
                reason: InvalidConfigurationReason::WrongValueType
            );
        }

        $selector = self::ofRange($mode, $from, $to, $ties);
        $comparison = CrossPoolRanking::comparisonFrom($config['comparison'] ?? null, 'rank range');
        if ($comparison instanceof PoolComparison) {
            $selector = $selector->comparedBy($comparison);
        }

        $decisions = SelectionTies::decisionsFrom($config['decisions'] ?? null, 'rank range');

        return $decisions === [] ? $selector : $selector->withDecisions($decisions);
    }

    /**
     * The plain-data form fromArray() accepts: `format_version`, the
     * version of this form (1), then the mode ('overall' or 'per-group')
     * and the two 1-based, inclusive bounds. topPerGroup(n) is written as
     * per-group 1..n. The key `ties` is written only for a selector that
     * accepts an undecided order ('accept-inside-selection' or
     * 'accept-table-order'), the key `comparison` only for one that was
     * given a method to compare pools by, and the key `decisions` only for
     * one that was given decisions, each written as the array of a decider
     * without a format version of its own; a selector with none of them
     * has no such key.
     *
     * @return array{format_version: int, mode: string, from: int, to: int, ties?: string, comparison?: string, decisions?: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        $data = [
            PlainData::VERSION_KEY => PlainData::VERSION,
            'mode' => $this->mode,
            'from' => $this->from,
            'to' => $this->to,
        ];
        if ($this->ties !== self::TIES_REFUSED) {
            $data['ties'] = $this->ties;
        }
        if ($this->comparison !== null) {
            $data['comparison'] = $this->comparison->value;
        }
        if ($this->decisions !== []) {
            $data['decisions'] = SelectionTies::decisionsData($this->decisions);
        }

        return $data;
    }

    /**
     * The participants at the selected ranks of the outcome's standings.
     *
     * Overall mode reads the outcome's own table and returns ranks
     * from..to in rank order. Pools, if the outcome has them, play one
     * part: where they are not of one size the selection is refused until
     * a comparison method is chosen, and a comparison per event played
     * reorders the table (comparedBy()).
     *
     * Per-group mode reads each pool's table and returns rank by rank.
     * The first selected rank is listed in pool order. Each later rank is
     * listed in the order that keeps the entrants of one pool apart in a
     * bracket folded from the whole list (the elimination engines fold by
     * list position): as few pairs of one pool as possible that can meet
     * in the first round, then in the second, and so on. With pools A and
     * B and ranks 1..2 that is A1, B1, A2, B2 (A1 meets B2). With three
     * pools it is A1, B1, C1, C2, B2, A2: the first-round ties are C2
     * against B2 and C1 against A2, where pool order would pair C1 with
     * C2. With four it is A1, B1, C1, D1, B2, A2, D2, C2: A1 meets C2 and
     * D1 meets B2 in one half, B1 meets D2 and C1 meets A2 in the other,
     * so a pool's first and second can only meet in the final. The order
     * depends on the number of pools and of ranks alone.
     *
     * A selection that results do not decide is refused unless the
     * selector accepts it or was given a decision that settles it; the
     * class docblock says when, and what an accepted selection returns.
     * The list is that of selection(), which also says which places a
     * decision settled.
     *
     * @return array<Participant>
     * @throws InvalidConfigurationException When per-group mode is given an outcome without pools,
     *                                       or a comparison per event played a table with tiers
     *                                       (reason `IncompatibleOutcome`); the table, or one of
     *                                       the pools, has fewer entries than the last rank
     *                                       (`RankUnavailable`); or overall mode is given pools of
     *                                       unequal size and no comparison method was chosen
     *                                       (`PoolComparisonRequired`)
     * @throws UndecidedSelectionException When results do not decide the selection and the selector
     *                                     does not accept that (`UnplayedStage`,
     *                                     `TieAcrossSelection`, `TieWithinSelection`)
     * @throws RejectedDecisionException When a decision the selector was given does not order one
     *                                   whole set of level entries of an order the selection reads
     */
    #[Override]
    public function select(StageOutcome $outcome): array
    {
        return $this->selection($outcome)->getParticipants();
    }

    /**
     * The selection with its marks: the participants select() returns, in
     * the same order, and for each of them the decision that settled the
     * place it was selected at, when one did (RankSelection).
     *
     * A place is marked when a decision put the participant there: one
     * given to this selector (withDecisions()) or one the table carried.
     * A participant that results placed is not marked, and neither is one
     * of an undecided order that the selector accepted, which the entrant
     * list or the table order placed.
     *
     * @throws InvalidConfigurationException As select()
     * @throws UndecidedSelectionException As select()
     * @throws RejectedDecisionException As select()
     */
    public function selection(StageOutcome $outcome): RankSelection
    {
        if ($this->mode === self::MODE_OVERALL) {
            $available = count($outcome->getStandings());
            if ($available < $this->to) {
                throw new InvalidConfigurationException(
                    'Rank range extends past the combined standings',
                    ['to' => $this->to, 'available' => $available],
                    reason: InvalidConfigurationReason::RankUnavailable
                );
            }

            if ($outcome->hasPools() && $this->comparison === null) {
                $sizes = CrossPoolRanking::poolSizes($outcome);
                if (CrossPoolRanking::spread($sizes)['unequal']) {
                    throw CrossPoolRanking::comparisonRequired($sizes);
                }
            }

            ['entries' => $entries, 'marks' => $marks] = $outcome->hasPools() && $this->comparison === PoolComparison::PerEvent
                ? $this->decidedEntriesPerEvent($outcome)
                : $this->decidedEntries($outcome, null, $this->decisions);

            $selected = [];
            for ($rank = $this->from; $rank <= $this->to; ++$rank) {
                $selected[] = $entries[$rank - 1];
            }

            return self::marked($selected, $marks);
        }

        if (!$outcome->hasPools()) {
            throw new InvalidConfigurationException(
                'Per-group rank selection requires a pooled outcome',
                ['mode' => $this->mode],
                reason: InvalidConfigurationReason::IncompatibleOutcome
            );
        }

        // Every pool has the ranks before any pool's table is read: the
        // lowest missing rank is reported, for the first pool that lacks it.
        $missing = null;
        foreach ($outcome->getPools() as $label => $poolOutcome) {
            $size = count($poolOutcome->getStandings());
            if ($size < $this->to && ($missing === null || max($this->from, $size + 1) < $missing['rank'])) {
                $missing = ['pool' => (string) $label, 'rank' => max($this->from, $size + 1), 'pool_size' => $size];
            }
        }
        if ($missing !== null) {
            throw new InvalidConfigurationException(
                "Pool {$missing['pool']} has no rank {$missing['rank']}",
                $missing,
                reason: InvalidConfigurationReason::RankUnavailable
            );
        }

        $decisions = SelectionTies::byPool($outcome, $this->decisions, null)['pools'];

        $entriesByPool = [];
        $marks = [];
        foreach ($outcome->getPools() as $label => $poolOutcome) {
            $decided = $this->decidedEntries($poolOutcome, (string) $label, $decisions[(string) $label] ?? []);
            $entriesByPool[] = $decided['entries'];
            $marks += $decided['marks'];
        }

        // Block by block: every pool's first selected rank, then every
        // pool's second... each block in the order that keeps a pool's
        // entrants apart in a bracket folded from the list.
        $selected = [];
        foreach (CrossPoolOrder::blocks(count($entriesByPool), $this->to - $this->from + 1) as $block => $pools) {
            foreach ($pools as $pool) {
                $selected[] = $entriesByPool[$pool][$this->from + $block - 1];
            }
        }

        return self::marked($selected, $marks);
    }

    /**
     * to - from + 1 in overall mode, a fixed count. In per-group mode the
     * same number from each pool, so the yield is that number times the
     * number of pools of the stage before.
     */
    #[Override]
    public function getYield(): SelectionYield
    {
        $count = $this->to - $this->from + 1;

        return $this->mode === self::MODE_OVERALL ? SelectionYield::fixed($count) : SelectionYield::perPool($count);
    }

    /**
     * to - from + 1 in overall mode. Null in per-group mode, where the
     * count is that number times the number of pools, which only the
     * outcome says.
     */
    #[Override]
    public function getSelectionSize(): ?int
    {
        // Per-group counts depend on how many pools the outcome carries
        return $this->mode === self::MODE_OVERALL ? $this->to - $this->from + 1 : null;
    }

    /**
     * Which table the ranks are taken from: the outcome's own, or each
     * pool's.
     */
    public function getMode(): RankRangeMode
    {
        return RankRangeMode::from($this->mode);
    }

    /**
     * The first selected rank, 1-based.
     */
    public function getFrom(): int
    {
        return $this->from;
    }

    /**
     * The last selected rank, 1-based: at least getFrom().
     */
    public function getTo(): int
    {
        return $this->to;
    }

    /**
     * What the selection does with an order that results do not decide;
     * Refused unless the selector was told otherwise.
     */
    public function getTiePolicy(): SelectionTiePolicy
    {
        return SelectionTiePolicy::from($this->ties);
    }

    /**
     * The selection of the given participants, each with the decisions
     * that settled its place.
     *
     * @param list<Participant> $selected
     * @param array<string, list<Decider>> $marks By participant ID, for any participant of the
     *                                            tables read
     */
    private static function marked(array $selected, array $marks): RankSelection
    {
        $kept = [];
        foreach ($selected as $participant) {
            if (isset($marks[$participant->getId()])) {
                $kept[$participant->getId()] = $marks[$participant->getId()];
            }
        }

        return new RankSelection($selected, $kept);
    }

    /**
     * The participants of one table in the order a selection reads them,
     * after applying the decisions for it and refusing what the selector
     * does not accept, and the decision that settled each one's place.
     *
     * @param list<Decider> $decisions The decisions for this table
     * @return array{entries: list<Participant>, marks: array<string, list<Decider>>}
     * @throws UndecidedSelectionException When results do not decide the selected ranks
     * @throws RejectedDecisionException When a decision does not order one whole tied set of the table
     */
    private function decidedEntries(StageOutcome $outcome, ?string $pool, array $decisions): array
    {
        $standings = $pool === null
            ? $outcome->getStandings()->withDecisions($decisions)
            : SelectionTies::decidedTable($outcome->getStandings(), $decisions, $pool);

        SelectionTies::requireTableDecided(
            $standings,
            $outcome->getResults() !== [],
            $pool,
            $this->from,
            $this->to,
            $this->ties
        );

        $entries = array_values(array_map(
            static fn(StandingEntry $entry): Participant => $entry->getParticipant(),
            $standings->getEntries()
        ));

        return ['entries' => $entries, 'marks' => SelectionTies::marksIn($standings, $entries)];
    }

    /**
     * The participants of a pooled outcome's table in the order of their
     * figures per event played, after applying the decisions and refusing
     * what the selector does not accept, and the decision that settled
     * each one's place.
     *
     * @return array{entries: list<Participant>, marks: array<string, list<Decider>>}
     * @throws InvalidConfigurationException When the table has tiers, which a comparison per
     *                                       event would cross (reason `IncompatibleOutcome`)
     * @throws UndecidedSelectionException When results do not decide the selected ranks
     * @throws RejectedDecisionException When a decision does not order one whole group of entries
     *                                   that are level per event played
     */
    private function decidedEntriesPerEvent(StageOutcome $outcome): array
    {
        $standings = $outcome->getStandings();
        if ($standings->getTiers() !== []) {
            throw new InvalidConfigurationException(
                'A table with tiers cannot be put in the order of its figures per event played',
                ['comparison' => PoolComparison::PerEvent->value],
                reason: InvalidConfigurationReason::IncompatibleOutcome
            );
        }

        if ($this->ties !== self::TABLE_ORDER_ACCEPTED && $outcome->getResults() === [] && count($standings) > 1) {
            throw new UndecidedSelectionException(
                array_map(
                    static fn(StandingEntry $entry): string => $entry->getParticipant()->getId(),
                    $standings->getEntries()
                ),
                1,
                count($standings),
                null,
                $this->from,
                $this->to,
                false
            );
        }

        $settled = DecidedOrder::apply(
            CrossPoolRanking::levelGroups($standings->getEntries(), true, CrossPoolRanking::positionsIn($outcome)),
            $this->decisions
        );
        SelectionTies::requireOrderDecided($settled['groups'], $this->from, $this->to, $this->ties, null, $settled['decided']);

        $marks = [];
        foreach ($settled['decided'] as $index => $decision) {
            foreach ($settled['groups'][$index] as $entry) {
                $marks[$entry->getParticipant()->getId()] = [$decision];
            }
        }

        return [
            'entries' => array_map(
                static fn(StandingEntry $entry): Participant => $entry->getParticipant(),
                array_merge(...$settled['groups'])
            ),
            'marks' => $marks,
        ];
    }
}
