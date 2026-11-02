<?php

declare(strict_types=1);

use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Repack\SessionGrid;
use MissionGaming\Tactician\Timeline\TimelineDefinition;

/**
 * Slot times are computed from the anchor (the timeline's start, a session's
 * start), never from the slot before. These are properties over anchors,
 * intervals and timezones, not single examples: the defects they guard
 * against only show at a month end or at a daylight-saving change, and only
 * in the rounds after it.
 *
 * The timezones change their clocks in both directions and in both
 * hemispheres; Asia/Kolkata and +05:30 never do.
 */
const ANCHORED_ZONES = ['UTC', 'Europe/London', 'America/New_York', 'Australia/Sydney', 'Asia/Kolkata', '+05:30'];

/**
 * The last day of a month, from the calendar rules alone.
 */
function anchoredLastDayOfMonth(int $year, int $month): int
{
    $leap = ($year % 4 === 0 && $year % 100 !== 0) || $year % 400 === 0;

    return [31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31][$month - 1];
}

/**
 * What the slot-by-slot arithmetic gives: the interval added once for
 * every step, each addition starting from the result of the one before.
 */
function anchoredIteratedAddition(DateTimeImmutable $start, DateInterval $interval, int $steps): DateTimeImmutable
{
    for ($i = 0; $i < $steps; ++$i) {
        $start = $start->add($interval);
    }

    return $start;
}

describe('Slot times computed from the anchor', function (): void {
    it('keeps a monthly interval from the 29th, 30th or 31st on the day, or on the month end', function (): void {
        $checked = 0;
        foreach (ANCHORED_ZONES as $zoneName) {
            $zone = new DateTimeZone($zoneName);
            // 2023 to 2028 holds two leap years, and a start in every month
            foreach ([2023, 2024, 2027, 2028] as $year) {
                foreach (range(1, 12) as $month) {
                    foreach ([29, 30, 31] as $day) {
                        if ($day > anchoredLastDayOfMonth($year, $month)) {
                            continue;
                        }

                        foreach ([1, 2, 3, 12] as $months) {
                            $interval = $months === 12 ? 'P1Y' : "P{$months}M";
                            $timeline = new TimelineDefinition(
                                new DateTimeImmutable(sprintf('%04d-%02d-%02d 19:00', $year, $month, $day), $zone),
                                new DateInterval($interval)
                            );

                            foreach ([2, 3, 4, 7, 13, 14, 25, 49] as $round) {
                                $monthIndex = ($month - 1) + ($round - 1) * $months;
                                $expectedYear = $year + intdiv($monthIndex, 12);
                                $expectedMonth = $monthIndex % 12 + 1;
                                $expectedDay = min($day, anchoredLastDayOfMonth($expectedYear, $expectedMonth));

                                $local = $timeline->getSlotTime($round)->setTimezone($zone)->format('Y-m-d H:i');
                                $expected = sprintf('%04d-%02d-%02d 19:00', $expectedYear, $expectedMonth, $expectedDay);
                                if ($local !== $expected) {
                                    throw new RuntimeException(
                                        "{$interval} from {$year}-{$month}-{$day} in {$zoneName}, round {$round}: {$local}, expected {$expected}"
                                    );
                                }
                                ++$checked;
                            }
                        }
                    }
                }
            }
        }

        expect($checked)->toBeGreaterThan(20000);
    });

    it('states the documented case: P1M from 31 January', function (): void {
        $timeline = new TimelineDefinition(
            new DateTimeImmutable('2026-01-31 19:00', new DateTimeZone('Europe/London')),
            new DateInterval('P1M')
        );

        $dates = [];
        foreach (range(1, 6) as $round) {
            $dates[] = $timeline->getSlotTime($round)->setTimezone(new DateTimeZone('Europe/London'))->format('j M H:i');
        }

        expect($dates)->toBe(['31 Jan 19:00', '28 Feb 19:00', '31 Mar 19:00', '30 Apr 19:00', '31 May 19:00', '30 Jun 19:00']);
    });

    it('keeps the local time of day of a date interval in every round it exists', function (): void {
        // 01:30 and 02:30 are the local times these zones skip when the
        // clocks go forward. A round that lands on the skipped time is
        // moved on by the skipped hour; no other round may be moved.
        $checked = 0;
        foreach (ANCHORED_ZONES as $zoneName) {
            $zone = new DateTimeZone($zoneName);
            foreach (['01:30', '02:30', '19:00'] as $timeOfDay) {
                foreach (['2026-01-04', '2027-06-06', '2028-02-29'] as $date) {
                    foreach (['P1D' => 800, 'P7D' => 120, 'P14D' => 60] as $interval => $rounds) {
                        $start = new DateTimeImmutable("{$date} {$timeOfDay}", $zone);
                        $timeline = new TimelineDefinition($start, new DateInterval($interval));
                        $step = (int) substr($interval, 1, -1);

                        foreach (range(1, $rounds) as $round) {
                            $local = $timeline->getSlotTime($round)->setTimezone($zone);
                            $days = ($round - 1) * $step;
                            $wallClock = new DateTimeImmutable(
                                $start->modify("+{$days} days")->format('Y-m-d') . " {$timeOfDay}",
                                new DateTimeZone('UTC')
                            );

                            // The date is always the anchor's date plus whole days
                            if ($local->format('Y-m-d') !== $wallClock->format('Y-m-d')) {
                                throw new RuntimeException("{$interval} from {$date} {$timeOfDay} {$zoneName}, round {$round}: date {$local->format('Y-m-d')}");
                            }

                            // Does that local time exist on that date? It does
                            // when writing it and reading it back agree.
                            $written = new DateTimeImmutable($wallClock->format('Y-m-d H:i'), $zone);
                            $exists = $written->format('H:i') === $timeOfDay;
                            $expected = $exists ? $timeOfDay : $written->format('H:i');
                            if ($local->format('H:i') !== $expected) {
                                throw new RuntimeException(
                                    "{$interval} from {$date} {$timeOfDay} {$zoneName}, round {$round}: {$local->format('Y-m-d H:i')}, expected {$expected}"
                                );
                            }
                            ++$checked;
                        }
                    }
                }
            }
        }

        expect($checked)->toBeGreaterThan(40000);
    });

    it('states the documented case: a weekly 01:30 in London across the night the clocks go forward', function (): void {
        $london = new DateTimeZone('Europe/London');
        $timeline = new TimelineDefinition(
            new DateTimeImmutable('2026-03-15 01:30', $london),
            new DateInterval('P7D')
        );

        $kickoffs = [];
        foreach (range(1, 5) as $round) {
            $kickoffs[] = $timeline->getSlotTime($round)->setTimezone($london)->format('j M H:i');
        }

        // 29 March has no 01:30; that round alone is moved
        expect($kickoffs)->toBe(['15 Mar 01:30', '22 Mar 01:30', '29 Mar 02:30', '5 Apr 01:30', '12 Apr 01:30']);
    });

    it('keeps a time interval as elapsed time from the start', function (): void {
        $checked = 0;
        foreach (ANCHORED_ZONES as $zoneName) {
            $zone = new DateTimeZone($zoneName);
            foreach (['2026-03-01 01:30', '2026-10-01 19:00', '2028-02-29 02:30'] as $startAt) {
                foreach (['PT1H' => 3600, 'PT90M' => 5400, 'PT24H' => 86400, 'PT168H' => 604800] as $interval => $seconds) {
                    $start = new DateTimeImmutable($startAt, $zone);
                    $timeline = new TimelineDefinition($start, new DateInterval($interval));

                    foreach ([1, 2, 30, 31, 200, 400] as $round) {
                        expect($timeline->getSlotTime($round)->getTimestamp() - $start->getTimestamp())
                            ->toBe(($round - 1) * $seconds);
                        ++$checked;
                    }
                }
            }
        }

        expect($checked)->toBe(432);
    });

    it('agrees with slot-by-slot addition wherever no month end and no clock change is in the way', function (): void {
        // The output that was correct does not change: a start on a day
        // every month has, at a time of day no zone skips or repeats.
        $checked = 0;
        foreach (ANCHORED_ZONES as $zoneName) {
            $zone = new DateTimeZone($zoneName);
            foreach (['2026-01-01 12:00', '2027-05-15 19:30:15', '2028-02-28 09:45'] as $startAt) {
                foreach (['P1M', 'P2M', 'P1Y', 'P1D', 'P7D', 'P1Y2M'] as $roundInterval) {
                    $start = new DateTimeImmutable($startAt, $zone);
                    $timeline = new TimelineDefinition(
                        $start,
                        new DateInterval($roundInterval),
                        slotsPerRound: 4,
                        slotInterval: new DateInterval('PT45M')
                    );

                    foreach ([1, 2, 3, 12, 13, 40] as $round) {
                        $roundStart = anchoredIteratedAddition($start, new DateInterval($roundInterval), $round - 1);
                        foreach ([0, 1, 3] as $slot) {
                            $expected = anchoredIteratedAddition($roundStart, new DateInterval('PT45M'), $slot);
                            expect($timeline->getSlotTime($round, $slot)->format('Y-m-d\TH:i:s.uP'))
                                ->toBe($expected->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP'));
                            ++$checked;
                        }
                    }
                }
            }
        }

        expect($checked)->toBe(1944);
    });

    it('agrees with slot-by-slot addition in a zone that never changes its clocks, whatever the interval', function (): void {
        foreach (['UTC', 'Asia/Kolkata', '+05:30'] as $zoneName) {
            foreach (['P1D', 'P7D', 'PT24H', 'PT90M', 'P1DT12H', 'P2DT30M'] as $roundInterval) {
                $start = new DateTimeImmutable('2026-03-28 01:30', new DateTimeZone($zoneName));
                $timeline = new TimelineDefinition($start, new DateInterval($roundInterval));

                foreach ([1, 2, 5, 100, 365] as $round) {
                    $expected = anchoredIteratedAddition($start, new DateInterval($roundInterval), $round - 1);
                    expect($timeline->getSlotTime($round)->getTimestamp())->toBe($expected->getTimestamp());
                }
            }
        }
    });

    it('gives the kickoffs slot-by-slot addition gave, from every day of two years, where no month end and no skipped hour is met', function (): void {
        // The claim of the changelog, swept: an interval of days or weeks
        // at a time of day that exists on every date, an interval of hours
        // or minutes from any time of day (01:30 is skipped once a year in
        // some of these zones, and elapsed time does not care), and an
        // interval of months or years from the 1st to the 28th. 2027 is a
        // common year and 2028 a leap year. Lord Howe moves its clocks by
        // half an hour.
        $dateIntervals = ['P1D', 'P2D', 'P7D', 'P14D'];
        $timeIntervals = ['PT1H', 'PT90M', 'PT24H', 'PT168H'];
        $monthIntervals = ['P1M', 'P3M', 'P1Y'];
        $checked = 0;
        foreach ([...ANCHORED_ZONES, 'Australia/Lord_Howe', 'America/Sao_Paulo'] as $zoneName) {
            $zone = new DateTimeZone($zoneName);
            for ($day = 0; $day < 731; ++$day) {
                $date = new DateTimeImmutable('2027-01-01', $zone)->modify("+{$day} days")->format('Y-m-d');
                $dayOfMonth = (int) substr($date, 8);

                // One interval of each kind from every day, taken in turn
                // 01:30 and 02:30 are skipped once a year in some of these
                // zones and occur twice once a year: a date interval from
                // them is followed until it meets a skipped one
                $cases = [
                    [$dateIntervals[$day % 4], '12:00'],
                    [$dateIntervals[($day + 1) % 4], $day % 2 === 0 ? '01:30' : '02:30'],
                    [$timeIntervals[$day % 4], '01:30'],
                ];
                if ($dayOfMonth <= 28) {
                    $cases[] = [$monthIntervals[$day % 3], '19:00'];
                }

                foreach ($cases as [$interval, $timeOfDay]) {
                    $start = new DateTimeImmutable("{$date} {$timeOfDay}", $zone);
                    $timeline = new TimelineDefinition($start, new DateInterval($interval));

                    $expected = $start;
                    for ($round = 2; $round <= 30; ++$round) {
                        $expected = $expected->add(new DateInterval($interval));
                        if ($interval[1] !== 'T' && $expected->format('H:i') !== $timeOfDay) {
                            // The round met a skipped local time: slot-by-slot
                            // addition is wrong from here on
                            break;
                        }
                        if ($timeline->getSlotTime($round)->getTimestamp() !== $expected->getTimestamp()) {
                            throw new RuntimeException(
                                "{$interval} from {$date} {$timeOfDay} in {$zoneName}, round {$round}: "
                                    . $timeline->getSlotTime($round)->format('Y-m-d H:i') . ' UTC, slot-by-slot addition gives '
                                    . $expected->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i') . ' UTC'
                            );
                        }
                        ++$checked;
                    }
                }
            }
        }

        expect($checked)->toBeGreaterThan(600000);
    });

    it('takes the first of the two when a local time occurs twice, as PHP reads it', function (): void {
        // London has 01:30 twice on 25 October 2026: at 00:30 UTC, before
        // the clocks go back, and at 01:30 UTC after
        $london = new DateTimeZone('Europe/London');
        $timeline = new TimelineDefinition(
            new DateTimeImmutable('2026-10-11 01:30', $london),
            new DateInterval('P7D')
        );

        $kickoffs = [];
        foreach (range(1, 4) as $round) {
            $kickoffs[] = $timeline->getSlotTime($round)->format('j M H:i');
        }

        expect($kickoffs)->toBe(['11 Oct 00:30', '18 Oct 00:30', '25 Oct 00:30', '1 Nov 01:30']);

        // The same from a start on winter time, 38 weeks before: PHP's own
        // one step from there reads 01:30 at the start's offset, the second
        $fromWinter = new TimelineDefinition(
            new DateTimeImmutable('2026-02-01 01:30', $london),
            new DateInterval('P7D')
        );
        expect($fromWinter->getSlotTime(39)->format('j M H:i'))->toBe('25 Oct 00:30')
            ->and($fromWinter->getSlotTime(40)->format('j M H:i'))->toBe('1 Nov 01:30');

        // Lord Howe puts its clocks back by half an hour, at 02:00: 01:45
        // occurs twice on 4 April 2027, at 14:45 and at 15:15 UTC the day
        // before. The start, in June, is on standard time
        $lordHowe = new TimelineDefinition(
            new DateTimeImmutable('2026-06-04 01:45', new DateTimeZone('Australia/Lord_Howe')),
            new DateInterval('P1M'),
        );
        expect($lordHowe->getSlotTime(11)->format('j M Y H:i'))->toBe('3 Apr 2027 14:45')
            ->and($lordHowe->getSlotTime(12)->format('j M Y H:i'))->toBe('3 May 2027 15:15');

        // A start that is the second 01:30 is kept as the instant it is
        $second = new DateTimeImmutable('2026-10-25 01:30', new DateTimeZone('UTC'))->setTimezone($london);
        $fromSecond = new TimelineDefinition($second, new DateInterval('P7D'), slotsPerRound: 2, slotInterval: new DateInterval('PT30M'));
        expect($fromSecond->getSlotTime(1)->format('j M H:i'))->toBe('25 Oct 01:30')
            ->and($fromSecond->getSlotTime(1, 1)->format('j M H:i'))->toBe('25 Oct 02:00')
            ->and($fromSecond->getSlotTime(2)->format('j M H:i'))->toBe('1 Nov 01:30');
    });

    it('takes the first of the two where the timezone database calls summer time the standard one', function (): void {
        // Ireland and Morocco are recorded with their summer offset as the
        // standard one, and PHP reads a repeated local time by that record:
        // its own addition takes the second 01:30 in Dublin from the week
        // before, where it takes the first in London. The rule here does
        // not read the record, so the two cities, whose clocks agree all
        // year, get the same kickoffs
        $kickoffs = static function (string $zone, string $start, string $interval): array {
            $timeline = new TimelineDefinition(new DateTimeImmutable($start, new DateTimeZone($zone)), new DateInterval($interval));

            return [$timeline->getSlotTime(2)->format('j M Y H:i'), $timeline->getSlotTime(3)->format('j M Y H:i')];
        };

        expect($kickoffs('Europe/Dublin', '2026-10-18 01:30', 'P7D'))->toBe(['25 Oct 2026 00:30', '1 Nov 2026 01:30'])
            ->and($kickoffs('Europe/Dublin', '2026-10-24 01:30', 'P1D'))->toBe(['25 Oct 2026 00:30', '26 Oct 2026 01:30'])
            ->and($kickoffs('Europe/London', '2026-10-18 01:30', 'P7D'))->toBe(['25 Oct 2026 00:30', '1 Nov 2026 01:30']);

        // Morocco puts its clocks back for Ramadan: 02:30 occurs twice on
        // 15 February 2026, at 01:30 and at 02:30 UTC
        expect($kickoffs('Africa/Casablanca', '2026-02-08 02:30', 'P7D'))->toBe(['15 Feb 2026 01:30', '22 Feb 2026 02:30']);
    });

    it('takes the first of the two for an interval long enough to step over the whole summer', function (): void {
        // Eight months from 25 February is 25 October. The round before is
        // on winter time, so adding round by round took the second 01:30,
        // and an interval of years is not the only one that long
        $london = new DateTimeZone('Europe/London');
        foreach (['P8M' => '2026-02-25 01:30', 'P34W' => '2026-03-01 01:30', 'P238D' => '2026-03-01 01:30', 'P1Y' => '2025-10-25 01:30'] as $interval => $start) {
            $timeline = new TimelineDefinition(new DateTimeImmutable($start, $london), new DateInterval($interval));

            expect($timeline->getSlotTime(2)->format('j M Y H:i'))->toBe('25 Oct 2026 00:30', $interval);
        }
    });

    it('puts two rounds a day apart at one time where a timezone skipped the day between them', function (): void {
        // Samoa had no 30 December 2011: it moved across the date line at
        // the end of the 29th. A local time on the 30th is moved on by the
        // skipped day, as one in a skipped hour is moved on by the hour, so
        // the round of the 30th and the round of the 31st are one instant.
        // This is the one way an interval written as a duration gives two
        // slots at one time
        $apia = new DateTimeZone('Pacific/Apia');
        $timeline = new TimelineDefinition(new DateTimeImmutable('2011-12-28 10:00', $apia), new DateInterval('P1D'));

        $local = [];
        foreach (range(1, 5) as $round) {
            $local[] = $timeline->getSlotTime($round)->setTimezone($apia)->format('j M H:i');
        }

        expect($local)->toBe(['28 Dec 10:00', '29 Dec 10:00', '31 Dec 10:00', '31 Dec 10:00', '1 Jan 10:00']);
    });

    it('multiplies a fraction of a second exactly, and carries its whole seconds', function (): void {
        // PHP holds the fraction as whole microseconds and shows it as a
        // float: three steps of 0.29 seconds multiplied as floats are
        // 0.869999. Every step count here is compared with the interval
        // added once per step, which is what the fraction always gave
        $start = new DateTimeImmutable('2026-01-31 19:00:00', new DateTimeZone('UTC'));
        $checked = 0;
        foreach ([0.29, 0.07, 0.1, 0.57, 0.000001, 0.123456, 0.999999, 0.5, 1.5] as $fraction) {
            foreach (['PT0S', 'PT1S', 'PT59M'] as $duration) {
                $interval = new DateInterval($duration);
                $interval->f = $fraction;
                $timeline = new TimelineDefinition($start, $interval);

                foreach ([2, 3, 4, 8, 101, 4322] as $round) {
                    expect($timeline->getSlotTime($round)->format('Y-m-d H:i:s.u'))
                        ->toBe(anchoredIteratedAddition($start, $interval, $round - 1)->format('Y-m-d H:i:s.u'), "{$duration} and {$fraction}, round {$round}");
                    ++$checked;
                }
            }
        }
        expect($checked)->toBe(162);

        $third = new DateInterval('PT0S');
        $third->f = 0.29;
        expect(new TimelineDefinition($start, $third)->getSlotTime(4)->format('s.u'))->toBe('00.870000');

        // The microseconds of many steps are more than PHP's integers hold
        // long before the seconds are: they are carried, not wrapped round
        $quarter = new DateInterval('PT0S');
        $quarter->f = 0.25;
        $far = new TimelineDefinition($start, $quarter)->getSlotTime(4_000_000_000_000_000_001);
        expect($far->getTimestamp() - $start->getTimestamp())->toBe(1_000_000_000_000_000_000);

        // And a fraction counts towards the furthest time there is
        $second = new DateInterval('PT0S');
        $second->f = 0.999999;
        $reason = null;
        try {
            new TimelineDefinition($start, $second)->getSlotTime(PHP_INT_MAX);
        } catch (InvalidConfigurationException $exception) {
            $reason = $exception->getReason();
        }
        expect($reason)->toBe(InvalidConfigurationReason::ValueOutOfRange);
    });

    it('reads the months and days of an interval from diff(), and not its count of days', function (): void {
        // 31 January to 3 March 2026 is "one month and three days" to
        // diff(), which also records 31 days in all. The timeline takes the
        // parts: round 2 is a month on (the end of February) plus three
        // days, round 3 two months on plus six
        $utc = new DateTimeZone('UTC');
        $start = new DateTimeImmutable('2026-01-31 19:00', $utc);
        $interval = $start->diff(new DateTimeImmutable('2026-03-06 19:00', $utc));
        expect([$interval->m, $interval->d, $interval->days])->toBe([1, 3, 34]);

        $timeline = new TimelineDefinition($start, $interval);

        expect($timeline->getSlotTime(2)->format('Y-m-d H:i'))->toBe('2026-03-03 19:00')
            ->and($timeline->getSlotTime(3)->format('Y-m-d H:i'))->toBe('2026-04-06 19:00')
            ->and($timeline->getSlotTime(4)->format('Y-m-d H:i'))->toBe('2026-05-09 19:00');
    });

    it('still drifts for a month written as a relative string, which has no parts to multiply', function (): void {
        // The documented exception: createFromDateString('1 month') is
        // added one step at a time. new DateInterval('P1M') keeps month ends
        $start = new DateTimeImmutable('2026-01-31 19:00', new DateTimeZone('UTC'));
        $relative = new TimelineDefinition($start, DateInterval::createFromDateString('1 month'));
        $duration = new TimelineDefinition($start, new DateInterval('P1M'));

        expect($relative->getSlotTime(2)->format('j M'))->toBe('3 Mar')
            ->and($relative->getSlotTime(3)->format('j M'))->toBe('3 Apr')
            ->and($duration->getSlotTime(2)->format('j M'))->toBe('28 Feb')
            ->and($duration->getSlotTime(3)->format('j M'))->toBe('31 Mar');
    });

    it('refuses a round too far from the start to compute, where PHP would return a date that has wrapped round', function (): void {
        $start = new DateTimeImmutable('2026-01-31 19:00', new DateTimeZone('UTC'));

        foreach (['P7D', 'P1D', 'P1M', 'P1Y', 'PT1H', 'P1M7DT1H'] as $interval) {
            $timeline = new TimelineDefinition($start, new DateInterval($interval));
            $reason = null;
            try {
                $timeline->getSlotTime(PHP_INT_MAX);
            } catch (InvalidConfigurationException $exception) {
                $reason = $exception->getReason();
            }

            expect($reason)->toBe(InvalidConfigurationReason::ValueOutOfRange);
        }

        // A round far away and in range is exact: a thousand million days
        $daily = new TimelineDefinition($start, new DateInterval('P1D'));
        expect($daily->getSlotTime(1_000_000_001)->getTimestamp() - $start->getTimestamp())->toBe(86_400 * 1_000_000_000);

        // An interval that is itself out of range is refused when the timeline is built
        $huge = new DateInterval('P1D');
        $huge->d = PHP_INT_MAX;
        expect(fn() => new TimelineDefinition($start, $huge))->toThrow(InvalidConfigurationException::class, 'too far');
    });

    it('takes the months of a mixed interval first and its days after, from the start', function (): void {
        // Round 13 of P1M7D is the start plus twelve months, plus 84 days:
        // one step of P12M84D. Adding P1M7D twelve times in turn lets the
        // days of each step run into the next month, and lands elsewhere.
        $timeline = new TimelineDefinition(
            new DateTimeImmutable('2026-01-01 12:00', new DateTimeZone('UTC')),
            new DateInterval('P1M7D')
        );

        expect($timeline->getSlotTime(2)->format('Y-m-d H:i'))->toBe('2026-02-08 12:00')
            ->and($timeline->getSlotTime(3)->format('Y-m-d H:i'))->toBe('2026-03-15 12:00')
            ->and($timeline->getSlotTime(13)->format('Y-m-d H:i'))->toBe('2027-03-26 12:00');
    });

    it('places the slots of a round from the round\'s first slot', function (): void {
        // The night the clocks go forward in London: slots a day apart from
        // 01:30. The slot on the 29th has no 01:30 and is moved; the slot
        // after it is not.
        $london = new DateTimeZone('Europe/London');
        $timeline = new TimelineDefinition(
            new DateTimeImmutable('2026-03-28 01:30', $london),
            new DateInterval('P1M'),
            slotsPerRound: 3,
            slotInterval: new DateInterval('P1D')
        );

        $slots = [];
        foreach ([0, 1, 2] as $slot) {
            $slots[] = $timeline->getSlotTime(1, $slot)->setTimezone($london)->format('j M H:i');
        }

        expect($slots)->toBe(['28 Mar 01:30', '29 Mar 02:30', '30 Mar 01:30']);
    });

    it('keeps the local time of an interval that PHP adds as local time', function (): void {
        // An interval from diff() is added as local time whatever its parts
        // (PHP's rule, which the class description states): 168 hours from
        // diff() is 19:00 a week later across a clock change, where
        // PT168H from the constructor is 168 elapsed hours.
        $utc = new DateTimeZone('UTC');
        $london = new DateTimeZone('Europe/London');
        $week = new DateTimeImmutable('2026-01-01', $utc)->diff(new DateTimeImmutable('2026-01-08', $utc));
        $week->d = 0;
        $week->h = 168;

        $timeline = new TimelineDefinition(new DateTimeImmutable('2026-03-15 19:00', $london), $week);

        foreach (range(1, 6) as $round) {
            expect($timeline->getSlotTime($round)->setTimezone($london)->format('H:i'))->toBe('19:00');
        }
        expect($timeline->getSlotTime(4)->setTimezone($london)->format('Y-m-d'))->toBe('2026-04-05');
    });

    it('still adds a relative interval one step at a time, which is the only reading it has', function (): void {
        // "next weekday" has no multiple: three of them are three steps.
        $timeline = new TimelineDefinition(
            new DateTimeImmutable('2026-08-06 19:00', new DateTimeZone('UTC')), // a Thursday
            DateInterval::createFromDateString('next weekday')
        );

        expect($timeline->getSlotTime(2)->format('D j M'))->toBe('Fri 7 Aug')
            ->and($timeline->getSlotTime(3)->format('D j M'))->toBe('Mon 10 Aug')
            ->and($timeline->getSlotTime(4)->format('D j M'))->toBe('Tue 11 Aug');
    });
});

describe('Session grid slot times computed from the session start', function (): void {
    it('moves only the slot that lands in the hour the clocks skip', function (): void {
        $london = new DateTimeZone('Europe/London');
        $grid = new SessionGrid(
            [new DateTimeImmutable('2026-03-27 01:30', $london)],
            new DateInterval('P1D'),
            slotsPerSession: 5
        );

        $slots = [];
        foreach (range(0, 4) as $slot) {
            $slots[] = $grid->getSlotTime(0, $slot)->setTimezone($london)->format('j M H:i');
        }

        expect($slots)->toBe(['27 Mar 01:30', '28 Mar 01:30', '29 Mar 02:30', '30 Mar 01:30', '31 Mar 01:30']);

        // positionOf() is the reverse of getSlotTime() for every position
        foreach (range(0, 4) as $slot) {
            expect($grid->positionOf($grid->getSlotTime(0, $slot)))->toBe(['session' => 0, 'slot' => $slot]);
        }
    });

    it('keeps slots of hours and minutes that far apart, in every zone', function (): void {
        foreach (ANCHORED_ZONES as $zoneName) {
            $zone = new DateTimeZone($zoneName);
            foreach (['2026-03-28 23:30', '2026-10-24 23:30', '2026-04-04 23:30', '2026-11-01 00:30'] as $startAt) {
                $start = new DateTimeImmutable($startAt, $zone);
                $grid = new SessionGrid([$start], new DateInterval('PT25M'), slotsPerSession: 12);

                foreach (range(0, 11) as $slot) {
                    expect($grid->getSlotTime(0, $slot)->getTimestamp() - $start->getTimestamp())->toBe($slot * 1500);
                }
            }
        }
    });
});
