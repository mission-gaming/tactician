<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Participant;

describe('Participant', function (): void {
    // Tests creating a basic participant with only ID and label (name),
    // verifying default values for optional seed ranking and metadata
    it('creates a participant with required fields', function (): void {
        $participant = new Participant('p1', 'Alice');

        expect($participant->getId())->toBe('p1');
        expect($participant->getLabel())->toBe('Alice');
        expect($participant->getSeed())->toBeNull();
        expect($participant->getMetadata())->toBe([]);
    });

    // Tests creating a participant with all optional fields (seed ranking, metadata),
    // ensuring the participant properly stores additional tournament information
    it('creates a participant with optional fields', function (): void {
        $metadata = ['rating' => 1500, 'country' => 'USA'];
        $participant = new Participant('p1', 'Alice', 42, $metadata);

        expect($participant->getId())->toBe('p1');
        expect($participant->getLabel())->toBe('Alice');
        expect($participant->getSeed())->toBe(42);
        expect($participant->getMetadata())->toBe($metadata);
    });

    // Tests the hasMetadata method to verify it correctly identifies whether
    // specific metadata keys exist on the participant or not
    it('checks metadata existence', function (): void {
        $metadata = ['rating' => 1500];
        $participant = new Participant('p1', 'Alice', null, $metadata);

        expect($participant->hasMetadata('rating'))->toBeTrue();
        expect($participant->hasMetadata('country'))->toBeFalse();
    });

    // Tests retrieving participant metadata with support for default values
    // when the requested metadata key doesn't exist
    it('gets metadata values with defaults', function (): void {
        $metadata = ['rating' => 1500];
        $participant = new Participant('p1', 'Alice', null, $metadata);

        expect($participant->getMetadataValue('rating'))->toBe(1500);
        expect($participant->getMetadataValue('country'))->toBeNull();
        expect($participant->getMetadataValue('country', 'Unknown'))->toBe('Unknown');
    });

    // Tests that the Participant class is readonly (immutable), ensuring
    // participant data cannot be modified after creation for data integrity
    it('is readonly', function (): void {
        $participant = new Participant('p1', 'Alice');

        expect($participant)->toBeInstanceOf(Participant::class);
        // Readonly classes cannot have properties modified after construction
    });

    it('reseeds via withSeed while preserving identity', function (): void {
        $participant = new Participant('p1', 'Alice', 5, ['club' => 'north']);
        $reseeded = $participant->withSeed(1);

        expect($reseeded->getSeed())->toBe(1);
        expect($reseeded->getId())->toBe('p1');
        expect($reseeded->getLabel())->toBe('Alice');
        expect($reseeded->getMetadata())->toBe(['club' => 'north']);
        expect($participant->getSeed())->toBe(5); // original untouched
    });

    it('rejects malformed serialized seed and metadata', function (): void {
        $valid = ['id' => 'p1', 'label' => 'Alice', 'seed' => null, 'metadata' => []];

        expect(fn () => Participant::fromArray([...$valid, 'seed' => 'first']))
            ->toThrow(InvalidArgumentException::class, 'seed');
        expect(fn () => Participant::fromArray([...$valid, 'metadata' => 'nope']))
            ->toThrow(InvalidArgumentException::class, 'metadata');
    });
});
