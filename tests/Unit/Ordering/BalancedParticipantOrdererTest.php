<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Ordering\BalancedParticipantOrderer;
use MissionGaming\Tactician\Ordering\EventOrderingContext;
use MissionGaming\Tactician\Scheduling\SchedulingContext;

describe('BalancedParticipantOrderer', function () {
    it('maintains original order when both participants have zero home count', function () {
        $participant1 = new Participant('1', 'Team A');
        $participant2 = new Participant('2', 'Team B');
        $participants = [$participant1, $participant2];

        $context = new SchedulingContext([$participant1, $participant2], []); // no events yet
        $orderingContext = new EventOrderingContext(1, 0, null, $context);

        $orderer = new BalancedParticipantOrderer();
        $ordered = $orderer->order($participants, $orderingContext);

        expect($ordered)->toBe([$participant1, $participant2]);
    });

    it('puts participant with fewer home appearances first', function () {
        $participant1 = new Participant('1', 'Team A');
        $participant2 = new Participant('2', 'Team B');
        $participant3 = new Participant('3', 'Team C');

        // Team A has been home twice, Team B once
        $existingEvents = [
            new Event([$participant1, $participant3], new Round(1)), // A home
            new Event([$participant1, $participant2], new Round(2)), // A home
            new Event([$participant2, $participant3], new Round(3)), // B home
        ];

        $context = new SchedulingContext([$participant1, $participant2, $participant3], $existingEvents);
        $orderingContext = new EventOrderingContext(4, 0, null, $context);

        $orderer = new BalancedParticipantOrderer();

        // A vs B: B should be home (B has 1, A has 2)
        $ordered = $orderer->order([$participant1, $participant2], $orderingContext);
        expect($ordered)->toBe([$participant2, $participant1]);

        // B vs A (reversed input): still B should be home
        $ordered = $orderer->order([$participant2, $participant1], $orderingContext);
        expect($ordered)->toBe([$participant2, $participant1]);
    });

    it('balances home/away distribution across multiple events', function () {
        $participant1 = new Participant('1', 'Team A');
        $participant2 = new Participant('2', 'Team B');

        $existingEvents = [];
        $context = new SchedulingContext([$participant1, $participant2], $existingEvents);
        $orderer = new BalancedParticipantOrderer();

        // First event: both have 0, so original order
        $orderingContext = new EventOrderingContext(1, 0, null, $context);
        $ordered1 = $orderer->order([$participant1, $participant2], $orderingContext);
        expect($ordered1)->toBe([$participant1, $participant2]);

        // Add that event to context
        $event1 = new Event($ordered1, new Round(1));
        $existingEvents[] = $event1;
        $context = new SchedulingContext([$participant1, $participant2], $existingEvents);

        // Second event: A has 1, B has 0, so B should be first
        $orderingContext = new EventOrderingContext(2, 0, null, $context);
        $ordered2 = $orderer->order([$participant1, $participant2], $orderingContext);
        expect($ordered2)->toBe([$participant2, $participant1]);

        // Add that event
        $event2 = new Event($ordered2, new Round(2));
        $existingEvents[] = $event2;
        $context = new SchedulingContext([$participant1, $participant2], $existingEvents);

        // Third event: both have 1, so original order (tie)
        $orderingContext = new EventOrderingContext(3, 0, null, $context);
        $ordered3 = $orderer->order([$participant1, $participant2], $orderingContext);
        expect($ordered3)->toBe([$participant1, $participant2]);
    });

    it('handles tie by preserving original order', function () {
        $participant1 = new Participant('1', 'Team A');
        $participant2 = new Participant('2', 'Team B');
        $participant3 = new Participant('3', 'Team C');

        // Both A and B have been home once
        $existingEvents = [
            new Event([$participant1, $participant3], new Round(1)), // A home
            new Event([$participant2, $participant3], new Round(2)), // B home
        ];

        $context = new SchedulingContext([$participant1, $participant2, $participant3], $existingEvents);
        $orderingContext = new EventOrderingContext(3, 0, null, $context);

        $orderer = new BalancedParticipantOrderer();

        // A vs B: tie (both 1), preserve original order
        $ordered1 = $orderer->order([$participant1, $participant2], $orderingContext);
        expect($ordered1)->toBe([$participant1, $participant2]);

        // B vs A: tie (both 1), preserve original order
        $ordered2 = $orderer->order([$participant2, $participant1], $orderingContext);
        expect($ordered2)->toBe([$participant2, $participant1]);
    });

    it('only counts home appearances (first position in event)', function () {
        $participant1 = new Participant('1', 'Team A');
        $participant2 = new Participant('2', 'Team B');
        $participant3 = new Participant('3', 'Team C');

        // A has been home once, away twice
        // B has been home once, away once
        $existingEvents = [
            new Event([$participant1, $participant3], new Round(1)), // A home
            new Event([$participant2, $participant1], new Round(2)), // B home, A away
            new Event([$participant3, $participant1], new Round(3)), // C home, A away
            new Event([$participant3, $participant2], new Round(4)), // C home, B away
        ];

        $context = new SchedulingContext([$participant1, $participant2, $participant3], $existingEvents);
        $orderingContext = new EventOrderingContext(5, 0, null, $context);

        $orderer = new BalancedParticipantOrderer();

        // A vs B: tie (both have been home once), preserve original order
        $ordered = $orderer->order([$participant1, $participant2], $orderingContext);
        expect($ordered)->toBe([$participant1, $participant2]);
    });

    it('returns original array for non-2-participant events', function () {
        $participant1 = new Participant('1', 'Team A');
        $participant2 = new Participant('2', 'Team B');
        $participant3 = new Participant('3', 'Team C');
        $participants = [$participant1, $participant2, $participant3]; // 3 participants

        $context = new SchedulingContext([$participant1, $participant2, $participant3]);
        $orderingContext = new EventOrderingContext(1, 0, null, $context);

        $orderer = new BalancedParticipantOrderer();
        $ordered = $orderer->order($participants, $orderingContext);

        expect($ordered)->toBe($participants);
    });
});
