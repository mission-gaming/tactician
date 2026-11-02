<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Tests\Support\UncheckedEvent;
use MissionGaming\Tactician\Timeline\MinimumRestRule;
use MissionGaming\Tactician\Timeline\TimelineAssigner;
use MissionGaming\Tactician\Timeline\TimelineDefinition;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;

// A slot with two resources holds two events at one instant. Nothing
// stopped both from holding the same participant: the assigner returned a
// timeline in which one participant plays on two pitches at once, unless a
// minimum rest rule happened to be configured.

/**
 * Weekly rounds from 1 August 2026, 18:00 UTC, on two pitches.
 *
 * @throws InvalidConfigurationException
 */
function twoPitchTimeline(int $slotsPerRound = 1): TimelineDefinition
{
    return new TimelineDefinition(
        new DateTimeImmutable('2026-08-01 18:00:00', new DateTimeZone('UTC')),
        new DateInterval('P7D'),
        $slotsPerRound,
        $slotsPerRound > 1 ? new DateInterval('PT2H') : null,
        ['Pitch 1', 'Pitch 2']
    );
}

/**
 * The configuration error a call raises.
 *
 * @param Closure(): mixed $call
 *
 * @throws AssertionFailedError When the call raises none
 */
function doubleBookingError(Closure $call): InvalidConfigurationException
{
    try {
        $call();
    } catch (InvalidConfigurationException $exception) {
        return $exception;
    }

    Assert::fail('The assigner returned a timeline with a participant in two events at one time.');
}

describe('a participant in two events of one slot', function (): void {
    beforeEach(function (): void {
        $this->alice = new Participant('a', 'Alice');
        $this->bob = new Participant('b', 'Bob');
        $this->carol = new Participant('c', 'Carol');
        $this->dave = new Participant('d', 'Dave');
    });

    it('is refused by assign(), with no rule configured', function (): void {
        $schedule = new Schedule([
            new Event([$this->alice, $this->bob], new Round(1)),
            new Event([$this->alice, $this->carol], new Round(1)),
        ]);

        $exception = doubleBookingError(fn() => new TimelineAssigner()->assign($schedule, twoPitchTimeline()));

        expect($exception->getReason())->toBe(InvalidConfigurationReason::ParticipantDoubleBooked)
            ->and($exception->getConfigurationIssue())->toBe(
                'Round 1 has 1 participant(s) in two events at the same time:'
                . ' Alice is in Alice vs Bob and in Alice vs Carol at 2026-08-01 18:00:00 UTC.'
            )
            ->and($exception->getContext())->toBe([
                'round' => 1,
                'double_bookings' => [
                    [
                        'participant' => 'a',
                        'kickoff' => '2026-08-01T18:00:00+00:00',
                        'events' => ['a vs b', 'a vs c'],
                    ],
                ],
            ]);
    });

    it('is refused by assignRound()', function (): void {
        $pairing = new RoundPairing(2, null, [
            new Event([$this->alice, $this->bob], new Round(2)),
            new Event([$this->carol, $this->alice], new Round(2)),
        ]);

        $exception = doubleBookingError(fn() => new TimelineAssigner()->assignRound($pairing, twoPitchTimeline()));

        expect($exception->getReason())->toBe(InvalidConfigurationReason::ParticipantDoubleBooked)
            ->and($exception->getContext()['round'])->toBe(2)
            ->and($exception->getContext()['double_bookings'])->toBe([
                ['participant' => 'a', 'kickoff' => '2026-08-08T18:00:00+00:00', 'events' => ['a vs b', 'c vs a']],
            ]);
    });

    it('lists every participant concerned', function (): void {
        $schedule = new Schedule([
            new Event([$this->alice, $this->bob], new Round(1)),
            new Event([$this->bob, $this->alice], new Round(1)),
        ]);

        $exception = doubleBookingError(fn() => new TimelineAssigner()->assign($schedule, twoPitchTimeline()));

        // In the order they are found: the participants of the later event, in its order
        expect(array_column($exception->getContext()['double_bookings'], 'participant'))->toBe(['b', 'a'])
            ->and($exception->getConfigurationIssue())->toStartWith('Round 1 has 2 participant(s) in two events at the same time:');
    });

    // It is the same mistake whether or not a rule would have reported it,
    // so it has the same reason
    it('is refused for the same reason when a minimum rest rule is configured', function (): void {
        $schedule = new Schedule([
            new Event([$this->alice, $this->bob], new Round(1)),
            new Event([$this->alice, $this->carol], new Round(1)),
        ]);
        $assigner = new TimelineAssigner([new MinimumRestRule(new DateInterval('PT1H'))]);

        expect(doubleBookingError(fn() => $assigner->assign($schedule, twoPitchTimeline()))->getReason())
            ->toBe(InvalidConfigurationReason::ParticipantDoubleBooked);
    });

    // assignRound() applies the assigner's rules too, and the round is
    // refused before they are asked, as in assign()
    it('is refused for the same reason by assignRound() when a minimum rest rule is configured', function (): void {
        $pairing = new RoundPairing(1, null, [
            new Event([$this->alice, $this->bob], new Round(1)),
            new Event([$this->alice, $this->carol], new Round(1)),
        ]);
        $assigner = new TimelineAssigner([new MinimumRestRule(new DateInterval('PT1H'))]);

        expect(doubleBookingError(fn() => $assigner->assignRound($pairing, twoPitchTimeline()))->getReason())
            ->toBe(InvalidConfigurationReason::ParticipantDoubleBooked);
    });

    // The check is of who is in an event, not of how many are: an event of
    // three is read like any other
    it('is refused in events of more than two participants', function (): void {
        $schedule = new Schedule([
            new Event([$this->alice, $this->bob, $this->carol], new Round(1)),
            new Event([$this->dave, new Participant('e', 'Erin'), $this->alice], new Round(1)),
        ]);

        $exception = doubleBookingError(fn() => new TimelineAssigner()->assign($schedule, twoPitchTimeline()));

        expect($exception->getReason())->toBe(InvalidConfigurationReason::ParticipantDoubleBooked)
            ->and($exception->getContext()['double_bookings'])->toBe([
                ['participant' => 'a', 'kickoff' => '2026-08-01T18:00:00+00:00', 'events' => ['a vs b vs c', 'd vs e vs a']],
            ]);
    });

    it('is reported once for a participant in three events at one instant, with the first two', function (): void {
        $schedule = new Schedule([
            new Event([$this->alice, $this->bob], new Round(1)),
            new Event([$this->alice, $this->carol], new Round(1)),
            new Event([$this->alice, $this->dave], new Round(1)),
        ]);
        $threePitches = new TimelineDefinition(
            new DateTimeImmutable('2026-08-01 18:00:00', new DateTimeZone('UTC')),
            new DateInterval('P7D'),
            resources: ['Pitch 1', 'Pitch 2', 'Pitch 3']
        );

        expect(doubleBookingError(fn() => new TimelineAssigner()->assign($schedule, $threePitches))->getContext()['double_bookings'])
            ->toBe([['participant' => 'a', 'kickoff' => '2026-08-01T18:00:00+00:00', 'events' => ['a vs b', 'a vs c']]]);
    });

    it('is matched by ID, not by object', function (): void {
        $schedule = new Schedule([
            new Event([$this->alice, $this->bob], new Round(1)),
            new Event([new Participant('a', 'Alice, again'), $this->carol], new Round(1)),
        ]);

        expect(doubleBookingError(fn() => new TimelineAssigner()->assign($schedule, twoPitchTimeline()))->getReason())
            ->toBe(InvalidConfigurationReason::ParticipantDoubleBooked);
    });
});

describe('a participant in two events of one round at different times', function (): void {
    // Two slots two hours apart, one pitch's worth of events in each: that
    // is two kickoffs, and whether two hours is enough rest is a rule's to
    // say, not the assigner's
    it('is assigned', function (): void {
        $alice = new Participant('a', 'Alice');
        $schedule = new Schedule([
            new Event([$alice, new Participant('b', 'Bob')], new Round(1)),
            new Event([new Participant('c', 'Carol'), new Participant('d', 'Dave')], new Round(1)),
            new Event([$alice, new Participant('e', 'Erin')], new Round(1)),
        ]);

        $scheduled = new TimelineAssigner()->assign($schedule, twoPitchTimeline(2));

        expect(array_map(
            fn($scheduledEvent): string => $scheduledEvent->getKickoff()->format('H:i'),
            $scheduled->getScheduledEvents()
        ))->toBe(['18:00', '18:00', '20:00']);
    });
});

describe('a participant listed twice in one event', function (): void {
    // One event is one place: the participant is not in two events. An
    // Event refuses such a list when it is built; one built past its
    // constructor (UncheckedEvent) holds one
    it('is not a double booking', function (): void {
        $alice = new Participant('a', 'Alice');
        $schedule = new Schedule([
            UncheckedEvent::of([$alice, $alice], new Round(1)),
            new Event([new Participant('b', 'Bob'), new Participant('c', 'Carol')], new Round(1)),
        ]);

        expect(new TimelineAssigner()->assign($schedule, twoPitchTimeline())->getScheduledEvents())->toHaveCount(2);
    });
});

describe('a schedule in which nobody is in two events of a slot', function (): void {
    it('is assigned as before, on two pitches', function (): void {
        $participants = [];
        foreach (['a', 'b', 'c', 'd'] as $id) {
            $participants[] = new Participant($id, strtoupper($id));
        }
        $schedule = new RoundRobinScheduler()->schedule($participants);

        $scheduled = new TimelineAssigner()->assign($schedule, twoPitchTimeline());

        expect($scheduled->getScheduledEvents())->toHaveCount(6)
            ->and($scheduled->getScheduledEvents()[1]->getResource())->toBe('Pitch 2');
    });
});
