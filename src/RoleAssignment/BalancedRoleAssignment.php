<?php

declare(strict_types=1);

namespace MissionGaming\Tactician\RoleAssignment;

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\Exceptions\InvariantViolationException;
use Override;

/**
 * Role assignment that ends every leg with each participant's two role
 * counts as close as the number of its events allows: equal when it plays an
 * even number of events in the leg, one apart when it plays an odd number.
 *
 * In a round robin that is a difference of at most 1 in a field of even size
 * (each participant plays an odd number of events in a leg) and of exactly 0
 * in a field of odd size. It holds for every leg of rounds the assignment is
 * given, whatever the pairings in them are.
 *
 * The answer is the first of these that is balanced:
 *
 * 1. The roles as given. A leg that is already balanced is not changed.
 * 2. The circle rule, for a leg the circle method laid out. Seating `k` of
 *    a round (counted from 0) keeps the proposed roles when `k` plus the
 *    round number is even and is reversed when it is odd. Seating 0, which
 *    holds the seat that never rotates, is always reversed in a field of
 *    even size; in a field of odd size the participant opposite the fixed
 *    seat takes the role the rule gives it in the seating of its bye. A
 *    participant then alternates roles from round to round and repeats a
 *    role at most once in the leg, when it meets the fixed seat or when its
 *    bye falls between two events.
 * 3. A repair of the roles as given, for any other layout (a first leg the
 *    backtracking search found). While a participant is 2 or more out of
 *    balance, the pairings along a shortest chain from it to a participant
 *    out of balance the other way are reversed. That moves the two ends
 *    towards balance and changes no participant between them.
 *
 * Only step 2 says anything about streaks. It needs no randomness, and the
 * same rounds always give the same answer.
 *
 * @experimental
 */
final readonly class BalancedRoleAssignment implements RoleAssignmentInterface
{
    #[Override]
    public function assignRoles(array $rounds): array
    {
        if ($this->isBalanced($rounds)) {
            return $rounds;
        }

        $byCircleRule = $this->applyCircleRule($rounds);
        if ($this->isBalanced($byCircleRule)) {
            return $byCircleRule;
        }

        return $this->repair($rounds);
    }

    /**
     * First-named count minus second-named count of every participant, in
     * the order the participants first appear.
     *
     * @param list<list<array{0: Participant|null, 1: Participant|null}>> $rounds
     * @return array<string, int>
     */
    private function differences(array $rounds): array
    {
        $differences = [];
        foreach ($rounds as $seatings) {
            foreach ($seatings as [$first, $second]) {
                if ($first === null || $second === null) {
                    continue;
                }

                $differences[$first->getId()] = ($differences[$first->getId()] ?? 0) + 1;
                $differences[$second->getId()] = ($differences[$second->getId()] ?? 0) - 1;
            }
        }

        return $differences;
    }

    /**
     * Whether every participant is as close to balance as the number of its
     * events allows. The difference of two counts has the parity of their
     * sum, so a difference of at most 1 is 0 for an even number of events.
     *
     * @param list<list<array{0: Participant|null, 1: Participant|null}>> $rounds
     */
    private function isBalanced(array $rounds): bool
    {
        foreach ($this->differences($rounds) as $difference) {
            if (abs($difference) > 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<list<array{0: Participant|null, 1: Participant|null}>> $rounds
     * @return list<list<array{0: Participant|null, 1: Participant|null}>>
     */
    private function applyCircleRule(array $rounds): array
    {
        $rolesOppositeBye = $this->rolesOppositeBye($rounds);
        $hasByes = $rolesOppositeBye !== [];
        $fixedSeat = $hasByes ? $this->fixedSeat($rounds) : null;

        $assigned = [];
        foreach ($rounds as $index => $seatings) {
            $round = $index + 1;
            $roundSeatings = [];

            foreach ($seatings as $position => $seating) {
                [$first, $second] = $seating;
                if ($first === null || $second === null) {
                    $roundSeatings[] = $seating;
                    continue;
                }

                if ($position > 0) {
                    $reverse = ($position + $round) % 2 === 1;
                } elseif (!$hasByes) {
                    $reverse = true;
                } else {
                    $rotatingIsFirst = $first->getId() !== $fixedSeat;
                    $rotating = $rotatingIsFirst ? $first : $second;
                    $reverse = ($rolesOppositeBye[$rotating->getId()] ?? $rotatingIsFirst) !== $rotatingIsFirst;
                }

                $roundSeatings[] = $reverse ? [$second, $first] : $seating;
            }

            $assigned[] = $roundSeatings;
        }

        return $assigned;
    }

    /**
     * For every participant with a bye away from seating 0: whether the
     * circle rule would name it first in the seating of its bye. An empty
     * result means the leg lists no bye.
     *
     * @param list<list<array{0: Participant|null, 1: Participant|null}>> $rounds
     * @return array<string, bool>
     */
    private function rolesOppositeBye(array $rounds): array
    {
        $roles = [];
        foreach ($rounds as $index => $seatings) {
            foreach ($seatings as $position => [$first, $second]) {
                $resting = $first ?? $second;
                if ($resting === null || ($first !== null && $second !== null)) {
                    continue;
                }

                $kept = ($position + $index + 1) % 2 === 0;
                // Seating 0 with a bye is the fixed seat's own bye: no
                // participant sits opposite the fixed seat in that round.
                $roles[$resting->getId()] = $position > 0 && ($first !== null) === $kept;
            }
        }

        return $roles;
    }

    /**
     * The participant in seating 0 of every round: the seat the circle
     * method never rotates. Null when no single participant is.
     *
     * @param list<list<array{0: Participant|null, 1: Participant|null}>> $rounds
     */
    private function fixedSeat(array $rounds): ?string
    {
        $candidates = null;
        foreach ($rounds as $seatings) {
            $ids = [];
            foreach ($seatings[0] ?? [] as $seat) {
                if ($seat !== null) {
                    $ids[] = $seat->getId();
                }
            }

            $candidates = $candidates === null ? $ids : array_values(array_intersect($candidates, $ids));
        }

        return $candidates !== null && count($candidates) === 1 ? $candidates[0] : null;
    }

    /**
     * Reverse chains of pairings until no participant is 2 or more out of
     * balance.
     *
     * Each pass takes 2 off the difference of the participant it starts
     * from, which lowers the amount by which that participant exceeds a
     * difference of 1, and it raises no other participant above 1, so the
     * loop ends.
     *
     * @param list<list<array{0: Participant|null, 1: Participant|null}>> $rounds
     * @return list<list<array{0: Participant|null, 1: Participant|null}>>
     */
    private function repair(array $rounds): array
    {
        while (true) {
            $differences = $this->differences($rounds);

            $start = null;
            foreach ($differences as $id => $difference) {
                if (abs($difference) >= 2) {
                    // PHP turns a numeric-string array key into an integer
                    $start = (string) $id;
                    break;
                }
            }

            if ($start === null) {
                return $rounds;
            }

            $rounds = $this->reverseSeatings($rounds, $this->chainFrom($rounds, $differences, $start));
        }
    }

    /**
     * The shortest chain of pairings from a participant that is out of
     * balance to one that is out of balance the other way, each pairing
     * entered through the role the start has too many of.
     *
     * Such a chain exists. Take the set of participants that chains from a
     * start with too many first-named roles can reach: every pairing between
     * the set and the rest names the participant outside it first, so the
     * differences inside the set sum to zero or less, and with the start at
     * 2 or more some other member is below zero. The mirror image holds for
     * a start with too many second-named roles.
     *
     * @param list<list<array{0: Participant|null, 1: Participant|null}>> $rounds
     * @param array<string, int> $differences
     * @return array<string, true> The seatings of the chain, as "round:position" keys
     */
    private function chainFrom(array $rounds, array $differences, string $start): array
    {
        $startIsFirstTooOften = $differences[$start] > 0;

        /** @var array<string, list<array{0: string, 1: string}>> $steps participant => [neighbour, seating key] */
        $steps = [];
        foreach ($rounds as $index => $seatings) {
            foreach ($seatings as $position => [$first, $second]) {
                if ($first === null || $second === null) {
                    continue;
                }

                [$from, $to] = $startIsFirstTooOften ? [$first, $second] : [$second, $first];
                $steps[$from->getId()][] = [$to->getId(), $index . ':' . $position];
            }
        }

        /** @var array<string, array{0: string, 1: string}|null> $reachedBy participant => [previous participant, seating key] */
        $reachedBy = [$start => null];
        $queue = [$start];

        while ($queue !== []) {
            $current = array_shift($queue);

            foreach ($steps[$current] ?? [] as [$neighbour, $seatingKey]) {
                if (array_key_exists($neighbour, $reachedBy)) {
                    continue;
                }

                $reachedBy[$neighbour] = [$current, $seatingKey];

                $difference = $differences[$neighbour] ?? 0;
                if ($startIsFirstTooOften ? $difference < 0 : $difference > 0) {
                    $chain = [];
                    for ($step = $reachedBy[$neighbour]; $step !== null; $step = $reachedBy[$step[0]] ?? null) {
                        $chain[$step[1]] = true;
                    }

                    return $chain;
                }

                $queue[] = $neighbour;
            }
        }

        // Not reached: the differences of a leg sum to zero, so a participant
        // that has one role too often is connected to one that has the other
        // role too often (the argument is in the docblock)
        throw new InvariantViolationException('A participant out of balance has no chain to one out of balance the other way');
    }

    /**
     * @param list<list<array{0: Participant|null, 1: Participant|null}>> $rounds
     * @param array<string, true> $seatingKeys The seatings to reverse, as "round:position" keys
     * @return list<list<array{0: Participant|null, 1: Participant|null}>>
     */
    private function reverseSeatings(array $rounds, array $seatingKeys): array
    {
        $reversed = [];
        foreach ($rounds as $index => $seatings) {
            $roundSeatings = [];
            foreach ($seatings as $position => $seating) {
                $roundSeatings[] = isset($seatingKeys[$index . ':' . $position])
                    ? [$seating[1], $seating[0]]
                    : $seating;
            }

            $reversed[] = $roundSeatings;
        }

        return $reversed;
    }
}
