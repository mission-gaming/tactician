<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Round;

describe('Round DTO', function (): void {
    it('creates a round with minimal parameters', function (): void {
        $round = new Round(1);

        expect($round->getNumber())->toBe(1);
        expect($round->getMetadata())->toBe([]);
        expect($round->hasMetadata('any'))->toBeFalse();
    });

    it('creates a round with all parameters', function (): void {
        $metadata = ['venue' => 'Hall A', 'max_concurrent' => 8];

        $round = new Round(
            number: 3,
            metadata: $metadata
        );

        expect($round->getNumber())->toBe(3);
        expect($round->getMetadata())->toBe($metadata);
    });

    it('validates round number is positive', function (): void {
        new Round(0);
    })->throws(InvalidArgumentException::class, 'Round number must be positive');

    it('validates round number is not negative', function (): void {
        new Round(-1);
    })->throws(InvalidArgumentException::class, 'Round number must be positive');

    describe('metadata operations', function (): void {
        it('checks for metadata existence', function (): void {
            $round = new Round(1, metadata: ['venue' => 'Hall A', 'capacity' => 50]);

            expect($round->hasMetadata('venue'))->toBeTrue();
            expect($round->hasMetadata('capacity'))->toBeTrue();
            expect($round->hasMetadata('nonexistent'))->toBeFalse();
        });

        it('retrieves metadata values', function (): void {
            $round = new Round(1, metadata: ['venue' => 'Hall A', 'capacity' => 50]);

            expect($round->getMetadataValue('venue'))->toBe('Hall A');
            expect($round->getMetadataValue('capacity'))->toBe(50);
            expect($round->getMetadataValue('nonexistent'))->toBeNull();
            expect($round->getMetadataValue('nonexistent', 'default'))->toBe('default');
        });

        it('handles null metadata value', function (): void {
            $round = new Round(1, metadata: ['nullable' => null]);

            expect($round->hasMetadata('nullable'))->toBeTrue();
            expect($round->getMetadataValue('nullable'))->toBeNull();
            expect($round->getMetadataValue('nullable', 'default'))->toBeNull();
        });
    });

    describe('equality and comparison', function (): void {
        it('compares rounds by number', function (): void {
            $round1 = new Round(1);
            $round2 = new Round(2);
            $round3 = new Round(1, ['venue' => 'Hall A']); // Same number, different metadata

            expect($round1->equals($round3))->toBeTrue();
            expect($round1->equals($round2))->toBeFalse();
        });

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
        it('provides readable string representation', function (): void {
            $round = new Round(5);
            expect($round->__toString())->toBe('Round 5');
            expect((string) $round)->toBe('Round 5');
        });

        it('string representation ignores metadata', function (): void {
            $round = new Round(3, ['venue' => 'Hall A', 'capacity' => 100]);
            expect($round->__toString())->toBe('Round 3');
        });
    });
});
