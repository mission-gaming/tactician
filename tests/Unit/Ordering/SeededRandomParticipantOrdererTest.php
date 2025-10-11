<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Ordering\EventOrderingContext;
use MissionGaming\Tactician\Ordering\SeededRandomParticipantOrderer;
use MissionGaming\Tactician\Scheduling\SchedulingContext;

describe('SeededRandomParticipantOrderer', function (): void {
    it('produces deterministic results for same context', function (): void {
        $participant1 = new Participant('1', 'Team A');
        $participant2 = new Participant('2', 'Team B');
        $participants = [$participant1, $participant2];

        $context = new SchedulingContext([$participant1, $participant2]);
        $orderingContext = new EventOrderingContext(1, 0, null, $context);

        $orderer1 = new SeededRandomParticipantOrderer();
        $ordered1 = $orderer1->order($participants, $orderingContext);

        $orderer2 = new SeededRandomParticipantOrderer();
        $ordered2 = $orderer2->order($participants, $orderingContext);

        expect($ordered1)->toBe($ordered2);
    });

    it('produces different results for different event indices', function (): void {
        $participant1 = new Participant('1', 'Team A');
        $participant2 = new Participant('2', 'Team B');
        $participants = [$participant1, $participant2];

        $context = new SchedulingContext([$participant1, $participant2]);
        $orderer = new SeededRandomParticipantOrderer();

        $results = [];
        for ($eventIndex = 0; $eventIndex < 10; ++$eventIndex) {
            $orderingContext = new EventOrderingContext(1, $eventIndex, null, $context);
            $results[] = $orderer->order($participants, $orderingContext);
        }

        // Check that we have both orderings represented (not all the same)
        $hasOriginal = false;
        $hasReversed = false;

        foreach ($results as $result) {
            if ($result === [$participant1, $participant2]) {
                $hasOriginal = true;
            }
            if ($result === [$participant2, $participant1]) {
                $hasReversed = true;
            }
        }

        expect($hasOriginal)->toBeTrue();
        expect($hasReversed)->toBeTrue();
    });

    it('produces different results for different round numbers', function (): void {
        $participant1 = new Participant('1', 'Team A');
        $participant2 = new Participant('2', 'Team B');
        $participants = [$participant1, $participant2];

        $context = new SchedulingContext([$participant1, $participant2]);
        $orderer = new SeededRandomParticipantOrderer();

        $results = [];
        for ($round = 1; $round <= 10; ++$round) {
            $orderingContext = new EventOrderingContext($round, 0, null, $context);
            $results[] = $orderer->order($participants, $orderingContext);
        }

        // Check that we have both orderings represented
        $hasOriginal = false;
        $hasReversed = false;

        foreach ($results as $result) {
            if ($result === [$participant1, $participant2]) {
                $hasOriginal = true;
            }
            if ($result === [$participant2, $participant1]) {
                $hasReversed = true;
            }
        }

        expect($hasOriginal)->toBeTrue();
        expect($hasReversed)->toBeTrue();
    });

    it('produces different results for different legs', function (): void {
        $participant1 = new Participant('1', 'Team A');
        $participant2 = new Participant('2', 'Team B');
        $participants = [$participant1, $participant2];

        $context = new SchedulingContext([$participant1, $participant2], [], 1, 5, 2);
        $orderer = new SeededRandomParticipantOrderer();

        $results = [];
        for ($leg = 1; $leg <= 5; ++$leg) {
            $orderingContext = new EventOrderingContext(1, 0, $leg, $context);
            $results[] = $orderer->order($participants, $orderingContext);
        }

        // Check that we have both orderings represented
        $hasOriginal = false;
        $hasReversed = false;

        foreach ($results as $result) {
            if ($result === [$participant1, $participant2]) {
                $hasOriginal = true;
            }
            if ($result === [$participant2, $participant1]) {
                $hasReversed = true;
            }
        }

        expect($hasOriginal)->toBeTrue();
        expect($hasReversed)->toBeTrue();
    });

    it('uses default randomizer when no engine provided', function (): void {
        $participant1 = new Participant('1', 'Team A');
        $participant2 = new Participant('2', 'Team B');
        $participants = [$participant1, $participant2];

        $context = new SchedulingContext([$participant1, $participant2]);
        $orderingContext = new EventOrderingContext(1, 0, null, $context);

        $orderer = new SeededRandomParticipantOrderer();
        $ordered = $orderer->order($participants, $orderingContext);

        // Should return one of the two orderings
        expect($ordered === [$participant1, $participant2] || $ordered === [$participant2, $participant1])->toBeTrue();
    });

    it('creates unique seed from context components', function (): void {
        $participant1 = new Participant('1', 'Team A');
        $participant2 = new Participant('2', 'Team B');
        $participants = [$participant1, $participant2];

        $context = new SchedulingContext([$participant1, $participant2]);
        $orderer = new SeededRandomParticipantOrderer();

        // Same seed = same result
        $context1 = new EventOrderingContext(5, 3, 2, $context);
        $ordered1 = $orderer->order($participants, $context1);

        $context2 = new EventOrderingContext(5, 3, 2, $context);
        $ordered2 = $orderer->order($participants, $context2);

        expect($ordered1)->toBe($ordered2);
    });

    it('reindexes array when receiving associative array', function (): void {
        $participant1 = new Participant('1', 'Team A');
        $participant2 = new Participant('2', 'Team B');
        $participants = [5 => $participant1, 'key' => $participant2];

        $context = new SchedulingContext([$participant1, $participant2]);
        $orderingContext = new EventOrderingContext(1, 0, null, $context);

        $orderer = new SeededRandomParticipantOrderer();
        $ordered = $orderer->order($participants, $orderingContext);

        expect(array_keys($ordered))->toBe([0, 1]);
    });
});
