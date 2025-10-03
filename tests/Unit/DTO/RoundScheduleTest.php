<?php

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\DTO\RoundSchedule;

describe('RoundSchedule', function (): void {
    it('creates a round schedule', function (): void {
        $participant1 = new Participant('team1', 'Team 1');
        $participant2 = new Participant('team2', 'Team 2');
        $round = new Round(1);
        $event = new Event([$participant1, $participant2], $round);

        $roundSchedule = new RoundSchedule(1, [$event]);

        expect($roundSchedule->getRoundNumber())->toBe(1);
        expect($roundSchedule->getEventCount())->toBe(1);
        expect($roundSchedule->getEvents())->toBe([$event]);
    });

    it('rejects round number less than 1', function (): void {
        expect(fn () => new RoundSchedule(0, []))
            ->toThrow(InvalidArgumentException::class, 'Round number must be at least 1');
    });

    it('gets all participants in the round', function (): void {
        $team1 = new Participant('team1', 'Team 1');
        $team2 = new Participant('team2', 'Team 2');
        $team3 = new Participant('team3', 'Team 3');
        $team4 = new Participant('team4', 'Team 4');

        $round = new Round(1);
        $event1 = new Event([$team1, $team2], $round);
        $event2 = new Event([$team3, $team4], $round);

        $roundSchedule = new RoundSchedule(1, [$event1, $event2]);

        $participants = $roundSchedule->getParticipants();

        expect(count($participants))->toBe(4);
        expect($participants[0]->getId())->toBe('team1');
        expect($participants[1]->getId())->toBe('team2');
        expect($participants[2]->getId())->toBe('team3');
        expect($participants[3]->getId())->toBe('team4');
    });

    it('deduplicates participants', function (): void {
        // Same participant in multiple events shouldn't be duplicated
        $team1 = new Participant('team1', 'Team 1');
        $team2 = new Participant('team2', 'Team 2');

        $round = new Round(1);
        $event1 = new Event([$team1, $team2], $round);
        $event2 = new Event([$team1, $team2], $round); // Duplicate pairing

        $roundSchedule = new RoundSchedule(1, [$event1, $event2]);

        $participants = $roundSchedule->getParticipants();

        expect(count($participants))->toBe(2);
    });

    it('checks if participant is in round', function (): void {
        $team1 = new Participant('team1', 'Team 1');
        $team2 = new Participant('team2', 'Team 2');
        $team3 = new Participant('team3', 'Team 3');

        $round = new Round(1);
        $event = new Event([$team1, $team2], $round);

        $roundSchedule = new RoundSchedule(1, [$event]);

        expect($roundSchedule->hasParticipant($team1))->toBeTrue();
        expect($roundSchedule->hasParticipant($team2))->toBeTrue();
        expect($roundSchedule->hasParticipant($team3))->toBeFalse();
    });
});
