<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidInputException;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Scheduling\SwissOptions;
use MissionGaming\Tactician\Scheduling\SwissScheduler;
use MissionGaming\Tactician\Timeline\ScheduledEvent;
use MissionGaming\Tactician\Timeline\ScheduledSchedule;
use MissionGaming\Tactician\Timeline\TimelineAssigner;
use MissionGaming\Tactician\Timeline\TimelineDefinition;

/**
 * @return list<Participant>
 */
function scheduledByesField(int $size): array
{
    $field = [];
    for ($i = 1; $i <= $size; ++$i) {
        $field[] = new Participant("p{$i}", "Participant {$i}");
    }

    return $field;
}

/**
 * @throws InvalidConfigurationException
 */
function scheduledByesTimeline(): TimelineDefinition
{
    return TimelineDefinition::fromArray([
        'start' => '2026-08-01 18:00:00',
        'timezone' => 'UTC',
        'round_interval' => 'P7D',
        'slots_per_round' => 4,
        'slot_interval' => 'PT2H',
    ]);
}

/**
 * @param array<int, list<Participant>> $byes
 * @return array<int, list<string>>
 */
function scheduledByeIds(array $byes): array
{
    return array_map(
        static fn(array $participants): array => array_map(static fn(Participant $p): string => $p->getId(), $participants),
        $byes
    );
}

describe('The byes of a scheduled schedule', function (): void {
    it('are the byes of the schedule assigned, round by round', function (Closure $generate): void {
        $schedule = $generate();
        $scheduled = (new TimelineAssigner())->assign($schedule, scheduledByesTimeline());

        expect(scheduledByeIds($scheduled->getByesByRound()))->toBe(scheduledByeIds($schedule->getByesByRound()))
            ->and($scheduled->getByeCounts())->toBe($schedule->getByeCounts())
            ->and($scheduled->getEntrants())->toBe($schedule->getEntrants());
    })->with([
        'round robin of 5' => [fn() => (new RoundRobinScheduler())->schedule(scheduledByesField(5))],
        'round robin of 6, no byes' => [fn() => (new RoundRobinScheduler())->schedule(scheduledByesField(6))],
        'whole-schedule Swiss of 7' => [fn() => (new SwissScheduler())->schedule(scheduledByesField(7), new SwissOptions(rounds: 4, seed: 3))],
    ]);

    it('has one bye in every round of an odd field, and every entrant sits out once in a round robin', function (): void {
        $schedule = (new RoundRobinScheduler())->schedule(scheduledByesField(5));
        $scheduled = (new TimelineAssigner())->assign($schedule, scheduledByesTimeline());

        foreach ($scheduled->getByesByRound() as $byes) {
            expect($byes)->toHaveCount(1);
        }
        $counts = $scheduled->getByeCounts();
        ksort($counts);
        expect($counts)->toBe(['p1' => 1, 'p2' => 1, 'p3' => 1, 'p4' => 1, 'p5' => 1]);
    });

    it('are read from the participants of the events when no entrant list was given', function (): void {
        [$a, $b, $c] = scheduledByesField(3);
        $kickoff = new DateTimeImmutable('2026-08-01T18:00:00Z');
        $scheduled = new ScheduledSchedule([
            new ScheduledEvent(new Event([$a, $b], new Round(1)), $kickoff),
            new ScheduledEvent(new Event([$b, $c], new Round(2)), $kickoff->modify('+7 days')),
        ]);

        expect(array_map(fn(Participant $p): string => $p->getId(), $scheduled->getEntrants()))->toBe(['p1', 'p2', 'p3'])
            ->and(scheduledByeIds($scheduled->getByesByRound()))->toBe([1 => ['p3'], 2 => ['p1']])
            ->and($scheduled->getByeCounts())->toBe(['p3' => 1, 'p1' => 1]);
    });

    it('keeps the entrant list through plain data, an entrant without an event included', function (): void {
        [$a, $b, $c] = scheduledByesField(3);
        $scheduled = new ScheduledSchedule(
            [new ScheduledEvent(new Event([$b, $c], new Round(1)), new DateTimeImmutable('2026-08-01T18:00:00Z'))],
            [$a, $b, $c]
        );

        $data = $scheduled->toArray();
        $read = ScheduledSchedule::fromJson($scheduled->toJson());

        expect($data['entrants'] ?? null)->toBe(['p1', 'p2', 'p3'])
            ->and(array_map(fn(array $p): string => $p['id'], $data['participants']))->toBe(['p2', 'p3', 'p1'])
            ->and(scheduledByeIds($read->getByesByRound()))->toBe([1 => ['p1']])
            ->and(array_map(fn(Participant $p): string => $p->getId(), $read->getEntrants()))->toBe(['p1', 'p2', 'p3'])
            ->and($read->toArray())->toBe($data);
    });

    it('writes no entrant list for a schedule built from its events alone', function (): void {
        [$a, $b] = scheduledByesField(2);
        $scheduled = new ScheduledSchedule([new ScheduledEvent(new Event([$a, $b], new Round(1)), new DateTimeImmutable('2026-08-01T18:00:00Z'))]);

        expect(array_keys($scheduled->toArray()))->toBe(['format_version', 'participants', 'events']);
    });

    it('refuses an entrant list that names a participant the data does not list', function (mixed $entrants): void {
        [$a, $b] = scheduledByesField(2);
        $data = (new ScheduledSchedule([new ScheduledEvent(new Event([$a, $b], new Round(1)), new DateTimeImmutable('2026-08-01T18:00:00Z'))]))->toArray();
        $data['entrants'] = $entrants;

        expect(fn() => ScheduledSchedule::fromArray($data))->toThrow(InvalidInputException::class);
    })->with([
        'unknown ID' => [['p1', 'p9']],
        'not a list' => ['p1'],
        'not a string' => [[1]],
        'keyed' => [['x' => 'p1']],
    ]);
});
