<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Decider;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Exceptions\UndecidedSelectionException;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Scheduling\SingleEliminationEngine;
use MissionGaming\Tactician\Stage\BestOfRankSelector;
use MissionGaming\Tactician\Stage\BracketQualifiersSelector;
use MissionGaming\Tactician\Stage\CompositionValidator;
use MissionGaming\Tactician\Stage\PoolComparison;
use MissionGaming\Tactician\Stage\RankRangeSelector;
use MissionGaming\Tactician\Stage\SelectionYieldKind;
use MissionGaming\Tactician\Stage\StageOutcome;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Stage\StageTransition;
use MissionGaming\Tactician\Standings\StandingsCalculator;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

// The top of every pool and the best few of the next rank, placed for a
// bracket: no two entrants of one pool meet in its first round, from two
// pools up, whichever pools the best of the next rank come from; with one
// pool the selection is refused, and composition validation says so before
// any pool is played.
//
// An entrant's ID is its pool's letter and its place in the pool's entrant
// list: A1, A2, ... A pool is played by the function given to
// bracketQualifiersOutcome(), which says the score of every event.

/**
 * A pooled outcome of pools with the given numbers of entrants, each
 * played as a round robin whose events are scored by $score, combined by
 * one calculator, with every entrant list given.
 *
 * @param list<int> $sizes The number of entrants of each pool, pools A, B, ... in order
 * @param Closure(Participant, Participant, string): array{int, int} $score The scores of the two
 *                                                                          entrants of an event of
 *                                                                          the pool labelled
 * @throws InvalidConfigurationException When the calculator refuses the table (not expected here)
 * @throws IncompleteScheduleException When a pool's round robin cannot be generated (not expected here)
 */
function bracketQualifiersOutcome(array $sizes, Closure $score): StageOutcome
{
    $calculator = new StandingsCalculator();
    $scheduler = new RoundRobinScheduler();
    $pools = [];
    $field = [];
    foreach ($sizes as $index => $size) {
        $label = chr(65 + $index);
        $members = [];
        for ($place = 1; $place <= $size; ++$place) {
            $members[] = new Participant("{$label}{$place}", "Pool {$label} entrant {$place}");
        }

        $results = [];
        foreach ($scheduler->schedule($members)->getEvents() as $event) {
            [$first, $second] = $event->getParticipants();
            [$forFirst, $forSecond] = $score($first, $second, $label);
            $winner = match ($forFirst <=> $forSecond) {
                1 => $first,
                -1 => $second,
                0 => null,
            };
            $results[] = new Result($event, $winner, [$first->getId() => $forFirst, $second->getId() => $forSecond]);
        }

        $pools[$label] = new StageOutcome($calculator->calculate($members, $results), $results, entrants: $members);
        $field = [...$field, ...$members];
    }

    return StageOutcome::combining($pools, $calculator, $field);
}

/**
 * The entrant listed earlier in its pool wins, by the margin of the pool:
 * results decide every place of a pool, in list order.
 *
 * @param array<string, int> $margins By pool label
 * @return Closure(Participant, Participant, string): array{int, int}
 */
function bracketQualifiersByMargin(array $margins): Closure
{
    return static function (Participant $first, Participant $second, string $label) use ($margins): array {
        $margin = $margins[$label];

        return (int) substr($first->getId(), 1) < (int) substr($second->getId(), 1) ? [$margin, 0] : [0, $margin];
    };
}

/**
 * @param array<Participant> $participants
 * @return list<string>
 */
function bracketQualifiersIds(array $participants): array
{
    return array_values(array_map(static fn(Participant $participant): string => $participant->getId(), $participants));
}

/**
 * The first round the single elimination engine pairs from the list, as
 * "X v Y" by ID, and the ties in it of two entrants of one pool.
 *
 * @param array<Participant> $entrants
 * @return array{ties: list<string>, poolMates: list<string>}
 * @throws InvalidConfigurationException When the engine refuses the list (not expected here)
 */
function bracketQualifiersFirstRound(array $entrants): array
{
    $ties = [];
    $poolMates = [];
    foreach (new SingleEliminationEngine()->pairNextRound(StageState::start(array_values($entrants)))->getEvents() as $event) {
        [$first, $second] = $event->getParticipants();
        $ties[] = $tie = "{$first->getId()} v {$second->getId()}";
        if ($first->getId()[0] === $second->getId()[0]) {
            $poolMates[] = $tie;
        }
    }

    return ['ties' => $ties, 'poolMates' => $poolMates];
}

describe('The top two of three pools and the two best third-placed, into a knockout', function (): void {
    // Pools of four, each won by the entrant listed earlier, by the pool's
    // margin. A third-placed entrant wins one event and loses two, so the
    // smaller the margin, the better its score difference.
    it('keeps the entrants of one pool apart in the first round, with the best third-placed seeded last', function (): void {
        // Given: A3 (score difference -1) and B3 (-2) are the best third-placed; C3 (-3) is not
        $outcome = bracketQualifiersOutcome([4, 4, 4], bracketQualifiersByMargin(['A' => 1, 'B' => 2, 'C' => 3]));
        $selector = BracketQualifiersSelector::of(RankRangeSelector::topPerGroup(2), BestOfRankSelector::ofRank(3, 2));

        // When
        $qualifiers = $selector->select($outcome);

        // Then: The winners of the pools hold positions 1 to 3 and the
        // runners-up 4 to 6, the third-placed 7 and 8, best first. A1, the
        // first seed, meets B3, the last; B1 meets A3; C1 meets B2, and the
        // last two runners-up meet each other. Of the orders that give no
        // first-round tie of one pool, this is the one nearest to pool
        // order that lets C1 and C2 meet only in the final
        expect(bracketQualifiersIds($qualifiers))->toBe(['A1', 'B1', 'C1', 'A2', 'C2', 'B2', 'A3', 'B3'])
            ->and(bracketQualifiersFirstRound($qualifiers))->toBe([
                'ties' => ['A1 v B3', 'A2 v C2', 'B1 v A3', 'C1 v B2'],
                'poolMates' => [],
            ]);
    });

    // Placed one after the other, the two selections can pair an entrant
    // with one of its own pool: that is what this selector is for
    it('places where the two lists one after the other pair two entrants of one pool', function (): void {
        // Given: The best third-placed are C3 (-1) and A3 (-2)
        $outcome = bracketQualifiersOutcome([4, 4, 4], bracketQualifiersByMargin(['A' => 2, 'B' => 3, 'C' => 1]));
        $topTwo = RankRangeSelector::topPerGroup(2);
        $bestThirds = BestOfRankSelector::ofRank(3, 2);

        // When
        $appended = [...$topTwo->select($outcome), ...$bestThirds->select($outcome)];
        $placed = BracketQualifiersSelector::of($topTwo, $bestThirds)->select($outcome);

        // Then: Appended, A1 (first in the list) meets A3 (last); placed, nobody meets a pool-mate
        expect(bracketQualifiersIds($appended))->toBe(['A1', 'B1', 'C1', 'C2', 'B2', 'A2', 'C3', 'A3'])
            ->and(bracketQualifiersFirstRound($appended)['poolMates'])->toBe(['A1 v A3'])
            ->and(bracketQualifiersFirstRound($placed)['poolMates'])->toBe([])
            ->and(array_slice(bracketQualifiersIds($placed), 6))->toBe(['C3', 'A3']);
    });

    it('is accepted by composition validation, which counts it', function (): void {
        $selector = BracketQualifiersSelector::of(RankRangeSelector::topPerGroup(2), BestOfRankSelector::ofRank(3, 2));

        expect(new CompositionValidator()->validateChain(12, [
            new StageTransition('quarterfinals', 8, $selector, sourcePools: 3),
            new StageTransition('semifinals', 4, null),
        ]))->toBe([]);
    });
});

describe('The order of the pools inside a rank', function (): void {
    // Each rank is settled before the next. The winners are placed against
    // A3 alone: seed 1 can meet seed 5 in the second round and seed 2 only
    // in the final, so A1 is second. That forces the runners-up: seed 4
    // meets A3 in the first round, so it is B2, and A2 is seed 3, who can
    // meet A1 in the second round, as B1 (seed 1) can meet B2. A1, B1, A2,
    // B2, A3 would let only A1 and A3 meet there; the selector does not
    // revisit the winners for that (the usage guide states this example)
    it('settles the winners before the runners-up, and does not revisit them', function (): void {
        // Given: Two pools of three, A3 the best third-placed
        $outcome = bracketQualifiersOutcome([3, 3], bracketQualifiersByMargin(['A' => 1, 'B' => 2]));

        // When
        $placed = BracketQualifiersSelector::of(RankRangeSelector::topPerGroup(2), BestOfRankSelector::ofRank(3, 1))->select($outcome);

        // Then
        expect(bracketQualifiersIds($placed))->toBe(['B1', 'A1', 'A2', 'B2', 'A3'])
            ->and(bracketQualifiersFirstRound($placed))->toBe(['ties' => ['B2 v A3'], 'poolMates' => []]);
    });

    // Placed against C3 alone, the winners can be C, A, B or C, B, A: in
    // both C1 can meet C3 only in the final, and both are as far from pool
    // order. Of the two, the one the assignment returns is taken, and it is
    // pinned here so that a change to it is seen
    it('takes one of two orders that are equally near pool order, the same every time', function (): void {
        // Given: Three pools of three, C3 the best third-placed
        $outcome = bracketQualifiersOutcome([3, 3, 3], bracketQualifiersByMargin(['A' => 3, 'B' => 2, 'C' => 1]));
        $selector = BracketQualifiersSelector::of(RankRangeSelector::topPerGroup(2), BestOfRankSelector::ofRank(3, 1));

        // When
        $placed = bracketQualifiersIds($selector->select($outcome));

        // Then
        expect($placed)->toBe(['C1', 'B1', 'A1', 'A2', 'B2', 'C2', 'C3'])
            ->and(bracketQualifiersFirstRound($selector->select($outcome))['poolMates'])->toBe([]);
    });
});

describe('Qualifiers of one pool', function (): void {
    it('are refused, because two of them meet in the first round however they are placed', function (): void {
        $outcome = bracketQualifiersOutcome([4], bracketQualifiersByMargin(['A' => 1]));
        $selector = BracketQualifiersSelector::of(RankRangeSelector::topPerGroup(2), BestOfRankSelector::ofRank(3, 1));

        try {
            $selector->select($outcome);
        } catch (InvalidConfigurationException $refusal) {
            expect($refusal->getReason())->toBe(InvalidConfigurationReason::PoolMatesInFirstRound)
                ->and($refusal->getReason()?->value)->toBe('pool_mates_in_first_round')
                ->and($refusal->getContext())->toBe(['pools' => 1]);

            return;
        }

        throw new LogicException('The qualifiers of one pool were placed.');
    });

    it('are reported by composition validation before any pool is played', function (): void {
        $selector = BracketQualifiersSelector::of(RankRangeSelector::topPerGroup(2), BestOfRankSelector::ofRank(3, 1));

        expect(new CompositionValidator()->validateChain(4, [new StageTransition('knockout', 3, $selector, sourcePools: 1)]))
            ->toBe(["Stage 'knockout' declares 1 pool for the previous stage; its selection is made from at least 2."]);
    });

    it('are not placed from an outcome without pools', function (): void {
        $members = [new Participant('a', 'A'), new Participant('b', 'B')];
        $result = new Result(new Event($members), $members[0]);
        $outcome = new StageOutcome(new StandingsCalculator()->calculate($members, [$result]), [$result]);

        expect(fn() => BracketQualifiersSelector::of(RankRangeSelector::topPerGroup(1), BestOfRankSelector::ofRank(2, 1))->select($outcome))
            ->toThrow(InvalidConfigurationException::class, 'Qualifiers placed for a bracket require a pooled outcome');
    });
});

describe('Qualifiers placed for a bracket, over generated pool stages', function (): void {
    // For every generated stage: composition validation accepts the
    // transition exactly when there are two pools or more and at least as
    // many as the best of the next rank; where it accepts, the list holds
    // what the two selectors select, in tiers, and the single elimination
    // engine pairs no two entrants of one pool in the first round; where it
    // reports, the selection is refused.
    it('pairs no two entrants of one pool in the first round wherever validation accepts', function (int $seed): void {
        $random = new Randomizer(new Xoshiro256StarStar($seed));

        for ($stage = 0; $stage < 25; ++$stage) {
            $pools = $random->getInt(1, 8);
            $ranks = $random->getInt(1, 3);
            $count = $random->getInt(1, $pools + 1);
            $sizes = [];
            for ($pool = 0; $pool < $pools; ++$pool) {
                $sizes[] = $ranks + 1 + $random->getInt(0, 1);
            }

            // Any score from 0 to 3 for either entrant: draws and level
            // records are common, and the entrant lists order what results
            // leave level
            $outcome = bracketQualifiersOutcome($sizes, static fn(): array => [$random->getInt(0, 3), $random->getInt(0, 3)]);
            $selector = BracketQualifiersSelector::of(
                RankRangeSelector::topPerGroup($ranks)->withTableOrderAccepted(),
                BestOfRankSelector::ofRank($ranks + 1, $count)->comparedBy(PoolComparison::PerEvent)->withTableOrderAccepted()
            );
            $entrants = array_sum($sizes);
            $expected = $ranks * $pools + $count;
            $violations = new CompositionValidator()->validateChain($entrants, [
                new StageTransition('knockout', max(2, $expected), $selector, sourcePools: $pools),
            ]);
            $shape = "seed {$seed}, stage {$stage}: {$pools} pools of " . implode(', ', $sizes) . ", top {$ranks}, best {$count}";

            if ($pools < 2 || $count > $pools) {
                expect($violations)->toHaveCount(1, $shape)
                    ->and(fn() => $selector->select($outcome))->toThrow(InvalidConfigurationException::class);

                continue;
            }

            expect($violations)->toBe([], $shape);

            $placed = bracketQualifiersIds($selector->select($outcome));
            $perGroup = bracketQualifiersIds($selector->getPerGroup()->select($outcome));
            $best = bracketQualifiersIds($selector->getBestOfRank()->select($outcome));

            // Every rank of the per-group selection is a tier of its own,
            // and the best of the next rank follow them, in their order
            expect($placed)->toHaveCount($expected, $shape)
                ->and(array_slice($placed, $ranks * $pools))->toBe($best, $shape);
            foreach (array_chunk(array_slice($placed, 0, $ranks * $pools), $pools) as $tier => $ids) {
                $selected = array_slice($perGroup, $tier * $pools, $pools);
                sort($ids);
                sort($selected);
                expect($ids)->toBe($selected, "{$shape}, rank " . ($tier + 1));
            }

            expect(bracketQualifiersFirstRound($selector->select($outcome))['poolMates'])->toBe([], $shape)
                ->and(bracketQualifiersIds($selector->select($outcome)))->toBe($placed, "{$shape}: the same list again");
        }
    })->with(range(1, 12));

    // Every set of pools the best of the next rank can come from, in every
    // order: the placement depends on which they are, and no set leaves a
    // first-round tie of one pool
    it('keeps the pools apart whichever pools the best of the next rank come from', function (int $pools, int $ranks): void {
        for ($count = 1; $count <= $pools; ++$count) {
            foreach (bracketQualifiersOrderedSubsets(range(0, $pools - 1), $count) as $bestPools) {
                // Margins that make the holders of the next rank in
                // $bestPools the best, in that order: a holder is last of its
                // pool, has lost every event, and the smaller the margin it
                // lost by, the better its score difference
                $margins = [];
                foreach (range(0, $pools - 1) as $pool) {
                    $at = array_search($pool, $bestPools, true);
                    $margins[chr(65 + $pool)] = $at === false ? 10 + $pool : 1 + $at;
                }

                $outcome = bracketQualifiersOutcome(array_fill(0, $pools, $ranks + 1), bracketQualifiersByMargin($margins));
                $placed = BracketQualifiersSelector::of(RankRangeSelector::topPerGroup($ranks), BestOfRankSelector::ofRank($ranks + 1, $count))
                    ->select($outcome);
                $shape = "{$pools} pools, top {$ranks}, best from " . implode(', ', array_map(static fn(int $pool): string => chr(65 + $pool), $bestPools));

                expect(array_slice(bracketQualifiersIds($placed), $ranks * $pools))
                    ->toBe(array_map(static fn(int $pool): string => chr(65 + $pool) . ($ranks + 1), $bestPools), $shape)
                    ->and(bracketQualifiersFirstRound($placed)['poolMates'])->toBe([], $shape);
            }
        }
    })->with([[2, 1], [2, 2], [2, 3], [3, 1], [3, 2], [3, 3], [4, 1], [4, 2], [5, 2]]);
});

describe('The two selections a placement is made of', function (): void {
    it('takes a per-group selection and a rank below its last', function (): void {
        $refusal = static function (Closure $build): ?InvalidConfigurationReason {
            try {
                $build();
            } catch (InvalidConfigurationException $exception) {
                return $exception->getReason();
            }

            return null;
        };

        expect($refusal(static fn() => BracketQualifiersSelector::of(RankRangeSelector::overall(1, 8), BestOfRankSelector::ofRank(3, 2))))
            ->toBe(InvalidConfigurationReason::IncompatibleOptions)
            ->and($refusal(static fn() => BracketQualifiersSelector::of(RankRangeSelector::topPerGroup(3), BestOfRankSelector::ofRank(3, 2))))
            ->toBe(InvalidConfigurationReason::IncompatibleOptions)
            ->and($refusal(static fn() => BracketQualifiersSelector::of(RankRangeSelector::perGroup(2, 3), BestOfRankSelector::ofRank(1, 2))))
            ->toBe(InvalidConfigurationReason::IncompatibleOptions)
            ->and($refusal(static fn() => BracketQualifiersSelector::of(RankRangeSelector::perGroup(2, 3), BestOfRankSelector::ofRank(5, 2))))
            ->toBeNull();
    });

    it('states its yield: the ranks of every pool plus the count, from at least 2 pools and as many as the count', function (): void {
        $twoThirds = BracketQualifiersSelector::of(RankRangeSelector::topPerGroup(2), BestOfRankSelector::ofRank(3, 2))->getYield();
        $fourThirds = BracketQualifiersSelector::of(RankRangeSelector::perGroup(2, 3), BestOfRankSelector::ofRank(4, 4))->getYield();

        expect($twoThirds->getKind())->toBe(SelectionYieldKind::PerPoolPlusFixed)
            ->and([$twoThirds->getCount(), $twoThirds->getAddedCount(), $twoThirds->getMinimumPools()])->toBe([2, 2, 2])
            ->and($twoThirds->countFrom(24, 6))->toBe(14)
            ->and([$fourThirds->getCount(), $fourThirds->getAddedCount(), $fourThirds->getMinimumPools()])->toBe([2, 4, 4])
            ->and(BracketQualifiersSelector::of(RankRangeSelector::topPerGroup(2), BestOfRankSelector::ofRank(3, 2))->getSelectionSize())
            ->toBeNull();
    });

    // Who is selected is decided by the two selectors, each with its own
    // tie policy and decisions; the composition takes their marks over
    it('keeps what each selector refuses and marks what a decision given to it settled', function (): void {
        // Given: Pools A and B score alike, so A3 and B3 are level on every figure
        $outcome = bracketQualifiersOutcome([4, 4, 4], bracketQualifiersByMargin(['A' => 1, 'B' => 1, 'C' => 2]));
        $topTwo = RankRangeSelector::topPerGroup(2);

        // Then: The best third-placed is undecided, and refused
        expect(fn() => BracketQualifiersSelector::of($topTwo, BestOfRankSelector::ofRank(3, 1))->select($outcome))
            ->toThrow(UndecidedSelectionException::class);

        // When: The application decides that B3 goes before A3
        $drawn = new Decider(['B3', 'A3'], 'drawn');
        $selection = BracketQualifiersSelector::of($topTwo, BestOfRankSelector::ofRank(3, 1)->withDecisions([$drawn]))->selection($outcome);

        // Then: B3 is selected, last, and marked with the decision; nobody else is
        expect(bracketQualifiersIds($selection->getParticipants())[6])->toBe('B3')
            ->and($selection->getDecisionsFor($selection->getParticipants()[6]))->toBe([$drawn])
            ->and($selection->getDecisions())->toBe([$drawn])
            ->and(bracketQualifiersFirstRound($selection->getParticipants())['poolMates'])->toBe([]);
    });

    // A tie of a pool's table across the last per-group rank and the rank
    // compared across the pools is read by both selectors. Ordered one way
    // by one and the other way by the other, both would select one entrant
    // and neither the other: the list would hold that entrant twice
    it('refuses where the two selectors read a pool\'s level entries in different orders', function (): void {
        // Given: In pool A, A2 and A3 draw and are level on every figure; the table lists A2 first
        $byMargin = bracketQualifiersByMargin(['A' => 1, 'B' => 2, 'C' => 3]);
        $outcome = bracketQualifiersOutcome([4, 4, 4], static function (Participant $first, Participant $second, string $label) use ($byMargin): array {
            $pair = [$first->getId(), $second->getId()];
            sort($pair);

            return $pair === ['A2', 'A3'] ? [1, 1] : $byMargin($first, $second, $label);
        });
        $aSecond = new Decider(['A3', 'A2'], 'drawn');

        // When: The per-group selector is told that A3 is second, and the
        // best-of-rank selector takes the table order, in which A3 is third
        $selector = BracketQualifiersSelector::of(
            RankRangeSelector::topPerGroup(2)->withDecisions([$aSecond]),
            BestOfRankSelector::ofRank(3, 2)->withTableOrderAccepted()
        );

        // Then: Both would select A3, so the placement is refused, naming A3
        try {
            $selector->select($outcome);
        } catch (InvalidConfigurationException $refusal) {
            expect($refusal->getReason())->toBe(InvalidConfigurationReason::IncompatibleOptions)
                ->and($refusal->getContext())->toBe(['participants' => ['A3']]);

            // And: With the decision given to both, A3 is second and A2 third
            $agreed = BracketQualifiersSelector::of(
                RankRangeSelector::topPerGroup(2)->withDecisions([$aSecond]),
                BestOfRankSelector::ofRank(3, 2)->withDecisions([$aSecond])
            )->select($outcome);
            $ids = bracketQualifiersIds($agreed);
            expect(array_slice($ids, 0, 6))->toContain('A3')
                ->and(array_slice($ids, 6))->toContain('A2')
                ->and(array_unique($ids))->toHaveCount(8);

            return;
        }

        throw new LogicException('Both selections selected A3 and the placement was not refused.');
    });

    it('reads and writes plain data', function (): void {
        $selector = BracketQualifiersSelector::of(
            RankRangeSelector::topPerGroup(2)->withTiesInsideSelectionAccepted(),
            BestOfRankSelector::ofRank(3, 2)->comparedBy(PoolComparison::DiscardLowest)
        );
        $data = [
            'format_version' => 1,
            'per_group' => ['mode' => 'per-group', 'from' => 1, 'to' => 2, 'ties' => 'accept-inside-selection'],
            'best_of_rank' => ['rank' => 3, 'count' => 2, 'comparison' => 'discard-lowest'],
        ];

        expect($selector->toArray())->toBe($data)
            ->and(BracketQualifiersSelector::fromArray($data)->toArray())->toBe($data)
            ->and(json_encode(BracketQualifiersSelector::fromArray(json_decode((string) json_encode($data), true))->toArray()))
            ->toBe(json_encode($data));
    });

    it('refuses plain data it cannot read', function (array $data, InvalidConfigurationReason $reason): void {
        try {
            BracketQualifiersSelector::fromArray($data);
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getReason())->toBe($reason);

            return;
        }

        throw new LogicException('Plain data that cannot be read was read.');
    })->with([
        'a selection missing' => [['per_group' => ['mode' => 'per-group', 'to' => 2]], InvalidConfigurationReason::WrongValueType],
        'a selection not an array' => [['per_group' => 'top-two', 'best_of_rank' => ['rank' => 3, 'count' => 2]], InvalidConfigurationReason::WrongValueType],
        'a key the form does not have' => [
            ['per_group' => ['mode' => 'per-group', 'to' => 2], 'best_of_rank' => ['rank' => 3, 'count' => 2], 'pools' => 3],
            InvalidConfigurationReason::UnknownOptionKey,
        ],
        'a selection that cannot be read' => [
            ['per_group' => ['mode' => 'per-group', 'to' => 2], 'best_of_rank' => ['rank' => 3]],
            InvalidConfigurationReason::WrongValueType,
        ],
        'an overall selection' => [
            ['per_group' => ['mode' => 'overall', 'to' => 6], 'best_of_rank' => ['rank' => 3, 'count' => 2]],
            InvalidConfigurationReason::IncompatibleOptions,
        ],
        'a later format version' => [
            ['format_version' => 99, 'per_group' => ['mode' => 'per-group', 'to' => 2], 'best_of_rank' => ['rank' => 3, 'count' => 2]],
            InvalidConfigurationReason::UnsupportedFormatVersion,
        ],
    ]);
});

/**
 * Every ordered selection of $count of the items, each item at most once.
 *
 * @param list<int> $items
 * @return list<list<int>>
 */
function bracketQualifiersOrderedSubsets(array $items, int $count): array
{
    if ($count === 0) {
        return [[]];
    }

    $subsets = [];
    foreach ($items as $index => $item) {
        $rest = $items;
        unset($rest[$index]);
        foreach (bracketQualifiersOrderedSubsets(array_values($rest), $count - 1) as $subset) {
            $subsets[] = [$item, ...$subset];
        }
    }

    return $subsets;
}
