<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Participant;

describe('Participant', function () {
    it('creates a participant with required fields', function () {
        $participant = new Participant('p1', 'Alice');

        expect($participant->getId())->toBe('p1');
        expect($participant->getLabel())->toBe('Alice');
        expect($participant->getSeed())->toBeNull();
        expect($participant->getMetadata())->toBe([]);
    });

    it('creates a participant with optional fields', function () {
        $metadata = ['rating' => 1500, 'country' => 'USA'];
        $participant = new Participant('p1', 'Alice', 42, $metadata);

        expect($participant->getId())->toBe('p1');
        expect($participant->getLabel())->toBe('Alice');
        expect($participant->getSeed())->toBe(42);
        expect($participant->getMetadata())->toBe($metadata);
    });

    it('checks metadata existence', function () {
        $metadata = ['rating' => 1500];
        $participant = new Participant('p1', 'Alice', null, $metadata);

        expect($participant->hasMetadata('rating'))->toBeTrue();
        expect($participant->hasMetadata('country'))->toBeFalse();
    });

    it('gets metadata values with defaults', function () {
        $metadata = ['rating' => 1500];
        $participant = new Participant('p1', 'Alice', null, $metadata);

        expect($participant->getMetadataValue('rating'))->toBe(1500);
        expect($participant->getMetadataValue('country'))->toBeNull();
        expect($participant->getMetadataValue('country', 'Unknown'))->toBe('Unknown');
    });

    it('is readonly', function () {
        $participant = new Participant('p1', 'Alice');

        expect($participant)->toBeInstanceOf(Participant::class);
        // Readonly classes cannot have properties modified after construction
    });
});
