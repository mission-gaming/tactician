<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\ConsecutiveRoleConstraint;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Validation\ConstraintViolation;
use MissionGaming\Tactician\Validation\ConstraintViolationCollector;

describe('ConstraintViolationCollector', function (): void {
    beforeEach(function (): void {
        $this->collector = new ConstraintViolationCollector();
        $this->participant1 = new Participant('p1', 'Alice');
        $this->participant2 = new Participant('p2', 'Bob');
        $this->event = new Event([$this->participant1, $this->participant2]);
        $this->constraint = ConsecutiveRoleConstraint::homeAway(2);
    });

    // Tests that violation collector initializes empty and provides proper counts
    it('initializes empty', function (): void {
        // Then: Should start with no violations
        expect($this->collector->getViolations())->toBeEmpty();
        expect($this->collector->hasViolations())->toBeFalse();
        expect($this->collector->getViolationCount())->toBe(0);
    });

    // Tests that violations can be added and retrieved from the collector
    it('records and retrieves violations', function (): void {
        // Given: A constraint violation
        $violation = new ConstraintViolation(
            constraint: $this->constraint,
            rejectedEvent: $this->event,
            reason: 'Test violation',
            affectedParticipants: [$this->participant1]
        );

        // When: Recording the violation
        $this->collector->recordViolation($violation);

        // Then: Should be available in collection
        expect($this->collector->hasViolations())->toBeTrue();
        expect($this->collector->getViolationCount())->toBe(1);
        expect($this->collector->getViolations())->toHaveCount(1);
        expect($this->collector->getViolations()[0])->toBe($violation);
    });

    // Tests that multiple violations can be added and order is preserved
    it('records multiple violations and preserves order', function (): void {
        // Given: Multiple violations
        $violation1 = new ConstraintViolation(
            constraint: $this->constraint,
            rejectedEvent: $this->event,
            reason: 'First violation',
            affectedParticipants: [$this->participant1]
        );

        $violation2 = new ConstraintViolation(
            constraint: $this->constraint,
            rejectedEvent: $this->event,
            reason: 'Second violation',
            affectedParticipants: [$this->participant2]
        );

        // When: Recording violations in order
        $this->collector->recordViolation($violation1);
        $this->collector->recordViolation($violation2);

        // Then: Should maintain order
        expect($this->collector->getViolationCount())->toBe(2);
        $violations = $this->collector->getViolations();
        expect($violations[0])->toBe($violation1);
        expect($violations[1])->toBe($violation2);
    });

    // Tests that violations can be grouped by constraint name for analysis
    it('groups violations by constraint', function (): void {
        // Given: Multiple violations for different constraints
        $homeAwayConstraint = ConsecutiveRoleConstraint::homeAway(2);
        $positionConstraint = ConsecutiveRoleConstraint::position(3);

        $violation1 = new ConstraintViolation(
            constraint: $homeAwayConstraint,
            rejectedEvent: $this->event,
            reason: 'Home/away violation',
            affectedParticipants: [$this->participant1]
        );

        $violation2 = new ConstraintViolation(
            constraint: $positionConstraint,
            rejectedEvent: $this->event,
            reason: 'Position violation',
            affectedParticipants: [$this->participant2]
        );

        $this->collector->recordViolation($violation1);
        $this->collector->recordViolation($violation2);

        // When: Grouping by constraint
        $grouped = $this->collector->getViolationsByConstraint();

        // Then: Should be grouped correctly
        expect($grouped)->toHaveKey('Home/Away consecutive limit (2)');
        expect($grouped)->toHaveKey('Position consecutive limit (3)');
        expect($grouped['Home/Away consecutive limit (2)'])->toContain($violation1);
        expect($grouped['Position consecutive limit (3)'])->toContain($violation2);
    });

    // Tests that violations can be grouped by participant for analysis
    it('groups violations by participant', function (): void {
        // Given: Violations affecting different participants
        $violation1 = new ConstraintViolation(
            constraint: $this->constraint,
            rejectedEvent: $this->event,
            reason: 'First violation',
            affectedParticipants: [$this->participant1]
        );

        $violation2 = new ConstraintViolation(
            constraint: $this->constraint,
            rejectedEvent: $this->event,
            reason: 'Second violation',
            affectedParticipants: [$this->participant1, $this->participant2]
        );

        $this->collector->recordViolation($violation1);
        $this->collector->recordViolation($violation2);

        // When: Grouping by participant
        $grouped = $this->collector->getViolationsByParticipant();

        // Then: Should group by participant ID
        expect($grouped)->toHaveKey('p1'); // Alice
        expect($grouped)->toHaveKey('p2'); // Bob
        expect($grouped['p1'])->toHaveCount(2); // Both violations affect Alice
        expect($grouped['p2'])->toHaveCount(1); // Only second violation affects Bob
    });

    // Tests that violation counts can be retrieved by constraint type
    it('counts violations by constraint', function (): void {
        // Given: Multiple violations for the same constraint
        $violation1 = new ConstraintViolation(
            constraint: $this->constraint,
            rejectedEvent: $this->event,
            reason: 'First violation',
            affectedParticipants: [$this->participant1]
        );

        $violation2 = new ConstraintViolation(
            constraint: $this->constraint,
            rejectedEvent: $this->event,
            reason: 'Second violation',
            affectedParticipants: [$this->participant2]
        );

        $this->collector->recordViolation($violation1);
        $this->collector->recordViolation($violation2);

        // When: Getting violation counts by constraint
        $counts = $this->collector->getViolationCountsByConstraint();

        // Then: Should count violations correctly
        expect($counts)->toHaveKey('Home/Away consecutive limit (2)');
        expect($counts['Home/Away consecutive limit (2)'])->toBe(2);
    });

    // Tests that affected rounds can be identified from violations
    it('identifies affected rounds', function (): void {
        // Given: Violations in different rounds
        $violation1 = new ConstraintViolation(
            constraint: $this->constraint,
            rejectedEvent: $this->event,
            reason: 'Round 2 violation',
            affectedParticipants: [$this->participant1],
            roundNumber: 2
        );

        $violation2 = new ConstraintViolation(
            constraint: $this->constraint,
            rejectedEvent: $this->event,
            reason: 'Round 4 violation',
            affectedParticipants: [$this->participant2],
            roundNumber: 4
        );

        $violation3 = new ConstraintViolation(
            constraint: $this->constraint,
            rejectedEvent: $this->event,
            reason: 'Unknown round violation',
            affectedParticipants: [$this->participant1]
        );

        $this->collector->recordViolation($violation1);
        $this->collector->recordViolation($violation2);
        $this->collector->recordViolation($violation3);

        // When: Getting affected rounds
        $rounds = $this->collector->getAffectedRounds();

        // Then: Should identify the correct rounds
        expect($rounds)->toContain(2);
        expect($rounds)->toContain(4);
        expect($rounds)->not->toContain(null);
        expect($rounds)->toHaveCount(2);
    });
});
