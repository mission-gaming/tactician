<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Repack\Internal;

use MissionGaming\Tactician\Repack\RepackObjective;

/**
 * The order in which the figures of a schedule rank it after the number
 * of events placed: the objectives of the options, with gap slots in
 * front of them where the options do not name them
 * (RepackObjective::FewestGapSlots).
 *
 * Where gap slots rank first, the repack is the one the objectives have
 * always made, and the objectives that follow them are what the searches
 * of that repack break their ties by ({@see self::afterGapSlots()}). Where
 * an objective ranks ahead of gap slots, the figures are compared in this
 * order wherever the repacker chooses between two schedules.
 *
 * @internal
 */
final readonly class Ranking
{
    /** @var list<RepackObjective> Every figure ranked, gap slots included, most important first */
    private array $figures;

    /**
     * @param list<RepackObjective> $objectives The options' objectives, in their order
     */
    public function __construct(private PlacementMetrics $metrics, array $objectives)
    {
        $this->figures = in_array(RepackObjective::FewestGapSlots, $objectives, true)
            ? $objectives
            : [RepackObjective::FewestGapSlots, ...$objectives];
    }

    /**
     * The figures of the request the ranking counts.
     */
    public function metrics(): PlacementMetrics
    {
        return $this->metrics;
    }

    /**
     * Whether gap slots rank ahead of every objective: the ranking of a
     * repack with no objective, and of every list that does not name an
     * objective before gap slots.
     */
    public function gapSlotsFirst(): bool
    {
        return $this->figures[0] === RepackObjective::FewestGapSlots;
    }

    /**
     * Every figure ranked, gap slots included, most important first.
     *
     * @return list<RepackObjective>
     */
    public function figures(): array
    {
        return $this->figures;
    }

    /**
     * The objectives that rank after gap slots, in their order.
     *
     * @return list<RepackObjective>
     */
    public function afterGapSlots(): array
    {
        $at = array_search(RepackObjective::FewestGapSlots, $this->figures, true);

        return array_slice($this->figures, (int) $at + 1);
    }

    /**
     * The figures that rank ahead of one of them, in their order. For a
     * figure the ranking does not hold, all of them.
     *
     * @return list<RepackObjective>
     */
    public function aheadOf(RepackObjective $objective): array
    {
        $at = array_search($objective, $this->figures, true);

        return $at === false ? $this->figures : array_slice($this->figures, 0, $at);
    }

    /**
     * Whether a figure ranks ahead of gap slots.
     */
    public function ranksAheadOfGapSlots(RepackObjective $objective): bool
    {
        return in_array($objective, $this->aheadOf(RepackObjective::FewestGapSlots), true);
    }

    /**
     * The figures of a schedule in the order they rank it, lower being
     * better: compared as PHP compares two lists of equal length, the
     * first figure first.
     *
     * @param array<int, array{int, int}> $positions Placed movable event index => [session, slot]
     *
     * @return list<int>
     */
    public function key(array $positions): array
    {
        return $this->metrics->score($positions, $this->figures);
    }

    /**
     * Some of the figures of a schedule, in the order given.
     *
     * @param array<int, array{int, int}> $positions Placed movable event index => [session, slot]
     * @param list<RepackObjective> $figures
     *
     * @return list<int>
     */
    public function keyOf(array $positions, array $figures): array
    {
        return $this->metrics->score($positions, $figures);
    }
}
