<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\RepackViolationsException;
use MissionGaming\Tactician\Repack\ContiguityBroken;
use MissionGaming\Tactician\Repack\LateStart;
use MissionGaming\Tactician\Repack\MovableEvent;
use MissionGaming\Tactician\Repack\PinnedEvent;
use MissionGaming\Tactician\Repack\RepackOptions;
use MissionGaming\Tactician\Repack\RepackOutcome;
use MissionGaming\Tactician\Repack\RepackRequest;
use MissionGaming\Tactician\Repack\ScheduleRepacker;
use MissionGaming\Tactician\Repack\SessionGrid;
use MissionGaming\Tactician\Repack\UnplacedReason;
use MissionGaming\Tactician\Repack\ViolationKind;
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
                $freePositionExists = ($occupancy[$position] ?? 0) < $grid->getCapacityPerSlot()
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
            static fn (MovableEvent $event): string => $event->getId(),
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
