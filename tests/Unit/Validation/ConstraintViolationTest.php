<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\ConsecutiveRoleConstraint;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Validation\ConstraintViolation;

describe('ConstraintViolation', function (): void {
    beforeEach(function (): void {
        $this->participant1 = new Participant('p1', 'Alice');
        $this->participant2 = new Participant('p2', 'Bob');
        $this->event = new Event([$this->participant1, $this->participant2]);
        $this->constraint = ConsecutiveRoleConstraint::homeAway(2);
    });

    // Tests that ConstraintViolation properly stores all violation data including
    // the constraint, affected event, participants, and round information
    it('stores violation data correctly', function (): void {
        // When: Creating a constraint violation
        $violation = new ConstraintViolation(
            constraint: $this->constraint,
            rejectedEvent: $this->event,
            reason: 'Test violation',
            affectedParticipants: [$this->participant1, $this->participant2],
            roundNumber: 3
        );

        // Then: All violation data should be accessible
        expect($violation->constraint)->toBe($this->constraint);
        expect($violation->rejectedEvent)->toBe($this->event);
        expect($violation->affectedParticipants)->toBe([$this->participant1, $this->participant2]);
        expect($violation->roundNumber)->toBe(3);
        expect($violation->reason)->toBe('Test violation');
    });

    // Tests that constraint violations can be created without optional parameters
    // like round number and custom description for flexible violation reporting
    it('handles optional parameters correctly', function (): void {
        // When: Creating a violation with minimal parameters
        $violation = new ConstraintViolation(
            constraint: $this->constraint,
            rejectedEvent: $this->event,
            reason: '',
            affectedParticipants: [$this->participant1]
        );

        // Then: Optional parameters should have default values
        expect($violation->constraint)->toBe($this->constraint);
        expect($violation->rejectedEvent)->toBe($this->event);
        expect($violation->affectedParticipants)->toBe([$this->participant1]);
        expect($violation->roundNumber)->toBeNull();
        expect($violation->reason)->toBe('');
    });

    // Tests that constraint violations properly track multiple affected participants
    // for constraints that impact several teams in a single violation
    it('tracks multiple affected participants', function (): void {
        // Given: Multiple participants affected by the violation
        $participant3 = new Participant('p3', 'Carol');
        $affectedParticipants = [$this->participant1, $this->participant2, $participant3];

        // When: Creating violation with multiple participants
        $violation = new ConstraintViolation(
            constraint: $this->constraint,
            rejectedEvent: $this->event,
            reason: 'Multiple participants affected',
            affectedParticipants: $affectedParticipants
        );

        // Then: All participants should be tracked
        expect($violation->affectedParticipants)->toHaveCount(3);
        expect($violation->affectedParticipants)->toContain($this->participant1);
        expect($violation->affectedParticipants)->toContain($this->participant2);
        expect($violation->affectedParticipants)->toContain($participant3);
    });

    // Tests that constraint violations generate human-readable descriptions
    // including constraint name, round information, and affected participants
    it('generates descriptive violation messages', function (): void {
        // When: Creating a violation with complete information
        $violation = new ConstraintViolation(
            constraint: $this->constraint,
            rejectedEvent: $this->event,
            reason: 'Home/away sequence exceeded limit',
            affectedParticipants: [$this->participant1, $this->participant2],
            roundNumber: 3
        );

        // Then: Description should be informative
        $description = $violation->getDescription();
        expect($description)->toContain('Home/Away consecutive limit (2)');
        expect($description)->toContain('round 3');
        expect($description)->toContain('Home/away sequence exceeded limit');
        expect($description)->toContain('Alice');
        expect($description)->toContain('Bob');
    });

    // Tests that constraint violations handle missing round information gracefully
    // in their human-readable descriptions
    it('handles missing round information in descriptions', function (): void {
        // When: Creating a violation without round number
        $violation = new ConstraintViolation(
            constraint: $this->constraint,
            rejectedEvent: $this->event,
            reason: 'Constraint violation occurred',
            affectedParticipants: [$this->participant1]
        );

        // Then: Description should not mention round
        $description = $violation->getDescription();
        expect($description)->not->toContain('round');
        expect($description)->toContain('Home/Away consecutive limit (2)');
        expect($description)->toContain('Alice');
    });
});
