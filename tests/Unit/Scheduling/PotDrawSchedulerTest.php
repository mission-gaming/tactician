<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Schedule;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Scheduling\PotDrawOptions;
use MissionGaming\Tactician\Scheduling\PotDrawScheduler;
use MissionGaming\Tactician\Scheduling\SwissOptions;
use MissionGaming\Tactician\Stage\PotDrawPlan;
use MissionGaming\Tactician\Tests\Support\PotDrawAudit;

/**
 * Entrants "e1".."en" in seeding order.
 *
 * @return list<Participant>
 */
function potDrawEntrants(int $count): array
{
    $entrants = [];
    for ($i = 1; $i <= $count; ++$i) {
        $entrants[] = new Participant("e{$i}", "Entrant {$i}");
    }

    return $entrants;
}

/**
 * The configuration error a call ends in.
 *
 * @param Closure(): mixed $call
 */
function potDrawRefusal(Closure $call): InvalidConfigurationException
{
    try {
        $call();
    } catch (InvalidConfigurationException $exception) {
        return $exception;
    }

    throw new LogicException('The call was not refused.');
}

/**
 * The events as "round:first-second" strings, in schedule order.
 *
 * @return list<string>
 */
function potDrawEventList(Schedule $schedule): array
{
    $list = [];
    foreach ($schedule->getEvents() as $event) {
        $pair = $event->getParticipants();
        $list[] = $event->getRound()?->getNumber() . ':' . $pair[0]->getId() . '-' . $pair[1]->getId();
    }

    return $list;
}

describe('PotDrawScheduler', function (): void {
    // The three worked cases of the format. Each asserts the numbers the
    // format states, counted in the events by PotDrawAudit.
    it('draws 6 entrants in 3 pots of 2 with one opponent per pot', function (int $seed): void {
        $entrants = potDrawEntrants(6);
        $schedule = (new PotDrawScheduler())->schedule($entrants, new PotDrawOptions(pots: 3, opponentsPerPot: 1, seed: $seed));
        $audit = PotDrawAudit::of($schedule, $entrants, 3);

        expect($audit->malformed)->toBe([])
            ->and($audit->eventsByRound)->toBe([1 => 3, 2 => 3, 3 => 3]);

        foreach ($entrants as $entrant) {
            $id = $entrant->getId();
            // Once per round, so 3 events each
            expect([$audit->appearancesByRound[1][$id], $audit->appearancesByRound[2][$id], $audit->appearancesByRound[3][$id]])
                ->toBe([1, 1, 1], $id);
            // Exactly one opponent from each pot, its own included
            $opponents = $audit->opponentsByPot[$id];
            ksort($opponents);
            expect($opponents)->toBe([1 => 1, 2 => 1, 3 => 1], $id);
        }

        // No rematch: 9 events between 9 different pairs
        expect($audit->meetings)->toHaveCount(9)
            ->and(array_unique(array_values($audit->meetings)))->toBe([1])
            ->and($audit->violations($entrants, 3, 1))->toBe([]);
    })->with([1, 2, 3, 42, 1337]);

    it('draws 20 entrants in 5 pots of 4 with one opponent per pot', function (int $seed): void {
        $entrants = potDrawEntrants(20);
        $schedule = (new PotDrawScheduler())->schedule($entrants, new PotDrawOptions(pots: 5, opponentsPerPot: 1, seed: $seed));
        $audit = PotDrawAudit::of($schedule, $entrants, 5);

        expect($schedule->count())->toBe(50)
            ->and($audit->malformed)->toBe([])
            ->and($audit->eventsByRound)->toBe([1 => 10, 2 => 10, 3 => 10, 4 => 10, 5 => 10]);

        foreach ($entrants as $entrant) {
            $id = $entrant->getId();
            for ($round = 1; $round <= 5; ++$round) {
                expect($audit->appearancesByRound[$round][$id] ?? 0)->toBe(1, "{$id} in round {$round}");
            }

            $opponents = $audit->opponentsByPot[$id];
            ksort($opponents);
            expect($opponents)->toBe([1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 1], $id);

            // 5 events each; the two role counts differ by at most one
            $first = array_sum($audit->firstRoleByPot[$id] ?? []);
            $second = array_sum($audit->secondRoleByPot[$id] ?? []);
            expect($first + $second)->toBe(5, $id)
                ->and(abs($first - $second))->toBe(1, $id);
        }

        expect($audit->meetings)->toHaveCount(50)
            ->and(array_unique(array_values($audit->meetings)))->toBe([1])
            ->and($audit->violations($entrants, 5, 1))->toBe([]);
    })->with([1, 2, 3, 42, 1337]);

    it('draws 36 entrants in 4 pots of 9 with two opponents per pot', function (int $seed): void {
        $entrants = potDrawEntrants(36);
        $schedule = (new PotDrawScheduler())->schedule($entrants, new PotDrawOptions(pots: 4, opponentsPerPot: 2, seed: $seed));
        $audit = PotDrawAudit::of($schedule, $entrants, 4);

        expect($schedule->count())->toBe(144)
            ->and($audit->malformed)->toBe([])
            ->and($audit->eventsByRound)->toBe(array_fill(1, 8, 18));

        foreach ($entrants as $entrant) {
            $id = $entrant->getId();
            for ($round = 1; $round <= 8; ++$round) {
                expect($audit->appearancesByRound[$round][$id] ?? 0)->toBe(1, "{$id} in round {$round}");
            }

            // Exactly two opponents from each pot: 8 events
            $opponents = $audit->opponentsByPot[$id];
            ksort($opponents);
            expect($opponents)->toBe([1 => 2, 2 => 2, 3 => 2, 4 => 2], $id);

            // One event in each role against every pot: 4 in each role
            $first = $audit->firstRoleByPot[$id];
            $second = $audit->secondRoleByPot[$id];
            ksort($first);
            ksort($second);
            expect($first)->toBe([1 => 1, 2 => 1, 3 => 1, 4 => 1], $id)
                ->and($second)->toBe([1 => 1, 2 => 1, 3 => 1, 4 => 1], $id);
        }

        expect($audit->meetings)->toHaveCount(144)
            ->and(array_unique(array_values($audit->meetings)))->toBe([1])
            ->and($audit->violations($entrants, 4, 2))->toBe([]);
    })->with([1, 2, 3, 42, 1337]);

    // The worked cases above have no pot of even size with two or more
    // opponents per pot. These two are small enough to check by hand, and
    // they are the family in which the layers of the draw must not share a
    // pairing and the roles inside a pot come from walking cycles.
    it('draws 8 entrants in 2 pots of 4 with two opponents per pot', function (int $seed): void {
        $entrants = potDrawEntrants(8);
        $schedule = (new PotDrawScheduler())->schedule($entrants, new PotDrawOptions(pots: 2, opponentsPerPot: 2, seed: $seed));
        $audit = PotDrawAudit::of($schedule, $entrants, 2);

        // 2 pots × 2 opponents = 4 rounds of 4 events
        expect($schedule->count())->toBe(16)
            ->and($audit->malformed)->toBe([])
            ->and($audit->eventsByRound)->toBe([1 => 4, 2 => 4, 3 => 4, 4 => 4]);

        foreach ($entrants as $entrant) {
            $id = $entrant->getId();
            for ($round = 1; $round <= 4; ++$round) {
                expect($audit->appearancesByRound[$round][$id] ?? 0)->toBe(1, "{$id} in round {$round}");
            }

            // Two of the three others in its own pot, two of the four in the other
            $opponents = $audit->opponentsByPot[$id];
            ksort($opponents);
            expect($opponents)->toBe([1 => 2, 2 => 2], $id);

            // One event in each role against each pot
            $first = $audit->firstRoleByPot[$id];
            $second = $audit->secondRoleByPot[$id];
            ksort($first);
            ksort($second);
            expect($first)->toBe([1 => 1, 2 => 1], $id)
                ->and($second)->toBe([1 => 1, 2 => 1], $id);
        }

        // No rematch: 16 events between 16 different pairs, of which 4
        // are inside each pot and 8 are between the pots
        $inside = array_filter(
            array_keys($audit->meetings),
            static function (string $pair): bool {
                [$a, $b] = explode('|', $pair);

                return in_array($a, ['e1', 'e2', 'e3', 'e4'], true) === in_array($b, ['e1', 'e2', 'e3', 'e4'], true);
            }
        );
        expect($audit->meetings)->toHaveCount(16)
            ->and(array_unique(array_values($audit->meetings)))->toBe([1])
            ->and($inside)->toHaveCount(8)
            ->and($audit->violations($entrants, 2, 2))->toBe([]);
    })->with([0, 1, 2, 3, 4, 5, 6, 7, 42, 1337]);

    it('draws 8 entrants in 2 pots of 4 with three opponents per pot', function (int $seed): void {
        $entrants = potDrawEntrants(8);
        $schedule = (new PotDrawScheduler())->schedule($entrants, new PotDrawOptions(pots: 2, opponentsPerPot: 3, seed: $seed));
        $audit = PotDrawAudit::of($schedule, $entrants, 2);

        // 2 pots × 3 opponents = 6 rounds of 4 events
        expect($schedule->count())->toBe(24)
            ->and($audit->malformed)->toBe([])
            ->and($audit->eventsByRound)->toBe(array_fill(1, 6, 4));

        foreach ($entrants as $entrant) {
            $id = $entrant->getId();
            for ($round = 1; $round <= 6; ++$round) {
                expect($audit->appearancesByRound[$round][$id] ?? 0)->toBe(1, "{$id} in round {$round}");
            }

            // All three others in its own pot, three of the four in the other
            $opponents = $audit->opponentsByPot[$id];
            ksort($opponents);
            expect($opponents)->toBe([1 => 3, 2 => 3], $id);

            // 6 events, so 3 in each role; against one pot the three events
            // split two and one
            $first = $audit->firstRoleByPot[$id] ?? [];
            $second = $audit->secondRoleByPot[$id] ?? [];
            expect(array_sum($first))->toBe(3, $id)
                ->and(array_sum($second))->toBe(3, $id);
            foreach ([1, 2] as $pot) {
                expect(abs(($first[$pot] ?? 0) - ($second[$pot] ?? 0)))->toBe(1, "{$id} against pot {$pot}");
            }
        }

        // No rematch: every pairing inside a pot is played (6 in each),
        // and 12 of the 16 pairings between the pots
        foreach ([['e1', 'e2', 'e3', 'e4'], ['e5', 'e6', 'e7', 'e8']] as $pot) {
            foreach ($pot as $i => $a) {
                foreach (array_slice($pot, $i + 1) as $b) {
                    expect($audit->meetings["{$a}|{$b}"] ?? 0)->toBe(1, "{$a} and {$b}");
                }
            }
        }
        expect($audit->meetings)->toHaveCount(24)
            ->and(array_unique(array_values($audit->meetings)))->toBe([1])
            ->and($audit->violations($entrants, 2, 3))->toBe([]);
    })->with([0, 1, 2, 3, 4, 5, 6, 7, 42, 1337]);

    it('gives the same schedule for the same participants, options and seed', function (int $pots, int $count, int $opponentsPerPot): void {
        $entrants = potDrawEntrants($count);
        $options = new PotDrawOptions($pots, $opponentsPerPot, seed: 2026);

        $scheduler = new PotDrawScheduler();
        $first = potDrawEventList($scheduler->schedule($entrants, $options));
        // The same instance again, after a draw with another seed in between
        $scheduler->schedule($entrants, new PotDrawOptions($pots, $opponentsPerPot, seed: 7));
        $second = potDrawEventList($scheduler->schedule($entrants, $options));
        // A new instance, and options rebuilt from plain data
        $third = potDrawEventList((new PotDrawScheduler())->schedule($entrants, PotDrawOptions::fromArray($options->toArray())));

        expect($second)->toBe($first)
            ->and($third)->toBe($first);
    })->with([
        '6 in 3 pots' => [3, 6, 1],
        '20 in 5 pots' => [5, 20, 1],
        '36 in 4 pots' => [4, 36, 2],
    ]);

    it('gives a different schedule for every seed', function (int $pots, int $count, int $opponentsPerPot): void {
        $entrants = potDrawEntrants($count);
        $scheduler = new PotDrawScheduler();

        $draws = [];
        for ($seed = 0; $seed < 25; ++$seed) {
            $draws[] = implode(' ', potDrawEventList($scheduler->schedule($entrants, new PotDrawOptions($pots, $opponentsPerPot, $seed))));
        }

        expect(array_unique($draws))->toHaveCount(25);
    })->with([
        '20 in 5 pots' => [5, 20, 1],
        '36 in 4 pots' => [4, 36, 2],
        '16 in 2 pots, three opponents' => [2, 16, 3],
    ]);

    // 6 entrants in 3 pots of 2 allow few schedules, so two seeds may draw
    // the same one; the seed still decides which
    it('draws more than one schedule for the smallest worked case', function (): void {
        $entrants = potDrawEntrants(6);

        $draws = [];
        for ($seed = 0; $seed < 25; ++$seed) {
            $draws[] = implode(' ', potDrawEventList((new PotDrawScheduler())->schedule($entrants, new PotDrawOptions(3, 1, $seed))));
        }

        expect(count(array_unique($draws)))->toBeGreaterThan(5);
    });

    it('treats seeds that differ by 2 to the power 32 as different seeds', function (): void {
        $entrants = potDrawEntrants(20);
        $scheduler = new PotDrawScheduler();

        expect(potDrawEventList($scheduler->schedule($entrants, new PotDrawOptions(5, 1, 1))))
            ->not->toBe(potDrawEventList($scheduler->schedule($entrants, new PotDrawOptions(5, 1, 1 + 2 ** 32))));
    });

    // Any integer is a seed: the ends of the range and the negative ones
    // draw a schedule that keeps the rules, and each draws its own
    it('draws from the seeds at the ends of the integer range', function (int $count, int $pots, int $opponentsPerPot): void {
        $entrants = potDrawEntrants($count);
        $scheduler = new PotDrawScheduler();

        $draws = [];
        foreach ([0, -1, 1, PHP_INT_MAX, PHP_INT_MIN, -PHP_INT_MAX] as $seed) {
            $schedule = $scheduler->schedule($entrants, new PotDrawOptions($pots, $opponentsPerPot, $seed));

            expect(PotDrawAudit::of($schedule, $entrants, $pots)->violations($entrants, $pots, $opponentsPerPot))->toBe([], "seed {$seed}")
                ->and($schedule->getMetadataValue('seed'))->toBe($seed);
            $draws[] = implode(' ', potDrawEventList($schedule));
        }

        expect(array_unique($draws))->toHaveCount(6);
    })->with([
        '20 in 5 pots' => [20, 5, 1],
        '36 in 4 pots' => [36, 4, 2],
        '16 in 2 pots, three opponents' => [16, 2, 3],
    ]);

    // The usage guide states what no seed changes ("The seed and
    // determinism"). This is a limit of the construction and not a rule
    // of the format: if the generator learns to mix the pots within a
    // round, this test and that passage change together.
    it('keeps whole pots together in a round whatever the seed', function (
        int $count,
        int $pots,
        int $opponentsPerPot,
        int $roundsWithEventsInsideAPot
    ): void {
        $entrants = potDrawEntrants($count);
        $potSize = intdiv($count, $pots);
        $potOf = [];
        foreach ($entrants as $position => $entrant) {
            $potOf[$entrant->getId()] = intdiv($position, $potSize);
        }

        for ($seed = 0; $seed < 20; ++$seed) {
            $schedule = (new PotDrawScheduler())->schedule($entrants, new PotDrawOptions($pots, $opponentsPerPot, $seed));

            $potsMet = [];
            $firstRoleHolders = [];
            $inside = [];
            foreach ($schedule->getEvents() as $event) {
                [$first, $second] = $event->getParticipants();
                $round = (int) $event->getRound()?->getNumber();
                $a = $potOf[$first->getId()];
                $b = $potOf[$second->getId()];
                $potsMet[$round][$a][$b] = true;
                $potsMet[$round][$b][$a] = true;
                if ($a === $b) {
                    $inside[$round] = true;
                } else {
                    $firstRoleHolders[$round][min($a, $b) . '-' . max($a, $b)][$a] = true;
                }
            }

            foreach ($potsMet as $round => $byPot) {
                foreach ($byPot as $pot => $met) {
                    // One pot only, unless the round also has events inside pots
                    expect(count($met))->toBeLessThanOrEqual(isset($inside[$round]) ? 2 : 1, "seed {$seed}, round {$round}, pot {$pot}");
                }
            }
            expect($inside)->toHaveCount($roundsWithEventsInsideAPot, "seed {$seed}");

            if ($opponentsPerPot % 2 === 0) {
                foreach ($firstRoleHolders as $round => $byPotPair) {
                    foreach ($byPotPair as $potPair => $holders) {
                        expect($holders)->toHaveCount(1, "seed {$seed}, round {$round}, pots {$potPair}");
                    }
                }
            }
        }
    })->with([
        // An even pot size and an even number of pots: the pots play
        // inside themselves in as many rounds as there are opponents per pot
        '16 in 4 pots of 4, two opponents' => [16, 4, 2, 2],
        '24 in 4 pots of 6, three opponents' => [24, 4, 3, 3],
        // An odd pot size: three rounds hold every event inside a pot
        '36 in 4 pots of 9, two opponents' => [36, 4, 2, 3],
        '18 in 6 pots of 3, two opponents' => [18, 6, 2, 3],
    ]);

    it('takes the pots from list position and reads no seed attribute', function (): void {
        // The seed attributes say the opposite of the list order
        $entrants = [];
        for ($i = 1; $i <= 8; ++$i) {
            $entrants[] = new Participant("e{$i}", "Entrant {$i}", 9 - $i);
        }
        $options = new PotDrawOptions(pots: 2, opponentsPerPot: 1, seed: 5);

        $schedule = (new PotDrawScheduler())->schedule($entrants, $options);

        // Counted with pots cut from list order: e1..e4 are pot 1
        expect(PotDrawAudit::of($schedule, $entrants, 2)->violations($entrants, 2, 1))->toBe([])
            ->and(potDrawEventList($schedule))->toBe(potDrawEventList((new PotDrawScheduler())->schedule(potDrawEntrants(8), $options)));
    });

    it('draws a different field when the list order changes', function (): void {
        $entrants = potDrawEntrants(8);
        $reordered = [$entrants[4], ...array_slice($entrants, 1, 3), $entrants[0], ...array_slice($entrants, 5)];
        $options = new PotDrawOptions(pots: 2, opponentsPerPot: 2, seed: 5);

        $schedule = (new PotDrawScheduler())->schedule($reordered, $options);

        // e5 is now in pot 1 and e1 in pot 2: the rules hold for that cut
        // and not for the original one
        expect(PotDrawAudit::of($schedule, $reordered, 2)->violations($reordered, 2, 2))->toBe([])
            ->and(PotDrawAudit::of($schedule, $entrants, 2)->violations($entrants, 2, 2))->not->toBe([]);
    });

    it('records the configuration in the schedule metadata', function (): void {
        $schedule = (new PotDrawScheduler())->schedule(potDrawEntrants(36), new PotDrawOptions(4, 2, 2026));

        expect($schedule->getMetadata())->toBe([
            'algorithm' => 'pot-draw',
            'participant_count' => 36,
            'pots' => 4,
            'pot_size' => 9,
            'opponents_per_pot' => 2,
            'seed' => 2026,
            'total_rounds' => 8,
            'expected_event_count' => 144,
        ]);
    });

    it('numbers the rounds from 1 and emits them in order', function (): void {
        $schedule = (new PotDrawScheduler())->schedule(potDrawEntrants(36), new PotDrawOptions(4, 2, 3));

        $rounds = [];
        foreach ($schedule->getEvents() as $event) {
            $rounds[] = $event->getRound()?->getNumber();
        }
        $sorted = $rounds;
        sort($sorted);

        expect($rounds)->toBe($sorted)
            ->and(array_values(array_unique($rounds)))->toBe(range(1, 8));
    });

    it('with no options pairs the whole field once as one pot', function (): void {
        $entrants = potDrawEntrants(8);
        $schedule = (new PotDrawScheduler())->schedule($entrants);

        expect($schedule->count())->toBe(4)
            ->and($schedule->getMetadataValue('seed'))->toBe(0)
            ->and(PotDrawAudit::of($schedule, $entrants, 1)->violations($entrants, 1, 1))->toBe([]);
    });

    it('reports the plan before anything is drawn', function (): void {
        $plan = (new PotDrawScheduler())->getPlan(potDrawEntrants(36), new PotDrawOptions(4, 2));

        expect($plan)->toBeInstanceOf(PotDrawPlan::class)
            ->and($plan->getAlgorithm())->toBe('pot-draw')
            ->and($plan->getTotalRounds())->toBe(8)
            ->and($plan->getExpectedEventCount())->toBe(144);
    });

    it('generates exactly what its plan reports', function (int $count, int $pots, int $opponentsPerPot): void {
        $entrants = potDrawEntrants($count);
        $options = new PotDrawOptions($pots, $opponentsPerPot, 11);
        $scheduler = new PotDrawScheduler();

        $plan = $scheduler->getPlan($entrants, $options);
        $schedule = $scheduler->schedule($entrants, $options);

        expect($schedule->count())->toBe($plan->getExpectedEventCount())
            ->and($schedule->getMaxRound()?->getNumber())->toBe($plan->getTotalRounds())
            ->and($plan->validateIntegrity($schedule))->toBe([]);
    })->with([
        [6, 3, 1],
        [20, 5, 1],
        [36, 4, 2],
        [12, 1, 11],
        [18, 6, 2],
    ]);

    describe('refuses, before anything is drawn,', function (): void {
        // Each refusal comes out of schedule() and of getPlan() alike, and
        // getPlan() draws nothing: the configuration is refused from its
        // numbers alone.
        it('a configuration that cannot exist', function (
            int $count,
            int $pots,
            int $opponentsPerPot,
            InvalidConfigurationReason $reason,
            array $context
        ): void {
            $entrants = potDrawEntrants($count);
            $options = new PotDrawOptions($pots, $opponentsPerPot, 1);

            $fromSchedule = potDrawRefusal(fn() => (new PotDrawScheduler())->schedule($entrants, $options));
            $fromPlan = potDrawRefusal(fn() => (new PotDrawScheduler())->getPlan($entrants, $options));

            expect($fromSchedule->getReason())->toBe($reason)
                ->and($fromSchedule->getContext())->toBe($context)
                ->and($fromPlan->getReason())->toBe($reason)
                ->and($fromPlan->getContext())->toBe($context);
        })->with([
            '20 entrants in 4 pots of 5 with one opponent per pot' => [
                20, 4, 1,
                InvalidConfigurationReason::OddPotWithOddOpponents,
                ['pot_size' => 5, 'opponents_per_pot' => 1, 'participant_slots_inside_one_pot' => 5],
            ],
            '18 entrants in 2 pots of 9 with three opponents per pot' => [
                18, 2, 3,
                InvalidConfigurationReason::OddPotWithOddOpponents,
                ['pot_size' => 9, 'opponents_per_pot' => 3, 'participant_slots_inside_one_pot' => 27],
            ],
            '19 entrants as one pot' => [
                19, 1, 2,
                InvalidConfigurationReason::OddParticipantCount,
                ['participant_count' => 19],
            ],
            '19 entrants in 4 pots' => [
                19, 4, 1,
                InvalidConfigurationReason::OddParticipantCount,
                ['participant_count' => 19],
            ],
            '19 entrants in 19 pots' => [
                19, 19, 1,
                InvalidConfigurationReason::OddParticipantCount,
                ['participant_count' => 19],
            ],
            '20 entrants in 3 pots' => [
                20, 3, 1,
                InvalidConfigurationReason::UnequalPots,
                ['participant_count' => 20, 'pots' => 3, 'remainder' => 2],
            ],
            '20 entrants in 5 pots of 4 with four opponents per pot' => [
                20, 5, 4,
                InvalidConfigurationReason::TooManyOpponentsPerPot,
                ['opponents_per_pot' => 4, 'pot_size' => 4, 'maximum_opponents_per_pot' => 3],
            ],
            '20 entrants in 20 pots of 1' => [
                20, 20, 1,
                InvalidConfigurationReason::TooManyOpponentsPerPot,
                ['opponents_per_pot' => 1, 'pot_size' => 1, 'maximum_opponents_per_pot' => 0],
            ],
        ]);

        it('a configuration that is feasible and not yet supported', function (int $count, int $pots, int $opponentsPerPot, int $potSize): void {
            $entrants = potDrawEntrants($count);
            $options = new PotDrawOptions($pots, $opponentsPerPot, 1);
            $context = [
                'participant_count' => $count,
                'pots' => $pots,
                'pot_size' => $potSize,
                'opponents_per_pot' => $opponentsPerPot,
                'supported_opponents_per_pot_for_odd_pot_size' => [2],
            ];

            $fromSchedule = potDrawRefusal(fn() => (new PotDrawScheduler())->schedule($entrants, $options));
            $fromPlan = potDrawRefusal(fn() => (new PotDrawScheduler())->getPlan($entrants, $options));

            expect($fromSchedule->getReason())->toBe(InvalidConfigurationReason::ConfigurationNotYetSupported)
                ->and($fromSchedule->getContext())->toBe($context)
                ->and($fromPlan->getReason())->toBe(InvalidConfigurationReason::ConfigurationNotYetSupported)
                ->and($fromPlan->getContext())->toBe($context);

            // Feasible: the plan itself describes it
            expect((new PotDrawPlan($entrants, $pots, $opponentsPerPot))->getTotalRounds())->toBe($pots * $opponentsPerPot);
        })->with([
            '36 entrants in 4 pots of 9 with four opponents per pot' => [36, 4, 4, 9],
            '10 entrants in 2 pots of 5 with four opponents per pot' => [10, 2, 4, 5],
            '28 entrants in 4 pots of 7 with six opponents per pot' => [28, 4, 6, 7],
        ]);

        it('an odd field without issuing a bye', function (): void {
            $refusal = potDrawRefusal(fn() => (new PotDrawScheduler())->schedule(potDrawEntrants(19), new PotDrawOptions(1, 2)));

            expect($refusal->getReason())->toBe(InvalidConfigurationReason::OddParticipantCount)
                ->and($refusal->getMessage())->toContain('issues no bye');
        });

        it('fewer than two participants', function (): void {
            expect(potDrawRefusal(fn() => (new PotDrawScheduler())->schedule(potDrawEntrants(1)))->getReason())
                ->toBe(InvalidConfigurationReason::TooFewParticipants)
                ->and(potDrawRefusal(fn() => (new PotDrawScheduler())->schedule([]))->getReason())
                ->toBe(InvalidConfigurationReason::TooFewParticipants);
        });

        it('two participants with one ID', function (): void {
            $entrants = [...potDrawEntrants(3), new Participant('e1', 'Again')];

            expect(potDrawRefusal(fn() => (new PotDrawScheduler())->schedule($entrants, new PotDrawOptions(2, 1)))->getReason())
                ->toBe(InvalidConfigurationReason::DuplicateParticipantIds);
        });

        it('the options of another format', function (): void {
            $refusal = potDrawRefusal(fn() => (new PotDrawScheduler())->schedule(potDrawEntrants(4), new SwissOptions(rounds: 1)));

            expect($refusal->getReason())->toBe(InvalidConfigurationReason::UnsupportedOptions)
                ->and($refusal->getContext())->toBe(['options' => SwissOptions::class]);
        });
    });
});
