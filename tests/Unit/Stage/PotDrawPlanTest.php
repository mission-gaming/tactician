<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Stage\PairwisePlan;
use MissionGaming\Tactician\Stage\PotDrawPlan;
use MissionGaming\Tactician\Stage\StagePlan;
use MissionGaming\Tactician\Validation\ConstraintViolationCollector;
use MissionGaming\Tactician\Validation\ScheduleValidator;

/**
 * Participants "a", "b", ... in seeding order.
 *
 * @return list<Participant>
 */
function potPlanField(int $count): array
{
    $field = [];
    for ($i = 0; $i < $count; ++$i) {
        $id = chr(ord('a') + $i);
        $field[] = new Participant($id, strtoupper($id));
    }

    return $field;
}

/**
 * A schedule from "first-second" pairs per round, written by hand.
 *
 * @param array<int, list<string>> $rounds Round number => pairs
 * @param list<Participant> $field
 */
function potPlanSchedule(array $rounds, array $field): Schedule
{
    $byId = [];
    foreach ($field as $participant) {
        $byId[$participant->getId()] = $participant;
    }

    $events = [];
    foreach ($rounds as $number => $pairs) {
        foreach ($pairs as $pair) {
            [$first, $second] = explode('-', $pair);
            $events[] = new Event(
                [$byId[$first] ?? new Participant($first, $first), $byId[$second] ?? new Participant($second, $second)],
                new Round($number)
            );
        }
    }

    return new Schedule($events);
}

/**
 * A valid draw of 4 entrants in 2 pots of 2 (a, b | c, d), one opponent per
 * pot, written by hand: everyone meets its pot partner once and one member
 * of the other pot once, and is in each role once.
 */
const POT_PLAN_VALID_DRAW = [
    1 => ['a-b', 'd-c'],
    2 => ['c-a', 'b-d'],
];

/**
 * A valid draw of 6 entrants in 2 pots of 3 (a, b, c | d, e, f), two
 * opponents per pot, written by hand: inside a pot everyone meets both
 * others, and against the other pot everyone has one event in each role.
 */
const POT_PLAN_VALID_ODD_POT_DRAW = [
    1 => ['b-c', 'e-f', 'a-d'],
    2 => ['a-b', 'd-e', 'c-f'],
    3 => ['c-a', 'f-d', 'b-e'],
    4 => ['d-b', 'e-c', 'f-a'],
];

describe('PotDrawPlan', function (): void {
    it('is a stage plan and not a pairwise plan', function (): void {
        $plan = new PotDrawPlan(potPlanField(4), 2, 1);

        expect($plan)->toBeInstanceOf(StagePlan::class)
            ->and($plan)->not->toBeInstanceOf(PairwisePlan::class);
    });

    it('reports the exact rounds and events before generation', function (
        int $count,
        int $pots,
        int $opponentsPerPot,
        int $potSize,
        int $rounds,
        int $eventsPerRound,
        int $events
    ): void {
        $plan = new PotDrawPlan(potPlanField($count), $pots, $opponentsPerPot);

        expect($plan->getAlgorithm())->toBe('pot-draw')
            ->and($plan->getPots())->toBe($pots)
            ->and($plan->getPotSize())->toBe($potSize)
            ->and($plan->getOpponentsPerPot())->toBe($opponentsPerPot)
            ->and($plan->getTotalRounds())->toBe($rounds)
            ->and($plan->getEventsPerParticipant())->toBe($rounds)
            ->and($plan->getEventsPerRound())->toBe($eventsPerRound)
            ->and($plan->getExpectedEventCount())->toBe($events)
            ->and($plan->getLegs())->toBeNull()
            ->and($plan->getRoundsPerLeg())->toBeNull();
    })->with([
        '6 in 3 pots of 2, one opponent' => [6, 3, 1, 2, 3, 3, 9],
        '20 in 5 pots of 4, one opponent' => [20, 5, 1, 4, 5, 10, 50],
        '36 in 4 pots of 9, two opponents' => [36, 4, 2, 9, 8, 18, 144],
        '8 as one pot, seven opponents' => [8, 1, 7, 8, 7, 4, 28],
        // Feasible, though the scheduler cannot draw it yet
        '10 in 2 pots of 5, four opponents' => [10, 2, 4, 5, 8, 5, 40],
    ]);

    it('cuts the pots from list position', function (): void {
        // The seed attributes say the opposite of the list order
        $field = [];
        foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $index => $id) {
            $field[] = new Participant($id, strtoupper($id), 6 - $index);
        }
        $plan = new PotDrawPlan($field, 3, 1);

        $ids = fn(array $members): array => array_map(fn(Participant $participant): string => $participant->getId(), $members);

        expect($ids($plan->getPotMembers(1)))->toBe(['a', 'b'])
            ->and($ids($plan->getPotMembers(2)))->toBe(['c', 'd'])
            ->and($ids($plan->getPotMembers(3)))->toBe(['e', 'f'])
            ->and($plan->getPotMembers(0))->toBe([])
            ->and($plan->getPotMembers(4))->toBe([])
            ->and($plan->getPotOf($field[0]))->toBe(1)
            ->and($plan->getPotOf($field[3]))->toBe(2)
            ->and($plan->getPotOf($field[5]))->toBe(3)
            ->and($plan->getPotOf(new Participant('z', 'Z')))->toBeNull()
            ->and($ids($plan->getParticipants()))->toBe(['a', 'b', 'c', 'd', 'e', 'f']);
    });

    it('refuses numbers that cannot form a pot draw', function (
        int $count,
        int $pots,
        int $opponentsPerPot,
        InvalidConfigurationReason $reason
    ): void {
        try {
            new PotDrawPlan(potPlanField($count), $pots, $opponentsPerPot);
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getReason())->toBe($reason);

            return;
        }

        throw new LogicException('The plan was not refused.');
    })->with([
        'zero pots' => [4, 0, 1, InvalidConfigurationReason::ValueOutOfRange],
        'zero opponents per pot' => [4, 2, 0, InvalidConfigurationReason::ValueOutOfRange],
        'one participant' => [1, 1, 1, InvalidConfigurationReason::TooFewParticipants],
        'an odd field' => [19, 1, 2, InvalidConfigurationReason::OddParticipantCount],
        'an odd field that does not divide either' => [19, 4, 1, InvalidConfigurationReason::OddParticipantCount],
        'pots of unequal size' => [20, 3, 1, InvalidConfigurationReason::UnequalPots],
        'more pots than participants' => [4, 6, 1, InvalidConfigurationReason::UnequalPots],
        'as many opponents as the pot has members' => [20, 5, 4, InvalidConfigurationReason::TooManyOpponentsPerPot],
        'an odd pot with an odd number of opponents' => [20, 4, 1, InvalidConfigurationReason::OddPotWithOddOpponents],
    ]);

    it('refuses two participants with one ID', function (): void {
        $field = [...potPlanField(3), new Participant('a', 'Again')];

        expect(fn() => new PotDrawPlan($field, 2, 1))->toThrow(
            InvalidConfigurationException::class,
            'All participants must have unique IDs'
        );
    });

    describe('integrity validation', function (): void {
        it('accepts a valid draw written by hand', function (): void {
            $field = potPlanField(4);

            expect((new PotDrawPlan($field, 2, 1))->validateIntegrity(potPlanSchedule(POT_PLAN_VALID_DRAW, $field)))->toBe([]);
        });

        it('accepts a valid draw with pots of odd size written by hand', function (): void {
            $field = potPlanField(6);

            expect((new PotDrawPlan($field, 2, 2))->validateIntegrity(potPlanSchedule(POT_PLAN_VALID_ODD_POT_DRAW, $field)))->toBe([]);
        });

        it('reports an entrant with the wrong number of opponents from a pot', function (): void {
            $field = potPlanField(4);
            // Every entrant is in every round once and nobody meets twice,
            // but a and b meet both members of the other pot and never
            // each other
            $schedule = potPlanSchedule([1 => ['a-c', 'd-b'], 2 => ['d-a', 'b-c']], $field);

            $violations = (new PotDrawPlan($field, 2, 1))->validateIntegrity($schedule);

            expect($violations)->toContain('Participant a has 0 opponent(s) from pot 1, expected 1.')
                ->and($violations)->toContain('Participant a has 2 opponent(s) from pot 2, expected 1.')
                ->and($violations)->toContain('Participant d has 2 opponent(s) from pot 1, expected 1.');
        });

        it('reports a pairing that repeats', function (): void {
            $field = potPlanField(4);
            $schedule = potPlanSchedule([1 => ['a-b', 'c-d'], 2 => ['b-a', 'd-c']], $field);

            expect((new PotDrawPlan($field, 2, 1))->validateIntegrity($schedule))
                ->toContain('Pairing b vs a appears more than once; pot draw pairings may not repeat.');
        });

        it('reports an entrant twice in a round and a round without the whole field', function (): void {
            $field = potPlanField(4);
            $schedule = potPlanSchedule([1 => ['a-b', 'a-c'], 2 => ['c-d', 'b-d']], $field);

            $violations = (new PotDrawPlan($field, 2, 1))->validateIntegrity($schedule);

            expect($violations)->toContain('Participant a appears more than once in round 1.')
                ->and($violations)->toContain('Round 1 has 3 of the 4 participants.');
        });

        it('reports a missing round', function (): void {
            $field = potPlanField(4);
            $schedule = potPlanSchedule([1 => ['a-b', 'c-d']], $field);

            expect((new PotDrawPlan($field, 2, 1))->validateIntegrity($schedule))
                ->toContain('Round 2 has 0 of the 4 participants.');
        });

        it('reports a round the stage does not have', function (): void {
            $field = potPlanField(4);
            $schedule = potPlanSchedule([1 => ['a-b', 'c-d'], 3 => ['c-a', 'b-d']], $field);

            expect((new PotDrawPlan($field, 2, 1))->validateIntegrity($schedule))
                ->toContain('Event 3 has an invalid round number.');
        });

        it('reports an event without a round', function (): void {
            $field = potPlanField(4);
            $schedule = new Schedule([new Event([$field[0], $field[1]])]);

            expect((new PotDrawPlan($field, 2, 1))->validateIntegrity($schedule))
                ->toContain('Event 1 has an invalid round number.');
        });

        it('reports a participant that is not in the stage', function (): void {
            $field = potPlanField(4);
            $schedule = potPlanSchedule([1 => ['a-b', 'c-z'], 2 => ['c-a', 'b-d']], $field);

            expect((new PotDrawPlan($field, 2, 1))->validateIntegrity($schedule))
                ->toContain('Event 2 contains a participant that is not in the stage.');
        });

        it('reports an event that names one participant twice', function (): void {
            $field = potPlanField(4);
            $schedule = potPlanSchedule([1 => ['a-a', 'c-d'], 2 => ['c-a', 'b-d']], $field);

            expect((new PotDrawPlan($field, 2, 1))->validateIntegrity($schedule))
                ->toContain('Event 1 contains participant a twice.');
        });

        // The format is pairwise: an event with more participants is
        // reported, never counted as if it were a pair
        it('reports an event with more than two participants', function (): void {
            $field = potPlanField(4);
            $schedule = new Schedule([
                new Event([$field[0], $field[1], $field[2]], new Round(1)),
                ...potPlanSchedule([2 => ['c-a', 'b-d']], $field)->getEvents(),
            ]);

            $violations = (new PotDrawPlan($field, 2, 1))->validateIntegrity($schedule);

            expect($violations)->toContain('Event 1 has 3 participants; pot draw events must have exactly 2 participants.')
                // and its participants were not counted as opponents of each other
                ->and($violations)->toContain('Participant a has 0 opponent(s) from pot 1, expected 1.');
        });

        it('reports role counts that differ by more than one', function (): void {
            $field = potPlanField(4);
            // The valid draw with the second round turned round: a is first twice
            $schedule = potPlanSchedule([1 => ['a-b', 'c-d'], 2 => ['a-c', 'd-b']], $field);

            $violations = (new PotDrawPlan($field, 2, 1))->validateIntegrity($schedule);

            expect($violations)->toContain('The two role counts of participant a differ by 2; they may differ by at most 1.')
                ->and($violations)->toContain('The two role counts of participant b differ by 2; they may differ by at most 1.');
        });

        it('reports unequal roles against one pot when opponents per pot is even', function (): void {
            $field = potPlanField(6);
            // The valid draw with d-b and f-a of the last round turned
            // round. a is now first against both d and f; overall it is
            // first three times and second once
            $rounds = POT_PLAN_VALID_ODD_POT_DRAW;
            $rounds[4] = ['b-d', 'e-c', 'a-f'];

            $violations = (new PotDrawPlan($field, 2, 2))->validateIntegrity(potPlanSchedule($rounds, $field));

            expect($violations)->toContain('Participant a is not in each role equally often against pot 2.')
                ->and($violations)->toContain('Participant d is not in each role equally often against pot 1.')
                ->and($violations)->toContain('The two role counts of participant a differ by 2; they may differ by at most 1.');
        });

        it('fails schedule validation through the plan', function (): void {
            $field = potPlanField(4);
            $plan = new PotDrawPlan($field, 2, 1);
            $wrongPots = potPlanSchedule([1 => ['a-c', 'd-b'], 2 => ['d-a', 'b-c']], $field);

            // The counts are right (4 events), so only the pot rules can fail it
            expect(fn() => (new ScheduleValidator())->validateScheduleCompleteness($wrongPots, $plan, new ConstraintViolationCollector(), $field))
                ->toThrow(IncompleteScheduleException::class, 'Generated schedule failed pot-draw integrity validation');

            $tooFew = potPlanSchedule([1 => ['a-b', 'c-d']], $field);
            expect(fn() => (new ScheduleValidator())->validateScheduleCompleteness($tooFew, $plan, new ConstraintViolationCollector(), $field))
                ->toThrow(IncompleteScheduleException::class);

            (new ScheduleValidator())->validateScheduleCompleteness(
                potPlanSchedule(POT_PLAN_VALID_DRAW, $field),
                $plan,
                new ConstraintViolationCollector(),
                $field
            );
        });
    });
});
