<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Stage\PairwisePlan;
use MissionGaming\Tactician\Stage\SwissPlan;

/**
 * @return array<Participant>
 */
function swissPlanParticipants(int $count): array
{
    $participants = [];
    for ($i = 1; $i <= $count; ++$i) {
        $participants[] = new Participant("p{$i}", "Player {$i}");
    }

    return $participants;
}

describe('SwissPlan', function (): void {
    it('declares its algorithm identifier without pairwise capability', function (): void {
        $plan = new SwissPlan(swissPlanParticipants(8), 3);

        expect($plan->getAlgorithm())->toBe('swiss');
        // Swiss pairings depend on results, so pairwise meeting counts are
        // not knowable up front and the plan is deliberately not pairwise.
        expect($plan)->not->toBeInstanceOf(PairwisePlan::class);
    });

    it('computes expected events from rounds', function (): void {
        $plan = new SwissPlan(swissPlanParticipants(8), 3);

        expect($plan->getEventsPerRound())->toBe(4);
        expect($plan->getExpectedEventCount())->toBe(12);
        expect($plan->getTotalRounds())->toBe(3);
    });

    it('handles odd participant counts with one bye per round', function (): void {
        $plan = new SwissPlan(swissPlanParticipants(5), 3);

        expect($plan->getEventsPerRound())->toBe(2);
        expect($plan->getExpectedEventCount())->toBe(6);
    });

    // Swiss has no legs concept: null means "does not apply", never a
    // fabricated 1 that downstream arithmetic could silently consume
    it('reports null for the legs concept', function (): void {
        $plan = new SwissPlan(swissPlanParticipants(8), 3);

        expect($plan->getLegs())->toBeNull();
        expect($plan->getRoundsPerLeg())->toBeNull();
    });

    // An open-ended Swiss stage (results-driven engine without a configured
    // length) cannot know its rounds or event count up front
    it('reports unknowable rounds and event count when the length is not fixed', function (): void {
        $plan = new SwissPlan(swissPlanParticipants(8), null);

        expect($plan->getTotalRounds())->toBeNull();
        expect($plan->getExpectedEventCount())->toBeNull();
        expect($plan->getEventsPerRound())->toBe(4);
    });

    it('rejects fewer than 2 participants', function (): void {
        new SwissPlan(swissPlanParticipants(1), 3);
    })->throws(InvalidConfigurationException::class);

    it('rejects a non-positive round count', function (): void {
        new SwissPlan(swissPlanParticipants(4), 0);
    })->throws(InvalidConfigurationException::class);

    it('passes integrity validation for a non-repeat subset of opponents', function (): void {
        [$participant1, $participant2, $participant3, $participant4] = swissPlanParticipants(4);
        $plan = new SwissPlan([$participant1, $participant2, $participant3, $participant4], 2);

        $schedule = new Schedule([
            new Event([$participant1, $participant2], new Round(1)),
            new Event([$participant3, $participant4], new Round(1)),
            new Event([$participant1, $participant3], new Round(2)),
            new Event([$participant2, $participant4], new Round(2)),
        ]);

        expect($plan->validateIntegrity($schedule))->toBe([]);
    });

    it('detects repeat pairings and duplicate round appearances', function (): void {
        [$participant1, $participant2, $participant3, $participant4] = swissPlanParticipants(4);
        $plan = new SwissPlan([$participant1, $participant2, $participant3, $participant4], 2);

        $schedule = new Schedule([
            new Event([$participant1, $participant2], new Round(1)),
            new Event([$participant1, $participant3], new Round(1)),
            new Event([$participant2, $participant1], new Round(2)),
            new Event([$participant3, $participant4], new Round(2)),
        ]);

        $violations = $plan->validateIntegrity($schedule);

        expect($violations)->toContain('Participant p1 appears more than once in round 1.');
        expect($violations)->toContain('Pairing p1 vs p2 appears 2 time(s); Swiss pairings may not repeat.');
    });

    it('rejects rounds outside the configured length', function (): void {
        [$participant1, $participant2] = swissPlanParticipants(2);
        $plan = new SwissPlan([$participant1, $participant2], 1);

        $violations = $plan->validateIntegrity(new Schedule([
            new Event([$participant1, $participant2], new Round(2)),
        ]));

        expect($violations)->toContain('Event 1 has an invalid round number.');
    });

    it('skips round-range and slot checks when the length is not fixed', function (): void {
        [$participant1, $participant2, $participant3, $participant4] = swissPlanParticipants(4);
        $plan = new SwissPlan([$participant1, $participant2, $participant3, $participant4], null);

        // Round 7 with a partial field would fail both checks under a fixed
        // length; with an open-ended stage neither applies.
        $violations = $plan->validateIntegrity(new Schedule([
            new Event([$participant1, $participant2], new Round(7)),
        ]));

        expect($violations)->toBe([]);
    });
});
