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
use MissionGaming\Tactician\Tests\Support\PotDrawRegularity;

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

    // As it is built, a draw has a shape the format does not ask for: every
    // round sets whole pots against each other, the events inside the pots
    // share a few rounds, one pot is first against another for a whole
    // round, and a round's events are listed pot pair by pot pair. The
    // scheduler walks away from that shape, and it walks long enough that
    // ten times as many steps give the same figures (the class docblock,
    // "How long the walk is"). Each row states, for one configuration and
    // the seeds 0 to `$seeds` - 1, the range every share must be in: the
    // long-run value is the one measured over thousands of draws at ten
    // times the steps, and the range is that value with a margin for the
    // number of draws here. The seeds are fixed, so the counts are the same
    // on every run. "Built" is what the construction alone gives, and what
    // a walk that is too short stays close to.
    it('walks away from the shape a draw is built with', function (
        int $count,
        int $pots,
        int $opponentsPerPot,
        int $seeds,
        array $wholePotRounds,
        array $roundsWithAnEventInsideAPot,
        array $blocksWithOnePotFirst,
        array $roundsListedByPotPair
    ): void {
        $entrants = potDrawEntrants($count);
        $scheduler = new PotDrawScheduler();

        $rounds = 0;
        $whole = 0;
        $inside = 0;
        $insideByDraw = [];
        $blocks = 0;
        $onePotFirst = 0;
        $listed = 0;
        for ($seed = 0; $seed < $seeds; ++$seed) {
            $regularity = PotDrawRegularity::of(
                $scheduler->schedule($entrants, new PotDrawOptions($pots, $opponentsPerPot, $seed)),
                $entrants,
                $pots
            );
            $rounds += $regularity->rounds;
            $whole += $regularity->wholePotRounds;
            $inside += $regularity->roundsWithAnEventInsideAPot;
            $insideByDraw[$regularity->roundsWithAnEventInsideAPot] = true;
            $blocks += $regularity->blocks;
            $onePotFirst += $regularity->blocksWithOnePotFirst;
            $listed += $regularity->roundsListedByPotPair;
        }

        expect($whole / $rounds)->toBeBetween(...$wholePotRounds)
            ->and($inside / $rounds)->toBeBetween(...$roundsWithAnEventInsideAPot)
            ->and($onePotFirst / $blocks)->toBeBetween(...$blocksWithOnePotFirst)
            ->and($listed / $rounds)->toBeBetween(...$roundsListedByPotPair);

        // Built: the same number of rounds with an event inside a pot in
        // every draw. Walked, the number varies from draw to draw, unless
        // nearly every round holds one: then all the draws of a sample this
        // small can have one in every round
        if ($roundsWithAnEventInsideAPot[0] < 0.9) {
            expect(count($insideByDraw))->toBeGreaterThan(1);
        }
    })->with([
        // Built: 1, 0.25, 1; long run: 0.004, 0.88, 0.6, 0.17
        '16 in 4 pots of 4, two opponents' => [16, 4, 2, 60, [0.0, 0.03], [0.8, 0.94], [0.51, 0.67], [0.09, 0.25]],
        // Built: 1, 0.25, 0.89; long run: 0, 0.96, 0.49, 0.002
        '24 in 4 pots of 6, three opponents' => [24, 4, 3, 40, [0.0, 0.01], [0.93, 1.0], [0.41, 0.57], [0.0, 0.03]],
        // Built: 0.63, 0.38, 1; long run: 0, 0.99, 0.37, 0
        '36 in 4 pots of 9, two opponents' => [36, 4, 2, 40, [0.0, 0.01], [0.96, 1.0], [0.3, 0.44], [0.0, 0.01]],
        // Built: 1, 1, 0.03; long run: 0.014, 0.86, 0.28, 0.08
        '20 in 5 pots of 4, one opponent' => [20, 5, 1, 60, [0.0, 0.04], [0.8, 0.92], [0.21, 0.35], [0.03, 0.13]],
        // The field that takes longest to lose its shape. Built: 0.25,
        // 0.75, 1; long run: 0.12, 0.88, 0.52, 0.34
        '10 in 2 pots of 5, two opponents' => [10, 2, 2, 100, [0.07, 0.17], [0.83, 0.93], [0.44, 0.61], [0.26, 0.43]],
    ]);

    // Built, the pairings between two pots are rotations: with one order
    // of the members of each pot, position i of the one meets positions
    // i + d of the other. The opponent exchange leaves that family. With
    // two opponents per pot the pairings between two pots are cycles, and
    // rotations give cycles of one length only, so a pot pair whose cycles
    // differ in length is certainly not a rotation.
    it('does not keep the pairings between two pots to rotations of one order', function (): void {
        $entrants = potDrawEntrants(36);
        $scheduler = new PotDrawScheduler();

        $potPairs = 0;
        $inRotation = 0;
        $drawsWithoutRotation = 0;
        for ($seed = 0; $seed < 40; ++$seed) {
            $regularity = PotDrawRegularity::of($scheduler->schedule($entrants, new PotDrawOptions(4, 2, $seed)), $entrants, 4);
            $potPairs += $regularity->potPairs;
            $inRotation += $regularity->potPairsInRotation;
            $drawsWithoutRotation += $regularity->potPairsInRotation === 0 ? 1 : 0;
        }

        // 4 pots are 6 pot pairs a draw. Built: every one of the 240 passes.
        // Long run: 0.31 of them do
        expect($potPairs)->toBe(240)
            ->and($inRotation / $potPairs)->toBeBetween(0.21, 0.41)
            ->and($drawsWithoutRotation)->toBeGreaterThan(0);
    });

    // With one opponent per pot a single pot pair is always a rotation,
    // and a pattern would show in three pots if the pots had one member
    // order each for every pot they meet: if a meets b and b meets c, then
    // whether c meets a would be the same for every member of the pot of a.
    // The members of two pots face each other in an order drawn for the
    // two, and the opponent exchange moves the pairings on from there.
    it('does not keep three pots to rotations of one order', function (int $count, int $pots, int $opponentsPerPot, float $least, float $most): void {
        $entrants = potDrawEntrants($count);
        $scheduler = new PotDrawScheduler();

        $potTriples = 0;
        $inRotation = 0;
        for ($seed = 0; $seed < 60; ++$seed) {
            $regularity = PotDrawRegularity::of(
                $scheduler->schedule($entrants, new PotDrawOptions($pots, $opponentsPerPot, $seed)),
                $entrants,
                $pots
            );
            $potTriples += $regularity->potTriples;
            $inRotation += $regularity->potTriplesInRotation;
        }

        // With one order for each pot: every set of three pots passes
        expect($inRotation / $potTriples)->toBeBetween($least, $most);
    })->with([
        // Long run: 0.44
        '20 in 5 pots of 4, one opponent' => [20, 5, 1, 0.34, 0.54],
        // Long run: 0.47
        '16 in 4 pots of 4, two opponents' => [16, 4, 2, 0.35, 0.59],
    ]);

    // With one opponent per pot the draw used to leave the field in two
    // halves that never met across the pots: the members at even positions
    // of every pot, and those at odd positions
    it('does not split a field with one opponent per pot into halves that never meet', function (): void {
        $entrants = potDrawEntrants(20);
        $scheduler = new PotDrawScheduler();

        $connected = 0;
        for ($seed = 0; $seed < 60; ++$seed) {
            $regularity = PotDrawRegularity::of($scheduler->schedule($entrants, new PotDrawOptions(5, 1, $seed)), $entrants, 5);
            $connected += $regularity->betweenPotComponents === 1 ? 1 : 0;
        }

        // Long run: 299 draws in 300 are in one piece
        expect($connected)->toBeGreaterThanOrEqual(57);

        // 6 entrants in 3 pots of 2 allow both: two sets of three who all
        // meet, or one ring of six
        $entrants = potDrawEntrants(6);
        $pieces = [1 => 0, 2 => 0];
        for ($seed = 0; $seed < 40; ++$seed) {
            $regularity = PotDrawRegularity::of($scheduler->schedule($entrants, new PotDrawOptions(3, 1, $seed)), $entrants, 3);
            $pieces[$regularity->betweenPotComponents] = ($pieces[$regularity->betweenPotComponents] ?? 0) + 1;
        }

        // Each is half of the eight ways the three pots can be joined
        expect(array_keys($pieces))->toBe([1, 2])
            ->and($pieces[1])->toBeGreaterThanOrEqual(10)
            ->and($pieces[2])->toBeGreaterThanOrEqual(10);
    });

    // With an odd number of opponents per pot, an entrant is first against
    // its own pot once more or once less than it is second, and the seed
    // says which. The roles of the single layer are taken along cycles
    // through the whole field: walked from the lowest list position every
    // time, a cycle gives that entrant the same role in every draw, and with
    // an even number of pots the first entrant of the list is then never
    // first against the opponent the single layer gives it from its own pot
    it('gives no entrant the same role against its own pot on every seed', function (int $count, int $pots, int $opponentsPerPot): void {
        $entrants = potDrawEntrants($count);
        $scheduler = new PotDrawScheduler();
        $potSize = intdiv($count, $pots);

        $firstMoreOften = array_fill_keys(array_map(static fn(Participant $entrant): string => $entrant->getId(), $entrants), 0);
        for ($seed = 0; $seed < 40; ++$seed) {
            $audit = PotDrawAudit::of($scheduler->schedule($entrants, new PotDrawOptions($pots, $opponentsPerPot, $seed)), $entrants, $pots);
            foreach ($entrants as $index => $entrant) {
                $ownPot = intdiv($index, $potSize) + 1;
                $first = $audit->firstRoleByPot[$entrant->getId()][$ownPot] ?? 0;
                $second = $audit->secondRoleByPot[$entrant->getId()][$ownPot] ?? 0;
                expect(abs($first - $second))->toBe(1, "{$entrant->getId()}, seed {$seed}");
                $firstMoreOften[$entrant->getId()] += $first > $second ? 1 : 0;
            }
        }

        // 20 of the 40 seeds on average, for every entrant
        foreach ($firstMoreOften as $id => $seeds) {
            expect($seeds)->toBeBetween(8, 32, $id);
        }
    })->with([
        '8 in 2 pots of 4, one opponent' => [8, 2, 1],
        '16 in 4 pots of 4, one opponent' => [16, 4, 1],
        '16 in 4 pots of 4, three opponents' => [16, 4, 3],
        '24 in 6 pots of 4, one opponent' => [24, 6, 1],
        '20 in 5 pots of 4, one opponent' => [20, 5, 1],
    ]);

    // In a pot of odd size each member is first against one member of its
    // pot, the next one on a cycle through the pot, and second against
    // another. Between two pots each member of the one is first against one
    // member of the other. When those pairs carry "next" in the one pot onto
    // "next" in the other in every draw, the two cycles always run the same
    // way: that is so for two partner pots unless a coin turns each cycle,
    // and with pots of three no move can change it afterwards
    it('turns the cycle inside a pot of odd size either way', function (int $count, int $pots, int $draws): void {
        $entrants = potDrawEntrants($count);
        $potSize = intdiv($count, $pots);
        $index = array_flip(array_map(static fn(Participant $entrant): string => $entrant->getId(), $entrants));

        $ontoNext = 0;
        $ontoPrevious = 0;
        for ($seed = 0; $seed < $draws; ++$seed) {
            $next = [];
            $firstAgainst = [];
            foreach ((new PotDrawScheduler())->schedule($entrants, new PotDrawOptions($pots, 2, $seed))->getEvents() as $event) {
                $first = $index[$event->getParticipants()[0]->getId()];
                $second = $index[$event->getParticipants()[1]->getId()];
                if (intdiv($first, $potSize) === intdiv($second, $potSize)) {
                    $next[$first] = $second;
                } else {
                    $firstAgainst[intdiv($second, $potSize)][$first] = $second;
                }
            }

            foreach ($firstAgainst as $pairs) {
                foreach ($pairs as $member => $opponent) {
                    $opponentOfNext = $pairs[$next[$member]];
                    $ontoNext += $next[$opponent] === $opponentOfNext ? 1 : 0;
                    $ontoPrevious += $next[$opponentOfNext] === $opponent ? 1 : 0;
                }
            }
        }

        // As likely one way as the other
        expect($ontoNext / ($ontoNext + $ontoPrevious))->toBeBetween(0.4, 0.6);
    })->with([
        // Without the coin: every time, in every draw
        '6 in 2 pots of 3' => [6, 2, 100],
        // Without the coin: two times in three (one pot in three is the partner)
        '12 in 4 pots of 3' => [12, 4, 100],
        // Without the coin, as built: every time. The walk takes most of it away
        '10 in 2 pots of 5' => [10, 2, 200],
    ]);

    // The fields a move can do nothing for, as the class docblock lists
    // them ("What the walk cannot change"). Each is drawn and keeps the
    // rules; what is asserted is the shape that stays.
    describe('leaves alone what its moves cannot change:', function (): void {
        // No move changes how often an entrant is in each role against each
        // pot. With an odd number of opponents per pot the single layer took
        // its roles two rounds at a time, so the pots a pot meets come in
        // pairs: every member of the pot leans one way against the one and
        // the other way against the other
        it('the roles, which pair off the pots a pot meets when the opponents per pot are odd', function (int $count, int $pots, int $opponentsPerPot, int $pairs): void {
            $entrants = potDrawEntrants($count);
            $potSize = intdiv($count, $pots);

            for ($seed = 0; $seed < 30; ++$seed) {
                $audit = PotDrawAudit::of((new PotDrawScheduler())->schedule($entrants, new PotDrawOptions($pots, $opponentsPerPot, $seed)), $entrants, $pots);

                // Entrant => pot => events first less events second against it
                $lean = [];
                foreach ($entrants as $entrant) {
                    for ($pot = 1; $pot <= $pots; ++$pot) {
                        $lean[$entrant->getId()][$pot] = ($audit->firstRoleByPot[$entrant->getId()][$pot] ?? 0)
                            - ($audit->secondRoleByPot[$entrant->getId()][$pot] ?? 0);
                    }
                }

                for ($pot = 0; $pot < $pots; ++$pot) {
                    $members = array_slice($entrants, $pot * $potSize, $potSize);
                    $opposite = 0;
                    for ($one = 1; $one <= $pots; ++$one) {
                        for ($other = $one + 1; $other <= $pots; ++$other) {
                            $everyMember = true;
                            foreach ($members as $member) {
                                $everyMember = $everyMember && $lean[$member->getId()][$one] === -$lean[$member->getId()][$other];
                            }
                            $opposite += $everyMember ? 1 : 0;
                        }
                    }
                    expect($opposite)->toBeGreaterThanOrEqual($pairs, "seed {$seed}");
                }
            }
        })->with([
            // 5 rounds in the single layer: two pairs of them, and one round over
            '20 entrants in 5 pots of 4, one opponent' => [20, 5, 1, 2],
            // 6 rounds: three pairs
            '48 entrants in 6 pots of 8, one opponent' => [48, 6, 1, 3],
            '24 entrants in 4 pots of 6, three opponents' => [24, 4, 3, 2],
        ]);

        // Two rounds of six or fewer entrants form one cycle, so the round
        // exchange trades whole rounds and every round keeps the pots it
        // was built with
        it('the rounds of six entrants or fewer', function (int $count, int $pots, int $opponentsPerPot, int $wholePotRounds): void {
            $entrants = potDrawEntrants($count);

            for ($seed = 0; $seed < 30; ++$seed) {
                $schedule = (new PotDrawScheduler())->schedule($entrants, new PotDrawOptions($pots, $opponentsPerPot, $seed));
                $regularity = PotDrawRegularity::of($schedule, $entrants, $pots);

                expect(PotDrawAudit::of($schedule, $entrants, $pots)->violations($entrants, $pots, $opponentsPerPot))->toBe([], "seed {$seed}")
                    ->and($regularity->wholePotRounds)->toBe($wholePotRounds, "seed {$seed}")
                    ->and($regularity->roundPairsInOneCycle)->toBe($regularity->roundPairs, "seed {$seed}");
            }
        })->with([
            // One pot inside itself and the other two against each other, in every round
            '6 entrants in 3 pots of 2' => [6, 3, 1, 3],
            // One round between the pots and one inside them
            '4 entrants in 2 pots of 2' => [4, 2, 1, 2],
            // Three rounds with one event between the pots, and one round between them only
            '6 entrants in 2 pots of 3' => [6, 2, 2, 1],
        ]);

        it('one round, which has nothing to trade with', function (int $count, int $pots, int $events): void {
            $entrants = potDrawEntrants($count);

            $draws = [];
            for ($seed = 0; $seed < 30; ++$seed) {
                $schedule = (new PotDrawScheduler())->schedule($entrants, new PotDrawOptions($pots, 1, $seed));
                $draws[implode(' ', potDrawEventList($schedule))] = true;

                expect($schedule->count())->toBe($events)
                    ->and(PotDrawAudit::of($schedule, $entrants, $pots)->violations($entrants, $pots, 1))->toBe([], "seed {$seed}");
            }

            // The seed still draws who meets whom
            expect(count($draws))->toBeGreaterThan($count === 2 ? 1 : 10);
        })->with([
            '2 entrants' => [2, 1, 1],
            '4 entrants as one pot' => [4, 1, 2],
            '12 entrants as one pot' => [12, 1, 6],
        ]);

        // Between two pots of three an entrant misses one member of the
        // other pot, and no two entrants can exchange opponents and keep
        // one event in each role against that pot. Nothing needs changing:
        // which three pairs do not meet is drawn evenly for every two pots
        // when the draw is built, and one pot pair says nothing about
        // another
        it('the pairings of pots of three, which are drawn evenly as built', function (): void {
            $entrants = potDrawEntrants(12);

            $leftOut = [];
            $potTriples = 0;
            $inRotation = 0;
            for ($seed = 0; $seed < 120; ++$seed) {
                $schedule = (new PotDrawScheduler())->schedule($entrants, new PotDrawOptions(4, 2, $seed));
                $audit = PotDrawAudit::of($schedule, $entrants, 4);
                $regularity = PotDrawRegularity::of($schedule, $entrants, 4);
                $potTriples += $regularity->potTriples;
                $inRotation += $regularity->potTriplesInRotation;

                expect($audit->violations($entrants, 4, 2))->toBe([], "seed {$seed}");

                // The pairs of pot 1 (e1..e3) and pot 2 (e4..e6) that do not meet
                $pairs = [];
                foreach (['e1', 'e2', 'e3'] as $a) {
                    foreach (['e4', 'e5', 'e6'] as $b) {
                        if (!isset($audit->meetings["{$a}|{$b}"])) {
                            $pairs[] = "{$a}-{$b}";
                        }
                    }
                }
                expect($pairs)->toHaveCount(3, "seed {$seed}");
                $leftOut[implode(' ', $pairs)] = ($leftOut[implode(' ', $pairs)] ?? 0) + 1;
            }

            // Three pairs that leave out one member of each pot each: the six
            // ways to match three with three, 20 draws each on average
            $rarest = array_values($leftOut);
            sort($rarest);
            expect($leftOut)->toHaveCount(6)
                ->and($rarest[0] ?? 0)->toBeGreaterThanOrEqual(10)
                // With one member order for each pot and no order drawn for
                // each two pots, every set of three pots would pass the
                // rotation test. Long run: 0.49
                ->and($inRotation / $potTriples)->toBeBetween(0.38, 0.6);
        });

        // One pot in which everyone meets everyone is a single round robin:
        // no pairing can change, and when the pot size less one is prime
        // every two rounds of the circle method form one cycle, so no round
        // can change either
        it('a single round robin of a pot whose size less one is prime', function (int $count): void {
            $entrants = potDrawEntrants($count);

            for ($seed = 0; $seed < 10; ++$seed) {
                $schedule = (new PotDrawScheduler())->schedule($entrants, new PotDrawOptions(1, $count - 1, $seed));
                $regularity = PotDrawRegularity::of($schedule, $entrants, 1);

                expect(PotDrawAudit::of($schedule, $entrants, 1)->violations($entrants, 1, $count - 1))->toBe([], "seed {$seed}")
                    ->and($regularity->roundPairsInOneCycle)->toBe($regularity->roundPairs, "seed {$seed}");
            }
        })->with([8, 12, 14, 18]);

        // 9 and 15 are not prime: rounds of 10 and of 16 do trade events
        it('and not a single round robin of another size', function (int $count): void {
            $entrants = potDrawEntrants($count);

            $roundPairs = 0;
            $inOneCycle = 0;
            for ($seed = 0; $seed < 10; ++$seed) {
                $regularity = PotDrawRegularity::of((new PotDrawScheduler())->schedule($entrants, new PotDrawOptions(1, $count - 1, $seed)), $entrants, 1);
                $roundPairs += $regularity->roundPairs;
                $inOneCycle += $regularity->roundPairsInOneCycle;
            }

            expect($inOneCycle)->toBeLessThan($roundPairs)
                ->and($inOneCycle)->toBeGreaterThan(0);
        })->with([10, 16]);
    });

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
