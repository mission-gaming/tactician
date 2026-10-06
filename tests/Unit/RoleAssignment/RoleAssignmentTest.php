<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\RoleAssignment\BalancedRoleAssignment;
use MissionGaming\Tactician\RoleAssignment\RoundParityRoleAssignment;

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

        expect((new RoundParityRoleAssignment())->assignRoles($rounds))->toBe($rounds);
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

        $assigned = seatedIds((new BalancedRoleAssignment())->assignRoles(seatedRounds($proposed)));

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

        $assigned = seatedIds((new BalancedRoleAssignment())->assignRoles(seatedRounds($proposed)));

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
        expect((new BalancedRoleAssignment())->assignRoles($twoParticipants))->toBe($twoParticipants);

        // Balanced, and not what the circle rule would produce
        $balanced = seatedRounds([
            [['a', 'b'], ['c', 'd']],
            [['c', 'a'], ['d', 'b']],
            [['a', 'd'], ['b', 'c']],
        ]);
        expect((new BalancedRoleAssignment())->assignRoles($balanced))->toBe($balanced);
    });

    // The guarantee does not depend on the layout: a leg that is not a
    // circle layout is repaired. The ids are numeric strings on purpose:
    // PHP turns such an array key into an integer.
    it('balances a leg the circle rule cannot, changing nothing but roles', function (int $size): void {
        $proposed = lopsidedLeg($size);
        // Participant 1 is first-named in every one of its events
        expect(array_values(seatedDifferences($proposed))[0] ?? null)->toBe($size - 1);

        $assigned = seatedIds((new BalancedRoleAssignment())->assignRoles(seatedRounds($proposed)));

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

        $assigned = seatedIds((new BalancedRoleAssignment())->assignRoles(seatedRounds($proposed)));
        $differences = seatedDifferences($assigned);

        // a plays 4 and b and c play 2: even. d and e play 1: one apart.
        expect($differences['a'])->toBe(0);
        expect($differences['b'])->toBe(0);
        expect($differences['c'])->toBe(0);
        expect(abs($differences['d']))->toBe(1);
        expect(abs($differences['e']))->toBe(1);
    });

    it('gives the same answer for the same rounds', function (): void {
        $rounds = seatedRounds(lopsidedLeg(9));

        expect((new BalancedRoleAssignment())->assignRoles($rounds))
            ->toBe((new BalancedRoleAssignment())->assignRoles($rounds));
    });
});
