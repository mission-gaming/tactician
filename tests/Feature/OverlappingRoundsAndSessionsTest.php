<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Repack\SessionGrid;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Timeline\TimelineAssigner;
use MissionGaming\Tactician\Timeline\TimelineDefinition;

/**
 * A round that is still being played when the next one starts, and a
 * session that is still running when the next one starts, put two positions
 * at one time: the same resource and the same participant can be booked
 * twice with nothing reported. Both are configuration errors and fail when
 * the definition or the grid is built, or at the latest when the round is
 * assigned.
 *
 * This is the reason the configuration is refused with, or null when it is
 * accepted.
 *
 * @param Closure(): mixed $build
 */
function overlapReason(Closure $build): ?InvalidConfigurationReason
{
    try {
        $build();
    } catch (InvalidConfigurationException $exception) {
        return $exception->getReason();
    }

    return null;
}

/**
 * One hour as `diff()` returns it. PHP adds the hours of such an interval
 * as local time, where it adds those of `new DateInterval('PT1H')` as
 * elapsed time.
 */
function overlapLocalHour(): DateInterval
{
    $utc = new DateTimeZone('UTC');

    return new DateTimeImmutable('2026-01-01 00:00', $utc)->diff(new DateTimeImmutable('2026-01-01 01:00', $utc));
}

describe('A timeline whose rounds overlap', function (): void {
    it('is rejected when the last slot of a round is the first slot of the next', function (): void {
        // The reported case: hourly rounds of two slots an hour apart. Round
        // 1 slot 1 and round 2 slot 0 are both 19:00.
        $build = fn() => new TimelineDefinition(
            new DateTimeImmutable('2026-08-01 18:00', new DateTimeZone('UTC')),
            new DateInterval('PT1H'),
            slotsPerRound: 2,
            slotInterval: new DateInterval('PT1H')
        );

        expect($build)->toThrow(InvalidConfigurationException::class, 'before the next round starts')
            ->and(overlapReason($build))->toBe(InvalidConfigurationReason::OverlappingTime);
    });

    it('is rejected from plain data as well', function (): void {
        expect(overlapReason(fn() => TimelineDefinition::fromArray([
            'start' => '2026-08-01 18:00',
            'timezone' => 'Europe/London',
            'round_interval' => 'P1D',
            'slots_per_round' => 4,
            'slot_interval' => 'PT8H',
        ])))->toBe(InvalidConfigurationReason::OverlappingTime);
    });

    it('is accepted exactly when every slot is before the next round, for any spacing', function (): void {
        // Rounds every 6 hours; a round of n slots m minutes apart ends
        // (n − 1) × m minutes after it starts
        $start = new DateTimeImmutable('2026-08-01 18:00', new DateTimeZone('Europe/London'));
        $checked = 0;
        foreach (range(1, 8) as $slots) {
            foreach ([30, 45, 60, 90, 120, 180, 360, 400] as $minutes) {
                $fits = ($slots - 1) * $minutes < 360;
                $reason = overlapReason(fn() => new TimelineDefinition(
                    $start,
                    new DateInterval('PT6H'),
                    $slots,
                    new DateInterval("PT{$minutes}M")
                ));

                expect($reason)->toBe($fits ? null : InvalidConfigurationReason::OverlappingTime);
                ++$checked;
            }
        }

        expect($checked)->toBe(64);
    });

    it('never has two slots at one instant, in round and slot order, once it is accepted', function (): void {
        foreach (['UTC', 'Europe/London', 'Australia/Sydney'] as $zoneName) {
            foreach ([['P1D', 3, 'PT7H'], ['P7D', 6, 'P1D'], ['PT2H', 4, 'PT30M'], ['P1M', 4, 'P7D']] as [$round, $slots, $slot]) {
                $timeline = new TimelineDefinition(
                    new DateTimeImmutable('2026-03-01 18:00', new DateTimeZone($zoneName)),
                    new DateInterval($round),
                    $slots,
                    new DateInterval($slot)
                );

                $previous = null;
                foreach (range(1, 60) as $roundNumber) {
                    foreach (range(0, $slots - 1) as $slotIndex) {
                        $time = $timeline->getSlotTime($roundNumber, $slotIndex);
                        if ($previous !== null) {
                            expect($time > $previous)->toBeTrue();
                        }
                        $previous = $time;
                    }
                }
            }
        }
    });

    it('is rejected at assignment when a later round is shorter than the first', function (): void {
        // Monthly rounds of two slots 29 days apart. March has 31 days, so
        // the first round fits and the definition is built. Round 12 starts
        // on 1 February 2027, a month of 28 days: its second slot is 2 March,
        // the day after round 13 starts.
        $timeline = new TimelineDefinition(
            new DateTimeImmutable('2026-03-01 19:00', new DateTimeZone('UTC')),
            new DateInterval('P1M'),
            slotsPerRound: 2,
            slotInterval: new DateInterval('P29D')
        );
        $a = new Participant('a', 'A');
        $b = new Participant('b', 'B');
        $assigner = new TimelineAssigner();

        // Rounds that do not overlap are assigned
        $early = new Schedule([new Event([$a, $b], new Round(1)), new Event([$b, $a], new Round(11))]);
        expect($assigner->assign($early, $timeline))->toHaveCount(2);

        $late = new Schedule([new Event([$a, $b], new Round(12)), new Event([$b, $a], new Round(13))]);
        expect(fn() => $assigner->assign($late, $timeline))
            ->toThrow(InvalidConfigurationException::class, 'Round 12 is still being played when round 13 starts')
            ->and(overlapReason(fn() => $assigner->assign($late, $timeline)))
            ->toBe(InvalidConfigurationReason::OverlappingTime);

        // Round 12 runs into a round 13 only where round 13 is played: a
        // schedule that ends with round 12 is assigned
        $endsAtTwelve = new Schedule([new Event([$a, $b], new Round(1)), new Event([$b, $a], new Round(12))]);
        expect($assigner->assign($endsAtTwelve, $timeline))->toHaveCount(2);

        // The round-by-round path makes the same check, when it is given
        // the later of the two rounds
        $twelfth = new RoundPairing(12, null, [new Event([$a, $b], new Round(12))]);
        $thirteenth = new RoundPairing(13, null, [new Event([$a, $b], new Round(13))]);
        expect($assigner->assignRound($twelfth, $timeline))->toHaveCount(1)
            ->and(fn() => $assigner->assignRound($thirteenth, $timeline))
            ->toThrow(InvalidConfigurationException::class, 'Round 12 is still being played when round 13 starts')
            ->and(overlapReason(fn() => $assigner->assignRound($thirteenth, $timeline)))
            ->toBe(InvalidConfigurationReason::OverlappingTime);
    });
});

describe('A timeline with two slots at one time', function (): void {
    // PHP adds the hours of an interval that diff() returned as local time.
    // In London 01:30 does not exist on 29 March 2026, and PHP reads it as
    // 02:30: one hour and two hours after 00:30 are then the same instant.
    it('is rejected when it is built, if the first round has them', function (): void {
        $build = fn() => new TimelineDefinition(
            new DateTimeImmutable('2026-03-29 00:30', new DateTimeZone('Europe/London')),
            new DateInterval('P7D'),
            slotsPerRound: 3,
            slotInterval: overlapLocalHour()
        );

        expect($build)->toThrow(InvalidConfigurationException::class, 'must be at rising times')
            ->and(overlapReason($build))->toBe(InvalidConfigurationReason::OverlappingTime);
    });

    it('is rejected at assignment, if a later round has them', function (): void {
        $london = new DateTimeZone('Europe/London');
        $timeline = new TimelineDefinition(
            new DateTimeImmutable('2026-03-22 00:30', $london),
            new DateInterval('P7D'),
            slotsPerRound: 3,
            slotInterval: overlapLocalHour()
        );
        $a = new Participant('a', 'A');
        $b = new Participant('b', 'B');
        $assigner = new TimelineAssigner();

        // Round 1 is 00:30, 01:30, 02:30 on a night with all three
        expect($assigner->assignRound(new RoundPairing(1, null, [new Event([$a, $b], new Round(1))]), $timeline))->toHaveCount(1);

        // Round 2 is the night the clocks go forward. One event fills one
        // slot: the check is of the slots the round declares
        $second = new RoundPairing(2, null, [new Event([$a, $b], new Round(2))]);
        expect(fn() => $assigner->assignRound($second, $timeline))
            ->toThrow(InvalidConfigurationException::class, 'Round 2 has two slots that are not at rising times')
            ->and(overlapReason(fn() => $assigner->assignRound($second, $timeline)))
            ->toBe(InvalidConfigurationReason::OverlappingTime)
            ->and(overlapReason(fn() => $assigner->assign(new Schedule($second->getEvents()), $timeline)))
            ->toBe(InvalidConfigurationReason::OverlappingTime);

        // The same slots an hour of elapsed time apart are three instants
        $elapsed = new TimelineDefinition(
            new DateTimeImmutable('2026-03-22 00:30', $london),
            new DateInterval('P7D'),
            slotsPerRound: 3,
            slotInterval: new DateInterval('PT1H')
        );
        expect($assigner->assignRound($second, $elapsed))->toHaveCount(1);
    });

    it('is rejected at assignment when two rounds of one slot each are at one time', function (): void {
        // Rounds one local hour apart from 00:30: rounds 2 and 3 are both
        // 02:30, so round 2 has not ended when round 3 starts
        $timeline = new TimelineDefinition(
            new DateTimeImmutable('2026-03-29 00:30', new DateTimeZone('Europe/London')),
            overlapLocalHour()
        );
        $a = new Participant('a', 'A');
        $b = new Participant('b', 'B');
        $assigner = new TimelineAssigner();

        $rounds = fn(int ...$numbers) => new Schedule(array_map(fn(int $number) => new Event([$a, $b], new Round($number)), $numbers));

        expect($timeline->getSlotTime(2)->format('H:i'))->toBe('01:30')
            ->and($timeline->getSlotTime(3)->format('H:i'))->toBe('01:30')
            ->and($assigner->assign($rounds(1, 2), $timeline))->toHaveCount(2)
            ->and(overlapReason(fn() => $assigner->assign($rounds(2, 3), $timeline)))
            ->toBe(InvalidConfigurationReason::OverlappingTime)
            // Either of the two rounds alone is at a time of its own
            ->and($assigner->assign($rounds(2), $timeline))->toHaveCount(1)
            ->and($assigner->assign($rounds(1, 3), $timeline))->toHaveCount(2)
            // Round by round, it is the later of the two that is refused
            ->and($assigner->assignRound(new RoundPairing(2, null, [new Event([$a, $b], new Round(2))]), $timeline))->toHaveCount(1)
            ->and(overlapReason(fn() => $assigner->assignRound(new RoundPairing(3, null, [new Event([$a, $b], new Round(3))]), $timeline)))
            ->toBe(InvalidConfigurationReason::OverlappingTime);
    });

    it('assigns no kickoff and makes no check for a round with no events', function (): void {
        $timeline = new TimelineDefinition(
            new DateTimeImmutable('2026-03-29 00:30', new DateTimeZone('Europe/London')),
            overlapLocalHour()
        );

        expect(new TimelineAssigner()->assignRound(new RoundPairing(2, null, []), $timeline))->toBe([]);
    });
});

describe('A session grid whose sessions overlap', function (): void {
    it('is rejected when a session runs up to the start of the next', function (): void {
        // The reported case: sessions at 20:00 and 20:25, two slots 25
        // minutes apart. Session 0 slot 1 and session 1 slot 0 are both 20:25.
        $london = new DateTimeZone('Europe/London');
        $build = fn() => new SessionGrid(
            [new DateTimeImmutable('2026-08-12 20:00', $london), new DateTimeImmutable('2026-08-12 20:25', $london)],
            new DateInterval('PT25M'),
            slotsPerSession: 2
        );

        expect($build)->toThrow(InvalidConfigurationException::class, 'Session 0 must end before session 1 starts')
            ->and(overlapReason($build))->toBe(InvalidConfigurationReason::OverlappingTime);
    });

    it('is rejected from plain data as well', function (): void {
        expect(overlapReason(fn() => SessionGrid::fromArray([
            'sessions' => ['2026-08-12 20:00', '2026-08-12 21:00'],
            'timezone' => 'Europe/London',
            'slot_interval' => 'PT25M',
            'slots_per_session' => 4,
        ])))->toBe(InvalidConfigurationReason::OverlappingTime);
    });

    it('is accepted exactly when every session ends before the next starts, for any gap and depth', function (): void {
        $london = new DateTimeZone('Europe/London');
        $first = new DateTimeImmutable('2026-08-12 20:00', $london);
        $checked = 0;
        foreach (range(5, 150, 5) as $gapMinutes) {
            foreach (range(1, 6) as $slots) {
                $fits = ($slots - 1) * 25 < $gapMinutes;
                $build = fn() => new SessionGrid(
                    [$first, $first->modify("+{$gapMinutes} minutes"), $first->modify('+1 day')],
                    new DateInterval('PT25M'),
                    slotsPerSession: $slots
                );

                expect(overlapReason($build))->toBe($fits ? null : InvalidConfigurationReason::OverlappingTime);

                if ($fits) {
                    // An accepted grid has its positions at distinct, rising instants
                    $grid = $build();
                    $previous = null;
                    foreach (range(0, $grid->getPositionCount() - 1) as $ordinal) {
                        $position = $grid->positionOf($ordinal);
                        assert($position !== null);
                        $time = $grid->getSlotTime($position['session'], $position['slot']);
                        if ($previous !== null) {
                            expect($time > $previous)->toBeTrue();
                        }
                        expect($grid->positionOf($time))->toBe($position);
                        $previous = $time;
                    }
                }
                ++$checked;
            }
        }

        expect($checked)->toBe(180);
    });

    it('reads the slot count of each session, overrides applied', function (): void {
        $london = new DateTimeZone('Europe/London');
        $starts = [new DateTimeImmutable('2026-08-12 20:00', $london), new DateTimeImmutable('2026-08-12 21:00', $london)];
        $interval = new DateInterval('PT25M');

        // Two slots each: 20:00, 20:25 and 21:00, 21:25
        expect(overlapReason(fn() => new SessionGrid($starts, $interval, 2)))->toBeNull();
        // The last session may run as deep as it likes: nothing follows it
        expect(overlapReason(fn() => new SessionGrid($starts, $interval, 2, [1 => 12])))->toBeNull();
        // The first deepened to four slots reaches 21:15
        expect(overlapReason(fn() => new SessionGrid($starts, $interval, 2, [0 => 4])))
            ->toBe(InvalidConfigurationReason::OverlappingTime);
        // A default that overlaps, made shallow enough by an override
        expect(overlapReason(fn() => new SessionGrid($starts, $interval, 4, [0 => 3])))->toBeNull();
    });

    it('is rejected when two slots of one session are at one instant', function (): void {
        // Slots one local hour apart from 00:30 on the night London skips
        // 01:30: slots 1 and 2 are both 02:30 local. It is so in the last
        // session too, which nothing follows
        $london = new DateTimeZone('Europe/London');
        $build = fn() => new SessionGrid(
            [new DateTimeImmutable('2026-03-22 00:30', $london), new DateTimeImmutable('2026-03-29 00:30', $london)],
            overlapLocalHour(),
            slotsPerSession: 3
        );

        expect($build)->toThrow(InvalidConfigurationException::class, 'The slots of session 1 must be at rising times')
            ->and(overlapReason($build))->toBe(InvalidConfigurationReason::OverlappingTime);

        // Two slots are 00:30 and 02:30, and the grid is built
        $grid = new SessionGrid(
            [new DateTimeImmutable('2026-03-22 00:30', $london), new DateTimeImmutable('2026-03-29 00:30', $london)],
            overlapLocalHour(),
            slotsPerSession: 2
        );
        expect($grid->positionOf($grid->getSlotTime(1, 1)))->toBe(['session' => 1, 'slot' => 1]);

        // An hour of elapsed time apart, the three slots are three instants
        expect(overlapReason(fn() => new SessionGrid(
            [new DateTimeImmutable('2026-03-22 00:30', $london), new DateTimeImmutable('2026-03-29 00:30', $london)],
            new DateInterval('PT1H'),
            slotsPerSession: 3
        )))->toBeNull();
    });

    it('accepts sessions that touch nothing: a session of one slot just before the next', function (): void {
        // One slot per session has no last slot to run on, so starts a
        // second apart are a valid grid of two instants
        $utc = new DateTimeZone('UTC');
        $grid = new SessionGrid(
            [new DateTimeImmutable('2026-08-12 20:00:00', $utc), new DateTimeImmutable('2026-08-12 20:00:01', $utc)],
            new DateInterval('PT25M')
        );

        expect($grid->getPositionCount())->toBe(2)
            ->and($grid->positionOf(new DateTimeImmutable('2026-08-12 20:00:01', $utc)))->toBe(['session' => 1, 'slot' => 0]);
    });

    it('accepts a last slot one second before the next session and refuses it at the same second', function (): void {
        $utc = new DateTimeZone('UTC');
        $first = new DateTimeImmutable('2026-08-12 20:00:00', $utc);
        $build = fn(string $second) => fn() => new SessionGrid(
            [$first, new DateTimeImmutable($second, $utc)],
            new DateInterval('PT25M'),
            slotsPerSession: 2
        );

        expect(overlapReason($build('2026-08-12 20:25:01')))->toBeNull()
            ->and(overlapReason($build('2026-08-12 20:25:00')))->toBe(InvalidConfigurationReason::OverlappingTime)
            ->and(overlapReason($build('2026-08-12 20:24:59')))->toBe(InvalidConfigurationReason::OverlappingTime);
    });

    it('does not apply to a shape-only grid, which has no instants to overlap', function (): void {
        expect(SessionGrid::shapeOnly(3, 40)->getPositionCount())->toBe(120);
    });
});
