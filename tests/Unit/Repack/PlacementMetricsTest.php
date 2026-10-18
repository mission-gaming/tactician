<?php

declare(strict_types=1);

use MissionGaming\Tactician\Repack\Internal\PlacementMetrics;
use MissionGaming\Tactician\Repack\RepackObjective;

describe('PlacementMetrics', function (): void {
    it('counts the gap slots of a participant in a session, pins included, where it has a movable event there', function (): void {
        // Participant 0: a movable event at slot 0 and a pin at slot 3 of
        // session 0, so slots 1 and 2 are a gap. Participant 1 has the same
        // slots in session 1 with pins only, which is no gap of the repack's
        $metrics = new PlacementMetrics(
            [0 => [0, 2]],
            [[[0, 3], 0, 3], [[1, 4], 1, 0], [[1, 5], 1, 3]],
            []
        );

        expect($metrics->gapSlots([0 => [0, 0]]))->toBe(2)
            ->and($metrics->gaps([0 => [0, 0]]))->toBe([0 => [0 => 2]])
            ->and($metrics->gapSlots([]))->toBe(0);
    });

    it('counts a gap once for each session it is in', function (): void {
        $metrics = new PlacementMetrics([0 => [0, 1], 1 => [0, 2], 2 => [0, 1], 3 => [0, 2]], [], []);
        $positions = [0 => [0, 0], 1 => [0, 2], 2 => [1, 0], 3 => [1, 3]];

        // Participant 0 waits one slot in session 0 and two in session 1
        expect($metrics->gaps($positions))->toBe([0 => [0 => 1, 1 => 2]])
            ->and($metrics->gapSlots($positions))->toBe(3);
    });

    it('lists the sessions that hold one event, and that one movable', function (): void {
        // Session 0: one pin. Session 1: one movable event. Session 2: a
        // movable event and a pin. Session 3: two movable events. Session 4
        // is empty. Session 5: one movable event
        $metrics = new PlacementMetrics(
            [0 => [0, 1], 1 => [2, 3], 2 => [4, 5], 3 => [6, 7], 4 => [0, 1]],
            [[[8, 9], 0, 0], [[8, 9], 2, 1]],
            []
        );

        expect($metrics->loneEventSessions([0 => [1, 0], 1 => [2, 0], 2 => [3, 0], 3 => [3, 1], 4 => [5, 0]]))->toBe([1, 5]);
    });

    it('counts the sessions a participant sits out between its first and its last, pins included', function (): void {
        // Participant 0: a pin in session 0, movable events in sessions 2
        // and 5: sessions 1, 3 and 4 are idle. Participant 1: session 2
        // only. Participant 2: session 5 only. Participant 3 is pinned in
        // sessions 0 and 4 and has no movable event: not counted
        $metrics = new PlacementMetrics(
            [0 => [0, 1], 1 => [0, 2]],
            [[[0, 3], 0, 0], [[3, 4], 4, 0]],
            []
        );
        $positions = [0 => [2, 0], 1 => [5, 0]];

        expect($metrics->idleSessionsByParticipant($positions))->toBe([0 => [1, 3, 4]])
            ->and($metrics->idleSessions($positions))->toBe(3)
            ->and($metrics->idleSessions([0 => [2, 0]]))->toBe(1)
            ->and($metrics->idleSessions([]))->toBe(0);
    });

    it('lists the lone-event sessions ascending, whatever order the events come in', function (): void {
        $metrics = new PlacementMetrics([0 => [0, 1], 1 => [2, 3], 2 => [4, 5]], [], []);

        expect($metrics->loneEventSessions([0 => [4, 0], 1 => [1, 0], 2 => [3, 0]]))->toBe([1, 3, 4]);
    });

    it('counts the idle sessions of every participant, after one that has none', function (): void {
        // Participant 0 plays in sessions 0 and 1, with no session between
        // them; participant 2, met after it, plays in sessions 0 and 3
        $metrics = new PlacementMetrics([0 => [0, 1], 1 => [0, 3], 2 => [2, 4], 3 => [2, 5]], [], []);
        $positions = [0 => [0, 0], 1 => [1, 0], 2 => [0, 1], 3 => [3, 0]];

        expect($metrics->idleSessionsByParticipant($positions))->toBe([2 => [1, 2]])
            ->and($metrics->idleSessions($positions))->toBe(2);
    });

    it('counts a placed event as moved when it is not at its current position', function (): void {
        $metrics = new PlacementMetrics(
            [0 => [0, 1], 1 => [2, 3], 2 => [4, 5], 3 => [6, 7], 4 => [8, 9]],
            [],
            // Event 3 states none; event 4's is not a position of any grid here
            [0 => [0, 0], 1 => [0, 1], 2 => [1, 0], 4 => [9, 9]]
        );

        // 0 stays; 1 changes slot; 2 is unplaced; 3 has no current position; 4 cannot be where it says
        expect($metrics->moved([0 => [0, 0], 1 => [0, 2], 3 => [1, 0], 4 => [1, 1]]))->toBe([1, 4]);
    });

    it('scores a schedule by the objectives in their order', function (): void {
        $metrics = new PlacementMetrics(
            [0 => [0, 1], 1 => [0, 2]],
            [],
            [0 => [0, 0], 1 => [0, 1]]
        );
        // Event 0 moved to session 2: one moved event, two lone-event
        // sessions, one idle session (participant 0 sits out session 1)
        $positions = [0 => [2, 0], 1 => [0, 1]];

        expect($metrics->score($positions, []))->toBe([])
            ->and($metrics->score($positions, [RepackObjective::LeastChurn]))->toBe([1])
            ->and($metrics->score($positions, [RepackObjective::FewestLoneEventSessions, RepackObjective::FewestIdleSessions, RepackObjective::LeastChurn]))
            ->toBe([2, 1, 1]);
    });

    it('counts every participant of an event, however many it has', function (): void {
        // The repacker takes pairwise events only; the figures themselves
        // are written over a list of participants. One event of three
        // participants in session 2, and each of the three pinned in
        // session 0 with a fourth
        $metrics = new PlacementMetrics(
            [0 => [0, 1, 2]],
            [[[0, 3], 0, 0], [[1, 3], 0, 1], [[2, 3], 0, 2]],
            []
        );

        // Session 1 is idle for each of the three, and one event in a
        // session is one lone-event session, not three. At slot 3 of
        // session 0 the three wait two slots, one slot and none
        expect($metrics->idleSessions([0 => [2, 0]]))->toBe(3)
            ->and($metrics->loneEventSessions([0 => [2, 0]]))->toBe([2])
            ->and($metrics->gapSlots([0 => [0, 3]]))->toBe(3);
    });

    it('gives the lowest and the highest of some indexes', function (): void {
        expect(PlacementMetrics::span([4, 1, 3]))->toBe([1, 4])
            ->and(PlacementMetrics::span([0]))->toBe([0, 0]);
    });
});
