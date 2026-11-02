<?php

declare(strict_types=1);

use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Repack\SessionGrid;
use MissionGaming\Tactician\Timeline\TimelineDefinition;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;

// What a timeline and a grid write as plain data has to read back as what
// they were. An ISO 8601 duration, as PHP reads one, holds years, months,
// days, hours, minutes and whole seconds, and nothing else. An interval can
// hold more: a sign, a fraction of a second, a relative part ("next
// weekday"), and hours that PHP adds as local time and not as elapsed time
// (an interval from createFromDateString() or from diff()). None of that was
// written, and nothing said so: "next weekday" was written "PT0S".

/**
 * An interval PHP made from a relative date string.
 *
 * @throws AssertionFailedError When PHP does not read the string
 * @throws DateMalformedIntervalStringException When PHP does not read the string, from PHP 8.3
 */
function relativeInterval(string $dateString): DateInterval
{
    $interval = DateInterval::createFromDateString($dateString);
    if ($interval === false) {
        Assert::fail("PHP does not read '{$dateString}' as an interval.");
    }

    return $interval;
}

/**
 * The interval between two local times in London on 1 January 2026, as
 * diff() returns it.
 *
 * @throws DateMalformedStringException
 */
function diffInterval(string $from, string $to): DateInterval
{
    $zone = new DateTimeZone('Europe/London');

    return new DateTimeImmutable($from, $zone)->diff(new DateTimeImmutable($to, $zone));
}

/**
 * An interval with a part an ISO 8601 duration does not hold.
 *
 * @throws DateMalformedIntervalStringException
 */
function alteredInterval(string $duration, float $fraction = 0.0, bool $inverted = false, int $negativeHours = 0): DateInterval
{
    $interval = new DateInterval($duration);
    $interval->f = $fraction;
    $interval->invert = $inverted ? 1 : 0;
    if ($negativeHours !== 0) {
        $interval->h = -$negativeHours;
    }

    return $interval;
}

/**
 * The configuration error a call raises.
 *
 * @param Closure(): mixed $call
 *
 * @throws AssertionFailedError When the call raises none
 */
function intervalError(Closure $call): InvalidConfigurationException
{
    try {
        $call();
    } catch (InvalidConfigurationException $exception) {
        return $exception;
    }

    Assert::fail('No InvalidConfigurationException was raised.');
}

describe('TimelineDefinition::formatInterval()', function (): void {
    it('writes an ISO 8601 duration as it did', function (string $duration, string $written): void {
        expect(TimelineDefinition::formatInterval(new DateInterval($duration)))->toBe($written);
    })->with([
        ['P7D', 'P7D'],
        ['P1W', 'P7D'],
        ['PT90M', 'PT90M'],
        ['P1DT12H', 'P1DT12H'],
        ['P1Y2M3DT4H5M6S', 'P1Y2M3DT4H5M6S'],
        ['PT0S', 'PT0S'],
    ]);

    // It is called to describe an interval in an error, an interval that a
    // constructor has just rejected among them, so it describes every
    // interval and what it writes is true of it.
    it('shows the sign of an inverted interval', function (): void {
        expect(TimelineDefinition::formatInterval(alteredInterval('P7D', inverted: true)))->toBe('-P7D')
            ->and(TimelineDefinition::formatInterval(alteredInterval('PT1H30M', inverted: true)))->toBe('-PT1H30M');
    });

    it('shows a fraction of a second', function (string $duration, float $fraction, string $written): void {
        expect(TimelineDefinition::formatInterval(alteredInterval($duration, $fraction)))->toBe($written);
    })->with([
        'a second and a half' => ['PT1S', 0.5, 'PT1.5S'],
        'a quarter of a second' => ['PT0S', 0.25, 'PT0.25S'],
        'a day and a millisecond' => ['P1D', 0.001, 'P1DT0.001S'],
        'a microsecond' => ['PT2M', 0.000001, 'PT2M0.000001S'],
    ]);

    it('shows the relative date string of an interval that has no duration', function (string $dateString): void {
        expect(TimelineDefinition::formatInterval(relativeInterval($dateString)))->toBe($dateString);
    })->with(['next weekday', '3 weekdays', 'last day of next month']);

    it('writes the amounts of a relative date string that is only amounts', function (string $dateString, string $written): void {
        expect(TimelineDefinition::formatInterval(relativeInterval($dateString)))->toBe($written);
    })->with([
        ['1 week', 'P7D'],
        ['+1 week 2 days', 'P9D'],
        ['25 minutes', 'PT25M'],
    ]);
});

describe('TimelineDefinition::writeInterval()', function (): void {
    it('writes an interval that reads back as the same interval', function (DateInterval $interval, string $written): void {
        $text = TimelineDefinition::writeInterval($interval, 'round_interval');
        $start = new DateTimeImmutable('2026-03-27 19:30:00', new DateTimeZone('Europe/London'));
        $readBack = TimelineDefinition::parseInterval($text, 'round_interval');

        // The clocks go forward in London on 29 March 2026: three steps from
        // the 27th cross the change
        $given = $start;
        $read = $start;
        for ($step = 0; $step < 3; ++$step) {
            $given = $given->add($interval);
            $read = $read->add($readBack);
        }

        expect($text)->toBe($written)
            ->and($read->format('c'))->toBe($given->format('c'));
    })->with([
        'an ISO duration of days' => [new DateInterval('P1D'), 'P1D'],
        'an ISO duration of hours' => [new DateInterval('PT20H'), 'PT20H'],
        'an ISO duration of days and hours' => [new DateInterval('P1DT2H'), 'P1DT2H'],
        'an ISO duration of a month' => [new DateInterval('P1M'), 'P1M'],
        'whole days from diff()' => [diffInterval('2026-01-01 00:00', '2026-01-08 00:00'), 'P7D'],
        'a month and days from diff()' => [diffInterval('2026-01-01 00:00', '2026-02-03 00:00'), 'P1M2D'],
    ]);

    it('refuses an interval that would not read back as the same interval', function (DateInterval $interval, string $why): void {
        $exception = intervalError(fn() => TimelineDefinition::writeInterval($interval, 'slot_interval'));

        expect($exception->getReason())->toBe(InvalidConfigurationReason::NotSerializable)
            ->and($exception->getConfigurationIssue())->toBe("slot_interval cannot be written as an ISO 8601 duration: {$why}")
            ->and($exception->getContext())->toBe(['slot_interval' => TimelineDefinition::formatInterval($interval)]);
    })->with([
        'next weekday' => [
            relativeInterval('next weekday'),
            'it was made from a relative date string, which a duration cannot state',
        ],
        'a number of weekdays' => [
            relativeInterval('3 weekdays'),
            'it was made from a relative date string, which a duration cannot state',
        ],
        'the last day of next month' => [
            relativeInterval('last day of next month'),
            'it was made from a relative date string, which a duration cannot state',
        ],
        // Amounts of time alone are refused too: the timeline and the grid
        // add such an interval one step at a time, each step from the one
        // before, and a duration read back is multiplied out and added once
        'minutes from a relative date string' => [
            relativeInterval('25 minutes'),
            'it was made from a relative date string: such an interval is added one step at a time and as local time, and a duration read back is not',
        ],
        'a day from a relative date string' => [
            relativeInterval('1 day'),
            'it was made from a relative date string: such an interval is added one step at a time and as local time, and a duration read back is not',
        ],
        'a week from a relative date string' => [
            relativeInterval('+1 week'),
            'it was made from a relative date string: such an interval is added one step at a time and as local time, and a duration read back is not',
        ],
        'a month and days from a relative date string' => [
            relativeInterval('1 month 2 days'),
            'it was made from a relative date string: such an interval is added one step at a time and as local time, and a duration read back is not',
        ],
        'a negative part' => [
            alteredInterval('P8D', negativeHours: 1),
            'it has a negative part, which a duration PHP reads cannot hold',
        ],
        'hours from diff()' => [
            diffInterval('2026-01-01 00:00', '2026-01-01 02:00'),
            'PHP adds its hours, minutes and seconds as local time, and those of a duration read back as elapsed time',
        ],
        'a fraction of a second' => [
            alteredInterval('PT1S', 0.5),
            'it has a fraction of a second, which a duration PHP reads cannot hold',
        ],
        'an inverted interval' => [
            alteredInterval('P7D', inverted: true),
            'it is negative, and a duration PHP reads has no sign',
        ],
    ]);

    // The reason the two refusals above are refusals: the same amount, added
    // across the night the clocks go forward, lands an hour apart.
    it('is right that hours from a relative date string are not the hours of a duration', function (): void {
        $start = new DateTimeImmutable('2026-03-29 00:30:00', new DateTimeZone('Europe/London'));

        expect($start->add(relativeInterval('2 hours'))->format('H:i T'))->toBe('02:30 BST')
            ->and($start->add(new DateInterval('PT2H'))->format('H:i T'))->toBe('03:30 BST');
    });
});

describe('a timeline definition written as plain data', function (): void {
    it('keeps a fraction of a second of its start', function (): void {
        $start = new DateTimeImmutable('2026-08-01 19:00:00.250000', new DateTimeZone('Europe/London'));
        $definition = new TimelineDefinition($start, new DateInterval('P7D'));

        $data = $definition->toArray();
        $readBack = TimelineDefinition::fromArray($data);

        expect($data['start'])->toBe('2026-08-01 19:00:00.250000')
            ->and($readBack->getStart()->format('Y-m-d H:i:s.u'))->toBe('2026-08-01 19:00:00.250000')
            ->and($readBack->getSlotTime(3)->format('U.u'))->toBe($definition->getSlotTime(3)->format('U.u'));
    });

    it('writes a start on a whole second as it did', function (): void {
        $definition = new TimelineDefinition(
            new DateTimeImmutable('2026-08-01 19:00:00', new DateTimeZone('Europe/London')),
            new DateInterval('P7D')
        );

        expect($definition->toArray()['start'])->toBe('2026-08-01 19:00:00');
    });

    it('is refused when an interval of it has no duration, and is not written as PT0S', function (string $key): void {
        $start = new DateTimeImmutable('2026-08-03 19:00:00', new DateTimeZone('Europe/London'));
        $definition = $key === 'round_interval'
            ? new TimelineDefinition($start, relativeInterval('next weekday'))
            : new TimelineDefinition($start, new DateInterval('P7D'), 2, relativeInterval('next weekday'));

        $exception = intervalError(fn() => $definition->toArray());

        expect($exception->getReason())->toBe(InvalidConfigurationReason::NotSerializable)
            ->and($exception->getContext())->toBe([$key => 'next weekday']);
    })->with(['round_interval', 'slot_interval']);

    // Refusing to write it does not refuse to use it
    it('still gives the slot times of an interval it cannot write', function (): void {
        $definition = new TimelineDefinition(
            new DateTimeImmutable('2026-08-07 19:00:00', new DateTimeZone('Europe/London')),
            relativeInterval('next weekday')
        );

        // Friday 7 August 2026, then Monday 10 and Tuesday 11
        expect($definition->getSlotTime(2)->format('Y-m-d H:i'))->toBe('2026-08-10 18:00')
            ->and($definition->getSlotTime(3)->format('Y-m-d H:i'))->toBe('2026-08-11 18:00');
    });

    it('reads back to the same slot times when it can be written', function (DateInterval $round, ?DateInterval $slot): void {
        // The clocks go back in London on 25 October 2026
        $definition = new TimelineDefinition(
            new DateTimeImmutable('2026-10-10 19:00:00', new DateTimeZone('Europe/London')),
            $round,
            $slot === null ? 1 : 2,
            $slot
        );
        $readBack = TimelineDefinition::fromArray($definition->toArray());

        for ($number = 1; $number <= 4; ++$number) {
            for ($index = 0; $index < $definition->getSlotsPerRound(); ++$index) {
                expect($readBack->getSlotTime($number, $index)->format('c'))->toBe($definition->getSlotTime($number, $index)->format('c'));
            }
        }
    })->with([
        'weekly, from an ISO duration' => [new DateInterval('P7D'), null],
        'weekly, from diff()' => [diffInterval('2026-01-01 00:00', '2026-01-08 00:00'), null],
        'weekly with slots two hours apart' => [new DateInterval('P7D'), new DateInterval('PT2H')],
        'every 168 hours' => [new DateInterval('PT168H'), null],
        'monthly, from diff()' => [diffInterval('2026-01-01 00:00', '2026-02-01 00:00'), null],
    ]);

    // The timeline adds an interval made from a relative date string one
    // step at a time, each step from the one before, and a duration all its
    // steps at once from the start. Written as the duration its amounts
    // spell, such a timeline read back to other slot times: `1 month` from
    // 31 January is 3 March and 3 April, and `P1M` is 28 February and 31
    // March; a weekly 01:30 stays at 02:30 once the clocks have skipped it,
    // and `P7D` goes back to 01:30.
    it('is refused when an interval of it was made from amounts of time in a relative date string', function (
        string $start,
        string $dateString,
        string $duration,
        int $round,
        string $given,
        string $readBack
    ): void {
        $london = new DateTimeZone('Europe/London');
        $definition = new TimelineDefinition(new DateTimeImmutable($start, $london), relativeInterval($dateString));
        $asDuration = new TimelineDefinition(new DateTimeImmutable($start, $london), new DateInterval($duration));

        // Why: the two are not one timeline
        expect($definition->getSlotTime($round)->setTimezone($london)->format('j M H:i'))->toBe($given)
            ->and($asDuration->getSlotTime($round)->setTimezone($london)->format('j M H:i'))->toBe($readBack);

        $exception = intervalError(fn() => $definition->toArray());

        expect($exception->getReason())->toBe(InvalidConfigurationReason::NotSerializable)
            ->and($exception->getContext())->toBe(['round_interval' => $duration]);
    })->with([
        'a month, from the 31st' => ['2026-01-31 19:00:00', '1 month', 'P1M', 2, '3 Mar 19:00', '28 Feb 19:00'],
        'a week, at a time of day the clocks skip once' => ['2026-03-15 01:30:00', '1 week', 'P7D', 4, '5 Apr 02:30', '5 Apr 01:30'],
    ]);

    // parseInterval() refuses a part with a minus sign, so a definition that
    // wrote one could not be read again
    it('is refused when an interval of it has a negative part', function (): void {
        $definition = new TimelineDefinition(
            new DateTimeImmutable('2026-08-01 19:00:00', new DateTimeZone('Europe/London')),
            alteredInterval('P8D', negativeHours: 1)
        );

        $exception = intervalError(fn() => $definition->toArray());

        expect($exception->getReason())->toBe(InvalidConfigurationReason::NotSerializable)
            ->and($exception->getContext())->toBe(['round_interval' => 'P8DT-1H']);
    });
});

describe('a session grid written as plain data', function (): void {
    it('is refused when its slot interval would not read back as the same interval', function (): void {
        $grid = new SessionGrid(
            [new DateTimeImmutable('2026-08-12 20:00:00', new DateTimeZone('Europe/London'))],
            relativeInterval('25 minutes'),
            4
        );

        $exception = intervalError(fn() => $grid->toArray());

        expect($exception->getReason())->toBe(InvalidConfigurationReason::NotSerializable)
            ->and($exception->getContext())->toBe(['slot_interval' => 'PT25M'])
            // Refusing to write it does not refuse to use it
            ->and($grid->getSlotTime(0, 1)->format('H:i'))->toBe('19:25');
    });

    it('is refused when its slot interval is whole days from a relative date string', function (): void {
        $grid = new SessionGrid(
            [new DateTimeImmutable('2026-03-15 01:30:00', new DateTimeZone('Europe/London'))],
            relativeInterval('1 week'),
            4
        );

        expect(intervalError(fn() => $grid->toArray())->getReason())->toBe(InvalidConfigurationReason::NotSerializable);
    });

    it('keeps a fraction of a second of a session start', function (): void {
        $grid = new SessionGrid(
            [
                new DateTimeImmutable('2026-08-12 20:00:00.250000', new DateTimeZone('Europe/London')),
                new DateTimeImmutable('2026-08-19 20:00:00', new DateTimeZone('Europe/London')),
            ],
            new DateInterval('PT25M'),
            2
        );

        $data = $grid->toArray();
        $readBack = SessionGrid::fromArray($data);

        // A start on a whole second is written as it was
        expect($data['sessions'] ?? null)->toBe(['2026-08-12 20:00:00.250000', '2026-08-19 20:00:00'])
            ->and($readBack->getSlotTime(0, 1)->format('U.u'))->toBe($grid->getSlotTime(0, 1)->format('U.u'))
            ->and($readBack->positionOf($grid->getSlotTime(0, 1)))->toBe($grid->positionOf($grid->getSlotTime(0, 1)));
    });

    it('is written as it was when its slot interval is an ISO duration, or has none', function (): void {
        $grid = new SessionGrid(
            [new DateTimeImmutable('2026-08-12 20:00:00', new DateTimeZone('Europe/London'))],
            new DateInterval('PT25M'),
            4
        );

        expect($grid->toArray()['slot_interval'] ?? null)->toBe('PT25M')
            ->and(SessionGrid::fromArray($grid->toArray())->getSlotTime(0, 3)->format('c'))->toBe($grid->getSlotTime(0, 3)->format('c'))
            ->and(SessionGrid::shapeOnly(2, 4)->toArray())->not->toHaveKey('slot_interval');
    });
});
