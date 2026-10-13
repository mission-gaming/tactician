<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Exceptions\UndecidedSelectionException;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Scheduling\SingleEliminationEngine;
use MissionGaming\Tactician\Stage\RankRangeSelector;
use MissionGaming\Tactician\Stage\StageOutcome;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Standings\StandingEntry;
use MissionGaming\Tactician\Standings\Standings;
use MissionGaming\Tactician\Standings\StandingsCalculator;

// A rank selection acts on the order of a table. Where results do not
// decide that order the selector refuses, unless it was told to go on.

/**
 * @param array<Participant> $participants
 * @return list<string>
 */
function selectionIds(array $participants): array
{
    return array_values(array_map(fn(Participant $participant): string => $participant->getId(), $participants));
}

/**
 * The outcome of a pool that played a single round robin.
 *
 * @param list<Participant> $pool The entrants in list order
 * @param Closure(Event): ?Participant $winner Who wins an event; null for a draw
 * @param bool $played False for a pool in which no event has a result
 * @throws InvalidConfigurationException When the pool cannot be scheduled
 * @throws IncompleteScheduleException When the pool cannot be scheduled
 */
function selectionPool(array $pool, Closure $winner, bool $played = true): StageOutcome
{
    $results = $played
        ? array_map(fn(Event $event): Result => new Result($event, $winner($event)), (new RoundRobinScheduler())->schedule($pool)->getEvents())
        : [];

    return new StageOutcome((new StandingsCalculator())->calculate($pool, $results), $results, [], null, [], $pool);
}

/**
 * The exception a selection raises.
 *
 * @param Closure(): mixed $selection
 */
function selectionRefusal(Closure $selection): UndecidedSelectionException
{
    try {
        $selection();
    } catch (UndecidedSelectionException $exception) {
        return $exception;
    }

    throw new LogicException('The selection was not refused.');
}

describe('a rank selection that results do not decide', function (): void {
    beforeEach(function (): void {
        // Listed Zed, Yan, Bob, Amy: the order of the labels, of the ids
        // and of the seed attributes is the reverse of the list
        $this->zed = new Participant('d-zed', 'Zed', 4);
        $this->yan = new Participant('c-yan', 'Yan', 3);
        $this->bob = new Participant('b-bob', 'Bob', 2);
        $this->amy = new Participant('a-amy', 'Amy', 1);
        $this->pool = [$this->zed, $this->yan, $this->bob, $this->amy];
        $this->allDrawn = fn(Event $event): ?Participant => null;
        // The entrant listed earlier wins: results decide every place
        $position = array_flip(selectionIds($this->pool));
        $this->earlierListedWins = function (Event $event) use ($position): Participant {
            [$first, $second] = $event->getParticipants();

            return $position[$first->getId()] < $position[$second->getId()] ? $first : $second;
        };
    });

    it('is refused for a pool in which every event is drawn, naming everyone and places 1 to 4', function (): void {
        $outcome = StageOutcome::combining(['A' => selectionPool($this->pool, $this->allDrawn)]);

        $refusal = selectionRefusal(fn() => RankRangeSelector::topPerGroup(2)->select($outcome));

        expect($refusal->getParticipantIds())->toBe(['d-zed', 'c-yan', 'b-bob', 'a-amy'])
            ->and($refusal->getFirstPlace())->toBe(1)
            ->and($refusal->getLastPlace())->toBe(4)
            ->and($refusal->getPool())->toBe('A')
            ->and([$refusal->getFrom(), $refusal->getTo()])->toBe([1, 2])
            ->and($refusal->getReason())->toBe(InvalidConfigurationReason::TieAcrossSelection)
            ->and($refusal)->toBeInstanceOf(InvalidConfigurationException::class)
            ->and($refusal->getMessage())->toContain('places 1 to 4 of pool A (d-zed, c-yan, b-bob, a-amy)')
            ->and($refusal->getContext())->toBe([
                'participants' => ['d-zed', 'c-yan', 'b-bob', 'a-amy'],
                'first_place' => 1,
                'last_place' => 4,
                'pool' => 'A',
                'from' => 1,
                'to' => 2,
            ]);
    });

    it('is refused for a pool with no results', function (): void {
        $outcome = StageOutcome::combining([
            'A' => selectionPool($this->pool, $this->earlierListedWins),
            'B' => selectionPool([new Participant('e', 'Eve'), new Participant('f', 'Fay')], $this->allDrawn, played: false),
        ]);

        $refusal = selectionRefusal(fn() => RankRangeSelector::topPerGroup(1)->select($outcome));

        expect($refusal->getReason())->toBe(InvalidConfigurationReason::UnplayedStage)
            ->and($refusal->getPool())->toBe('B')
            ->and($refusal->getParticipantIds())->toBe(['e', 'f'])
            ->and([$refusal->getFirstPlace(), $refusal->getLastPlace()])->toBe([1, 2])
            ->and($refusal->getMessage())->toContain('No result is recorded in pool B');
    });

    it('is refused for an unplayed table in overall mode too, with no pool to name', function (): void {
        $refusal = selectionRefusal(fn() => RankRangeSelector::overall(1, 2)->select(selectionPool($this->pool, $this->allDrawn, played: false)));

        expect($refusal->getReason())->toBe(InvalidConfigurationReason::UnplayedStage)
            ->and($refusal->getPool())->toBeNull()
            ->and($refusal->getMessage())->toContain('No result is recorded in the standings');
    });

    it('is not refused for a tie that does not touch the selected places', function (): void {
        // Zed beats everyone; the other three draw with each other and
        // are level in places 2 to 4
        $pool = selectionPool($this->pool, fn(Event $event): ?Participant => $event->hasParticipant($this->zed) ? $this->zed : null);
        $outcome = StageOutcome::combining(['A' => $pool]);

        expect($pool->getStandings()->getTiedSets())->toHaveCount(1)
            ->and(selectionIds(RankRangeSelector::topPerGroup(1)->select($outcome)))->toBe(['d-zed'])
            ->and(selectionIds(RankRangeSelector::overall(1, 1)->select($pool)))->toBe(['d-zed']);

        // The same table cut inside the tie is refused, at either end of
        // the selection
        expect(selectionRefusal(fn() => RankRangeSelector::topPerGroup(2)->select($outcome))->getReason())
            ->toBe(InvalidConfigurationReason::TieAcrossSelection)
            ->and(selectionRefusal(fn() => RankRangeSelector::perGroup(3, 4)->select($outcome))->getReason())
            ->toBe(InvalidConfigurationReason::TieAcrossSelection)
            ->and(selectionRefusal(fn() => RankRangeSelector::overall(4, 4)->select($pool))->getParticipantIds())
            ->toBe(['c-yan', 'b-bob', 'a-amy']);
    });

    it('is refused for a tie inside the selection, where the order seeds the next stage, until that is accepted', function (): void {
        $pool = selectionPool($this->pool, fn(Event $event): ?Participant => $event->hasParticipant($this->zed) ? $this->zed : null);
        $outcome = StageOutcome::combining(['A' => $pool]);

        $refusal = selectionRefusal(fn() => RankRangeSelector::perGroup(2, 4)->select($outcome));
        expect($refusal->getReason())->toBe(InvalidConfigurationReason::TieWithinSelection)
            ->and([$refusal->getFirstPlace(), $refusal->getLastPlace()])->toBe([2, 4])
            ->and($refusal->getMessage())->toContain('the order they are selected in is not decided');

        // Accepted: everyone selected is selected by results, and the
        // level three are in list order
        expect(selectionIds(RankRangeSelector::perGroup(2, 4)->withTiesInsideSelectionAccepted()->select($outcome)))
            ->toBe(['c-yan', 'b-bob', 'a-amy'])
            ->and(selectionIds(RankRangeSelector::topPerGroup(4)->withTiesInsideSelectionAccepted()->select($outcome)))
            ->toBe(['d-zed', 'c-yan', 'b-bob', 'a-amy']);

        // Accepting ties inside the selection does not accept a cut
        // through one, nor a pool that has not played
        expect(selectionRefusal(fn() => RankRangeSelector::topPerGroup(2)->withTiesInsideSelectionAccepted()->select($outcome))->getReason())
            ->toBe(InvalidConfigurationReason::TieAcrossSelection);
        $unplayed = StageOutcome::combining(['A' => selectionPool($this->pool, $this->allDrawn, played: false)]);
        expect(selectionRefusal(fn() => RankRangeSelector::topPerGroup(4)->withTiesInsideSelectionAccepted()->select($unplayed))->getReason())
            ->toBe(InvalidConfigurationReason::UnplayedStage);
    });

    it('returns the table order, level entrants in list order, once that is accepted', function (): void {
        $outcome = StageOutcome::combining(['A' => selectionPool($this->pool, $this->allDrawn)]);

        expect(selectionIds(RankRangeSelector::topPerGroup(2)->withTableOrderAccepted()->select($outcome)))
            ->toBe(['d-zed', 'c-yan'])
            ->and(selectionIds(RankRangeSelector::overall(1, 3)->withTableOrderAccepted()->select(selectionPool($this->pool, $this->allDrawn))))
            ->toBe(['d-zed', 'c-yan', 'b-bob']);
    });

    it('takes the first of each unplayed pool by position once that is accepted', function (): void {
        $cat = new Participant('cat', 'Cat');
        $outcome = StageOutcome::combining([
            'A' => selectionPool([$this->zed, $cat], $this->allDrawn, played: false),
            'B' => selectionPool([$this->amy, $this->bob], $this->allDrawn, played: false),
        ]);

        expect(selectionIds(RankRangeSelector::topPerGroup(1)->withTableOrderAccepted()->select($outcome)))
            ->toBe(['d-zed', 'a-amy']);
    });

    it('leaves a table that was built without an entrant list in the order it was given', function (): void {
        // No entrant list: the table is the calculator's own, in its
        // fallback order (the seed attribute, then the id)
        $results = array_map(fn(Event $event): Result => new Result($event), (new RoundRobinScheduler())->schedule($this->pool)->getEvents());
        $outcome = new StageOutcome((new StandingsCalculator())->calculate($this->pool, $results), $results);

        expect($outcome->getEntrants())->toBe([])
            ->and(selectionIds(RankRangeSelector::overall(1, 2)->withTableOrderAccepted()->select($outcome)))->toBe(['a-amy', 'b-bob']);
    });

    it('selects without a word from a table that results decide', function (): void {
        // The earlier-listed entrant wins every event: places 1 to 4 in list order
        $pool = selectionPool($this->pool, $this->earlierListedWins);
        $outcome = StageOutcome::combining(['A' => $pool]);

        expect($pool->getStandings()->getTiedSets())->toBe([])
            ->and(selectionIds(RankRangeSelector::topPerGroup(2)->select($outcome)))->toBe(['d-zed', 'c-yan'])
            ->and(selectionIds(RankRangeSelector::perGroup(3, 4)->select($outcome)))->toBe(['b-bob', 'a-amy'])
            ->and(selectionIds(RankRangeSelector::overall(2, 3)->select($pool)))->toBe(['c-yan', 'b-bob']);
    });

    it('reads the places a bracket decided and refuses the ones it left shared', function (): void {
        $field = array_map(fn(int $number): Participant => new Participant("p{$number}", "P{$number}"), range(1, 8));
        $engine = new SingleEliminationEngine();
        $state = StageState::start($field);
        while (!$engine->isComplete($state)) {
            $pairing = $engine->pairNextRound($state);
            $state = $state->withRoundPlayed($pairing, array_map(
                fn(Event $event): Result => new Result($event, $event->getParticipants()[0]),
                $pairing->getEvents()
            ));
        }
        $outcome = $engine->getOutcome($state);
        assert($outcome !== null);

        expect(selectionIds(RankRangeSelector::overall(1, 2)->select($outcome)))->toBe(['p1', 'p2'])
            ->and(selectionIds(RankRangeSelector::overall(1, 4)->withTiesInsideSelectionAccepted()->select($outcome)))
            ->toBe(['p1', 'p2', 'p3', 'p4']);

        // The two who went out in the semifinals share places 3 and 4
        $refusal = selectionRefusal(fn() => RankRangeSelector::overall(1, 3)->select($outcome));
        expect($refusal->getParticipantIds())->toBe(['p3', 'p4'])
            ->and([$refusal->getFirstPlace(), $refusal->getLastPlace()])->toBe([3, 4])
            ->and($refusal->getPool())->toBeNull();
    });

    it('reports a rank the table does not have before it reads the table', function (): void {
        $outcome = StageOutcome::combining(['A' => selectionPool($this->pool, $this->allDrawn, played: false)]);

        try {
            RankRangeSelector::topPerGroup(5)->select($outcome);
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getReason())->toBe(InvalidConfigurationReason::RankUnavailable)
                ->and($exception->getContext())->toBe(['pool' => 'A', 'rank' => 5, 'pool_size' => 4]);

            return;
        }

        throw new LogicException('A rank the pool does not have was selected.');
    });

    it('keeps the choice through plain data, and writes it only when it was made', function (): void {
        $refusing = RankRangeSelector::topPerGroup(2);
        $inside = $refusing->withTiesInsideSelectionAccepted();
        $asItStands = RankRangeSelector::overall(1, 4)->withTableOrderAccepted();

        expect($refusing->toArray())->toBe(['mode' => 'per-group', 'from' => 1, 'to' => 2])
            ->and($inside->toArray())->toBe(['mode' => 'per-group', 'from' => 1, 'to' => 2, 'ties' => 'accept-inside-selection'])
            ->and($asItStands->toArray())->toBe(['mode' => 'overall', 'from' => 1, 'to' => 4, 'ties' => 'accept-table-order'])
            ->and(RankRangeSelector::fromArray($inside->toArray())->toArray())->toBe($inside->toArray())
            ->and(RankRangeSelector::fromArray($asItStands->toArray())->toArray())->toBe($asItStands->toArray())
            ->and(RankRangeSelector::fromArray(['mode' => 'overall', 'to' => 2, 'ties' => 'refuse'])->toArray())
            ->toBe(['mode' => 'overall', 'from' => 1, 'to' => 2])
            // The selector it was called on is not changed
            ->and($refusing->toArray())->toBe(['mode' => 'per-group', 'from' => 1, 'to' => 2]);

        // Data stored before the choice existed refuses, as a new selector does
        $outcome = StageOutcome::combining(['A' => selectionPool($this->pool, $this->allDrawn)]);
        expect(fn() => RankRangeSelector::fromArray(['mode' => 'per-group', 'from' => 1, 'to' => 2])->select($outcome))
            ->toThrow(UndecidedSelectionException::class);

        try {
            RankRangeSelector::fromArray(['mode' => 'overall', 'to' => 2, 'ties' => 'whatever']);
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getReason())->toBe(InvalidConfigurationReason::UnknownIdentifier);

            return;
        }

        throw new LogicException('An unknown tie policy was accepted.');
    });
});

/**
 * A pooled outcome of the given shape in which results decide every place:
 * pool X has entrants X1, X2, ... in that order of the table.
 */
function crossPoolOutcome(int $pools, int $ranks): StageOutcome
{
    $outcomes = [];
    for ($pool = 0; $pool < $pools; ++$pool) {
        $label = chr(65 + $pool);
        $entries = [];
        $members = [];
        for ($rank = 1; $rank <= $ranks; ++$rank) {
            $members[] = $member = new Participant("{$label}{$rank}", "Pool {$label} rank {$rank}");
            $entries[] = new StandingEntry($member, $ranks, $ranks - $rank, 0, $rank, 3.0 * ($ranks - $rank + 1));
        }
        $outcomes[$label] = new StageOutcome(
            new Standings($entries),
            [new Result(new Event([$members[0], $members[count($members) - 1]]), $members[0])]
        );
    }

    return StageOutcome::combining($outcomes);
}

/**
 * For each pool, the first round of a single elimination bracket in which
 * two of its entrants meet when they win every tie against anyone else;
 * null when they never meet. The bracket is played by the engine.
 *
 * @param list<Participant> $entrants
 * @return array{meetings: array<string, int|null>, rounds: int}
 * @throws InvalidConfigurationException When the engine refuses the state
 */
function crossPoolMeetings(array $entrants): array
{
    $engine = new SingleEliminationEngine();
    $pools = array_values(array_unique(array_map(fn(Participant $participant): string => $participant->getId()[0], $entrants)));
    $meetings = [];
    $rounds = 0;

    foreach ($pools as $pool) {
        $meetings[$pool] = null;
        $state = StageState::start($entrants);
        while (!$engine->isComplete($state)) {
            $pairing = $engine->pairNextRound($state);
            $rounds = max($rounds, $pairing->getRoundNumber());
            $results = [];
            foreach ($pairing->getEvents() as $event) {
                [$first, $second] = $event->getParticipants();
                $firstIsOurs = $first->getId()[0] === $pool;
                $secondIsOurs = $second->getId()[0] === $pool;
                if ($firstIsOurs && $secondIsOurs) {
                    $meetings[$pool] ??= $pairing->getRoundNumber();
                }
                $results[] = new Result($event, $secondIsOurs && !$firstIsOurs ? $second : $first);
            }
            $state = $state->withRoundPlayed($pairing, $results);
        }
    }

    return ['meetings' => $meetings, 'rounds' => $rounds];
}

describe('a per-group selection folded into a bracket', function (): void {
    it('lists the first rank in pool order and every rank as a block', function (int $pools, int $ranks): void {
        $selected = selectionIds(RankRangeSelector::topPerGroup($ranks)->select(crossPoolOutcome($pools, $ranks)));

        expect(array_slice($selected, 0, $pools))->toBe(array_map(fn(int $pool): string => chr(65 + $pool) . '1', range(0, $pools - 1)));

        foreach (array_chunk($selected, max(1, $pools)) as $block => $ids) {
            $expected = array_map(fn(int $pool): string => chr(65 + $pool) . ($block + 1), range(0, $pools - 1));
            sort($ids, SORT_STRING);
            expect($ids)->toBe($expected);
        }
    })->with([[1, 3], [2, 2], [3, 2], [4, 2], [5, 3], [8, 2], [8, 4], [26, 2]]);

    it('keeps two pools in the order A1, B1, A2, B2', function (): void {
        expect(selectionIds(RankRangeSelector::topPerGroup(2)->select(crossPoolOutcome(2, 2))))->toBe(['A1', 'B1', 'A2', 'B2']);
    });

    // Pool order is what 0.2.x listed every rank in. It stays the order
    // wherever it keeps the pools apart as well as any other: for one pool
    // and for two, whatever the number of ranks, and for a single rank of
    // any number of pools.
    it('keeps one pool and two pools in pool order for every number of ranks', function (int $pools): void {
        for ($ranks = 1; $ranks <= 9; ++$ranks) {
            $inPoolOrder = [];
            for ($rank = 1; $rank <= $ranks; ++$rank) {
                for ($pool = 0; $pool < $pools; ++$pool) {
                    $inPoolOrder[] = chr(65 + $pool) . $rank;
                }
            }

            expect(selectionIds(RankRangeSelector::topPerGroup($ranks)->select(crossPoolOutcome($pools, $ranks))))
                ->toBe($inPoolOrder, "{$pools} pool(s), {$ranks} rank(s)");
        }
    })->with([[1], [2]]);

    it('keeps a single rank of any number of pools in pool order', function (): void {
        for ($pools = 1; $pools <= 26; ++$pools) {
            foreach ([1, 2, 3] as $rank) {
                expect(selectionIds(RankRangeSelector::perGroup($rank, $rank)->select(crossPoolOutcome($pools, 3))))
                    ->toBe(array_map(fn(int $pool): string => chr(65 + $pool) . $rank, range(0, $pools - 1)));
            }
        }
    });

    // The top two of every pool: for any number of pools, a pool's first
    // and second are in opposite halves of the bracket, so they can only
    // meet in its final.
    it('lets the first and second of a pool meet only in the final, for any number of pools', function (): void {
        for ($pools = 2; $pools <= 26; ++$pools) {
            $entrants = RankRangeSelector::topPerGroup(2)->select(crossPoolOutcome($pools, 2));
            $played = crossPoolMeetings(array_values($entrants));

            foreach ($played['meetings'] as $pool => $round) {
                expect($round)->toBe($played['rounds'], "{$pools} pools, pool {$pool}");
            }
        }
    });

    // More than two per pool cannot all be kept to the final. What holds:
    // no two entrants of a pool are paired in the first round.
    it('pairs no two entrants of one pool in the first round', function (int $pools, int $ranks): void {
        $entrants = RankRangeSelector::topPerGroup($ranks)->select(crossPoolOutcome($pools, $ranks));

        $firstRound = (new SingleEliminationEngine())->pairNextRound(StageState::start(array_values($entrants)));
        foreach ($firstRound->getEvents() as $event) {
            [$first, $second] = $event->getParticipants();
            expect($first->getId()[0])->not->toBe($second->getId()[0], "{$pools} pools, {$ranks} ranks: {$first->getId()} v {$second->getId()}");
        }
    })->with([
        [2, 2], [3, 2], [4, 2], [5, 2], [6, 2], [7, 2], [8, 2], [12, 2], [16, 2], [26, 2],
        [2, 3], [3, 3], [4, 3], [5, 3], [8, 3], [2, 4], [3, 4], [4, 4], [8, 4], [3, 5],
    ]);

    it('orders a slice that does not start at rank 1 the same way', function (): void {
        // Ranks 2 and 3 of three pools, into a bracket of their own
        $selected = selectionIds(RankRangeSelector::perGroup(2, 3)->select(crossPoolOutcome(3, 3)));
        $topTwo = selectionIds(RankRangeSelector::topPerGroup(2)->select(crossPoolOutcome(3, 3)));

        expect(array_map(fn(string $id): string => $id[0] . ((int) $id[1] - 1), $selected))->toBe($topTwo);
    });
});
