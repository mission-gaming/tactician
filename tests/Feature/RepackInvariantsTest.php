<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\RepackViolationsException;
use MissionGaming\Tactician\Repack\CapacityExceeded;
use MissionGaming\Tactician\Repack\ContiguityBroken;
use MissionGaming\Tactician\Repack\EventUnplaced;
use MissionGaming\Tactician\Repack\LateStart;
use MissionGaming\Tactician\Repack\MovableEvent;
use MissionGaming\Tactician\Repack\ParticipantDoubleBooked;
use MissionGaming\Tactician\Repack\PinnedEvent;
use MissionGaming\Tactician\Repack\RepackOptions;
use MissionGaming\Tactician\Repack\RepackOutcome;
use MissionGaming\Tactician\Repack\RepackRequest;
use MissionGaming\Tactician\Repack\ScheduleRepacker;
use MissionGaming\Tactician\Repack\SessionGrid;
use MissionGaming\Tactician\Repack\SlotAssignment;
use MissionGaming\Tactician\Repack\UnplacedEvent;
use MissionGaming\Tactician\Repack\UnplacedReason;
use MissionGaming\Tactician\Repack\ViolationKind;
use MissionGaming\Tactician\Tests\Support\RepackBruteForce;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * @return array{0: array<MovableEvent>, 1: array<PinnedEvent>, 2: SessionGrid}
 *
 * @throws \MissionGaming\Tactician\Exceptions\InvalidConfigurationException
 * @throws Random\RandomException
 * @throws Exception
 */
function randomRepackInstance(int $seed): array
{
    $rng = new Randomizer(new Mt19937($seed));

    $participantCount = $rng->getInt(6, 10);
    $participants = [];
    for ($i = 1; $i <= $participantCount; ++$i) {
        $participants[] = new Participant("p{$i}", "P{$i}");
    }

    $sessions = [];
    $sessionCount = $rng->getInt(2, 3);
    for ($i = 0; $i < $sessionCount; ++$i) {
        $sessions[] = new DateTimeImmutable(
            sprintf('2026-08-%02d 20:00', 12 + 7 * $i),
            new DateTimeZone('Europe/London')
        );
    }
    $slotsPerSession = $rng->getInt(3, 4);
    $capacity = $rng->getInt(2, 4);
    $grid = new SessionGrid($sessions, new DateInterval('PT30M'), $slotsPerSession, [], $capacity);

    // A random multigraph: repeated pairings are deliberate
    $movable = [];
    $eventCount = $rng->getInt(8, 20);
    for ($i = 1; $i <= $eventCount; ++$i) {
        $a = $rng->getInt(0, $participantCount - 1);
        $b = $rng->getInt(0, $participantCount - 2);
        if ($b >= $a) {
            ++$b;
        }
        $movable[] = new MovableEvent("m{$i}", $participants[$a], $participants[$b]);
    }

    // Random legal pins: never two pins sharing a participant at one
    // position, never over capacity
    $pinned = [];
    $pinnedAt = [];
    $countAt = [];
    $attempts = $rng->getInt(0, 6);
    for ($i = 1; $i <= $attempts; ++$i) {
        $a = $rng->getInt(0, $participantCount - 1);
        $b = $rng->getInt(0, $participantCount - 2);
        if ($b >= $a) {
            ++$b;
        }
        $session = $rng->getInt(0, $sessionCount - 1);
        $slot = $rng->getInt(0, $slotsPerSession - 1);
        $position = "{$session}:{$slot}";
        if (($countAt[$position] ?? 0) >= $capacity) {
            continue;
        }
        if (isset($pinnedAt["{$position}:{$a}"]) || isset($pinnedAt["{$position}:{$b}"])) {
            continue;
        }
        $pinned[] = new PinnedEvent("x{$i}", $participants[$a], $participants[$b], $session, $slot);
        $pinnedAt["{$position}:{$a}"] = true;
        $pinnedAt["{$position}:{$b}"] = true;
        $countAt[$position] = ($countAt[$position] ?? 0) + 1;
    }

    return [$movable, $pinned, $grid];
}

/**
 * Assert nobody occupies one position twice, counting pins, and that the
 * audit agrees.
 *
 * @param array<MovableEvent> $movable
 * @param array<PinnedEvent> $pinned
 */
function assertProperness(RepackOutcome $outcome, array $movable, array $pinned): void
{
    $participantsByEvent = [];
    foreach ($movable as $event) {
        $participantsByEvent[$event->getId()] = $event->getParticipants();
    }

    $seen = [];
    foreach ($outcome->getAssignments() as $assignment) {
        foreach ($participantsByEvent[$assignment->getEventId()] as $participant) {
            $key = "{$assignment->getSession()}:{$assignment->getSlot()}:{$participant->getId()}";
            expect($seen)->not->toHaveKey($key);
            $seen[$key] = $assignment->getEventId();
        }
    }
    foreach ($pinned as $pin) {
        foreach ($pin->getParticipants() as $participant) {
            $key = "{$pin->getSession()}:{$pin->getSlot()}:{$participant->getId()}";
            expect($seen)->not->toHaveKey($key);
            $seen[$key] = $pin->getId();
        }
    }

    expect($outcome->getViolationsOfKind(ViolationKind::ParticipantDoubleBooked))->toBe([]);
}

/**
 * Assert the "no slot available" reason is literally true: no position
 * on the grid has capacity left with both participants free.
 *
 * @param array<MovableEvent> $movable
 * @param array<PinnedEvent> $pinned
 *
 * @throws \MissionGaming\Tactician\Exceptions\InvalidConfigurationException
 */
function assertNoSlotAvailableIsLiteral(
    RepackOutcome $outcome,
    array $movable,
    array $pinned,
    SessionGrid $grid
): void {
    $participantsByEvent = [];
    foreach ($movable as $event) {
        $participantsByEvent[$event->getId()] = $event->getParticipants();
    }

    $occupancy = [];
    $busy = [];
    foreach ($outcome->getAssignments() as $assignment) {
        $position = "{$assignment->getSession()}:{$assignment->getSlot()}";
        $occupancy[$position] = ($occupancy[$position] ?? 0) + 1;
        foreach ($participantsByEvent[$assignment->getEventId()] as $participant) {
            $busy["{$position}:{$participant->getId()}"] = true;
        }
    }
    foreach ($pinned as $pin) {
        $position = "{$pin->getSession()}:{$pin->getSlot()}";
        $occupancy[$position] = ($occupancy[$position] ?? 0) + 1;
        foreach ($pin->getParticipants() as $participant) {
            $busy["{$position}:{$participant->getId()}"] = true;
        }
    }

    foreach ($outcome->getUnplaced() as $unplaced) {
        if ($unplaced->getReason() !== UnplacedReason::NoSlotAvailable) {
            continue;
        }
        [$a, $b] = $participantsByEvent[$unplaced->getEventId()];
        for ($session = 0; $session < $grid->getSessionCount(); ++$session) {
            for ($slot = 0; $slot < $grid->getSlotCount($session); ++$slot) {
                $position = "{$session}:{$slot}";
                $freePositionExists = ($occupancy[$position] ?? 0) < ($grid->getCapacityLimit() ?? PHP_INT_MAX)
                    && !isset($busy["{$position}:{$a->getId()}"])
                    && !isset($busy["{$position}:{$b->getId()}"]);
                expect($freePositionExists)->toBeFalse();
            }
        }
    }
}

/**
 * Assert pattern violations arrive in scope order within each kind:
 * participant id ascending, then session ascending.
 */
function assertScopeOrder(RepackOutcome $outcome): void
{
    foreach ([ViolationKind::ContiguityBroken, ViolationKind::LateStart] as $kind) {
        $previous = null;
        foreach ($outcome->getViolationsOfKind($kind) as $violation) {
            assert($violation instanceof ContiguityBroken || $violation instanceof LateStart);
            $current = [$violation->getParticipant()->getId(), $violation->getSession()];
            if ($previous !== null) {
                $comparison = strcmp($previous[0], $current[0]) ?: $previous[1] <=> $current[1];
                expect($comparison)->toBeLessThan(1);
            }
            $previous = $current;
        }
    }
}

describe('Repack invariants', function (): void {
    it('assigns or reports every event, never double-books, never moves a pin', function (int $seed): void {
        [$movable, $pinned, $grid] = randomRepackInstance($seed);

        $outcome = (new ScheduleRepacker())->repack(new RepackRequest($movable, $pinned, $grid));

        // Exact reconciliation: nothing vanishes
        $accounted = [];
        foreach ($outcome->getAssignments() as $assignment) {
            $accounted[$assignment->getEventId()] = true;
        }
        foreach ($outcome->getUnplaced() as $unplaced) {
            expect($accounted)->not->toHaveKey($unplaced->getEventId());
            $accounted[$unplaced->getEventId()] = true;
        }
        expect(count($accounted))->toBe(count($movable));
        foreach ($movable as $event) {
            expect($accounted)->toHaveKey($event->getId());
        }

        // Pins are never re-emitted, so they hold their input position
        $movableIds = array_fill_keys(array_map(
            static fn(MovableEvent $event): string => $event->getId(),
            $movable
        ), true);
        foreach ($outcome->getAssignments() as $assignment) {
            expect($movableIds)->toHaveKey($assignment->getEventId());
        }

        assertProperness($outcome, $movable, $pinned);

        // The violation stream mirrors the unplaced list exactly
        expect(count($outcome->getViolationsOfKind(ViolationKind::EventUnplaced)))
            ->toBe(count($outcome->getUnplaced()));

        assertNoSlotAvailableIsLiteral($outcome, $movable, $pinned, $grid);
        assertScopeOrder($outcome);
    })->with(range(1, 12));

    it('produces byte-identical output whatever order the input lists are in', function (int $seed): void {
        [$movable, $pinned, $grid] = randomRepackInstance($seed);

        $repacker = new ScheduleRepacker();
        $baseline = $repacker->repack(new RepackRequest($movable, $pinned, $grid))->toArray();

        $rng = new Randomizer(new Mt19937($seed + 1000));
        $shuffledMovable = $rng->shuffleArray($movable);
        $shuffledPinned = $rng->shuffleArray($pinned);

        $shuffled = $repacker->repack(new RepackRequest($shuffledMovable, $shuffledPinned, $grid))->toArray();

        expect(json_encode($shuffled))->toBe(json_encode($baseline));
    })->with([[3], [7], [11]]);

    it('packs a complete even round robin with zero violations', function (): void {
        // K8 decomposes into 7 perfect matchings; a 7-slot session must
        // come back gap-free, late-start-free, everything placed. This is
        // the exactness case the brief says would have caught the old
        // caller-side implementation.
        $participants = [];
        for ($i = 1; $i <= 8; ++$i) {
            $participants[] = new Participant("p{$i}", "P{$i}");
        }
        $movable = [];
        for ($a = 0; $a < 8; ++$a) {
            for ($b = $a + 1; $b < 8; ++$b) {
                $movable[] = new MovableEvent(sprintf('e%d%d', $a, $b), $participants[$a], $participants[$b]);
            }
        }
        $grid = new SessionGrid(
            [new DateTimeImmutable('2026-08-12 20:00', new DateTimeZone('UTC'))],
            new DateInterval('PT30M'),
            7,
            [],
            4
        );

        $outcome = (new ScheduleRepacker())->repack(new RepackRequest($movable, [], $grid));

        expect($outcome->isClean())->toBeTrue();
        expect($outcome->getAssignments())->toHaveCount(28);

        // Every participant plays every slot
        $slotsByParticipant = [];
        foreach ($outcome->getAssignments() as $assignment) {
            foreach ($movable as $event) {
                if ($event->getId() === $assignment->getEventId()) {
                    foreach ($event->getParticipants() as $participant) {
                        $slotsByParticipant[$participant->getId()][] = $assignment->getSlot();
                    }
                }
            }
        }
        foreach ($slotsByParticipant as $slots) {
            sort($slots);
            expect($slots)->toBe([0, 1, 2, 3, 4, 5, 6]);
        }
    });

    it('reports an odd complete round robin honestly instead of mangling it', function (): void {
        // K5 has no interval edge colouring: every participant misses one
        // slot of five, and three of the five misses are interior. The
        // contract is a proper schedule plus reported compromises — never
        // an unreported relaxation.
        $participants = [];
        for ($i = 1; $i <= 5; ++$i) {
            $participants[] = new Participant("p{$i}", "P{$i}");
        }
        $movable = [];
        for ($a = 0; $a < 5; ++$a) {
            for ($b = $a + 1; $b < 5; ++$b) {
                $movable[] = new MovableEvent(sprintf('e%d%d', $a, $b), $participants[$a], $participants[$b]);
            }
        }
        $grid = new SessionGrid(
            [new DateTimeImmutable('2026-08-12 20:00', new DateTimeZone('UTC'))],
            new DateInterval('PT30M'),
            5,
            [],
            2
        );

        $outcome = (new ScheduleRepacker())->repack(new RepackRequest($movable, [], $grid));

        assertProperness($outcome, $movable, []);
        expect($outcome->getViolations())->not->toBe([]);
        expect(count($outcome->getAssignments()) + count($outcome->getUnplaced()))->toBe(10);
    });

    it('throws the outcome-carrying exception when opted in and the outcome is not clean', function (): void {
        // K5 cannot pack clean (see the honesty test above), so opting
        // into throwing must throw — and lose nothing: the exception
        // carries the exact outcome the default contract returns
        $participants = [];
        for ($i = 1; $i <= 5; ++$i) {
            $participants[] = new Participant("p{$i}", "P{$i}");
        }
        $movable = [];
        for ($a = 0; $a < 5; ++$a) {
            for ($b = $a + 1; $b < 5; ++$b) {
                $movable[] = new MovableEvent(sprintf('e%d%d', $a, $b), $participants[$a], $participants[$b]);
            }
        }
        $grid = new SessionGrid(
            [new DateTimeImmutable('2026-08-12 20:00', new DateTimeZone('UTC'))],
            new DateInterval('PT30M'),
            5,
            [],
            2
        );

        $repacker = new ScheduleRepacker();
        $returned = $repacker->repack(new RepackRequest($movable, [], $grid));

        try {
            $repacker->repack(new RepackRequest(
                $movable,
                [],
                $grid,
                new RepackOptions(throwOnViolations: true)
            ));
            expect(false)->toBeTrue();
        } catch (RepackViolationsException $exception) {
            expect($exception->getOutcome()->toArray())->toBe($returned->toArray());
            expect($exception->getDiagnosticReport())->toContain('Repack violations by kind:');
        }
    });

    it('does not throw on a clean outcome even when opted in', function (): void {
        $participants = [];
        for ($i = 1; $i <= 8; ++$i) {
            $participants[] = new Participant("p{$i}", "P{$i}");
        }
        $movable = [];
        for ($a = 0; $a < 8; ++$a) {
            for ($b = $a + 1; $b < 8; ++$b) {
                $movable[] = new MovableEvent(sprintf('e%d%d', $a, $b), $participants[$a], $participants[$b]);
            }
        }
        $grid = new SessionGrid(
            [new DateTimeImmutable('2026-08-12 20:00', new DateTimeZone('UTC'))],
            new DateInterval('PT30M'),
            7,
            [],
            4
        );

        $outcome = (new ScheduleRepacker())->repack(new RepackRequest(
            $movable,
            [],
            $grid,
            new RepackOptions(throwOnViolations: true)
        ));

        expect($outcome->isClean())->toBeTrue();
    });
});

/**
 * The grid of a random instance with its capacity replaced, and
 * optionally with its instants taken away.
 *
 * @throws MissionGaming\Tactician\Exceptions\InvalidConfigurationException
 */
function regridded(SessionGrid $grid, ?int $capacityPerSlot, bool $shapeOnly): SessionGrid
{
    $overrides = [];
    for ($session = 0; $session < $grid->getSessionCount(); ++$session) {
        $overrides[$session] = $grid->getSlotCount($session);
    }

    return $shapeOnly
        ? SessionGrid::shapeOnly($grid->getSessionCount(), 1, $overrides, $capacityPerSlot)
        : new SessionGrid(
            array_map($grid->getSessionStart(...), array_keys($overrides)),
            $grid->getSlotInterval(),
            1,
            $overrides,
            $capacityPerSlot
        );
}

/**
 * The repack of a random instance, with one more record of every kind
 * added, so that every class the outcome can hold is in it whatever the
 * instance happened to produce.
 *
 * @throws MissionGaming\Tactician\Exceptions\TacticianException
 * @throws Random\RandomException
 * @throws Exception
 */
function outcomeWithEveryRecord(int $seed): RepackOutcome
{
    [$movable, $pinned, $grid] = randomRepackInstance($seed);
    $outcome = (new ScheduleRepacker())->repack(new RepackRequest($movable, $pinned, $grid));

    $one = new Participant('extra-1', 'Extra One');
    $two = new Participant('extra-2', 'Extra Two');

    return new RepackOutcome(
        [
            ...$outcome->getAssignments(),
            new SlotAssignment('extra-shape-only', 0, 0, null),
        ],
        [
            ...$outcome->getUnplaced(),
            new UnplacedEvent('extra-u1', UnplacedReason::ParticipantOverCapacity, $one),
            new UnplacedEvent('extra-u2', UnplacedReason::NoSlotAvailable),
        ],
        [
            ...$outcome->getViolations(),
            new ParticipantDoubleBooked($one, 0, 1, ['extra-a', 'extra-b']),
            new EventUnplaced('extra-u1', UnplacedReason::ParticipantOverCapacity, $one),
            new EventUnplaced('extra-u2', UnplacedReason::NoSlotAvailable),
            new ContiguityBroken($two, 1, 2, [0, 3]),
            new LateStart($two, 1, 1),
            new CapacityExceeded($one, 5, 3),
            new CapacityExceeded(null, 9, 8),
        ],
        $outcome->isBudgetExhausted()
    );
}

/**
 * Every copy of an outcome that differs from it in exactly one field of
 * one record, or by one record added or removed, keyed by what was
 * changed.
 *
 * @return array<string, RepackOutcome>
 *
 * @throws MissionGaming\Tactician\Exceptions\TacticianException
 */
function singleChangeMutants(RepackOutcome $outcome): array
{
    $assignments = $outcome->getAssignments();
    $unplaced = $outcome->getUnplaced();
    $violations = $outcome->getViolations();
    $other = new Participant('someone-else', 'Someone Else');

    $mutants = [];

    foreach ($assignments as $i => $a) {
        $kickoff = $a->hasKickoff() ? $a->getKickoff() : null;
        $changed = [
            'event id' => new SlotAssignment($a->getEventId() . '~', $a->getSession(), $a->getSlot(), $kickoff),
            'session' => new SlotAssignment($a->getEventId(), $a->getSession() + 1, $a->getSlot(), $kickoff),
            'slot' => new SlotAssignment($a->getEventId(), $a->getSession(), $a->getSlot() + 1, $kickoff),
            'kickoff' => new SlotAssignment(
                $a->getEventId(),
                $a->getSession(),
                $a->getSlot(),
                $kickoff instanceof DateTimeImmutable
                    ? $kickoff->modify('+1 second')
                    : new DateTimeImmutable('2026-08-12 19:00:00', new DateTimeZone('UTC'))
            ),
        ];
        if ($kickoff instanceof DateTimeImmutable) {
            $changed['kickoff removed'] = new SlotAssignment($a->getEventId(), $a->getSession(), $a->getSlot(), null);
        }
        foreach ($changed as $what => $replacement) {
            $mutants["assignment {$i}: {$what}"] = new RepackOutcome(array_replace($assignments, [$i => $replacement]), $unplaced, $violations);
        }

        $without = $assignments;
        unset($without[$i]);
        $mutants["assignment {$i}: removed"] = new RepackOutcome(array_values($without), $unplaced, $violations);
        $mutants["assignment {$i}: twice"] = new RepackOutcome([...$assignments, $a], $unplaced, $violations);
    }

    foreach ($unplaced as $i => $u) {
        $otherReason = $u->getReason() === UnplacedReason::NoSlotAvailable
            ? UnplacedReason::ParticipantOverCapacity
            : UnplacedReason::NoSlotAvailable;
        $changed = [
            'event id' => new UnplacedEvent($u->getEventId() . '~', $u->getReason(), $u->getParticipant()),
            'reason' => new UnplacedEvent($u->getEventId(), $otherReason, $u->getParticipant()),
            'participant' => new UnplacedEvent($u->getEventId(), $u->getReason(), $u->getParticipant() === null ? $other : null),
        ];
        foreach ($changed as $what => $replacement) {
            $mutants["unplaced {$i}: {$what}"] = new RepackOutcome($assignments, array_replace($unplaced, [$i => $replacement]), $violations);
        }

        $without = $unplaced;
        unset($without[$i]);
        $mutants["unplaced {$i}: removed"] = new RepackOutcome($assignments, array_values($without), $violations);
    }

    foreach ($violations as $i => $v) {
        $changed = match (true) {
            $v instanceof ParticipantDoubleBooked => [
                'participant' => new ParticipantDoubleBooked($other, $v->getSession(), $v->getSlot(), $v->getEventIds()),
                'session' => new ParticipantDoubleBooked($v->getParticipant(), $v->getSession() + 1, $v->getSlot(), $v->getEventIds()),
                'slot' => new ParticipantDoubleBooked($v->getParticipant(), $v->getSession(), $v->getSlot() + 1, $v->getEventIds()),
                'event ids' => new ParticipantDoubleBooked($v->getParticipant(), $v->getSession(), $v->getSlot(), [...$v->getEventIds(), 'one-more']),
            ],
            $v instanceof EventUnplaced => [
                'event id' => new EventUnplaced($v->getEventId() . '~', $v->getReason(), $v->getParticipant()),
                'reason' => new EventUnplaced(
                    $v->getEventId(),
                    $v->getReason() === UnplacedReason::NoSlotAvailable ? UnplacedReason::ParticipantOverCapacity : UnplacedReason::NoSlotAvailable,
                    $v->getParticipant()
                ),
                'participant' => new EventUnplaced($v->getEventId(), $v->getReason(), $v->getParticipant() === null ? $other : null),
            ],
            $v instanceof ContiguityBroken => [
                'participant' => new ContiguityBroken($other, $v->getSession(), $v->getGapSlots(), $v->getOccupiedSlots()),
                'session' => new ContiguityBroken($v->getParticipant(), $v->getSession() + 1, $v->getGapSlots(), $v->getOccupiedSlots()),
                'gap slots' => new ContiguityBroken($v->getParticipant(), $v->getSession(), $v->getGapSlots() + 1, $v->getOccupiedSlots()),
                'occupied slots' => new ContiguityBroken($v->getParticipant(), $v->getSession(), $v->getGapSlots(), [...$v->getOccupiedSlots(), 99]),
            ],
            $v instanceof LateStart => [
                'participant' => new LateStart($other, $v->getSession(), $v->getFirstSlot()),
                'session' => new LateStart($v->getParticipant(), $v->getSession() + 1, $v->getFirstSlot()),
                'first slot' => new LateStart($v->getParticipant(), $v->getSession(), $v->getFirstSlot() + 1),
            ],
            $v instanceof CapacityExceeded => [
                'participant' => new CapacityExceeded($v->getParticipant() === null ? $other : null, $v->getDemand(), $v->getCapacity()),
                'demand' => new CapacityExceeded($v->getParticipant(), $v->getDemand() + 1, $v->getCapacity()),
                'capacity' => new CapacityExceeded($v->getParticipant(), $v->getDemand(), $v->getCapacity() + 1),
            ],
            default => throw new LogicException('A violation class this test does not know: ' . $v::class),
        };
        foreach ($changed as $what => $replacement) {
            $mutants["violation {$i} ({$v->getKind()->value}): {$what}"] = new RepackOutcome($assignments, $unplaced, array_replace($violations, [$i => $replacement]));
        }

        $without = $violations;
        unset($without[$i]);
        $mutants["violation {$i} ({$v->getKind()->value}): removed"] = new RepackOutcome($assignments, $unplaced, array_values($without));
    }

    return $mutants;
}

describe('Repack outcome fingerprint', function (): void {
    it('is the same for the same request, whatever order its lists are in', function (int $seed): void {
        [$movable, $pinned, $grid] = randomRepackInstance($seed);
        $repacker = new ScheduleRepacker();
        $rng = new Randomizer(new Mt19937($seed + 2000));

        $first = $repacker->repack(new RepackRequest($movable, $pinned, $grid));
        $again = $repacker->repack(new RepackRequest($movable, $pinned, $grid));
        $shuffled = $repacker->repack(new RepackRequest($rng->shuffleArray($movable), $rng->shuffleArray($pinned), $grid));

        expect($first->fingerprint())->toMatch('/\Av1:[0-9a-f]{64}\z/');
        expect($again->fingerprint())->toBe($first->fingerprint());
        expect($shuffled->fingerprint())->toBe($first->fingerprint());
    })->with(range(1, 12));

    it('is the same for the same records in any order, and whatever the budget flag says', function (int $seed): void {
        $outcome = outcomeWithEveryRecord($seed);
        $rng = new Randomizer(new Mt19937($seed + 3000));

        for ($round = 0; $round < 5; ++$round) {
            $reordered = new RepackOutcome(
                $rng->shuffleArray($outcome->getAssignments()),
                $rng->shuffleArray($outcome->getUnplaced()),
                $rng->shuffleArray($outcome->getViolations()),
                !$outcome->isBudgetExhausted()
            );

            expect($reordered->fingerprint())->toBe($outcome->fingerprint());
        }
    })->with(range(1, 12));

    it('changes when any one field of any one record changes, or a record comes or goes', function (int $seed): void {
        $outcome = outcomeWithEveryRecord($seed);
        $fingerprint = $outcome->fingerprint();

        $mutants = singleChangeMutants($outcome);

        // The outcome holds every kind of record, so every kind was mutated
        foreach (ViolationKind::cases() as $kind) {
            expect(array_filter(array_keys($mutants), static fn(string $what): bool => str_contains($what, "({$kind->value})")))->not->toBe([]);
        }
        expect(count($mutants))->toBeGreaterThan(40);

        $seen = [$fingerprint => 'the outcome itself'];
        foreach ($mutants as $what => $mutant) {
            // Different from the original, and from every other mutant:
            // no two changes cancel out or collide
            expect($seen)->not->toHaveKey($mutant->fingerprint(), "{$what} has the fingerprint of " . ($seen[$mutant->fingerprint()] ?? ''));
            $seen[$mutant->fingerprint()] = $what;
        }
    })->with(range(1, 12));

    it('differs between different requests', function (): void {
        $fingerprints = [];
        foreach (range(1, 12) as $seed) {
            [$movable, $pinned, $grid] = randomRepackInstance($seed);
            $fingerprints[] = (new ScheduleRepacker())->repack(new RepackRequest($movable, $pinned, $grid))->fingerprint();
        }

        expect(array_unique($fingerprints))->toHaveCount(12);
    });

    it('covers a participant by its id and nothing else of it', function (): void {
        $before = new RepackOutcome([], [], [new LateStart(new Participant('p1', 'Before'), 0, 1)]);
        $relabelled = new RepackOutcome([], [], [new LateStart(new Participant('p1', 'After', 3, ['region' => 'north']), 0, 1)]);
        $renamed = new RepackOutcome([], [], [new LateStart(new Participant('p2', 'Before'), 0, 1)]);

        expect($relabelled->fingerprint())->toBe($before->fingerprint());
        expect($renamed->fingerprint())->not->toBe($before->fingerprint());
    });
});

describe('Repacking onto a shape-only grid', function (): void {
    it('gives every event the position the instant-based grid of that shape gives it', function (int $seed): void {
        [$movable, $pinned, $grid] = randomRepackInstance($seed);
        $shape = regridded($grid, $grid->getCapacityPerSlot(), shapeOnly: true);
        $repacker = new ScheduleRepacker();

        $timed = $repacker->repack(new RepackRequest($movable, $pinned, $grid));
        $shaped = $repacker->repack(new RepackRequest($movable, $pinned, $shape));

        $positions = static fn(RepackOutcome $outcome): array => array_map(
            static fn(SlotAssignment $a): array => [$a->getEventId(), $a->getSession(), $a->getSlot()],
            $outcome->getAssignments()
        );

        expect($positions($shaped))->toBe($positions($timed));
        expect($shaped->toArray()['unplaced'])->toBe($timed->toArray()['unplaced']);
        expect($shaped->toArray()['violations'])->toBe($timed->toArray()['violations']);
        expect($shaped->isBudgetExhausted())->toBe($timed->isBudgetExhausted());

        // The one difference: no kickoff, stated as none
        expect($timed->getAssignments())->not->toBe([]);
        foreach ($timed->getAssignments() as $assignment) {
            expect($assignment->hasKickoff())->toBeTrue();
        }
        foreach ($shaped->getAssignments() as $assignment) {
            expect($assignment->hasKickoff())->toBeFalse();
            expect($assignment->toArray()['kickoff'])->toBeNull();
        }
        expect($shaped->fingerprint())->not->toBe($timed->fingerprint());
    })->with(range(1, 12));

    it('checks pins against the shape as it does against the instants', function (): void {
        $a = new Participant('a', 'A');
        $b = new Participant('b', 'B');

        expect(fn() => new RepackRequest([], [new PinnedEvent('x1', $a, $b, 2, 0)], SessionGrid::shapeOnly(2, 3)))
            ->toThrow(MissionGaming\Tactician\Exceptions\InvalidConfigurationException::class, 'A pinned event must sit on a grid position');
    });
});

describe('Repacking with unbounded capacity', function (): void {
    it('never reports the grid as too small, and never double-books', function (int $seed, bool $shapeOnly): void {
        [$movable, $pinned, $grid] = randomRepackInstance($seed);
        $unbounded = regridded($grid, null, $shapeOnly);

        $outcome = (new ScheduleRepacker())->repack(new RepackRequest($movable, $pinned, $unbounded));

        // A CapacityExceeded with no participant is the grid's capacity
        // running out, which unbounded capacity cannot do. One that names
        // a participant is that participant having more events than free
        // positions, which no capacity changes.
        foreach ($outcome->getCapacityExceededViolations() as $violation) {
            expect($violation->getParticipant())->not->toBeNull();
        }

        assertProperness($outcome, $movable, $pinned);
        expect(count($outcome->getAssignments()) + count($outcome->getUnplaced()))->toBe(count($movable));
        expect(count($outcome->getEventUnplacedViolations()))->toBe(count($outcome->getUnplaced()));
    })->with(range(1, 12))->with([false, true]);

    it('is every capacity too large to matter', function (int $seed): void {
        [$movable, $pinned, $grid] = randomRepackInstance($seed);
        $repacker = new ScheduleRepacker();
        $events = count($movable) + count($pinned);

        $unbounded = $repacker->repack(new RepackRequest($movable, $pinned, regridded($grid, null, false)));

        // No slot can hold more than every event of the request
        foreach ([$events, $events + 1, $events * 50] as $capacity) {
            $finite = $repacker->repack(new RepackRequest($movable, $pinned, regridded($grid, $capacity, false)));

            expect($finite->toArray())->toBe($unbounded->toArray());
            expect($finite->fingerprint())->toBe($unbounded->fingerprint());
        }
    })->with(range(1, 12));

    it('places what the default capacity of 1 reports as over capacity', function (): void {
        // Four events with no participant in common, one position
        $movable = [];
        for ($i = 0; $i < 4; ++$i) {
            $movable[] = new MovableEvent("e{$i}", new Participant("a{$i}", "A{$i}"), new Participant("b{$i}", "B{$i}"));
        }
        $repacker = new ScheduleRepacker();

        $one = $repacker->repack(new RepackRequest($movable, [], SessionGrid::shapeOnly(1)));
        expect($one->getAssignments())->toHaveCount(1);
        expect($one->getCapacityExceededViolations())->toHaveCount(1);
        expect($one->getCapacityExceededViolations()[0]->getParticipant())->toBeNull();
        expect($one->getCapacityExceededViolations()[0]->getShortfall())->toBe(3);

        $unbounded = $repacker->repack(new RepackRequest($movable, [], SessionGrid::shapeOnly(1, capacityPerSlot: null)));
        expect($unbounded->isClean())->toBeTrue();
        expect($unbounded->getAssignments())->toHaveCount(4);
    });

    it('still reports a participant with more events than positions', function (): void {
        $a = new Participant('a', 'A');
        $movable = [
            new MovableEvent('e1', $a, new Participant('b', 'B')),
            new MovableEvent('e2', $a, new Participant('c', 'C')),
        ];

        $outcome = (new ScheduleRepacker())->repack(new RepackRequest($movable, [], SessionGrid::shapeOnly(1, capacityPerSlot: null)));

        expect($outcome->getAssignments())->toHaveCount(1);
        expect($outcome->getCapacityExceededViolations())->toHaveCount(1);
        expect($outcome->getCapacityExceededViolations()[0]->getParticipant()?->getId())->toBe('a');
        expect($outcome->getCapacityExceededViolations()[0]->getDemand())->toBe(2);
        expect($outcome->getCapacityExceededViolations()[0]->getCapacity())->toBe(1);
    });

    it('leaves an event unplaced, with no capacity violation, when only its participants are in the way', function (): void {
        // Three participants, each meeting both others, two positions: a
        // position holds one of the three events, whatever the capacity
        $a = new Participant('a', 'A');
        $b = new Participant('b', 'B');
        $c = new Participant('c', 'C');
        $movable = [new MovableEvent('ab', $a, $b), new MovableEvent('ac', $a, $c), new MovableEvent('bc', $b, $c)];

        $outcome = (new ScheduleRepacker())->repack(new RepackRequest($movable, [], SessionGrid::shapeOnly(1, 2, [], null)));

        expect($outcome->getAssignments())->toHaveCount(2);
        expect($outcome->getUnplaced())->toHaveCount(1);
        expect($outcome->getUnplaced()[0]->getReason())->toBe(UnplacedReason::NoSlotAvailable);
        expect($outcome->getCapacityExceededViolations())->toBe([]);
        assertProperness($outcome, $movable, []);
    });

    it('accepts any number of pins at one position', function (): void {
        $pinned = [];
        for ($i = 0; $i < 6; ++$i) {
            $pinned[] = new PinnedEvent("x{$i}", new Participant("a{$i}", "A{$i}"), new Participant("b{$i}", "B{$i}"), 0, 0);
        }
        $movable = [new MovableEvent('e1', new Participant('y', 'Y'), new Participant('z', 'Z'))];

        $outcome = (new ScheduleRepacker())->repack(new RepackRequest($movable, $pinned, SessionGrid::shapeOnly(1, capacityPerSlot: null)));

        expect($outcome->isClean())->toBeTrue();
        expect($outcome->getAssignmentFor('e1')?->getSlot())->toBe(0);
    });
});

describe('Repack step budget flag', function (): void {
    it('is false when the budget stopped nothing, and then a larger budget changes nothing', function (int $seed): void {
        [$movable, $pinned, $grid] = randomRepackInstance($seed);
        $repacker = new ScheduleRepacker();
        $run = static fn(int $budget): RepackOutcome => $repacker->repack(
            new RepackRequest($movable, $pinned, $grid, new RepackOptions(stepBudget: $budget))
        );

        $ample = $run(PHP_INT_MAX);
        expect($ample->isBudgetExhausted())->toBeFalse();

        foreach ([1, 3, 10, 30, 100, 1_000, 10_000, 200_000] as $budget) {
            $outcome = $run($budget);

            if (!$outcome->isBudgetExhausted()) {
                // What the docblock promises of a false flag
                expect($outcome->toArray())->toBe($ample->toArray());
                expect($run($budget * 7)->toArray())->toBe($ample->toArray());
            }

            // Whatever the budget, the hard guarantees hold
            assertProperness($outcome, $movable, $pinned);
            expect(count($outcome->getAssignments()) + count($outcome->getUnplaced()))->toBe(count($movable));
        }

        // A single step cannot carry any search of these instances through
        expect($run(1)->isBudgetExhausted())->toBeTrue();
    })->with(range(1, 12));

    it('is true for some outcome that a larger budget would have changed', function (): void {
        // Without this the property above could hold with the flag stuck on
        $changed = 0;
        foreach (range(1, 12) as $seed) {
            [$movable, $pinned, $grid] = randomRepackInstance($seed);
            $repacker = new ScheduleRepacker();
            $ample = $repacker->repack(new RepackRequest($movable, $pinned, $grid));
            $starved = $repacker->repack(new RepackRequest($movable, $pinned, $grid, new RepackOptions(stepBudget: 1)));

            expect($starved->isBudgetExhausted())->toBeTrue();
            if ($starved->fingerprint() !== $ample->fingerprint()) {
                ++$changed;
            }
        }

        expect($changed)->toBeGreaterThan(0);
    });

    it('is carried by the exception when the caller asked for one', function (): void {
        // K5 cannot pack clean, and one step stops its search
        $participants = [];
        for ($i = 1; $i <= 5; ++$i) {
            $participants[] = new Participant("p{$i}", "P{$i}");
        }
        $movable = [];
        for ($a = 0; $a < 5; ++$a) {
            for ($b = $a + 1; $b < 5; ++$b) {
                $movable[] = new MovableEvent(sprintf('e%d%d', $a, $b), $participants[$a], $participants[$b]);
            }
        }

        try {
            (new ScheduleRepacker())->repack(new RepackRequest(
                $movable,
                [],
                SessionGrid::shapeOnly(1, 5, [], 2),
                new RepackOptions(stepBudget: 1, throwOnViolations: true)
            ));
            expect(false)->toBeTrue();
        } catch (RepackViolationsException $exception) {
            expect($exception->getOutcome()->isBudgetExhausted())->toBeTrue();
        }
    });

    it('is false for an outcome the last placement step changed only when a larger budget changes nothing', function (string $name): void {
        [$movable, $pinned, $grid] = smallRepackCorpus()[$name];
        $repacker = new ScheduleRepacker();
        $run = static fn(int $budget): RepackOutcome => $repacker->repack(
            new RepackRequest($movable, $pinned, $grid, new RepackOptions(stepBudget: $budget))
        );

        $ample = $run(PHP_INT_MAX);
        expect($ample->isBudgetExhausted())->toBeFalse();

        foreach ([1, 2, 5, 20, 100, 1_000, 200_000] as $budget) {
            $outcome = $run($budget);
            if (!$outcome->isBudgetExhausted()) {
                expect($outcome->toArray())->toBe($ample->toArray());
            }
            assertProperness($outcome, $movable, $pinned);
            expect(count($outcome->getAssignments()) + count($outcome->getUnplaced()))->toBe(count($movable));
        }
    })->with(array_slice(array_keys(smallRepackCorpus()), 0, 40));

    it('gives the last placement step a budget of its own, so a run whose searches spent theirs still places every event', function (): void {
        // A complete round robin of 24 on six four-slot sessions: the
        // packing searches spend the whole default budget, and 26 events
        // were left unplaced before the last placement step existed
        $outcome = (new ScheduleRepacker())->repack(completeGraphRequest(24, 'from one'));

        expect($outcome->isBudgetExhausted())->toBeTrue()
            ->and($outcome->getUnplaced())->toBe([])
            ->and($outcome->getAssignments())->toHaveCount(276);
    });

    it('spends no budget on the last placement step when every group of participants already fills the positions it can', function (): void {
        // Eight separate single round robins of five participants on four
        // one-slot sessions. A position holds at most two events of a group
        // of five, so 8 of each group's 10 events is the most there is, and
        // the earlier phases place exactly that. Searching for an exchange
        // anyway spent the whole default budget, and the outcome said the
        // budget had stopped a search although no budget could place more
        $participants = [];
        for ($i = 1; $i <= 40; ++$i) {
            $participants[$i] = new Participant("p{$i}", "P{$i}");
        }
        $movable = [];
        for ($group = 0; $group < 8; ++$group) {
            for ($i = 1; $i <= 5; ++$i) {
                for ($j = $i + 1; $j <= 5; ++$j) {
                    $movable[] = new MovableEvent(
                        sprintf('g%d-%d%d', $group, $i, $j),
                        $participants[$group * 5 + $i],
                        $participants[$group * 5 + $j]
                    );
                }
            }
        }

        $outcome = (new ScheduleRepacker())->repack(new RepackRequest($movable, [], SessionGrid::shapeOnly(4, 1, [], null)));

        expect($outcome->getAssignments())->toHaveCount(64)
            ->and($outcome->getUnplaced())->toHaveCount(16)
            ->and($outcome->isBudgetExhausted())->toBeFalse();
    });

    it('spends no budget on the last placement step when only the over-capacity drops are left over', function (): void {
        // p1 has seven events for five slots, so two of them cannot be
        // placed, and every other event is. Placing a dropped event would
        // only leave out another of p1's, so there is nothing to search
        // for, and a budget of 50 that the earlier phases do not use up
        // must not be reported as having stopped one
        $p = [];
        for ($i = 1; $i <= 6; ++$i) {
            $p[$i] = new Participant("p{$i}", "P{$i}");
        }
        $movable = [
            new MovableEvent('m01', $p[1], $p[3]),
            new MovableEvent('m02', $p[1], $p[6]),
            new MovableEvent('m03', $p[2], $p[4]),
            new MovableEvent('m04', $p[2], $p[5]),
            new MovableEvent('m05', $p[2], $p[1]),
            new MovableEvent('m06', $p[6], $p[1]),
            new MovableEvent('m07', $p[1], $p[3]),
            new MovableEvent('m08', $p[6], $p[1]),
            new MovableEvent('m09', $p[6], $p[4]),
            new MovableEvent('m10', $p[4], $p[1]),
        ];
        $pinned = [new PinnedEvent('x1', $p[5], $p[4], 0, 1)];

        $outcome = (new ScheduleRepacker())->repack(new RepackRequest(
            $movable,
            $pinned,
            SessionGrid::shapeOnly(1, 5, [], 4),
            new RepackOptions(stepBudget: 50)
        ));

        expect($outcome->getAssignments())->toHaveCount(8)
            ->and(array_map(static fn(UnplacedEvent $u): string => $u->getReason()->value, $outcome->getUnplaced()))
            ->toBe(['participant_over_capacity', 'participant_over_capacity'])
            ->and($outcome->isBudgetExhausted())->toBeFalse();
    });
});

/**
 * A single round robin of n participants on ceil(n / 4) sessions of four
 * slots with capacity n / 2: every participant has at least n slots for
 * its n - 1 events, and no slot can hold more than the n / 2 events a
 * perfect matching has, so a placement of every event exists (an even
 * round robin decomposes into n - 1 perfect matchings).
 *
 * Event ids are given in pair order: "1".."E" ('from one'), "1000".. ('from
 * a thousand'), or a non-numeric id whose byte order is unrelated to the
 * pair order ('scrambled').
 *
 * @throws MissionGaming\Tactician\Exceptions\InvalidConfigurationException
 */
function completeGraphRequest(int $n, string $idFormat): RepackRequest
{
    $participants = [];
    for ($i = 1; $i <= $n; ++$i) {
        $participants[$i] = new Participant((string) $i, "P{$i}");
    }

    $movable = [];
    $k = 0;
    for ($i = 1; $i <= $n; ++$i) {
        for ($j = $i + 1; $j <= $n; ++$j) {
            ++$k;
            $id = match ($idFormat) {
                'from one' => (string) $k,
                'from a thousand' => (string) (999 + $k),
                'scrambled' => sprintf('m-%s', dechex($k * 7919 % 10007)),
                default => throw new LogicException("Unknown id format {$idFormat}"),
            };
            $movable[] = new MovableEvent($id, $participants[$i], $participants[$j]);
        }
    }

    return new RepackRequest($movable, [], SessionGrid::shapeOnly(intdiv($n + 3, 4), 4, [], intdiv($n, 2)));
}

/**
 * A random request small enough for RepackBruteForce: three to six
 * participants, one to three sessions of one to three slots, a capacity
 * of 1, 2, 3 or unbounded, three to nine events (repeated pairings
 * included) and up to two legal pins.
 *
 * @return array{0: array<MovableEvent>, 1: array<PinnedEvent>, 2: SessionGrid}
 *
 * @throws MissionGaming\Tactician\Exceptions\InvalidConfigurationException
 * @throws Random\RandomException
 */
function tinyRepackInstance(int $seed): array
{
    $rng = new Randomizer(new Mt19937($seed));
    $participantCount = $rng->getInt(3, 6);
    $participants = [];
    for ($i = 1; $i <= $participantCount; ++$i) {
        $participants[] = new Participant("p{$i}", "P{$i}");
    }
    $sessions = $rng->getInt(1, 3);
    $slots = $rng->getInt(1, 3);
    $capacityChoice = $rng->getInt(0, 3);
    $capacity = $capacityChoice === 0 ? null : $capacityChoice;
    $grid = SessionGrid::shapeOnly($sessions, $slots, [], $capacity);

    $movable = [];
    $eventCount = $rng->getInt(3, 9);
    for ($i = 1; $i <= $eventCount; ++$i) {
        $a = $rng->getInt(0, $participantCount - 1);
        $b = $rng->getInt(0, $participantCount - 2);
        if ($b >= $a) {
            ++$b;
        }
        $movable[] = new MovableEvent(sprintf('m%02d', $i), $participants[$a], $participants[$b]);
    }

    $pinned = [];
    $pinnedAt = [];
    $countAt = [];
    $attempts = $rng->getInt(0, 2);
    for ($i = 1; $i <= $attempts; ++$i) {
        $a = $rng->getInt(0, $participantCount - 1);
        $b = $rng->getInt(0, $participantCount - 2);
        if ($b >= $a) {
            ++$b;
        }
        $session = $rng->getInt(0, $sessions - 1);
        $slot = $rng->getInt(0, $slots - 1);
        $position = "{$session}:{$slot}";
        if ($capacity !== null && ($countAt[$position] ?? 0) >= $capacity) {
            continue;
        }
        if (isset($pinnedAt["{$position}:{$a}"]) || isset($pinnedAt["{$position}:{$b}"])) {
            continue;
        }
        $pinned[] = new PinnedEvent("x{$i}", $participants[$a], $participants[$b], $session, $slot);
        $pinnedAt["{$position}:{$a}"] = true;
        $pinnedAt["{$position}:{$b}"] = true;
        $countAt[$position] = ($countAt[$position] ?? 0) + 1;
    }

    return [$movable, $pinned, $grid];
}

/**
 * The corpus the repacker is compared with RepackBruteForce on: named
 * instances for the cases the issue reported (one-slot sessions, the drop
 * rule, under-reported demand, pins and capacity limits), then 300 random
 * tiny ones. Of the random ones, 458 of the first 3,000 seeds came out
 * below the optimum before the last placement step and the drop rule
 * were changed; the first 300 hold 40 of them.
 *
 * @return array<string, array{0: array<MovableEvent>, 1: array<PinnedEvent>, 2: SessionGrid}>
 *
 * @throws MissionGaming\Tactician\Exceptions\InvalidConfigurationException
 * @throws Random\RandomException
 */
function smallRepackCorpus(): array
{
    static $corpus = null;
    if ($corpus !== null) {
        return $corpus;
    }

    $p = [];
    for ($i = 1; $i <= 6; ++$i) {
        $p[$i] = new Participant("p{$i}", "P{$i}");
    }

    $k6 = [];
    for ($i = 1; $i <= 6; ++$i) {
        for ($j = $i + 1; $j <= 6; ++$j) {
            $k6[] = new MovableEvent("k{$i}{$j}", $p[$i], $p[$j]);
        }
    }

    $corpus = [
        // Fifteen events, six one-slot sessions: the standard rounds fit
        'complete round robin of six on one-slot sessions' => [$k6, [], SessionGrid::shapeOnly(6, 1, [], 3)],
        // One slot: p3 and p5 each have one event too many, and they share one
        'two over-capacity participants sharing an event' => [
            [new MovableEvent('e1', $p[3], $p[5]), new MovableEvent('e2', $p[5], $p[6]), new MovableEvent('e3', $p[3], $p[2])],
            [],
            SessionGrid::shapeOnly(1, 1, [], 3),
        ],
        // p1 and p2 are each two over, and two of their events are shared
        'two over-capacity participants sharing two events' => [
            [
                new MovableEvent('e1', $p[1], $p[2]),
                new MovableEvent('e2', $p[1], $p[2]),
                new MovableEvent('e3', $p[1], $p[3]),
                new MovableEvent('e4', $p[2], $p[4]),
            ],
            [],
            SessionGrid::shapeOnly(1, 1, [], null),
        ],
        // Which over-capacity events go decides whether the rest fit: p4 is
        // pinned at two of three slots, and the pins hold capacity
        'over-capacity drops that decide what fits' => [
            [
                new MovableEvent('m01', $p[2], $p[5]),
                new MovableEvent('m02', $p[2], $p[4]),
                new MovableEvent('m03', $p[4], $p[3]),
                new MovableEvent('m04', $p[2], $p[4]),
            ],
            [new PinnedEvent('x1', $p[4], $p[5], 0, 2), new PinnedEvent('x2', $p[2], $p[4], 0, 1)],
            SessionGrid::shapeOnly(1, 3, [], 2),
        ],
        // Capacity 1 on one-slot sessions: every position holds one event
        'one event per position' => [
            [
                new MovableEvent('e1', $p[1], $p[2]),
                new MovableEvent('e2', $p[3], $p[4]),
                new MovableEvent('e3', $p[1], $p[3]),
                new MovableEvent('e4', $p[2], $p[4]),
                new MovableEvent('e5', $p[1], $p[4]),
            ],
            [new PinnedEvent('x1', $p[5], $p[6], 1, 0)],
            SessionGrid::shapeOnly(4, 1, [], 1),
        ],
    ];

    for ($seed = 1; $seed <= 300; ++$seed) {
        $corpus["random instance {$seed}"] = tinyRepackInstance($seed);
    }

    return $corpus;
}

describe('Repacking every placeable event', function (): void {
    it('places every event of a complete round robin that fits the grid', function (int $n, string $idFormat): void {
        $request = completeGraphRequest($n, $idFormat);

        $outcome = (new ScheduleRepacker())->repack($request);

        expect($outcome->getUnplaced())->toBe([])
            ->and($outcome->getAssignments())->toHaveCount(intdiv($n * ($n - 1), 2))
            ->and($outcome->getCapacityExceededViolations())->toBe([]);
        assertProperness($outcome, $request->getMovableEvents(), []);

        // No slot holds more than the capacity
        $perSlot = [];
        foreach ($outcome->getAssignments() as $assignment) {
            $key = "{$assignment->getSession()}:{$assignment->getSlot()}";
            $perSlot[$key] = ($perSlot[$key] ?? 0) + 1;
        }
        foreach ($perSlot as $count) {
            expect($count)->toBeLessThanOrEqual(intdiv($n, 2));
        }
    })->with(range(6, 40, 2))->with(['from one', 'from a thousand', 'scrambled']);

    it('places as many events as the best placement there is', function (string $name): void {
        [$movable, $pinned, $grid] = smallRepackCorpus()[$name];

        $outcome = (new ScheduleRepacker())->repack(new RepackRequest($movable, $pinned, $grid));

        expect(count($outcome->getAssignments()))->toBe(RepackBruteForce::maxPlaced($movable, $pinned, $grid));
        assertProperness($outcome, $movable, $pinned);
        assertNoSlotAvailableIsLiteral($outcome, $movable, $pinned, $grid);
        expect(count($outcome->getAssignments()) + count($outcome->getUnplaced()))->toBe(count($movable))
            ->and(count($outcome->getEventUnplacedViolations()))->toBe(count($outcome->getUnplaced()));

        // Pins hold capacity: no position holds more than the grid allows
        $capacity = $grid->getCapacityLimit();
        if ($capacity !== null) {
            $perPosition = [];
            foreach ($pinned as $pin) {
                $key = "{$pin->getSession()}:{$pin->getSlot()}";
                $perPosition[$key] = ($perPosition[$key] ?? 0) + 1;
            }
            foreach ($outcome->getAssignments() as $assignment) {
                $key = "{$assignment->getSession()}:{$assignment->getSlot()}";
                $perPosition[$key] = ($perPosition[$key] ?? 0) + 1;
            }
            expect($perPosition === [] ? 0 : max($perPosition))->toBeLessThanOrEqual($capacity);
        }

        // Every over-capacity participant has at least its shortfall of
        // events unplaced as over capacity, each naming it or the other
        // over-capacity participant of the event
        $overCapacity = [];
        foreach ($outcome->getCapacityExceededViolations() as $violation) {
            if ($violation->getParticipant() !== null) {
                $overCapacity[$violation->getParticipant()->getId()] = $violation->getShortfall();
            }
        }
        $participantsOf = [];
        foreach ($movable as $event) {
            $participantsOf[$event->getId()] = [$event->getParticipantA()->getId(), $event->getParticipantB()->getId()];
        }
        $covered = [];
        foreach ($outcome->getUnplaced() as $unplaced) {
            if ($unplaced->getReason() !== UnplacedReason::ParticipantOverCapacity) {
                continue;
            }
            $named = $unplaced->getParticipant()?->getId();
            expect($overCapacity)->toHaveKey((string) $named)
                ->and($participantsOf[$unplaced->getEventId()])->toContain($named);
            foreach ($participantsOf[$unplaced->getEventId()] as $id) {
                $covered[$id] = ($covered[$id] ?? 0) + 1;
            }
        }
        foreach ($overCapacity as $id => $shortfall) {
            expect($covered[$id] ?? 0)->toBeGreaterThanOrEqual($shortfall);
        }
    })->with(array_keys(smallRepackCorpus()));

    it('drops the event two over-capacity participants share before any other', function (): void {
        // One slot of capacity 3. p3 plays p5 and p2, p5 plays p3 and p6:
        // each has two events and one position, and one event between
        // them fixes both, leaving p5 v p6 and p3 v p2 to share the slot
        $p2 = new Participant('p2', 'P2');
        $p3 = new Participant('p3', 'P3');
        $p5 = new Participant('p5', 'P5');
        $p6 = new Participant('p6', 'P6');
        $movable = [new MovableEvent('e1', $p3, $p5), new MovableEvent('e2', $p5, $p6), new MovableEvent('e3', $p3, $p2)];

        $outcome = (new ScheduleRepacker())->repack(new RepackRequest($movable, [], SessionGrid::shapeOnly(1, 1, [], 3)));

        expect(array_map(static fn(SlotAssignment $a): string => $a->getEventId(), $outcome->getAssignments()))->toBe(['e2', 'e3'])
            ->and(array_map(static fn(UnplacedEvent $u): array => $u->toArray(), $outcome->getUnplaced()))->toBe([
                ['event_id' => 'e1', 'reason' => 'participant_over_capacity', 'participant' => 'p3'],
            ])
            ->and(array_map(static fn(CapacityExceeded $v): array => $v->toArray(), $outcome->getCapacityExceededViolations()))->toBe([
                ['kind' => 'capacity_exceeded', 'participant' => 'p3', 'demand' => 2, 'capacity' => 1, 'shortfall' => 1],
                ['kind' => 'capacity_exceeded', 'participant' => 'p5', 'demand' => 2, 'capacity' => 1, 'shortfall' => 1],
            ]);
    });

    it('reports an over-capacity participant with its demand before any event was dropped', function (): void {
        // One slot, no capacity limit. p1 plays p2 twice and p3 once, p2
        // plays p1 twice and p4 once: three events each for one position.
        // Dropping p1 v p3 and one p1 v p2 first, as the earlier rule did,
        // made p2's demand look like two
        $p1 = new Participant('p1', 'P1');
        $p2 = new Participant('p2', 'P2');
        $p3 = new Participant('p3', 'P3');
        $p4 = new Participant('p4', 'P4');
        $movable = [
            new MovableEvent('e1', $p1, $p2),
            new MovableEvent('e2', $p1, $p2),
            new MovableEvent('e3', $p1, $p3),
            new MovableEvent('e4', $p2, $p4),
        ];

        $outcome = (new ScheduleRepacker())->repack(new RepackRequest($movable, [], SessionGrid::shapeOnly(1, 1, [], null)));

        expect(array_map(static fn(CapacityExceeded $v): array => $v->toArray(), $outcome->getCapacityExceededViolations()))->toBe([
            ['kind' => 'capacity_exceeded', 'participant' => 'p1', 'demand' => 3, 'capacity' => 1, 'shortfall' => 2],
            ['kind' => 'capacity_exceeded', 'participant' => 'p2', 'demand' => 3, 'capacity' => 1, 'shortfall' => 2],
        ]);
        // The two events they share are the two dropped, and both others fit
        expect(array_map(static fn(SlotAssignment $a): string => $a->getEventId(), $outcome->getAssignments()))->toBe(['e3', 'e4']);
    });

    it('reports an over-capacity participant that dropping another participant\'s events would have fixed', function (): void {
        // Two slots; p1 is pinned at the second, so it has one free
        // position for three events, and p2 has two for three. The events
        // p1 must lose include one against p2, which fixes p2 as well; the
        // earlier rule then left p2 out of the report
        $p1 = new Participant('p1', 'P1');
        $p2 = new Participant('p2', 'P2');
        $p3 = new Participant('p3', 'P3');
        $p4 = new Participant('p4', 'P4');
        $p5 = new Participant('p5', 'P5');
        $movable = [
            new MovableEvent('e1', $p1, $p2),
            new MovableEvent('e2', $p1, $p2),
            new MovableEvent('e3', $p1, $p3),
            new MovableEvent('e4', $p2, $p4),
        ];
        $pinned = [new PinnedEvent('x1', $p1, $p5, 0, 1)];

        $outcome = (new ScheduleRepacker())->repack(new RepackRequest($movable, $pinned, SessionGrid::shapeOnly(1, 2, [], null)));

        expect(array_map(static fn(CapacityExceeded $v): array => $v->toArray(), $outcome->getCapacityExceededViolations()))->toBe([
            ['kind' => 'capacity_exceeded', 'participant' => 'p1', 'demand' => 3, 'capacity' => 1, 'shortfall' => 2],
            ['kind' => 'capacity_exceeded', 'participant' => 'p2', 'demand' => 3, 'capacity' => 2, 'shortfall' => 1],
        ]);
        expect($outcome->getAssignments())->toHaveCount(2);
    });
});
