<?php

declare(strict_types=1);

use MissionGaming\Tactician\Scheduling\PerfectMatching;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Whether the vertices can be split into adjacent pairs, by trying every
 * split: the definition, with nothing clever in it.
 *
 * @param list<int> $vertices
 * @param array<int, array<int, true>> $edges Symmetric adjacency
 */
function hasPerfectMatchingByExhaustion(array $vertices, array $edges): bool
{
    if ($vertices === []) {
        return true;
    }

    $first = array_shift($vertices);
    foreach ($vertices as $position => $partner) {
        if (!isset($edges[$first][$partner])) {
            continue;
        }
        $rest = $vertices;
        unset($rest[$position]);
        if (hasPerfectMatchingByExhaustion(array_values($rest), $edges)) {
            return true;
        }
    }

    return false;
}

/**
 * The graph on vertices 0..$size-1 whose edges are the set bits of $bits,
 * the pairs taken in the order (0,1), (0,2), ..., (1,2), ...
 *
 * @return array<int, array<int, true>>
 */
function graphFromBits(int $size, int $bits): array
{
    $edges = [];
    $bit = 0;
    for ($a = 0; $a < $size; ++$a) {
        for ($b = $a + 1; $b < $size; ++$b) {
            if ((($bits >> $bit) & 1) === 1) {
                $edges[$a][$b] = true;
                $edges[$b][$a] = true;
            }
            ++$bit;
        }
    }

    return $edges;
}

/**
 * @param array<int, int>|null $mate
 * @param list<int> $vertices
 * @param array<int, array<int, true>> $edges
 */
function expectAPerfectMatchingOrNone(?array $mate, array $vertices, array $edges): void
{
    $exists = hasPerfectMatchingByExhaustion($vertices, $edges);
    expect($mate !== null)->toBe($exists);
    if ($mate === null) {
        return;
    }

    // What is returned must itself be a perfect matching of the graph.
    $keys = array_keys($mate);
    sort($keys);
    expect($keys)->toBe($vertices);
    foreach ($mate as $vertex => $partner) {
        expect(isset($edges[$vertex][$partner]))->toBeTrue();
        expect($mate[$partner])->toBe($vertex);
    }
}

describe('PerfectMatching', function (): void {
    it('decides every graph on up to six vertices as exhaustion does', function (): void {
        // 2, 64 and 32,768 graphs: every graph there is on 2, 4 and 6
        // vertices, which includes every odd cycle the search must contract.
        foreach ([2, 4, 6] as $size) {
            $vertices = range(0, $size - 1);
            $pairs = intdiv($size * ($size - 1), 2);
            for ($bits = 0; $bits < (1 << $pairs); ++$bits) {
                $edges = graphFromBits($size, $bits);
                $matching = new PerfectMatching(static fn(int $a, int $b): bool => isset($edges[$a][$b]));

                expectAPerfectMatchingOrNone($matching->of($vertices), $vertices, $edges);
            }
        }
    });

    it('decides sparse graphs on up to twelve vertices as exhaustion does', function (): void {
        $randomizer = new Randomizer(new Mt19937(12));

        for ($case = 0; $case < 1500; ++$case) {
            $size = 2 * $randomizer->getInt(4, 6);
            $vertices = range(0, $size - 1);
            // Around the density where a perfect matching may or may not
            // exist, so both answers come up and blossoms are common.
            $edges = [];
            for ($a = 0; $a < $size; ++$a) {
                for ($b = $a + 1; $b < $size; ++$b) {
                    if ($randomizer->getInt(0, 99) < 22) {
                        $edges[$a][$b] = true;
                        $edges[$b][$a] = true;
                    }
                }
            }
            $matching = new PerfectMatching(static fn(int $a, int $b): bool => isset($edges[$a][$b]));

            expectAPerfectMatchingOrNone($matching->of($vertices), $vertices, $edges);
        }
    });

    it('keeps the matching right as pairs of vertices are taken away', function (): void {
        $randomizer = new Randomizer(new Mt19937(2));
        $checked = 0;
        $lost = 0;

        for ($case = 0; $case < 600; ++$case) {
            $size = 2 * $randomizer->getInt(2, 5);
            $vertices = range(0, $size - 1);
            $edges = [];
            for ($a = 0; $a < $size; ++$a) {
                for ($b = $a + 1; $b < $size; ++$b) {
                    if ($randomizer->getInt(0, 99) < 45) {
                        $edges[$a][$b] = true;
                        $edges[$b][$a] = true;
                    }
                }
            }
            $matching = new PerfectMatching(static fn(int $a, int $b): bool => isset($edges[$a][$b]));
            $mate = $matching->of($vertices);

            // Take pairs away until nothing is left or no matching remains.
            while ($mate !== null && $vertices !== []) {
                $first = $vertices[$randomizer->getInt(0, count($vertices) - 1)];
                do {
                    $second = $vertices[$randomizer->getInt(0, count($vertices) - 1)];
                } while ($second === $first);

                $before = $mate;
                $vertices = array_values(array_diff($vertices, [$first, $second]));
                $mate = $matching->without($mate, $first, $second);

                expectAPerfectMatchingOrNone($mate, $vertices, $edges);
                // The matching handed in is the caller's to keep using.
                expect(count($before))->toBe(count($vertices) + 2);
                ++$checked;
                $lost += (int) ($mate === null);
            }
        }

        expect($checked)->toBeGreaterThan(600);
        expect($lost)->toBeGreaterThan(100);
    });

    it('refuses an odd number of vertices', function (): void {
        $matching = new PerfectMatching(static fn(int $a, int $b): bool => true);

        expect($matching->of([0, 1, 2]))->toBeNull();
        expect($matching->of([]))->toBe([]);
    });

    it('reads only the pairs the search reaches', function (): void {
        // A complete graph on 40 vertices has 780 pairs; pairing them off
        // greedily needs one look per vertex paired.
        $asked = 0;
        $matching = new PerfectMatching(static function (int $a, int $b) use (&$asked): bool {
            ++$asked;

            return true;
        });

        expect($matching->of(range(0, 39)))->not->toBeNull();
        expect($asked)->toBe(20);
    });
});
