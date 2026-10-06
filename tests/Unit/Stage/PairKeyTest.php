<?php

declare(strict_types=1);

use MissionGaming\Tactician\Stage\PairKey;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * The key every pairing map used before the shared helper existed.
 *
 * @param array<string> $ids
 */
function legacyPairKey(array $ids): string
{
    sort($ids);

    return implode('|', $ids);
}

/**
 * Every order of a list.
 *
 * @param list<string> $items
 * @return list<list<string>>
 */
function permutationsOf(array $items): array
{
    if (count($items) < 2) {
        return [$items];
    }

    $permutations = [];
    foreach ($items as $index => $item) {
        $rest = $items;
        unset($rest[$index]);
        foreach (permutationsOf(array_values($rest)) as $permutation) {
            $permutations[] = [$item, ...$permutation];
        }
    }

    return $permutations;
}

describe('PairKey', function (): void {
    // The compatibility promise: for ids that need neither the tiebreak nor
    // the escape, the key is the one the library has always built. The
    // expected strings are written out, not computed, so a change of order
    // or of encoding for ordinary ids fails here.
    it('builds the key ordinary ids have always had', function (array $ids, string $expected): void {
        expect(PairKey::of(...$ids))->toBe($expected)
            ->and(PairKey::of(...array_reverse($ids)))->toBe($expected);
    })->with([
        'letters' => [['a', 'b'], 'a|b'],
        'prefixed numbers compare as strings' => [['p9', 'p10'], 'p10|p9'],
        'plain decimals compare as numbers' => [['9', '10'], '9|10'],
        'decimals of three lengths' => [['100', '9', '10'], '9|10|100'],
        'a decimal and a word' => [['10', 'a1'], '10|a1'],
        'a decimal and a word that starts with a digit' => [['9', '10a'], '10a|9'],
        'slugs' => [['team-b', 'team-a'], 'team-a|team-b'],
        'uuids' => [
            ['7f1c5d2e-0b7a-4c58-9a1e-3d2f6b8c9e10', '0a4e6f7b-2c1d-4e3f-8a9b-1c2d3e4f5a6b'],
            '0a4e6f7b-2c1d-4e3f-8a9b-1c2d3e4f5a6b|7f1c5d2e-0b7a-4c58-9a1e-3d2f6b8c9e10',
        ],
        'colons and spaces' => [['club:2', 'club 1'], 'club 1|club:2'],
        'accented letters' => [['é', 'e'], 'e|é'],
        'mixed case' => [['B', 'a'], 'B|a'],
        'negative numbers' => [['-2', '-10'], '-10|-2'],
    ]);

    it('matches sort() and implode() for every ordinary id list', function (int $seed): void {
        $randomizer = new Randomizer(new Mt19937($seed));
        $letters = ['a', 'b', 'c', 'X', 'Y', 'Z', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '-', '_', ':', '.', ' ', 'é'];

        $consistent = 0;
        $repaired = 0;

        for ($case = 0; $case < 400; ++$case) {
            $ids = [];
            $size = $randomizer->getInt(2, 5);
            while (count($ids) < $size) {
                $id = '';
                $length = $randomizer->getInt(1, 4);
                for ($position = 0; $position < $length; ++$position) {
                    $id .= $letters[$randomizer->getInt(0, count($letters) - 1)];
                }
                $ids[$id] = $id;
            }
            $ids = array_map(strval(...), array_values($ids));

            // Leave out the lists the helper exists to repair: two
            // different ids that PHP compares as equal.
            foreach ($ids as $first) {
                foreach ($ids as $second) {
                    if ($first !== $second && ($first <=> $second) === 0) {
                        continue 3;
                    }
                }
            }

            // PHP's comparison is not transitive over some lists of three
            // or more ('2' < '10' < '1a' < '2'), and sort() gave such a list
            // a key that depended on the order it was given in. Where
            // sort() gave one key, the helper gives that key; where it gave
            // several, the helper gives one.
            $legacyKeys = [];
            $keys = [];
            foreach (permutationsOf($ids) as $permutation) {
                $legacyKeys[legacyPairKey($permutation)] = true;
                $keys[PairKey::of(...$permutation)] = true;
            }

            expect($keys)->toHaveCount(1, 'ids: ' . json_encode($ids));
            if (count($legacyKeys) === 1) {
                expect(array_key_first($keys))->toBe(array_key_first($legacyKeys), 'ids: ' . json_encode($ids));
                ++$consistent;
            } else {
                ++$repaired;
            }
        }

        // Both kinds of list were drawn, so neither branch above is idle.
        expect($consistent)->toBeGreaterThan(300)
            ->and($repaired)->toBeGreaterThan(0);
    })->with([[1], [2], [3]]);

    it('gives three ids one key in every order, where the comparison is not transitive', function (): void {
        // '2' < '10' as numbers, '10' < '1a' and '1a' < '2' as text.
        $keys = [];
        foreach (permutationsOf(['2', '10', '1a']) as $permutation) {
            $keys[PairKey::of(...$permutation)] = true;
            expect(PairKey::order($permutation))->toBe(PairKey::order(['2', '10', '1a']));
        }

        expect(array_keys($keys))->toBe(['10|1a|2']);
    });

    it('keeps the order sort() gave a longer list that it ordered consistently', function (array $ids, string $expected): void {
        foreach (permutationsOf($ids) as $permutation) {
            expect(PairKey::of(...$permutation))->toBe($expected)
                ->and(legacyPairKey($permutation))->toBe($expected);
        }
    })->with([
        'decimals' => [['100', '9', '10', '2'], '2|9|10|100'],
        'words' => [['c', 'a', 'b'], 'a|b|c'],
        'prefixed numbers' => [['p10', 'p9', 'p100'], 'p10|p100|p9'],
        'negative and positive decimals' => [['-1', '10', '9'], '-1|9|10'],
    ]);

    it('gives three ids one key when two of them are equal as numbers', function (): void {
        $keys = [];
        foreach (permutationsOf(['1', '01', '2']) as $permutation) {
            $keys[PairKey::of(...$permutation)] = true;
        }

        expect(array_keys($keys))->toBe(['01|1|2']);
    });

    it('orders plain decimal ids as numbers and reports the order', function (): void {
        expect(PairKey::order(['10', '9']))->toBe(['9', '10'])
            ->and(PairKey::order(['b', 'a', 'c']))->toBe(['a', 'b', 'c']);
    });

    it('gives numerically equal ids one key in either order', function (string $first, string $second, string $expected): void {
        expect(PairKey::of($first, $second))->toBe($expected)
            ->and(PairKey::of($second, $first))->toBe($expected);
    })->with([
        'a leading zero' => ['1', '01', '01|1'],
        'an exponent' => ['1e3', '1000', '1000|1e3'],
        'two zero exponents' => ['0e2', '0e1', '0e1|0e2'],
        'a decimal point' => ['1.0', '1', '1|1.0'],
        'a sign' => ['+1', '1', '+1|1'],
        'leading whitespace' => ['1', ' 1', ' 1|1'],
        'trailing whitespace' => ['1 ', '1', '1|1 '],
        'negative zero' => ['0', '-0', '-0|0'],
    ]);

    it('compares two ids as equal only when they are identical', function (): void {
        expect(PairKey::compare('01', '1'))->toBe(-1)
            ->and(PairKey::compare('1', '01'))->toBe(1)
            ->and(PairKey::compare('1', '1'))->toBe(0)
            ->and(PairKey::compare('9', '10'))->toBe(-1)
            ->and(PairKey::compare('b', 'a'))->toBe(1);
    });

    it('escapes the separator and the escape character', function (array $ids, string $expected): void {
        expect(PairKey::of(...$ids))->toBe($expected);
    })->with([
        'a separator in the second id' => [['a', 'b|c'], 'a|b\|c'],
        'a separator in the first id' => [['a|b', 'c'], 'a\|b|c'],
        'an id that is the separator' => [['|', 'a'], 'a|\|'],
        'an id that ends in the escape character' => [['a\\', 'b'], 'a\\\\|b'],
        'an escaped separator written by hand' => [['a\\|b', 'c'], 'a\\\\\\|b|c'],
    ]);

    // A pair takes a shorter route through the helper than a longer list;
    // order() and join() are the long route, spelled out.
    it('builds a pair as it builds any list', function (): void {
        $ids = [
            'a', 'b', '9', '10', 'p9', 'p10', '10a', '01', '1', '1e3', '1000', '1.0', '0', '-0', '',
            ' ', "\0", 'a|b', 'b|c', '|', '||', '\\', '\\\\', 'a\\', '\\|b', '|b', 'é',
        ];

        foreach ($ids as $first) {
            foreach ($ids as $second) {
                expect(PairKey::of($first, $second))->toBe(
                    PairKey::join(PairKey::order([$first, $second])),
                    'ids: ' . json_encode([$first, $second])
                );
            }
        }

        // Named arguments reach the helper as a keyed list.
        expect(PairKey::of(first: '10', second: '9'))->toBe('9|10');
    });

    it('gives different id lists different keys', function (): void {
        $ids = [
            'a', 'b', 'c', 'a|b', 'b|c', 'a|b|c', '|', '||', '\\', '\\\\', '\\|', '|\\',
            'a\\', '\\a', 'a\\|b', 'a|\\b', '01', '1', '001', '1e3', '1000', '1.0', '0', '00',
            '-0', ' ', '  ', '1 ', ' 1', "\t", "\0", "\0bye", ':', '1:a', 'a:1', 'é', "e\u{301}", '日本',
        ];

        $keys = [];
        foreach ($ids as $first) {
            foreach ($ids as $second) {
                if ($first === $second) {
                    continue;
                }

                $key = PairKey::of($first, $second);
                expect(PairKey::of($second, $first))->toBe($key);

                $pair = PairKey::order([$first, $second]);
                expect($keys[$key] ?? $pair)->toBe($pair, 'two pairs share the key ' . json_encode($key));
                $keys[$key] = $pair;
            }
        }

        // A single id never shares a key with a pair, nor a pair with a triple.
        foreach ($ids as $id) {
            expect(isset($keys[PairKey::of($id)]))->toBeFalse();
            expect(isset($keys[PairKey::of($id, 'x', 'y')]))->toBeFalse();
        }
    });
});
