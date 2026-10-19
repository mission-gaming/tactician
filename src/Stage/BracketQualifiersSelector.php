<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Exceptions\RejectedDecisionException;
use MissionGaming\Tactician\Exceptions\UndecidedSelectionException;
use MissionGaming\Tactician\Serialization\PlainData;
use Override;

/**
 * The qualifiers of a pool stage, placed for a bracket: the selected ranks
 * of every pool and the best few of a lower rank across the pools, in an
 * order that keeps the entrants of one pool apart when the list is folded
 * into a bracket, as the elimination engines fold it. With
 * RankRangeSelector::topPerGroup(2) and BestOfRankSelector::ofRank(3, 2) it
 * is the format "the top two of each pool and the two best of those placed
 * third", into a knockout.
 *
 * Who is selected is what the two selectors select, each by its own rules,
 * its own tie policy and its own decisions: the composition adds an order
 * and nothing else. Give a decision to the selector whose order it settles
 * (a tie of a pool's table that touches the per-group ranks to the
 * per-group selector, one that touches the rank compared across the pools
 * to the best-of-rank selector, a tie that touches both to both), or put it
 * on the pool's table (Standings::withDecisions()), where both read it.
 *
 * **The list is in tiers.** It holds every pool's first selected rank,
 * then every pool's second, and so on, then the best of the lower rank,
 * best first: an entrant of a higher rank always has a better seed than one
 * of a lower rank, and of the best of the lower rank the better has the
 * better seed. The order of the pools inside each rank of the per-group
 * selection is the one that keeps a pool's entrants apart (CrossPoolOrder):
 * it depends on which pools the best of the lower rank come from, and so on
 * the results, as the draw of such a format does. The ranks are ordered one
 * after the other, each against the best of the lower rank and the ranks
 * before it, and settled for good: of the orders a rank can take, the one
 * in which no two entrants of one pool meet in the first round, then in
 * which as few pairs of one pool as can be can meet in the second, and so
 * on; and of orders that are equal in that, the one nearest to pool order.
 *
 * **No two entrants of one pool meet in the first round, from two pools
 * up.** With one pool the bracket cannot keep them apart, and select()
 * refuses (reason `PoolMatesInFirstRound`). The yield states it
 * (getYield(), with a minimum of 2 pools, or as many as the count of the
 * best-of-rank selector when that is more), so CompositionValidator reports
 * a transition that declares fewer pools up front. That is the one case:
 * from two pools on, every number of ranks and every count has an order
 * with no first-round tie of one pool, whichever pools the best of the
 * lower rank come from (CrossPoolOrder says why). The order is the one for
 * a bracket of exactly these entrants; put anywhere else (dealt into pools
 * again, or into a bracket with others) it has no special meaning.
 *
 * @experimental
 */
final readonly class BracketQualifiersSelector implements ProgressionSelector
{
    private function __construct(
        private RankRangeSelector $perGroup,
        private BestOfRankSelector $bestOfRank
    ) {}

    /**
     * The ranks a per-group selector selects from every pool, then the best
     * of a lower rank that a best-of-rank selector selects across the
     * pools, placed for a bracket.
     *
     * @param RankRangeSelector $perGroup A per-group selection (RankRangeSelector::perGroup() or
     *                                    topPerGroup())
     * @param BestOfRankSelector $bestOfRank The best of a rank below the last rank of the
     *                                       per-group selection
     *
     * @throws InvalidConfigurationException When the rank range is not per group, or the rank of
     *                                       the best-of-rank selection is not below its last rank
     *                                       (reason `IncompatibleOptions`)
     */
    public static function of(RankRangeSelector $perGroup, BestOfRankSelector $bestOfRank): self
    {
        if ($perGroup->getMode() !== RankRangeMode::PerGroup) {
            throw new InvalidConfigurationException(
                'Qualifiers placed for a bracket take a per-group rank selection; an overall one has no pools to keep apart',
                ['mode' => $perGroup->getMode()->value],
                reason: InvalidConfigurationReason::IncompatibleOptions
            );
        }

        if ($bestOfRank->getRank() <= $perGroup->getTo()) {
            throw new InvalidConfigurationException(
                sprintf(
                    'The best of rank %d would select entrants of ranks the per-group selection already selects (%d to %d)',
                    $bestOfRank->getRank(),
                    $perGroup->getFrom(),
                    $perGroup->getTo()
                ),
                ['rank' => $bestOfRank->getRank(), 'from' => $perGroup->getFrom(), 'to' => $perGroup->getTo()],
                reason: InvalidConfigurationReason::IncompatibleOptions
            );
        }

        return new self($perGroup, $bestOfRank);
    }

    /**
     * Build from plain configuration data, the form toArray() gives:
     * `per_group`, a rank range selector in its array form
     * (RankRangeSelector::fromArray()), whose mode is 'per-group', and
     * `best_of_rank`, a best-of-rank selector in its array form
     * (BestOfRankSelector::fromArray()). Both are required. Any other key
     * is refused.
     *
     * @param array<string, mixed> $config
     * @throws InvalidConfigurationException When the format version is not one this release reads
     *                                       (reason `UnsupportedFormatVersion`), a key is unknown
     *                                       (`UnknownOptionKey`), either selector is missing or
     *                                       not an array (`WrongValueType`), either cannot be
     *                                       read (the reason its own fromArray() gives), or the
     *                                       two do not go together, as of() says
     *                                       (`IncompatibleOptions`)
     */
    public static function fromArray(array $config): self
    {
        PlainData::checkOptions($config, ['per_group', 'best_of_rank'], 'a bracket qualifiers selector');

        $perGroup = $config['per_group'] ?? null;
        $bestOfRank = $config['best_of_rank'] ?? null;
        if (!is_array($perGroup) || !is_array($bestOfRank)) {
            throw new InvalidConfigurationException(
                'The two selections of a bracket qualifiers selector must be arrays',
                ['per_group' => get_debug_type($perGroup), 'best_of_rank' => get_debug_type($bestOfRank)],
                reason: InvalidConfigurationReason::WrongValueType
            );
        }

        return self::of(
            RankRangeSelector::fromArray(self::keyed($perGroup)),
            BestOfRankSelector::fromArray(self::keyed($bestOfRank))
        );
    }

    /**
     * The plain-data form fromArray() accepts: `format_version`, the
     * version of this form (1), then `per_group` and `best_of_rank`, each
     * the array form of its selector without a format version of its own.
     *
     * @return array{format_version: int, per_group: array<string, mixed>, best_of_rank: array<string, mixed>}
     */
    public function toArray(): array
    {
        $perGroup = $this->perGroup->toArray();
        unset($perGroup[PlainData::VERSION_KEY]);
        $bestOfRank = $this->bestOfRank->toArray();
        unset($bestOfRank[PlainData::VERSION_KEY]);

        return [PlainData::VERSION_KEY => PlainData::VERSION, 'per_group' => $perGroup, 'best_of_rank' => $bestOfRank];
    }

    /**
     * The qualifiers in bracket order: the ranks of the per-group selection,
     * rank by rank, each in the order of the pools that keeps a pool's
     * entrants apart, then the best of the lower rank, best first.
     *
     * Things are checked in this order, and the first that fails is
     * reported: the outcome has pools; it has two or more; the per-group
     * selection is made; the best-of-rank selection is made. The list is
     * that of selection(), which also says which places a decision settled.
     *
     * @return array<Participant>
     * @throws InvalidConfigurationException When the outcome has no pools (reason
     *                                       `IncompatibleOutcome`), it has one
     *                                       (`PoolMatesInFirstRound`), or either selector refuses
     *                                       it (the reason it gives)
     * @throws UndecidedSelectionException When either selector refuses an order that results do
     *                                     not decide
     * @throws RejectedDecisionException When either selector rejects a decision it was given
     */
    #[Override]
    public function select(StageOutcome $outcome): array
    {
        return $this->selection($outcome)->getParticipants();
    }

    /**
     * The selection with its marks: the participants select() returns, in
     * the same order, each with the decisions the selector that selected it
     * marked it with (RankSelection). Placing them for the bracket decides
     * no place by a decision, and marks nothing.
     *
     * @throws InvalidConfigurationException As select()
     * @throws UndecidedSelectionException As select()
     * @throws RejectedDecisionException As select()
     */
    public function selection(StageOutcome $outcome): RankSelection
    {
        if (!$outcome->hasPools()) {
            throw new InvalidConfigurationException(
                'Qualifiers placed for a bracket require a pooled outcome',
                ['rank' => $this->bestOfRank->getRank(), 'count' => $this->bestOfRank->getCount()],
                reason: InvalidConfigurationReason::IncompatibleOutcome
            );
        }

        $poolOf = [];
        $pools = 0;
        foreach ($outcome->getPools() as $poolOutcome) {
            foreach ($poolOutcome->getStandings()->getEntries() as $entry) {
                $poolOf[$entry->getParticipant()->getId()] = $pools;
            }
            ++$pools;
        }

        if ($pools < 2) {
            throw new InvalidConfigurationException(
                'Qualifiers of one pool cannot be kept apart in a bracket: two of them meet in its first round',
                ['pools' => $pools],
                reason: InvalidConfigurationReason::PoolMatesInFirstRound
            );
        }

        $perGroup = $this->perGroup->selection($outcome);
        $bestOfRank = $this->bestOfRank->selection($outcome);

        // The per-group selection lists its ranks block by block, a block
        // holding one entrant of every pool
        $ranks = intdiv(count($perGroup), $pools);
        $byRankAndPool = [];
        foreach ($perGroup->getParticipants() as $index => $participant) {
            $byRankAndPool[intdiv($index, $pools)][$poolOf[$participant->getId()]] = $participant;
        }

        $tail = array_map(
            static fn(Participant $participant): int => $poolOf[$participant->getId()],
            $bestOfRank->getParticipants()
        );

        $placed = [];
        foreach (CrossPoolOrder::blocks($pools, $ranks, $tail) as $rank => $order) {
            foreach ($order as $pool) {
                $placed[] = $byRankAndPool[$rank][$pool];
            }
        }
        $placed = [...$placed, ...$bestOfRank->getParticipants()];

        $marks = [];
        foreach ([$perGroup, $bestOfRank] as $selection) {
            foreach ($selection->getParticipants() as $participant) {
                $marks[$participant->getId()] = $selection->getDecisionsFor($participant);
            }
        }

        return new RankSelection($placed, $marks);
    }

    /**
     * The count per pool of the per-group selection, plus the count of the
     * best-of-rank selection; made from at least 2 pools, or from as many
     * as that count when it is more.
     */
    #[Override]
    public function getYield(): SelectionYield
    {
        return SelectionYield::perPoolPlus(
            $this->perGroup->getTo() - $this->perGroup->getFrom() + 1,
            $this->bestOfRank->getCount(),
            max(2, $this->bestOfRank->getCount())
        );
    }

    /**
     * Null: the count depends on how many pools the outcome has.
     */
    #[Override]
    public function getSelectionSize(): ?int
    {
        return null;
    }

    /**
     * The per-group selection: who is selected from every pool.
     */
    public function getPerGroup(): RankRangeSelector
    {
        return $this->perGroup;
    }

    /**
     * The best-of-rank selection: who is selected across the pools.
     */
    public function getBestOfRank(): BestOfRankSelector
    {
        return $this->bestOfRank;
    }

    /**
     * @param array<mixed> $data
     * @return array<string, mixed>
     */
    private static function keyed(array $data): array
    {
        $keyed = [];
        foreach ($data as $key => $value) {
            $keyed[(string) $key] = $value;
        }

        return $keyed;
    }
}
