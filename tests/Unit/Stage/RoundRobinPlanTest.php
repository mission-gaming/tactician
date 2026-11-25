<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Stage\PairKey;
use MissionGaming\Tactician\Stage\PairwisePlan;
use MissionGaming\Tactician\Stage\RoundRobinPlan;
use MissionGaming\Tactician\Stage\StagePlan;
use MissionGaming\Tactician\Tests\Support\UncheckedEvent;

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

        expect(new RoundRobinPlan($participants, 1)->getExpectedEventCount())->toBe(6);
        expect(new RoundRobinPlan($participants, 2)->getExpectedEventCount())->toBe(12);
        expect(new RoundRobinPlan($participants, 3)->getExpectedEventCount())->toBe(18);
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

    // validateIntegrity()'s docblock: event violations in schedule order,
    // then the pairing counts in field order, each pairing once, counted
    // whichever participant is named first and named in key order. The
    // oracle states that rule with a PairKey per pairing. The fields list
    // ids twice, ids that read as numbers, ids PHP compares without a total
    // order ('10', '9', '1e1') and ids holding PairKey's separator; the
    // schedules drop, repeat and reverse pairings and hold events the plan
    // reports.
    it('counts every pairing of the field in field order, whatever the ids', function (): void {
        $randomizer = new Random\Randomizer(new Random\Engine\Xoshiro256StarStar(251));
        $pool = ['a', 'b', '10', '9', '1e1', '0', 'x|y', 'x\\y', 'c'];

        for ($case = 0; $case < 300; ++$case) {
            $size = $randomizer->getInt(2, 7);
            $field = [];
            for ($i = 0; $i < $size; ++$i) {
                $id = $pool[$randomizer->getInt(0, count($pool) - 1)];
                $field[] = new Participant($id, "L{$i}-{$id}");
            }
            $outsider = new Participant('outsider', 'Outsider');
            $legs = $randomizer->getInt(1, 2);

            $events = [];
            for ($e = $randomizer->getInt(0, 14); $e > 0; --$e) {
                $kind = $randomizer->getInt(0, 9);
                $one = $field[$randomizer->getInt(0, $size - 1)];
                $two = $field[$randomizer->getInt(0, $size - 1)];
                $events[] = match (true) {
                    $kind === 0 => UncheckedEvent::of([$one, $two, $outsider], new Round(1)),
                    $kind === 1 => new Event([$one, $outsider], new Round(1)),
                    default => UncheckedEvent::of([$one, $two], new Round(1)),
                };
            }

            $labels = [];
            foreach ($field as $participant) {
                $labels[$participant->getId()] = $participant->getLabel();
            }
            $expected = [];
            $counts = [];
            foreach ($events as $index => $event) {
                $sides = array_values($event->getParticipants());
                if (count($sides) !== 2) {
                    $expected[] = sprintf('Event %d has %d participants; round robin events must have exactly 2 participants.', $index + 1, count($sides));
                } elseif ($sides[0]->getId() === $sides[1]->getId()) {
                    $expected[] = sprintf('Event %d contains participant %s twice.', $index + 1, $sides[0]->getId());
                } elseif (!isset($labels[$sides[0]->getId()], $labels[$sides[1]->getId()])) {
                    $expected[] = sprintf('Event %d contains a participant that is not in the tournament.', $index + 1);
                } else {
                    $key = PairKey::of($sides[0]->getId(), $sides[1]->getId());
                    $counts[$key] = ($counts[$key] ?? 0) + 1;
                }
            }
            $pairings = [];
            for ($i = 0; $i < $size - 1; ++$i) {
                for ($j = $i + 1; $j < $size; ++$j) {
                    $ids = PairKey::order([$field[$i]->getId(), $field[$j]->getId()]);
                    $pairings[PairKey::join($ids)] = $ids;
                }
            }
            foreach ($pairings as $key => [$first, $second]) {
                if (($counts[$key] ?? 0) !== $legs) {
                    $expected[] = sprintf('Pairing %s vs %s appears %d time(s), expected %d.', $labels[$first], $labels[$second], $counts[$key] ?? 0, $legs);
                }
            }

            expect(new RoundRobinPlan($field, $legs)->validateIntegrity(new Schedule($events)))
                ->toBe($expected, "case {$case}");
        }
    });

    it('exposes its participants re-indexed', function (): void {
        $participants = roundRobinPlanParticipants(3);
        $plan = new RoundRobinPlan([5 => $participants[0], 9 => $participants[1], 12 => $participants[2]], 1);

        expect($plan->getParticipants())->toBe($participants);
    });

    it('reports events with the wrong participant count', function (): void {
        [$participant1, $participant2, $participant3] = roundRobinPlanParticipants(3);
        $plan = new RoundRobinPlan([$participant1, $participant2, $participant3], 1);

        $violations = $plan->validateIntegrity(new Schedule([
            new Event([$participant1, $participant2, $participant3], new Round(1)),
        ]));

        expect($violations)->toContain('Event 1 has 3 participants; round robin events must have exactly 2 participants.');
    });

    it('reports events with foreign or duplicated participants', function (): void {
        [$participant1, $participant2] = roundRobinPlanParticipants(2);
        $plan = new RoundRobinPlan([$participant1, $participant2], 1);
        $outsider = new Participant('x1', 'Outsider');

        $violations = $plan->validateIntegrity(new Schedule([
            new Event([$participant1, $outsider], new Round(1)),
            UncheckedEvent::of([$participant2, $participant2], new Round(1)),
        ]));

        expect($violations)->toContain('Event 1 contains a participant that is not in the tournament.');
        expect($violations)->toContain('Event 2 contains participant p2 twice.');
    });

    // An event keeps the participant array it is given, keys included. The
    // plan read the two sides by the keys 0 and 1, so other keys were a raw
    // PHP warning and a call on null.
    it('reads the two sides of an event by their order, whatever the array keys are', function (array $keys): void {
        [$participant1, $participant2, $participant3] = roundRobinPlanParticipants(3);
        $plan = new RoundRobinPlan([$participant1, $participant2, $participant3], 1);

        $complete = new Schedule([
            new Event([$keys[0] => $participant1, $keys[1] => $participant2], new Round(1)),
            new Event([$keys[0] => $participant2, $keys[1] => $participant3], new Round(2)),
            new Event([$keys[0] => $participant1, $keys[1] => $participant3], new Round(3)),
        ]);
        $repeated = new Schedule([
            new Event([$keys[0] => $participant1, $keys[1] => $participant2], new Round(1)),
            UncheckedEvent::of([$keys[0] => $participant2, $keys[1] => $participant2], new Round(2)),
        ]);

        expect($plan->validateIntegrity($complete))->toBe([])
            ->and($plan->validateIntegrity($repeated))->toContain('Event 2 contains participant p2 twice.')
            ->and($plan->findUnplayedPairings([new Result($complete->getEvents()[0], $participant1)]))
            ->toBe(['Player 1 vs Player 3', 'Player 2 vs Player 3']);
    })->with([
        'a list' => [[0, 1]],
        'string keys' => [['home', 'away']],
        'sparse integer keys' => [[3, 7]],
    ]);
});
