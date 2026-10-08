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
    // pairing.
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
        // An odd number of rounds: every entrant leaves one event alone
        '20 in 5 pots, three opponents' => [5, 20, 3],
        '16 in 2 pots, three opponents' => [2, 16, 3],
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
    // share a few rounds, and a round's events are listed pot pair by pot
    // pair. The scheduler walks away from that shape, and it walks long
    // enough that ten times as many steps give the same figures (the class
    // docblock, "How long the walk is"). Each row states, for one
    // configuration and the seeds 0 to `$seeds` - 1, the range every share
    // must be in: the long-run value is the one measured over 2,000 rounds
    // or more at ten times the steps, and the range is that value with a
    // margin for the number of draws here. The seeds are fixed, so the
    // counts are the same on every run. "Built" is what the construction
    // alone gives, and what a walk that is too short stays close to.
    it('walks away from the shape a draw is built with', function (
        int $count,
        int $pots,
        int $opponentsPerPot,
        int $seeds,
        array $wholePotRounds,
        array $roundsWithAnEventInsideAPot,
        array $roundsListedByPotPair
    ): void {
        $entrants = potDrawEntrants($count);
        $scheduler = new PotDrawScheduler();

        $rounds = 0;
        $whole = 0;
        $inside = 0;
        $insideByDraw = [];
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
            $listed += $regularity->roundsListedByPotPair;
        }

        expect($whole / $rounds)->toBeBetween(...$wholePotRounds)
            ->and($inside / $rounds)->toBeBetween(...$roundsWithAnEventInsideAPot)
            ->and($listed / $rounds)->toBeBetween(...$roundsListedByPotPair);

        // Built: the same number of rounds with an event inside a pot in
        // every draw. Walked, the number varies from draw to draw, unless
        // nearly every round holds one: then all the draws of a sample this
        // small can have one in every round
        if ($roundsWithAnEventInsideAPot[0] < 0.9) {
            expect(count($insideByDraw))->toBeGreaterThan(1);
        }
    })->with([
        // Built: 1, 0.25; long run: 0.003, 0.88, 0.15
        '16 in 4 pots of 4, two opponents' => [16, 4, 2, 60, [0.0, 0.03], [0.8, 0.94], [0.08, 0.22]],
        // Built: 1, 0.25; long run: 0, 0.96, 0.002
        '24 in 4 pots of 6, three opponents' => [24, 4, 3, 40, [0.0, 0.01], [0.93, 1.0], [0.0, 0.03]],
        // Built: 0.63, 0.38; long run: 0, 0.99, 0
        '36 in 4 pots of 9, two opponents' => [36, 4, 2, 40, [0.0, 0.01], [0.96, 1.0], [0.0, 0.01]],
        // Built: 1, 1; long run: 0.010, 0.85, 0.06
        '20 in 5 pots of 4, one opponent' => [20, 5, 1, 60, [0.0, 0.04], [0.79, 0.91], [0.02, 0.12]],
        // Built: 0.25, 0.75; long run: 0.11, 0.89, 0.35
        '10 in 2 pots of 5, two opponents' => [10, 2, 2, 100, [0.06, 0.16], [0.84, 0.94], [0.27, 0.43]],
        // The smallest fields need more steps for every round than the
        // rest. Built: 1, 0.5; after 16 steps for every round: 0.37, 0.82;
        // long run: 0.33, 0.835, 0.56
        '8 in 2 pots of 4, three opponents' => [8, 2, 3, 300, [0.3, 0.36], [0.82, 0.85], [0.5, 0.62]],
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
        for ($seed = 0; $seed < 40; ++$seed) {
            $regularity = PotDrawRegularity::of($scheduler->schedule($entrants, new PotDrawOptions(4, 2, $seed)), $entrants, 4);
            $potPairs += $regularity->potPairs;
            $inRotation += $regularity->potPairsInRotation;
        }

        // 4 pots are 6 pot pairs a draw. Built: every one of the 240 passes.
        // Long run: 0.52 of them do, which is the share of the ways to join
        // two pots of 9 twice each in which every cycle has one length
        expect($potPairs)->toBe(240)
            ->and($inRotation / $potPairs)->toBeBetween(0.42, 0.62);
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
        // Long run: 0.32
        '16 in 4 pots of 4, two opponents' => [16, 4, 2, 0.2, 0.44],
    ]);

    // The opponent exchange does not need two pots: four members of one pot
    // exchange opponents as well. One pot of 8 with two opponents each is
    // built from two rounds of the circle method, whose events always form
    // one cycle through all eight (7 is prime), and no round exchange
    // changes a pairing. Of the 3,150 ways to choose two rounds of eight
    // that share no pairing, 2,520 form one cycle of eight and 630 form two
    // cycles of four.
    it('exchanges opponents inside a single pot', function (): void {
        $entrants = potDrawEntrants(8);
        $scheduler = new PotDrawScheduler();

        $oneCycle = 0;
        for ($seed = 0; $seed < 100; ++$seed) {
            $schedule = $scheduler->schedule($entrants, new PotDrawOptions(1, 2, $seed));
            $regularity = PotDrawRegularity::of($schedule, $entrants, 1);

            expect(PotDrawAudit::of($schedule, $entrants, 1)->violations($entrants, 1, 2))->toBe([], "seed {$seed}")
                ->and($regularity->roundPairs)->toBe(1);
            $oneCycle += $regularity->roundPairsInOneCycle;
        }

        // Built: all 100. Drawn evenly: 80 in 100
        expect($oneCycle)->toBeBetween(68, 92);
    });

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

    // Roles are given last, by one rule: every entrant puts its events in
    // twos, against one pot as far as they go, and is first in one event of
    // a two and second in the other. With an odd number of opponents per
    // pot that leaves an entrant first once more or once less than second
    // against every pot, and the seed says which. Nothing in the rule looks
    // at who the entrant is, so over the seeds each is first more often
    // against its own pot about half the time. With an odd number of rounds
    // an entrant is also first once more or once less than second over all
    // its events, and that is each way about half the time as well. (The
    // trails that end are walked from the end of the entrant earlier in the
    // list: without the coin of such a trail, the first entrant of the list
    // is first more often than second on every seed.)
    it('gives no entrant the same role against its own pot on every seed', function (int $count, int $pots, int $opponentsPerPot): void {
        $entrants = potDrawEntrants($count);
        $scheduler = new PotDrawScheduler();
        $potSize = intdiv($count, $pots);

        $firstMoreOften = array_fill_keys(array_map(static fn(Participant $entrant): string => $entrant->getId(), $entrants), 0);
        $firstMoreOftenOverall = $firstMoreOften;
        for ($seed = 0; $seed < 40; ++$seed) {
            $audit = PotDrawAudit::of($scheduler->schedule($entrants, new PotDrawOptions($pots, $opponentsPerPot, $seed)), $entrants, $pots);
            foreach ($entrants as $index => $entrant) {
                $id = $entrant->getId();
                // Within one against every pot, which is more than the
                // format asks for an odd number of opponents per pot
                for ($pot = 1; $pot <= $pots; ++$pot) {
                    expect(abs(($audit->firstRoleByPot[$id][$pot] ?? 0) - ($audit->secondRoleByPot[$id][$pot] ?? 0)))
                        ->toBe(1, "{$id} against pot {$pot}, seed {$seed}");
                }
                // And within one over all its events
                expect(abs(array_sum($audit->firstRoleByPot[$id] ?? []) - array_sum($audit->secondRoleByPot[$id] ?? [])))
                    ->toBe(($pots * $opponentsPerPot) % 2, "{$id}, seed {$seed}");

                $ownPot = intdiv($index, $potSize) + 1;
                $firstMoreOften[$id] += ($audit->firstRoleByPot[$id][$ownPot] ?? 0) > ($audit->secondRoleByPot[$id][$ownPot] ?? 0) ? 1 : 0;
                $firstMoreOftenOverall[$id] += array_sum($audit->firstRoleByPot[$id] ?? []) > array_sum($audit->secondRoleByPot[$id] ?? []) ? 1 : 0;
            }
        }

        // 20 of the 40 seeds on average, for every entrant
        foreach ($firstMoreOften as $id => $seeds) {
            expect($seeds)->toBeBetween(8, 32, $id);
        }

        // Over all its events: never with an even number of rounds, and 20
        // of the 40 seeds on average with an odd number
        foreach ($firstMoreOftenOverall as $id => $seeds) {
            if (($pots * $opponentsPerPot) % 2 === 0) {
                expect($seeds)->toBe(0, $id);
            } else {
                expect($seeds)->toBeBetween(8, 32, $id);
            }
        }
    })->with([
        '8 in 2 pots of 4, one opponent' => [8, 2, 1],
        '16 in 4 pots of 4, one opponent' => [16, 4, 1],
        '16 in 4 pots of 4, three opponents' => [16, 4, 3],
        '24 in 6 pots of 4, one opponent' => [24, 6, 1],
        '20 in 5 pots of 4, one opponent' => [20, 5, 1],
        '12 in 3 pots of 4, three opponents' => [12, 3, 3],
    ]);

    // The coin of a trail decides which of the two entrants of its first
    // event is first, and nothing else does: not where they are in the list.
    // With an odd number of rounds a trail that ends has an entrant at each
    // end who left that event alone, and it is walked from the end of the
    // one earlier in the list. (Without the coin that entrant is first in
    // the event it left alone and the later one is second in its own, so
    // the earlier an entrant is in the list, the more often it is first.)
    it('is as likely to put the earlier of two entrants in the list first as second', function (int $count, int $pots, int $opponentsPerPot, int $seeds): void {
        $entrants = potDrawEntrants($count);
        $index = array_flip(array_map(static fn(Participant $entrant): string => $entrant->getId(), $entrants));
        $scheduler = new PotDrawScheduler();

        $events = 0;
        $earlierFirst = 0;
        for ($seed = 0; $seed < $seeds; ++$seed) {
            foreach ($scheduler->schedule($entrants, new PotDrawOptions($pots, $opponentsPerPot, $seed))->getEvents() as $event) {
                ++$events;
                $earlierFirst += $index[$event->getParticipants()[0]->getId()] < $index[$event->getParticipants()[1]->getId()] ? 1 : 0;
            }
        }

        // Half of the events. Each row has 5,000 events or more, and an
        // event's roles are not independent of the others on its trail, so
        // the range is wider than 5,000 coins would need
        expect($earlierFirst / $events)->toBeBetween(0.47, 0.53);
    })->with([
        '12 in 3 pots of 4, one opponent' => [12, 3, 1, 300],
        '12 in 3 pots of 4, three opponents' => [12, 3, 3, 100],
        '20 in 5 pots of 4, one opponent' => [20, 5, 1, 100],
    ]);

    // With two opponents per pot each member of a pot is first against one
    // member of its pot (call it the next one) and second against another,
    // and between two pots each member of the one is first against one
    // member of the other. Those pairs carry "next" in the one pot onto
    // "next" in the other as often as onto "previous" when the roles of
    // every trail come from a coin of its own. Two pots that are built
    // together, position by position, would otherwise run the same way in
    // every draw. This pins a pattern of the construction that gave roles
    // while it built: no single change to the present rule is known that
    // brings it back, so the test guards against a return to roles given
    // by position and is not seen to fail against this scheduler.
    it('does not line up the roles inside two pots of odd size', function (int $count, int $pots, int $draws): void {
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
        '6 in 2 pots of 3' => [6, 2, 100],
        '12 in 4 pots of 3' => [12, 4, 100],
        '10 in 2 pots of 5' => [10, 2, 200],
    ]);

    // With an odd number of opponents per pot an entrant leans one way or
    // the other against every pot: first once more than second, or second
    // once more. When every entrant takes the events it has left over in a
    // drawn order, two pots are not a pair that every member of a third pot
    // leans opposite ways against, except by chance. (Taken in the order of
    // the pots, or two rounds at a time, they are such a pair in every
    // draw.)
    it('does not pair off the pots a pot meets', function (int $count, int $pots, int $opponentsPerPot, float $most): void {
        $entrants = potDrawEntrants($count);
        $potSize = intdiv($count, $pots);

        $triples = 0;
        $opposite = 0;
        $potsWithNoPair = 0;
        for ($seed = 0; $seed < 20; ++$seed) {
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
                $pairs = 0;
                for ($one = 1; $one <= $pots; ++$one) {
                    for ($other = $one + 1; $other <= $pots; ++$other) {
                        $everyMember = true;
                        foreach ($members as $member) {
                            $everyMember = $everyMember && $lean[$member->getId()][$one] === -$lean[$member->getId()][$other];
                        }
                        ++$triples;
                        $pairs += $everyMember ? 1 : 0;
                    }
                }
                $opposite += $pairs;
                $potsWithNoPair += $pairs === 0 ? 1 : 0;
            }
        }

        expect($opposite / $triples)->toBeLessThan($most)
            // In most draws a pot has no such pair at all
            ->and($potsWithNoPair)->toBeGreaterThan(10 * $pots);
    })->with([
        // By chance: 2 in 1,000. Two rounds at a time: 200 in 1,000
        '60 in 5 pots of 12, one opponent' => [60, 5, 1, 0.02],
        // By chance: 17 in 1,000. Two rounds at a time: 226 in 1,000
        '48 in 6 pots of 8, one opponent' => [48, 6, 1, 0.06],
        // By chance: 18 in 1,000. Two rounds at a time: 206 in 1,000
        '40 in 5 pots of 8, three opponents' => [40, 5, 3, 0.06],
    ]);

    // An entrant takes its events in an order drawn for it. Were they taken
    // in the order of the rounds, every entrant would put the same two
    // rounds together, and in those two rounds every entrant of the field
    // would be first in the one and second in the other
    it('does not pair off the rounds', function (int $count, int $pots, int $opponentsPerPot): void {
        $entrants = potDrawEntrants($count);
        $scheduler = new PotDrawScheduler();

        $pairedRounds = 0;
        for ($seed = 0; $seed < 20; ++$seed) {
            // Round => participant ID => whether it is first
            $first = [];
            foreach ($scheduler->schedule($entrants, new PotDrawOptions($pots, $opponentsPerPot, $seed))->getEvents() as $event) {
                $round = (int) $event->getRound()?->getNumber();
                $first[$round][$event->getParticipants()[0]->getId()] = true;
                $first[$round][$event->getParticipants()[1]->getId()] = false;
            }

            foreach ($first as $round => $roles) {
                foreach ($first as $other => $otherRoles) {
                    if ($other <= $round) {
                        continue;
                    }

                    $opposite = true;
                    foreach ($roles as $id => $isFirst) {
                        $opposite = $opposite && $otherRoles[$id] !== $isFirst;
                    }
                    $pairedRounds += $opposite ? 1 : 0;
                }
            }
        }

        // By chance: once in 2 to the power of half the field, for two rounds
        expect($pairedRounds)->toBe(0);
    })->with([
        '20 in 5 pots of 4, one opponent' => [20, 5, 1],
        '24 in 6 pots of 4, one opponent' => [24, 6, 1],
    ]);

    // Every assignment of roles that keeps the counts can be drawn. Four
    // entrants who all meet have 3 rounds, in 6 orders, and 24 ways to give
    // the six events their roles with every entrant first once or twice;
    // 4 entrants in 2 pots of 2 have 4 ways to place their four events and
    // 2 ways to give them roles
    it('draws every assignment of roles for the smallest fields', function (int $count, int $pots, int $opponentsPerPot, int $seeds, int $draws): void {
        $entrants = potDrawEntrants($count);
        $scheduler = new PotDrawScheduler();

        $seen = [];
        for ($seed = 0; $seed < $seeds; ++$seed) {
            $rounds = [];
            foreach ($scheduler->schedule($entrants, new PotDrawOptions($pots, $opponentsPerPot, $seed))->getEvents() as $event) {
                $rounds[(int) $event->getRound()?->getNumber()][] = $event->getParticipants()[0]->getId() . '-' . $event->getParticipants()[1]->getId();
            }
            foreach ($rounds as $number => $events) {
                sort($events);
                $rounds[$number] = implode(' ', $events);
            }
            $draw = implode(' | ', $rounds);
            $seen[$draw] = ($seen[$draw] ?? 0) + 1;
        }

        // Every one of them, and none much more often than another
        $counts = array_values($seen);
        sort($counts);
        expect($counts)->toHaveCount($draws)
            ->and($counts[count($counts) - 1] ?? 0)->toBeLessThan(4 * ($counts[0] ?? 0));
    })->with([
        '4 entrants as one pot, three opponents' => [4, 1, 3, 6000, 144],
        '4 entrants in 2 pots of 2' => [4, 2, 1, 800, 8],
    ]);

    // The fields a move can do nothing for, as the class docblock lists
    // them ("What the walk cannot change"). Each is drawn and keeps the
    // rules; what is asserted is the shape that stays.
    describe('leaves alone what its moves cannot change:', function (): void {
        // Two rounds of six or fewer entrants form one cycle, so the round
        // exchange trades whole rounds; and an opponent exchange in a round
        // of whole pots gives a round of the same pots. Every round keeps
        // the pots it was built with
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
        // other pot, so the pairs that do not meet match the members of the
        // two pots one to one, whatever is exchanged. Which of the six ways
        // it is, is drawn evenly for every two pots, and one pot pair says
        // nothing about another
        it('that two pots of three leave out one way to match them, each as often', function (): void {
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

    // The keys of the list are not read: an entrant is its place in the list
    it('draws the same schedule whatever the keys of the participant list', function (): void {
        $entrants = potDrawEntrants(12);
        $keyed = [];
        foreach ($entrants as $place => $entrant) {
            // Keys that are neither a list nor in order
            $keyed[$place % 2 === 0 ? "k{$place}" : 100 - $place] = $entrant;
        }
        $options = new PotDrawOptions(pots: 3, opponentsPerPot: 3, seed: 9);

        $schedule = (new PotDrawScheduler())->schedule($keyed, $options);

        expect(PotDrawAudit::of($schedule, $entrants, 3)->violations($entrants, 3, 3))->toBe([])
            ->and(potDrawEventList($schedule))->toBe(potDrawEventList((new PotDrawScheduler())->schedule($entrants, $options)));
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
