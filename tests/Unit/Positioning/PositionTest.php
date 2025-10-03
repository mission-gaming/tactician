<?php

use MissionGaming\Tactician\Positioning\Position;
use MissionGaming\Tactician\Positioning\PositionType;

describe('Position', function (): void {
    it('creates a seed position', function (): void {
        $position = new Position(PositionType::SEED, 1);

        expect($position->getType())->toBe(PositionType::SEED);
        expect($position->getValue())->toBe(1);
        expect($position->getRoundContext())->toBeNull();
        expect((string) $position)->toBe('Seed 1');
    });

    it('creates a standing position', function (): void {
        $position = new Position(PositionType::STANDING, 3);

        expect($position->getType())->toBe(PositionType::STANDING);
        expect($position->getValue())->toBe(3);
        expect($position->getRoundContext())->toBeNull();
        expect((string) $position)->toBe('Standing 3');
    });

    it('creates a standing after round position', function (): void {
        $position = new Position(PositionType::STANDING_AFTER_ROUND, 2, 5);

        expect($position->getType())->toBe(PositionType::STANDING_AFTER_ROUND);
        expect($position->getValue())->toBe(2);
        expect($position->getRoundContext())->toBe(5);
        expect((string) $position)->toBe('Standing 2 (after round 5)');
    });

    it('rejects position value less than 1', function (): void {
        expect(fn () => new Position(PositionType::SEED, 0))
            ->toThrow(InvalidArgumentException::class, 'Position value must be at least 1');
    });

    it('requires round context for STANDING_AFTER_ROUND', function (): void {
        expect(fn () => new Position(PositionType::STANDING_AFTER_ROUND, 1))
            ->toThrow(InvalidArgumentException::class, 'Round context required for STANDING_AFTER_ROUND');
    });

    it('identifies seed positions as statically resolvable', function (): void {
        $seedPosition = new Position(PositionType::SEED, 1);
        expect($seedPosition->isStaticallyResolvable())->toBeTrue();
    });

    it('identifies standing positions as not statically resolvable', function (): void {
        $standingPosition = new Position(PositionType::STANDING, 1);
        expect($standingPosition->isStaticallyResolvable())->toBeFalse();

        $standingAfterRound = new Position(PositionType::STANDING_AFTER_ROUND, 1, 2);
        expect($standingAfterRound->isStaticallyResolvable())->toBeFalse();
    });
});
