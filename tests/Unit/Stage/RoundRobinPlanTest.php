<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Stage\PairwisePlan;
use MissionGaming\Tactician\Stage\RoundRobinPlan;
use MissionGaming\Tactician\Stage\StagePlan;

/**
 * @return array<Participant>
 */
function roundRobinPlanParticipants(int $count): array
{
    $participants = [];
    for ($i = 1; $i <= $count; ++$i) {
        $participants[] = new Participant("p{$i}", "Player {$i}");
    }

    return $participants;
}

describe('RoundRobinPlan', function (): void {
    it('declares its algorithm identifier and pairwise capability', function (): void {
        $plan = new RoundRobinPlan(roundRobinPlanParticipants(4), 1);

        expect($plan->getAlgorithm())->toBe('round-robin');
        expect($plan)->toBeInstanceOf(StagePlan::class);
        expect($plan)->toBeInstanceOf(PairwisePlan::class);
    });

    // Tests that the plan correctly computes expected events: C(n,2) per leg
    it('computes expected events from the participant count', function (): void {
        $testCases = [
            2 => 1,   // C(2,2) = 2*1/2 = 1
            3 => 3,   // C(3,2) = 3*2/2 = 3
            4 => 6,   // C(4,2) = 4*3/2 = 6
            6 => 15,  // C(6,2) = 6*5/2 = 15
            8 => 28,  // C(8,2) = 8*7/2 = 28
            12 => 66, // C(12,2) = 12*11/2 = 66
        ];

        foreach ($testCases as $participantCount => $expectedEvents) {
            $plan = new RoundRobinPlan(roundRobinPlanParticipants($participantCount), 1);

            expect($plan->getEventsPerLeg())->toBe($expectedEvents);
            expect($plan->getExpectedEventCount())->toBe($expectedEvents);
        }
    });

    // Tests that expected events scale linearly with leg count
    it('scales expected events linearly with legs', function (): void {
        $participants = roundRobinPlanParticipants(4);

        expect((new RoundRobinPlan($participants, 1))->getExpectedEventCount())->toBe(6);
        expect((new RoundRobinPlan($participants, 2))->getExpectedEventCount())->toBe(12);
        expect((new RoundRobinPlan($participants, 3))->getExpectedEventCount())->toBe(18);
    });

    // Regression: the pre-plan strategy math reported n-1 rounds per leg for
    // odd participant counts, which need n rounds for the bye rotation.
    it('declares bye-aware rounds per leg for odd and even fields', function (): void {
        $evenPlan = new RoundRobinPlan(roundRobinPlanParticipants(4), 2);
        expect($evenPlan->getRoundsPerLeg())->toBe(3);
        expect($evenPlan->getTotalRounds())->toBe(6);

        $oddPlan = new RoundRobinPlan(roundRobinPlanParticipants(5), 2);
        expect($oddPlan->getRoundsPerLeg())->toBe(5);
        expect($oddPlan->getTotalRounds())->toBe(10);
    });

    // Round robin has legs, so getLegs() is never null and the invariant
    // legs × roundsPerLeg = totalRounds holds
    it('always reports its legs and upholds the rounds invariant', function (): void {
        foreach ([1, 2, 3] as $legs) {
            foreach ([4, 5, 7, 8] as $count) {
                $plan = new RoundRobinPlan(roundRobinPlanParticipants($count), $legs);

                expect($plan->getLegs())->toBe($legs);
                expect($plan->getLegs() * $plan->getRoundsPerLeg())->toBe($plan->getTotalRounds());
            }
        }
    });

    it('expects every distinct pair to meet once per leg', function (): void {
        $participants = roundRobinPlanParticipants(4);
        $plan = new RoundRobinPlan($participants, 2);

        expect($plan->getExpectedMeetings($participants[0], $participants[1]))->toBe(2);
        expect($plan->getExpectedMeetings($participants[2], $participants[3]))->toBe(2);
    });

    it('expects no meetings for outsiders or self-pairings', function (): void {
        $participants = roundRobinPlanParticipants(4);
        $plan = new RoundRobinPlan($participants, 2);
        $outsider = new Participant('x1', 'Outsider');

        expect($plan->getExpectedMeetings($participants[0], $participants[0]))->toBe(0);
        expect($plan->getExpectedMeetings($participants[0], $outsider))->toBe(0);
        expect($plan->getExpectedMeetings($outsider, $participants[0]))->toBe(0);
    });

    it('carries the leg strategy contribution facts', function (): void {
        $plan = new RoundRobinPlan(
            roundRobinPlanParticipants(4),
            2,
            rolesMirrorAcrossLegs: true,
            requiresRandomization: false,
            warnings: ['example warning']
        );

        expect($plan->rolesMirrorAcrossLegs())->toBeTrue();
        expect($plan->requiresRandomization())->toBeFalse();
        expect($plan->getWarnings())->toBe(['example warning']);
    });

    it('rejects fewer than 2 participants', function (): void {
        new RoundRobinPlan(roundRobinPlanParticipants(1), 1);
    })->throws(InvalidConfigurationException::class);

    it('rejects a non-positive leg count', function (): void {
        new RoundRobinPlan(roundRobinPlanParticipants(4), 0);
    })->throws(InvalidConfigurationException::class);

    it('reports no integrity violations for complete pairings', function (): void {
        [$participant1, $participant2, $participant3] = roundRobinPlanParticipants(3);
        $plan = new RoundRobinPlan([$participant1, $participant2, $participant3], 1);

        $schedule = new Schedule([
            new Event([$participant1, $participant2], new Round(1)),
            new Event([$participant2, $participant3], new Round(2)),
            new Event([$participant1, $participant3], new Round(3)),
        ]);

        expect($plan->validateIntegrity($schedule))->toBe([]);
    });

    it('reports duplicate and missing round-robin pairings', function (): void {
        [$participant1, $participant2, $participant3] = roundRobinPlanParticipants(3);
        $plan = new RoundRobinPlan([$participant1, $participant2, $participant3], 1);

        $schedule = new Schedule([
            new Event([$participant1, $participant2], new Round(1)),
            new Event([$participant1, $participant2], new Round(2)),
            new Event([$participant1, $participant2], new Round(3)),
        ]);

        $violations = $plan->validateIntegrity($schedule);

        expect($violations)->toContain('Pairing Player 1 vs Player 2 appears 3 time(s), expected 1.');
        expect($violations)->toContain('Pairing Player 1 vs Player 3 appears 0 time(s), expected 1.');
        expect($violations)->toContain('Pairing Player 2 vs Player 3 appears 0 time(s), expected 1.');
    });

    it('reports events with foreign or duplicated participants', function (): void {
        [$participant1, $participant2] = roundRobinPlanParticipants(2);
        $plan = new RoundRobinPlan([$participant1, $participant2], 1);
        $outsider = new Participant('x1', 'Outsider');

        $violations = $plan->validateIntegrity(new Schedule([
            new Event([$participant1, $outsider], new Round(1)),
            new Event([$participant2, $participant2], new Round(1)),
        ]));

        expect($violations)->toContain('Event 1 contains a participant that is not in the tournament.');
        expect($violations)->toContain('Event 2 contains participant p2 twice.');
    });
});
