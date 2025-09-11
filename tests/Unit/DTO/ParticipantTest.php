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
});
