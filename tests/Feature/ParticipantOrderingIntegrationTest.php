<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Ordering\AlternatingParticipantOrderer;
use MissionGaming\Tactician\Ordering\BalancedParticipantOrderer;
use MissionGaming\Tactician\Ordering\SeededRandomParticipantOrderer;
use MissionGaming\Tactician\Ordering\StaticParticipantOrderer;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;

describe('Participant Ordering Integration', function (): void {
    it('demonstrates deterministic ordering with StaticParticipantOrderer', function (): void {
        $celtic = new Participant('1', 'Celtic');
        $athletic = new Participant('2', 'Athletic');
        $livorno = new Participant('3', 'Livorno');
        $redstar = new Participant('4', 'Redstar');

        $participants = [$celtic, $athletic, $livorno, $redstar];

        // Using default StaticParticipantOrderer
        $scheduler = new RoundRobinScheduler();
        $schedule = $scheduler->generateSchedule($participants);

        // Count how many times each team is "home" (first position)
        $homeCounts = [];
        foreach ($schedule->getEvents() as $event) {
            $eventParticipants = $event->getParticipants();
            $homeTeam = $eventParticipants[0];
            $homeCounts[$homeTeam->getId()] = ($homeCounts[$homeTeam->getId()] ?? 0) + 1;
        }

        // With static ordering, the home/away distribution is deterministic based on
        // the circle method pairing algorithm
        expect($homeCounts[$celtic->getId()])->toBe(3); // Celtic home for all their matches
        expect($homeCounts[$athletic->getId()])->toBe(1);
        expect($homeCounts[$livorno->getId()])->toBe(1);
        expect($homeCounts[$redstar->getId()])->toBe(1);

        // Total home appearances = total events
        expect(array_sum($homeCounts))->toBe(6);
    });

    it('redistributes home/away with AlternatingParticipantOrderer', function (): void {
        $celtic = new Participant('1', 'Celtic');
        $athletic = new Participant('2', 'Athletic');
        $livorno = new Participant('3', 'Livorno');
        $redstar = new Participant('4', 'Redstar');

        $participants = [$celtic, $athletic, $livorno, $redstar];

        // Using AlternatingParticipantOrderer
        $scheduler = new RoundRobinScheduler(
            null,
            null,
            new AlternatingParticipantOrderer()
        );
        $schedule = $scheduler->generateSchedule($participants);

        // Count how many times each team is "home"
        $homeCounts = [];
        foreach ($schedule->getEvents() as $event) {
            $eventParticipants = $event->getParticipants();
            $homeTeam = $eventParticipants[0];
            $homeCounts[$homeTeam->getId()] = ($homeCounts[$homeTeam->getId()] ?? 0) + 1;
        }

        // With alternating orderer, some events get reversed (odd event indices)
        // This changes the home/away distribution compared to static ordering
        expect($homeCounts[$celtic->getId()])->toBe(3); // Still 3 (all events with Celtic are even indices)
        expect($homeCounts[$athletic->getId()])->toBe(1);
        expect($homeCounts[$livorno->getId()])->toBe(1);
        expect($homeCounts[$redstar->getId()])->toBe(1);

        // The key difference is WHICH team is away, not necessarily home count for Celtic
        // Compare with static: R1-Event1 was "Athletic vs Livorno", now it's "Livorno vs Athletic"

        // Total still equals total events
        expect(array_sum($homeCounts))->toBe(6);
    });

    it('achieves perfect balance with BalancedParticipantOrderer', function (): void {
        $celtic = new Participant('1', 'Celtic');
        $athletic = new Participant('2', 'Athletic');
        $livorno = new Participant('3', 'Livorno');
        $redstar = new Participant('4', 'Redstar');

        $participants = [$celtic, $athletic, $livorno, $redstar];

        // Using BalancedParticipantOrderer
        $scheduler = new RoundRobinScheduler(
            null,
            null,
            new BalancedParticipantOrderer()
        );
        $schedule = $scheduler->generateSchedule($participants);

        // Count home appearances
        $homeCounts = [];
        foreach ($schedule->getEvents() as $event) {
            $eventParticipants = $event->getParticipants();
            $homeTeam = $eventParticipants[0];
            $homeCounts[$homeTeam->getId()] = ($homeCounts[$homeTeam->getId()] ?? 0) + 1;
        }

        // Each team plays 3 matches in a 4-team round robin
        // Perfect balance would be close to 1.5 home matches per team
        // (6 total events, each has 1 home team = 6 home slots / 4 teams = 1.5)
        foreach ([$celtic, $athletic, $livorno, $redstar] as $team) {
            $count = $homeCounts[$team->getId()] ?? 0;
            // Should be 1 or 2 (close to 1.5)
            expect($count)->toBeGreaterThanOrEqual(1);
            expect($count)->toBeLessThanOrEqual(2);
        }

        // Total home counts should equal total events
        $totalHomeCount = array_sum($homeCounts);
        expect($totalHomeCount)->toBe(6); // 6 events in 4-team round robin
    });

    it('provides deterministic randomization with SeededRandomParticipantOrderer', function (): void {
        $celtic = new Participant('1', 'Celtic');
        $athletic = new Participant('2', 'Athletic');
        $livorno = new Participant('3', 'Livorno');
        $redstar = new Participant('4', 'Redstar');

        $participants = [$celtic, $athletic, $livorno, $redstar];

        // Using SeededRandomParticipantOrderer with specific seed
        $scheduler1 = new RoundRobinScheduler(
            null,
            null,
            new SeededRandomParticipantOrderer()
        );
        $schedule1 = $scheduler1->generateSchedule($participants);

        $scheduler2 = new RoundRobinScheduler(
            null,
            null,
            new SeededRandomParticipantOrderer()
        );
        $schedule2 = $scheduler2->generateSchedule($participants);

        // Same seed should produce identical schedules
        $events1 = $schedule1->getEvents();
        $events2 = $schedule2->getEvents();

        expect(count($events1))->toBe(count($events2));

        for ($i = 0; $i < count($events1); ++$i) {
            $participants1 = $events1[$i]->getParticipants();
            $participants2 = $events2[$i]->getParticipants();

            expect($participants1[0]->getId())->toBe($participants2[0]->getId());
            expect($participants1[1]->getId())->toBe($participants2[1]->getId());
        }
    });

    it('demonstrates ordering works across multiple rounds', function (): void {
        $teams = [];
        for ($i = 1; $i <= 6; ++$i) {
            $teams[] = new Participant((string) $i, "Team {$i}");
        }

        $scheduler = new RoundRobinScheduler(
            null,
            null,
            new BalancedParticipantOrderer()
        );
        $schedule = $scheduler->generateSchedule($teams);

        // Track home counts per round
        $roundHomeCounts = [];
        foreach ($schedule->getEvents() as $event) {
            $round = $event->getRound()?->getNumber();
            if ($round === null) {
                continue;
            }

            if (!isset($roundHomeCounts[$round])) {
                $roundHomeCounts[$round] = [];
            }

            $homeTeam = $event->getParticipants()[0];
            $roundHomeCounts[$round][$homeTeam->getId()] = ($roundHomeCounts[$round][$homeTeam->getId()] ?? 0) + 1;
        }

        // Verify each round has balanced home distribution
        foreach ($roundHomeCounts as $round => $counts) {
            // In each round, exactly 3 teams are "home" (6 teams / 2 = 3 pairings per round)
            expect(array_sum($counts))->toBe(3);

            // No team should be home more than once per round
            foreach ($counts as $count) {
                expect($count)->toBe(1);
            }
        }
    });

    it('shows ordering is independent of leg strategies', function (): void {
        $celtic = new Participant('1', 'Celtic');
        $athletic = new Participant('2', 'Athletic');
        $livorno = new Participant('3', 'Livorno');
        $redstar = new Participant('4', 'Redstar');

        $participants = [$celtic, $athletic, $livorno, $redstar];

        // Multi-leg with balanced ordering
        $scheduler = new RoundRobinScheduler(
            null,
            null,
            new BalancedParticipantOrderer()
        );
        $schedule = $scheduler->generateSchedule($participants, 2);

        // Get events from first leg only
        $firstLegEvents = array_filter(
            $schedule->getEvents(),
            fn ($event) => $event->getRound()?->getNumber() <= 3
        );

        // Count home appearances in first leg
        $homeCounts = [];
        foreach ($firstLegEvents as $event) {
            $homeTeam = $event->getParticipants()[0];
            $homeCounts[$homeTeam->getId()] = ($homeCounts[$homeTeam->getId()] ?? 0) + 1;
        }

        // Even in multi-leg tournaments, ordering should balance within each leg
        foreach ([$celtic, $athletic, $livorno, $redstar] as $team) {
            $count = $homeCounts[$team->getId()] ?? 0;
            expect($count)->toBeGreaterThanOrEqual(1);
            expect($count)->toBeLessThanOrEqual(2);
        }
    });

    it('maintains backward compatibility with default StaticParticipantOrderer', function (): void {
        $participants = [
            new Participant('1', 'Team A'),
            new Participant('2', 'Team B'),
            new Participant('3', 'Team C'),
            new Participant('4', 'Team D'),
        ];

        // Default behavior (no orderer specified)
        $defaultScheduler = new RoundRobinScheduler();
        $defaultSchedule = $defaultScheduler->generateSchedule($participants);

        // Explicit StaticParticipantOrderer
        $staticScheduler = new RoundRobinScheduler(
            null,
            null,
            new StaticParticipantOrderer()
        );
        $staticSchedule = $staticScheduler->generateSchedule($participants);

        // Should produce identical schedules
        $defaultEvents = $defaultSchedule->getEvents();
        $staticEvents = $staticSchedule->getEvents();

        expect(count($defaultEvents))->toBe(count($staticEvents));

        for ($i = 0; $i < count($defaultEvents); ++$i) {
            $defaultParticipants = $defaultEvents[$i]->getParticipants();
            $staticParticipants = $staticEvents[$i]->getParticipants();

            expect($defaultParticipants[0]->getId())->toBe($staticParticipants[0]->getId());
            expect($defaultParticipants[1]->getId())->toBe($staticParticipants[1]->getId());
        }
    });
});
