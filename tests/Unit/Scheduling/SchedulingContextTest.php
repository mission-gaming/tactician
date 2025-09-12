<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\SchedulingContext;

describe('SchedulingContext', function (): void {
    beforeEach(function (): void {
        $this->participant1 = new Participant('p1', 'Alice');
        $this->participant2 = new Participant('p2', 'Bob');
        $this->participant3 = new Participant('p3', 'Carol');
        $this->participant4 = new Participant('p4', 'Dave');

        $this->participants = [$this->participant1, $this->participant2, $this->participant3];

        $this->event1 = new Event([$this->participant1, $this->participant2]);
        $this->event2 = new Event([$this->participant2, $this->participant3]);
        $this->event3 = new Event([$this->participant1, $this->participant3]);

        $this->existingEvents = [$this->event1, $this->event2];
        $this->metadata = ['tournament' => 'test', 'round' => 1];
    });

    // Tests creating a basic scheduling context with only participants,
    // verifying default values for optional existing events and metadata
    it('creates a context with only participants', function (): void {
        $context = new SchedulingContext($this->participants);

        expect($context->getParticipants())->toBe($this->participants);
        expect($context->getExistingEvents())->toBe([]);
        expect($context->getMetadata())->toBe([]);
    });

    // Tests creating a context with participants and existing events,
    // verifying the context properly stores both required and optional data
    it('creates a context with participants and existing events', function (): void {
        $context = new SchedulingContext($this->participants, $this->existingEvents);

        expect($context->getParticipants())->toBe($this->participants);
        expect($context->getExistingEvents())->toBe($this->existingEvents);
        expect($context->getMetadata())->toBe([]);
    });

    // Tests creating a context with all fields populated (participants, events, metadata),
    // ensuring the context properly stores and returns all provided data
    it('creates a context with all fields', function (): void {
        $context = new SchedulingContext($this->participants, $this->existingEvents, $this->metadata);

        expect($context->getParticipants())->toBe($this->participants);
        expect($context->getExistingEvents())->toBe($this->existingEvents);
        expect($context->getMetadata())->toBe($this->metadata);
    });

    // Tests the hasMetadata method to verify it correctly identifies whether
    // specific metadata keys exist in the context or not
    it('checks metadata existence', function (): void {
        $context = new SchedulingContext($this->participants, [], $this->metadata);

        expect($context->hasMetadata('tournament'))->toBeTrue();
        expect($context->hasMetadata('round'))->toBeTrue();
        expect($context->hasMetadata('nonexistent'))->toBeFalse();
    });

    // Tests that hasMetadata returns false for empty metadata
    it('checks metadata existence with empty metadata', function (): void {
        $context = new SchedulingContext($this->participants);

        expect($context->hasMetadata('tournament'))->toBeFalse();
        expect($context->hasMetadata('round'))->toBeFalse();
    });

    // Tests retrieving metadata values with support for default values when
    // the requested metadata key doesn't exist in the context
    it('gets metadata values with defaults', function (): void {
        $context = new SchedulingContext($this->participants, [], $this->metadata);

        expect($context->getMetadataValue('tournament'))->toBe('test');
        expect($context->getMetadataValue('round'))->toBe(1);
        expect($context->getMetadataValue('nonexistent'))->toBeNull();
        expect($context->getMetadataValue('nonexistent', 'default'))->toBe('default');
    });

    // Tests getMetadataValue with empty metadata returns defaults properly
    it('gets metadata values with empty metadata', function (): void {
        $context = new SchedulingContext($this->participants);

        expect($context->getMetadataValue('tournament'))->toBeNull();
        expect($context->getMetadataValue('tournament', 'default'))->toBe('default');
    });

    // Tests that haveParticipantsPlayed correctly identifies when two participants
    // have competed against each other in previous events
    it('identifies when participants have played together', function (): void {
        $context = new SchedulingContext($this->participants, $this->existingEvents);

        // participant1 and participant2 played in event1
        expect($context->haveParticipantsPlayed($this->participant1, $this->participant2))->toBeTrue();
        expect($context->haveParticipantsPlayed($this->participant2, $this->participant1))->toBeTrue();

        // participant2 and participant3 played in event2
        expect($context->haveParticipantsPlayed($this->participant2, $this->participant3))->toBeTrue();
        expect($context->haveParticipantsPlayed($this->participant3, $this->participant2))->toBeTrue();

        // participant1 and participant3 have not played (not in existing events)
        expect($context->haveParticipantsPlayed($this->participant1, $this->participant3))->toBeFalse();
        expect($context->haveParticipantsPlayed($this->participant3, $this->participant1))->toBeFalse();
    });

    // Tests haveParticipantsPlayed returns false when no existing events are present
    it('returns false when no existing events', function (): void {
        $context = new SchedulingContext($this->participants);

        expect($context->haveParticipantsPlayed($this->participant1, $this->participant2))->toBeFalse();
        expect($context->haveParticipantsPlayed($this->participant2, $this->participant3))->toBeFalse();
    });

    // Tests haveParticipantsPlayed with participants not in any existing events
    it('returns false when participants are not in any existing events', function (): void {
        $context = new SchedulingContext($this->participants, $this->existingEvents);

        expect($context->haveParticipantsPlayed($this->participant1, $this->participant4))->toBeFalse();
        expect($context->haveParticipantsPlayed($this->participant4, $this->participant2))->toBeFalse();
    });

    // Tests haveParticipantsPlayed when participant played with themselves (edge case)
    it('handles same participant comparison', function (): void {
        $context = new SchedulingContext($this->participants, $this->existingEvents);

        expect($context->haveParticipantsPlayed($this->participant1, $this->participant1))->toBeFalse();
    });

    // Tests getEventsForParticipant returns all events where a specific participant
    // is involved, filtering the existing events correctly
    it('gets events for specific participant', function (): void {
        $context = new SchedulingContext($this->participants, $this->existingEvents);

        // participant1 is in event1 only
        $eventsForP1 = $context->getEventsForParticipant($this->participant1);
        expect($eventsForP1)->toHaveCount(1);
        expect($eventsForP1)->toContain($this->event1);

        // participant2 is in both event1 and event2
        $eventsForP2 = $context->getEventsForParticipant($this->participant2);
        expect($eventsForP2)->toHaveCount(2);
        expect($eventsForP2)->toContain($this->event1);
        expect($eventsForP2)->toContain($this->event2);

        // participant3 is in event2 only
        $eventsForP3 = $context->getEventsForParticipant($this->participant3);
        expect($eventsForP3)->toHaveCount(1);
        expect($eventsForP3)->toContain($this->event2);
    });

    // Tests getEventsForParticipant returns empty array when participant
    // is not involved in any existing events
    it('gets empty events for participant not in any events', function (): void {
        $context = new SchedulingContext($this->participants, $this->existingEvents);

        $eventsForP4 = $context->getEventsForParticipant($this->participant4);
        expect($eventsForP4)->toBeEmpty();
    });

    // Tests getEventsForParticipant with no existing events returns empty array
    it('gets empty events when no existing events', function (): void {
        $context = new SchedulingContext($this->participants);

        $eventsForP1 = $context->getEventsForParticipant($this->participant1);
        expect($eventsForP1)->toBeEmpty();
    });

    // Tests that withEvents creates a new context instance with additional events
    // while preserving the original context's immutability
    it('creates new context with additional events', function (): void {
        $originalContext = new SchedulingContext($this->participants, $this->existingEvents, $this->metadata);
        $newEvents = [$this->event3];

        $newContext = $originalContext->withEvents($newEvents);

        // Original context unchanged
        expect($originalContext->getExistingEvents())->toHaveCount(2);
        expect($originalContext->getExistingEvents())->toBe($this->existingEvents);

        // New context has additional events
        expect($newContext->getExistingEvents())->toHaveCount(3);
        expect($newContext->getExistingEvents())->toContain($this->event1);
        expect($newContext->getExistingEvents())->toContain($this->event2);
        expect($newContext->getExistingEvents())->toContain($this->event3);

        // Other properties preserved
        expect($newContext->getParticipants())->toBe($this->participants);
        expect($newContext->getMetadata())->toBe($this->metadata);
    });

    // Tests withEvents with empty array still creates new instance
    it('creates new context with empty events array', function (): void {
        $originalContext = new SchedulingContext($this->participants, $this->existingEvents);
        $newContext = $originalContext->withEvents([]);

        // Should be different instances
        expect($newContext)->not->toBe($originalContext);

        // Events should be the same
        expect($newContext->getExistingEvents())->toBe($originalContext->getExistingEvents());
    });

    // Tests withEvents maintains immutability - original context is unchanged
    it('maintains immutability when adding events', function (): void {
        $originalContext = new SchedulingContext($this->participants, [$this->event1]);
        $originalEventCount = count($originalContext->getExistingEvents());

        $newContext = $originalContext->withEvents([$this->event2, $this->event3]);

        // Original should be unchanged
        expect($originalContext->getExistingEvents())->toHaveCount($originalEventCount);
        expect($newContext->getExistingEvents())->toHaveCount($originalEventCount + 2);
    });

    // Tests withEvents functionality affects haveParticipantsPlayed results
    it('withEvents affects participant played status', function (): void {
        $originalContext = new SchedulingContext($this->participants, [$this->event1]);

        // Initially participant1 and participant3 haven't played
        expect($originalContext->haveParticipantsPlayed($this->participant1, $this->participant3))->toBeFalse();

        // Add event where they play together
        $newContext = $originalContext->withEvents([$this->event3]);

        // Now they have played
        expect($newContext->haveParticipantsPlayed($this->participant1, $this->participant3))->toBeTrue();
    });

    // Tests withEvents functionality affects getEventsForParticipant results
    it('withEvents affects events for participant', function (): void {
        $originalContext = new SchedulingContext($this->participants, [$this->event1]);

        // Initially participant3 has no events
        expect($originalContext->getEventsForParticipant($this->participant3))->toBeEmpty();

        // Add event involving participant3
        $newContext = $originalContext->withEvents([$this->event2]);

        // Now participant3 has events
        expect($newContext->getEventsForParticipant($this->participant3))->toHaveCount(1);
        expect($newContext->getEventsForParticipant($this->participant3))->toContain($this->event2);
    });

    // Tests that SchedulingContext handles complex event relationships correctly
    it('handles complex event relationships', function (): void {
        $multiParticipantEvent = new Event([$this->participant1, $this->participant2, $this->participant3]);
        $context = new SchedulingContext($this->participants, [$multiParticipantEvent]);

        // All participants should have played with each other in the multi-participant event
        expect($context->haveParticipantsPlayed($this->participant1, $this->participant2))->toBeTrue();
        expect($context->haveParticipantsPlayed($this->participant1, $this->participant3))->toBeTrue();
        expect($context->haveParticipantsPlayed($this->participant2, $this->participant3))->toBeTrue();

        // All participants should have this event in their event list
        expect($context->getEventsForParticipant($this->participant1))->toContain($multiParticipantEvent);
        expect($context->getEventsForParticipant($this->participant2))->toContain($multiParticipantEvent);
        expect($context->getEventsForParticipant($this->participant3))->toContain($multiParticipantEvent);
    });

    // Tests that SchedulingContext is readonly (immutable)
    it('is readonly', function (): void {
        $context = new SchedulingContext($this->participants, $this->existingEvents, $this->metadata);

        expect($context)->toBeInstanceOf(SchedulingContext::class);
        // Readonly classes cannot have properties modified after construction
    });
});
