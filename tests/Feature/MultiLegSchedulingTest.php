<?php

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\LegStrategies\MirroredLegStrategy;
use MissionGaming\Tactician\LegStrategies\RepeatedLegStrategy;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

// Tests that the scheduler can generate a 2-leg tournament where the second leg mirrors
// the first leg (same pairings, roles reversed), doubling the total matches
it('generates multi-leg schedule with mirrored strategy', function (): void {
    $participants = [
        new Participant('team-a', 'Team A'),
        new Participant('team-b', 'Team B'),
        new Participant('team-c', 'Team C'),
        new Participant('team-d', 'Team D'),
    ];

    $scheduler = new RoundRobinScheduler();

    $schedule = $scheduler->generateSchedule(
        $participants,
        2, // legs
        new MirroredLegStrategy()
    );

    // Should have 2 legs worth of events
    expect(count($schedule))->toBe(12); // 6 events per leg × 2 legs

    // Check metadata includes leg information
    expect($schedule->getMetadataValue('legs'))->toBe(2);
    expect($schedule->getMetadataValue('rounds_per_leg'))->toBe(3);
    expect($schedule->getMetadataValue('total_rounds'))->toBe(6);

    // Check round numbering is continuous
    $rounds = [];
    foreach ($schedule as $event) {
        $rounds[] = $event->getRound()?->getNumber();
    }
    sort($rounds);
    expect($rounds)->toBe([1, 1, 2, 2, 3, 3, 4, 4, 5, 5, 6, 6]);

    // First leg should have rounds 1-3, second leg should have rounds 4-6
    $leg1Events = array_filter(
        iterator_to_array($schedule),
        fn ($event) => $event->getRound()?->getNumber() <= 3
    );
    $leg2Events = array_filter(
        iterator_to_array($schedule),
        fn ($event) => $event->getRound()?->getNumber() > 3
    );

    expect(count($leg1Events))->toBe(6);
    expect(count($leg2Events))->toBe(6);
});

// Tests that the scheduler can generate a 2-leg tournament using repeated strategy
// where each leg repeats the same structure, creating consistent tournament rounds
it('generates multi-leg schedule with repeated strategy', function (): void {
    $participants = [
        new Participant('team-a', 'Team A'),
        new Participant('team-b', 'Team B'),
        new Participant('team-c', 'Team C'),
        new Participant('team-d', 'Team D'),
    ];

    $scheduler = new RoundRobinScheduler();

    $schedule = $scheduler->generateSchedule(
        $participants,
        2, // legs
        new RepeatedLegStrategy()
    );

    expect(count($schedule))->toBe(12); // 6 events per leg × 2 legs
    expect($schedule->getMetadataValue('legs'))->toBe(2);
    expect($schedule->getMetadataValue('total_rounds'))->toBe(6);
});

// Tests backward compatibility by ensuring single-leg scheduling (the default)
// works exactly the same as it did before multi-leg functionality was added
it('single leg schedule works same as before', function (): void {
    $participants = [
        new Participant('team-a', 'Team A'),
        new Participant('team-b', 'Team B'),
        new Participant('team-c', 'Team C'),
        new Participant('team-d', 'Team D'),
    ];

    $scheduler = new RoundRobinScheduler(); // Default: 1 leg

    $schedule = $scheduler->generateSchedule($participants);

    expect(count($schedule))->toBe(6); // 6 events for single leg
    // Single-leg schedules don't have 'legs' metadata since it's always 1
    expect($schedule->getMetadataValue('legs', 1))->toBe(1);
    expect($schedule->getMetadataValue('rounds_per_leg'))->toBe(3);
    expect($schedule->getMetadataValue('total_rounds'))->toBe(3);
});
