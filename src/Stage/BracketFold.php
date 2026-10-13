<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Stage;

/**
 * The fold that places an entrant list into a knockout bracket, in one
 * place: the elimination engines place entrants by it, and a per-group rank
 * selection reads it to keep the entrants of one pool apart.
 *
 * @internal Not public API: the arithmetic of the fold the engines apply.
 */
final class BracketFold
{
    /**
     * The smallest bracket that holds the entrants: a power of two, at
     * least 2.
     */
    public static function size(int $entrants): int
    {
        $size = 2;
        while ($size < $entrants) {
            $size *= 2;
        }

        return $size;
    }

    /**
     * The list position (1-based) that stands in each slot of a bracket of
     * the given size, in slot order. Consecutive slots are a first-round
     * tie, and the two positions of a tie add up to size + 1, so the top
     * positions can only meet in the latest possible round.
     *
     * @return list<int>
     */
    public static function positions(int $size): array
    {
        $positions = [1];
        while (count($positions) < $size) {
            $target = count($positions) * 2 + 1;
            $next = [];
            foreach ($positions as $position) {
                $next[] = $position;
                $next[] = $target - $position;
            }
            $positions = $next;
        }

        return $positions;
    }
}
