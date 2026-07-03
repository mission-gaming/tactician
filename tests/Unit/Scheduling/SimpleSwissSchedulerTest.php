<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Scheduling\SimpleSwissScheduler;
use MissionGaming\Tactician\Scheduling\SwissOptions;
use Random\Engine\Mt19937;
use Random\Randomizer;

describe('SimpleSwissScheduler', function (): void {
    it('generates the requested number of simple Swiss rounds', function (): void {
        $participants = [];
        for ($i = 1; $i <= 8; ++$i) {
            $participants[] = new Participant("p{$i}", "Player {$i}");
        }

        $scheduler = new SimpleSwissScheduler(null, new Randomizer(new Mt19937(123)));
        $schedule = $scheduler->schedule($participants, new SwissOptions(rounds: 3));

        expect($schedule->count())->toBe(12);
        expect($schedule->getMetadataValue('algorithm'))->toBe('swiss');
        expect($schedule->getMetadataValue('rounds'))->toBe(3);
        expect($schedule->getMetadataValue('total_rounds'))->toBe(3);
        expect($schedule->getMetadataValue('expected_event_count'))->toBe(12);
        expect($schedule->getMaxRound()?->getNumber())->toBe(3);

        $pairings = [];
        for ($round = 1; $round <= 3; ++$round) {
            $roundEvents = $schedule->getEventsForRound(new MissionGaming\Tactician\DTO\Round($round));
            expect($roundEvents)->toHaveCount(4);

            $participantsInRound = [];
            foreach ($roundEvents as $event) {
                $eventParticipants = $event->getParticipants();
                foreach ($eventParticipants as $participant) {
                    expect($participantsInRound)->not->toContain($participant->getId());
                    $participantsInRound[] = $participant->getId();
                }

                $pairing = [$eventParticipants[0]->getId(), $eventParticipants[1]->getId()];
                sort($pairing);
                $pairingKey = implode('-', $pairing);
                expect($pairings)->not->toContain($pairingKey);
                $pairings[] = $pairingKey;
            }
        }
    });

    it('handles odd participant counts by assigning one bye each round', function (): void {
        $participants = [];
        for ($i = 1; $i <= 5; ++$i) {
            $participants[] = new Participant("p{$i}", "Player {$i}");
        }

        $scheduler = new SimpleSwissScheduler(null, new Randomizer(new Mt19937(321)));
        $schedule = $scheduler->schedule($participants, new SwissOptions(rounds: 3));

        expect($schedule->count())->toBe(6);

        $playedCounts = [];
        for ($round = 1; $round <= 3; ++$round) {
            $roundEvents = $schedule->getEventsForRound(new MissionGaming\Tactician\DTO\Round($round));
            expect($roundEvents)->toHaveCount(2);

            $participantsInRound = [];
            foreach ($roundEvents as $event) {
                foreach ($event->getParticipants() as $participant) {
                    $participantsInRound[$participant->getId()] = true;
                    $playedCounts[$participant->getId()] = ($playedCounts[$participant->getId()] ?? 0) + 1;
                }
            }

            expect($participantsInRound)->toHaveCount(4);
        }

        expect($playedCounts)->toHaveCount(5);
        foreach ($playedCounts as $count) {
            expect($count)->toBeGreaterThanOrEqual(2);
        }
    });

    it('produces deterministic schedules with the same random seed', function (): void {
        $participants = [];
        for ($i = 1; $i <= 8; ++$i) {
            $participants[] = new Participant("p{$i}", "Player {$i}");
        }

        $firstSchedule = (new SimpleSwissScheduler(null, new Randomizer(new Mt19937(777))))
            ->schedule($participants, new SwissOptions(rounds: 3));
        $secondSchedule = (new SimpleSwissScheduler(null, new Randomizer(new Mt19937(777))))
            ->schedule($participants, new SwissOptions(rounds: 3));

        $firstPairings = array_map(
            fn ($event) => implode('-', array_map(fn ($participant) => $participant->getId(), $event->getParticipants())),
            $firstSchedule->getEvents()
        );
        $secondPairings = array_map(
            fn ($event) => implode('-', array_map(fn ($participant) => $participant->getId(), $event->getParticipants())),
            $secondSchedule->getEvents()
        );

        expect($firstPairings)->toBe($secondPairings);
    });

    it('throws when requested rounds require repeat opponents', function (): void {
        $participants = [
            new Participant('p1', 'Player 1'),
            new Participant('p2', 'Player 2'),
            new Participant('p3', 'Player 3'),
            new Participant('p4', 'Player 4'),
        ];

        expect(fn () => (new SimpleSwissScheduler())->schedule($participants, new SwissOptions(rounds: 4)))
            ->toThrow(InvalidConfigurationException::class);
    });

    it('throws when constraints prevent a complete round', function (): void {
        $participants = [
            new Participant('p1', 'Player 1'),
            new Participant('p2', 'Player 2'),
            new Participant('p3', 'Player 3'),
            new Participant('p4', 'Player 4'),
        ];

        $constraints = ConstraintSet::create()
            ->custom(fn () => false, 'Reject Everything')
            ->build();

        try {
            (new SimpleSwissScheduler($constraints))->schedule($participants, new SwissOptions(rounds: 1));
            expect(false)->toBeTrue('Expected IncompleteScheduleException');
        } catch (IncompleteScheduleException $e) {
            expect($e->getPlan()->getTotalRounds())->toBe(1);
            expect($e->getPlan()->getLegs())->toBeNull();
            expect($e->getDiagnosticReport())->toContain('Rounds: 1');
            expect($e->getDiagnosticReport())->not->toContain('Legs:');
        }
    });
});
