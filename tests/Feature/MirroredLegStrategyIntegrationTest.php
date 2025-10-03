<?php

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\LegStrategies\MirroredLegStrategy;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

describe('MirroredLegStrategy Integration', function (): void {
    it('actually reverses participant order in full schedule generation', function (): void {
        $participants = [
            new Participant('celtic', 'Celtic'),
            new Participant('athletic', 'Athletic Bilbao'),
            new Participant('livorno', 'AS Livorno'),
            new Participant('redstar', 'Red Star FC'),
        ];

        $scheduler = new RoundRobinScheduler();
        $schedule = $scheduler->generateMultiLegSchedule(
            participants: $participants,
            legs: 2,
            legStrategy: new MirroredLegStrategy()
        );

        $roundsPerLeg = $schedule->getMetadataValue('rounds_per_leg');

        // Collect first leg and second leg events for comparison
        $firstLegEvents = [];
        $secondLegEvents = [];

        foreach ($schedule as $event) {
            $roundNumber = $event->getRound()?->getNumber() ?? 0;
            if ($roundNumber <= $roundsPerLeg) {
                $firstLegEvents[] = $event;
            } else {
                $secondLegEvents[] = $event;
            }
        }

        expect(count($firstLegEvents))->toBe(count($secondLegEvents));
        expect(count($firstLegEvents))->toBeGreaterThan(0);

        // For debugging: let's check the actual participant order
        $firstEvent = $firstLegEvents[0];
        $correspondingSecondEvent = $secondLegEvents[0];

        $firstLegParticipants = $firstEvent->getParticipants();
        $secondLegParticipants = $correspondingSecondEvent->getParticipants();

        // They should be the same participants, but in reversed order
        expect($firstLegParticipants[0]->getId())->toBe($secondLegParticipants[1]->getId());
        expect($firstLegParticipants[1]->getId())->toBe($secondLegParticipants[0]->getId());

        // Test a few more events to ensure consistency
        if (count($firstLegEvents) > 1) {
            $firstEvent2 = $firstLegEvents[1];
            $correspondingSecondEvent2 = $secondLegEvents[1];

            $firstLegParticipants2 = $firstEvent2->getParticipants();
            $secondLegParticipants2 = $correspondingSecondEvent2->getParticipants();

            expect($firstLegParticipants2[0]->getId())->toBe($secondLegParticipants2[1]->getId());
            expect($firstLegParticipants2[1]->getId())->toBe($secondLegParticipants2[0]->getId());
        }
    });

    it('demonstrates the Celtic always home issue in examples', function (): void {
        $participants = [
            new Participant('celtic', 'Celtic'),
            new Participant('athletic', 'Athletic Bilbao'),
            new Participant('livorno', 'AS Livorno'),
            new Participant('redstar', 'Red Star FC'),
        ];

        $scheduler = new RoundRobinScheduler();
        $schedule = $scheduler->generateMultiLegSchedule(
            participants: $participants,
            legs: 2,
            legStrategy: new MirroredLegStrategy()
        );

        $celticHomeCount = 0;
        $celticAwayCount = 0;

        foreach ($schedule as $event) {
            $eventParticipants = $event->getParticipants();

            // Check if Celtic is involved and count home/away
            if ($eventParticipants[0]->getId() === 'celtic') {
                ++$celticHomeCount;
            } elseif ($eventParticipants[1]->getId() === 'celtic') {
                ++$celticAwayCount;
            }
        }

        // If MirroredLegStrategy is working, Celtic should play both home and away
        expect($celticHomeCount)->toBeGreaterThan(0);
        expect($celticAwayCount)->toBeGreaterThan(0);
        expect($celticHomeCount)->toBe($celticAwayCount); // Should be equal for mirrored strategy
    });
});
