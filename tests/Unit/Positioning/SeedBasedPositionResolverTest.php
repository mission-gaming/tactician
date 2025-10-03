<?php

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Positioning\Position;
use MissionGaming\Tactician\Positioning\PositionType;
use MissionGaming\Tactician\Positioning\SeedBasedPositionResolver;

describe('SeedBasedPositionResolver', function (): void {
    it('resolves seed positions to participants', function (): void {
        $participants = [
            new Participant('team1', 'Team 1'),
            new Participant('team2', 'Team 2'),
            new Participant('team3', 'Team 3'),
        ];

        $resolver = new SeedBasedPositionResolver($participants);

        $position1 = new Position(PositionType::SEED, 1);
        $position2 = new Position(PositionType::SEED, 2);
        $position3 = new Position(PositionType::SEED, 3);

        expect($resolver->resolve($position1)?->getId())->toBe('team1');
        expect($resolver->resolve($position2)?->getId())->toBe('team2');
        expect($resolver->resolve($position3)?->getId())->toBe('team3');
    });

    it('returns null for out of bounds seed positions', function (): void {
        $participants = [
            new Participant('team1', 'Team 1'),
            new Participant('team2', 'Team 2'),
        ];

        $resolver = new SeedBasedPositionResolver($participants);
        $position = new Position(PositionType::SEED, 5);

        expect($resolver->resolve($position))->toBeNull();
    });

    it('returns null for non-seed positions', function (): void {
        $participants = [
            new Participant('team1', 'Team 1'),
        ];

        $resolver = new SeedBasedPositionResolver($participants);
        $standingPosition = new Position(PositionType::STANDING, 1);

        expect($resolver->resolve($standingPosition))->toBeNull();
    });

    it('can resolve only seed positions', function (): void {
        $participants = [
            new Participant('team1', 'Team 1'),
        ];

        $resolver = new SeedBasedPositionResolver($participants);

        $seedPosition = new Position(PositionType::SEED, 1);
        $standingPosition = new Position(PositionType::STANDING, 1);
        $standingAfterRound = new Position(PositionType::STANDING_AFTER_ROUND, 1, 2);

        expect($resolver->canResolve($seedPosition))->toBeTrue();
        expect($resolver->canResolve($standingPosition))->toBeFalse();
        expect($resolver->canResolve($standingAfterRound))->toBeFalse();
    });
});
