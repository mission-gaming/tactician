<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Ordering\AlternatingParticipantOrderer;
use MissionGaming\Tactician\Ordering\EventOrderingContext;
use MissionGaming\Tactician\Scheduling\SchedulingContext;

describe('AlternatingParticipantOrderer', function (): void {
    it('maintains original order for even event indices', function (): void {
        $participant1 = new Participant('1', 'Team A');
        $participant2 = new Participant('2', 'Team B');
        $participants = [$participant1, $participant2];

        $context = new SchedulingContext([$participant1, $participant2]);
        $orderer = new AlternatingParticipantOrderer();

        // Event index 0 (even)
        $orderingContext = new EventOrderingContext(1, 0, null, $context);
        $ordered = $orderer->order($participants, $orderingContext);
        expect($ordered)->toBe([$participant1, $participant2]);

        // Event index 2 (even)
        $orderingContext = new EventOrderingContext(1, 2, null, $context);
        $ordered = $orderer->order($participants, $orderingContext);
        expect($ordered)->toBe([$participant1, $participant2]);

        // Event index 4 (even)
        $orderingContext = new EventOrderingContext(1, 4, null, $context);
        $ordered = $orderer->order($participants, $orderingContext);
        expect($ordered)->toBe([$participant1, $participant2]);
    });

    it('reverses order for odd event indices', function (): void {
        $participant1 = new Participant('1', 'Team A');
        $participant2 = new Participant('2', 'Team B');
        $participants = [$participant1, $participant2];

        $context = new SchedulingContext([$participant1, $participant2]);
        $orderer = new AlternatingParticipantOrderer();

        // Event index 1 (odd)
        $orderingContext = new EventOrderingContext(1, 1, null, $context);
        $ordered = $orderer->order($participants, $orderingContext);
        expect($ordered)->toBe([$participant2, $participant1]);

        // Event index 3 (odd)
        $orderingContext = new EventOrderingContext(1, 3, null, $context);
        $ordered = $orderer->order($participants, $orderingContext);
        expect($ordered)->toBe([$participant2, $participant1]);

        // Event index 5 (odd)
        $orderingContext = new EventOrderingContext(1, 5, null, $context);
        $ordered = $orderer->order($participants, $orderingContext);
        expect($ordered)->toBe([$participant2, $participant1]);
    });

    it('creates alternating pattern for sequential events', function (): void {
        $participant1 = new Participant('1', 'Team A');
        $participant2 = new Participant('2', 'Team B');
        $participants = [$participant1, $participant2];

        $context = new SchedulingContext([$participant1, $participant2]);
        $orderer = new AlternatingParticipantOrderer();

        $results = [];
        for ($eventIndex = 0; $eventIndex < 6; ++$eventIndex) {
            $orderingContext = new EventOrderingContext(1, $eventIndex, null, $context);
            $results[] = $orderer->order($participants, $orderingContext);
        }

        // Pattern: original, reversed, original, reversed, original, reversed
        expect($results[0])->toBe([$participant1, $participant2]);
        expect($results[1])->toBe([$participant2, $participant1]);
        expect($results[2])->toBe([$participant1, $participant2]);
        expect($results[3])->toBe([$participant2, $participant1]);
        expect($results[4])->toBe([$participant1, $participant2]);
        expect($results[5])->toBe([$participant2, $participant1]);
    });

    it('alternates independently of round number', function (): void {
        $participant1 = new Participant('1', 'Team A');
        $participant2 = new Participant('2', 'Team B');
        $participants = [$participant1, $participant2];

        $context = new SchedulingContext([$participant1, $participant2]);
        $orderer = new AlternatingParticipantOrderer();

        // Same event index (1, odd) but different rounds - should always reverse
        $context1 = new EventOrderingContext(1, 1, null, $context);
        $ordered1 = $orderer->order($participants, $context1);

        $context2 = new EventOrderingContext(5, 1, null, $context);
        $ordered2 = $orderer->order($participants, $context2);

        expect($ordered1)->toBe([$participant2, $participant1]);
        expect($ordered2)->toBe([$participant2, $participant1]);
    });

    it('reindexes array when receiving associative array', function (): void {
        $participant1 = new Participant('1', 'Team A');
        $participant2 = new Participant('2', 'Team B');
        $participants = [5 => $participant1, 'key' => $participant2];

        $context = new SchedulingContext([$participant1, $participant2]);
        $orderingContext = new EventOrderingContext(1, 0, null, $context);

        $orderer = new AlternatingParticipantOrderer();
        $ordered = $orderer->order($participants, $orderingContext);

        expect(array_keys($ordered))->toBe([0, 1]);
    });
});
