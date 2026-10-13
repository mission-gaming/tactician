<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

/**
 * The order in which a per-group rank selection lists the pools, rank by
 * rank, so that a bracket folded from the list keeps the entrants of one
 * pool apart.
 *
 * A selection of several ranks from every pool is a list of blocks: every
 * pool's first selected rank, then every pool's second, and so on. A
 * bracket takes its entrants from list positions (BracketFold), so the
 * order of the pools inside each block decides who meets whom. The first
 * block is in pool order. With every block in pool order, two entrants of
 * one pool meet in the first round with three pools (C1 against C2), and
 * with four or eight pools a pool's first and second are in the same half.
 *
 * Each later block is therefore ordered against the blocks before it: of
 * all the ways to give its positions to the pools, the one in which
 * entrants of one pool can first meet as late as possible. "As late as
 * possible" is lexicographic: as few pairs of one pool as can be that can
 * meet in round 1, then as few in round 2, and so on. Among orders that
 * are equal in that, the one nearest to pool order is taken, so two pools
 * are still listed A, B: A1, B1, A2, B2.
 *
 * Blocks are settled one after another, each for good; an earlier block is
 * not revisited for the sake of a later one. For two ranks per pool that
 * is the best order there is. The order is a function of the two numbers
 * alone: no entrant, label or result is read.
 *
 * The position of an entrant is the one it has when the whole selection is
 * the entrant list of a bracket. A selection that is put elsewhere (into
 * pools again, or into a bracket together with other entrants) is in an
 * order that means nothing in particular there.
 *
 * @internal Not public API: how RankRangeSelector orders a per-group selection.
 */
final class CrossPoolOrder
{
    /**
     * The pools in list order for each block of a selection.
     *
     * @param int $pools How many pools the outcome has, at least 1
     * @param int $ranks How many ranks are selected from each pool, at least 1
     * @return list<list<int>> One list per rank: the 0-based pool indices in the order their
     *                         entrants of that rank are listed
     */
    public static function blocks(int $pools, int $ranks): array
    {
        $size = BracketFold::size($pools * $ranks);
        $slotOf = array_flip(BracketFold::positions($size));
        $rounds = (int) round(log($size, 2));

        // The cost of two entrants of one pool that can first meet in
        // round r is base^(depth - r): one such pair in an earlier round
        // outweighs every pair of the later rounds together, because no
        // block has as many as `base` pairs. The depth is as many rounds
        // as an integer can hold apart; rounds beyond it are not told
        // apart (reached only by selections of hundreds of entrants).
        $base = $pools * max(1, $ranks - 1) + 1;
        $nearness = $pools * $pools + 1;
        $limit = intdiv(PHP_INT_MAX, $nearness * $base * $pools * $ranks);
        // $weights[$r] is the cost of a pair that can first meet in round
        // $r; the last round told apart costs 1.
        $powers = [1];
        while (count($powers) < $rounds && $powers[count($powers) - 1] <= intdiv($limit, $base)) {
            $powers[] = $powers[count($powers) - 1] * $base;
        }
        $depth = count($powers);
        $weights = [];
        foreach ($powers as $exponent => $power) {
            $weights[$depth - $exponent] = $power;
        }

        /** @var list<list<int>> $blocks */
        $blocks = [range(0, $pools - 1)];
        /** @var array<int, list<int>> $slotsOfPool The bracket slots a pool's entrants hold so far */
        $slotsOfPool = [];
        for ($pool = 0; $pool < $pools; ++$pool) {
            $slotsOfPool[$pool] = [$slotOf[$pool + 1]];
        }

        for ($block = 1; $block < $ranks; ++$block) {
            $costs = [];
            for ($pool = 0; $pool < $pools; ++$pool) {
                for ($index = 0; $index < $pools; ++$index) {
                    $slot = $slotOf[$block * $pools + $index + 1];
                    $cost = 0;
                    foreach ($slotsOfPool[$pool] as $held) {
                        $cost += $weights[self::meetingRound($slot, $held)] ?? 0;
                    }
                    $costs[$pool][$index] = $cost * $nearness + abs($pool - $index);
                }
            }

            $indexOfPool = self::assign($costs);
            $order = array_flip($indexOfPool);
            ksort($order);
            $blocks[] = array_values($order);

            foreach ($indexOfPool as $pool => $index) {
                $slotsOfPool[$pool][] = $slotOf[$block * $pools + $index + 1];
            }
        }

        return $blocks;
    }

    /**
     * The first round in which the entrants of two bracket slots can meet.
     */
    private static function meetingRound(int $one, int $other): int
    {
        $round = 0;
        while ($one !== $other) {
            $one = intdiv($one, 2);
            $other = intdiv($other, 2);
            ++$round;
        }

        return $round;
    }

    /**
     * A cheapest assignment of rows to columns of a square matrix of
     * integer costs: for every row a column of its own, with the lowest
     * total there is. The Hungarian method with potentials; which of
     * several cheapest assignments is returned depends only on the matrix.
     *
     * @param array<int, array<int, int>> $costs Cost of each row (first key) in each column, both keyed from 0
     * @return array<int, int> The column of each row
     */
    private static function assign(array $costs): array
    {
        $count = count($costs);
        $rowPotential = array_fill(0, $count + 1, 0);
        $columnPotential = array_fill(0, $count + 1, 0);
        // Rows and columns are numbered from 1 inside; 0 is the column of
        // no row, where every search starts.
        $rowOfColumn = array_fill(0, $count + 1, 0);
        $previousColumn = array_fill(0, $count + 1, 0);

        for ($row = 1; $row <= $count; ++$row) {
            $rowOfColumn[0] = $row;
            $column = 0;
            $slack = array_fill(0, $count + 1, PHP_INT_MAX);
            $visited = array_fill(0, $count + 1, false);

            do {
                $visited[$column] = true;
                $current = $rowOfColumn[$column];
                $step = PHP_INT_MAX;
                $next = 0;

                for ($candidate = 1; $candidate <= $count; ++$candidate) {
                    if ($visited[$candidate]) {
                        continue;
                    }

                    $reduced = $costs[$current - 1][$candidate - 1] - $rowPotential[$current] - $columnPotential[$candidate];
                    if ($reduced < $slack[$candidate]) {
                        $slack[$candidate] = $reduced;
                        $previousColumn[$candidate] = $column;
                    }
                    if ($slack[$candidate] < $step) {
                        $step = $slack[$candidate];
                        $next = $candidate;
                    }
                }

                for ($candidate = 0; $candidate <= $count; ++$candidate) {
                    if ($visited[$candidate]) {
                        $rowPotential[$rowOfColumn[$candidate]] += $step;
                        $columnPotential[$candidate] -= $step;
                    } else {
                        $slack[$candidate] -= $step;
                    }
                }

                $column = $next;
            } while ($rowOfColumn[$column] !== 0);

            do {
                $before = $previousColumn[$column];
                $rowOfColumn[$column] = $rowOfColumn[$before];
                $column = $before;
            } while ($column !== 0);
        }

        $columnOfRow = [];
        for ($column = 1; $column <= $count; ++$column) {
            $columnOfRow[$rowOfColumn[$column] - 1] = $column - 1;
        }
        ksort($columnOfRow);

        return $columnOfRow;
    }
}
