<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Repack\MovableEvent;
use MissionGaming\Tactician\Repack\RepackOutcome;
use MissionGaming\Tactician\Repack\RepackRequest;
use MissionGaming\Tactician\Repack\ScheduleRepacker;
use MissionGaming\Tactician\Repack\SessionGrid;
use MissionGaming\Tactician\Repack\SlotAssignment;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Timeline\ScheduledEvent;
use MissionGaming\Tactician\Timeline\ScheduledSchedule;
use MissionGaming\Tactician\Timeline\TimelineAssigner;
use MissionGaming\Tactician\Timeline\TimelineDefinition;

// A timeline or a grid whose start has a fraction of a second writes it and
// reads it back (#136). The kickoffs assigned from it are written with that
// fraction too, so a kickoff read back is the slot time the definition
// gives (#148, ADR 0024). A kickoff on a whole second is written as it
// always was.

/**
 * The fractions a start can carry, as PHP holds them (whole microseconds),
 * with the six digits each is written with.
 *
 * @return array<string, array{string, string}>
 */
function kickoffFractions(): array
{
    return [
        'one microsecond' => ['.000001', '.000001'],
        'a quarter of a second' => ['.25', '.250000'],
        'half a second' => ['.5', '.500000'],
        'six digits' => ['.123456', '.123456'],
        'the last microsecond of a second' => ['.999999', '.999999'],
    ];
}

describe('A kickoff with a fraction of a second', function (): void {
    it('is written with it by a scheduled event, which reads it back', function (string $fraction, string $written): void {
        $event = new Event([new Participant('a', 'A'), new Participant('b', 'B')], new Round(1));
        $participants = ['a' => new Participant('a', 'A'), 'b' => new Participant('b', 'B')];
        // Given in London, so the conversion to UTC is part of what is written
        $kickoff = new DateTimeImmutable("2026-08-01 18:00:00{$fraction}", new DateTimeZone('Europe/London'));

        $data = new ScheduledEvent($event, $kickoff)->toArray();
        $readBack = ScheduledEvent::fromArray($data, $participants)->getKickoff();

        expect($data['kickoff'])->toBe("2026-08-01T17:00:00{$written}Z")
            ->and($readBack->format('U.u'))->toBe($kickoff->format('U.u'));
    })->with(kickoffFractions());

    it('is written with it by a slot assignment', function (string $fraction, string $written): void {
        $kickoff = new DateTimeImmutable("2026-08-12 20:15:00{$fraction}", new DateTimeZone('Europe/London'));

        expect(new SlotAssignment('e1', 0, 0, $kickoff)->toArray()['kickoff'])->toBe("2026-08-12T19:15:00{$written}Z");
    })->with(kickoffFractions());

    it('is written to the second when it is on a whole second', function (): void {
        $event = new Event([new Participant('a', 'A'), new Participant('b', 'B')], new Round(1));
        $kickoff = new DateTimeImmutable('2026-08-01 18:00:00', new DateTimeZone('Europe/London'));

        expect(new ScheduledEvent($event, $kickoff)->toArray()['kickoff'])->toBe('2026-08-01T17:00:00Z')
            ->and(new SlotAssignment('e1', 0, 0, $kickoff)->toArray()['kickoff'])->toBe('2026-08-01T17:00:00Z');
    });
});

describe('Kickoffs assigned from a start with a fraction of a second', function (): void {
    // The property: for every start fraction, timezone and slot pattern,
    // every kickoff of an assigned schedule, written and read back, is the
    // slot time the definition gives for its round and slot, to the
    // microsecond.
    it('read back as the slot times of the definition', function (string $fraction): void {
        $participants = [];
        foreach (range(1, 6) as $n) {
            $participants[] = new Participant("p{$n}", "P{$n}");
        }
        $schedule = new RoundRobinScheduler()->schedule($participants);

        foreach (['UTC', 'Europe/London', 'America/New_York', '+05:30'] as $zone) {
            foreach ([['P7D', 'PT1H'], ['P1M', 'PT25M'], ['PT36H', 'PT7M']] as [$roundInterval, $slotInterval]) {
                $timeline = new TimelineDefinition(
                    new DateTimeImmutable("2026-03-01 18:59:59{$fraction}", new DateTimeZone($zone)),
                    new DateInterval($roundInterval),
                    3,
                    new DateInterval($slotInterval)
                );

                $scheduled = new TimelineAssigner()->assign($schedule, $timeline);
                $readBack = ScheduledSchedule::fromArray($scheduled->toArray())->getScheduledEvents();

                $slotsSeen = [];
                foreach ($scheduled->getScheduledEvents() as $index => $scheduledEvent) {
                    $round = $scheduledEvent->getEvent()->getRound()?->getNumber() ?? 0;
                    $slot = $slotsSeen[$round] ?? 0;
                    $slotsSeen[$round] = $slot + 1;

                    expect($readBack[$index]->getKickoff()->format('Y-m-d H:i:s.u'))
                        ->toBe($timeline->getSlotTime($round, $slot)->format('Y-m-d H:i:s.u'), "{$zone}, {$roundInterval}/{$slotInterval}, round {$round}, slot {$slot}");
                }
            }
        }
    })->with(array_map(static fn(array $case): array => [$case[0]], kickoffFractions()));
});

describe('A repack on a grid whose session starts with a fraction of a second', function (): void {
    $repackOn = static function (string $sessionStart): RepackOutcome {
        $grid = new SessionGrid(
            [new DateTimeImmutable($sessionStart, new DateTimeZone('Europe/London'))],
            new DateInterval('PT25M'),
            2
        );
        $a = new Participant('a', 'A');
        $b = new Participant('b', 'B');
        $c = new Participant('c', 'C');

        return new ScheduleRepacker()->repack(new RepackRequest([new MovableEvent('e1', $a, $b), new MovableEvent('e2', $b, $c)], [], $grid));
    };

    it('writes each kickoff as the slot time of the grid', function () use ($repackOn): void {
        $outcome = $repackOn('2026-08-12 20:15:00.25');

        $kickoffs = array_column($outcome->toArray()['assignments'], 'kickoff');
        sort($kickoffs);

        expect($kickoffs)->toBe(['2026-08-12T19:15:00.250000Z', '2026-08-12T19:40:00.250000Z']);
    });

    it('has another fingerprint than the same repack on a whole second', function () use ($repackOn): void {
        expect($repackOn('2026-08-12 20:15:00.25')->fingerprint())->not->toBe($repackOn('2026-08-12 20:15:00')->fingerprint())
            ->and($repackOn('2026-08-12 20:15:00.25')->fingerprint())->not->toBe($repackOn('2026-08-12 20:15:00.5')->fingerprint());
    });

    // Scheme v1 holds what toArray() of the record holds under each key,
    // so the fraction is in the kickoff's string and nothing else of the
    // document changes. Written out from the docblock of fingerprint().
    it('is the documented digest, the fraction in the kickoff string', function (): void {
        $outcome = new RepackOutcome(
            [new SlotAssignment('e1', 0, 2, new DateTimeImmutable('2026-08-12 19:00:00.25', new DateTimeZone('UTC')))],
            [],
            []
        );
        $document = "tactician.repack.outcome.v1\n"
            . 'l1:m4:s4:slot;i2;s7:kickoff;s27:2026-08-12T19:00:00.250000Z;s7:session;i0;s8:event_id;s2:e1;;;'
            . 'l0:;l0:;';

        expect($outcome->fingerprint())->toBe('v1:' . hash('sha256', $document));
    });
});
