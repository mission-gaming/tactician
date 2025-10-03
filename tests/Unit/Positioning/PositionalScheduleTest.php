<?php

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Positioning\Position;
use MissionGaming\Tactician\Positioning\PositionalPairing;
use MissionGaming\Tactician\Positioning\PositionalRound;
use MissionGaming\Tactician\Positioning\PositionalSchedule;
use MissionGaming\Tactician\Positioning\PositionType;
use MissionGaming\Tactician\Positioning\SeedBasedPositionResolver;

describe('PositionalSchedule', function (): void {
    it('creates a positional schedule', function (): void {
        $round1 = new PositionalRound(1, [
            new PositionalPairing(
                new Position(PositionType::SEED, 1),
                new Position(PositionType::SEED, 2)
            ),
        ]);

        $round2 = new PositionalRound(2, [
            new PositionalPairing(
                new Position(PositionType::SEED, 1),
                new Position(PositionType::SEED, 3)
            ),
        ]);

        $schedule = new PositionalSchedule([$round1, $round2]);

        expect($schedule->getRoundCount())->toBe(2);
        expect($schedule->getTotalPairingCount())->toBe(2);
    });

    it('retrieves a round by number', function (): void {
        $round1 = new PositionalRound(1, [
            new PositionalPairing(
                new Position(PositionType::SEED, 1),
                new Position(PositionType::SEED, 2)
            ),
        ]);

        $round2 = new PositionalRound(2, [
            new PositionalPairing(
                new Position(PositionType::SEED, 3),
                new Position(PositionType::SEED, 4)
            ),
        ]);

        $schedule = new PositionalSchedule([$round1, $round2]);

        $retrievedRound = $schedule->getRound(2);
        expect($retrievedRound)->not->toBeNull();
        assert($retrievedRound !== null); // For PHPStan
        expect($retrievedRound->getRoundNumber())->toBe(2);

        $nonExistentRound = $schedule->getRound(5);
        expect($nonExistentRound)->toBeNull();
    });

    it('resolves to complete schedule', function (): void {
        $participants = [
            new Participant('team1', 'Team 1'),
            new Participant('team2', 'Team 2'),
            new Participant('team3', 'Team 3'),
            new Participant('team4', 'Team 4'),
        ];

        $round1 = new PositionalRound(1, [
            new PositionalPairing(
                new Position(PositionType::SEED, 1),
                new Position(PositionType::SEED, 2)
            ),
            new PositionalPairing(
                new Position(PositionType::SEED, 3),
                new Position(PositionType::SEED, 4)
            ),
        ]);

        $positionalSchedule = new PositionalSchedule([$round1]);
        $resolver = new SeedBasedPositionResolver($participants);

        $schedule = $positionalSchedule->resolve($resolver);

        expect($schedule->count())->toBe(2);
        expect($schedule->isFullyResolved())->toBeTrue();

        $events = $schedule->getEvents();
        expect($events[0]->getParticipants()[0]->getId())->toBe('team1');
        expect($events[0]->getParticipants()[1]->getId())->toBe('team2');
        expect($events[1]->getParticipants()[0]->getId())->toBe('team3');
        expect($events[1]->getParticipants()[1]->getId())->toBe('team4');
    });

    it('identifies fully predetermined schedules', function (): void {
        $seedOnlySchedule = new PositionalSchedule([
            new PositionalRound(1, [
                new PositionalPairing(
                    new Position(PositionType::SEED, 1),
                    new Position(PositionType::SEED, 2)
                ),
            ]),
        ]);

        expect($seedOnlySchedule->isFullyPredetermined())->toBeTrue();

        $dynamicSchedule = new PositionalSchedule([
            new PositionalRound(1, [
                new PositionalPairing(
                    new Position(PositionType::STANDING, 1),
                    new Position(PositionType::STANDING, 2)
                ),
            ]),
        ]);

        expect($dynamicSchedule->isFullyPredetermined())->toBeFalse();
    });

    it('stores and retrieves metadata', function (): void {
        $schedule = new PositionalSchedule([], [
            'algorithm' => 'round-robin',
            'participant_count' => 4,
        ]);

        expect($schedule->hasMetadata('algorithm'))->toBeTrue();
        expect($schedule->getMetadataValue('algorithm'))->toBe('round-robin');
        expect($schedule->getMetadataValue('nonexistent', 'default'))->toBe('default');
    });

    it('can check if fully resolvable with given resolver', function (): void {
        $participants = [
            new Participant('team1', 'Team 1'),
            new Participant('team2', 'Team 2'),
        ];

        $resolver = new SeedBasedPositionResolver($participants);

        $resolvableSchedule = new PositionalSchedule([
            new PositionalRound(1, [
                new PositionalPairing(
                    new Position(PositionType::SEED, 1),
                    new Position(PositionType::SEED, 2)
                ),
            ]),
        ]);

        expect($resolvableSchedule->canFullyResolve($resolver))->toBeTrue();

        $unresolvableSchedule = new PositionalSchedule([
            new PositionalRound(1, [
                new PositionalPairing(
                    new Position(PositionType::STANDING, 1),
                    new Position(PositionType::STANDING, 2)
                ),
            ]),
        ]);

        expect($unresolvableSchedule->canFullyResolve($resolver))->toBeFalse();
    });
});
