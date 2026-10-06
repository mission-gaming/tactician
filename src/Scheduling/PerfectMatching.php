<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\Scheduling;

use Closure;

/**
 * Decides whether a set of vertices can be split into adjacent pairs with
 * no vertex left over (a perfect matching of a general graph), and keeps
 * such a matching up to date as pairs of vertices are taken away.
 *
 * The Swiss pairing search asks this before it descends: a set of unpaired
 * participants that has no perfect matching among the pairs still open to
 * it has no complete pairing, so the search need not look for one.
 *
 * The algorithm is Edmonds' blossom algorithm, one augmenting path search
 * at a time. Two facts carry the answers:
 *
 * - **Berge's theorem.** A matching is maximum exactly when no augmenting
 *   path exists for it: a path that starts and ends at an unmatched vertex
 *   and alternates between edges outside and inside the matching.
 * - **Edmonds' search is complete.** From an unmatched vertex it finds an
 *   augmenting path whenever one starts there, contracting each odd cycle
 *   (blossom) it meets. When none starts at a vertex, none ever will after
 *   other augmentations, so that vertex is unmatched in some maximum
 *   matching and the graph has no perfect matching.
 *
 * Vertices are integers. The edges are read through a callback, one pair at
 * a time and only when the search reaches that pair, so a caller with a
 * costly edge test pays for the pairs looked at and not for the whole graph.
 * The callback must answer the same for (a, b) and (b, a) and must not
 * change between calls.
 *
 * @internal Not public API.
 */
final class PerfectMatching
{
    private const int NONE = -1;

    /** @var array<int, int> Parent of a vertex in the search forest */
    private array $parent = [];

    /** @var array<int, int> The vertex that stands for the blossom a vertex is in */
    private array $base = [];

    /**
     * @param Closure(int, int): bool $adjacent Whether two different vertices may be paired
     */
    public function __construct(private readonly Closure $adjacent) {}

    /**
     * A perfect matching of the given vertices, or null when there is none.
     *
     * @param list<int> $vertices Distinct vertices
     * @return array<int, int>|null Each vertex mapped to its partner
     */
    public function of(array $vertices): ?array
    {
        if (count($vertices) % 2 !== 0) {
            return null;
        }

        // A greedy pass pairs most vertices of a dense graph; the search
        // below then has little left to do.
        $mate = array_fill_keys($vertices, self::NONE);
        foreach ($vertices as $position => $vertex) {
            if ($mate[$vertex] !== self::NONE) {
                continue;
            }
            for ($next = $position + 1, $count = count($vertices); $next < $count; ++$next) {
                $candidate = $vertices[$next];
                if ($mate[$candidate] === self::NONE && ($this->adjacent)($vertex, $candidate)) {
                    $mate[$vertex] = $candidate;
                    $mate[$candidate] = $vertex;
                    break;
                }
            }
        }

        foreach ($vertices as $vertex) {
            if ($mate[$vertex] === self::NONE && !$this->augmentFrom($vertex, $mate)) {
                // No augmenting path starts here, so some maximum matching
                // leaves this vertex out: the matching cannot be perfect.
                return null;
            }
        }

        return $mate;
    }

    /**
     * The matching of what is left when two vertices are taken out of a
     * perfectly matched set, or null when what is left has no perfect
     * matching.
     *
     * Taking out two vertices that are partners leaves a perfect matching.
     * Otherwise their two partners are left unmatched, and they are the
     * only unmatched vertices: by Berge's theorem the rest has a perfect
     * matching exactly when an augmenting path joins those two, which one
     * search decides.
     *
     * @param array<int, int> $mate A perfect matching; not changed
     * @return array<int, int>|null
     */
    public function without(array $mate, int $first, int $second): ?array
    {
        $firstPartner = $mate[$first];
        $secondPartner = $mate[$second];
        unset($mate[$first], $mate[$second]);

        if ($firstPartner === $second) {
            return $mate;
        }

        $mate[$firstPartner] = self::NONE;
        $mate[$secondPartner] = self::NONE;

        if (($this->adjacent)($firstPartner, $secondPartner)) {
            $mate[$firstPartner] = $secondPartner;
            $mate[$secondPartner] = $firstPartner;

            return $mate;
        }

        return $this->augmentFrom($firstPartner, $mate) ? $mate : null;
    }

    /**
     * Search for an augmenting path from an unmatched vertex and, when one
     * exists, flip the matching along it.
     *
     * @param array<int, int> $mate Changed only when a path is found
     */
    private function augmentFrom(int $root, array &$mate): bool
    {
        $this->parent = [];
        $this->base = [];
        $inTree = [];
        foreach (array_keys($mate) as $vertex) {
            $this->parent[$vertex] = self::NONE;
            $this->base[$vertex] = $vertex;
            $inTree[$vertex] = false;
        }

        $inTree[$root] = true;
        $queue = [$root];

        for ($head = 0; $head < count($queue); ++$head) {
            $vertex = $queue[$head];

            foreach (array_keys($mate) as $to) {
                if ($to === $vertex
                    || $this->base[$vertex] === $this->base[$to]
                    || $mate[$vertex] === $to
                    || !($this->adjacent)($vertex, $to)
                ) {
                    continue;
                }

                $toMate = $mate[$to];
                if ($to === $root || ($toMate !== self::NONE && $this->parent[$toMate] !== self::NONE)) {
                    // An edge between two outer vertices closes an odd
                    // cycle: contract it into its base.
                    $blossomBase = $this->lowestCommonAncestor($vertex, $to, $mate);
                    $inBlossom = [];
                    $this->markPath($vertex, $blossomBase, $to, $mate, $inBlossom);
                    $this->markPath($to, $blossomBase, $vertex, $mate, $inBlossom);

                    foreach (array_keys($mate) as $member) {
                        if (isset($inBlossom[$this->base[$member]])) {
                            $this->base[$member] = $blossomBase;
                            if (!$inTree[$member]) {
                                $inTree[$member] = true;
                                $queue[] = $member;
                            }
                        }
                    }
                } elseif ($this->parent[$to] === self::NONE) {
                    $this->parent[$to] = $vertex;

                    if ($toMate === self::NONE) {
                        // An unmatched vertex ends the path: flip it.
                        for ($end = $to; $end !== self::NONE; $end = $beyond) {
                            $before = $this->parent[$end];
                            $beyond = $mate[$before];
                            $mate[$end] = $before;
                            $mate[$before] = $end;
                        }

                        return true;
                    }

                    $inTree[$toMate] = true;
                    $queue[] = $toMate;
                }
            }
        }

        return false;
    }

    /**
     * @param array<int, int> $mate
     */
    private function lowestCommonAncestor(int $first, int $second, array $mate): int
    {
        $onFirstPath = [];
        while (true) {
            $first = $this->base[$first];
            $onFirstPath[$first] = true;
            if ($mate[$first] === self::NONE) {
                break;
            }
            $first = $this->parent[$mate[$first]];
        }

        while (true) {
            $second = $this->base[$second];
            if (isset($onFirstPath[$second])) {
                return $second;
            }
            $second = $this->parent[$mate[$second]];
        }
    }

    /**
     * @param array<int, int> $mate
     * @param array<int, true> $inBlossom
     */
    private function markPath(int $vertex, int $blossomBase, int $child, array $mate, array &$inBlossom): void
    {
        while ($this->base[$vertex] !== $blossomBase) {
            $inBlossom[$this->base[$vertex]] = true;
            $inBlossom[$this->base[$mate[$vertex]]] = true;
            $this->parent[$vertex] = $child;
            $child = $mate[$vertex];
            $vertex = $this->parent[$mate[$vertex]];
        }
    }
}
