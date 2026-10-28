<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\RoleAssignment\BalancedRoleAssignment;
use MissionGaming\Tactician\RoleAssignment\RoleAssignmentContext;
use MissionGaming\Tactician\RoleAssignment\RoundParityRoleAssignment;
use MissionGaming\Tactician\Scheduling\SchedulingContext;
use MissionGaming\Tactician\Stage\RoundRobinPlan;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * A leg written as ids, turned into the rounds a role assignment receives.
 * An empty string is the bye seat.
 *
 * @param list<list<array{0: string, 1: string}>> $rounds
 * @return list<list<array{0: Participant|null, 1: Participant|null}>>
 */
function seatedRounds(array $rounds): array
{
    $participants = [];
    $seat = static function (string $id) use (&$participants): ?Participant {
        if ($id === '') {
            return null;
        }

        return $participants[$id] ??= new Participant($id, "Participant {$id}");
    };

    $seated = [];
    foreach ($rounds as $seatings) {
        $roundSeatings = [];
        foreach ($seatings as [$first, $second]) {
            $roundSeatings[] = [$seat($first), $seat($second)];
        }
        $seated[] = $roundSeatings;
    }

    return $seated;
}

/**
 * The same leg back as ids, to compare with what a test states.
 *
 * @param list<list<array{0: Participant|null, 1: Participant|null}>> $rounds
 * @return list<list<array{0: string, 1: string}>>
 */
function seatedIds(array $rounds): array
{
    $ids = [];
    foreach ($rounds as $seatings) {
        $roundIds = [];
        foreach ($seatings as [$first, $second]) {
            $roundIds[] = [$first?->getId() ?? '', $second?->getId() ?? ''];
        }
        $ids[] = $roundIds;
    }

    return $ids;
}

/**
 * First-named count minus second-named count per participant, counted here
 * and not by the class under test.
 *
 * @param list<list<array{0: string, 1: string}>> $rounds
 * @return array<string, int>
 */
function seatedDifferences(array $rounds): array
{
    $differences = [];
    foreach ($rounds as $seatings) {
        foreach ($seatings as [$first, $second]) {
            if ($first === '' || $second === '') {
                continue;
            }
            $differences[$first] = ($differences[$first] ?? 0) + 1;
            $differences[$second] = ($differences[$second] ?? 0) - 1;
        }
    }

    return $differences;
}

/**
 * How far apart the two role counts of the worst-placed participant are;
 * 0 for a leg without a pairing.
 *
 * @param list<list<array{0: string, 1: string}>> $rounds
 */
function seatedWorstDifference(array $rounds): int
{
    $worst = 0;
    foreach (seatedDifferences($rounds) as $difference) {
        $worst = max($worst, abs($difference));
    }

    return $worst;
}

/**
 * Every pairing of a field in a round of its own, the lower id first-named
 * every time: participant 1 is first-named in all of its events. Nothing
 * about it is a circle layout, so the circle rule cannot balance it.
 *
 * @return list<list<array{0: string, 1: string}>>
 */
function lopsidedLeg(int $size): array
{
    $rounds = [];
    for ($first = 1; $first <= $size; ++$first) {
        for ($second = $first + 1; $second <= $size; ++$second) {
            $rounds[] = [[(string) $first, (string) $second]];
        }
    }

    return $rounds;
}

describe('RoundParityRoleAssignment', function (): void {
    it('keeps the roles it is given', function (): void {
        $rounds = seatedRounds([
            [['a', 'd'], ['b', 'c']],
            [['b', 'a'], ['d', 'c']],
            [['a', 'c'], ['d', 'b']],
        ]);

        expect(new RoundParityRoleAssignment()->assignRoles($rounds, new RoleAssignmentContext()))->toBe($rounds);
    });
});

describe('BalancedRoleAssignment', function (): void {
    // The circle layout of four participants as the scheduler proposes it:
    // seat i meets seat (3 - i), the first seat stays, the roles alternate
    // with round parity. Participant c is second-named in all three events.
    //
    // The circle rule, worked by hand: seating 0 is reversed in every round
    // (d-a, a-b, c-a); seating 1 keeps its roles when 1 + round is even
    // (rounds 1 and 3) and is reversed in round 2 (c-d).
    it('applies the circle rule to a leg the circle method laid out', function (): void {
        $proposed = [
            [['a', 'd'], ['b', 'c']],
            [['b', 'a'], ['d', 'c']],
            [['a', 'c'], ['d', 'b']],
        ];
        expect(seatedDifferences($proposed)['c'])->toBe(-3);

        $assigned = seatedIds(new BalancedRoleAssignment()->assignRoles(seatedRounds($proposed), new RoleAssignmentContext()));

        expect($assigned)->toBe([
            [['d', 'a'], ['b', 'c']],
            [['a', 'b'], ['c', 'd']],
            [['c', 'a'], ['d', 'b']],
        ]);
        expect(seatedDifferences($assigned))->toBe(['d' => 1, 'a' => -1, 'b' => -1, 'c' => 1]);
    });

    // Five participants [a, b, c, d, e] and a bye seat, laid out by hand:
    // seat i meets seat (5 - i), all seats but the first move up one place
    // after each round, and the even rounds are listed reversed. Every
    // participant plays four events, so the roles can split 2 and 2.
    it('balances a field of odd size exactly and leaves the bye seatings in place', function (): void {
        $proposed = [
            [['a', ''], ['b', 'e'], ['c', 'd']],
            [['b', 'a'], ['', 'c'], ['e', 'd']],
            [['a', 'c'], ['d', 'b'], ['e', '']],
            [['d', 'a'], ['c', 'e'], ['b', '']],
            [['a', 'e'], ['', 'd'], ['b', 'c']],
        ];
        // What the circle method proposes leaves b and e two apart
        expect(seatedDifferences($proposed))->toBe(['b' => 2, 'e' => -2, 'c' => 0, 'd' => 0, 'a' => 0]);

        $assigned = seatedIds(new BalancedRoleAssignment()->assignRoles(seatedRounds($proposed), new RoleAssignmentContext()));

        $differences = seatedDifferences($assigned);
        ksort($differences);
        expect($differences)->toBe(['a' => 0, 'b' => 0, 'c' => 0, 'd' => 0, 'e' => 0]);

        foreach ($proposed as $round => $seatings) {
            foreach ($seatings as $position => $seating) {
                // Same seating in the same place, as given or reversed
                expect([$seating, [$seating[1], $seating[0]]])->toContain($assigned[$round][$position]);

                if (in_array('', $seating, true)) {
                    expect($assigned[$round][$position])->toBe($seating);
                }
            }
        }
    });

    it('does not change a leg that is already balanced', function (): void {
        $twoParticipants = seatedRounds([[['a', 'b']]]);
        expect(new BalancedRoleAssignment()->assignRoles($twoParticipants, new RoleAssignmentContext()))->toBe($twoParticipants);

        // Balanced, and not what the circle rule would produce
        $balanced = seatedRounds([
            [['a', 'b'], ['c', 'd']],
            [['c', 'a'], ['d', 'b']],
            [['a', 'd'], ['b', 'c']],
        ]);
        expect(new BalancedRoleAssignment()->assignRoles($balanced, new RoleAssignmentContext()))->toBe($balanced);
    });

    // The guarantee does not depend on the layout: a leg that is not a
    // circle layout is repaired. The ids are numeric strings on purpose:
    // PHP turns such an array key into an integer.
    it('balances a leg the circle rule cannot, changing nothing but roles', function (int $size): void {
        $proposed = lopsidedLeg($size);
        // Participant 1 is first-named in every one of its events
        expect(array_values(seatedDifferences($proposed))[0] ?? null)->toBe($size - 1);

        $assigned = seatedIds(new BalancedRoleAssignment()->assignRoles(seatedRounds($proposed), new RoleAssignmentContext()));

        expect(count($assigned))->toBe(count($proposed));
        foreach ($proposed as $round => $seatings) {
            expect(count($assigned[$round]))->toBe(1);
            expect([$seatings[0], [$seatings[0][1], $seatings[0][0]]])->toContain($assigned[$round][0]);
        }

        // Each participant plays (size - 1) events: an even number splits
        // evenly, an odd number splits one apart
        foreach (seatedDifferences($assigned) as $difference) {
            expect(abs($difference))->toBe(($size - 1) % 2);
        }
    })->with([[3], [4], [5], [6], [7], [8], [9], [10], [11], [12]]);

    // Participants with different numbers of events: each is held to what
    // its own count allows.
    it('holds every participant to what the number of its events allows', function (): void {
        $proposed = [
            [['a', 'b']],
            [['a', 'c']],
            [['a', 'd']],
            [['a', 'e']],
            [['b', 'c']],
        ];

        $assigned = seatedIds(new BalancedRoleAssignment()->assignRoles(seatedRounds($proposed), new RoleAssignmentContext()));
        $differences = seatedDifferences($assigned);

        // a plays 4 and b and c play 2: even. d and e play 1: one apart.
        expect($differences['a'])->toBe(0);
        expect($differences['b'])->toBe(0);
        expect($differences['c'])->toBe(0);
        expect(abs($differences['d']))->toBe(1);
        expect(abs($differences['e']))->toBe(1);
    });

    // "Whatever the pairings in them are": legs no generator would produce.
    // Pairings repeat, rounds are ragged or empty, bye seatings sit anywhere,
    // and participants play different numbers of events. The ids include
    // numeric strings that PHP would merge or reorder as array keys if the
    // class were careless with them.
    it('balances any leg at all, changes nothing but roles, and leaves its own answer alone', function (): void {
        $randomizer = new Randomizer(new Mt19937(46));
        $ids = ['a', 'b', 'c', 'd', 'e', 'f', '1', '01', '1.0', '10', '-1', ' 1'];
        $assignment = new BalancedRoleAssignment();
        $faults = [];

        for ($case = 0; $case < 400; ++$case) {
            $field = array_slice($ids, 0, $randomizer->getInt(2, count($ids)));
            $proposed = [];
            for ($round = $randomizer->getInt(0, 9); $round > 0; --$round) {
                $seatings = [];
                for ($seating = $randomizer->getInt(0, 5); $seating > 0; --$seating) {
                    [$first, $second] = $randomizer->pickArrayKeys($field, 2);
                    $pair = [$field[$first], $field[$second]];
                    $roll = $randomizer->getInt(0, 9);
                    $seatings[] = match (true) {
                        $roll === 0 => [$pair[0], ''],
                        $roll === 1 => ['', $pair[1]],
                        $roll < 6 => $pair,
                        default => [$pair[1], $pair[0]],
                    };
                }
                $proposed[] = $seatings;
            }

            $rounds = seatedRounds($proposed);
            $answer = $assignment->assignRoles($rounds, new RoleAssignmentContext());
            $assigned = seatedIds($answer);

            if (array_keys($assigned) !== array_keys($proposed)) {
                $faults[] = "case {$case}: the rounds changed";

                continue;
            }
            foreach ($proposed as $round => $seatings) {
                if (array_keys($assigned[$round]) !== array_keys($seatings)) {
                    $faults[] = "case {$case}: the seatings of a round changed";

                    continue;
                }
                foreach ($seatings as $position => $seating) {
                    if (!in_array($assigned[$round][$position], [$seating, [$seating[1], $seating[0]]], true)) {
                        $faults[] = "case {$case}: a seating is neither as given nor reversed";
                    }
                }
            }

            // At most 1 apart is exactly 0 apart for an even number of events
            foreach (seatedDifferences($assigned) as $id => $difference) {
                if (abs($difference) > 1) {
                    $faults[] = "case {$case}: {$id} is {$difference} apart";
                }
            }

            if ($assignment->assignRoles($answer, new RoleAssignmentContext()) !== $answer) {
                $faults[] = "case {$case}: a balanced answer was changed again";
            }
        }

        expect($faults)->toBe([]);
    });

    it('gives the same answer for the same rounds', function (): void {
        $rounds = seatedRounds(lopsidedLeg(9));

        expect(new BalancedRoleAssignment()->assignRoles($rounds, new RoleAssignmentContext()))
            ->toBe(new BalancedRoleAssignment()->assignRoles($rounds, new RoleAssignmentContext()));
    });

    // Every leg is decided by the same rule: the leg number is told, and
    // this assignment does not read it.
    it('gives the same answer whichever leg it is told it decides', function (): void {
        $rounds = seatedRounds([
            [['a', 'd'], ['b', 'c']],
            [['b', 'a'], ['d', 'c']],
            [['a', 'c'], ['d', 'b']],
        ]);
        $assignment = new BalancedRoleAssignment();

        expect($assignment->assignRoles($rounds, new RoleAssignmentContext(3, 4)))
            ->toBe($assignment->assignRoles($rounds, new RoleAssignmentContext(1, 1)));
    });
});

describe('RoleAssignmentContext', function (): void {
    it('describes a single leg with nothing fixed when it is given nothing', function (): void {
        $context = new RoleAssignmentContext();

        expect($context->leg)->toBe(1)
            ->and($context->legs)->toBe(1)
            ->and($context->hasFixedSeatings())->toBeFalse()
            ->and($context->isFixed(0, 0))->toBeFalse();
    });

    it('says which leg of how many, and which seatings are fixed, by round and position', function (): void {
        $context = new RoleAssignmentContext(2, 3, [[0, 1], [4, 0]]);

        expect($context->leg)->toBe(2)
            ->and($context->legs)->toBe(3)
            ->and($context->hasFixedSeatings())->toBeTrue()
            ->and($context->isFixed(0, 1))->toBeTrue()
            ->and($context->isFixed(4, 0))->toBeTrue()
            // Round and position are not exchanged, and nothing else is fixed
            ->and($context->isFixed(1, 0))->toBeFalse()
            ->and($context->isFixed(0, 4))->toBeFalse()
            ->and($context->isFixed(0, 0))->toBeFalse()
            // "1:10" and "11:0" are two seatings
            ->and(new RoleAssignmentContext(1, 1, [[1, 10]])->isFixed(11, 0))->toBeFalse();
    });
});

describe('BalancedRoleAssignment with fixed seatings', function (): void {
    // The balanced leg of four participants from the test of the circle
    // rule, with its first seating reversed and fixed, as the scheduler
    // hands a leg over after a constraint rejected "d first-named against
    // a". That leaves a three apart one way and d three apart the other:
    //
    //   round 1: a-d (fixed)  b-c
    //   round 2: a-b          c-d
    //   round 3: c-a          d-b
    //
    // The one chain from a to d that does not use the fixed seating is
    // a-b, b-c, c-d.
    it('keeps a fixed seating and balances the leg around it', function (): void {
        $given = [
            [['a', 'd'], ['b', 'c']],
            [['a', 'b'], ['c', 'd']],
            [['c', 'a'], ['d', 'b']],
        ];
        expect(seatedDifferences($given))->toBe(['a' => 1, 'd' => -1, 'b' => -1, 'c' => 1]);

        // Balanced as it stands: nothing to do
        $context = new RoleAssignmentContext(1, 1, [[0, 0]]);
        expect(seatedIds(new BalancedRoleAssignment()->assignRoles(seatedRounds($given), $context)))->toBe($given);

        // Out of balance: a first-named three times, d second-named three times
        $lopsided = [
            [['a', 'd'], ['b', 'c']],
            [['a', 'b'], ['c', 'd']],
            [['a', 'c'], ['b', 'd']],
        ];
        expect(seatedDifferences($lopsided))->toBe(['a' => 3, 'd' => -3, 'b' => 1, 'c' => -1]);

        $assigned = seatedIds(new BalancedRoleAssignment()->assignRoles(seatedRounds($lopsided), $context));

        expect($assigned[0][0])->toBe(['a', 'd']);
        foreach (seatedDifferences($assigned) as $difference) {
            expect(abs($difference))->toBe(1);
        }
    });

    // Without the context the circle rule would decide every seating, the
    // fixed one included: it reverses seating 0 of every round of a field
    // of even size.
    it('leaves the circle rule out when a seating is fixed', function (): void {
        $proposed = seatedRounds([
            [['a', 'd'], ['b', 'c']],
            [['b', 'a'], ['d', 'c']],
            [['a', 'c'], ['d', 'b']],
        ]);

        $free = seatedIds(new BalancedRoleAssignment()->assignRoles($proposed, new RoleAssignmentContext()));
        $fixed = seatedIds(new BalancedRoleAssignment()->assignRoles($proposed, new RoleAssignmentContext(1, 1, [[0, 0]])));

        expect($free[0][0])->toBe(['d', 'a'])
            ->and($fixed[0][0])->toBe(['a', 'd']);
        foreach (seatedDifferences($fixed) as $difference) {
            expect(abs($difference))->toBe(1);
        }
    });

    // a is fixed first-named in all three of its events: no leg of four
    // participants with those roles is balanced. The leg comes back as it
    // can be, with the fixed seatings as given, and the scheduler, which
    // holds every leg to the rule, does not use it.
    it('returns the fixed seatings as given when they leave no balanced leg', function (): void {
        $given = [
            [['a', 'd'], ['b', 'c']],
            [['a', 'b'], ['c', 'd']],
            [['a', 'c'], ['d', 'b']],
        ];
        $context = new RoleAssignmentContext(1, 1, [[0, 0], [1, 0], [2, 0]]);

        $assigned = seatedIds(new BalancedRoleAssignment()->assignRoles(seatedRounds($given), $context));

        expect($assigned[0][0])->toBe(['a', 'd'])
            ->and($assigned[1][0])->toBe(['a', 'b'])
            ->and($assigned[2][0])->toBe(['a', 'c'])
            ->and(seatedDifferences($assigned)['a'])->toBe(3);
    });

    // The claim of the class: with fixed seatings it finds a balanced leg
    // whenever one exists that keeps them. "One exists" is counted here, by
    // trying every way to play the free seatings: legs of up to twelve
    // seatings, so at most 4,096 ways each.
    it('finds a balanced leg exactly when the fixed seatings leave one', function (): void {
        $randomizer = new Randomizer(new Mt19937(51));
        $ids = ['a', 'b', 'c', 'd', 'e', '1', '01'];
        $assignment = new BalancedRoleAssignment();
        $faults = [];
        $withOne = 0;
        $withNone = 0;

        for ($case = 0; $case < 300; ++$case) {
            $field = array_slice($ids, 0, $randomizer->getInt(3, count($ids)));
            $proposed = [];
            $fixed = [];
            $seatingCount = 0;
            for ($round = 0; $round < $randomizer->getInt(1, 4); ++$round) {
                $seatings = [];
                for ($position = 0; $position < $randomizer->getInt(1, 3); ++$position) {
                    [$first, $second] = $randomizer->pickArrayKeys($field, 2);
                    $seatings[] = $randomizer->getInt(0, 1) === 0 ? [$field[$first], $field[$second]] : [$field[$second], $field[$first]];
                    if ($randomizer->getInt(0, 2) === 0) {
                        $fixed[] = [$round, $position];
                    }
                    ++$seatingCount;
                }
                $proposed[] = $seatings;
            }
            if ($fixed === []) {
                $fixed[] = [0, 0];
            }
            $isFixed = static fn(int $round, int $position): bool => in_array([$round, $position], $fixed, true);

            // Every way to play the free seatings
            $free = [];
            foreach ($proposed as $round => $seatings) {
                foreach ($seatings as $position => $seating) {
                    if (!$isFixed($round, $position)) {
                        $free[] = [$round, $position];
                    }
                }
            }
            $exists = false;
            for ($roles = 0; $roles < 2 ** count($free) && !$exists; ++$roles) {
                $candidate = $proposed;
                foreach ($free as $bit => [$round, $position]) {
                    if ((($roles >> $bit) & 1) === 1) {
                        $candidate[$round][$position] = [$candidate[$round][$position][1], $candidate[$round][$position][0]];
                    }
                }
                $exists = seatedWorstDifference($candidate) <= 1;
            }
            $exists ? ++$withOne : ++$withNone;

            $answer = seatedIds($assignment->assignRoles(seatedRounds($proposed), new RoleAssignmentContext(1, 1, $fixed)));

            foreach ($proposed as $round => $seatings) {
                foreach ($seatings as $position => $seating) {
                    if ($isFixed($round, $position) && $answer[$round][$position] !== $seating) {
                        $faults[] = "case {$case}: a fixed seating was reversed";
                    }
                    if (!in_array($answer[$round][$position], [$seating, [$seating[1], $seating[0]]], true)) {
                        $faults[] = "case {$case}: a seating is neither as given nor reversed";
                    }
                }
            }

            $balanced = seatedWorstDifference($answer) <= 1;
            if ($balanced !== $exists) {
                $faults[] = "case {$case} ({$seatingCount} seatings): a balanced leg " . ($exists ? 'exists and was not found' : 'does not exist and one was returned');
            }
        }

        expect($faults)->toBe([])
            // Both answers were compared
            ->and($withOne)->toBeGreaterThan(20)
            ->and($withNone)->toBeGreaterThan(20);
    });
});

describe('the rule of a role assignment', function (): void {
    it('is none for round parity, which keeps every leg', function (): void {
        expect(new RoundParityRoleAssignment()->rule())->toBeNull();
    });

    /**
     * A leg of a round robin of $size participants, with roles drawn at
     * random: the participants, and the events. Every event is in the first
     * round, which the rule does not mind: it counts the events of a leg,
     * and reads a round only to tell which leg an event is in.
     *
     * @return array{list<Participant>, list<Event>}
     */
    $randomLeg = static function (int $size, Randomizer $randomizer): array {
        $participants = [];
        for ($number = 1; $number <= $size; ++$number) {
            $participants[] = new Participant((string) $number, "Participant {$number}");
        }

        $events = [];
        for ($first = 0; $first < $size; ++$first) {
            for ($second = $first + 1; $second < $size; ++$second) {
                $pair = $randomizer->getInt(0, 1) === 0
                    ? [$participants[$first], $participants[$second]]
                    : [$participants[$second], $participants[$first]];
                $events[] = new Event($pair, new Round(1));
            }
        }

        return [$participants, $events];
    };

    // What the scheduler relies on: the rule of the balanced role
    // assignment accepts every event of a leg, put one after the other,
    // exactly when the leg is one the assignment returns unchanged. Legs
    // of 3 to 7 participants with roles drawn at random are both kinds.
    it('accepts every event of a leg exactly when the balanced role assignment keeps the leg', function () use ($randomLeg): void {
        $randomizer = new Randomizer(new Mt19937(151));
        $assignment = new BalancedRoleAssignment();
        $rule = $assignment->rule();
        $kept = 0;
        $changed = 0;

        for ($case = 0; $case < 400; ++$case) {
            $size = $randomizer->getInt(3, 7);
            [$participants, $events] = $randomLeg($size, $randomizer);
            $plan = new RoundRobinPlan($participants, 1);

            $accepted = true;
            $placed = [];
            foreach ($events as $event) {
                if (!$rule->isSatisfied($event, new SchedulingContext($participants, $plan, $placed, 1))) {
                    $accepted = false;
                    break;
                }
                $placed[] = $event;
            }

            $rounds = array_map(static fn(Event $event): array => [[$event->getParticipants()[0], $event->getParticipants()[1]]], $events);
            $isKept = $assignment->assignRoles($rounds, new RoleAssignmentContext()) === $rounds;
            $isKept ? ++$kept : ++$changed;

            expect($accepted)->toBe($isKept, "case {$case}, {$size} participants");
        }

        expect($kept)->toBeGreaterThan(10)->and($changed)->toBeGreaterThan(10);
    });

    // Three participants play two events each in a leg: one in each role.
    // The rule refuses the event that would give a participant the same
    // role twice in a leg, and counts the roles of one leg only.
    it('refuses the event that gives a participant a role too often in its leg, and counts no other leg', function (): void {
        $a = new Participant('a', 'A');
        $b = new Participant('b', 'B');
        $c = new Participant('c', 'C');
        $plan = new RoundRobinPlan([$a, $b, $c], 2);
        $rule = new BalancedRoleAssignment()->rule();

        $firstLeg = [new Event([$a, $b], new Round(1))];
        $context = new SchedulingContext([$a, $b, $c], $plan, $firstLeg, 1);

        expect($rule->getName())->toBe('Balanced roles in a leg')
            ->and($rule->isSatisfied(new Event([$a, $c], new Round(2)), $context))->toBeFalse()
            ->and($rule->isSatisfied(new Event([$c, $a], new Round(2)), $context))->toBeTrue()
            // b was second-named once already
            ->and($rule->isSatisfied(new Event([$c, $b], new Round(3)), $context))->toBeFalse()
            ->and($rule->isSatisfied(new Event([$b, $c], new Round(3)), $context))->toBeTrue();

        // Round 4 opens the second leg, in which a has played nothing yet
        $secondLeg = new SchedulingContext([$a, $b, $c], $plan, $firstLeg, 2);
        expect($rule->isSatisfied(new Event([$a, $c], new Round(4)), $secondLeg))->toBeTrue();

        // An event that is not a pairing has no two roles to count
        expect($rule->isSatisfied(new Event([$a, $b, $c], new Round(2)), $context))->toBeTrue();
    });
});
