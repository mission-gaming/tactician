<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Timeline\TimelineAssigner;
use MissionGaming\Tactician\Timeline\TimelineDefinition;
use PHPUnit\Framework\Assert;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/*
 * Which rounds the assigner compares. The rounds of a timeline must not
 * overlap, and the ones that can are the ones that are played: assign()
 * compares the rounds its schedule holds, in round order, each with the next
 * one it holds, and assignRound() compares its round with the one before it.
 * A round nobody plays is not a round to run into.
 */

/**
 * A schedule with one event in each of the given rounds.
 */
function roundsHeldSchedule(int ...$rounds): Schedule
{
    $a = new Participant('a', 'A');
    $b = new Participant('b', 'B');

    return new Schedule(array_map(static fn(int $round): Event => new Event([$a, $b], new Round($round)), $rounds));
}

/**
 * A pairing of one event in the given round.
 */
function roundsHeldPairing(int $round): RoundPairing
{
    return new RoundPairing($round, null, [new Event([new Participant('a', 'A'), new Participant('b', 'B')], new Round($round))]);
}

/**
 * The exception an assignment is refused with, or null when it is made.
 *
 * @param Closure(): mixed $assign
 */
function roundsHeldRefusal(Closure $assign): ?InvalidConfigurationException
{
    try {
        $assign();
    } catch (InvalidConfigurationException $exception) {
        return $exception;
    }

    return null;
}

/**
 * Half an hour as `diff()` returns it, which PHP adds as local time.
 */
function roundsHeldLocalHalfHour(): DateInterval
{
    $utc = new DateTimeZone('UTC');

    return (new DateTimeImmutable('2026-01-01 00:00', $utc))->diff(new DateTimeImmutable('2026-01-01 00:30', $utc));
}

/**
 * Whether the rounds a schedule holds are sound on a timeline, worked out
 * from the slot times alone and without the assigner: within every round
 * held, each slot is after the slot before it; and for every two rounds
 * held, every slot of the one with the lower number is before every slot of
 * the other. Every pair of rounds and every pair of slots is looked at.
 *
 * @param list<int> $rounds
 *
 * @throws InvalidConfigurationException When the time of a round cannot be computed
 */
function roundsHeldAreSound(TimelineDefinition $timeline, array $rounds): bool
{
    $slots = $timeline->getSlotsPerRound();

    /** @var array<int, list<DateTimeImmutable>> $times */
    $times = [];
    foreach ($rounds as $round) {
        $times[$round] = [];
        for ($slot = 0; $slot < $slots; ++$slot) {
            $times[$round][] = $timeline->getSlotTime($round, $slot);
        }
    }

    foreach ($times as $round => $ofRound) {
        for ($slot = 1; $slot < $slots; ++$slot) {
            if ($ofRound[$slot] <= $ofRound[$slot - 1]) {
                return false;
            }
        }

        foreach ($times as $other => $ofOther) {
            if ($other <= $round) {
                continue;
            }

            foreach ($ofRound as $earlier) {
                foreach ($ofOther as $later) {
                    if ($earlier >= $later) {
                        return false;
                    }
                }
            }
        }
    }

    return true;
}

describe('assign() compares the rounds the schedule holds', function (): void {
    it('compares a round with the next one the schedule holds, across a gap', function (): void {
        // Rounds half a local hour apart from 00:30 on the night London
        // skips 01:00 to 02:00: round 2 would be 01:00, which is read as
        // 02:00, and round 4 is 02:00. Rounds 2 and 4 are one kickoff, and
        // neither is at the time of the round after it (02:30 and 03:00)
        $timeline = new TimelineDefinition(
            new DateTimeImmutable('2026-03-29 00:30', new DateTimeZone('Europe/London')),
            roundsHeldLocalHalfHour()
        );
        $assigner = new TimelineAssigner();

        expect($timeline->getSlotTime(2))->toEqual($timeline->getSlotTime(4))
            ->and($timeline->getSlotTime(3) > $timeline->getSlotTime(2))->toBeTrue()
            ->and($timeline->getSlotTime(5) > $timeline->getSlotTime(4))->toBeTrue();

        $refusal = roundsHeldRefusal(fn() => $assigner->assign(roundsHeldSchedule(2, 4), $timeline));

        Assert::assertNotNull($refusal, 'Rounds 2 and 4 are at one instant and were assigned.');
        expect($refusal->getReason())->toBe(InvalidConfigurationReason::OverlappingTime)
            ->and($refusal->getMessage())->toBe(
                'Invalid scheduler configuration: Round 2 is still being played when round 4 starts: '
                . 'its last slot is at 2026-03-29 01:00:00 UTC and round 4 starts at 2026-03-29 01:00:00 UTC'
            )
            ->and($refusal->getContext())->toBe([
                'round' => 2,
                'next_round' => 4,
                'slots_per_round' => 1,
                'last_slot' => '2026-03-29T01:00:00+00:00',
                'next_round_start' => '2026-03-29T01:00:00+00:00',
            ]);

        // Each of the two with other rounds, none of which shares its time
        expect($assigner->assign(roundsHeldSchedule(1, 2, 3), $timeline))->toHaveCount(3)
            ->and($assigner->assign(roundsHeldSchedule(1, 4, 5), $timeline))->toHaveCount(3);
    });

    it('names the two rounds in round order, whatever the order of the events in the schedule', function (): void {
        // The same night: rounds 2 and 4 are one kickoff, and round 6 is
        // clear of both. The events are given latest round first
        $timeline = new TimelineDefinition(
            new DateTimeImmutable('2026-03-29 00:30', new DateTimeZone('Europe/London')),
            roundsHeldLocalHalfHour()
        );

        $refusal = roundsHeldRefusal(fn() => (new TimelineAssigner())->assign(roundsHeldSchedule(6, 4, 2), $timeline));

        expect($refusal?->getReason())->toBe(InvalidConfigurationReason::OverlappingTime)
            ->and($refusal?->getContext()['round'] ?? null)->toBe(2)
            ->and($refusal?->getContext()['next_round'] ?? null)->toBe(4);
    });

    it('does not refuse a round for running into a round the schedule does not hold', function (): void {
        // Monthly rounds from 31 December, two slots 30 days apart. Round 2
        // is 31 January and 2 March; round 3 starts on 28 February, the last
        // day of a short month, so round 2 runs into it
        $timeline = new TimelineDefinition(
            new DateTimeImmutable('2026-12-31 19:00', new DateTimeZone('UTC')),
            new DateInterval('P1M'),
            slotsPerRound: 2,
            slotInterval: new DateInterval('P30D')
        );
        $assigner = new TimelineAssigner();

        expect($timeline->getSlotTime(2, 1)->format('Y-m-d'))->toBe('2027-03-02')
            ->and($timeline->getSlotTime(3, 0)->format('Y-m-d'))->toBe('2027-02-28');

        // A schedule that ends with round 2 has no round 3 to run into
        $scheduled = $assigner->assign(roundsHeldSchedule(1, 2), $timeline);
        expect(array_map(
            static fn($scheduledEvent): string => $scheduledEvent->getKickoff()->format('Y-m-d H:i'),
            $scheduled->getScheduledEvents()
        ))->toBe(['2026-12-31 19:00', '2027-01-31 19:00']);

        // Nor has one that goes on at round 4, which starts on 31 March
        expect($assigner->assign(roundsHeldSchedule(1, 2, 4), $timeline))->toHaveCount(3);

        // One that holds round 3 as well is refused, and for those two rounds
        $refusal = roundsHeldRefusal(fn() => $assigner->assign(roundsHeldSchedule(1, 2, 3), $timeline));
        Assert::assertNotNull($refusal, 'Round 2 ends after round 3 starts and both were assigned.');
        expect($refusal->getReason())->toBe(InvalidConfigurationReason::OverlappingTime)
            ->and($refusal->getContext()['round'] ?? null)->toBe(2)
            ->and($refusal->getContext()['next_round'] ?? null)->toBe(3);
    });

    it('compares the last round of a schedule with no round after it', function (): void {
        $timeline = new TimelineDefinition(
            new DateTimeImmutable('2026-12-31 19:00', new DateTimeZone('UTC')),
            new DateInterval('P1M'),
            slotsPerRound: 2,
            slotInterval: new DateInterval('P30D')
        );

        expect((new TimelineAssigner())->assign(roundsHeldSchedule(2), $timeline))->toHaveCount(1);
    });

    it('refuses a later round that is at an earlier time, as it refuses one at the same time', function (): void {
        // An interval with a negative part can be built by hand, and its
        // rounds need not rise: a month less 30 days from 31 December is
        // 1 January, and two months less 60 days is 30 December
        $interval = new DateInterval('P1M');
        $interval->d = -30;
        $timeline = new TimelineDefinition(new DateTimeImmutable('2026-12-31 19:00', new DateTimeZone('UTC')), $interval);
        $assigner = new TimelineAssigner();

        expect($timeline->getSlotTime(2)->format('Y-m-d'))->toBe('2027-01-01')
            ->and($timeline->getSlotTime(3)->format('Y-m-d'))->toBe('2026-12-30')
            ->and($assigner->assign(roundsHeldSchedule(1, 2), $timeline))->toHaveCount(2)
            ->and(roundsHeldRefusal(fn() => $assigner->assign(roundsHeldSchedule(1, 3), $timeline))?->getReason())
            ->toBe(InvalidConfigurationReason::OverlappingTime);
    });

    it('still checks the slots a round declares, filled or not', function (): void {
        // Round 2's two slots are both 02:30 on the night the clocks skip
        // 01:30; its one event fills the first of them
        $utc = new DateTimeZone('UTC');
        $localHour = (new DateTimeImmutable('2026-01-01 00:00', $utc))->diff(new DateTimeImmutable('2026-01-01 01:00', $utc));
        $timeline = new TimelineDefinition(
            new DateTimeImmutable('2026-03-22 00:30', new DateTimeZone('Europe/London')),
            new DateInterval('P7D'),
            slotsPerRound: 3,
            slotInterval: $localHour
        );

        $refusal = roundsHeldRefusal(fn() => (new TimelineAssigner())->assign(roundsHeldSchedule(1, 2), $timeline));

        expect($refusal?->getReason())->toBe(InvalidConfigurationReason::OverlappingTime)
            ->and($refusal?->getMessage())->toContain('Round 2 has two slots that are not at rising times');
    });
});

describe('assignRound() compares the round with the one before it', function (): void {
    it('refuses the later of two rounds that overlap, and assigns the earlier', function (): void {
        $timeline = new TimelineDefinition(
            new DateTimeImmutable('2026-12-31 19:00', new DateTimeZone('UTC')),
            new DateInterval('P1M'),
            slotsPerRound: 2,
            slotInterval: new DateInterval('P30D')
        );
        $assigner = new TimelineAssigner();

        // Round 2 is the last round of a stage of two rounds: nothing
        // follows it, and it is assigned
        expect($assigner->assignRound(roundsHeldPairing(1), $timeline))->toHaveCount(1)
            ->and($assigner->assignRound(roundsHeldPairing(2), $timeline))->toHaveCount(1);

        // A stage that goes on to round 3 is told when round 3 is assigned
        $refusal = roundsHeldRefusal(fn() => $assigner->assignRound(roundsHeldPairing(3), $timeline));
        Assert::assertNotNull($refusal, 'Round 3 starts before round 2 has ended and was assigned.');
        expect($refusal->getReason())->toBe(InvalidConfigurationReason::OverlappingTime)
            ->and($refusal->getMessage())->toBe(
                'Invalid scheduler configuration: Round 2 is still being played when round 3 starts: '
                . 'its last slot is at 2027-03-02 19:00:00 UTC and round 3 starts at 2027-02-28 19:00:00 UTC'
            )
            ->and($refusal->getContext())->toBe([
                'round' => 2,
                'next_round' => 3,
                'slots_per_round' => 2,
                'last_slot' => '2027-03-02T19:00:00+00:00',
                'next_round_start' => '2027-02-28T19:00:00+00:00',
            ]);

        expect($assigner->assignRound(roundsHeldPairing(4), $timeline))->toHaveCount(1);
    });

    it('agrees with assign() on every stage played in order from round 1', function (): void {
        // A stage of n rounds, assigned round by round, is refused exactly
        // when the schedule of its rounds is, and at the same two rounds
        $assigner = new TimelineAssigner();
        $checked = 0;
        foreach ([['2026-12-31', 'P1M', 'P30D'], ['2026-03-01', 'P1M', 'P29D'], ['2026-03-31', 'P1M', 'P29D'], ['2026-01-31', 'P1M', 'P27D']] as [$start, $round, $slot]) {
            $timeline = new TimelineDefinition(
                new DateTimeImmutable("{$start} 19:00", new DateTimeZone('UTC')),
                new DateInterval($round),
                slotsPerRound: 2,
                slotInterval: new DateInterval($slot)
            );

            foreach (range(1, 15) as $rounds) {
                $whole = roundsHeldRefusal(fn() => $assigner->assign(roundsHeldSchedule(...range(1, $rounds)), $timeline));

                $byRound = null;
                foreach (range(1, $rounds) as $round) {
                    $byRound ??= roundsHeldRefusal(fn() => $assigner->assignRound(roundsHeldPairing($round), $timeline));
                }

                expect($byRound?->getContext())->toBe($whole?->getContext());
                ++$checked;
            }
        }

        expect($checked)->toBe(60);
    });

    it('refuses a pairing of a round below 1 for its number, and compares it with no round before it', function (int $round): void {
        $timeline = new TimelineDefinition(new DateTimeImmutable('2026-08-01 18:00', new DateTimeZone('UTC')), new DateInterval('P7D'));
        $pairing = new RoundPairing($round, null, [new Event([new Participant('a', 'A'), new Participant('b', 'B')], new Round(1))]);

        $refusal = roundsHeldRefusal(fn() => (new TimelineAssigner())->assignRound($pairing, $timeline));

        expect($refusal?->getReason())->toBe(InvalidConfigurationReason::PositionOutOfRange)
            ->and($refusal?->getContext())->toBe(['round' => $round]);
    })->with([0, -1, PHP_INT_MIN]);

    it('compares the round with the one before it by number, whether or not that one was played', function (): void {
        // The monthly timeline above: round 2 runs into round 3. A caller
        // that plays rounds 1 and 3 and skips 2 has round 3 compared with
        // round 2 all the same, and refused; the schedule of the rounds it
        // played is assigned
        $timeline = new TimelineDefinition(
            new DateTimeImmutable('2026-12-31 19:00', new DateTimeZone('UTC')),
            new DateInterval('P1M'),
            slotsPerRound: 2,
            slotInterval: new DateInterval('P30D')
        );
        $assigner = new TimelineAssigner();

        expect($assigner->assignRound(roundsHeldPairing(1), $timeline))->toHaveCount(1);

        $refusal = roundsHeldRefusal(fn() => $assigner->assignRound(roundsHeldPairing(3), $timeline));
        expect($refusal?->getReason())->toBe(InvalidConfigurationReason::OverlappingTime)
            ->and($refusal?->getContext()['round'] ?? null)->toBe(2)
            ->and($refusal?->getContext()['next_round'] ?? null)->toBe(3)
            ->and($assigner->assign(roundsHeldSchedule(1, 3), $timeline))->toHaveCount(2);
    });

    it('compares round 1 with nothing, and a pairing with no events with nothing', function (): void {
        $utc = new DateTimeZone('UTC');
        $localHour = (new DateTimeImmutable('2026-01-01 00:00', $utc))->diff(new DateTimeImmutable('2026-01-01 01:00', $utc));
        // Rounds 2 and 3 are both 02:30 local
        $timeline = new TimelineDefinition(new DateTimeImmutable('2026-03-29 00:30', new DateTimeZone('Europe/London')), $localHour);
        $assigner = new TimelineAssigner();

        expect($assigner->assignRound(roundsHeldPairing(1), $timeline))->toHaveCount(1)
            ->and($assigner->assignRound(new RoundPairing(3, null, []), $timeline))->toBe([])
            ->and(roundsHeldRefusal(fn() => $assigner->assignRound(roundsHeldPairing(3), $timeline))?->getReason())
            ->toBe(InvalidConfigurationReason::OverlappingTime);
    });
});

describe('No two rounds of an assigned schedule overlap', function (): void {
    it('refuses exactly the schedules whose held rounds overlap, for generated timelines and schedules with gaps', function (): void {
        $randomizer = new Randomizer(new Xoshiro256StarStar(152));
        $utc = new DateTimeZone('UTC');

        $localHour = (new DateTimeImmutable('2026-01-01 00:00', $utc))->diff(new DateTimeImmutable('2026-01-01 01:00', $utc));
        $monthLessDays = static function (int $days): DateInterval {
            $interval = new DateInterval('P1M');
            $interval->d = -$days;

            return $interval;
        };

        // The pools lean towards what makes rounds meet: months of unequal
        // length under slots four weeks apart, intervals PHP adds as local
        // time on the nights the clocks change, and intervals with a
        // negative part, whose rounds need not rise
        /** @var list<Closure(): DateInterval> $roundIntervals */
        $roundIntervals = [
            static fn(): DateInterval => new DateInterval('P1M'),
            static fn(): DateInterval => new DateInterval('P1M'),
            static fn(): DateInterval => new DateInterval('P1M'),
            static fn(): DateInterval => new DateInterval('P1M'),
            static fn(): DateInterval => new DateInterval('P1M1D'),
            static fn(): DateInterval => new DateInterval('P1D'),
            static fn(): DateInterval => new DateInterval('P7D'),
            static fn(): DateInterval => new DateInterval('PT1H'),
            static fn(): DateInterval => new DateInterval('PT6H'),
            static fn(): DateInterval => $localHour,
            static fn(): DateInterval => $localHour,
            roundsHeldLocalHalfHour(...),
            roundsHeldLocalHalfHour(...),
            roundsHeldLocalHalfHour(...),
            static fn(): DateInterval => DateInterval::createFromDateString('1 month'),
            static fn(): DateInterval => DateInterval::createFromDateString('next weekday'),
            static fn(): DateInterval => $monthLessDays(27),
            static fn(): DateInterval => $monthLessDays(28),
            static fn(): DateInterval => $monthLessDays(29),
            static fn(): DateInterval => $monthLessDays(30),
        ];
        /** @var list<Closure(): DateInterval> $slotIntervals */
        $slotIntervals = [
            static fn(): DateInterval => new DateInterval('PT20M'),
            static fn(): DateInterval => new DateInterval('PT1H'),
            static fn(): DateInterval => new DateInterval('PT5H'),
            static fn(): DateInterval => new DateInterval('P1D'),
            static fn(): DateInterval => new DateInterval('P7D'),
            static fn(): DateInterval => new DateInterval('P14D'),
            static fn(): DateInterval => new DateInterval('P27D'),
            static fn(): DateInterval => new DateInterval('P28D'),
            static fn(): DateInterval => new DateInterval('P29D'),
            static fn(): DateInterval => new DateInterval('P29D'),
            static fn(): DateInterval => new DateInterval('P30D'),
            static fn(): DateInterval => new DateInterval('P30D'),
            static fn(): DateInterval => $localHour,
            static fn(): DateInterval => DateInterval::createFromDateString('1 day'),
        ];
        $zones = ['UTC', 'Europe/London', 'Europe/London', 'Australia/Sydney', 'America/New_York', 'Pacific/Apia'];
        // Month ends, and the nights the clocks change in London
        $starts = [
            '2026-01-29', '2026-01-30', '2026-01-31', '2026-03-01', '2026-03-28', '2026-03-29', '2026-03-29',
            '2026-03-31', '2026-05-31', '2026-08-31', '2026-10-24', '2026-10-25', '2026-12-31', '2026-12-31',
            '2027-01-31', '2028-01-31', '2011-12-28',
        ];
        $times = ['00:00', '00:30', '00:30', '01:00', '01:30', '12:00', '19:00', '23:30'];

        $assigner = new TimelineAssigner();
        $assigned = 0;
        $refused = 0;
        $refusedAcrossAGap = 0;
        $assignedAlthoughNumberedNeighbourOverlaps = 0;

        $pick = static fn(array $from): mixed => $from[$randomizer->getInt(0, count($from) - 1)];

        for ($case = 0; $case < 3000; ++$case) {
            // Half the cases from anywhere in the pools, and half from three
            // families in which rounds are known to meet
            $family = $randomizer->getInt(0, 5);
            try {
                $timeline = match ($family) {
                    // Monthly rounds of two slots about four weeks apart
                    3 => new TimelineDefinition(
                        new DateTimeImmutable($pick(['2026-01-31', '2026-03-01', '2026-03-31', '2026-12-31', '2027-01-30']) . ' 19:00', new DateTimeZone($pick($zones))),
                        new DateInterval('P1M'),
                        2,
                        new DateInterval($pick(['P27D', 'P28D', 'P29D', 'P30D']))
                    ),
                    // Rounds a local hour or half hour apart, on the night
                    // London skips an hour
                    4 => new TimelineDefinition(
                        new DateTimeImmutable('2026-03-29 ' . $pick(['00:00', '00:30']), new DateTimeZone('Europe/London')),
                        $pick([$localHour, roundsHeldLocalHalfHour()])
                    ),
                    // Rounds that do not rise with their numbers
                    5 => new TimelineDefinition(
                        new DateTimeImmutable($pick(['2026-12-31', '2027-01-30', '2026-03-31']) . ' 19:00', $utc),
                        $monthLessDays($pick([27, 28, 29, 30]))
                    ),
                    default => new TimelineDefinition(
                        new DateTimeImmutable($pick($starts) . ' ' . $pick($times), new DateTimeZone($pick($zones))),
                        $pick($roundIntervals)(),
                        $slots = $randomizer->getInt(1, 4),
                        $slots > 1 || $randomizer->getInt(0, 1) === 1 ? $pick($slotIntervals)() : null
                    ),
                };
            } catch (InvalidConfigurationException) {
                // A definition whose first two rounds overlap, or whose
                // interval does not move forward, is not one to assign onto
                continue;
            }

            // One to six rounds out of the first twelve, so most have gaps
            $rounds = [];
            foreach ($randomizer->pickArrayKeys(array_fill(1, 12, true), $randomizer->getInt(1, 6)) as $round) {
                $rounds[] = (int) $round;
            }
            sort($rounds);
            $shuffled = $randomizer->shuffleArray($rounds);

            $sound = roundsHeldAreSound($timeline, $rounds);

            $scheduled = null;
            $refusal = null;
            try {
                // The order of the events in the schedule is not the order of the rounds
                $scheduled = $assigner->assign(roundsHeldSchedule(...$shuffled), $timeline);
            } catch (InvalidConfigurationException $exception) {
                $refusal = $exception;
            }

            $described = json_encode([
                'start' => $timeline->getStart()->format('Y-m-d H:i e'),
                'round_interval' => TimelineDefinition::formatInterval($timeline->getRoundInterval()),
                'slots' => $timeline->getSlotsPerRound(),
                'slot_interval' => $timeline->getSlotInterval() instanceof DateInterval ? TimelineDefinition::formatInterval($timeline->getSlotInterval()) : null,
                'rounds' => $rounds,
                'refusal' => $refusal?->getMessage(),
            ]);

            if (!$sound) {
                Assert::assertNotNull($refusal, "Rounds that overlap were assigned: {$described}");
                Assert::assertSame(InvalidConfigurationReason::OverlappingTime, $refusal->getReason(), (string) $described);
                ++$refused;

                // Refused although every round held is clear of the round
                // numbered after it: only comparing the rounds held finds it
                $clearOfNumberedNeighbours = true;
                foreach ($rounds as $round) {
                    $clearOfNumberedNeighbours = $clearOfNumberedNeighbours && roundsHeldAreSound($timeline, [$round, $round + 1]);
                }
                $refusedAcrossAGap += $clearOfNumberedNeighbours ? 1 : 0;

                continue;
            }

            Assert::assertNull($refusal, "Rounds that do not overlap were refused: {$described}");
            Assert::assertNotNull($scheduled);
            ++$assigned;

            // The kickoffs returned, read on their own: one for each round,
            // at the round's first slot, each after the one before it
            $kickoffs = [];
            foreach ($scheduled->getScheduledEvents() as $scheduledEvent) {
                $kickoffs[(int) $scheduledEvent->getEvent()->getRound()?->getNumber()] = $scheduledEvent->getKickoff();
            }
            Assert::assertSame($rounds, array_keys($kickoffs), (string) $described);
            $previous = null;
            foreach ($kickoffs as $round => $kickoff) {
                Assert::assertEquals($timeline->getSlotTime($round), $kickoff, (string) $described);
                Assert::assertTrue($previous === null || $kickoff > $previous, "Kickoffs out of order: {$described}");
                $previous = $kickoff;
            }

            foreach ($rounds as $round) {
                if (!roundsHeldAreSound($timeline, [$round, $round + 1])) {
                    ++$assignedAlthoughNumberedNeighbourOverlaps;
                    break;
                }
            }
        }

        // The generator reaches both answers, and both cases of the report:
        // an overlap across a gap, and a round that runs into one not held
        expect($assigned)->toBeGreaterThan(1000)
            ->and($refused)->toBeGreaterThan(100)
            ->and($refusedAcrossAGap)->toBeGreaterThan(5)
            ->and($assignedAlthoughNumberedNeighbourOverlaps)->toBeGreaterThan(100);
    });
});
