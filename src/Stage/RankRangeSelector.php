<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Exceptions\UndecidedSelectionException;
use MissionGaming\Tactician\Serialization\PlainData;
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
 *   pools', or simply 'top 8' of an unpooled stage)
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
 * What the selector can see is the table. It recognises a pool with no
 * result at all; it cannot know that a pool with some results has events
 * still to play, so check that before selecting
 * (RoundRobinPlan::findUnplayedPairings(), or the engine's isComplete()).
 *
 * @experimental
 */
final readonly class RankRangeSelector implements ProgressionSelector
{
    private const string MODE_OVERALL = 'overall';
    private const string MODE_PER_GROUP = 'per-group';

    private const string TIES_REFUSED = 'refuse';
    private const string TIES_INSIDE_ACCEPTED = 'accept-inside-selection';
    private const string TABLE_ORDER_ACCEPTED = 'accept-table-order';

    private const array TIE_POLICIES = [self::TIES_REFUSED, self::TIES_INSIDE_ACCEPTED, self::TABLE_ORDER_ACCEPTED];

    /**
     * @param 'overall'|'per-group' $mode
     * @param int $from 1-based first rank, inclusive
     * @param int $to 1-based last rank, inclusive
     * @param 'refuse'|'accept-inside-selection'|'accept-table-order' $ties What the selection does
     *                                                                     where results do not
     *                                                                     decide an order
     */
    private function __construct(
        private string $mode,
        private int $from,
        private int $to,
        private string $ties = self::TIES_REFUSED
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
        return new self($this->mode, $this->from, $this->to, self::TIES_INSIDE_ACCEPTED);
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
        return new self($this->mode, $this->from, $this->to, self::TABLE_ORDER_ACCEPTED);
    }

    /**
     * Build from plain configuration data:
     * ['mode' => 'per-group', 'from' => 1, 'to' => 2], the form toArray()
     * gives. The mode is 'overall' or 'per-group'; `from` defaults to 1
     * and `to` is required. `ties` is 'refuse' (the default),
     * 'accept-inside-selection' or 'accept-table-order'. Any other key is
     * refused, so that a misspelt `from` is not silently read as 1.
     *
     * @param array<string, mixed> $config
     * @throws InvalidConfigurationException When the format version is not one this release reads
     *                                       (reason `UnsupportedFormatVersion`), a key is unknown
     *                                       (`UnknownOptionKey`), the mode is missing or unknown
     *                                       or `ties` is unknown (`UnknownIdentifier`), a bound is
     *                                       not an integer (`WrongValueType`), or the range is not
     *                                       1 <= from <= to (`ValueOutOfRange`)
     */
    public static function fromArray(array $config): self
    {
        PlainData::checkOptions($config, ['mode', 'from', 'to', 'ties'], 'a rank range selector');

        $mode = $config['mode'] ?? null;
        if (!in_array($mode, [self::MODE_OVERALL, self::MODE_PER_GROUP], true)) {
            throw new InvalidConfigurationException(
                'Unknown rank range mode',
                ['mode' => $mode, 'known' => [self::MODE_OVERALL, self::MODE_PER_GROUP]],
                reason: InvalidConfigurationReason::UnknownIdentifier
            );
        }

        $ties = $config['ties'] ?? self::TIES_REFUSED;
        if (!in_array($ties, self::TIE_POLICIES, true)) {
            throw new InvalidConfigurationException(
                'Unknown rank range tie policy',
                ['ties' => $ties, 'known' => self::TIE_POLICIES],
                reason: InvalidConfigurationReason::UnknownIdentifier
            );
        }

        $from = $config['from'] ?? 1;
        $to = $config['to'] ?? null;
        if (!is_int($from) || !is_int($to)) {
            throw new InvalidConfigurationException(
                'Rank range bounds must be integers',
                ['from' => $from, 'to' => $to],
                reason: InvalidConfigurationReason::WrongValueType
            );
        }

        return self::ofRange($mode, $from, $to, $ties);
    }

    /**
     * The plain-data form fromArray() accepts: `format_version`, the
     * version of this form (1), then the mode ('overall' or 'per-group')
     * and the two 1-based, inclusive bounds. topPerGroup(n) is written as
     * per-group 1..n. The key `ties` is written only for a selector that
     * accepts an undecided order ('accept-inside-selection' or
     * 'accept-table-order'); a selector that refuses has no such key.
     *
     * @return array{format_version: int, mode: string, from: int, to: int, ties?: string}
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

        return $data;
    }

    /**
     * The participants at the selected ranks of the outcome's standings.
     *
     * Overall mode reads the outcome's own table and returns ranks
     * from..to in rank order; pools, if the outcome has them, play no
     * part.
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
     * selector accepts it; the class docblock says when, and what an
     * accepted selection returns.
     *
     * @return array<Participant>
     * @throws InvalidConfigurationException When per-group mode is given an outcome without pools
     *                                       (reason `IncompatibleOutcome`), or the table, or one of
     *                                       the pools, has fewer entries than the last rank
     *                                       (`RankUnavailable`)
     * @throws UndecidedSelectionException When results do not decide the selection and the selector
     *                                     does not accept that (`UnplayedStage`,
     *                                     `TieAcrossSelection`, `TieWithinSelection`)
     */
    #[Override]
    public function select(StageOutcome $outcome): array
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

            $entries = $this->decidedEntries($outcome, null);

            $selected = [];
            for ($rank = $this->from; $rank <= $this->to; ++$rank) {
                $selected[] = $entries[$rank - 1];
            }

            return $selected;
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

        $entriesByPool = [];
        foreach ($outcome->getPools() as $label => $poolOutcome) {
            $entriesByPool[] = $this->decidedEntries($poolOutcome, (string) $label);
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

        return $selected;
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
     * The participants of one table in the order a selection reads them,
     * after refusing what the selector does not accept.
     *
     * @return list<Participant>
     * @throws UndecidedSelectionException When results do not decide the selected ranks
     */
    private function decidedEntries(StageOutcome $outcome, ?string $pool): array
    {
        $standings = $outcome->getStandings();

        if ($this->ties !== self::TABLE_ORDER_ACCEPTED) {
            $this->requireDecided($standings, $outcome->getResults() !== [], $pool);
        }

        return array_values(array_map(
            static fn(StandingEntry $entry): Participant => $entry->getParticipant(),
            $standings->getEntries()
        ));
    }

    /**
     * @throws UndecidedSelectionException When the table is unplayed, or a tied set holds a
     *                                     selected rank in a way the selector does not accept
     */
    private function requireDecided(Standings $standings, bool $played, ?string $pool): void
    {
        if (!$played && count($standings) > 1) {
            throw new UndecidedSelectionException(
                array_map(
                    static fn(StandingEntry $entry): string => $entry->getParticipant()->getId(),
                    $standings->getEntries()
                ),
                1,
                count($standings),
                $pool,
                $this->from,
                $this->to,
                false
            );
        }

        foreach ($standings->getTiedSets() as $tiedSet) {
            $first = $tiedSet->getFirstPosition();
            $last = $tiedSet->getLastPosition();

            if ($last < $this->from || $first > $this->to) {
                continue;
            }

            $inside = $first >= $this->from && $last <= $this->to;
            if ($inside && $this->ties === self::TIES_INSIDE_ACCEPTED) {
                continue;
            }

            throw new UndecidedSelectionException(
                array_map(
                    static fn(Participant $participant): string => $participant->getId(),
                    $tiedSet->getParticipants()
                ),
                $first,
                $last,
                $pool,
                $this->from,
                $this->to
            );
        }
    }
}
