<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\RepackViolationsException;
use MissionGaming\Tactician\Repack\CapacityExceeded;
use MissionGaming\Tactician\Repack\ContiguityBroken;
use MissionGaming\Tactician\Repack\EventUnplaced;
use MissionGaming\Tactician\Repack\LateStart;
use MissionGaming\Tactician\Repack\ParticipantDoubleBooked;
use MissionGaming\Tactician\Repack\RepackOutcome;
use MissionGaming\Tactician\Repack\UnplacedEvent;
use MissionGaming\Tactician\Repack\UnplacedReason;
use MissionGaming\Tactician\Repack\ViolationKind;

describe('Repack violation DTOs', function (): void {
    it('serializes ParticipantDoubleBooked with its colliding event ids', function (): void {
        $violation = new ParticipantDoubleBooked(new Participant('p1', 'P1'), 2, 3, ['e1', 'e2']);

        expect($violation->getKind())->toBe(ViolationKind::ParticipantDoubleBooked);
        expect($violation->getParticipant()->getId())->toBe('p1');
        expect($violation->getSession())->toBe(2);
        expect($violation->getSlot())->toBe(3);
        expect($violation->getEventIds())->toBe(['e1', 'e2']);
        expect($violation->toArray())->toBe([
            'kind' => 'participant_double_booked',
            'participant' => 'p1',
            'session' => 2,
            'slot' => 3,
            'event_ids' => ['e1', 'e2'],
        ]);
    });

    it('serializes ContiguityBroken with the gap size and occupied slots', function (): void {
        $violation = new ContiguityBroken(new Participant('p1', 'P1'), 1, 2, [0, 3]);

        expect($violation->getKind())->toBe(ViolationKind::ContiguityBroken);
        expect($violation->getGapSlots())->toBe(2);
        expect($violation->getOccupiedSlots())->toBe([0, 3]);
        expect($violation->toArray())->toBe([
            'kind' => 'contiguity_broken',
            'participant' => 'p1',
            'session' => 1,
            'gap_slots' => 2,
            'occupied_slots' => [0, 3],
        ]);
    });

    it('serializes LateStart with the first occupied slot', function (): void {
        $violation = new LateStart(new Participant('p1', 'P1'), 0, 2);

        expect($violation->getKind())->toBe(ViolationKind::LateStart);
        expect($violation->getFirstSlot())->toBe(2);
        expect($violation->toArray())->toBe([
            'kind' => 'late_start',
            'participant' => 'p1',
            'session' => 0,
            'first_slot' => 2,
        ]);
    });

    it('serializes CapacityExceeded in both scopes and clamps the shortfall at zero', function (): void {
        $scoped = new CapacityExceeded(new Participant('p1', 'P1'), 15, 12);
        expect($scoped->getShortfall())->toBe(3);
        expect($scoped->toArray())->toBe([
            'kind' => 'capacity_exceeded',
            'participant' => 'p1',
            'demand' => 15,
            'capacity' => 12,
            'shortfall' => 3,
        ]);

        $global = new CapacityExceeded(null, 5, 8);
        expect($global->getParticipant())->toBeNull();
        expect($global->getShortfall())->toBe(0);
        expect($global->toArray()['participant'])->toBeNull();
    });

    it('serializes EventUnplaced mirroring its UnplacedEvent', function (): void {
        $unplaced = new UnplacedEvent('e9', UnplacedReason::ParticipantOverCapacity, new Participant('p1', 'P1'));
        $violation = new EventUnplaced($unplaced->getEventId(), $unplaced->getReason(), $unplaced->getParticipant());

        expect($violation->getKind())->toBe(ViolationKind::EventUnplaced);
        expect($violation->toArray())->toBe([
            'kind' => 'event_unplaced',
            'event_id' => 'e9',
            'reason' => 'participant_over_capacity',
            'participant' => 'p1',
        ]);
        expect($unplaced->toArray())->toBe([
            'event_id' => 'e9',
            'reason' => 'participant_over_capacity',
            'participant' => 'p1',
        ]);
    });
});

describe('RepackViolationsException', function (): void {
    it('carries the outcome and reports violation counts by kind', function (): void {
        $outcome = new RepackOutcome(
            [],
            [new UnplacedEvent('e1', UnplacedReason::NoSlotAvailable)],
            [
                new EventUnplaced('e1', UnplacedReason::NoSlotAvailable),
                new LateStart(new Participant('p1', 'P1'), 0, 1),
                new LateStart(new Participant('p2', 'P2'), 0, 1),
            ]
        );

        $exception = new RepackViolationsException($outcome);

        expect($exception->getOutcome())->toBe($outcome);
        expect($exception->getMessage())->toBe(
            'Repacking finished with 3 violation(s) and 1 unplaced event(s)'
        );

        $report = $exception->getDiagnosticReport();
        expect($report)->toContain('event_unplaced: 1');
        expect($report)->toContain('late_start: 2');
        expect($report)->toContain('Unplaced events: 1');
        expect($report)->toContain('Assigned events: 0');
    });
});
