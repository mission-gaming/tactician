<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\CallableConstraint;
use MissionGaming\Tactician\Constraints\ConsecutiveRoleConstraint;
use MissionGaming\Tactician\Constraints\ConstraintInterface;
use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Constraints\NoRepeatPairings;
use MissionGaming\Tactician\Constraints\RoleBalanceConstraint;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Scheduling\BacktrackingRoundRobinGenerator;
use MissionGaming\Tactician\Scheduling\SchedulingContext;
use MissionGaming\Tactician\Stage\RoundRobinPlan;
use MissionGaming\Tactician\Tests\Support\ReferenceBacktrackingSearch;

/**
 * Writes down every pairing attempt a search makes: the event tried and the
 * events the context held at that moment. Two searches with the same log
 * tried the same pairings, in the same order, against the same schedule.
 */
final class PairingAttemptLog implements ConstraintInterface
{
    /** @var list<string> */
    public array $attempts = [];

    #[Override]
    public function isSatisfied(Event $event, SchedulingContext $context): bool
    {
        $this->attempts[] = describeEvent($event) . ' after ' . implode(',', array_map(describeEvent(...), $context->getExistingEvents()));

        return true;
    }

    #[Override]
    public function getName(): string
    {
        return 'Pairing attempt log';
    }
}

function describeEvent(Event $event): string
{
    return implode('v', array_map(static fn(Participant $participant): string => $participant->getId(), $event->getParticipants()))
        . '@' . ($event->getRound()?->getNumber() ?? 0);
}

/**
 * @return list<Participant>
 */
function searchOrderField(int $size): array
{
    $field = [];
    for ($i = 1; $i <= $size; ++$i) {
        $field[] = new Participant("p{$i}", "Participant {$i}", $i);
    }

    return $field;
}

/**
 * Constraints that send the search down different paths: none, ones that
 * read the placed events, and ones no schedule can satisfy.
 *
 * @return array<string, list<ConstraintInterface>>
 */
function searchOrderConstraints(int $size): array
{
    $lastRound = $size % 2 === 0 ? $size - 1 : $size;

    return [
        'none' => [],
        'role streak of 1' => [ConsecutiveRoleConstraint::homeAway(1)],
        'role balance of 1' => [RoleBalanceConstraint::homeAway(1)],
        'no repeats and role streak of 2' => [new NoRepeatPairings(), ConsecutiveRoleConstraint::homeAway(2)],
        'first participant meets the others in order' => [new CallableConstraint(
            static function (Event $event): bool {
                $ids = array_map(static fn(Participant $participant): int => (int) substr($participant->getId(), 1), $event->getParticipants());
                sort($ids);

                return $ids[0] !== 1 || $event->getRound()?->getNumber() === $ids[1] - 1;
            },
            'Fixture placement'
        )],
        'nothing in the last round' => [new CallableConstraint(
            static fn(Event $event): bool => $event->getRound()?->getNumber() !== $lastRound,
            'No last round'
        )],
        'reads the round so far' => [new CallableConstraint(
            static fn(Event $event, SchedulingContext $context): bool => (count($context->getEventsInRound($event->getRound()?->getNumber() ?? 0))
                + $context->getEventCount()) % 5 !== 4,
            'Round so far'
        )],
    ];
}

describe('The backtracking search, against the search as first written', function (): void {
    it('tries the same pairings in the same order and stops at the same step', function (): void {
        $compared = 0;
        $stoppedByBudget = 0;
        $exhaustedTheSpace = 0;

        foreach ([2, 3, 4, 5, 6, 7, 8] as $size) {
            foreach (searchOrderConstraints($size) as $constraints) {
                foreach ([0, 1, 2, 3, 5, 8, 13, 40, 150, 700, 4000] as $budget) {
                    foreach ([false, true] as $reversed) {
                        $field = $reversed ? array_reverse(searchOrderField($size)) : searchOrderField($size);
                        $plan = new RoundRobinPlan($field, 1);

                        $log = new PairingAttemptLog();
                        $generator = new BacktrackingRoundRobinGenerator(new ConstraintSet([$log, ...$constraints]), $budget);
                        $events = $generator->generateFirstLeg($field, $plan);

                        $referenceLog = new PairingAttemptLog();
                        $reference = new ReferenceBacktrackingSearch(new ConstraintSet([$referenceLog, ...$constraints]), $budget);
                        $referenceEvents = $reference->generateFirstLeg($field, $plan);

                        expect($log->attempts)->toBe($referenceLog->attempts);
                        expect($events === null ? null : array_map(describeEvent(...), $events))
                            ->toBe($referenceEvents === null ? null : array_map(describeEvent(...), $referenceEvents));
                        expect($generator->wasBudgetExhausted())->toBe($reference->wasBudgetExhausted());
                        expect($generator->getRoundByes())->toBe($reference->getRoundByes());

                        // A step is a pairing attempt: the budget is never overdrawn.
                        expect(count($log->attempts))->toBeLessThanOrEqual($budget);

                        ++$compared;
                        $stoppedByBudget += (int) $generator->wasBudgetExhausted();
                        $exhaustedTheSpace += (int) ($events === null && !$generator->wasBudgetExhausted());
                    }
                }
            }
        }

        // The comparison is only worth something if it covered all three
        // endings: a schedule, a budget that ran out, and a search space
        // that held nothing.
        expect($compared)->toBe(1078);
        expect($stoppedByBudget)->toBeGreaterThan(300);
        expect($exhaustedTheSpace)->toBeGreaterThan(20);
        expect($compared - $stoppedByBudget - $exhaustedTheSpace)->toBeGreaterThan(200);
    });

    it('gives the same answer without constraints, where no context is built', function (): void {
        foreach ([2, 3, 6, 9, 12] as $size) {
            foreach ([1, 10, 200_000] as $budget) {
                $field = searchOrderField($size);
                $plan = new RoundRobinPlan($field, 1);

                $generator = new BacktrackingRoundRobinGenerator(null, $budget);
                $events = $generator->generateFirstLeg($field, $plan);
                $reference = new ReferenceBacktrackingSearch(null, $budget);
                $referenceEvents = $reference->generateFirstLeg($field, $plan);

                expect($events === null ? null : array_map(describeEvent(...), $events))
                    ->toBe($referenceEvents === null ? null : array_map(describeEvent(...), $referenceEvents));
                expect($generator->wasBudgetExhausted())->toBe($reference->wasBudgetExhausted());
                expect($generator->getRoundByes())->toBe($reference->getRoundByes());
            }
        }
    });

    it('starts each search afresh on one generator', function (): void {
        $generator = new BacktrackingRoundRobinGenerator(null, 40);

        // Twelve participants need 66 pairings: the budget of 40 runs out
        // part-way down a path, with pairings and events on it.
        $large = searchOrderField(12);
        expect($generator->generateFirstLeg($large, new RoundRobinPlan($large, 1)))->toBeNull();
        expect($generator->wasBudgetExhausted())->toBeTrue();

        // Nothing of that path may be left for the next search.
        $small = searchOrderField(6);
        $plan = new RoundRobinPlan($small, 1);
        $fresh = (new BacktrackingRoundRobinGenerator(null, 40))->generateFirstLeg($small, $plan);
        $reused = $generator->generateFirstLeg($small, $plan);

        expect($fresh)->not->toBeNull();
        expect($reused)->not->toBeNull();
        expect(array_map(describeEvent(...), $reused ?? []))->toBe(array_map(describeEvent(...), $fresh ?? []));
        expect(count($reused ?? []))->toBe(15);
        expect($generator->wasBudgetExhausted())->toBeFalse();
    });
});
