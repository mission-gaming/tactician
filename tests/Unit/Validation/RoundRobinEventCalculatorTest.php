<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Validation\RoundRobinEventCalculator;

describe('RoundRobinEventCalculator', function (): void {
    beforeEach(function (): void {
        $this->calculator = new RoundRobinEventCalculator();
    });

    // Tests that the calculator correctly computes expected events for small tournaments
    it('calculates expected events for small tournaments', function (): void {
        // Given: Small number of participants
        $participants = [
            new Participant('p1', 'Team A'),
            new Participant('p2', 'Team B'),
            new Participant('p3', 'Team C'),
        ];

        // When: Calculating expected events for single leg
        $expectedEvents = $this->calculator->calculateExpectedEvents($participants, 1);

        // Then: Should use formula C(n,2) = n*(n-1)/2 = 3*2/2 = 3
        expect($expectedEvents)->toBe(3);
    });

    // Tests that the calculator scales correctly with participant count
    it('scales correctly with participant count', function (): void {
        // Given: Different participant counts
        $testCases = [
            2 => 1,   // C(2,2) = 2*1/2 = 1
            4 => 6,   // C(4,2) = 4*3/2 = 6
            6 => 15,  // C(6,2) = 6*5/2 = 15
            8 => 28,  // C(8,2) = 8*7/2 = 28
        ];

        foreach ($testCases as $participantCount => $expectedEvents) {
            // When: Creating participants and calculating events
            $participants = [];
            for ($i = 1; $i <= $participantCount; ++$i) {
                $participants[] = new Participant("p{$i}", "Team {$i}");
            }

            $result = $this->calculator->calculateExpectedEvents($participants, 1);

            // Then: Should match expected combinatorial result
            expect($result)->toBe($expectedEvents);
        }
    });

    // Tests that the calculator correctly handles multiple legs
    it('handles multiple legs correctly', function (): void {
        // Given: 4 participants
        $participants = [
            new Participant('p1', 'Team A'),
            new Participant('p2', 'Team B'),
            new Participant('p3', 'Team C'),
            new Participant('p4', 'Team D'),
        ];

        // When: Calculating for different leg counts
        $singleLeg = $this->calculator->calculateExpectedEvents($participants, 1);
        $doubleLeg = $this->calculator->calculateExpectedEvents($participants, 2);
        $tripleLeg = $this->calculator->calculateExpectedEvents($participants, 3);

        // Then: Should scale linearly with leg count
        expect($singleLeg)->toBe(6);   // C(4,2) = 6
        expect($doubleLeg)->toBe(12);  // 6 * 2 = 12
        expect($tripleLeg)->toBe(18);  // 6 * 3 = 18
    });

    // Tests the edge case of minimum tournament size
    it('handles minimum tournament size', function (): void {
        // Given: Minimum viable tournament (2 participants)
        $participants = [
            new Participant('p1', 'Team A'),
            new Participant('p2', 'Team B'),
        ];

        // When: Calculating expected events
        $expectedEvents = $this->calculator->calculateExpectedEvents($participants, 1);

        // Then: Should be exactly 1 event
        expect($expectedEvents)->toBe(1);
    });

    // Tests that empty participant list returns zero events
    it('handles empty participant list', function (): void {
        // Given: No participants
        $participants = [];

        // When: Calculating expected events
        $expectedEvents = $this->calculator->calculateExpectedEvents($participants, 1);

        // Then: Should be zero events
        expect($expectedEvents)->toBe(0);
    });

    // Tests that single participant returns zero events
    it('handles single participant', function (): void {
        // Given: Only one participant
        $participants = [
            new Participant('p1', 'Team A'),
        ];

        // When: Calculating expected events
        $expectedEvents = $this->calculator->calculateExpectedEvents($participants, 1);

        // Then: Cannot create any pairings
        expect($expectedEvents)->toBe(0);
    });

    // Tests that large tournaments are calculated correctly
    it('handles large tournaments', function (): void {
        // Given: Large tournament (12 participants)
        $participants = [];
        for ($i = 1; $i <= 12; ++$i) {
            $participants[] = new Participant("p{$i}", "Team {$i}");
        }

        // When: Calculating expected events
        $expectedEvents = $this->calculator->calculateExpectedEvents($participants, 1);

        // Then: Should use formula C(12,2) = 12*11/2 = 66
        expect($expectedEvents)->toBe(66);
    });

    // Tests that the calculator validates input parameters correctly
    it('handles zero legs correctly', function (): void {
        // Given: Valid participants but zero legs
        $participants = [
            new Participant('p1', 'Team A'),
            new Participant('p2', 'Team B'),
        ];

        // When: Calculating with zero legs
        $expectedEvents = $this->calculator->calculateExpectedEvents($participants, 0);

        // Then: Should return zero events
        expect($expectedEvents)->toBe(0);
    });

    // Tests the mathematical correctness of the combinatorial formula
    it('validates combinatorial mathematics', function (): void {
        // Given: Known combinatorial values
        $testCases = [
            5 => 10,   // C(5,2) = 5*4/2 = 10
            7 => 21,   // C(7,2) = 7*6/2 = 21
            10 => 45,  // C(10,2) = 10*9/2 = 45
        ];

        foreach ($testCases as $participantCount => $expectedResult) {
            // When: Creating participants and calculating
            $participants = [];
            for ($i = 1; $i <= $participantCount; ++$i) {
                $participants[] = new Participant("p{$i}", "Team {$i}");
            }

            $result = $this->calculator->calculateExpectedEvents($participants, 1);

            // Then: Should match mathematical expectation
            expect($result)->toBe($expectedResult);
        }
    });
});
