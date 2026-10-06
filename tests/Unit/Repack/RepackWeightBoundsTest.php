<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Repack\MovableEvent;
use MissionGaming\Tactician\Repack\RepackOptions;
use MissionGaming\Tactician\Repack\RepackRequest;
use MissionGaming\Tactician\Repack\ScheduleRepacker;
use MissionGaming\Tactician\Repack\SessionGrid;

/**
 * The configuration error a call ends in, or null when it ends in none.
 *
 * @param Closure(): mixed $call
 */
function weightError(Closure $call): ?InvalidConfigurationException
{
    try {
        $call();
    } catch (InvalidConfigurationException $e) {
        return $e;
    }

    return null;
}

// The repacker scores a move as at most
// earlyFillWeight x (sessions - 1) + 2 x consolidationWeight. The bounds
// are exactly where that stops being an integer.
describe('RepackOptions weight bounds', function (): void {
    it('accepts a consolidation weight whose double is still an integer', function (): void {
        $options = new RepackOptions(consolidationWeight: RepackOptions::MAX_CONSOLIDATION_WEIGHT);

        expect(RepackOptions::MAX_CONSOLIDATION_WEIGHT)->toBe(intdiv(PHP_INT_MAX, 2));
        expect(2 * $options->consolidationWeight)->toBeInt();
    });

    it('rejects a consolidation weight whose double is not', function (): void {
        $weight = RepackOptions::MAX_CONSOLIDATION_WEIGHT + 1;
        // What the objective would have computed
        expect(2 * $weight)->toBeFloat();

        $error = weightError(fn() => new RepackOptions(consolidationWeight: $weight));

        expect($error?->getReason())->toBe(InvalidConfigurationReason::ValueOutOfRange);
        expect($error?->getContext())->toBe(['consolidation_weight' => $weight, 'largest' => RepackOptions::MAX_CONSOLIDATION_WEIGHT]);
        expect(weightError(fn() => RepackOptions::fromArray(['consolidation_weight' => PHP_INT_MAX]))?->getReason())
            ->toBe(InvalidConfigurationReason::ValueOutOfRange);
    });

    it('accepts any early-fill weight with no grid to measure it against', function (): void {
        expect((new RepackOptions(earlyFillWeight: PHP_INT_MAX))->earlyFillWeight)->toBe(PHP_INT_MAX);
    });

    it('keeps the messages it always had for a negative weight and a budget below 1', function (): void {
        expect(fn() => new RepackOptions(earlyFillWeight: -1))
            ->toThrow(InvalidConfigurationException::class, 'Objective weights must be zero or positive');
        expect(fn() => new RepackOptions(stepBudget: 0))
            ->toThrow(InvalidConfigurationException::class, 'The step budget must be a positive integer');
    });
});

describe('RepackRequest weight bounds', function (): void {
    it('accepts weights on the boundary and rejects them one past it', function (int $sessions, int $consolidation, int $earlyFill, bool $accepted): void {
        $error = weightError(fn() => new RepackRequest(
            [],
            [],
            SessionGrid::shapeOnly($sessions),
            new RepackOptions(consolidationWeight: $consolidation, earlyFillWeight: $earlyFill)
        ));

        if ($accepted) {
            expect($error)->toBeNull();
        } else {
            expect($error?->getReason())->toBe(InvalidConfigurationReason::IncompatibleOptions);
            expect($error?->getContext())->toBe([
                'consolidation_weight' => $consolidation,
                'early_fill_weight' => $earlyFill,
                'sessions' => $sessions,
            ]);
        }
    })->with([
        // sessions, consolidation, early fill: accepted exactly when
        // early fill x (sessions - 1) + 2 x consolidation <= PHP_INT_MAX
        'one session, the largest early fill' => [1, 0, PHP_INT_MAX, true],
        'one session, both at their largest' => [1, PHP_INT_MAX >> 1, PHP_INT_MAX, true],
        'two sessions, early fill alone at the largest' => [2, 0, PHP_INT_MAX, true],
        'two sessions, one unit of consolidation too many' => [2, 1, PHP_INT_MAX - 1, false],
        'two sessions, exactly full' => [2, 1, PHP_INT_MAX - 2, true],
        'three sessions, on the boundary' => [3, 0, PHP_INT_MAX >> 1, true],
        'three sessions, one past it' => [3, 0, (PHP_INT_MAX >> 1) + 1, false],
        'largest consolidation leaves room for one' => [2, PHP_INT_MAX >> 1, 1, true],
        'largest consolidation and two' => [2, PHP_INT_MAX >> 1, 2, false],
        'eleven sessions, on the boundary' => [11, 3, intdiv(PHP_INT_MAX - 6, 10), true],
        'eleven sessions, one past it' => [11, 3, intdiv(PHP_INT_MAX - 6, 10) + 1, false],
        'the defaults on a long grid' => [5_000, 3, 1, true],
    ]);

    it('repacks with the largest weights a grid allows', function (): void {
        $participants = [];
        for ($i = 0; $i < 4; ++$i) {
            $participants[] = new Participant("p{$i}", "P{$i}");
        }
        $movable = [];
        foreach ([[0, 1], [0, 2], [0, 3], [1, 2], [1, 3], [2, 3]] as $index => [$a, $b]) {
            $movable[] = new MovableEvent("e{$index}", $participants[$a], $participants[$b]);
        }

        $outcome = (new ScheduleRepacker())->repack(new RepackRequest(
            $movable,
            [],
            SessionGrid::shapeOnly(3, 3, [], 2),
            new RepackOptions(consolidationWeight: 0, earlyFillWeight: PHP_INT_MAX >> 1)
        ));

        // Early fill is all that counts: the first session takes what it can hold
        expect(count($outcome->getAssignments()) + count($outcome->getUnplaced()))->toBe(6);
        $inFirst = 0;
        foreach ($outcome->getAssignments() as $assignment) {
            $inFirst += $assignment->getSession() === 0 ? 1 : 0;
        }
        expect($inFirst)->toBe(6);
    });
});
