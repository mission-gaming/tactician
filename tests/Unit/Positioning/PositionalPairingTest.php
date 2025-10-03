<?php

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Positioning\Position;
use MissionGaming\Tactician\Positioning\PositionalPairing;
use MissionGaming\Tactician\Positioning\PositionType;
use MissionGaming\Tactician\Positioning\SeedBasedPositionResolver;

describe('PositionalPairing', function (): void {
    it('creates a positional pairing', function (): void {
        $pos1 = new Position(PositionType::SEED, 1);
        $pos2 = new Position(PositionType::SEED, 2);

        $pairing = new PositionalPairing($pos1, $pos2);

        expect($pairing->getPosition1())->toBe($pos1);
        expect($pairing->getPosition2())->toBe($pos2);
        expect((string) $pairing)->toBe('Seed 1 vs Seed 2');
    });

    it('resolves to participants', function (): void {
        $participants = [
            new Participant('team1', 'Team 1'),
            new Participant('team2', 'Team 2'),
        ];

        $resolver = new SeedBasedPositionResolver($participants);

        $pos1 = new Position(PositionType::SEED, 1);
        $pos2 = new Position(PositionType::SEED, 2);
        $pairing = new PositionalPairing($pos1, $pos2);

        $resolved = $pairing->resolve($resolver);

        expect($resolved)->not->toBeNull();
        assert($resolved !== null); // For PHPStan
        expect($resolved[0]->getId())->toBe('team1');
        expect($resolved[1]->getId())->toBe('team2');
    });

    it('returns null if positions cannot be resolved', function (): void {
        $participants = [
            new Participant('team1', 'Team 1'),
        ];

        $resolver = new SeedBasedPositionResolver($participants);

        $pos1 = new Position(PositionType::SEED, 1);
        $pos2 = new Position(PositionType::SEED, 5); // Out of bounds
        $pairing = new PositionalPairing($pos1, $pos2);

        $resolved = $pairing->resolve($resolver);

        expect($resolved)->toBeNull();
    });

    it('can check if pairing is resolvable', function (): void {
        $participants = [
            new Participant('team1', 'Team 1'),
            new Participant('team2', 'Team 2'),
        ];

        $resolver = new SeedBasedPositionResolver($participants);

        $seedPairing = new PositionalPairing(
            new Position(PositionType::SEED, 1),
            new Position(PositionType::SEED, 2)
        );

        $mixedPairing = new PositionalPairing(
            new Position(PositionType::SEED, 1),
            new Position(PositionType::STANDING, 1)
        );

        expect($seedPairing->canResolve($resolver))->toBeTrue();
        expect($mixedPairing->canResolve($resolver))->toBeFalse();
    });
});
