<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Ordering\EventOrderingContext;
use MissionGaming\Tactician\Ordering\StaticParticipantOrderer;
use MissionGaming\Tactician\Scheduling\SchedulingContext;

describe('StaticParticipantOrderer', function () {
    it('maintains original participant order', function () {
        $participant1 = new Participant('1', 'Team A');
        $participant2 = new Participant('2', 'Team B');
        $participants = [$participant1, $participant2];

        $context = new SchedulingContext([$participant1, $participant2]);
        $orderingContext = new EventOrderingContext(1, 0, null, $context);

        $orderer = new StaticParticipantOrderer();
        $ordered = $orderer->order($participants, $orderingContext);

        expect($ordered)->toBe([$participant1, $participant2]);
    });

    it('handles reversed input order without changing it', function () {
        $participant1 = new Participant('1', 'Team A');
        $participant2 = new Participant('2', 'Team B');
        $participants = [$participant2, $participant1]; // reversed

        $context = new SchedulingContext([$participant1, $participant2]);
        $orderingContext = new EventOrderingContext(1, 0, null, $context);

        $orderer = new StaticParticipantOrderer();
        $ordered = $orderer->order($participants, $orderingContext);

        expect($ordered)->toBe([$participant2, $participant1]);
    });

    it('maintains same order across multiple calls with different contexts', function () {
        $participant1 = new Participant('1', 'Team A');
        $participant2 = new Participant('2', 'Team B');
        $participants = [$participant1, $participant2];

        $context = new SchedulingContext([$participant1, $participant2]);
        $orderer = new StaticParticipantOrderer();

        // Different round numbers
        $context1 = new EventOrderingContext(1, 0, null, $context);
        $ordered1 = $orderer->order($participants, $context1);

        $context2 = new EventOrderingContext(2, 0, null, $context);
        $ordered2 = $orderer->order($participants, $context2);

        expect($ordered1)->toBe([$participant1, $participant2]);
        expect($ordered2)->toBe([$participant1, $participant2]);
    });

    it('reindexes array when receiving associative array', function () {
        $participant1 = new Participant('1', 'Team A');
        $participant2 = new Participant('2', 'Team B');
        $participants = [5 => $participant1, 'key' => $participant2]; // associative

        $context = new SchedulingContext([$participant1, $participant2]);
        $orderingContext = new EventOrderingContext(1, 0, null, $context);

        $orderer = new StaticParticipantOrderer();
        $ordered = $orderer->order($participants, $orderingContext);

        expect(array_keys($ordered))->toBe([0, 1]);
        expect($ordered)->toBe([$participant1, $participant2]);
    });
});
