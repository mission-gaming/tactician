<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\LegStrategies\LegStrategyInterface;
use MissionGaming\Tactician\LegStrategies\MirroredLegStrategy;
use MissionGaming\Tactician\LegStrategies\RepeatedLegStrategy;
use MissionGaming\Tactician\Scheduling\SupportsMultipleLegs;
use Random\Engine\Mt19937;
use Random\Randomizer;

// Test class that uses the trait to expose its functionality for testing
class TestSchedulerWithMultipleLegs
{
    use SupportsMultipleLegs;

    private bool $allowAllConstraints = true;

    public function __construct(bool $allowAllConstraints = true)
    {
        $this->allowAllConstraints = $allowAllConstraints;
    }

    // Public wrapper to test expandScheduleForLegs
    /**
     * @param array<Event> $baseEvents
     * @return array<Event>
     */
    public function testExpandScheduleForLegs(
        array $baseEvents,
        int $legs,
        LegStrategyInterface $legStrategy,
        ?Randomizer $randomizer = null
    ): array {
        return $this->expandScheduleForLegs($baseEvents, $legs, $legStrategy, $randomizer);
    }

    // Public wrapper to test calculateRoundsPerLeg
    /**
     * @param array<Event> $events
     */
    public function testCalculateRoundsPerLeg(array $events): int
    {
        return $this->calculateRoundsPerLeg($events);
    }

    // Public wrapper to test extractPairings
    /**
     * @param array<Event> $events
     * @return array<array{0: \MissionGaming\Tactician\DTO\Participant, 1: \MissionGaming\Tactician\DTO\Participant}>
     */
    public function testExtractPairings(array $events): array
    {
        return $this->extractPairings($events);
    }

    // Public wrapper to test createEventsFromPairings
    /**
     * @param array<array<\MissionGaming\Tactician\DTO\Participant>> $pairings
     * @return array<Event>
     */
    public function testCreateEventsFromPairings(
        array $pairings,
        int $roundsPerLeg,
        int $leg
    ): array {
        return $this->createEventsFromPairings($pairings, $roundsPerLeg, $leg);
    }

    // Public wrapper to test createEventsFromPairingsWithConstraints
    /**
     * @param array<array<\MissionGaming\Tactician\DTO\Participant>> $pairings
     * @param array<Event> $existingEvents
     * @return array<Event>
     */
    public function testCreateEventsFromPairingsWithConstraints(
        array $pairings,
        int $roundsPerLeg,
        int $leg,
        array $existingEvents
    ): array {
        return $this->createEventsFromPairingsWithConstraints($pairings, $roundsPerLeg, $leg, $existingEvents);
    }

    // Implementation of abstract method
    #[\Override]
    protected function shouldAddEventWithConstraints(Event $event, array $existingEvents): bool
    {
        return $this->allowAllConstraints;
    }

    // Method to control constraint behavior for testing
    public function setConstraintBehavior(bool $allowAll): void
    {
        $this->allowAllConstraints = $allowAll;
    }
}

describe('SupportsMultipleLegs', function (): void {
    beforeEach(function (): void {
        $this->participants = [
            new Participant('p1', 'Alice'),
            new Participant('p2', 'Bob'),
            new Participant('p3', 'Carol'),
            new Participant('p4', 'Dave'),
        ];

        // Create base events for testing (2 rounds, 2 events each)
        $this->baseEvents = [
            new Event([$this->participants[0], $this->participants[1]], new Round(1)),
            new Event([$this->participants[2], $this->participants[3]], new Round(1)),
            new Event([$this->participants[0], $this->participants[2]], new Round(2)),
            new Event([$this->participants[1], $this->participants[3]], new Round(2)),
        ];

        $this->scheduler = new TestSchedulerWithMultipleLegs();
    });

    describe('expandScheduleForLegs', function (): void {
        it('returns base events unchanged when legs is 1', function (): void {
            $scheduler = new TestSchedulerWithMultipleLegs();
            $participants = [
                new Participant('p1', 'Alice'),
                new Participant('p2', 'Bob'),
                new Participant('p3', 'Carol'),
                new Participant('p4', 'Dave'),
            ];
            $baseEvents = [
                new Event([$participants[0], $participants[1]], new Round(1)),
                new Event([$participants[2], $participants[3]], new Round(1)),
                new Event([$participants[0], $participants[2]], new Round(2)),
                new Event([$participants[1], $participants[3]], new Round(2)),
            ];

            $result = $scheduler->testExpandScheduleForLegs(
                $baseEvents,
                1,
                new MirroredLegStrategy()
            );

            expect($result)->toBe($baseEvents);
        });

        it('expands schedule for 2 legs with mirrored strategy', function (): void {
            $scheduler = new TestSchedulerWithMultipleLegs();
            $participants = [
                new Participant('p1', 'Alice'),
                new Participant('p2', 'Bob'),
                new Participant('p3', 'Carol'),
                new Participant('p4', 'Dave'),
            ];
            $baseEvents = [
                new Event([$participants[0], $participants[1]], new Round(1)),
                new Event([$participants[2], $participants[3]], new Round(1)),
                new Event([$participants[0], $participants[2]], new Round(2)),
                new Event([$participants[1], $participants[3]], new Round(2)),
            ];

            $result = $scheduler->testExpandScheduleForLegs(
                $baseEvents,
                2,
                new MirroredLegStrategy()
            );

            // Should have 8 events total (4 base + 4 mirrored)
            expect($result)->toHaveCount(8);

            // Check round numbering continuity
            $rounds = array_map(fn (Event $e) => $e->getRound()?->getNumber(), $result);
            expect($rounds)->toBe([1, 1, 2, 2, 3, 3, 4, 4]);
        });

        it('expands schedule for 3 legs', function (): void {
            $scheduler = new TestSchedulerWithMultipleLegs();
            $participants = [
                new Participant('p1', 'Alice'),
                new Participant('p2', 'Bob'),
                new Participant('p3', 'Carol'),
                new Participant('p4', 'Dave'),
            ];
            $baseEvents = [
                new Event([$participants[0], $participants[1]], new Round(1)),
                new Event([$participants[2], $participants[3]], new Round(1)),
                new Event([$participants[0], $participants[2]], new Round(2)),
                new Event([$participants[1], $participants[3]], new Round(2)),
            ];

            $result = $scheduler->testExpandScheduleForLegs(
                $baseEvents,
                3,
                new RepeatedLegStrategy()
            );

            // Should have 12 events total (4 base + 4 + 4)
            expect($result)->toHaveCount(12);

            // Check round numbering spans 3 legs
            $rounds = array_map(fn (Event $e) => $e->getRound()?->getNumber(), $result);
            $expectedRounds = [1, 1, 2, 2, 3, 3, 4, 4, 5, 5, 6, 6];
            expect($rounds)->toBe($expectedRounds);
        });

        it('passes randomizer to leg strategy', function (): void {
            $scheduler = new TestSchedulerWithMultipleLegs();
            $participants = [
                new Participant('p1', 'Alice'),
                new Participant('p2', 'Bob'),
                new Participant('p3', 'Carol'),
                new Participant('p4', 'Dave'),
            ];
            $baseEvents = [
                new Event([$participants[0], $participants[1]], new Round(1)),
                new Event([$participants[2], $participants[3]], new Round(1)),
                new Event([$participants[0], $participants[2]], new Round(2)),
                new Event([$participants[1], $participants[3]], new Round(2)),
            ];
            $randomizer = new Randomizer(new Mt19937(42));

            $result = $scheduler->testExpandScheduleForLegs(
                $baseEvents,
                2,
                new MirroredLegStrategy(),
                $randomizer
            );

            expect($result)->toHaveCount(8);
        });

        it('applies constraints during expansion', function (): void {
            $scheduler = new TestSchedulerWithMultipleLegs();
            $participants = [
                new Participant('p1', 'Alice'),
                new Participant('p2', 'Bob'),
                new Participant('p3', 'Carol'),
                new Participant('p4', 'Dave'),
            ];
            $baseEvents = [
                new Event([$participants[0], $participants[1]], new Round(1)),
                new Event([$participants[2], $participants[3]], new Round(1)),
                new Event([$participants[0], $participants[2]], new Round(2)),
                new Event([$participants[1], $participants[3]], new Round(2)),
            ];

            // Set scheduler to reject all constraint checks
            $scheduler->setConstraintBehavior(false);

            $result = $scheduler->testExpandScheduleForLegs(
                $baseEvents,
                2,
                new MirroredLegStrategy()
            );

            // Should only have base events since all additional events are rejected by constraints
            expect($result)->toHaveCount(4);
            expect($result)->toBe($baseEvents);
        });
    });

    describe('calculateRoundsPerLeg', function (): void {
        it('calculates rounds correctly from events', function (): void {
            $scheduler = new TestSchedulerWithMultipleLegs();
            $participants = [
                new Participant('p1', 'Alice'),
                new Participant('p2', 'Bob'),
                new Participant('p3', 'Carol'),
                new Participant('p4', 'Dave'),
            ];
            $baseEvents = [
                new Event([$participants[0], $participants[1]], new Round(1)),
                new Event([$participants[2], $participants[3]], new Round(1)),
                new Event([$participants[0], $participants[2]], new Round(2)),
                new Event([$participants[1], $participants[3]], new Round(2)),
            ];

            $rounds = $scheduler->testCalculateRoundsPerLeg($baseEvents);
            expect($rounds)->toBe(2); // Events span rounds 1 and 2
        });

        it('handles events with null rounds', function (): void {
            $scheduler = new TestSchedulerWithMultipleLegs();
            $participants = [
                new Participant('p1', 'Alice'),
                new Participant('p2', 'Bob'),
                new Participant('p3', 'Carol'),
                new Participant('p4', 'Dave'),
            ];
            $eventsWithNulls = [
                new Event([$participants[0], $participants[1]], null),
                new Event([$participants[2], $participants[3]], new Round(1)),
            ];

            $rounds = $scheduler->testCalculateRoundsPerLeg($eventsWithNulls);
            expect($rounds)->toBe(1); // Only one valid round
        });

        it('handles empty events array', function (): void {
            $scheduler = new TestSchedulerWithMultipleLegs();
            $rounds = $scheduler->testCalculateRoundsPerLeg([]);
            expect($rounds)->toBe(0);
        });

        it('handles non-consecutive round numbers', function (): void {
            $scheduler = new TestSchedulerWithMultipleLegs();
            $participants = [
                new Participant('p1', 'Alice'),
                new Participant('p2', 'Bob'),
                new Participant('p3', 'Carol'),
                new Participant('p4', 'Dave'),
            ];
            $eventsWithGaps = [
                new Event([$participants[0], $participants[1]], new Round(1)),
                new Event([$participants[2], $participants[3]], new Round(5)),
                new Event([$participants[0], $participants[2]], new Round(1)),
                new Event([$participants[1], $participants[3]], new Round(5)),
            ];

            $rounds = $scheduler->testCalculateRoundsPerLeg($eventsWithGaps);
            expect($rounds)->toBe(2); // Rounds 1 and 5 = 2 unique rounds
        });
    });

    describe('extractPairings', function (): void {
        it('extracts participant pairings from events', function (): void {
            $scheduler = new TestSchedulerWithMultipleLegs();
            $participants = [
                new Participant('p1', 'Alice'),
                new Participant('p2', 'Bob'),
                new Participant('p3', 'Carol'),
                new Participant('p4', 'Dave'),
            ];
            $baseEvents = [
                new Event([$participants[0], $participants[1]], new Round(1)),
                new Event([$participants[2], $participants[3]], new Round(1)),
                new Event([$participants[0], $participants[2]], new Round(2)),
                new Event([$participants[1], $participants[3]], new Round(2)),
            ];

            $pairings = $scheduler->testExtractPairings($baseEvents);

            expect($pairings)->toHaveCount(4);

            // Check first pairing
            expect($pairings[0])->toHaveCount(2);
            expect($pairings[0][0])->toBe($participants[0]);
            expect($pairings[0][1])->toBe($participants[1]);

            // Check second pairing
            expect($pairings[1][0])->toBe($participants[2]);
            expect($pairings[1][1])->toBe($participants[3]);
        });

        it('handles empty events array', function (): void {
            $scheduler = new TestSchedulerWithMultipleLegs();
            $pairings = $scheduler->testExtractPairings([]);
            expect($pairings)->toHaveCount(0);
        });

        it('extracts pairings from single event', function (): void {
            $scheduler = new TestSchedulerWithMultipleLegs();
            $participants = [
                new Participant('p1', 'Alice'),
                new Participant('p2', 'Bob'),
            ];
            $singleEvent = [new Event([$participants[0], $participants[1]], new Round(1))];
            $pairings = $scheduler->testExtractPairings($singleEvent);

            expect($pairings)->toHaveCount(1);
            expect($pairings[0][0])->toBe($participants[0]);
            expect($pairings[0][1])->toBe($participants[1]);
        });
    });

    describe('createEventsFromPairings', function (): void {
        it('creates events with correct round numbering for leg 1', function (): void {
            $scheduler = new TestSchedulerWithMultipleLegs();
            $participants = [
                new Participant('p1', 'Alice'),
                new Participant('p2', 'Bob'),
                new Participant('p3', 'Carol'),
                new Participant('p4', 'Dave'),
            ];
            $pairings = [
                [$participants[0], $participants[1]],
                [$participants[2], $participants[3]],
                [$participants[0], $participants[2]],
                [$participants[1], $participants[3]],
            ];

            $events = $scheduler->testCreateEventsFromPairings($pairings, 2, 1);

            expect($events)->toHaveCount(4);

            // First round events (round 1)
            expect($events[0]->getRound()?->getNumber())->toBe(1);
            expect($events[1]->getRound()?->getNumber())->toBe(1);

            // Second round events (round 2)
            expect($events[2]->getRound()?->getNumber())->toBe(2);
            expect($events[3]->getRound()?->getNumber())->toBe(2);
        });

        it('creates events with correct round numbering for leg 2', function (): void {
            $scheduler = new TestSchedulerWithMultipleLegs();
            $participants = [
                new Participant('p1', 'Alice'),
                new Participant('p2', 'Bob'),
                new Participant('p3', 'Carol'),
                new Participant('p4', 'Dave'),
            ];
            $pairings = [
                [$participants[0], $participants[1]],
                [$participants[2], $participants[3]],
                [$participants[0], $participants[2]],
                [$participants[1], $participants[3]],
            ];

            $events = $scheduler->testCreateEventsFromPairings($pairings, 2, 2);

            expect($events)->toHaveCount(4);

            // First round events (round 3 = 1 + 2*1)
            expect($events[0]->getRound()?->getNumber())->toBe(3);
            expect($events[1]->getRound()?->getNumber())->toBe(3);

            // Second round events (round 4 = 2 + 2*1)
            expect($events[2]->getRound()?->getNumber())->toBe(4);
            expect($events[3]->getRound()?->getNumber())->toBe(4);
        });

        it('creates events with correct round numbering for leg 3', function (): void {
            $scheduler = new TestSchedulerWithMultipleLegs();
            $participants = [
                new Participant('p1', 'Alice'),
                new Participant('p2', 'Bob'),
                new Participant('p3', 'Carol'),
                new Participant('p4', 'Dave'),
            ];
            $pairings = [
                [$participants[0], $participants[1]],
                [$participants[2], $participants[3]],
                [$participants[0], $participants[2]],
                [$participants[1], $participants[3]],
            ];

            $events = $scheduler->testCreateEventsFromPairings($pairings, 2, 3);

            expect($events)->toHaveCount(4);

            // First round events (round 5 = 1 + 2*2)
            expect($events[0]->getRound()?->getNumber())->toBe(5);
            expect($events[1]->getRound()?->getNumber())->toBe(5);

            // Second round events (round 6 = 2 + 2*2)
            expect($events[2]->getRound()?->getNumber())->toBe(6);
            expect($events[3]->getRound()?->getNumber())->toBe(6);
        });

        it('handles single round per leg', function (): void {
            $scheduler = new TestSchedulerWithMultipleLegs();
            $participants = [
                new Participant('p1', 'Alice'),
                new Participant('p2', 'Bob'),
                new Participant('p3', 'Carol'),
                new Participant('p4', 'Dave'),
            ];
            $singleRoundPairings = [
                [$participants[0], $participants[1]],
                [$participants[2], $participants[3]],
            ];

            $events = $scheduler->testCreateEventsFromPairings($singleRoundPairings, 1, 2);

            expect($events)->toHaveCount(2);
            expect($events[0]->getRound()?->getNumber())->toBe(2); // 1 + 1*1
            expect($events[1]->getRound()?->getNumber())->toBe(2);
        });

        it('handles division by zero gracefully when roundsPerLeg is zero', function (): void {
            $scheduler = new TestSchedulerWithMultipleLegs();
            $participants = [
                new Participant('p1', 'Alice'),
                new Participant('p2', 'Bob'),
            ];
            $pairings = [[$participants[0], $participants[1]]];

            // This should not throw DivisionByZeroError but handle gracefully
            $events = $scheduler->testCreateEventsFromPairings($pairings, 0, 1);
            expect($events)->toHaveCount(0); // No rounds means no events
        });

        it('handles empty pairings array', function (): void {
            $scheduler = new TestSchedulerWithMultipleLegs();
            $events = $scheduler->testCreateEventsFromPairings([], 2, 1);
            expect($events)->toHaveCount(0);
        });

        it('handles uneven pairings distribution', function (): void {
            $scheduler = new TestSchedulerWithMultipleLegs();
            $participants = [
                new Participant('p1', 'Alice'),
                new Participant('p2', 'Bob'),
                new Participant('p3', 'Carol'),
                new Participant('p4', 'Dave'),
            ];
            // 3 pairings, 2 rounds = 1.5 pairings per round
            $unevenPairings = [
                [$participants[0], $participants[1]],
                [$participants[2], $participants[3]],
                [$participants[0], $participants[2]],
            ];

            $events = $scheduler->testCreateEventsFromPairings($unevenPairings, 2, 1);

            expect($events)->toHaveCount(2); // Should handle truncation gracefully
            expect($events[0]->getRound()?->getNumber())->toBe(1);
            expect($events[1]->getRound()?->getNumber())->toBe(2);
        });
    });

    describe('createEventsFromPairingsWithConstraints', function (): void {
        it('creates events when constraints are satisfied', function (): void {
            $scheduler = new TestSchedulerWithMultipleLegs();
            $participants = [
                new Participant('p1', 'Alice'),
                new Participant('p2', 'Bob'),
                new Participant('p3', 'Carol'),
                new Participant('p4', 'Dave'),
            ];
            $pairings = [
                [$participants[0], $participants[1]],
                [$participants[2], $participants[3]],
            ];
            $existingEvents = [
                new Event([$participants[0], $participants[2]], new Round(1)),
            ];

            $scheduler->setConstraintBehavior(true); // Allow all constraints

            $events = $scheduler->testCreateEventsFromPairingsWithConstraints(
                $pairings,
                1,
                2,
                $existingEvents
            );

            expect($events)->toHaveCount(2);
            expect($events[0]->getRound()?->getNumber())->toBe(2);
            expect($events[1]->getRound()?->getNumber())->toBe(2);
        });

        it('skips events when constraints are not satisfied', function (): void {
            $scheduler = new TestSchedulerWithMultipleLegs();
            $participants = [
                new Participant('p1', 'Alice'),
                new Participant('p2', 'Bob'),
                new Participant('p3', 'Carol'),
                new Participant('p4', 'Dave'),
            ];
            $pairings = [
                [$participants[0], $participants[1]],
                [$participants[2], $participants[3]],
            ];
            $existingEvents = [
                new Event([$participants[0], $participants[2]], new Round(1)),
            ];

            $scheduler->setConstraintBehavior(false); // Reject all constraints

            $events = $scheduler->testCreateEventsFromPairingsWithConstraints(
                $pairings,
                1,
                2,
                $existingEvents
            );

            expect($events)->toHaveCount(0); // All events should be rejected
        });

        it('builds incremental context correctly', function (): void {
            // Use a custom scheduler that tracks context calls
            $participants = [
                new Participant('p1', 'Alice'),
                new Participant('p2', 'Bob'),
                new Participant('p3', 'Carol'),
                new Participant('p4', 'Dave'),
            ];
            $pairings = [
                [$participants[0], $participants[1]],
                [$participants[2], $participants[3]],
            ];
            $existingEvents = [
                new Event([$participants[0], $participants[2]], new Round(1)),
            ];
            /** @var array<int> $contextCalls */
            $contextCalls = [];
            $customScheduler = new class ($contextCalls) extends TestSchedulerWithMultipleLegs {
                /** @var array<int> */
                private array $contextCalls;

                /** @param array<int> $contextCalls */
                public function __construct(array &$contextCalls)
                {
                    parent::__construct();
                    $this->contextCalls = &$contextCalls;
                }

                #[\Override]
                protected function shouldAddEventWithConstraints(Event $event, array $existingEvents): bool
                {
                    $this->contextCalls[] = count($existingEvents);

                    return true; // Always allow events
                }

                /** @return array<int> */
                public function getContextCalls(): array
                {
                    return $this->contextCalls;
                }
            };

            $customScheduler->testCreateEventsFromPairingsWithConstraints(
                $pairings,
                1,
                2,
                $existingEvents
            );

            // Context should grow incrementally: [1, 2] (1 existing + 0 new, then 1 existing + 1 new)
            expect($contextCalls)->toBe([1, 2]);
        });

        it('handles division by zero gracefully when roundsPerLeg is zero', function (): void {
            $scheduler = new TestSchedulerWithMultipleLegs();
            $participants = [
                new Participant('p1', 'Alice'),
                new Participant('p2', 'Bob'),
            ];
            $pairings = [[$participants[0], $participants[1]]];

            // This should not throw DivisionByZeroError but handle gracefully
            $events = $scheduler->testCreateEventsFromPairingsWithConstraints($pairings, 0, 1, []);
            expect($events)->toHaveCount(0); // No rounds means no events
        });

        it('handles empty pairings with constraints', function (): void {
            $scheduler = new TestSchedulerWithMultipleLegs();
            $participants = [
                new Participant('p1', 'Alice'),
                new Participant('p2', 'Bob'),
            ];
            $existingEvents = [
                new Event([$participants[0], $participants[1]], new Round(1)),
            ];

            $events = $scheduler->testCreateEventsFromPairingsWithConstraints(
                [],
                1,
                2,
                $existingEvents
            );

            expect($events)->toHaveCount(0);
        });

        it('handles multiple rounds with mixed constraint results', function (): void {
            $participants = [
                new Participant('p1', 'Alice'),
                new Participant('p2', 'Bob'),
                new Participant('p3', 'Carol'),
                new Participant('p4', 'Dave'),
            ];
            $multiRoundPairings = [
                [$participants[0], $participants[1]], // Round 1
                [$participants[2], $participants[3]], // Round 1
                [$participants[0], $participants[2]], // Round 2
                [$participants[1], $participants[3]], // Round 2
            ];

            // Create a scheduler that alternates between allowing and rejecting
            $callCount = 0;
            $alternatingScheduler = new class ($callCount) extends TestSchedulerWithMultipleLegs {
                private int $callCount;

                public function __construct(int &$callCount)
                {
                    parent::__construct();
                    $this->callCount = &$callCount;
                }

                #[\Override]
                protected function shouldAddEventWithConstraints(Event $event, array $existingEvents): bool
                {
                    return (++$this->callCount) % 2 === 1; // Allow odd calls, reject even calls
                }
            };

            $events = $alternatingScheduler->testCreateEventsFromPairingsWithConstraints(
                $multiRoundPairings,
                2,
                2,
                []
            );

            expect($events)->toHaveCount(2); // Should have 2 events (1st and 3rd calls allowed)
            expect($events[0]->getRound()?->getNumber())->toBe(3); // First round of leg 2
            expect($events[1]->getRound()?->getNumber())->toBe(4); // Second round of leg 2
        });
    });

    describe('integration scenarios', function (): void {
        it('handles complete multi-leg expansion with constraints', function (): void {
            $scheduler = new TestSchedulerWithMultipleLegs();
            $participants = [
                new Participant('p1', 'Alice'),
                new Participant('p2', 'Bob'),
                new Participant('p3', 'Carol'),
                new Participant('p4', 'Dave'),
            ];
            $baseEvents = [
                new Event([$participants[0], $participants[1]], new Round(1)),
                new Event([$participants[2], $participants[3]], new Round(1)),
                new Event([$participants[0], $participants[2]], new Round(2)),
                new Event([$participants[1], $participants[3]], new Round(2)),
            ];

            $scheduler->setConstraintBehavior(true);

            $result = $scheduler->testExpandScheduleForLegs(
                $baseEvents,
                2,
                new MirroredLegStrategy()
            );

            expect($result)->toHaveCount(8);

            // Check that all rounds are properly numbered
            $roundNumbers = array_map(fn (Event $e) => $e->getRound()?->getNumber(), $result);
            sort($roundNumbers);
            expect($roundNumbers)->toBe([1, 1, 2, 2, 3, 3, 4, 4]);

            // Check that pairings are properly maintained
            $leg1Pairings = [];
            $leg2Pairings = [];

            foreach ($result as $event) {
                $participants = $event->getParticipants();
                $pairing = [$participants[0]->getId(), $participants[1]->getId()];
                sort($pairing);

                if ($event->getRound()?->getNumber() <= 2) {
                    $leg1Pairings[] = implode('-', $pairing);
                } else {
                    $leg2Pairings[] = implode('-', $pairing);
                }
            }

            // In mirrored strategy, leg 2 should have same pairings as leg 1
            sort($leg1Pairings);
            sort($leg2Pairings);
            expect($leg1Pairings)->toBe($leg2Pairings);
        });

        it('handles edge case with single event base', function (): void {
            $scheduler = new TestSchedulerWithMultipleLegs();
            $participants = [
                new Participant('p1', 'Alice'),
                new Participant('p2', 'Bob'),
            ];
            $singleEvent = [new Event([$participants[0], $participants[1]], new Round(1))];

            $result = $scheduler->testExpandScheduleForLegs(
                $singleEvent,
                3,
                new RepeatedLegStrategy()
            );

            expect($result)->toHaveCount(3); // 1 base + 1 + 1
            expect($result[0]->getRound()?->getNumber())->toBe(1); // Base event
            expect($result[1]->getRound()?->getNumber())->toBe(2); // Leg 2
            expect($result[2]->getRound()?->getNumber())->toBe(3); // Leg 3
        });
    });
});
