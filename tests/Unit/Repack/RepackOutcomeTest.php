<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Exceptions\InvalidInputException;
use MissionGaming\Tactician\Repack\CapacityExceeded;
use MissionGaming\Tactician\Repack\ContiguityBroken;
use MissionGaming\Tactician\Repack\EventUnplaced;
use MissionGaming\Tactician\Repack\LateStart;
use MissionGaming\Tactician\Repack\ParticipantDoubleBooked;
use MissionGaming\Tactician\Repack\RepackOutcome;
use MissionGaming\Tactician\Repack\RepackViolation;
use MissionGaming\Tactician\Repack\SlotAssignment;
use MissionGaming\Tactician\Repack\UnplacedEvent;
use MissionGaming\Tactician\Repack\UnplacedReason;
use MissionGaming\Tactician\Repack\ViolationKind;
use MissionGaming\Tactician\Tests\Support\DecimalCommaLocale;

/**
 * An outcome holding one violation of every kind, two of the capacity
 * kind, an unplaced event and an assignment with no kickoff.
 */
function outcomeOfEveryKind(): RepackOutcome
{
    $p1 = new Participant('p1', 'One');
    $p2 = new Participant('p2', 'Two');

    return new RepackOutcome(
        [new SlotAssignment('e1', 0, 2, null)],
        [new UnplacedEvent('e9', UnplacedReason::ParticipantOverCapacity, $p1)],
        [
            new ParticipantDoubleBooked($p1, 0, 1, ['e1', 'x1']),
            new EventUnplaced('e9', UnplacedReason::ParticipantOverCapacity, $p1),
            new ContiguityBroken($p2, 1, 2, [0, 3]),
            new LateStart($p2, 1, 1),
            new CapacityExceeded($p1, 5, 3),
            new CapacityExceeded(null, 9, 8),
        ]
    );
}

/**
 * A violation of a class from outside the library, serializing to the
 * data it is given.
 *
 * @param array<string, mixed> $data
 */
function foreignViolation(array $data): RepackViolation
{
    return new readonly class ($data) implements RepackViolation {
        /**
         * @param array<string, mixed> $data
         */
        public function __construct(private array $data) {}

        #[Override]
        public function getKind(): ViolationKind
        {
            return ViolationKind::LateStart;
        }

        #[Override]
        public function toArray(): array
        {
            return $this->data;
        }
    };
}

describe('RepackOutcome typed accessors', function (): void {
    it('returns the violations of each kind as their own class', function (): void {
        $outcome = outcomeOfEveryKind();

        expect($outcome->getParticipantDoubleBookedViolations())->toHaveCount(1);
        expect($outcome->getParticipantDoubleBookedViolations()[0]->getEventIds())->toBe(['e1', 'x1']);
        expect($outcome->getParticipantDoubleBookedViolations()[0]->getSlot())->toBe(1);

        expect($outcome->getEventUnplacedViolations())->toHaveCount(1);
        expect($outcome->getEventUnplacedViolations()[0]->getEventId())->toBe('e9');
        expect($outcome->getEventUnplacedViolations()[0]->getReason())->toBe(UnplacedReason::ParticipantOverCapacity);

        expect($outcome->getContiguityBrokenViolations())->toHaveCount(1);
        expect($outcome->getContiguityBrokenViolations()[0]->getGapSlots())->toBe(2);
        expect($outcome->getContiguityBrokenViolations()[0]->getOccupiedSlots())->toBe([0, 3]);

        expect($outcome->getLateStartViolations())->toHaveCount(1);
        expect($outcome->getLateStartViolations()[0]->getFirstSlot())->toBe(1);

        expect($outcome->getCapacityExceededViolations())->toHaveCount(2);
        expect($outcome->getCapacityExceededViolations()[0]->getParticipant()?->getId())->toBe('p1');
        expect($outcome->getCapacityExceededViolations()[0]->getShortfall())->toBe(2);
        expect($outcome->getCapacityExceededViolations()[1]->getParticipant())->toBeNull();
    });

    it('agrees with getViolationsOfKind() for every kind, in the same order', function (): void {
        $outcome = outcomeOfEveryKind();

        $typed = [
            ViolationKind::ParticipantDoubleBooked->value => $outcome->getParticipantDoubleBookedViolations(),
            ViolationKind::EventUnplaced->value => $outcome->getEventUnplacedViolations(),
            ViolationKind::ContiguityBroken->value => $outcome->getContiguityBrokenViolations(),
            ViolationKind::LateStart->value => $outcome->getLateStartViolations(),
            ViolationKind::CapacityExceeded->value => $outcome->getCapacityExceededViolations(),
        ];

        // One accessor for every kind there is
        expect(array_keys($typed))->toBe(array_map(static fn(ViolationKind $kind): string => $kind->value, ViolationKind::cases()));
        foreach (ViolationKind::cases() as $kind) {
            expect($typed[$kind->value])->toBe($outcome->getViolationsOfKind($kind));
        }
    });

    it('leaves a violation of a class from outside the library to getViolationsOfKind()', function (): void {
        // The foreign class states the kind LateStart and is not a LateStart
        $foreign = foreignViolation(['kind' => 'late_start']);
        $own = new LateStart(new Participant('p', 'P'), 0, 1);
        $outcome = new RepackOutcome([], [], [$foreign, $own]);

        expect($outcome->getViolationsOfKind(ViolationKind::LateStart))->toBe([$foreign, $own]);
        expect($outcome->getLateStartViolations())->toBe([$own]);
    });

    it('returns empty lists for a clean outcome', function (): void {
        $outcome = new RepackOutcome([], [], []);

        expect($outcome->getParticipantDoubleBookedViolations())->toBe([]);
        expect($outcome->getEventUnplacedViolations())->toBe([]);
        expect($outcome->getContiguityBrokenViolations())->toBe([]);
        expect($outcome->getLateStartViolations())->toBe([]);
        expect($outcome->getCapacityExceededViolations())->toBe([]);
    });
});

describe('RepackOutcome construction', function (): void {
    it('rejects a list that holds something else, with a library exception', function (Closure $build, string $message): void {
        expect($build)->toThrow(InvalidInputException::class, $message);
    })->with([
        'a string among the assignments' => [
            fn() => new RepackOutcome(['x'], [], []), // @phpstan-ignore argument.type (deliberately malformed)
            'Every assignment of a repack outcome must be a ' . SlotAssignment::class . '; the entry at key 0 is of type string',
        ],
        'an assignment among the unplaced events' => [
            fn() => new RepackOutcome([], [new SlotAssignment('e1', 0, 0, null)], []), // @phpstan-ignore argument.type (deliberately malformed)
            'Every unplaced event of a repack outcome must be a ' . UnplacedEvent::class . '; the entry at key 0 is of type ' . SlotAssignment::class,
        ],
        'a null among the violations' => [
            fn() => new RepackOutcome([], [], ['first' => new LateStart(new Participant('p', 'P'), 0, 1), 'second' => null]), // @phpstan-ignore argument.type (deliberately malformed)
            'Every violation of a repack outcome must be a ' . RepackViolation::class . "; the entry at key 'second' is of type null",
        ],
    ]);

    it('says the budget was not exhausted unless told otherwise', function (): void {
        expect((new RepackOutcome([], [], []))->isBudgetExhausted())->toBeFalse();
        expect((new RepackOutcome([], [], [], true))->isBudgetExhausted())->toBeTrue();
        expect((new RepackOutcome([], [], [], budgetExhausted: true))->isBudgetExhausted())->toBeTrue();
    });

    it('keeps the budget flag out of the plain data', function (): void {
        expect((new RepackOutcome([], [], [], true))->toArray())->toBe(['assignments' => [], 'unplaced' => [], 'violations' => []]);
    });
});

describe('SlotAssignment without a kickoff', function (): void {
    it('says so, refuses to state one, and serializes a null', function (): void {
        $assignment = new SlotAssignment('e1', 1, 2, null);

        expect($assignment->hasKickoff())->toBeFalse();
        expect($assignment->toArray())->toBe(['event_id' => 'e1', 'session' => 1, 'slot' => 2, 'kickoff' => null]);
        expect(fn() => $assignment->getKickoff())
            ->toThrow(InvalidConfigurationException::class, 'This assignment has no kickoff: its grid is shape-only');

        try {
            $assignment->getKickoff();
        } catch (InvalidConfigurationException $e) {
            expect($e->getReason())->toBe(InvalidConfigurationReason::GridWithoutInstants);
            expect($e->getContext())->toBe(['event_id' => 'e1', 'session' => 1, 'slot' => 2]);
        }
    });

    it('is unchanged when it has one', function (): void {
        $kickoff = new DateTimeImmutable('2026-08-12 19:00:00', new DateTimeZone('UTC'));
        $assignment = new SlotAssignment('e1', 1, 2, $kickoff);

        expect($assignment->hasKickoff())->toBeTrue();
        expect($assignment->getKickoff())->toBe($kickoff);
        expect($assignment->toArray())->toBe(['event_id' => 'e1', 'session' => 1, 'slot' => 2, 'kickoff' => '2026-08-12T19:00:00Z']);
    });
});

describe('RepackOutcome::fingerprint()', function (): void {
    // The expected values below were computed by an implementation of the
    // documented scheme written apart from the library's, from the
    // docblock of fingerprint() alone. They are literals on purpose: any
    // change to the canonical document changes them, and a change to the
    // canonical document needs a new scheme, not new literals.
    it('is the documented digest of the documented bytes', function (): void {
        $empty = new RepackOutcome([], [], []);
        expect($empty->fingerprint())->toBe('v1:' . hash('sha256', "tactician.repack.outcome.v1\nl0:;l0:;l0:;"));
        expect($empty->fingerprint())->toBe('v1:3873a09ebfb80b40f6238420ad0dce02f348d5c37cd7ccff46f5f150d0a7c9a6');

        $two = new RepackOutcome(
            [
                new SlotAssignment('e1', 0, 2, new DateTimeImmutable('2026-08-12 19:00:00', new DateTimeZone('UTC'))),
                new SlotAssignment('e2', 1, 0, new DateTimeImmutable('2026-08-19 19:00:00', new DateTimeZone('UTC'))),
            ],
            [],
            []
        );
        $document = "tactician.repack.outcome.v1\n"
            . 'l2:'
            . 'm4:s4:slot;i0;s7:kickoff;s20:2026-08-19T19:00:00Z;s7:session;i1;s8:event_id;s2:e2;;'
            . 'm4:s4:slot;i2;s7:kickoff;s20:2026-08-12T19:00:00Z;s7:session;i0;s8:event_id;s2:e1;;'
            . ';l0:;l0:;';
        expect($two->fingerprint())->toBe('v1:' . hash('sha256', $document));
        expect($two->fingerprint())->toBe('v1:ca89d58284b112278f2dffe74fee18e3a225f8eebabfc1503e7eb435836f46da');
    });

    it('is pinned for an outcome holding every kind of record', function (): void {
        expect(outcomeOfEveryKind()->fingerprint())->toBe('v1:5a2ca37420f0e0aab286eb4418d0b6f3a67b6ae2cd375b56aaad4e1f28c80688');
    });

    // The canonical document of scheme v1 written out for every class of
    // record, from the key lists in the docblock of fingerprint(). The
    // bytes were produced by an encoder written in another language from
    // that docblock. A record class whose keys change fails here.
    it('is the digest of the documented keys of every class of record', function (): void {
        $document = "tactician.repack.outcome.v1\n"
            // The assignment, without a kickoff
            . 'l1:m4:s4:slot;i2;s7:kickoff;n;s7:session;i0;s8:event_id;s2:e1;;;'
            // The unplaced event
            . 'l1:m3:s11:participant;s2:p1;s6:reason;s25:participant_over_capacity;s8:event_id;s2:e9;;;'
            . 'l6:'
            // LateStart
            . 'm4:s10:first_slot;i1;s11:participant;s2:p2;s4:kind;s10:late_start;s7:session;i1;;'
            // EventUnplaced
            . 'm4:s11:participant;s2:p1;s4:kind;s14:event_unplaced;s6:reason;s25:participant_over_capacity;s8:event_id;s2:e9;;'
            // CapacityExceeded for the grid, then for a participant
            . 'm5:s11:participant;n;s4:kind;s17:capacity_exceeded;s6:demand;i9;s8:capacity;i8;s9:shortfall;i1;;'
            . 'm5:s11:participant;s2:p1;s4:kind;s17:capacity_exceeded;s6:demand;i5;s8:capacity;i3;s9:shortfall;i2;;'
            // ParticipantDoubleBooked
            . 'm5:s11:participant;s2:p1;s4:kind;s25:participant_double_booked;s4:slot;i1;s7:session;i0;s9:event_ids;l2:s2:e1;s2:x1;;;'
            // ContiguityBroken
            . 'm5:s11:participant;s2:p2;s14:occupied_slots;l2:i0;i3;;s4:kind;s17:contiguity_broken;s7:session;i1;s9:gap_slots;i2;;'
            . ';';

        expect(outcomeOfEveryKind()->fingerprint())->toBe('v1:' . hash('sha256', $document));
    });

    // Scheme v1 names the keys it covers, so that a key added to a
    // toArray() later does not change a v1 fingerprint. Today the named
    // keys are all the keys there are. If this fails because a toArray()
    // gained a key, the fingerprint does not cover the new key: decide
    // whether that is right (it then needs saying in the usage guide) or
    // whether the new key calls for a new scheme, and only then change the
    // list here. Do not add the key to the v1 lists in RepackOutcome.
    it('covers every key the records have today', function (): void {
        $data = outcomeOfEveryKind()->toArray();

        $keys = ['assignment' => array_keys($data['assignments'][0]), 'unplaced' => array_keys($data['unplaced'][0])];
        foreach ($data['violations'] as $violation) {
            $keys[(string) $violation['kind']] = array_keys($violation);
        }

        expect($keys)->toBe([
            'assignment' => ['event_id', 'session', 'slot', 'kickoff'],
            'unplaced' => ['event_id', 'reason', 'participant'],
            'participant_double_booked' => ['kind', 'participant', 'session', 'slot', 'event_ids'],
            'event_unplaced' => ['kind', 'event_id', 'reason', 'participant'],
            'contiguity_broken' => ['kind', 'participant', 'session', 'gap_slots', 'occupied_slots'],
            'late_start' => ['kind', 'participant', 'session', 'first_slot'],
            'capacity_exceeded' => ['kind', 'participant', 'demand', 'capacity', 'shortfall'],
        ]);
    });

    it('is pinned for event ids that are empty, numeric, multi-byte or hold the delimiters', function (): void {
        $outcome = new RepackOutcome(
            [
                new SlotAssignment("\u{e9}v;1\n", 0, 0, null),
                new SlotAssignment('', 0, 1, null),
                new SlotAssignment('10', 0, 2, null),
            ],
            [],
            []
        );

        expect($outcome->fingerprint())->toBe('v1:5294ca9d18e9c065a2cc148ef365f8320d3cbf792009900fbddfbc0bb159b9ac');
    });

    it('is pinned for a violation of a class from outside the library, floats and nested maps included', function (): void {
        $outcome = new RepackOutcome([], [], [foreignViolation([
            'kind' => 'late_start',
            'ratio' => 0.1,
            'flag' => true,
            'nested' => ['b' => [1, -2], 'a' => null, 7 => 'seven'],
        ])]);

        expect($outcome->fingerprint())->toBe('v1:ea881cdf842688e71a8f26450592ae55509b05a0298c4d7345fa44fef65c7142');
    });

    it('has the scheme, a colon and 64 lowercase hexadecimal digits', function (): void {
        expect(RepackOutcome::FINGERPRINT_SCHEME)->toBe('v1');
        expect(outcomeOfEveryKind()->fingerprint())->toMatch('/\Av1:[0-9a-f]{64}\z/');
    });

    it('does not depend on the order of the three lists', function (): void {
        $outcome = outcomeOfEveryKind();
        $reversed = new RepackOutcome(
            array_reverse($outcome->getAssignments()),
            array_reverse($outcome->getUnplaced()),
            array_reverse($outcome->getViolations())
        );

        expect($reversed->toArray())->not->toBe($outcome->toArray());
        expect($reversed->fingerprint())->toBe($outcome->fingerprint());
    });

    it('does not depend on the order of the keys of a record', function (): void {
        $forwards = new RepackOutcome([], [], [foreignViolation(['kind' => 'late_start', 'depth' => 2])]);
        $backwards = new RepackOutcome([], [], [foreignViolation(['depth' => 2, 'kind' => 'late_start'])]);

        expect($backwards->fingerprint())->toBe($forwards->fingerprint());
    });

    it('depends on the order of a list inside a record', function (): void {
        $participant = new Participant('p', 'P');
        $ascending = new RepackOutcome([], [], [new ContiguityBroken($participant, 0, 1, [0, 2])]);
        $descending = new RepackOutcome([], [], [new ContiguityBroken($participant, 0, 1, [2, 0])]);

        expect($descending->fingerprint())->not->toBe($ascending->fingerprint());
    });

    it('does not depend on the budget flag', function (): void {
        $outcome = outcomeOfEveryKind();
        $exhausted = new RepackOutcome($outcome->getAssignments(), $outcome->getUnplaced(), $outcome->getViolations(), true);

        expect($exhausted->fingerprint())->toBe($outcome->fingerprint());
    });

    it('does not depend on precision or serialize_precision', function (): void {
        $outcome = new RepackOutcome([], [], [foreignViolation(['kind' => 'late_start', 'ratio' => 0.1, 'third' => 1 / 3])]);
        $expected = $outcome->fingerprint();

        $precision = (string) ini_get('precision');
        $serializePrecision = (string) ini_get('serialize_precision');
        try {
            ini_set('precision', '3');
            ini_set('serialize_precision', '5');
            // Proof that the settings are in force and change how a float is written
            expect((string) (1 / 3))->toBe('0.333');
            expect(json_encode(1 / 3))->toBe('0.33333');

            expect($outcome->fingerprint())->toBe($expected);
        } finally {
            ini_set('precision', $precision);
            ini_set('serialize_precision', $serializePrecision);
        }
    });

    it('does not depend on the locale', function (): void {
        $outcome = new RepackOutcome([], [], [foreignViolation(['kind' => 'late_start', 'ratio' => 0.1, 'count' => 1234567])]);
        $expected = $outcome->fingerprint();

        expect(DecimalCommaLocale::during($outcome->fingerprint(...)))->toBe($expected);
    });

    it('tells apart values that print alike', function (array $one, array $other): void {
        $first = new RepackOutcome([], [], [foreignViolation(['kind' => 'late_start', ...$one])]);
        $second = new RepackOutcome([], [], [foreignViolation(['kind' => 'late_start', ...$other])]);

        expect($second->fingerprint())->not->toBe($first->fingerprint());
    })->with([
        'the integer 1 and the string 1' => [['v' => 1], ['v' => '1']],
        'the integer 1 and the float 1' => [['v' => 1], ['v' => 1.0]],
        'the integer 1 and true' => [['v' => 1], ['v' => true]],
        'null and an empty string' => [['v' => null], ['v' => '']],
        'null and false' => [['v' => null], ['v' => false]],
        'an empty list and null' => [['v' => []], ['v' => null]],
        'zero and negative zero' => [['v' => 0.0], ['v' => -0.0]],
        'one string and the same bytes split in two' => [['v' => ['ab']], ['v' => ['a', 'b']]],
        'a string holding an encoded string' => [['v' => 's1:a;'], ['v' => ['a']]],
        'a key and a value exchanged' => [['a' => 'b'], ['b' => 'a']],
        'a list and a map with the same items' => [['v' => ['x', 'y']], ['v' => [1 => 'x', 2 => 'y']]],
    ]);

    it('refuses a record it has no encoding for, with a library exception', function (): void {
        $outcome = new RepackOutcome([], [], [foreignViolation(['kind' => 'late_start', 'when' => new DateTimeImmutable('2026-08-12')])]);

        expect(fn() => $outcome->fingerprint())->toThrow(
            InvalidInputException::class,
            'a record holds a value of type DateTimeImmutable'
        );
    });
});
