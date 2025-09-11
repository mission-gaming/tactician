<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Round;

describe('Round DTO', function (): void {
    // Tests creating a basic round with only the required round number,
    // verifying default empty metadata and proper number storage
    it('creates a round with minimal parameters', function (): void {
        $round = new Round(1);

        expect($round->getNumber())->toBe(1);
        expect($round->getMetadata())->toBe([]);
        expect($round->hasMetadata('any'))->toBeFalse();
    });

    // Tests creating a round with optional metadata (venue, capacity info),
    // ensuring rounds can carry additional tournament organization data
    it('creates a round with all parameters', function (): void {
        $metadata = ['venue' => 'Hall A', 'max_concurrent' => 8];

        $round = new Round(
            number: 3,
            metadata: $metadata
        );

        expect($round->getNumber())->toBe(3);
        expect($round->getMetadata())->toBe($metadata);
    });

    // Tests that round creation rejects zero as an invalid round number,
    // since tournament rounds must start from 1 (positive integers)
    it('validates round number is positive', function (): void {
        new Round(0);
    })->throws(InvalidArgumentException::class, 'Round number must be positive');

    // Tests that round creation rejects negative numbers as invalid round numbers,
    // ensuring rounds always have logical, positive numbering
    it('validates round number is not negative', function (): void {
        new Round(-1);
    })->throws(InvalidArgumentException::class, 'Round number must be positive');

    describe('metadata operations', function (): void {
        // Tests the hasMetadata method to verify it correctly identifies whether
        // specific metadata keys exist on the round or not
        it('checks for metadata existence', function (): void {
            $round = new Round(1, metadata: ['venue' => 'Hall A', 'capacity' => 50]);

            expect($round->hasMetadata('venue'))->toBeTrue();
            expect($round->hasMetadata('capacity'))->toBeTrue();
            expect($round->hasMetadata('nonexistent'))->toBeFalse();
        });

        // Tests retrieving round metadata values with support for default values
        // when requested keys don't exist, useful for tournament configuration
        it('retrieves metadata values', function (): void {
            $round = new Round(1, metadata: ['venue' => 'Hall A', 'capacity' => 50]);

            expect($round->getMetadataValue('venue'))->toBe('Hall A');
            expect($round->getMetadataValue('capacity'))->toBe(50);
            expect($round->getMetadataValue('nonexistent'))->toBeNull();
            expect($round->getMetadataValue('nonexistent', 'default'))->toBe('default');
        });

        // Tests that rounds can store null metadata values and properly distinguish
        // between null values and missing keys when retrieving metadata
        it('handles null metadata value', function (): void {
            $round = new Round(1, metadata: ['nullable' => null]);

            expect($round->hasMetadata('nullable'))->toBeTrue();
            expect($round->getMetadataValue('nullable'))->toBeNull();
            expect($round->getMetadataValue('nullable', 'default'))->toBeNull();
        });
    });

    describe('equality and comparison', function (): void {
        // Tests round equality comparison based on round number only,
        // ignoring metadata differences when determining if rounds are the same
        it('compares rounds by number', function (): void {
            $round1 = new Round(1);
            $round2 = new Round(2);
            $round3 = new Round(1, ['venue' => 'Hall A']); // Same number, different metadata

            expect($round1->equals($round3))->toBeTrue();
            expect($round1->equals($round2))->toBeFalse();
        });

        // Tests temporal comparison methods (isBefore, isAfter) for determining
        // round ordering in tournament progression and scheduling logic
        it('implements comparison operators', function (): void {
            $round1 = new Round(1);
            $round2 = new Round(2);
            $round3 = new Round(3);

            expect($round1->isBefore($round2))->toBeTrue();
            expect($round2->isBefore($round1))->toBeFalse();
            expect($round2->isAfter($round1))->toBeTrue();
            expect($round1->isAfter($round2))->toBeFalse();

            expect($round1->isBefore($round3))->toBeTrue();
            expect($round3->isAfter($round1))->toBeTrue();
        });
    });

    describe('string representation', function (): void {
        // Tests that rounds convert to human-readable strings ("Round 5") for
        // display purposes in tournament brackets and user interfaces
        it('provides readable string representation', function (): void {
            $round = new Round(5);
            expect($round->__toString())->toBe('Round 5');
            expect((string) $round)->toBe('Round 5');
        });

        // Tests that the string representation shows only the round number,
        // not metadata details, keeping display format clean and consistent
        it('string representation ignores metadata', function (): void {
            $round = new Round(3, ['venue' => 'Hall A', 'capacity' => 100]);
            expect($round->__toString())->toBe('Round 3');
        });
    });
});
