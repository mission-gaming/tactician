<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Scheduling\SwissPairingEngine;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Timeline\ScheduledEvent;
use MissionGaming\Tactician\Timeline\ScheduledSchedule;
use MissionGaming\Tactician\Timeline\TimelineAssigner;
use MissionGaming\Tactician\Timeline\TimelineDefinition;

/**
 * @return array<Participant>
 */
function timelineField(int $count): array
{
    $participants = [];
    for ($i = 1; $i <= $count; ++$i) {
        $participants[] = new Participant("t{$i}", "Team {$i}", $i);
    }

    return $participants;
}

describe('TimelineAssigner', function (): void {
    it('staggers each round across its slots in schedule order', function (): void {
        // A 4-team round has 2 events; every slot holds one event in this
        // cut, so the timeline declares 2 slots per round
        $schedule = (new RoundRobinScheduler())->schedule(timelineField(4));
        $timeline = new TimelineDefinition(
            new DateTimeImmutable('2026-08-01 19:00', new DateTimeZone('UTC')),
            new DateInterval('P7D'),
            slotsPerRound: 2,
            slotInterval: new DateInterval('PT2H')
        );

        $scheduled = (new TimelineAssigner())->assign($schedule, $timeline);

        expect($scheduled)->toHaveCount(6);

        $byRound = $scheduled->getEventsByRound();
        expect(array_keys($byRound))->toBe([1, 2, 3]);

        // Round 2 kickoffs: a week after round 1, staggered 2h apart in
        // schedule order
        expect($byRound[2][0]->getKickoff()->format('Y-m-d H:i'))->toBe('2026-08-08 19:00');
        expect($byRound[2][1]->getKickoff()->format('Y-m-d H:i'))->toBe('2026-08-08 21:00');
    });

    it('assigns a truly round-aligned timeline for single-event rounds', function (): void {
        $schedule = (new RoundRobinScheduler())->schedule(timelineField(2));
        $timeline = new TimelineDefinition(
            new DateTimeImmutable('2026-08-01 19:00', new DateTimeZone('UTC')),
            new DateInterval('P7D')
        );

        $scheduled = (new TimelineAssigner())->assign($schedule, $timeline);

        expect($scheduled)->toHaveCount(1);
        expect($scheduled->getScheduledEvents()[0]->getKickoff()->format('Y-m-d H:i'))
            ->toBe('2026-08-01 19:00');
    });

    it('is deterministic: same schedule and timeline, same kickoffs', function (): void {
        $schedule = (new RoundRobinScheduler())->schedule(timelineField(6));
        $timeline = new TimelineDefinition(
            new DateTimeImmutable('2026-08-01 18:00', new DateTimeZone('UTC')),
            new DateInterval('P7D'),
            slotsPerRound: 3,
            slotInterval: new DateInterval('PT1H')
        );

        $first = (new TimelineAssigner())->assign($schedule, $timeline);
        $second = (new TimelineAssigner())->assign($schedule, $timeline);

        $kickoffs = fn (ScheduledSchedule $s) => array_map(
            fn (ScheduledEvent $e) => $e->getKickoff()->format(DATE_ATOM),
            $s->getScheduledEvents()
        );

        expect($kickoffs($first))->toBe($kickoffs($second));
    });

    it('rejects rounds with more events than slots', function (): void {
        $schedule = (new RoundRobinScheduler())->schedule(timelineField(4)); // 2 events per round
        $timeline = new TimelineDefinition(
            new DateTimeImmutable('2026-08-01 19:00', new DateTimeZone('UTC')),
            new DateInterval('P7D')
        );

        expect(fn () => (new TimelineAssigner())->assign($schedule, $timeline))
            ->toThrow(InvalidConfigurationException::class, 'more events than the timeline can hold');
    });

    // getEventsByRound() silently excludes round-less events; the assigner
    // must refuse rather than silently drop fixtures
    it('rejects schedules carrying round-less events', function (): void {
        [$a, $b] = timelineField(2);
        $schedule = new Schedule([new Event([$a, $b])]); // no round
        $timeline = new TimelineDefinition(
            new DateTimeImmutable('2026-08-01 19:00', new DateTimeZone('UTC')),
            new DateInterval('P7D')
        );

        expect(fn () => (new TimelineAssigner())->assign($schedule, $timeline))
            ->toThrow(InvalidConfigurationException::class, 'round number');
    });

    it('assigns kickoffs to a results-driven round pairing', function (): void {
        $engine = new SwissPairingEngine(plannedRounds: 3);
        $state = StageState::start(timelineField(4));
        $pairing = $engine->pairNextRound($state);

        $timeline = new TimelineDefinition(
            new DateTimeImmutable('2026-08-01 18:00', new DateTimeZone('UTC')),
            new DateInterval('P7D'),
            slotsPerRound: 2,
            slotInterval: new DateInterval('PT1H')
        );

        $scheduledEvents = (new TimelineAssigner())->assignRound($pairing, $timeline);

        expect($scheduledEvents)->toHaveCount(2);
        expect($scheduledEvents[0]->getKickoff()->format('H:i'))->toBe('18:00');
        expect($scheduledEvents[1]->getKickoff()->format('H:i'))->toBe('19:00');
        // Decoration, not mutation: the wrapped events are the pairing's own
        expect($scheduledEvents[0]->getEvent())->toBe($pairing->getEvents()[0]);
    });

    it('leaves gaps for round numbers, not schedule positions', function (): void {
        [$a, $b] = timelineField(2);
        // Only rounds 1 and 3 exist; round 3 still lands two intervals out
        $schedule = new Schedule([
            new Event([$a, $b], new Round(1)),
            new Event([$b, $a], new Round(3)),
        ]);
        $timeline = new TimelineDefinition(
            new DateTimeImmutable('2026-08-01 19:00', new DateTimeZone('UTC')),
            new DateInterval('P7D')
        );

        $scheduled = (new TimelineAssigner())->assign($schedule, $timeline);

        expect($scheduled->getScheduledEvents()[1]->getKickoff()->format('Y-m-d'))->toBe('2026-08-15');
    });
});

describe('ScheduledSchedule', function (): void {
    it('round-trips through JSON with kickoffs, rounds, and resources intact', function (): void {
        $schedule = (new RoundRobinScheduler())->schedule(timelineField(4));
        $timeline = new TimelineDefinition(
            new DateTimeImmutable('2026-08-01 18:00', new DateTimeZone('Europe/London')),
            new DateInterval('P7D'),
            slotsPerRound: 2,
            slotInterval: new DateInterval('PT1H'),
            resources: ['Centre Court']
        );

        $scheduled = (new TimelineAssigner())->assign($schedule, $timeline);
        $rebuilt = ScheduledSchedule::fromJson($scheduled->toJson());

        expect($rebuilt)->toHaveCount(6);
        expect($rebuilt->toJson())->toBe($scheduled->toJson());

        $original = $scheduled->getScheduledEvents()[0];
        $restored = $rebuilt->getScheduledEvents()[0];
        expect($restored->getResource())->toBe('Centre Court');
        expect($restored->getKickoff()->format(DATE_ATOM))->toBe($original->getKickoff()->format(DATE_ATOM));
        expect($restored->getEvent()->getRound()?->getNumber())
            ->toBe($original->getEvent()->getRound()?->getNumber());
        expect($restored->getEvent()->getParticipants()[0]->getId())
            ->toBe($original->getEvent()->getParticipants()[0]->getId());
    });

    it('iterates its scheduled events', function (): void {
        $schedule = (new RoundRobinScheduler())->schedule(timelineField(2));
        $timeline = new TimelineDefinition(
            new DateTimeImmutable('2026-08-01 19:00', new DateTimeZone('UTC')),
            new DateInterval('P7D')
        );

        $scheduled = (new TimelineAssigner())->assign($schedule, $timeline);

        $seen = 0;
        foreach ($scheduled as $scheduledEvent) {
            expect($scheduledEvent)->toBeInstanceOf(ScheduledEvent::class);
            ++$seen;
        }
        expect($seen)->toBe(1);
    });

    it('rejects malformed serialized data', function (): void {
        expect(fn () => ScheduledSchedule::fromArray(['participants' => 'nope']))
            ->toThrow(InvalidArgumentException::class);
        expect(fn () => ScheduledSchedule::fromArray(['participants' => ['nope'], 'events' => []]))
            ->toThrow(InvalidArgumentException::class, 'must be an array');
        expect(fn () => ScheduledSchedule::fromArray(['participants' => [], 'events' => 'nope']))
            ->toThrow(InvalidArgumentException::class);
        expect(fn () => ScheduledSchedule::fromArray(['participants' => [], 'events' => ['nope']]))
            ->toThrow(InvalidArgumentException::class);
        expect(fn () => ScheduledSchedule::fromJson('"nope"'))
            ->toThrow(InvalidArgumentException::class);

        $participant = ['id' => 'p1', 'label' => 'Alice', 'seed' => null, 'metadata' => []];
        expect(fn () => ScheduledSchedule::fromArray([
            'participants' => [$participant],
            'events' => [['kickoff' => '2026-08-01T19:00:00Z']], // no event
        ]))->toThrow(InvalidArgumentException::class, 'event');
        expect(fn () => ScheduledSchedule::fromArray([
            'participants' => [$participant, ['id' => 'p2', 'label' => 'Bob', 'seed' => null, 'metadata' => []]],
            'events' => [[
                'event' => ['participants' => ['p1', 'p2'], 'round' => ['number' => 1, 'metadata' => []], 'metadata' => []],
                'kickoff' => 42,
            ]],
        ]))->toThrow(InvalidArgumentException::class, 'kickoff');
        expect(fn () => ScheduledSchedule::fromArray([
            'participants' => [$participant, ['id' => 'p2', 'label' => 'Bob', 'seed' => null, 'metadata' => []]],
            'events' => [[
                'event' => ['participants' => ['p1', 'p2'], 'round' => ['number' => 1, 'metadata' => []], 'metadata' => []],
                'kickoff' => 'half past never',
            ]],
        ]))->toThrow(InvalidArgumentException::class, 'not parseable');
        expect(fn () => ScheduledSchedule::fromArray([
            'participants' => [$participant, ['id' => 'p2', 'label' => 'Bob', 'seed' => null, 'metadata' => []]],
            'events' => [[
                'event' => ['participants' => ['p1', 'p2'], 'round' => ['number' => 1, 'metadata' => []], 'metadata' => []],
                'kickoff' => '2026-08-01T19:00:00Z',
                'resource' => 42,
            ]],
        ]))->toThrow(InvalidArgumentException::class, 'resource');
        // Deserialized data upholds the same invariant the definition
        // enforces: resource names are never empty
        expect(fn () => ScheduledSchedule::fromArray([
            'participants' => [$participant, ['id' => 'p2', 'label' => 'Bob', 'seed' => null, 'metadata' => []]],
            'events' => [[
                'event' => ['participants' => ['p1', 'p2'], 'round' => ['number' => 1, 'metadata' => []], 'metadata' => []],
                'kickoff' => '2026-08-01T19:00:00Z',
                'resource' => '',
            ]],
        ]))->toThrow(InvalidArgumentException::class, 'non-empty');
    });

    // The UTC invariant is enforced, not assumed: a zoned kickoff is
    // normalized on construction, so serialization's literal 'Z' suffix
    // is always truthful
    it('normalizes constructor kickoffs to UTC', function (): void {
        [$a, $b] = timelineField(2);
        $zoned = new DateTimeImmutable('2026-08-01 19:00', new DateTimeZone('Europe/London')); // BST

        $scheduledEvent = new ScheduledEvent(new Event([$a, $b], new Round(1)), $zoned);

        expect($scheduledEvent->getKickoff()->getTimezone()->getName())->toBe('UTC');
        expect($scheduledEvent->getKickoff()->format('H:i'))->toBe('18:00');
        expect($scheduledEvent->toArray()['kickoff'])->toBe('2026-08-01T18:00:00Z');
    });

    it('hosts concurrent events per slot across named resources', function (): void {
        // A 4-team round has 2 events; one slot with two pitches hosts
        // them concurrently
        $schedule = (new RoundRobinScheduler())->schedule(timelineField(4));
        $timeline = new TimelineDefinition(
            new DateTimeImmutable('2026-08-01 19:00', new DateTimeZone('UTC')),
            new DateInterval('P7D'),
            resources: ['Pitch 1', 'Pitch 2']
        );

        $scheduled = (new TimelineAssigner())->assign($schedule, $timeline);

        $byRound = $scheduled->getEventsByRound();
        foreach ($byRound as $scheduledEvents) {
            // Same kickoff, distinct resources, in declared order
            expect($scheduledEvents[0]->getKickoff())->toEqual($scheduledEvents[1]->getKickoff());
            expect($scheduledEvents[0]->getResource())->toBe('Pitch 1');
            expect($scheduledEvents[1]->getResource())->toBe('Pitch 2');
        }
    });

    it('fills slot by slot, resource by resource', function (): void {
        // A 6-team round has 3 events; 2 slots x 2 pitches hold them:
        // slot 0 fills both pitches, slot 1 takes the remainder
        $schedule = (new RoundRobinScheduler())->schedule(timelineField(6));
        $timeline = new TimelineDefinition(
            new DateTimeImmutable('2026-08-01 18:00', new DateTimeZone('UTC')),
            new DateInterval('P7D'),
            slotsPerRound: 2,
            slotInterval: new DateInterval('PT2H'),
            resources: ['Pitch 1', 'Pitch 2']
        );

        $scheduled = (new TimelineAssigner())->assign($schedule, $timeline);
        $round1 = $scheduled->getEventsByRound()[1];

        expect(array_map(fn (ScheduledEvent $e) => [$e->getKickoff()->format('H:i'), $e->getResource()], $round1))
            ->toBe([
                ['18:00', 'Pitch 1'],
                ['18:00', 'Pitch 2'],
                ['20:00', 'Pitch 1'],
            ]);
    });

    it('counts resources toward the round capacity', function (): void {
        $schedule = (new RoundRobinScheduler())->schedule(timelineField(6)); // 3 events per round
        $timeline = new TimelineDefinition(
            new DateTimeImmutable('2026-08-01 19:00', new DateTimeZone('UTC')),
            new DateInterval('P7D'),
            resources: ['Pitch 1', 'Pitch 2']
        ); // 1 slot x 2 resources = capacity 2

        expect(fn () => (new TimelineAssigner())->assign($schedule, $timeline))
            ->toThrow(InvalidConfigurationException::class, 'more events than the timeline can hold');
    });
});
