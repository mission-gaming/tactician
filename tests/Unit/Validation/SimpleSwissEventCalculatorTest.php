<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Validation\ScheduleValidationContext;
use MissionGaming\Tactician\Validation\SimpleSwissEventCalculator;

describe('SimpleSwissEventCalculator', function (): void {
    beforeEach(function (): void {
        $this->calculator = new SimpleSwissEventCalculator();
    });

    it('calculates expected events from rounds rather than legs', function (): void {
        $participants = [];
        for ($i = 1; $i <= 8; ++$i) {
            $participants[] = new Participant("p{$i}", "Player {$i}");
        }

        expect($this->calculator->calculateExpectedEvents($participants, 3))->toBe(12);
        expect($this->calculator->calculateExpectedEvents($participants, 0))->toBe(0);
    });

    it('handles odd participant counts with one bye per round', function (): void {
        $participants = [];
        for ($i = 1; $i <= 5; ++$i) {
            $participants[] = new Participant("p{$i}", "Player {$i}");
        }

        expect($this->calculator->calculateExpectedEvents($participants, 3))->toBe(6);
    });

    it('passes integrity validation for a non-repeat subset of opponents', function (): void {
        $participant1 = new Participant('p1', 'Player 1');
        $participant2 = new Participant('p2', 'Player 2');
        $participant3 = new Participant('p3', 'Player 3');
        $participant4 = new Participant('p4', 'Player 4');
        $participants = [$participant1, $participant2, $participant3, $participant4];

        $schedule = new Schedule([
            new Event([$participant1, $participant2], new Round(1)),
            new Event([$participant3, $participant4], new Round(1)),
            new Event([$participant1, $participant3], new Round(2)),
            new Event([$participant2, $participant4], new Round(2)),
        ]);

        $violations = $this->calculator->validateScheduleIntegrity(
            $schedule,
            $participants,
            ScheduleValidationContext::forAlgorithm('Simple Swiss', 2)
        );

        expect($violations)->toBe([]);
    });

    it('detects repeat pairings and duplicate round appearances', function (): void {
        $participant1 = new Participant('p1', 'Player 1');
        $participant2 = new Participant('p2', 'Player 2');
        $participant3 = new Participant('p3', 'Player 3');
        $participant4 = new Participant('p4', 'Player 4');
        $participants = [$participant1, $participant2, $participant3, $participant4];

        $schedule = new Schedule([
            new Event([$participant1, $participant2], new Round(1)),
            new Event([$participant1, $participant3], new Round(1)),
            new Event([$participant2, $participant1], new Round(2)),
            new Event([$participant3, $participant4], new Round(2)),
        ]);

        $violations = $this->calculator->validateScheduleIntegrity(
            $schedule,
            $participants,
            ScheduleValidationContext::forAlgorithm('Simple Swiss', 2)
        );

        expect($violations)->toContain('Participant p1 appears more than once in round 1.');
        expect($violations)->toContain('Pairing p1 vs p2 appears 2 time(s); simple Swiss pairings may not repeat.');
    });
});
