<?php

declare(strict_types=1);

use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Repack\SessionGrid;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

// The lookups between a position, its ordinal and its instant (#225). The
// grid keeps running totals of its slot counts and searches them, and the
// session starts and slot times, by halves; these tests state what the
// answers are, at the edges of every session and against a plain walk
// over the grid, whatever the search does to find them.

/**
 * A grid of irregular sessions in UTC: 1, 5, 1, 3 and 2 slots, 25 minutes
 * apart, with whole days between the sessions.
 *
 * @throws InvalidConfigurationException
 */
function lookupGrid(): SessionGrid
{
    $utc = new DateTimeZone('UTC');

    return new SessionGrid(
        [
            new DateTimeImmutable('2026-08-12 18:00', $utc),
            new DateTimeImmutable('2026-08-13 18:00', $utc),
            new DateTimeImmutable('2026-08-14 18:00', $utc),
            new DateTimeImmutable('2026-08-15 18:00', $utc),
            new DateTimeImmutable('2026-08-16 18:00', $utc),
        ],
        new DateInterval('PT25M'),
        1,
        [1 => 5, 3 => 3, 4 => 2]
    );
}

/**
 * Every position of a grid in grid order, found by walking it.
 *
 * @return list<array{session: int, slot: int}>
 *
 * @throws InvalidConfigurationException
 */
function walkedPositions(SessionGrid $grid): array
{
    $positions = [];
    for ($session = 0; $session < $grid->getSessionCount(); ++$session) {
        for ($slot = 0; $slot < $grid->getSlotCount($session); ++$slot) {
            $positions[] = ['session' => $session, 'slot' => $slot];
        }
    }

    return $positions;
}

/**
 * A grid of random shape, and of random times when instant-based: sessions
 * of 1 to 6 slots, an interval of 1 to 90 minutes and, between the last
 * slot of a session and the start of the next, a gap of 1 to 3,000 seconds
 * and a random number of microseconds, so that neighbouring instants are
 * close.
 *
 * @throws DateMalformedStringException
 * @throws InvalidConfigurationException
 */
function randomLookupGrid(Randomizer $random, bool $instants): SessionGrid
{
    $sessions = $random->getInt(1, 40);
    $counts = [];
    for ($session = 0; $session < $sessions; ++$session) {
        $counts[$session] = $random->getInt(1, 6);
    }

    if (!$instants) {
        return SessionGrid::shapeOnly($sessions, 1, $counts, $random->getInt(1, 3));
    }

    $minutes = $random->getInt(1, 90);
    $starts = [];
    $start = new DateTimeImmutable('2026-03-01 00:00:00.000000', new DateTimeZone('UTC'));
    foreach ($counts as $slots) {
        $starts[] = $start;
        $lastSlot = $start->modify('+' . (($slots - 1) * $minutes) . ' minutes');
        $start = $lastSlot->modify('+' . $random->getInt(1, 3000) . ' seconds +' . $random->getInt(0, 999_999) . ' microseconds');
    }

    return new SessionGrid($starts, new DateInterval("PT{$minutes}M"), 1, $counts);
}

describe('SessionGrid lookups', function (): void {
    it('numbers the first and the last slot of every session', function (): void {
        $grid = lookupGrid();

        // Sessions of 1, 5, 1, 3 and 2 slots: the first slots are at the
        // running totals 0, 1, 6, 7 and 10, and the last before the next
        $edges = [[0, 0, 0], [1, 1, 5], [2, 6, 6], [3, 7, 9], [4, 10, 11]];
        foreach ($edges as [$session, $first, $last]) {
            $lastSlot = $grid->getSlotCount($session) - 1;

            expect($grid->ordinalOf($session, 0))->toBe($first)
                ->and($grid->ordinalOf($session, $lastSlot))->toBe($last)
                ->and($grid->positionOf($first))->toBe(['session' => $session, 'slot' => 0])
                ->and($grid->positionOf($last))->toBe(['session' => $session, 'slot' => $lastSlot])
                ->and($grid->positionOf($grid->getSlotTime($session, 0)))->toBe(['session' => $session, 'slot' => 0])
                ->and($grid->positionOf($grid->getSlotTime($session, $lastSlot)))->toBe(['session' => $session, 'slot' => $lastSlot]);
        }
        expect($grid->getPositionCount())->toBe(12);
    });

    it('has no position at an ordinal off the grid', function (int $ordinal): void {
        expect(lookupGrid()->positionOf($ordinal))->toBeNull();
    })->with(['one past the last' => [12], 'far past the last' => [PHP_INT_MAX], 'one before the first' => [-1], 'far before the first' => [PHP_INT_MIN]]);

    it('refuses the ordinal of a position off the grid, as it always has', function (int $session, int $slot): void {
        $grid = lookupGrid();

        expect(fn() => $grid->ordinalOf($session, $slot))->toThrow(InvalidConfigurationException::class, 'The position is not on this grid');

        try {
            $grid->ordinalOf($session, $slot);
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getReason())->toBe(InvalidConfigurationReason::PositionOutOfRange);
        }
    })->with([
        'a slot past the end of a one-slot session' => [0, 1],
        'a slot past the end of a deeper session' => [1, 5],
        'a slot past the end of the last session' => [4, 2],
        'a session past the last' => [5, 0],
        'a negative session' => [-1, 0],
        'a negative slot' => [3, -1],
        'the largest integers' => [PHP_INT_MAX, PHP_INT_MAX],
    ]);

    it('has no position at an instant that is no slot time', function (string $instant): void {
        expect(lookupGrid()->positionOf(new DateTimeImmutable($instant, new DateTimeZone('UTC'))))->toBeNull();
    })->with([
        'before the first session' => ['2026-08-12 17:59:59'],
        'a microsecond before a session start' => ['2026-08-13 17:59:59.999999'],
        'a microsecond after a session start' => ['2026-08-13 18:00:00.000001'],
        'between two slots of a session' => ['2026-08-13 18:30'],
        'one interval after the last slot of a session' => ['2026-08-13 20:05'],
        'between two sessions' => ['2026-08-14 06:00'],
        'one interval after the last slot of the grid' => ['2026-08-16 18:50'],
        'long after the grid' => ['2099-01-01 00:00'],
    ]);

    it('finds the one position of a grid of one slot', function (): void {
        $grid = new SessionGrid([new DateTimeImmutable('2026-08-12 18:00', new DateTimeZone('UTC'))], new DateInterval('PT25M'));

        expect($grid->ordinalOf(0, 0))->toBe(0)
            ->and($grid->positionOf(0))->toBe(['session' => 0, 'slot' => 0])
            ->and($grid->positionOf(1))->toBeNull()
            ->and($grid->positionOf($grid->getSlotTime(0, 0)))->toBe(['session' => 0, 'slot' => 0])
            ->and($grid->positionOf(new DateTimeImmutable('2026-08-12 18:25', new DateTimeZone('UTC'))))->toBeNull()
            ->and($grid->positionOf(new DateTimeImmutable('2026-08-12 17:35', new DateTimeZone('UTC'))))->toBeNull();
    });

    it('finds every slot time of a grid declared in a zone that changes its clocks inside a session', function (string $day): void {
        // Sessions of six half-hour slots from 00:30 London time on the
        // night the clocks go back (25 October) or forward (29 March): the
        // instants rise in UTC whatever the wall clock does, and each is
        // found given in UTC or in London time, and nothing a microsecond
        // either side of it
        $london = new DateTimeZone('Europe/London');
        $utc = new DateTimeZone('UTC');
        $grid = new SessionGrid(
            [new DateTimeImmutable("{$day} 00:30", $london), new DateTimeImmutable("{$day} 12:00", $london)],
            new DateInterval('PT30M'),
            6
        );

        $first = new DateTimeImmutable("{$day} 00:30", $london);
        for ($slot = 0; $slot < 6; ++$slot) {
            $time = $first->setTimezone($utc)->modify('+' . (30 * $slot) . ' minutes');

            expect($grid->getSlotTime(0, $slot) <=> $time)->toBe(0)
                ->and($grid->positionOf($time))->toBe(['session' => 0, 'slot' => $slot])
                ->and($grid->positionOf($time->setTimezone($london)))->toBe(['session' => 0, 'slot' => $slot])
                ->and($grid->positionOf($time->modify('-1 microsecond')))->toBeNull()
                ->and($grid->positionOf($time->modify('+1 microsecond')))->toBeNull();
        }
    })->with(['the clocks go back' => ['2026-10-25'], 'the clocks go forward' => ['2026-03-29']]);

    it('has no empty session to look up: every session has a slot', function (): void {
        expect(fn() => SessionGrid::shapeOnly(3, 2, [1 => 0]))->toThrow(InvalidConfigurationException::class, 'An overridden session still needs at least 1 slot');
    });

    it('is the same grid after a round trip through plain data, totals and all', function (): void {
        $grid = lookupGrid();
        $shape = SessionGrid::shapeOnly(5, 1, [1 => 5, 3 => 3, 4 => 2]);

        expect(SessionGrid::fromArray($grid->toArray()))->toEqual($grid)
            ->and(SessionGrid::fromArray($shape->toArray()))->toEqual($shape)
            ->and($grid->toArray())->toBe([
                'format_version' => 1,
                'sessions' => ['2026-08-12 18:00:00', '2026-08-13 18:00:00', '2026-08-14 18:00:00', '2026-08-15 18:00:00', '2026-08-16 18:00:00'],
                'timezone' => 'UTC',
                'slot_interval' => 'PT25M',
                'slots_per_session' => 1,
                'capacity_per_slot' => 1,
                'slots_per_session_overrides' => [1 => 5, 3 => 3, 4 => 2],
            ]);
    });

    it('agrees with a walk over the grid, on random grids', function (int $seed, bool $instants): void {
        $random = new Randomizer(new Xoshiro256StarStar($seed));

        for ($round = 0; $round < 25; ++$round) {
            $grid = randomLookupGrid($random, $instants);
            $walked = walkedPositions($grid);

            expect($grid->getPositionCount())->toBe(count($walked));
            foreach ($walked as $ordinal => $position) {
                expect($grid->ordinalOf($position['session'], $position['slot']))->toBe($ordinal)
                    ->and($grid->positionOf($ordinal))->toBe($position);
            }
            expect($grid->positionOf(count($walked)))->toBeNull()
                ->and($grid->positionOf(-1))->toBeNull();

            if (!$instants) {
                continue;
            }

            // Every slot time finds its position, written in another zone;
            // an instant a microsecond either side of one, and one
            // anywhere in the grid's span, finds what a walk over every
            // slot time finds, which is nothing unless it is a slot time
            $times = [];
            foreach ($walked as $position) {
                $times[] = $grid->getSlotTime($position['session'], $position['slot']);
            }
            $walk = static function (DateTimeImmutable $at) use ($times, $walked): ?array {
                foreach ($times as $ordinal => $time) {
                    if (($time <=> $at) === 0) {
                        return $walked[$ordinal];
                    }
                }

                return null;
            };

            $tokyo = new DateTimeZone('Asia/Tokyo');
            foreach ($times as $ordinal => $time) {
                expect($grid->positionOf($time->setTimezone($tokyo)))->toBe($walked[$ordinal]);
                foreach (['-1 microsecond', '+1 microsecond'] as $step) {
                    $near = $time->modify($step);
                    expect($grid->positionOf($near))->toBe($walk($near));
                }
            }

            $first = (int) $times[0]->format('U');
            $last = (int) $times[count($times) - 1]->format('U');
            for ($probe = 0; $probe < 20; ++$probe) {
                $at = new DateTimeImmutable('@' . $random->getInt($first - 60, $last + 60));
                expect($grid->positionOf($at))->toBe($walk($at));
            }
        }
    })->with(function (): iterable {
        foreach ([1, 2, 3, 4, 5] as $seed) {
            yield "shape-only, seed {$seed}" => [$seed, false];
            yield "instant-based, seed {$seed}" => [$seed, true];
        }
    });
});
