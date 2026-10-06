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
use MissionGaming\Tactician\Scheduling\ConstraintPurity;
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
 *
 * @throws Throwable Whatever a constraint throws
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
 * Constraint sets for the comparison. Some are sets ConstraintPurity
 * knows, for which the search may take its shortcut; the others hold a
 * callable or a role extractor, and must be searched plainly: verdicts that
 * depend on the pair alone, on the pair's history, and on the round's other
 * pairings.
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
        'role balance of 1' => new ConstraintSet([RoleBalanceConstraint::homeAway(1)]),
        'role balance of 1 and seed protection' => new ConstraintSet([
            RoleBalanceConstraint::homeAway(1),
            new SeedProtectionConstraint(3, 1.0),
        ]),
    ];
}

/**
 * Both searches on one input, with the plain allowance at its default, at
 * zero (the shortcut from the first step, where it may be taken at all) and
 * at three (the shortcut after a few candidates).
 *
 * With no allowance a known constraint set must go to the shortcut, and
 * a set that is not known must never: it is searched plainly whatever the
 * allowance.
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

    $known = ConstraintPurity::isKnown($constraints);
    foreach ([null, 0, 3] as $allowance) {
        $search = new SwissRoundSearch($constraints, $history['played'], $history['homeCounts'], $context, 4, $allowance);
        $found = describePairing($search->pair($field));
        $shortcuts = $search->getPrunedSearchCount();
        if ($found !== $expected || $shortcuts > 1 || (!$known && $shortcuts !== 0) || ($known && $allowance === 0 && $shortcuts !== 1)) {
            // One expectation per input would be half a million of them.
            expect([$found, $shortcuts, $allowance])->toBe([$expected, $known && $allowance === 0 ? 1 : 0, $allowance]);
        }
    }

    return $expected;
}

describe('SwissRoundSearch, against the search without pruning', function (): void {
    it('returns the same pairing, or none, for every history of four participants', function (): void {
        $field = swissSearchField(4);
        $allPairs = [[0, 1], [0, 2], [0, 3], [1, 2], [1, 3], [2, 3]];

        $paired = 0;
        $unpairable = 0;
        foreach (swissSearchConstraints() as $constraints) {
            for ($bits = 0; $bits < 64; ++$bits) {
                $played = [];
                foreach ($allPairs as $bit => $pair) {
                    if ((($bits >> $bit) & 1) === 1) {
                        $played[] = $pair;
                    }
                }
                expectTheSearchesToAgree($field, $played, $constraints) === null ? ++$unpairable : ++$paired;
            }
        }

        expect($paired + $unpairable)->toBe(64 * count(swissSearchConstraints()));
        expect($paired)->toBeGreaterThan(100);
        expect($unpairable)->toBeGreaterThan(100);
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
            // Every third of the 32,768 histories without constraints; every
            // 11th with each constraint set, offset so that the sets see
            // different ones.
            $step = $constraints === null ? 3 : 11;
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
        $paired = 0;
        $unpairable = 0;

        for ($case = 0; $case < 640; ++$case) {
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

            expectTheSearchesToAgree($field, $played, $constraintSets[$case % count($constraintSets)]) === null ? ++$unpairable : ++$paired;
        }

        expect($paired)->toBeGreaterThan(50);
        expect($unpairable)->toBeGreaterThan(50);
    });

    it('takes the shortcut past a dead end the plain search would walk for hours', function (?ConstraintSet $constraints): void {
        // Two halves of eleven that have played every pairing across the
        // halves: only pairings inside a half are left, and eleven cannot be
        // paired off. The pairing order alternates between the halves, so
        // the plain search pairs ten of each half in every way there is
        // (10,395 ways each, one inside the other) before giving up. A
        // search that did not take the shortcut would not finish this test.
        $field = swissSearchField(22);
        $crossPairs = [];
        for ($a = 0; $a < 11; ++$a) {
            for ($b = 11; $b < 22; ++$b) {
                $crossPairs[] = [$a, $b];
            }
        }
        $history = swissHistory($field, $crossPairs);
        $context = new SchedulingContext($field, new SwissPlan($field, null), $history['events']);
        $alternating = [];
        for ($i = 0; $i < 11; ++$i) {
            $alternating[] = $field[$i];
            $alternating[] = $field[11 + $i];
        }

        $search = new SwissRoundSearch($constraints, $history['played'], $history['homeCounts'], $context, 12);

        expect($search->pair($alternating))->toBeNull();
        expect($search->getPrunedSearchCount())->toBe(1);
    })->with([
        'no constraints' => [null],
        'a constraint the search knows' => [fn(): ConstraintSet => new ConstraintSet([new MinimumRestPeriodsConstraint(1)])],
    ]);

    it('does not prune a round that pairs without a dead end', function (): void {
        $field = swissSearchField(40);
        $context = new SchedulingContext($field, new SwissPlan($field, 5), []);
        $search = new SwissRoundSearch(null, [], [], $context, 1);

        expect(count($search->pair($field) ?? []))->toBe(20);
        expect($search->getPrunedSearchCount())->toBe(0);
    });
});

/**
 * What a search did, in a form two searches can be compared by: the pairing
 * it returned, or the exception it threw.
 *
 * @param Closure(): (list<Event>|null) $search
 */
function swissSearchOutcome(Closure $search): string
{
    try {
        return 'paired: ' . (describePairing($search()) ?? 'nothing');
    } catch (Throwable $thrown) {
        return 'threw ' . $thrown::class . ': ' . $thrown->getMessage();
    }
}

describe('SwissRoundSearch under constraints that could tell how they are asked', function (): void {
    it('asks a constraint it does not know exactly what the plain search asks, in the same order', function (): void {
        // The log is what a constraint of the caller's could observe: each
        // candidate it was shown, and the events of the round so far in the
        // context it was shown it with.
        $log = [];
        $constraints = new ConstraintSet([
            RoleBalanceConstraint::homeAway(1),
            new CallableConstraint(
                static function (Event $event, SchedulingContext $context) use (&$log): bool {
                    $inRound = $context->getEventsInRound($event->getRound()?->getNumber() ?? 0);
                    $log[] = describePairing([$event]) . ' after ' . describePairing(array_values($inRound));

                    return (count($log) + count($inRound)) % 4 !== 0;
                },
                'Keeps a log, and answers by how often it was asked'
            ),
        ]);

        $randomizer = new Randomizer(new Mt19937(41));
        $questions = 0;
        $outcomes = [];
        for ($case = 0; $case < 150; ++$case) {
            $size = 2 * $randomizer->getInt(3, 6);
            $field = $randomizer->shuffleArray(swissSearchField($size));
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

            $log = [];
            $expected = swissSearchOutcome(fn(): ?array => pairRoundWithoutPruning($field, $history['played'], $history['homeCounts'], $constraints, $context, 4));
            $expectedLog = $log;
            $questions += count($expectedLog);
            $outcomes[$expected === 'paired: nothing' ? 'nothing' : 'paired'] = true;

            // An allowance of zero would send a known set to the shortcut
            // at once; this set must be searched plainly whatever it is.
            foreach ([null, 0, 5] as $allowance) {
                $log = [];
                $search = new SwissRoundSearch($constraints, $history['played'], $history['homeCounts'], $context, 4, $allowance);

                expect(swissSearchOutcome(fn(): ?array => $search->pair($field)))->toBe($expected);
                expect($log)->toBe($expectedLog);
                expect($search->getPrunedSearchCount())->toBe(0);
            }
        }

        // The comparison saw both outcomes, and enough questions for an
        // allowance to have run out many times over.
        expect($outcomes)->toHaveCount(2);
        expect($questions)->toBeGreaterThan(150 * 10);
    });

    it('lets a constraint of the caller\'s fail exactly where the plain search meets the failure', function (): void {
        // The role extractor throws when it is asked about participant s5.
        // A search that skipped a branch, or asked about a pair the plain
        // search never reaches, would throw where the plain search pairs,
        // or pair where it throws.
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

        $threw = 0;
        $paired = 0;
        for ($bits = 0; $bits < 32768; $bits += 13) {
            $played = [];
            foreach ($allPairs as $bit => $pair) {
                if ((($bits >> $bit) & 1) === 1) {
                    $played[] = $pair;
                }
            }
            $history = swissHistory($field, $played);
            $context = new SchedulingContext($field, new SwissPlan($field, 6), $history['events']);

            $expected = swissSearchOutcome(fn(): ?array => pairRoundWithoutPruning($field, $history['played'], $history['homeCounts'], $throwing, $context, 4));
            str_starts_with($expected, 'threw') ? ++$threw : ++$paired;

            foreach ([null, 0] as $allowance) {
                $search = new SwissRoundSearch($throwing, $history['played'], $history['homeCounts'], $context, 4, $allowance);
                $outcome = swissSearchOutcome(fn(): ?array => $search->pair($field));
                if ($outcome !== $expected) {
                    expect([$bits, $allowance, $outcome])->toBe([$bits, $allowance, $expected]);
                }
            }
        }

        expect($threw)->toBeGreaterThan(100);
        expect($paired)->toBeGreaterThan(100);
    });

    it('gives up the shortcut when a constraint it knows fails on a malformed history', function (): void {
        // RoleBalanceConstraint reads the two participants of a recorded
        // event by position, and fails on an event whose participants are
        // not a list. The shortcut puts every open pair to the constraints
        // before it starts; when one of those questions fails it must run
        // the plain search instead, so that the failure comes from the
        // question the plain search asks, or not at all when the plain
        // search never asks it.
        $constraints = new ConstraintSet([RoleBalanceConstraint::homeAway(1)]);
        $randomizer = new Randomizer(new Mt19937(7));

        // The warning PHP raises for the missing position becomes an
        // exception, as it is in an application that converts errors.
        set_error_handler(static function (int $severity, string $message): never {
            throw new ErrorException($message, 0, $severity);
        });

        $outcomes = ['threw' => 0, 'paired' => 0, 'nothing' => 0];
        try {
            for ($case = 0; $case < 300; ++$case) {
                $size = 2 * $randomizer->getInt(3, 5);
                $field = swissSearchField($size);
                $played = [];
                for ($a = 0; $a < $size; ++$a) {
                    for ($b = $a + 1; $b < $size; ++$b) {
                        if ($randomizer->getInt(0, 99) < 55) {
                            $played[] = [$a, $b];
                        }
                    }
                }
                $history = swissHistory($field, $played);
                // One recorded event, of one participant, keyed by role.
                $victim = $randomizer->getInt(0, $size - 1);
                $other = ($victim + 1 + $randomizer->getInt(0, $size - 2)) % $size;
                $events = [...$history['events'], new Event(['home' => $field[$victim], 'away' => $field[$other]], new Round(3))];
                $context = new SchedulingContext($field, new SwissPlan($field, 6), $events);

                $expected = swissSearchOutcome(fn(): ?array => pairRoundWithoutPruning($field, $history['played'], $history['homeCounts'], $constraints, $context, 4));
                ++$outcomes[str_starts_with($expected, 'threw') ? 'threw' : ($expected === 'paired: nothing' ? 'nothing' : 'paired')];

                foreach ([null, 0, 2] as $allowance) {
                    $search = new SwissRoundSearch($constraints, $history['played'], $history['homeCounts'], $context, 4, $allowance);
                    $outcome = swissSearchOutcome(fn(): ?array => $search->pair($field));
                    if ($outcome !== $expected) {
                        expect([$case, $allowance, $outcome])->toBe([$case, $allowance, $expected]);
                    }
                }
            }
        } finally {
            restore_error_handler();
        }

        // Every pairing needs the participant whose history is malformed, so
        // the plain search either fails on it or finds no pairing without
        // asking about it. A shortcut that went on without the pairs whose
        // question failed would report "nothing" for all of them.
        expect($outcomes['threw'])->toBeGreaterThan(100);
        expect($outcomes['nothing'])->toBeGreaterThan(10);
        expect($outcomes['paired'])->toBe(0);
    });
});

describe('The constraints the Swiss round search may take its shortcut for', function (): void {
    it('give one verdict on a pair whatever else the round holds', function (): void {
        // Instances of every class ConstraintPurity lists. A class added
        // to the list without an instance here fails the test.
        $instances = [
            NoRepeatPairings::class => [new NoRepeatPairings(), new NoRepeatPairings(acrossLegs: true)],
            MinimumRestPeriodsConstraint::class => [new MinimumRestPeriodsConstraint(1), new MinimumRestPeriodsConstraint(3)],
            RoleBalanceConstraint::class => [RoleBalanceConstraint::homeAway(1), RoleBalanceConstraint::homeAway(2)],
            SeedProtectionConstraint::class => [new SeedProtectionConstraint(2, 0.5), new SeedProtectionConstraint(4, 1.0)],
        ];
        expect(array_keys($instances))->toEqualCanonicalizing(ConstraintPurity::KNOWN);

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
        expect($rejections)->toBeGreaterThan(150);
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

    it('lets a constraint of the caller\'s fail in a branch that holds no pairing', function (): void {
        // Sixteen participants: seven and nine that have played every
        // pairing across the two groups, so no pairing is left (seven
        // cannot be paired off). The plain search still walks the pairings
        // inside the groups before it gives up, and on the way it asks the
        // constraint about s7 and s9, which the constraint cannot judge.
        // The engine has always let that failure through. A search that
        // skipped the branch would report "no valid pairing" instead.
        $field = swissSearchField(16);
        $state = StageState::start($field);
        for ($round = 0; $round < 9; ++$round) {
            $events = [];
            $playing = [];
            for ($i = 0; $i < 7; ++$i) {
                $opponent = 7 + (($i + $round) % 9);
                $playing[$opponent] = true;
                $events[] = new Event([$field[$i], $field[$opponent]], new Round($round + 1));
            }
            $byes = [];
            for ($opponent = 7; $opponent < 16; ++$opponent) {
                if (!isset($playing[$opponent])) {
                    $byes[] = $field[$opponent];
                }
            }
            $state = $state->withRoundPlayed(new RoundPairing($round + 1, null, $events, $byes), []);
        }

        $constraints = new ConstraintSet([new CallableConstraint(
            static function (Event $event): bool {
                $ids = array_map(static fn(Participant $participant): string => $participant->getId(), $event->getParticipants());
                if (in_array('s7', $ids, true) && in_array('s9', $ids, true)) {
                    throw new LogicException('This constraint cannot judge s7 against s9');
                }

                return true;
            },
            'Cannot judge one pairing'
        )]);

        expect(fn() => (new SwissPairingEngine($constraints))->pairNextRound($state))
            ->toThrow(LogicException::class, 'This constraint cannot judge s7 against s9');
        // The state itself has no pairing: without the constraint, that is
        // what the engine reports.
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
