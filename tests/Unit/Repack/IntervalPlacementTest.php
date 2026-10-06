<?php

declare(strict_types=1);

use MissionGaming\Tactician\Repack\Internal\IntervalPlacement;
use MissionGaming\Tactician\Repack\Internal\StepBudget;
use MissionGaming\Tactician\Tests\Support\ReferenceIntervalPlacement;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * The fewest odd positions over every way of placing the runs, by trying
 * every way: the definition of the placement score.
 *
 * @param list<int> $lengths
 */
function minOddScoreByExhaustion(int $mask, array $lengths, int $slotCount): int
{
    if ($lengths === []) {
        $odd = 0;
        for ($position = 0; $position < $slotCount; ++$position) {
            $odd += ($mask >> $position) & 1;
        }

        return $odd;
    }

    $length = array_shift($lengths);
    $best = PHP_INT_MAX;
    for ($start = 0; $start + $length <= $slotCount; ++$start) {
        $run = 1 << $start;
        if ($start + $length < $slotCount) {
            $run |= 1 << ($start + $length);
        }
        $best = min($best, minOddScoreByExhaustion($mask ^ $run, $lengths, $slotCount));
    }

    return $best;
}

/**
 * What an enumeration did with a budget: the placements it offered, in
 * order, what it returned, and what it left of the budget.
 *
 * @param callable(int, array<int>, int, StepBudget, callable(array<int>): bool): bool $enumerate
 * @param list<int> $lengths
 * @return array{offered: list<string>, found: bool, stopped: bool, stepsLeft: bool}
 */
function traceEnumeration(callable $enumerate, int $fixedMask, array $lengths, int $slotCount, int $steps, int $acceptAfter): array
{
    $budget = new StepBudget($steps);
    $offered = [];
    $found = $enumerate($fixedMask, $lengths, $slotCount, $budget, static function (array $starts) use (&$offered, $acceptAfter): bool {
        $offered[] = implode(',', $starts);

        return count($offered) === $acceptAfter;
    });

    return ['offered' => $offered, 'found' => $found, 'stopped' => $budget->stoppedASearch(), 'stepsLeft' => $budget->hasStepsLeft()];
}

describe('IntervalPlacement', function (): void {
    it('scores parity-feasible load vectors zero', function (): void {
        // Two runs of length 1 in 2 slots pair up at either slot
        expect(IntervalPlacement::minOddScore(0, [1, 1], 2))->toBe(0);
        // Everyone plays every slot: always feasible in parity terms
        expect(IntervalPlacement::minOddScore(0, [4, 4, 4, 4], 4))->toBe(0);
    });

    it('detects load vectors no run placement can pack gap-free', function (): void {
        // Five runs of length 4 in 5 slots: every run sits out one end
        // slot, so the three interior slots always hold an odd count —
        // the K5 shape has no interval colouring
        expect(IntervalPlacement::minOddScore(0, [4, 4, 4, 4, 4], 5))->toBeGreaterThan(0);
    });

    it('enumerates cheapest total late-start first', function (): void {
        $seen = [];
        IntervalPlacement::enumerate(0, [2, 2], 3, new StepBudget(10_000), function (array $starts) use (&$seen): bool {
            $seen[] = $starts;

            return false;
        });

        // [0,0] (both from the top) must be tried before any late start
        expect($seen[0])->toBe([0, 0]);
        expect($seen)->toContain([1, 1]);
    });

    it('stops when a placement is accepted', function (): void {
        $calls = 0;
        $found = IntervalPlacement::enumerate(0, [1, 1], 2, new StepBudget(10_000), function (array $starts) use (&$calls): bool {
            ++$calls;

            return true;
        });

        expect($found)->toBeTrue();
        expect($calls)->toBe(1);
    });
    it('scores every load vector as trying every placement does', function (): void {
        $randomizer = new Randomizer(new Mt19937(5));
        $nonZero = 0;

        for ($case = 0; $case < 500; ++$case) {
            $slotCount = $randomizer->getInt(1, 6);
            $lengths = [];
            for ($i = $randomizer->getInt(0, 5); $i > 0; --$i) {
                // Now and then a run longer than the session, which fits nowhere.
                $lengths[] = $randomizer->getInt(1, $slotCount + ($randomizer->getInt(0, 19) === 0 ? 1 : 0));
            }
            $fixedMask = $randomizer->getInt(0, (1 << $slotCount) - 1);

            $score = IntervalPlacement::minOddScore($fixedMask, $lengths, $slotCount);
            expect($score)->toBe(minOddScoreByExhaustion($fixedMask, $lengths, $slotCount));
            $nonZero += (int) ($score > 0);
        }

        expect($nonZero)->toBeGreaterThan(100);
    });

    it('scores many runs of one length as applying them one by one does', function (): void {
        // Runs of one length are counted, not applied, once the set of
        // reachable masks starts to repeat. Every count from none to more
        // than any session holds, for every length and several fixed masks,
        // alone and mixed with another length.
        foreach ([1, 2, 3, 5, 8] as $slotCount) {
            for ($length = 1; $length <= $slotCount; ++$length) {
                for ($copies = 0; $copies <= 13; ++$copies) {
                    foreach ([0, 1, (1 << $slotCount) - 1, 5 % (1 << $slotCount)] as $fixedMask) {
                        $lengths = array_fill(0, $copies, $length);
                        expect(IntervalPlacement::minOddScore($fixedMask, $lengths, $slotCount))
                            ->toBe(ReferenceIntervalPlacement::minOddScore($fixedMask, $lengths, $slotCount));

                        $mixed = [...$lengths, 1, ...array_fill(0, intdiv($copies, 2), max(1, $slotCount - 1))];
                        expect(IntervalPlacement::minOddScore($fixedMask, $mixed, $slotCount))
                            ->toBe(ReferenceIntervalPlacement::minOddScore($fixedMask, $mixed, $slotCount));
                    }
                }
            }
        }
    });

    it('scores a load vector the same in any order', function (): void {
        expect(IntervalPlacement::minOddScore(0b0101, [3, 1, 2, 2, 1], 5))
            ->toBe(IntervalPlacement::minOddScore(0b0101, [1, 1, 2, 2, 3], 5))
            ->toBe(IntervalPlacement::minOddScore(0b0101, [2, 3, 1, 2, 1], 5));
    });

    it('offers the same placements in the same order and spends the same steps', function (): void {
        $randomizer = new Randomizer(new Mt19937(8));
        $stopped = 0;
        $found = 0;

        for ($case = 0; $case < 400; ++$case) {
            $slotCount = $randomizer->getInt(1, 5);
            $lengths = [];
            for ($i = $randomizer->getInt(0, 5); $i > 0; --$i) {
                $lengths[] = $randomizer->getInt(1, $slotCount);
            }
            $fixedMask = $randomizer->getInt(0, 3) === 0 ? $randomizer->getInt(0, (1 << $slotCount) - 1) : 0;
            $acceptAfter = [0, 0, 1, 3, 20][$randomizer->getInt(0, 4)];

            // Every budget from none to plenty: the two must stop at the
            // same step, whichever step that is.
            foreach ([0, 1, 2, 3, 4, 6, 9, 14, 30, 100, 5000] as $steps) {
                $trace = traceEnumeration(IntervalPlacement::enumerate(...), $fixedMask, $lengths, $slotCount, $steps, $acceptAfter);
                expect($trace)->toBe(traceEnumeration(ReferenceIntervalPlacement::enumerate(...), $fixedMask, $lengths, $slotCount, $steps, $acceptAfter));
                $stopped += (int) $trace['stopped'];
                $found += (int) $trace['found'];
            }
        }

        expect($stopped)->toBeGreaterThan(500);
        expect($found)->toBeGreaterThan(300);
    });

    it('records a spent budget as stopping the enumeration only when there was one to stop', function (): void {
        $never = static fn(array $starts): bool => false;

        // Two runs of length 1 in 2 slots can pair up: there is a placement
        // to enumerate, and the spent budget is what stops it.
        $spent = new StepBudget(1);
        $spent->consume();
        expect(IntervalPlacement::enumerate(0, [1, 1], 2, $spent, $never))->toBeFalse();
        expect($spent->stoppedASearch())->toBeTrue();

        // One run of length 1 in 2 slots leaves an odd position wherever it
        // goes: nothing to enumerate, so the budget stopped nothing.
        $spent = new StepBudget(1);
        $spent->consume();
        expect(IntervalPlacement::enumerate(0, [1], 2, $spent, $never))->toBeFalse();
        expect($spent->stoppedASearch())->toBeFalse();
    });

    it('decides a wide session on a spent budget without the cost table', function (): void {
        // Sixteen slots and fourteen participants of mixed loads: the cost
        // table holds a row of masks for every participant, 13 MB in all
        // when measured. With no step left, only whether a placement exists
        // is needed, which two sets of masks answer in about 3 MB.
        $spent = new StepBudget(1);
        $spent->consume();
        $before = memory_get_usage();
        memory_reset_peak_usage();

        $found = IntervalPlacement::enumerate(
            0,
            [3, 3, 4, 4, 5, 5, 6, 6, 7, 7, 2, 2, 1, 1],
            16,
            $spent,
            static fn(array $starts): bool => false
        );

        expect($found)->toBeFalse();
        expect($spent->stoppedASearch())->toBeTrue();
        expect(memory_get_peak_usage() - $before)->toBeLessThan(7 * 1024 * 1024);
    });
});
