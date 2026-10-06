<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Repack\MovableEvent;
use MissionGaming\Tactician\Repack\PinnedEvent;
use MissionGaming\Tactician\Repack\RepackOptions;
use MissionGaming\Tactician\Repack\RepackOutcome;
use MissionGaming\Tactician\Repack\RepackRequest;
use MissionGaming\Tactician\Repack\ScheduleRepacker;
use MissionGaming\Tactician\Repack\SessionGrid;
use MissionGaming\Tactician\Repack\UnplacedReason;

// An event that the first pass over the sessions leaves without one.
//
// The repacker gives each event a session in turn, the best one that still
// has room. An event can come up when every session it may use is full,
// although the grid as a whole has room for it: an earlier event sits where
// it has to go and could have gone elsewhere. The repacker then moves one
// such event to another session and places the blocked one: a single move,
// deeper repair being left to the later phases. No other test reached that
// step.
//
// The instance, through the public API. Two sessions, two events per slot:
//
//   session 0: one slot, holding a pinned event of C.     One place left.
//   session 1: two slots. Slot 0 is full (a pinned event
//              of A and one between outsiders); slot 1
//              holds one pinned event between outsiders.  One place left.
//
// Two movable events: e0 is A against B, e1 is C against D. C is busy in
// the only slot of session 0, so e1 can only go to session 1. e0 may go to
// either session and is placed first; consolidation draws it to session 1,
// where A already plays, and it takes the last place there. e1 is then
// blocked, and one move frees it: e0 to session 0.

/**
 * @param bool $bIsPinnedWithC Whether the pinned event of C in session 0 is against B, which keeps e0 out of session 0
 * @return array{list<MovableEvent>, list<PinnedEvent>, SessionGrid}
 * @throws InvalidConfigurationException When the instance is not a valid request (it is)
 */
function relocationInstance(bool $bIsPinnedWithC = false): array
{
    $participant = static fn(string $id): Participant => new Participant($id, strtoupper($id));
    [$a, $b, $c, $d] = array_map($participant, ['a', 'b', 'c', 'd']);
    [$o1, $o2, $o3, $o4, $o5, $o6] = array_map($participant, ['o1', 'o2', 'o3', 'o4', 'o5', 'o6']);

    return [
        [new MovableEvent('e0', $a, $b), new MovableEvent('e1', $c, $d)],
        [
            new PinnedEvent('x1', $c, $bIsPinnedWithC ? $b : $o1, 0, 0),
            new PinnedEvent('x2', $a, $o2, 1, 0),
            new PinnedEvent('x3', $o3, $o4, 1, 0),
            new PinnedEvent('x4', $o5, $o6, 1, 1),
        ],
        SessionGrid::shapeOnly(2, 1, [1 => 2], 2),
    ];
}

/**
 * Where each movable event ended up: "session.slot", or the reason it has
 * no position.
 *
 * @return array<string, string>
 */
function relocationPositions(RepackOutcome $outcome): array
{
    $positions = [];
    foreach ($outcome->getAssignments() as $assignment) {
        $positions[$assignment->getEventId()] = $assignment->getSession() . '.' . $assignment->getSlot();
    }
    foreach ($outcome->getUnplaced() as $unplaced) {
        $positions[$unplaced->getEventId()] = $unplaced->getReason()->name;
    }
    ksort($positions);

    return $positions;
}

describe('an event blocked by one that could be elsewhere', function (): void {
    it('is placed by moving the other event to a session that has room', function (): void {
        [$movable, $pinned, $grid] = relocationInstance();

        $outcome = (new ScheduleRepacker())->repack(new RepackRequest($movable, $pinned, $grid));

        // Both are placed, each in the only place the instance leaves it:
        // slot 0 of session 1 is full, so e1 is in slot 1
        expect(relocationPositions($outcome))->toBe(['e0' => '0.0', 'e1' => '1.1'])
            ->and($outcome->getUnplaced())->toBe([])
            ->and($outcome->isBudgetExhausted())->toBeFalse();
    });

    // B is now busy in session 0 as well, so both events can only go to
    // session 1, which has one place: one of the two has no position, and
    // moving the other does not help.
    it('is reported as unplaced when the other event has nowhere else to go', function (): void {
        [$movable, $pinned, $grid] = relocationInstance(bIsPinnedWithC: true);

        $outcome = (new ScheduleRepacker())->repack(new RepackRequest($movable, $pinned, $grid));
        $positions = relocationPositions($outcome);

        expect($positions)->toHaveCount(2);
        expect(array_values(array_unique($positions)))->toEqualCanonicalizing(['1.1', UnplacedReason::NoSlotAvailable->name]);
        expect($outcome->getUnplaced())->toHaveCount(1)
            ->and($outcome->getEventUnplacedViolations())->toHaveCount(1)
            ->and($outcome->isBudgetExhausted())->toBeFalse();
    });

    // The search for a move spends steps of the shared budget. With one
    // step it stops before it finds the move, and says so; the outcome is
    // still a proper one, with the blocked event reported.
    it('is reported as unplaced, with the budget flag, when the step budget ends the search for a move', function (): void {
        [$movable, $pinned, $grid] = relocationInstance();

        $outcome = (new ScheduleRepacker())->repack(
            new RepackRequest($movable, $pinned, $grid, new RepackOptions(stepBudget: 1))
        );

        expect($outcome->isBudgetExhausted())->toBeTrue()
            ->and(relocationPositions($outcome))->toBe(['e0' => '1.1', 'e1' => UnplacedReason::NoSlotAvailable->name]);
    });
});
