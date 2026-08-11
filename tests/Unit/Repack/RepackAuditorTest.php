<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Repack\Internal\RepackAuditor;
use MissionGaming\Tactician\Repack\ParticipantDoubleBooked;
use MissionGaming\Tactician\Repack\PinnedEvent;
use MissionGaming\Tactician\Repack\ViolationKind;

/**
 * The packer cannot produce a double-booking, so no end-to-end path
 * reaches the ParticipantDoubleBooked branch — the audit is exercised
 * directly with hand-built collisions to prove the detector itself
 * works (the audit being the proof is the point of running it at all).
 */
describe('RepackAuditor', function (): void {
    it('detects a participant double-booked across two movable events at one position', function (): void {
        $participants = [
            new Participant('p1', 'P1'),
            new Participant('p2', 'P2'),
            new Participant('p3', 'P3'),
        ];

        $violations = (new RepackAuditor())->audit(
            [0 => [0, 0], 1 => [0, 0]],
            [0 => [0, 1], 1 => [0, 2]],
            [],
            ['e1', 'e2'],
            $participants,
            ['p1' => 0, 'p2' => 1, 'p3' => 2]
        );

        $doubleBookings = array_values(array_filter(
            $violations,
            static fn ($violation): bool => $violation->getKind() === ViolationKind::ParticipantDoubleBooked
        ));
        expect($doubleBookings)->toHaveCount(1);
        $violation = $doubleBookings[0];
        assert($violation instanceof ParticipantDoubleBooked);
        expect($violation->getParticipant()->getId())->toBe('p1');
        expect($violation->getSession())->toBe(0);
        expect($violation->getSlot())->toBe(0);
        expect($violation->getEventIds())->toBe(['e1', 'e2']);
    });

    it('detects a movable assignment colliding with a pin', function (): void {
        $participants = [
            new Participant('p1', 'P1'),
            new Participant('p2', 'P2'),
            new Participant('p3', 'P3'),
        ];

        $violations = (new RepackAuditor())->audit(
            [0 => [1, 0]],
            [0 => [0, 1]],
            [new PinnedEvent('x1', $participants[0], $participants[2], 1, 0)],
            ['e1'],
            $participants,
            ['p1' => 0, 'p2' => 1, 'p3' => 2]
        );

        $doubleBookings = array_values(array_filter(
            $violations,
            static fn ($violation): bool => $violation->getKind() === ViolationKind::ParticipantDoubleBooked
        ));
        expect($doubleBookings)->toHaveCount(1);
        $violation = $doubleBookings[0];
        assert($violation instanceof ParticipantDoubleBooked);
        expect($violation->getParticipant()->getId())->toBe('p1');
        expect($violation->getEventIds())->toBe(['e1', 'x1']);
    });

    it('reports contiguity and late starts only where a movable assignment exists', function (): void {
        $participants = [
            new Participant('p1', 'P1'),
            new Participant('p2', 'P2'),
            new Participant('p3', 'P3'),
            new Participant('p4', 'P4'),
        ];

        // p1/p2 movable at slots 1 and 3 of session 0: late start 1,
        // gap at slot 2. p3/p4 purely pinned deep in session 1:
        // historical fact, not a repack compromise — no violations
        $violations = (new RepackAuditor())->audit(
            [0 => [0, 1], 1 => [0, 3]],
            [0 => [0, 1], 1 => [0, 1]],
            [new PinnedEvent('x1', $participants[2], $participants[3], 1, 2)],
            ['e1', 'e2'],
            $participants,
            ['p1' => 0, 'p2' => 1, 'p3' => 2, 'p4' => 3]
        );

        $byKind = [];
        foreach ($violations as $violation) {
            $byKind[$violation->getKind()->value][] = $violation;
        }

        expect($byKind[ViolationKind::ContiguityBroken->value] ?? [])->toHaveCount(2);
        expect($byKind[ViolationKind::LateStart->value] ?? [])->toHaveCount(2);
        expect($byKind[ViolationKind::ParticipantDoubleBooked->value] ?? [])->toBe([]);
        foreach ($violations as $violation) {
            assert(method_exists($violation, 'getParticipant'));
            expect(in_array($violation->getParticipant()->getId(), ['p1', 'p2'], true))->toBeTrue();
        }
    });
});
