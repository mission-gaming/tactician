<?php

declare(strict_types=1);

use MissionGaming\Tactician\Constraints\CallableConstraint;
use MissionGaming\Tactician\Constraints\ConsecutiveRoleConstraint;
use MissionGaming\Tactician\Constraints\ConstraintSet;
use MissionGaming\Tactician\Constraints\MinimumRestPeriodsConstraint;
use MissionGaming\Tactician\Constraints\NoRepeatPairings;
use MissionGaming\Tactician\Constraints\RoleBalanceConstraint;
use MissionGaming\Tactician\Constraints\SeedProtectionConstraint;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Round;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\NoValidPairingException;
use MissionGaming\Tactician\Scheduling\SchedulingContext;
use MissionGaming\Tactician\Scheduling\SwissOptions;
use MissionGaming\Tactician\Scheduling\SwissPairingEngine;
use MissionGaming\Tactician\Scheduling\SwissRoundSearch;
use MissionGaming\Tactician\Scheduling\SwissScheduler;
use MissionGaming\Tactician\Stage\PairKey;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Stage\SwissPlan;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * The Swiss round search with no pruning at all: the first unpaired
 * participant against each later one in order, skipping a pair that has
 * played or that the constraints reject, the constraints seeing the round's
 * events so far. This is the search SwissRoundSearch must agree with on
 * every input, written out plainly so that it can be read as the definition.
 *
 * @param list<Participant> $ordered
 * @param array<string, bool> $played
 * @param array<string, int> $homeCounts
 * @param list<Event> $roundEvents
 * @return list<Event>|null
 */
function pairRoundWithoutPruning(
    array $ordered,
    array $played,
    array $homeCounts,
    ?ConstraintSet $constraints,
    SchedulingContext $context,
    int $roundNumber,
    array $roundEvents = []
): ?array {
    if ($ordered === []) {
        return $roundEvents;
    }

    $participant = array_shift($ordered);
    foreach ($ordered as $index => $opponent) {
        if (isset($played[PairKey::of($participant->getId(), $opponent->getId())])) {
            continue;
        }

        $home = ($homeCounts[$participant->getId()] ?? 0) < ($homeCounts[$opponent->getId()] ?? 0)
            ? [$participant, $opponent]
            : [$opponent, $participant];
        $event = new Event($home, new Round($roundNumber));
        if ($constraints !== null && !$constraints->isSatisfied($event, $context->withEvents($roundEvents))) {
            continue;
        }

        $rest = $ordered;
        unset($rest[$index]);
        $pairings = pairRoundWithoutPruning(array_values($rest), $played, $homeCounts, $constraints, $context, $roundNumber, [...$roundEvents, $event]);
        if ($pairings !== null) {
            return $pairings;
        }
    }

    return null;
}

/**
 * @param list<Event>|null $events
 */
function describePairing(?array $events): ?string
{
    if ($events === null) {
        return null;
    }

    return implode(' ', array_map(
        static fn(Event $event): string => $event->getParticipants()[0]->getId() . '-' . $event->getParticipants()[1]->getId()
            . '@' . ($event->getRound()?->getNumber() ?? 0),
        $events
    ));
}

/**
 * @return list<Participant>
 */
function swissSearchField(int $size): array
{
    $field = [];
    for ($i = 0; $i < $size; ++$i) {
        $field[] = new Participant('s' . $i, 'Participant ' . $i, $i + 1);
    }

    return $field;
}

/**
 * A recorded history in which exactly the given pairs have played: one
 * event per pair, in rounds 1 to 3, with home going to the first of the
 * pair or the second by turns.
 *
 * @param list<Participant> $field
 * @param list<array{int, int}> $pairs
 * @return array{events: list<Event>, played: array<string, bool>, homeCounts: array<string, int>}
 */
function swissHistory(array $field, array $pairs): array
{
    $events = [];
    $played = [];
    $homeCounts = [];
    foreach ($pairs as $number => [$a, $b]) {
        [$home, $away] = $number % 2 === 0 ? [$field[$a], $field[$b]] : [$field[$b], $field[$a]];
        $events[] = new Event([$home, $away], new Round($number % 3 + 1));
        $played[PairKey::of($home->getId(), $away->getId())] = true;
        $homeCounts[$home->getId()] = ($homeCounts[$home->getId()] ?? 0) + 1;
    }

    return ['events' => $events, 'played' => $played, 'homeCounts' => $homeCounts];
}

/**
 * Constraint sets for the comparison: verdicts that depend on the pair
 * alone, on the pair's history, and on the round's other pairings (which
 * the pruned search may assume nothing about).
 *
 * @return array<string, ConstraintSet|null>
 */
function swissSearchConstraints(): array
{
    return [
        'none' => null,
        'an empty set' => new ConstraintSet([]),
        'role streak of 1' => new ConstraintSet([ConsecutiveRoleConstraint::homeAway(1)]),
        'role balance of 1 and no neighbours' => new ConstraintSet([
            RoleBalanceConstraint::homeAway(1),
            new CallableConstraint(
                static fn(Event $event): bool => abs(($event->getParticipants()[0]->getSeed() ?? 0) - ($event->getParticipants()[1]->getSeed() ?? 0)) !== 1,
                'No neighbours'
            ),
        ]),
        'depends on the round so far' => new ConstraintSet([new CallableConstraint(
            static function (Event $event, SchedulingContext $context): bool {
                $inRound = $context->getEventsInRound($event->getRound()?->getNumber() ?? 0);
                $last = $inRound === [] ? null : $inRound[array_key_last($inRound)];

                return (count($inRound) + ($event->getParticipants()[0]->getSeed() ?? 0)
                    + ($last?->getParticipants()[1]->getSeed() ?? 0)) % 3 !== 0;
            },
            'Round so far'
        )]),
        'seed protection and minimum rest' => new ConstraintSet([
            new SeedProtectionConstraint(2, 1.0),
            new MinimumRestPeriodsConstraint(2),
            new NoRepeatPairings(),
        ]),
    ];
}

/**
 * Both searches on one input, with the plain allowance at its default and
 * at zero (pruning from the first step).
 *
 * @param list<Participant> $field
 * @param list<array{int, int}> $playedPairs
 *
 * @throws InvalidConfigurationException
 */
function expectTheSearchesToAgree(array $field, array $playedPairs, ?ConstraintSet $constraints): ?string
{
    $history = swissHistory($field, $playedPairs);
    $context = new SchedulingContext($field, new SwissPlan($field, 6), $history['events']);
    $expected = describePairing(pairRoundWithoutPruning($field, $history['played'], $history['homeCounts'], $constraints, $context, 4));

    foreach ([null, 0, 3] as $allowance) {
        $search = new SwissRoundSearch($constraints, $history['played'], $history['homeCounts'], $context, 4, $allowance);
        expect(describePairing($search->pair($field)))->toBe($expected);
    }

    return $expected;
}

describe('SwissRoundSearch, against the search without pruning', function (): void {
    it('returns the same pairing, or none, for every history of four participants', function (): void {
        $field = swissSearchField(4);
        $allPairs = [[0, 1], [0, 2], [0, 3], [1, 2], [1, 3], [2, 3]];

        foreach (swissSearchConstraints() as $constraints) {
            for ($bits = 0; $bits < 64; ++$bits) {
                $played = [];
                foreach ($allPairs as $bit => $pair) {
                    if ((($bits >> $bit) & 1) === 1) {
                        $played[] = $pair;
                    }
                }
                expectTheSearchesToAgree($field, $played, $constraints);
            }
        }
    });

    it('returns the same pairing, or none, for every history of six participants', function (): void {
        $field = swissSearchField(6);
        $allPairs = [];
        for ($a = 0; $a < 6; ++$a) {
            for ($b = $a + 1; $b < 6; ++$b) {
                $allPairs[] = [$a, $b];
            }
        }

        $paired = 0;
        $unpairable = 0;
        foreach (array_values(swissSearchConstraints()) as $number => $constraints) {
            // All 32,768 histories without constraints; every 11th with each
            // constraint set, offset so that the sets see different ones.
            $step = $constraints === null ? 1 : 11;
            for ($bits = $number; $bits < 32768; $bits += $step) {
                $played = [];
                foreach ($allPairs as $bit => $pair) {
                    if ((($bits >> $bit) & 1) === 1) {
                        $played[] = $pair;
                    }
                }
                $result = expectTheSearchesToAgree($field, $played, $constraints);
                $result === null ? ++$unpairable : ++$paired;
            }
        }

        expect($paired)->toBeGreaterThan(5000);
        expect($unpairable)->toBeGreaterThan(5000);
    });

    it('returns the same pairing, or none, for generated histories of eight to twelve', function (): void {
        $randomizer = new Randomizer(new Mt19937(2026));
        $constraintSets = array_values(swissSearchConstraints());

        for ($case = 0; $case < 1200; ++$case) {
            $size = 2 * $randomizer->getInt(4, 6);
            $field = $randomizer->shuffleArray(swissSearchField($size));
            // Dense histories, where few pairs are left and dead ends are common.
            $density = $randomizer->getInt(40, 90);
            $played = [];
            for ($a = 0; $a < $size; ++$a) {
                for ($b = $a + 1; $b < $size; ++$b) {
                    if ($randomizer->getInt(0, 99) < $density) {
                        $played[] = [$a, $b];
                    }
                }
            }

            expectTheSearchesToAgree($field, $played, $constraintSets[$case % count($constraintSets)]);
        }
    });

    it('prunes a dead end the plain search would walk for minutes', function (): void {
        // Two halves of eleven that have played every pairing across the
        // halves: only pairings inside a half are left, and eleven cannot be
        // paired off. Without pruning the search pairs ten of the first half
        // in every way there is before giving up, and took 32 seconds at
        // this size when measured.
        $field = swissSearchField(22);
        $crossPairs = [];
        for ($a = 0; $a < 11; ++$a) {
            for ($b = 11; $b < 22; ++$b) {
                $crossPairs[] = [$a, $b];
            }
        }
        $history = swissHistory($field, $crossPairs);
        $context = new SchedulingContext($field, new SwissPlan($field, null), $history['events']);

        // A constraint that reads the context, so the search may assume
        // nothing about it and prunes on the played pairings alone.
        $evaluations = 0;
        $constraints = new ConstraintSet([new CallableConstraint(
            static function (Event $event, SchedulingContext $context) use (&$evaluations): bool {
                ++$evaluations;

                return $context->getEventCount() >= 0;
            },
            'Counts evaluations'
        )]);

        $search = new SwissRoundSearch($constraints, $history['played'], $history['homeCounts'], $context, 12);

        expect($search->pair($field))->toBeNull();
        expect($search->getPrunedSearchCount())->toBe(1);
        // The plain search is allowed 22 x 22 candidates; the pruned one
        // then finds no perfect matching at the root and tries none.
        expect($evaluations)->toBeLessThanOrEqual(484);
    });

    it('does not prune a round that pairs without a dead end', function (): void {
        $field = swissSearchField(40);
        $context = new SchedulingContext($field, new SwissPlan($field, 5), []);
        $search = new SwissRoundSearch(null, [], [], $context, 1);

        expect(count($search->pair($field) ?? []))->toBe(20);
        expect($search->getPrunedSearchCount())->toBe(0);
    });

    it('lets a failing constraint fail where the plain search meets it, and nowhere else', function (): void {
        // The role extractor throws when it is asked about participant s5.
        // The pruned search may ask about a pair the plain search never
        // reaches; such a failure must not escape. If the pruned search
        // throws, the plain search must have thrown too.
        $throwing = new ConstraintSet([new ConsecutiveRoleConstraint(
            2,
            static function (Event $event, Participant $participant): string {
                if ($participant->getId() === 's5') {
                    throw new RuntimeException('The extractor cannot place s5');
                }

                return $event->getParticipants()[0]->getId() === $participant->getId() ? 'home' : 'away';
            }
        )]);
        $field = swissSearchField(6);
        $allPairs = [];
        for ($a = 0; $a < 6; ++$a) {
            for ($b = $a + 1; $b < 6; ++$b) {
                $allPairs[] = [$a, $b];
            }
        }

        $prunedThrew = 0;
        $prunedPaired = 0;
        for ($bits = 0; $bits < 32768; $bits += 5) {
            $played = [];
            foreach ($allPairs as $bit => $pair) {
                if ((($bits >> $bit) & 1) === 1) {
                    $played[] = $pair;
                }
            }
            $history = swissHistory($field, $played);
            $context = new SchedulingContext($field, new SwissPlan($field, 6), $history['events']);

            $plainThrew = false;
            $plain = null;
            try {
                $plain = describePairing(pairRoundWithoutPruning($field, $history['played'], $history['homeCounts'], $throwing, $context, 4));
            } catch (RuntimeException) {
                $plainThrew = true;
            }

            try {
                $pruned = describePairing((new SwissRoundSearch($throwing, $history['played'], $history['homeCounts'], $context, 4, 0))->pair($field));
                ++$prunedPaired;
                if (!$plainThrew) {
                    expect($pruned)->toBe($plain);
                }
            } catch (RuntimeException) {
                ++$prunedThrew;
                expect($plainThrew)->toBeTrue();
            }
        }

        expect($prunedThrew)->toBeGreaterThan(100);
        expect($prunedPaired)->toBeGreaterThan(100);
    });
});

describe('The constraints SwissRoundSearch takes as independent of the round', function (): void {
    it('give one verdict on a pair whatever else the round holds', function (): void {
        // One instance of every class the search lists. A class added to
        // the list without an instance here fails the test.
        $instances = [
            NoRepeatPairings::class => [new NoRepeatPairings(), new NoRepeatPairings(acrossLegs: true)],
            MinimumRestPeriodsConstraint::class => [new MinimumRestPeriodsConstraint(1), new MinimumRestPeriodsConstraint(3)],
            ConsecutiveRoleConstraint::class => [ConsecutiveRoleConstraint::homeAway(1), ConsecutiveRoleConstraint::position(2)],
            RoleBalanceConstraint::class => [RoleBalanceConstraint::homeAway(1), RoleBalanceConstraint::homeAway(2)],
            SeedProtectionConstraint::class => [new SeedProtectionConstraint(2, 0.5), new SeedProtectionConstraint(4, 1.0)],
        ];
        $listed = (new ReflectionClassConstant(SwissRoundSearch::class, 'INDEPENDENT_OF_THE_ROUND'))->getValue();
        expect($listed)->toBeArray();
        expect(array_keys($instances))->toEqualCanonicalizing($listed);

        $randomizer = new Randomizer(new Mt19937(99));
        $rejections = 0;
        for ($case = 0; $case < 400; ++$case) {
            $field = swissSearchField(2 * $randomizer->getInt(3, 6));
            $size = count($field);
            $played = [];
            for ($a = 0; $a < $size; ++$a) {
                for ($b = $a + 1; $b < $size; ++$b) {
                    if ($randomizer->getInt(0, 99) < 45) {
                        $played[] = [$a, $b];
                    }
                }
            }
            $history = swissHistory($field, $played);
            $context = new SchedulingContext($field, new SwissPlan($field, 6), $history['events']);

            // A candidate pair, and pairings of the same round among the others.
            $order = $randomizer->shuffleArray($field);
            $candidate = new Event([$order[0], $order[1]], new Round(4));
            $others = [];
            for ($i = 2; $i + 1 < $size; $i += 2) {
                if ($randomizer->getInt(0, 3) !== 0) {
                    $others[] = new Event([$order[$i], $order[$i + 1]], new Round(4));
                }
            }
            $withTheRound = $context->withEvents($others);

            foreach ($instances as $constraintsOfClass) {
                foreach ($constraintsOfClass as $constraint) {
                    $verdict = $constraint->isSatisfied($candidate, $context);
                    expect($constraint->isSatisfied($candidate, $withTheRound))->toBe($verdict);
                    $rejections += (int) !$verdict;
                }
            }
        }

        // The verdicts compared were not all "yes".
        expect($rejections)->toBeGreaterThan(200);
    });
});

describe('Swiss pairing where dead ends are common', function (): void {
    it('fails at once in a state with no pairing left', function (): void {
        // The dead end above, through the engine: 22 participants, eleven
        // recorded rounds in which each half played only the other half.
        $field = swissSearchField(22);
        $state = StageState::start($field);
        for ($round = 0; $round < 11; ++$round) {
            $events = [];
            for ($i = 0; $i < 11; ++$i) {
                $events[] = new Event([$field[$i], $field[11 + (($i + $round) % 11)]], new Round($round + 1));
            }
            $state = $state->withRoundPlayed(new RoundPairing($round + 1, null, $events), []);
        }

        expect(fn() => (new SwissPairingEngine())->pairNextRound($state))->toThrow(NoValidPairingException::class);
    });

    it('schedules 24 participants over 5 rounds under a role streak limit of 2', function (): void {
        // The limit rules out many pairings of the later rounds, so this is
        // a round the search has to back out of dead ends to pair.
        $field = swissSearchField(24);
        $schedule = (new SwissScheduler(new ConstraintSet([ConsecutiveRoleConstraint::homeAway(2)])))
            ->schedule($field, new SwissOptions(5));

        expect(count($schedule))->toBe(60);

        $pairs = [];
        $roles = [];
        $perRound = [];
        foreach ($schedule as $event) {
            [$home, $away] = $event->getParticipants();
            $round = $event->getRound()?->getNumber() ?? 0;
            $pairs[] = PairKey::of($home->getId(), $away->getId());
            $roles[$home->getId()][$round] = 'home';
            $roles[$away->getId()][$round] = 'away';
            $perRound[$round] = ($perRound[$round] ?? 0) + 1;
        }

        expect($perRound)->toBe([1 => 12, 2 => 12, 3 => 12, 4 => 12, 5 => 12]);
        expect(count(array_unique($pairs)))->toBe(60);
        foreach ($roles as $byRound) {
            expect(count($byRound))->toBe(5);
            ksort($byRound);
            $sequence = implode(',', $byRound);
            expect($sequence)->not->toContain('home,home,home');
            expect($sequence)->not->toContain('away,away,away');
        }
    });
});
