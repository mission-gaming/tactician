<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Exceptions\UndecidedSelectionException;
use MissionGaming\Tactician\Serialization\PlainData;
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
 * without it; the pools' own tables may still use it.
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
     */
    private function __construct(
        private int $rank,
        private int $count,
        private ?PoolComparison $comparison = null,
        private string $ties = SelectionTies::REFUSED
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
        return new self($this->rank, $this->count, $comparison, $this->ties);
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
        return new self($this->rank, $this->count, $this->comparison, SelectionTies::INSIDE_ACCEPTED);
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
        return new self($this->rank, $this->count, $this->comparison, SelectionTies::ORDER_ACCEPTED);
    }

    /**
     * Build from plain configuration data: ['rank' => 3, 'count' => 2],
     * the form toArray() gives. `rank` and `count` are required integers.
     * `comparison` is 'raw-totals', 'per-event' or 'discard-lowest'
     * (PoolComparison), and left out or null for none chosen. `ties` is
     * 'refuse' (the default), 'accept-inside-selection' or
     * 'accept-table-order'. Any other key is refused, so that a misspelt
     * `comparison` is not silently read as none.
     *
     * @param array<string, mixed> $config
     * @throws InvalidConfigurationException When the format version is not one this release reads
     *                                       (reason `UnsupportedFormatVersion`), a key is unknown
     *                                       (`UnknownOptionKey`), `rank` or `count` is not an
     *                                       integer or `comparison`
     *                                       is not a string (`WrongValueType`), `comparison` or
     *                                       `ties` is not a known identifier (`UnknownIdentifier`),
     *                                       or the rank or the count is below 1
     *                                       (`ValueOutOfRange`)
     */
    public static function fromArray(array $config): self
    {
        PlainData::checkOptions($config, ['rank', 'count', 'comparison', 'ties'], 'a best-of-rank selector');

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

        return new self($selector->rank, $selector->count, $comparison, $ties);
    }

    /**
     * The plain-data form fromArray() accepts: `format_version`, the
     * version of this form (1), then the 1-based rank and the count.
     * `comparison` is written only when a method was chosen, and `ties`
     * only for a selector that accepts an undecided order.
     *
     * @return array{format_version: int, rank: int, count: int, comparison?: string, ties?: string}
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
     * comparison method; each pool's table decides who holds the rank;
     * the comparison decides who is selected and in which order.
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
     */
    #[Override]
    public function select(StageOutcome $outcome): array
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

        $holders = [];
        $leftOut = [];
        foreach ($outcome->getPools() as $label => $poolOutcome) {
            $standings = $poolOutcome->getStandings();
            SelectionTies::requireTableDecided(
                $standings,
                $poolOutcome->getResults() !== [],
                (string) $label,
                $this->rank,
                $this->rank,
                $this->ties
            );

            $entries = array_values($standings->getEntries());
            $holders[] = $entries[$this->rank - 1]->getParticipant();

            if ($this->comparison === PoolComparison::DiscardLowest && count($entries) > $smallest) {
                $this->requireCutDecided($poolOutcome, (string) $label, $smallest);
                foreach (array_slice($entries, $smallest) as $entry) {
                    $leftOut[$entry->getParticipant()->getId()] = true;
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

        $groups = CrossPoolRanking::levelGroups(
            $compared,
            $this->comparison === PoolComparison::PerEvent,
            CrossPoolRanking::positionsIn($outcome)
        );
        SelectionTies::requireOrderDecided($groups, 1, $this->count, $this->ties, $this->rank);

        return array_map(
            static fn(StandingEntry $entry): Participant => $entry->getParticipant(),
            array_slice(array_merge(...$groups), 0, $this->count)
        );
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
    private function requireCutDecided(StageOutcome $poolOutcome, string $label, int $smallest): void
    {
        if ($this->ties === SelectionTies::ORDER_ACCEPTED) {
            return;
        }

        foreach ($poolOutcome->getStandings()->getTiedSets() as $tiedSet) {
            if ($tiedSet->getFirstPosition() <= $smallest && $tiedSet->getLastPosition() > $smallest) {
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
     * and without every result they are in.
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
        foreach ($outcome->getStandings()->getEntries() as $entry) {
            if (!isset($leftOut[$entry->getParticipant()->getId()])) {
                $participants[] = $entry->getParticipant();
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

        return $calculator->calculate($participants, array_values($results));
    }
}
