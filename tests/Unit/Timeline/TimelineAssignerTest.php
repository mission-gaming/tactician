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
            ->toThrow(InvalidConfigurationException::class, 'more events than the timeline has slots');
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
    it('round-trips through JSON with kickoffs and rounds intact', function (): void {
        $schedule = (new RoundRobinScheduler())->schedule(timelineField(4));
        $timeline = new TimelineDefinition(
            new DateTimeImmutable('2026-08-01 18:00', new DateTimeZone('Europe/London')),
            new DateInterval('P7D'),
            slotsPerRound: 2,
            slotInterval: new DateInterval('PT1H')
        );

        $scheduled = (new TimelineAssigner())->assign($schedule, $timeline);
        $rebuilt = ScheduledSchedule::fromJson($scheduled->toJson());

        expect($rebuilt)->toHaveCount(6);
        expect($rebuilt->toJson())->toBe($scheduled->toJson());

        $original = $scheduled->getScheduledEvents()[0];
        $restored = $rebuilt->getScheduledEvents()[0];
        expect($restored->getKickoff()->format(DATE_ATOM))->toBe($original->getKickoff()->format(DATE_ATOM));
        expect($restored->getEvent()->getRound()?->getNumber())
            ->toBe($original->getEvent()->getRound()?->getNumber());
        expect($restored->getEvent()->getParticipants()[0]->getId())
            ->toBe($original->getEvent()->getParticipants()[0]->getId());
    });

    it('rejects malformed serialized data', function (): void {
        expect(fn () => ScheduledSchedule::fromArray(['participants' => 'nope']))
            ->toThrow(InvalidArgumentException::class);
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
    });
});
