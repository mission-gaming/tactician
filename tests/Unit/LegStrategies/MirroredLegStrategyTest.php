<?php

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\LegStrategies\MirroredLegStrategy;
use MissionGaming\Tactician\Scheduling\SchedulingContext;
use MissionGaming\Tactician\Stage\RoundRobinPlan;

describe('MirroredLegStrategy', function (): void {
    it('keeps original participant order for first leg', function (): void {
        $strategy = new MirroredLegStrategy();

        $participant1 = new Participant('team-a', 'Team A');
        $participant2 = new Participant('team-b', 'Team B');
        $participants = [$participant1, $participant2];

        $context = roundRobinContext([$participant1, $participant2], [], legs: 2, currentLeg: 1);

        $event = $strategy->generateEventForLeg($participants, 1, 1, $context);

        expect($event)->not->toBeNull();
        assert($event !== null);
        expect($event->getParticipants())->toBe([$participant1, $participant2]);
        expect($event->getRound()?->getNumber())->toBe(1);
    });

    it('reverses participant order for second leg', function (): void {
        $strategy = new MirroredLegStrategy();

        $participant1 = new Participant('team-a', 'Team A');
        $participant2 = new Participant('team-b', 'Team B');
        $participants = [$participant1, $participant2];

        $context = roundRobinContext([$participant1, $participant2], [], legs: 2, currentLeg: 2);

        $event = $strategy->generateEventForLeg($participants, 2, 4, $context);

        expect($event)->not->toBeNull();
        assert($event !== null);
        expect($event->getParticipants())->toBe([$participant2, $participant1]); // Should be reversed
        expect($event->getRound()?->getNumber())->toBe(4);
    });

    it('reverses participant order for any leg greater than 1', function (): void {
        $strategy = new MirroredLegStrategy();

        $participant1 = new Participant('team-a', 'Team A');
        $participant2 = new Participant('team-b', 'Team B');
        $participants = [$participant1, $participant2];

        $context = roundRobinContext([$participant1, $participant2], [], legs: 3, currentLeg: 3);

        $event = $strategy->generateEventForLeg($participants, 3, 7, $context);

        expect($event)->not->toBeNull();
        assert($event !== null);
        expect($event->getParticipants())->toBe([$participant2, $participant1]); // Should be reversed
        expect($event->getRound()?->getNumber())->toBe(7);
    });

    it('returns null for non-2-participant events', function (): void {
        $strategy = new MirroredLegStrategy();

        $participant1 = new Participant('team-a', 'Team A');
        $participant2 = new Participant('team-b', 'Team B');
        $participant3 = new Participant('team-c', 'Team C');
        $participants = [$participant1, $participant2, $participant3];

        $context = new SchedulingContext(
            [$participant1, $participant2, $participant3],
            new RoundRobinPlan([$participant1, $participant2, $participant3], 2),
            [],
            1,
            3
        );

        $event = $strategy->generateEventForLeg($participants, 1, 1, $context);

        expect($event)->toBeNull();
    });
});
