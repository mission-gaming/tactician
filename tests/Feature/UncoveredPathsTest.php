<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Diagnostics\SchedulingDiagnostics;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\LegStrategies\LegPlanContribution;
use MissionGaming\Tactician\LegStrategies\LegStrategyInterface;
use MissionGaming\Tactician\Repack\ContiguityBroken;
use MissionGaming\Tactician\Repack\MovableEvent;
use MissionGaming\Tactician\Repack\PinnedEvent;
use MissionGaming\Tactician\Repack\RepackOptions;
use MissionGaming\Tactician\Repack\RepackOutcome;
use MissionGaming\Tactician\Repack\RepackRequest;
use MissionGaming\Tactician\Repack\ScheduleRepacker;
use MissionGaming\Tactician\Repack\SessionGrid;
use MissionGaming\Tactician\Scheduling\RoundRobinOptions;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Scheduling\SchedulingContext;
use MissionGaming\Tactician\Stage\PairwisePlan;

// Tests for the lines of src/ that the coverage report showed no test
// executing, each through the behaviour that reaches it. The end of the
// file says where the lines that stay unexecuted, because nothing can reach
// them, carry their reasons.

/**
 * The reason of the configuration error a call ends in, or null when it
 * does not end in one.
 *
 * @param Closure(): mixed $call
 */
function uncoveredPathsReason(Closure $call): ?InvalidConfigurationReason
{
    try {
        $call();
    } catch (InvalidConfigurationException $exception) {
        return $exception->getReason();
    }

    return null;
}

/**
 * A list as a caller without static analysis passes it: nothing is said
 * about what it holds.
 *
 * @param array<mixed> $items
 * @return array<mixed>
 */
function uncoveredPathsUntyped(array $items): array
{
    return $items;
}

/**
 * What is wrong with a repack outcome, counted from the positions it
 * assigns and the pins of the request: an event with no position and no
 * unplaced record, a participant in two events of one position, a slot over
 * capacity, and a difference between the participants the outcome reports
 * a gap for and the participants whose occupied slots have one.
 *
 * @param list<MovableEvent> $movable
 * @param list<PinnedEvent> $pinned
 * @return list<string>
 */
function uncoveredPathsRepackFaults(RepackOutcome $outcome, array $movable, array $pinned, ?int $capacityPerSlot): array
{
    $faults = [];
    /** @var array<string, int> $events Position => events at it */
    $events = [];
    /** @var array<string, array<int, list<int>>> $slotsOf Participant ID => session => slots */
    $slotsOf = [];

    $occupy = static function (string $what, Participant $one, Participant $other, int $session, int $slot) use (&$events, &$slotsOf, &$faults): void {
        $events["{$session}.{$slot}"] = ($events["{$session}.{$slot}"] ?? 0) + 1;
        foreach ([$one, $other] as $participant) {
            if (in_array($slot, $slotsOf[$participant->getId()][$session] ?? [], true)) {
                $faults[] = "{$participant->getId()} is in two events at {$session}.{$slot} ({$what})";
            }
            $slotsOf[$participant->getId()][$session][] = $slot;
        }
    };

    foreach ($pinned as $pin) {
        $occupy($pin->getId(), $pin->getParticipantA(), $pin->getParticipantB(), $pin->getSession(), $pin->getSlot());
    }

    $unplaced = array_map(static fn($record): string => $record->getEventId(), $outcome->getUnplaced());
    foreach ($movable as $event) {
        $assignment = $outcome->getAssignmentFor($event->getId());
        if ($assignment === null) {
            if (!in_array($event->getId(), $unplaced, true)) {
                $faults[] = "{$event->getId()} has no position and is not reported as unplaced";
            }

            continue;
        }
        if (in_array($event->getId(), $unplaced, true)) {
            $faults[] = "{$event->getId()} has a position and is reported as unplaced";
        }
        $occupy($event->getId(), $event->getParticipantA(), $event->getParticipantB(), $assignment->getSession(), $assignment->getSlot());
    }

    if ($capacityPerSlot !== null) {
        foreach ($events as $position => $count) {
            if ($count > $capacityPerSlot) {
                $faults[] = "{$count} events at {$position}";
            }
        }
    }

    $withGap = [];
    foreach ($slotsOf as $id => $sessions) {
        foreach ($sessions as $session => $slots) {
            if ($slots !== [] && max($slots) - min($slots) + 1 > count($slots)) {
                $withGap[] = "{$id} in session {$session}";
            }
        }
    }
    $reported = array_map(
        static fn(ContiguityBroken $violation): string => "{$violation->getParticipant()->getId()} in session {$violation->getSession()}",
        $outcome->getContiguityBrokenViolations()
    );
    sort($withGap);
    sort($reported);
    if ($withGap !== $reported) {
        $faults[] = 'gaps reported for [' . implode(', ', $reported) . '], gaps counted for [' . implode(', ', $withGap) . ']';
    }

    return $faults;
}

describe('repack requests that are refused', function (): void {
    beforeEach(function (): void {
        $this->a = new Participant('a', 'A');
        $this->b = new Participant('b', 'B');
    });

    it('refuses a pinned event without an id', function (): void {
        expect(uncoveredPathsReason(fn() => new PinnedEvent('', $this->a, $this->b, 0, 0)))
            ->toBe(InvalidConfigurationReason::EmptyEventId);
    });

    it('refuses a pinned event between a participant and itself', function (): void {
        expect(uncoveredPathsReason(fn() => new PinnedEvent('x1', $this->a, new Participant('a', 'Another A'), 0, 0)))
            ->toBe(InvalidConfigurationReason::IdenticalParticipants);
    });

    it('refuses options whose throw_on_violations is not a boolean', function (mixed $value): void {
        expect(uncoveredPathsReason(fn() => RepackOptions::fromArray(['throw_on_violations' => $value])))
            ->toBe(InvalidConfigurationReason::WrongValueType);
    })->with([['yes'], [1], [0.0]]);

    it('refuses a movable event that is not a MovableEvent', function (): void {
        $grid = SessionGrid::shapeOnly(1);

        $movable = uncoveredPathsUntyped([new MovableEvent('e1', $this->a, $this->b), 'e2']);

        expect(uncoveredPathsReason(fn() => new RepackRequest($movable, [], $grid)))
            ->toBe(InvalidConfigurationReason::WrongValueType);
    });

    it('refuses two movable events with one id', function (): void {
        $grid = SessionGrid::shapeOnly(1);
        $movable = [new MovableEvent('e1', $this->a, $this->b), new MovableEvent('e1', $this->b, $this->a)];

        expect(uncoveredPathsReason(fn() => new RepackRequest($movable, [], $grid)))
            ->toBe(InvalidConfigurationReason::DuplicateEventId);
    });

    it('refuses a pinned event that is not a PinnedEvent', function (): void {
        $grid = SessionGrid::shapeOnly(1);

        $pinned = uncoveredPathsUntyped([new MovableEvent('e1', $this->a, $this->b)]);

        expect(uncoveredPathsReason(fn() => new RepackRequest([], $pinned, $grid)))
            ->toBe(InvalidConfigurationReason::WrongValueType);
    });
});

describe('repacking where no other test went', function (): void {
    // A session of more than 20 slots is wider than the bitmask the exact
    // search enumerates run patterns in; such a session is packed by the
    // greedy path. The outcome is held to the same rules as any other.
    it('packs a session wider than the exact search can enumerate', function (
        int $size,
        int $capacityPerSlot,
        bool $pin,
        bool $clean
    ): void {
        $participants = array_map(static fn(int $i): Participant => new Participant("p{$i}", "P{$i}"), range(1, $size));
        $movable = [];
        foreach ($participants as $index => $one) {
            foreach (array_slice($participants, $index + 1) as $other) {
                $movable[] = new MovableEvent("{$one->getId()}-{$other->getId()}", $one, $other);
            }
        }
        $pinned = $pin ? [new PinnedEvent('x1', $participants[0], new Participant('o1', 'O1'), 0, 2)] : [];

        $outcome = (new ScheduleRepacker())->repack(
            new RepackRequest($movable, $pinned, SessionGrid::shapeOnly(1, 22, [], $capacityPerSlot))
        );

        // A complete round robin in 22 slots: every event has a position
        expect(uncoveredPathsRepackFaults($outcome, $movable, $pinned, $capacityPerSlot))->toBe([])
            ->and($outcome->getAssignments())->toHaveCount(count($movable))
            ->and($outcome->getUnplaced())->toBe([])
            ->and($outcome->isClean())->toBe($clean);
    })->with([
        // Seven rounds of four events side by side: no gap, no late start
        'eight participants, four events per slot' => [8, 4, false, true],
        // Two events per slot cannot hold a round of three: the capacity
        // decides the packing, and somebody waits
        'six participants, two events per slot' => [6, 2, false, false],
        'six participants, two events per slot, one pinned' => [6, 2, true, false],
    ]);

    // The improvement pass spends steps of the shared budget. Whatever
    // budget it is stopped at, the outcome is a proper one, and the flag
    // says whether a search was cut short: once a budget is enough it stays
    // enough, and the outcome is then the one an ample budget gives.
    it('returns a proper outcome whatever step budget the improvement pass is stopped at', function (): void {
        [$p1, $p2, $p3] = array_map(static fn(int $i): Participant => new Participant("p{$i}", "P{$i}"), [1, 2, 3]);
        $movable = [
            new MovableEvent('m01', $p2, $p3),
            new MovableEvent('m02', $p2, $p1),
            new MovableEvent('m03', $p3, $p1),
            new MovableEvent('m04', $p3, $p2),
        ];
        $grid = SessionGrid::shapeOnly(1, 5, [], 1);
        $repacker = new ScheduleRepacker();
        $ample = $repacker->repack(new RepackRequest($movable, [], $grid));

        expect($ample->isBudgetExhausted())->toBeFalse();

        $stopped = 0;
        $enough = false;
        for ($budget = 1; $budget <= 120; ++$budget) {
            $outcome = $repacker->repack(new RepackRequest($movable, [], $grid, new RepackOptions(stepBudget: $budget)));

            expect(uncoveredPathsRepackFaults($outcome, $movable, [], 1))->toBe([], "budget {$budget}");

            if ($outcome->isBudgetExhausted()) {
                ++$stopped;
                // Never again once a smaller budget was enough
                expect($enough)->toBeFalse("budget {$budget}");
            } else {
                $enough = true;
                expect($outcome->fingerprint())->toBe($ample->fingerprint(), "budget {$budget}");
            }
        }

        // The sweep saw both: budgets that stop a search and budgets that do not
        expect($stopped)->toBeGreaterThan(5)
            ->and($enough)->toBeTrue();
    });

    // An instance in which the greedy path leaves a participant with a hole
    // that the improvement pass looks at: five events, three slots of two,
    // one pin. Found by random search; what is asserted is what any outcome
    // owes, and that every gap the outcome reports is a gap that is there.
    it('reports exactly the gaps that remain after the improvement pass', function (): void {
        [$p1, $p2, $p3, $p4, $p5] = array_map(static fn(int $i): Participant => new Participant("p{$i}", "P{$i}"), [1, 2, 3, 4, 5]);
        $movable = [
            new MovableEvent('m01', $p2, $p1),
            new MovableEvent('m02', $p3, $p2),
            new MovableEvent('m03', $p1, $p3),
            new MovableEvent('m04', $p2, $p5),
            new MovableEvent('m05', $p5, $p3),
        ];
        $pinned = [new PinnedEvent('x1', $p4, $p1, 0, 1)];

        $outcome = (new ScheduleRepacker())->repack(new RepackRequest($movable, $pinned, SessionGrid::shapeOnly(1, 3, [], 2)));

        expect(uncoveredPathsRepackFaults($outcome, $movable, $pinned, 2))->toBe([])
            ->and($outcome->getAssignments())->toHaveCount(5)
            ->and($outcome->isBudgetExhausted())->toBeFalse();
    });
});

describe('a leg strategy that yields no event', function (): void {
    // Without constraints nothing in the library can leave a leg short; a
    // leg strategy of the caller's can. The failure is as loud as any
    // other, and carries no constraint analysis, because there is no
    // constraint to analyse.
    it('fails generation loudly, without a constraint analysis', function (): void {
        $strategy = new readonly class implements LegStrategyInterface {
            #[Override]
            public function planLegs(array $participants, int $legs, ConstraintSet $constraints): LegPlanContribution
            {
                return new LegPlanContribution(rolesMirrorAcrossLegs: false, requiresRandomization: false);
            }

            #[Override]
            public function generateEventForLeg(array $participants, int $leg, int $round, SchedulingContext $context): ?Event
            {
                return null;
            }
        };
        $participants = [new Participant('a', 'A'), new Participant('b', 'B'), new Participant('c', 'C'), new Participant('d', 'D')];

        $failure = null;
        try {
            (new RoundRobinScheduler())->schedule($participants, new RoundRobinOptions(legs: 2, strategy: $strategy));
        } catch (IncompleteScheduleException $exception) {
            $failure = $exception;
        }

        // The first leg does not ask the strategy: its six events stand
        expect($failure)->toBeInstanceOf(IncompleteScheduleException::class)
            ->and($failure?->getExpectedEventCount())->toBe(12)
            ->and($failure?->getActualEventCount())->toBe(6)
            ->and($failure?->getAnalysis())->toBeNull()
            ->and($failure?->getViolationCollector()->hasViolations())->toBeFalse();
    });
});

describe('diagnostics for a plan that reports no rounds per leg', function (): void {
    // A PairwisePlan is an interface a caller may implement. One that
    // reports zero rounds per leg must not make the analysis divide by
    // zero: every round is then read as a round of the first leg.
    it('reads every round as a round of the first leg', function (): void {
        $a = new Participant('a', 'A');
        $b = new Participant('b', 'B');
        $plan = new readonly class implements PairwisePlan {
            #[Override]
            public function getAlgorithm(): string
            {
                return 'stub';
            }

            #[Override]
            public function getTotalRounds(): int
            {
                return 2;
            }

            #[Override]
            public function getLegs(): int
            {
                return 1;
            }

            #[Override]
            public function getRoundsPerLeg(): int
            {
                return 0;
            }

            #[Override]
            public function getExpectedEventCount(): int
            {
                return 1;
            }

            #[Override]
            public function validateIntegrity(Schedule $schedule): array
            {
                return [];
            }

            #[Override]
            public function getExpectedMeetings(Participant $a, Participant $b): int
            {
                return 1;
            }
        };
        $notInRoundOne = ConstraintSet::create()
            ->custom(static fn(Event $event): bool => $event->getRound()?->getNumber() !== 1, 'Not in round 1')
            ->build();
        $diagnostics = new SchedulingDiagnostics();

        // Nothing generated: the pairing is missing, and round 2 is open to it
        $report = $diagnostics->analyzeSchedulingFailure([$a, $b], $notInRoundOne, [], $plan);

        expect($report->getMissingPairings())->toBe(['A vs B (Leg 1)'])
            ->and($report->getImpossiblePairings())->toBe([]);

        // Played in round 2: counted for the first leg, so nothing is missing
        $played = $diagnostics->analyzeSchedulingFailure([$a, $b], $notInRoundOne, [new Event([$a, $b], new Round(2))], $plan);

        expect($played->getMissingPairings())->toBe([]);
    });
});

// The lines that stay unexecuted are guards for a state that the code
// around them rules out, so no input reaches them; a test could only reach
// one by breaking the invariant it guards with reflection, which would test
// the test. Each carries its reason in the source, in a comment that starts
// `// Not reached:` (fifteen of them: `grep -rn "Not reached:" src/`).
