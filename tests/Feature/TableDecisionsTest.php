<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Decider;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Exceptions\IncompleteScheduleException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationReason;
use MissionGaming\Tactician\Exceptions\InvalidInputException;
use MissionGaming\Tactician\Exceptions\RejectedDecisionException;
use MissionGaming\Tactician\Exceptions\RejectedDecisionReason;
use MissionGaming\Tactician\Exceptions\TacticianException;
use MissionGaming\Tactician\Exceptions\UndecidedSelectionException;
use MissionGaming\Tactician\Scheduling\RoundRobinScheduler;
use MissionGaming\Tactician\Scheduling\SingleEliminationEngine;
use MissionGaming\Tactician\Stage\BestOfRankSelector;
use MissionGaming\Tactician\Stage\PoolComparison;
use MissionGaming\Tactician\Stage\RankRangeSelector;
use MissionGaming\Tactician\Stage\StageOutcome;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Standings\Adjustment;
use MissionGaming\Tactician\Standings\StandingEntry;
use MissionGaming\Tactician\Standings\Standings;
use MissionGaming\Tactician\Standings\StandingsCalculator;
use MissionGaming\Tactician\Standings\TiebreakerInterface;
use MissionGaming\Tactician\Standings\TiedSet;
use Random\Engine\Mt19937;
use Random\Randomizer;

/*
 * A tie that results leave level is settled by a decision the application
 * supplies: an order of the level participants, best first (DTO\Decider).
 *
 * The property tests below do not read their expectation from the table. A
 * generated table is plain rows first (an id, a tier and the figures a table
 * is ordered by), and the oracle works on those rows alone: which rows are
 * level, what a list of decisions does to their order, and why a decision is
 * rejected. The Standings object is built from the same rows and held to
 * what the oracle says.
 */

/**
 * @param array<Participant> $participants
 *
 * @return list<string>
 */
function tableDecisionIds(array $participants): array
{
    return array_values(array_map(static fn(Participant $participant): string => $participant->getId(), $participants));
}

/**
 * @return list<string>
 */
function tableDecisionOrder(Standings $standings): array
{
    return array_values(array_map(
        static fn(StandingEntry $entry): string => $entry->getParticipant()->getId(),
        $standings->getEntries()
    ));
}

/**
 * A generated table as plain rows in table order, and the Standings built
 * from them. Few distinct figures, so that level rows are common; one table
 * in three has tiers, and rows of different tiers can carry equal figures.
 *
 * @return array{rows: list<array{id: string, tier: int, figures: list<float>}>, standings: Standings, participants: array<string, Participant>}
 */
function tableDecisionTable(Randomizer $rng, string $prefix = 'p'): array
{
    $count = $rng->getInt(2, 9);
    $tiered = $rng->getInt(0, 2) === 0;
    $values = array_slice([3.0, 1.0, 0.0, 4.5], 0, $rng->getInt(1, 4));

    $drawn = [];
    $tier = 0;
    foreach ($rng->shuffleArray(range(1, $count)) as $index => $number) {
        if ($tiered && $index > 0 && $rng->getInt(0, 2) === 0) {
            ++$tier;
        }

        $scoreFor = $rng->getInt(0, 4) === 0 ? 2.0 : 0.0;
        $drawn[] = [
            'id' => "{$prefix}{$number}",
            'tier' => $tier,
            // Ranking value, the one tiebreaker, score difference, scores-for
            'figures' => [$values[$rng->getInt(0, count($values) - 1)], $rng->getInt(0, 4) === 0 ? 1.0 : 0.0, $scoreFor, $scoreFor],
            'drawn' => $index,
        ];
    }

    // The order of a table: tier, then the figures, higher first; what is
    // level after that keeps the order it was drawn in
    usort($drawn, static fn(array $a, array $b): int => [$a['tier'], $b['figures'], $a['drawn']] <=> [$b['tier'], $a['figures'], $b['drawn']]);

    $rows = [];
    $entries = [];
    $participants = [];
    foreach ($drawn as $row) {
        $rows[] = ['id' => $row['id'], 'tier' => $row['tier'], 'figures' => $row['figures']];
        $participants[$row['id']] = $participant = new Participant($row['id'], strtoupper($row['id']));
        $entries[] = new StandingEntry($participant, 0, 0, 0, 0, $row['figures'][0], $row['figures'][3], 0.0, ['tb' => $row['figures'][1]]);
    }

    return [
        'rows' => $rows,
        'standings' => new Standings($entries, $tiered ? array_column($rows, 'tier') : []),
        'participants' => $participants,
    ];
}

/**
 * The oracle's level sets: the ids of every run of rows with one tier and
 * the same figures, in table order, by the 0-based index of its first row.
 * A row that is level with no other is a set of one.
 *
 * @param list<array{id: string, tier: int, figures: list<float>}> $rows
 *
 * @return array<int, list<string>>
 */
function tableDecisionLevelSets(array $rows): array
{
    $sets = [];
    $start = 0;
    foreach ($rows as $index => $row) {
        if ($index > 0 && ($rows[$index - 1]['tier'] !== $row['tier'] || $rows[$index - 1]['figures'] !== $row['figures'])) {
            $start = $index;
        }
        $sets[$start][] = $row['id'];
    }

    return $sets;
}

/**
 * Why the oracle rejects a list of decisions, as the backing string of the
 * reason, or null when it accepts every one. The rules are those the
 * contract states, in the order it states them, and each is held to every
 * decision before the next is held to any: the reason does not depend on
 * the order the decisions are given in.
 *
 * @param list<array{id: string, tier: int, figures: list<float>}> $rows
 * @param list<list<string>> $decisions Each as its ids, best first
 */
function tableDecisionOracleReason(array $rows, array $decisions): ?string
{
    $setOf = [];
    foreach (tableDecisionLevelSets($rows) as $ids) {
        foreach ($ids as $id) {
            $setOf[$id] = $ids;
        }
    }

    foreach ($decisions as $ids) {
        foreach ($ids as $id) {
            if (!isset($setOf[$id])) {
                return 'unknown_participant';
            }
        }
    }

    $named = array_merge(...$decisions);
    if (count($named) !== count(array_unique($named))) {
        return 'set_already_decided';
    }

    foreach ($decisions as $ids) {
        $set = $setOf[$ids[0]];
        if (count($set) < 2 || array_diff($ids, $set) !== []) {
            return 'participants_not_level';
        }
    }

    foreach ($decisions as $ids) {
        if (array_diff($setOf[$ids[0]], $ids) !== []) {
            return 'part_of_level_set';
        }
    }

    return null;
}

/**
 * The order the oracle gives the rows once every decision is applied: each
 * decided set in the order of its decision, in the places it held, and
 * every other row where it was.
 *
 * @param list<array{id: string, tier: int, figures: list<float>}> $rows
 * @param list<list<string>> $decisions Decisions the oracle accepts
 *
 * @return list<string>
 */
function tableDecisionOracleOrder(array $rows, array $decisions): array
{
    $order = array_column($rows, 'id');
    foreach (tableDecisionLevelSets($rows) as $start => $ids) {
        foreach ($decisions as $decision) {
            if (array_diff($ids, $decision) === [] && count($ids) === count($decision)) {
                array_splice($order, $start, count($ids), $decision);
            }
        }
    }

    return $order;
}

/**
 * One drawn order for some of the level sets of two or more.
 *
 * @param list<array{id: string, tier: int, figures: list<float>}> $rows
 * @param int $share How many of ten sets are decided
 *
 * @return list<list<string>>
 */
function tableDecisionDrawn(Randomizer $rng, array $rows, int $share = 6): array
{
    $decisions = [];
    foreach (tableDecisionLevelSets($rows) as $ids) {
        if (count($ids) >= 2 && $rng->getInt(1, 10) <= $share) {
            $decisions[] = $rng->shuffleArray($ids);
        }
    }

    return $rng->shuffleArray($decisions);
}

/**
 * @param list<list<string>> $decisions
 *
 * @return list<Decider>
 */
function tableDecisionDeciders(array $decisions): array
{
    return array_map(
        static fn(array $ids): Decider => new Decider($ids, 'decided', 'for ' . implode(' ', $ids)),
        $decisions
    );
}

/**
 * The rejection that applying decisions, or selecting with them, ends in.
 *
 * @param Closure(): mixed $apply
 */
function tableDecisionRejection(Closure $apply): RejectedDecisionException
{
    try {
        $apply();
    } catch (RejectedDecisionException $rejected) {
        return $rejected;
    }

    throw new LogicException('The decision was not rejected.');
}

/**
 * The pool of the issue: four entrants, every event drawn 0-0.
 *
 * @return array{pool: list<Participant>, results: list<Result>, outcome: StageOutcome}
 *
 * @throws InvalidConfigurationException When the pool cannot be scheduled
 * @throws IncompleteScheduleException When the pool cannot be scheduled
 */
function tableDecisionDrawnPool(): array
{
    $pool = array_map(static fn(int $number): Participant => new Participant("p{$number}", "Entrant {$number}"), range(1, 4));
    $results = array_values(array_map(static function (Event $event): Result {
        [$first, $second] = $event->getParticipants();

        return new Result($event, null, [$first->getId() => 0, $second->getId() => 0]);
    }, new RoundRobinScheduler()->schedule($pool)->getEvents()));

    return [
        'pool' => $pool,
        'results' => $results,
        'outcome' => StageOutcome::combining([
            'A' => new StageOutcome(new StandingsCalculator()->calculate($pool, $results), $results, entrants: $pool),
        ]),
    ];
}

/**
 * A table of stated ranking values, in the order given, with one result so
 * that a selector does not read it as unplayed.
 *
 * @param array<string, float> $values Ranking values by id, best first
 */
function tableDecisionStatedOutcome(array $values): StageOutcome
{
    $entries = [];
    $members = [];
    foreach ($values as $id => $value) {
        $members[] = $member = new Participant((string) $id, strtoupper((string) $id));
        $entries[] = new StandingEntry($member, 2, 0, 0, 0, $value);
    }

    return new StageOutcome(new Standings($entries), [new Result(new Event([$members[0], $members[1]]))]);
}

/**
 * Generated pools as plain rows, and the pooled outcome built from them by
 * hand. Each row has the events it has played, so that rows can be level per
 * event played and apart on their totals: most ranking values are a rate
 * times the events played. The table of the outcome is every row in the
 * order of its totals.
 *
 * @return array{
 *     pools: array<string, list<array{id: string, tier: int, figures: list<float>, played: int}>>,
 *     rows: list<array{id: string, tier: int, figures: list<float>, played: int}>,
 *     smallest: int,
 *     outcome: StageOutcome
 * }
 */
function tableDecisionPooled(Randomizer $rng): array
{
    // By totals, higher first; a stable sort leaves level rows as drawn
    $byTotals = static fn(array $a, array $b): int => $b['figures'] <=> $a['figures'];

    $pools = [];
    foreach (array_slice(['A', 'B', 'C', 'D'], 0, $rng->getInt(2, 4)) as $label) {
        $rows = [];
        for ($number = 1, $size = $rng->getInt(2, 4); $number <= $size; ++$number) {
            $played = $rng->getInt(1, 3);
            $scoreFor = $rng->getInt(0, 5) === 0 ? 2.0 * $played : 0.0;
            $rows[] = [
                'id' => strtolower($label) . $number,
                'tier' => 0,
                // Ranking value, the one tiebreaker, score difference, scores-for
                'figures' => [
                    $rng->getInt(0, 5) === 0 ? 4.5 : [0.0, 1.0, 3.0][$rng->getInt(0, 2)] * $played,
                    $rng->getInt(0, 5) === 0 ? 1.0 * $played : 0.0,
                    $scoreFor,
                    $scoreFor,
                ],
                'played' => $played,
            ];
        }
        usort($rows, $byTotals);
        $pools[$label] = $rows;
    }

    $all = array_merge(...array_values($pools));
    usort($all, $byTotals);

    $entries = [];
    foreach ($all as $row) {
        $entries[$row['id']] = new StandingEntry(
            new Participant($row['id'], strtoupper($row['id'])),
            $row['played'],
            0,
            0,
            0,
            $row['figures'][0],
            $row['figures'][3],
            0.0,
            ['tb' => $row['figures'][1]]
        );
    }

    $outcomes = [];
    $results = [];
    foreach ($pools as $label => $rows) {
        $table = array_map(static fn(array $row): StandingEntry => $entries[$row['id']], $rows);
        $played = [new Result(new Event([$table[0]->getParticipant(), $table[1]->getParticipant()]))];
        $outcomes[$label] = new StageOutcome(new Standings($table), $played);
        $results = [...$results, ...$played];
    }

    return [
        'pools' => $pools,
        'rows' => $all,
        'smallest' => min(array_map(count(...), $pools)),
        'outcome' => new StageOutcome(new Standings(array_values($entries)), $results, pools: $outcomes),
    ];
}

/**
 * The oracle's comparison across pools: the given ids as groups of level
 * rows, best first, each group in the order of the table of the outcome.
 * Per event played, two figures are compared as fractions, by multiplying
 * each by the events the other row has played: nothing is divided.
 *
 * @param list<array{id: string, tier: int, figures: list<float>, played: int}> $rows The rows of the
 *                                                                                    outcome's table,
 *                                                                                    in its order
 * @param list<string> $ids
 *
 * @return list<list<string>>
 */
function tableDecisionComparedGroups(array $rows, array $ids, bool $perEvent): array
{
    $compare = static function (array $a, array $b) use ($perEvent): int {
        foreach ($a['figures'] as $index => $figure) {
            $comparison = $perEvent
                ? $b['figures'][$index] * $a['played'] <=> $figure * $b['played']
                : $b['figures'][$index] <=> $figure;
            if ($comparison !== 0) {
                return $comparison;
            }
        }

        return 0;
    };

    // In the order of the table, which a stable sort keeps among level rows
    $compared = array_values(array_filter($rows, static fn(array $row): bool => in_array($row['id'], $ids, true)));
    usort($compared, $compare);

    $groups = [];
    $group = [];
    foreach ($compared as $index => $row) {
        if ($index > 0 && $compare($compared[$index - 1], $row) !== 0) {
            $groups[] = $group;
            $group = [];
        }
        $group[] = $row['id'];
    }
    if ($group !== []) {
        $groups[] = $group;
    }

    return $groups;
}

describe('a decision applied to a generated table', function (): void {
    it('reorders exactly the set it names, and nothing else', function (): void {
        $decided = 0;
        $setsOfThreeOrMore = 0;
        $tablesWithSeveralDecisions = 0;
        $decidedInsideATier = 0;
        $leftUndecided = 0;

        for ($seed = 1; $seed <= 300; ++$seed) {
            $rng = new Randomizer(new Mt19937($seed));
            ['rows' => $rows, 'standings' => $standings, 'participants' => $participants] = tableDecisionTable($rng);
            $decisions = tableDecisionDrawn($rng, $rows);
            $deciders = tableDecisionDeciders($decisions);
            $context = "seed {$seed}";

            $table = $standings->withDecisions($deciders);

            // The order is the oracle's
            expect(tableDecisionOrder($table))->toBe(tableDecisionOracleOrder($rows, $decisions), $context)
                ->and($table->getTiers())->toBe($standings->getTiers(), $context)
                ->and(count($table))->toBe(count($standings), $context);

            // Every entry is the entry it was, and one outside a decided set
            // is at the place it had
            $decidedIds = array_merge(...$decisions);
            foreach (array_values($standings->getEntries()) as $index => $entry) {
                $id = $entry->getParticipant()->getId();
                expect($table->getEntryFor($entry->getParticipant()))->toBe($entry, $context);
                if (!in_array($id, $decidedIds, true)) {
                    expect($table->getEntries()[$index])->toBe($entry, $context)
                        ->and($table->getDecisionFor($entry->getParticipant()))->toBeNull($context);
                }
            }

            // The tied sets are the same sets at the same positions, and
            // each says whether it was decided, and by which decision
            $before = $standings->getTiedSets();
            $after = $table->getTiedSets();
            expect(count($after))->toBe(count($before), $context);
            $inTableOrder = [];
            foreach ($after as $number => $tiedSet) {
                $ids = tableDecisionIds($tiedSet->getParticipants());
                $sorted = $ids;
                sort($sorted);
                $was = tableDecisionIds($before[$number]->getParticipants());
                sort($was);

                expect($sorted)->toBe($was, $context)
                    ->and($tiedSet->getFirstPosition())->toBe($before[$number]->getFirstPosition(), $context)
                    ->and($tiedSet->getLastPosition())->toBe($before[$number]->getLastPosition(), $context);

                $decision = $tiedSet->getDecision();
                if (in_array($ids, $decisions, true)) {
                    expect($decision?->getParticipantIds())->toBe($ids, $context)
                        ->and($decision?->getReason())->toBe('for ' . implode(' ', $ids), $context);
                    foreach ($tiedSet->getParticipants() as $participant) {
                        expect($table->getDecisionFor($participant))->toBe($decision, $context);
                    }
                    $inTableOrder[] = $decision;
                    ++$decided;
                    $setsOfThreeOrMore += count($ids) >= 3 ? 1 : 0;
                    $decidedInsideATier += $standings->getTiers() !== [] ? 1 : 0;
                } else {
                    expect($decision)->toBeNull($context);
                    ++$leftUndecided;
                }
            }
            expect($table->getDecisions())->toBe($inTableOrder, $context);
            $tablesWithSeveralDecisions += count($decisions) > 1 ? 1 : 0;

            // The order the decisions are given in does not matter, and
            // neither does giving them one call at a time
            $oneByOne = $standings;
            foreach ($rng->shuffleArray($deciders) as $decider) {
                $oneByOne = $oneByOne->withDecisions([$decider]);
            }
            expect($oneByOne->getEntries())->toBe($table->getEntries(), $context)
                ->and($oneByOne->getDecisions())->toBe($table->getDecisions(), $context)
                ->and($standings->withDecisions($rng->shuffleArray($deciders))->getEntries())->toBe($table->getEntries(), $context);

            // The table the decisions were applied to is not changed
            expect(tableDecisionOrder($standings))->toBe(array_column($rows, 'id'), $context)
                ->and($standings->getDecisions())->toBe([], $context);

            // The entrant list orders the sets that are still undecided and
            // leaves the decided ones in the order of their decisions
            $entrants = $rng->shuffleArray(array_values($participants));
            $byList = $table->withTiedSetsOrderedBy($entrants);
            $position = array_flip(tableDecisionIds($entrants));
            foreach ($byList->getTiedSets() as $tiedSet) {
                $ids = tableDecisionIds($tiedSet->getParticipants());
                if ($tiedSet->getDecision() instanceof Decider) {
                    expect($ids)->toBe($tiedSet->getDecision()->getParticipantIds(), $context);
                } else {
                    $inListOrder = $ids;
                    usort($inListOrder, static fn(string $a, string $b): int => $position[$a] <=> $position[$b]);
                    expect($ids)->toBe($inListOrder, $context);
                }
            }
            expect($byList->getDecisions())->toBe($table->getDecisions(), $context);
        }

        // Every shape occurred, so nothing above is vacuous
        expect($decided)->toBeGreaterThan(150)
            ->and($setsOfThreeOrMore)->toBeGreaterThan(40)
            ->and($tablesWithSeveralDecisions)->toBeGreaterThan(30)
            ->and($decidedInsideATier)->toBeGreaterThan(30)
            ->and($leftUndecided)->toBeGreaterThan(60);
    });

    it('is accepted or rejected as the oracle says, with its reason, whatever it names', function (): void {
        /** @var array<string, int> $seen */
        $seen = ['accepted' => 0];
        $ordersHeldToOneReason = 0;

        for ($seed = 1; $seed <= 1500; ++$seed) {
            $rng = new Randomizer(new Mt19937($seed));
            ['rows' => $rows, 'standings' => $standings] = tableDecisionTable($rng);
            $ids = array_column($rows, 'id');
            $sets = array_values(tableDecisionLevelSets($rows));

            // One to three decisions, each drawn one of five ways: a whole
            // set in a drawn order; a set with a member left out; a set with
            // a row from elsewhere added; any ids at all; a set with an id
            // no row has
            $decisions = [];
            for ($i = $rng->getInt(1, 3); $i > 0; --$i) {
                $set = $rng->shuffleArray($sets[$rng->getInt(0, count($sets) - 1)]);
                $decisions[] = match ($rng->getInt(0, 9)) {
                    0, 1, 2, 3, 4, 5 => $set,
                    6 => count($set) > 1 ? array_slice($set, 1) : $set,
                    7 => array_values(array_unique([...$set, $ids[$rng->getInt(0, count($ids) - 1)]])),
                    8 => array_slice($rng->shuffleArray($ids), 0, $rng->getInt(1, count($ids))),
                    default => [...$set, 'nobody'],
                };
            }

            $expected = tableDecisionOracleReason($rows, $decisions);
            $context = "seed {$seed}: " . json_encode($decisions);

            try {
                $table = $standings->withDecisions(tableDecisionDeciders($decisions));
            } catch (RejectedDecisionException $rejected) {
                expect($rejected->getReason()->value)->toBe($expected, $context)
                    ->and($rejected)->toBeInstanceOf(TacticianException::class)
                    ->and($rejected)->toBeInstanceOf(InvalidArgumentException::class)
                    ->and($rejected->getParticipantIds())->not->toBe([], $context)
                    ->and($rejected->getPool())->toBeNull($context);
                // The decision named is one of those given
                expect(in_array($rejected->getDecider()->getParticipantIds(), $decisions, true))->toBeTrue($context);
                $seen[$rejected->getReason()->value] = ($seen[$rejected->getReason()->value] ?? 0) + 1;

                // The reason is the same whatever order the decisions are in
                $reversed = tableDecisionRejection(
                    fn(): Standings => $standings->withDecisions(tableDecisionDeciders(array_reverse($decisions)))
                );
                expect($reversed->getReason()->value)->toBe($expected, $context);
                $ordersHeldToOneReason += count($decisions) > 1 ? 1 : 0;

                continue;
            }

            expect($expected)->toBeNull($context)
                ->and(tableDecisionOrder($table))->toBe(tableDecisionOracleOrder($rows, $decisions), $context);
            ++$seen['accepted'];
        }

        expect($seen['accepted'])->toBeGreaterThan(100)
            ->and($seen['unknown_participant'] ?? 0)->toBeGreaterThan(100)
            ->and($seen['set_already_decided'] ?? 0)->toBeGreaterThan(50)
            ->and($seen['participants_not_level'] ?? 0)->toBeGreaterThan(100)
            ->and($seen['part_of_level_set'] ?? 0)->toBeGreaterThan(25)
            ->and($ordersHeldToOneReason)->toBeGreaterThan(500);
    });

    it('never moves an entry past one it is not level with, and never across a tier', function (): void {
        // Two rows with the same figures in different tiers, and a row
        // between two level ones in a table built by hand
        $a = new Participant('a', 'A');
        $b = new Participant('b', 'B');
        $c = new Participant('c', 'C');
        $entry = static fn(Participant $participant, float $value): StandingEntry => new StandingEntry($participant, 1, 0, 0, 0, $value);

        $tiered = new Standings([$entry($a, 3.0), $entry($b, 3.0), $entry($c, 3.0)], [0, 0, 1]);
        $rejected = tableDecisionRejection(fn(): Standings => $tiered->withDecisions([new Decider(['c', 'a', 'b'])]));
        expect($rejected->getReason())->toBe(RejectedDecisionReason::ParticipantsNotLevel)
            ->and($rejected->getParticipantIds())->toBe(['a', 'b'])
            ->and(tableDecisionOrder($tiered->withDecisions([new Decider(['b', 'a'])])))->toBe(['b', 'a', 'c']);

        // Level entries that are not adjacent are no tied set: the table is
        // read as it is given
        $apart = new Standings([$entry($a, 3.0), $entry($b, 4.0), $entry($c, 3.0)]);
        expect(tableDecisionRejection(fn(): Standings => $apart->withDecisions([new Decider(['c', 'a'])]))->getReason())
            ->toBe(RejectedDecisionReason::ParticipantsNotLevel);
    });

    it('keeps the keys of a table built by hand at the places they were given at', function (): void {
        $a = new StandingEntry(new Participant('a', 'A'), 0, 0, 0, 0, 1.0);
        $b = new StandingEntry(new Participant('b', 'B'), 0, 0, 0, 0, 1.0);

        expect(new Standings(['first' => $a, 'second' => $b])->withDecisions([new Decider(['b', 'a'])])->getEntries())
            ->toBe(['first' => $b, 'second' => $a]);
    });

    it('keeps every entry of a table built by hand that lists a participant twice', function (): void {
        // Nothing stops such a table being built. A decision orders by ID,
        // and both entries of the ID go where the decision puts it
        $first = new StandingEntry(new Participant('a', 'A'), 1, 0, 0, 0, 1.0);
        $again = new StandingEntry(new Participant('a', 'A again'), 1, 0, 0, 0, 1.0);
        $b = new StandingEntry(new Participant('b', 'B'), 1, 0, 0, 0, 1.0);

        $table = new Standings([$first, $b, $again])->withDecisions([new Decider(['b', 'a'])]);

        expect($table->getEntries())->toBe([$b, $first, $again]);
    });

    it('takes its decisions through the constructor under the same rules', function (): void {
        $a = new StandingEntry(new Participant('a', 'A'), 0, 0, 0, 0, 1.0);
        $b = new StandingEntry(new Participant('b', 'B'), 0, 0, 0, 0, 1.0);
        $c = new StandingEntry(new Participant('c', 'C'), 0, 0, 0, 0, 0.0);
        $decision = new Decider(['b', 'a'], 'ruling');

        $table = new Standings([$a, $b, $c], [], [$decision]);

        expect(tableDecisionOrder($table))->toBe(['b', 'a', 'c'])
            ->and($table->getDecisions())->toBe([$decision])
            ->and(fn(): Standings => new Standings([$a, $b, $c], [], [new Decider(['a', 'c'])]))
            ->toThrow(RejectedDecisionException::class);
    });

    it('returns the table itself when it is given no decision, and a table without decisions says so', function (): void {
        $table = new StandingsCalculator()->calculate([new Participant('a', 'A'), new Participant('b', 'B')], []);

        expect($table->withDecisions([]))->toBe($table)
            ->and($table->getDecisions())->toBe([])
            ->and($table->getDecisionFor(new Participant('a', 'A')))->toBeNull()
            ->and($table->getTiedSets()[0]->getDecision())->toBeNull()
            ->and(new TiedSet($table->getEntries(), 1)->getDecision())->toBeNull();
    });
});

describe('a decision that is rejected', function (): void {
    beforeEach(function (): void {
        // a and b are level on 6, c stands alone on 4, d, e and f are level on 1
        $this->table = tableDecisionStatedOutcome(['a' => 6.0, 'b' => 6.0, 'c' => 4.0, 'd' => 1.0, 'e' => 1.0, 'f' => 1.0])->getStandings();
    });

    it('says which rule it broke, which decision and which participants', function (array $ids, RejectedDecisionReason $reason, array $atFault): void {
        /** @var list<string> $ids */
        $decision = new Decider($ids, 'ruling', 'as decided');

        $rejected = tableDecisionRejection(fn(): Standings => $this->table->withDecisions([$decision]));

        expect($rejected->getReason())->toBe($reason)
            ->and($rejected->getDecider())->toBe($decision)
            ->and($rejected->getParticipantIds())->toBe($atFault)
            ->and($rejected->getRankAcrossPools())->toBeNull()
            ->and($rejected->getMessage())->toContain(implode(', ', $ids));
    })->with([
        'a participant who is tied with nobody' => [['c'], RejectedDecisionReason::ParticipantsNotLevel, ['c']],
        'two participants who are not level' => [['c', 'a'], RejectedDecisionReason::ParticipantsNotLevel, ['a']],
        'a set and a participant below it' => [['a', 'b', 'c'], RejectedDecisionReason::ParticipantsNotLevel, ['c']],
        'the wrong set: members of two sets' => [['a', 'd'], RejectedDecisionReason::ParticipantsNotLevel, ['d']],
        'two whole sets as one order' => [['d', 'e', 'f', 'a', 'b'], RejectedDecisionReason::ParticipantsNotLevel, ['a', 'b']],
        'part of a set of three' => [['f', 'd'], RejectedDecisionReason::PartOfLevelSet, ['e']],
        'one member of a set of two' => [['b'], RejectedDecisionReason::PartOfLevelSet, ['a']],
        'a participant the table does not have' => [['a', 'b', 'x'], RejectedDecisionReason::UnknownParticipant, ['x']],
        'nobody the table has' => [['x', 'y'], RejectedDecisionReason::UnknownParticipant, ['x', 'y']],
    ]);

    it('is reported by the first rule it breaks: unknown, then decided twice, then not level, then partial', function (): void {
        $reason = fn(array ...$decisions): RejectedDecisionReason => tableDecisionRejection(
            fn(): Standings => $this->table->withDecisions(array_map(static fn(array $ids): Decider => new Decider($ids), $decisions))
        )->getReason();

        expect($reason(['a', 'b'], ['b', 'x']))->toBe(RejectedDecisionReason::UnknownParticipant)
            ->and($reason(['a', 'b'], ['b', 'c']))->toBe(RejectedDecisionReason::SetAlreadyDecided)
            ->and($reason(['d', 'c']))->toBe(RejectedDecisionReason::ParticipantsNotLevel)
            ->and($reason(['d', 'e']))->toBe(RejectedDecisionReason::PartOfLevelSet);
    });

    it('is reported by the same rule whatever order the decisions are given in', function (): void {
        $reason = fn(array ...$decisions): RejectedDecisionReason => tableDecisionRejection(
            fn(): Standings => $this->table->withDecisions(array_map(static fn(array $ids): Decider => new Decider($ids), $decisions))
        )->getReason();

        // One decision is not level and overlaps the other: decided twice
        // comes first, also when the decision that is not level is read first
        expect($reason(['a', 'b'], ['b', 'c']))->toBe(RejectedDecisionReason::SetAlreadyDecided)
            ->and($reason(['b', 'c'], ['a', 'b']))->toBe(RejectedDecisionReason::SetAlreadyDecided)
            // One names nobody the table has, another is not level
            ->and($reason(['d', 'c'], ['a', 'x']))->toBe(RejectedDecisionReason::UnknownParticipant)
            ->and($reason(['a', 'x'], ['d', 'c']))->toBe(RejectedDecisionReason::UnknownParticipant)
            // One is part of a set, another is not level
            ->and($reason(['d', 'e'], ['c', 'a']))->toBe(RejectedDecisionReason::ParticipantsNotLevel)
            ->and($reason(['c', 'a'], ['d', 'e']))->toBe(RejectedDecisionReason::ParticipantsNotLevel);
    });

    it('is rejected for a set that has a decision already, given together or one after the other', function (): void {
        $first = new Decider(['a', 'b']);
        $second = new Decider(['b', 'a']);

        $together = tableDecisionRejection(fn(): Standings => $this->table->withDecisions([$first, $second]));
        $later = tableDecisionRejection(fn(): Standings => $this->table->withDecisions([$first])->withDecisions([$second]));
        $overlapping = tableDecisionRejection(fn(): Standings => $this->table->withDecisions([new Decider(['d', 'e', 'f']), new Decider(['f', 'e'])]));

        expect($together->getReason())->toBe(RejectedDecisionReason::SetAlreadyDecided)
            ->and($together->getDecider())->toBe($second)
            ->and($together->getParticipantIds())->toBe(['b', 'a'])
            ->and($later->getReason())->toBe(RejectedDecisionReason::SetAlreadyDecided)
            ->and($overlapping->getReason())->toBe(RejectedDecisionReason::SetAlreadyDecided)
            ->and($overlapping->getParticipantIds())->toBe(['f', 'e']);
    });

    it('changes nothing: the table it was given to is as it was', function (): void {
        $before = tableDecisionOrder($this->table);

        tableDecisionRejection(fn(): Standings => $this->table->withDecisions([new Decider(['b', 'a']), new Decider(['d', 'e'])]));

        expect(tableDecisionOrder($this->table))->toBe($before)
            ->and($this->table->getDecisions())->toBe([]);
    });

    it('cannot list a participant twice: the decision itself refuses that', function (): void {
        expect(fn(): Decider => new Decider(['a', 'b', 'a']))
            ->toThrow(InvalidInputException::class, 'A decider lists participant a twice');
    });

    it('has a stable identifier for every reason', function (): void {
        expect(array_column(RejectedDecisionReason::cases(), 'value', 'name'))->toBe([
            'UnknownParticipant' => 'unknown_participant',
            'SetAlreadyDecided' => 'set_already_decided',
            'ParticipantsNotLevel' => 'participants_not_level',
            'PartOfLevelSet' => 'part_of_level_set',
            'Stale' => 'stale',
        ]);
    });
});

describe('a table that carries a decision and is then changed', function (): void {
    it('keeps the decision where the change leaves the set as it was, and is refused where it does not', function (): void {
        // The tiebreaker of the generated entries, by name: its values are
        // those the entries carry
        $calculator = new StandingsCalculator(tiebreakers: [new class implements TiebreakerInterface {
            #[Override]
            public function getName(): string
            {
                return 'tb';
            }

            #[Override]
            public function calculate(Participant $participant, array $results, array $entries): float
            {
                return 0.0;
            }
        }]);

        $kept = 0;
        $stale = 0;
        $staleBecauseASetGrew = 0;

        for ($seed = 1; $seed <= 1200; ++$seed) {
            $rng = new Randomizer(new Mt19937($seed));
            ['rows' => $rows, 'standings' => $standings] = tableDecisionTable($rng);
            $decisions = tableDecisionDrawn($rng, $rows, 10);
            if ($decisions === []) {
                continue;
            }
            $table = $standings->withDecisions(tableDecisionDeciders($decisions));
            $context = "seed {$seed}";

            // Adjust a few rows by amounts that keep every sum exact
            $amounts = [];
            foreach ($rng->shuffleArray(array_column($rows, 'id')) as $index => $id) {
                if ($index < $rng->getInt(1, 3)) {
                    $amounts[$id] = [-3.0, -1.0, 1.0, 1.5, 3.0][$rng->getInt(0, 4)];
                }
            }

            // One table in three: a single adjustment that brings a row of
            // the same tier level with a decided set, where there is such a
            // row. The set is not separated, it grows
            if ($rng->getInt(0, 2) === 0) {
                $byId = array_column($rows, null, 'id');
                $decidedRow = $byId[$decisions[0][0]];
                foreach ($rows as $row) {
                    if (
                        !in_array($row['id'], $decisions[0], true)
                        && $row['tier'] === $decidedRow['tier']
                        && array_slice($row['figures'], 1) === array_slice($decidedRow['figures'], 1)
                    ) {
                        $amounts = [$row['id'] => $decidedRow['figures'][0] - $row['figures'][0]];
                        break;
                    }
                }
            }

            // The oracle: the adjusted rows, level by tier and figures alone.
            // A decision still stands when its ids are one whole level set.
            $levelWith = [];
            foreach ($rows as $row) {
                $figures = $row['figures'];
                $figures[0] += $amounts[$row['id']] ?? 0.0;
                $levelWith[json_encode([$row['tier'], $figures])][] = $row['id'];
            }
            // It no longer stands when its ids are in more than one level
            // set (separated), or in one that has more members (grown)
            $stands = true;
            $separated = false;
            foreach ($decisions as $ids) {
                $sorted = $ids;
                sort($sorted);
                $found = false;
                $within = false;
                foreach ($levelWith as $set) {
                    sort($set);
                    $found = $found || $set === $sorted;
                    $within = $within || array_diff($ids, $set) === [];
                }
                $stands = $stands && $found;
                $separated = $separated || !$within;
            }

            $adjustments = [];
            foreach ($amounts as $id => $amount) {
                $adjustments[] = new Adjustment($id, $amount);
            }

            try {
                $adjusted = $calculator->withAdjustments($table, $adjustments);
            } catch (RejectedDecisionException $rejected) {
                expect($stands)->toBeFalse($context)
                    ->and($rejected->getReason())->toBe(RejectedDecisionReason::Stale, $context)
                    ->and($rejected->getPrevious())->toBeInstanceOf(RejectedDecisionException::class, $context)
                    ->and(in_array($rejected->getDecider()->getParticipantIds(), $decisions, true))->toBeTrue($context);
                ++$stale;

                // What the decision was refused for against the changed
                // table: not level where a set was separated, and part of a
                // set where the only change is that a set grew
                $previous = $rejected->getPrevious();
                assert($previous instanceof RejectedDecisionException);
                expect($previous->getReason())->toBe(
                    $separated ? RejectedDecisionReason::ParticipantsNotLevel : RejectedDecisionReason::PartOfLevelSet,
                    $context
                );
                $staleBecauseASetGrew += $separated ? 0 : 1;

                continue;
            }

            // Kept: every decision is still carried, and its set is in its order
            expect($stands)->toBeTrue($context)
                ->and(count($adjusted->getDecisions()))->toBe(count($decisions), $context);
            foreach ($adjusted->getTiedSets() as $tiedSet) {
                $decision = $tiedSet->getDecision();
                if ($decision instanceof Decider) {
                    expect(tableDecisionIds($tiedSet->getParticipants()))->toBe($decision->getParticipantIds(), $context);
                }
            }
            ++$kept;
        }

        expect($kept)->toBeGreaterThan(40)
            ->and($stale)->toBeGreaterThan(100)
            ->and($staleBecauseASetGrew)->toBeGreaterThan(40);
    });

    it('is refused when an adjustment separates a decided pair, and kept when it moves both alike', function (): void {
        ['pool' => $pool, 'results' => $results] = tableDecisionDrawnPool();
        $calculator = new StandingsCalculator();
        $decision = new Decider(['p3', 'p1', 'p4', 'p2'], 'ruling');
        $table = $calculator->calculate($pool, $results)->withDecisions([$decision]);

        $rejected = tableDecisionRejection(fn(): Standings => $calculator->withAdjustments($table, [new Adjustment('p1', 1.0)]));
        expect($rejected->getReason())->toBe(RejectedDecisionReason::Stale)
            ->and($rejected->getDecider())->toBe($decision)
            ->and($rejected->getMessage())->toContain('apply the decisions last');

        $moved = $calculator->withAdjustments($table, array_map(
            static fn(Participant $participant): Adjustment => new Adjustment($participant->getId(), -2.0),
            $pool
        ));
        expect(tableDecisionOrder($moved))->toBe(['p3', 'p1', 'p4', 'p2'])
            ->and($moved->getDecisions())->toBe([$decision])
            ->and($moved->getEntries()[0]->getRankingValue())->toBe(1.0);

        // The order to do it in: adjust, then decide what is level after that
        $adjustedFirst = $calculator->withAdjustments($calculator->calculate($pool, $results), [new Adjustment('p1', 1.0)])
            ->withDecisions([new Decider(['p3', 'p4', 'p2'])]);
        expect(tableDecisionOrder($adjustedFirst))->toBe(['p1', 'p3', 'p4', 'p2']);
    });

    it('is refused when a credited bye separates a decided pair, and kept when no bye is credited', function (): void {
        ['pool' => $pool, 'results' => $results] = tableDecisionDrawnPool();
        $calculator = new StandingsCalculator();
        $table = $calculator->calculate($pool, $results)->withDecisions([new Decider(['p3', 'p1', 'p4', 'p2'])]);

        expect(tableDecisionRejection(fn(): Standings => $calculator->withByesCredited($table, ['p2' => 1]))->getReason())
            ->toBe(RejectedDecisionReason::Stale)
            ->and($calculator->withByesCredited($table, []))->toBe($table);
    });

    it('is rejected as not level when it is given again after a result separated the set', function (): void {
        // The decision was made for four level entrants. A corrected result
        // then gives p1 a win: the same decision no longer fits the table
        ['pool' => $pool, 'results' => $results] = tableDecisionDrawnPool();
        $decision = new Decider(['p3', 'p1', 'p4', 'p2']);
        $results[0] = new Result($results[0]->getEvent(), $pool[0]);

        $rejected = tableDecisionRejection(fn(): Standings => new StandingsCalculator()->calculate($pool, $results)->withDecisions([$decision]));

        expect($results[0]->getEvent()->hasParticipant($pool[0]))->toBeTrue()
            ->and($rejected->getReason())->toBe(RejectedDecisionReason::ParticipantsNotLevel);
    });
});

describe('the pool of four in which every event is drawn 0-0, top two to qualify', function (): void {
    it('is reported as one tied set of four spanning places 1 to 4', function (): void {
        ['outcome' => $outcome] = tableDecisionDrawnPool();

        $tiedSets = $outcome->getPool('A')?->getStandings()->getTiedSets() ?? [];
        expect($tiedSets)->toHaveCount(1)
            ->and(count($tiedSets[0]))->toBe(4)
            ->and([$tiedSets[0]->getFirstPosition(), $tiedSets[0]->getLastPosition()])->toBe([1, 4])
            ->and($tiedSets[0]->getDecision())->toBeNull();

        try {
            RankRangeSelector::topPerGroup(2)->select($outcome);
        } catch (UndecidedSelectionException $undecided) {
            expect($undecided->getParticipantIds())->toBe(['p1', 'p2', 'p3', 'p4'])
                ->and([$undecided->getFirstPlace(), $undecided->getLastPlace()])->toBe([1, 4])
                ->and($undecided->getPool())->toBe('A')
                ->and($undecided->getReason())->toBe(InvalidConfigurationReason::TieAcrossSelection)
                ->and($undecided->isPerEvent())->toBeFalse()
                ->and($undecided->getRankAcrossPools())->toBeNull();

            return;
        }

        throw new LogicException('The selection was not refused.');
    });

    it('returns p3 then p1 for the decision p3, p1, p4, p2, each marked with it', function (): void {
        ['outcome' => $outcome] = tableDecisionDrawnPool();
        $decision = new Decider(['p3', 'p1', 'p4', 'p2'], 'drawn', 'Drawn by the committee on 4 May');
        $selector = RankRangeSelector::topPerGroup(2)->withDecisions([$decision]);

        $selection = $selector->selection($outcome);

        expect(tableDecisionIds($selector->select($outcome)))->toBe(['p3', 'p1'])
            ->and(tableDecisionIds($selection->getParticipants()))->toBe(['p3', 'p1'])
            ->and(count($selection))->toBe(2)
            ->and($selection->getDecisions())->toBe([$decision]);
        foreach ($selection->getParticipants() as $participant) {
            expect($selection->isSettledByDecision($participant))->toBeTrue()
                ->and($selection->getDecisionsFor($participant))->toBe([$decision])
                ->and($selection->getDecisionsFor($participant)[0]->getLabel())->toBe('drawn')
                ->and($selection->getDecisionsFor($participant)[0]->getReason())->toBe('Drawn by the committee on 4 May');
        }

        // The two who are not selected are not in the selection, marked or not
        expect($selection->isSettledByDecision(new Participant('p4', 'Entrant 4')))->toBeFalse()
            ->and($selection->getDecisionsFor(new Participant('p4', 'Entrant 4')))->toBe([]);
    });

    it('rejects a decision for only the two who would qualify: it orders the whole set', function (): void {
        ['outcome' => $outcome] = tableDecisionDrawnPool();

        try {
            RankRangeSelector::topPerGroup(2)->withDecisions([new Decider(['p3', 'p1'])])->select($outcome);
        } catch (RejectedDecisionException $rejected) {
            expect($rejected->getReason())->toBe(RejectedDecisionReason::PartOfLevelSet)
                ->and($rejected->getParticipantIds())->toBe(['p2', 'p4'])
                ->and($rejected->getPool())->toBe('A')
                ->and($rejected->getMessage())->toContain('pool A');

            return;
        }

        throw new LogicException('The decision was not rejected.');
    });

    it('is still refused as unplayed when no event has a result, whatever is decided', function (): void {
        ['pool' => $pool] = tableDecisionDrawnPool();
        $unplayed = StageOutcome::combining(['A' => new StageOutcome(new StandingsCalculator()->calculate($pool, []), [], entrants: $pool)]);
        $selector = RankRangeSelector::topPerGroup(2)->withDecisions([new Decider(['p3', 'p1', 'p4', 'p2'])]);

        try {
            $selector->select($unplayed);
        } catch (UndecidedSelectionException $undecided) {
            expect($undecided->getReason())->toBe(InvalidConfigurationReason::UnplayedStage)
                // Accepting the table as it stands accepts an unplayed one,
                // and the decision then orders it
                ->and(tableDecisionIds($selector->withTableOrderAccepted()->select($unplayed)))->toBe(['p3', 'p1']);

            return;
        }

        throw new LogicException('The unplayed pool was selected from.');
    });
});

describe('first and second are level, both qualify, and the two places feed different slots', function (): void {
    beforeEach(function (): void {
        // In each pool the first two listed beat the other two and draw with
        // each other: places 1 and 2 are level, 3 and 4 are level
        $this->pools = [];
        $this->members = [];
        foreach (['A' => ['a1', 'a2', 'a3', 'a4'], 'B' => ['b1', 'b2', 'b3', 'b4']] as $label => $ids) {
            $members = array_map(static fn(string $id): Participant => new Participant($id, strtoupper($id)), $ids);
            $top = [$ids[0], $ids[1]];
            $results = array_map(static function (Event $event) use ($top): Result {
                [$first, $second] = $event->getParticipants();
                $firstIsTop = in_array($first->getId(), $top, true);

                return new Result($event, $firstIsTop === in_array($second->getId(), $top, true) ? null : ($firstIsTop ? $first : $second));
            }, new RoundRobinScheduler()->schedule($members)->getEvents());

            $this->members[$label] = $members;
            $this->pools[$label] = new StageOutcome(new StandingsCalculator()->calculate($members, $results), $results, entrants: $members);
        }
        $this->outcome = StageOutcome::combining($this->pools);
    });

    it('is still reported as undecided until a decision is supplied', function (): void {
        try {
            RankRangeSelector::topPerGroup(2)->select($this->outcome);
        } catch (UndecidedSelectionException $undecided) {
            expect($undecided->getReason())->toBe(InvalidConfigurationReason::TieWithinSelection)
                ->and($undecided->getParticipantIds())->toBe(['a1', 'a2'])
                ->and([$undecided->getFirstPlace(), $undecided->getLastPlace()])->toBe([1, 2])
                ->and($undecided->getPool())->toBe('A');

            // One pool decided is not both: the other is reported next
            $partly = RankRangeSelector::topPerGroup(2)->withDecisions([new Decider(['a2', 'a1'])]);
            expect(fn(): array => $partly->select($this->outcome))->toThrow(UndecidedSelectionException::class, 'pool B (b1, b2)');

            return;
        }

        throw new LogicException('The selection was not refused.');
    });

    it('puts each entrant in the slot the decision gives it, and marks the four places', function (): void {
        $forA = new Decider(['a2', 'a1'], 'decided on the day');
        $forB = new Decider(['b1', 'b2'], 'decided on the day');
        $selector = RankRangeSelector::topPerGroup(2)->withDecisions([$forB, $forA]);

        $selection = $selector->selection($this->outcome);

        // Winners first, then the runners-up: the winner of A meets the
        // runner-up of B in the bracket folded from this list
        expect(tableDecisionIds($selection->getParticipants()))->toBe(['a2', 'b1', 'a1', 'b2'])
            ->and($selection->getDecisions())->toBe([$forA, $forB]);
        foreach ($selection->getParticipants() as $participant) {
            expect($selection->getDecisionsFor($participant))->toBe([$participant->getId()[0] === 'a' ? $forA : $forB]);
        }

        $firstRound = new SingleEliminationEngine()->pairNextRound(StageState::start($selection->getParticipants()));
        $ties = array_map(static fn(Event $event): array => tableDecisionIds($event->getParticipants()), $firstRound->getEvents());
        expect($ties)->toContain(['a2', 'b2'])->toContain(['b1', 'a1']);

        // The decision the other way round puts them in the other slots
        expect(tableDecisionIds($selector->withDecisions([new Decider(['a1', 'a2']), $forB])->select($this->outcome)))
            ->toBe(['a1', 'b1', 'a2', 'b2']);
    });

    it('settles the selected places and leaves a tie below them alone', function (): void {
        // Places 3 and 4 of each pool are level too, and are not selected:
        // no decision is asked for them
        $selector = RankRangeSelector::topPerGroup(2)->withDecisions([new Decider(['a2', 'a1']), new Decider(['b2', 'b1'])]);

        expect(tableDecisionIds($selector->select($this->outcome)))->toBe(['a2', 'b2', 'a1', 'b1'])
            // A decision for a tie that touches no selected place is applied
            // and changes nothing in the list
            ->and(tableDecisionIds($selector->withDecisions([
                new Decider(['a2', 'a1']), new Decider(['b2', 'b1']), new Decider(['a4', 'a3']),
            ])->select($this->outcome)))->toBe(['a2', 'b2', 'a1', 'b1']);
    });

    it('marks only what a decision settled when the rest is accepted as the list has it', function (): void {
        $forA = new Decider(['a2', 'a1']);
        $selection = RankRangeSelector::topPerGroup(2)
            ->withTiesInsideSelectionAccepted()
            ->withDecisions([$forA])
            ->selection($this->outcome);

        expect(tableDecisionIds($selection->getParticipants()))->toBe(['a2', 'b1', 'a1', 'b2'])
            ->and($selection->isSettledByDecision($this->members['A'][0]))->toBeTrue()
            ->and($selection->isSettledByDecision($this->members['B'][0]))->toBeFalse()
            ->and($selection->getDecisions())->toBe([$forA]);
    });

    it('rejects a decision for participants who are not level, naming the pool', function (): void {
        $selector = RankRangeSelector::topPerGroup(2)->withDecisions([new Decider(['a3', 'a1'])]);

        try {
            $selector->select($this->outcome);
        } catch (RejectedDecisionException $rejected) {
            expect($rejected->getReason())->toBe(RejectedDecisionReason::ParticipantsNotLevel)
                ->and($rejected->getParticipantIds())->toBe(['a1'])
                ->and($rejected->getPool())->toBe('A')
                ->and($rejected->getPrevious())->toBeInstanceOf(RejectedDecisionException::class);

            return;
        }

        throw new LogicException('The decision was not rejected.');
    });

    it('rejects a decision that names entrants of two pools, and one that names nobody of any pool', function (): void {
        $acrossPools = RankRangeSelector::topPerGroup(2)->withDecisions([new Decider(['a1', 'b1'])]);
        $unknown = RankRangeSelector::topPerGroup(2)->withDecisions([new Decider(['a1', 'zz'])]);

        $rejected = tableDecisionRejection(fn(): array => $acrossPools->select($this->outcome));
        expect($rejected->getReason())->toBe(RejectedDecisionReason::ParticipantsNotLevel)
            ->and($rejected->getParticipantIds())->toBe(['b1']);

        $rejected = tableDecisionRejection(fn(): array => $unknown->select($this->outcome));
        expect($rejected->getReason())->toBe(RejectedDecisionReason::UnknownParticipant)
            ->and($rejected->getParticipantIds())->toBe(['zz']);

        // Both at once: the unknown participant is reported, whichever
        // decision is given first
        foreach ([[['a1', 'b1'], ['a2', 'zz']], [['a2', 'zz'], ['a1', 'b1']]] as [$one, $other]) {
            $both = RankRangeSelector::topPerGroup(2)->withDecisions([new Decider($one), new Decider($other)]);
            expect(tableDecisionRejection(fn(): array => $both->select($this->outcome))->getReason())
                ->toBe(RejectedDecisionReason::UnknownParticipant);
        }
    });

    it('reads a decision the pool table carries as one given to the selector, and refuses it twice', function (): void {
        $decision = new Decider(['a2', 'a1'], 'on the table');
        $forB = new Decider(['b2', 'b1']);
        $poolA = new StageOutcome(
            $this->pools['A']->getStandings()->withDecisions([$decision]),
            $this->pools['A']->getResults(),
            entrants: $this->members['A']
        );
        $outcome = StageOutcome::combining(['A' => $poolA, 'B' => $this->pools['B']]);

        // The entrant list of the outcome did not put the decided pair back
        expect(tableDecisionOrder($poolA->getStandings()))->toBe(['a2', 'a1', 'a3', 'a4']);

        $selection = RankRangeSelector::topPerGroup(2)->withDecisions([$forB])->selection($outcome);
        expect(tableDecisionIds($selection->getParticipants()))->toBe(['a2', 'b2', 'a1', 'b1'])
            ->and($selection->getDecisions())->toBe([$decision, $forB]);

        $twice = RankRangeSelector::topPerGroup(2)->withDecisions([new Decider(['a1', 'a2']), $forB]);
        $rejected = tableDecisionRejection(fn(): array => $twice->select($outcome));
        expect($rejected->getReason())->toBe(RejectedDecisionReason::SetAlreadyDecided)
            ->and($rejected->getPool())->toBe('A');
    });
});

describe('a per-group selection from generated pools with decisions', function (): void {
    it('returns the decided order with its marks, and refuses exactly what is still undecided', function (): void {
        $selected = 0;
        $refused = 0;
        $marked = 0;
        $unmarked = 0;

        for ($seed = 1; $seed <= 500; ++$seed) {
            $rng = new Randomizer(new Mt19937($seed));

            // One pool or two, each a generated table with drawn decisions
            // for some of its level sets
            $drawn = [];
            $pools = [];
            $allDecisions = [];
            foreach ($rng->getInt(0, 1) === 0 ? ['A'] : ['A', 'B'] as $label) {
                $table = tableDecisionTable($rng, strtolower($label));
                $members = array_values($table['participants']);
                $decisions = tableDecisionDrawn($rng, $table['rows']);

                $pools[$label] = new StageOutcome($table['standings'], [new Result(new Event([$members[0], $members[1]]))]);
                $drawn[] = ['label' => $label, 'rows' => $table['rows'], 'decisions' => $decisions];
                $allDecisions = [...$allDecisions, ...$decisions];
            }

            $smallest = min(array_map(static fn(array $pool): int => count($pool['rows']), $drawn));
            $from = $rng->getInt(1, $smallest);
            $to = $rng->getInt($from, $smallest);
            $policy = $rng->getInt(0, 2);
            $context = "seed {$seed}, ranks {$from} to {$to}, policy {$policy}";

            $selector = RankRangeSelector::perGroup($from, $to)->withDecisions(tableDecisionDeciders($rng->shuffleArray($allDecisions)));
            $selector = match ($policy) {
                0 => $selector,
                1 => $selector->withTiesInsideSelectionAccepted(),
                default => $selector->withTableOrderAccepted(),
            };

            // The oracle: each pool's order with its decisions, and the first
            // undecided set, in pool order and then table order, that holds a
            // selected place in a way the policy does not accept
            $orders = [];
            $expectedRefusal = null;
            foreach ($drawn as $pool) {
                $orders[] = tableDecisionOracleOrder($pool['rows'], $pool['decisions']);
                foreach (tableDecisionLevelSets($pool['rows']) as $start => $ids) {
                    $first = $start + 1;
                    $last = $start + count($ids);
                    // The drawn decisions are whole sets, so one that names
                    // every member of this set is the decision for it
                    $undecided = count($ids) >= 2
                        && array_filter($pool['decisions'], static fn(array $decision): bool => array_diff($ids, $decision) === []) === [];
                    $touches = $last >= $from && $first <= $to;
                    $inside = $first >= $from && $last <= $to;
                    if ($expectedRefusal === null && $undecided && $touches && ($policy === 0 || ($policy === 1 && !$inside))) {
                        $expectedRefusal = ['ids' => $ids, 'first' => $first, 'last' => $last, 'pool' => $pool['label']];
                    }
                }
            }

            $outcome = StageOutcome::combining($pools);

            try {
                $selection = $selector->selection($outcome);
            } catch (UndecidedSelectionException $undecided) {
                expect($expectedRefusal)->not->toBeNull($context)
                    ->and([
                        'ids' => $undecided->getParticipantIds(),
                        'first' => $undecided->getFirstPlace(),
                        'last' => $undecided->getLastPlace(),
                        'pool' => $undecided->getPool(),
                    ])->toBe($expectedRefusal, $context);
                ++$refused;

                continue;
            }

            expect($expectedRefusal)->toBeNull($context);

            // Rank by rank, each rank in pool order
            $expected = [];
            for ($rank = $from; $rank <= $to; ++$rank) {
                foreach ($orders as $order) {
                    $expected[] = $order[$rank - 1];
                }
            }
            expect(tableDecisionIds($selection->getParticipants()))->toBe($expected, $context)
                ->and(tableDecisionIds($selector->select($outcome)))->toBe($expected, $context);

            // A selected participant is marked exactly when a decision names it
            foreach ($selection->getParticipants() as $participant) {
                $naming = array_values(array_filter(
                    $allDecisions,
                    static fn(array $decision): bool => in_array($participant->getId(), $decision, true)
                ));

                expect($selection->isSettledByDecision($participant))->toBe($naming !== [], $context)
                    ->and(array_map(
                        static fn(Decider $decider): array => $decider->getParticipantIds(),
                        $selection->getDecisionsFor($participant)
                    ))->toBe($naming, $context);
                $marked += $naming === [] ? 0 : 1;
                $unmarked += $naming === [] ? 1 : 0;
            }
            ++$selected;
        }

        expect($selected)->toBeGreaterThan(150)
            ->and($refused)->toBeGreaterThan(50)
            ->and($marked)->toBeGreaterThan(150)
            ->and($unmarked)->toBeGreaterThan(150);
    });
});

describe('a decision given to an overall selection', function (): void {
    it('settles the places a bracket left shared, in the table of an engine', function (): void {
        $field = array_map(static fn(int $number): Participant => new Participant("p{$number}", "P{$number}"), range(1, 8));
        $engine = new SingleEliminationEngine();
        $state = StageState::start($field);
        while (!$engine->isComplete($state)) {
            $pairing = $engine->pairNextRound($state);
            $state = $state->withRoundPlayed($pairing, array_map(
                static fn(Event $event): Result => new Result($event, $event->getParticipants()[0]),
                $pairing->getEvents()
            ));
        }
        $outcome = $engine->getOutcome($state);
        assert($outcome instanceof StageOutcome);

        // The two who went out in the semifinals share places 3 and 4
        $decision = new Decider(['p4', 'p3'], 'ruling');
        $selection = RankRangeSelector::overall(1, 3)->withDecisions([$decision])->selection($outcome);

        expect(tableDecisionIds($selection->getParticipants()))->toBe(['p1', 'p2', 'p4'])
            ->and($selection->isSettledByDecision($field[0]))->toBeFalse()
            ->and($selection->getDecisionsFor($field[3]))->toBe([$decision]);

        // The finalist and a semifinalist are in different tiers: no decision
        // moves one past the other
        $acrossTiers = RankRangeSelector::overall(1, 3)->withDecisions([new Decider(['p3', 'p2'])]);
        $rejected = tableDecisionRejection(fn(): array => $acrossTiers->select($outcome));
        expect($rejected->getReason())->toBe(RejectedDecisionReason::ParticipantsNotLevel)
            ->and($rejected->getPool())->toBeNull();

        // The table of the outcome takes the same decision for display
        expect(tableDecisionOrder($outcome->getStandings()->withDecisions([$decision])))
            ->toBe(['p1', 'p2', 'p4', 'p3', 'p5', 'p6', 'p7', 'p8']);
    });

    it('settles entries that are level per event played, which the table itself places apart', function (): void {
        // Pool A of three: a1 has 4 points from 2 events. Pool B of five:
        // b1 has 8 from 4. Level per event, apart in the table of totals.
        $entry = static fn(string $id, int $played, float $value): StandingEntry => new StandingEntry(new Participant($id, strtoupper($id)), $played, 0, 0, 0, $value);
        $poolA = [$entry('a1', 2, 4.0), $entry('a2', 2, 1.0), $entry('a3', 2, 0.0)];
        $poolB = [$entry('b1', 4, 8.0), $entry('b2', 4, 6.0), $entry('b3', 4, 3.0), $entry('b4', 4, 1.0), $entry('b5', 4, 0.0)];
        $result = static fn(array $entries): array => [new Result(new Event([$entries[0]->getParticipant(), $entries[1]->getParticipant()]))];

        $combined = [$poolB[0], $poolB[1], $poolA[0], $poolB[2], $poolA[1], $poolB[3], $poolA[2], $poolB[4]];
        $outcome = new StageOutcome(
            new Standings($combined),
            [...$result($poolA), ...$result($poolB)],
            pools: [
                'A' => new StageOutcome(new Standings($poolA), $result($poolA)),
                'B' => new StageOutcome(new Standings($poolB), $result($poolB)),
            ]
        );
        $perEvent = RankRangeSelector::overall(1, 1)->comparedBy(PoolComparison::PerEvent);

        try {
            $perEvent->select($outcome);
            throw new LogicException('The selection was not refused.');
        } catch (UndecidedSelectionException $undecided) {
            // The exception says which kind of tie it is
            expect($undecided->isPerEvent())->toBeTrue()
                ->and($undecided->getPool())->toBeNull()
                ->and($undecided->getRankAcrossPools())->toBeNull()
                ->and($undecided->getContext()['per_event'] ?? null)->toBeTrue()
                ->and($undecided->getMessage())->toContain('the standings per event played');
            $level = $undecided->getParticipantIds();
        }
        sort($level);
        expect($level)->toBe(['a1', 'b1']);

        $decision = new Decider(['a1', 'b1'], 'ruling');
        $selection = $perEvent->withDecisions([$decision])->selection($outcome);
        expect(tableDecisionIds($selection->getParticipants()))->toBe(['a1'])
            ->and($selection->getDecisionsFor($selection->getParticipants()[0]))->toBe([$decision]);

        // They are no tied set of the table of totals, so the table refuses
        // the decision the selector takes, and so does a selection by totals
        expect(tableDecisionRejection(fn(): Standings => $outcome->getStandings()->withDecisions([$decision]))->getReason())
            ->toBe(RejectedDecisionReason::ParticipantsNotLevel)
            ->and(fn(): array => RankRangeSelector::overall(1, 1)->comparedBy(PoolComparison::RawTotals)->withDecisions([$decision])->select($outcome))
            ->toThrow(RejectedDecisionException::class);

        // Per event a decision orders a whole level group and nothing else
        expect(tableDecisionRejection(fn(): array => $perEvent->withDecisions([new Decider(['a1', 'b2'])])->select($outcome))->getReason())
            ->toBe(RejectedDecisionReason::ParticipantsNotLevel);
    });
});

describe('a decision given to a best-of-rank selection', function (): void {
    beforeEach(function (): void {
        // Three pools of three. The entrants placed third: a3 and b3 have a
        // point each and are level; c3 has none. In pool C the first two
        // are level on 4 points.
        $entry = static fn(string $id, float $value): StandingEntry => new StandingEntry(new Participant($id, strtoupper($id)), 2, 0, 0, 0, $value);
        $this->tables = [
            'A' => [$entry('a1', 6.0), $entry('a2', 3.0), $entry('a3', 1.0)],
            'B' => [$entry('b1', 6.0), $entry('b2', 3.0), $entry('b3', 1.0)],
            'C' => [$entry('c1', 4.0), $entry('c2', 4.0), $entry('c3', 0.0)],
        ];
        $pools = [];
        $results = [];
        $all = [];
        foreach ($this->tables as $label => $entries) {
            $played = [new Result(new Event([$entries[0]->getParticipant(), $entries[1]->getParticipant()]))];
            $pools[$label] = new StageOutcome(new Standings($entries), $played);
            $results = [...$results, ...$played];
            $all = [...$all, ...$entries];
        }
        usort($all, static fn(StandingEntry $a, StandingEntry $b): int => $b->getRankingValue() <=> $a->getRankingValue());
        $this->outcome = new StageOutcome(new Standings($all), $results, pools: $pools);
    });

    it('settles the holders of the rank that the comparison leaves level, and marks them', function (): void {
        $selector = BestOfRankSelector::ofRank(3, 1);

        try {
            $selector->select($this->outcome);
            throw new LogicException('The selection was not refused.');
        } catch (UndecidedSelectionException $undecided) {
            expect($undecided->getRankAcrossPools())->toBe(3)
                ->and($undecided->isPerEvent())->toBeFalse()
                ->and($undecided->getParticipantIds())->toBe(['a3', 'b3'])
                ->and($undecided->getReason())->toBe(InvalidConfigurationReason::TieAcrossSelection);
        }

        $decision = new Decider(['b3', 'a3'], 'ruling', 'Decided by the organisers');
        $selection = $selector->withDecisions([$decision])->selection($this->outcome);

        expect(tableDecisionIds($selection->getParticipants()))->toBe(['b3'])
            ->and($selection->getDecisionsFor($selection->getParticipants()[0]))->toBe([$decision])
            ->and(tableDecisionIds($selector->withDecisions([$decision])->select($this->outcome)))->toBe(['b3'])
            // Both selected, in the decided order, with c3 behind them by results
            ->and(tableDecisionIds(BestOfRankSelector::ofRank(3, 3)->withDecisions([$decision])->select($this->outcome)))
            ->toBe(['b3', 'a3', 'c3']);
    });

    it('never moves a holder past one it is not level with', function (): void {
        $pastAResult = BestOfRankSelector::ofRank(3, 1)->withDecisions([new Decider(['c3', 'a3', 'b3'])]);
        $notAHolder = BestOfRankSelector::ofRank(3, 1)->withDecisions([new Decider(['b3', 'a3', 'c2'])]);

        $rejected = tableDecisionRejection(fn(): array => $pastAResult->select($this->outcome));
        expect($rejected->getReason())->toBe(RejectedDecisionReason::ParticipantsNotLevel)
            ->and($rejected->getRankAcrossPools())->toBe(3)
            ->and($rejected->getParticipantIds())->toBe(['a3', 'b3'])
            ->and($rejected->getMessage())->toContain('the entrants placed 3 in their pools');

        $rejected = tableDecisionRejection(fn(): array => $notAHolder->select($this->outcome));
        expect($rejected->getReason())->toBe(RejectedDecisionReason::ParticipantsNotLevel)
            ->and($rejected->getParticipantIds())->toBe(['c2'])
            ->and($rejected->getRankAcrossPools())->toBe(3);
    });

    it('settles who holds the rank in a pool, and marks a holder with both decisions', function (): void {
        // Rank 1: a1 and b1 on 6 are level across pools; in pool C the rank
        // is held by c1 or c2, who are level
        $selector = BestOfRankSelector::ofRank(1, 2);

        try {
            $selector->select($this->outcome);
            throw new LogicException('The selection was not refused.');
        } catch (UndecidedSelectionException $undecided) {
            expect($undecided->getPool())->toBe('C')
                ->and($undecided->getRankAcrossPools())->toBeNull();
        }

        $inPool = new Decider(['c2', 'c1'], 'pool');
        $across = new Decider(['b1', 'a1'], 'across');

        // The pool decided, the holders still level
        try {
            $selector->withDecisions([$inPool])->select($this->outcome);
            throw new LogicException('The selection was not refused.');
        } catch (UndecidedSelectionException $undecided) {
            expect($undecided->getRankAcrossPools())->toBe(1)
                ->and($undecided->getReason())->toBe(InvalidConfigurationReason::TieWithinSelection);
        }

        $selection = BestOfRankSelector::ofRank(1, 3)->withDecisions([$across, $inPool])->selection($this->outcome);
        [$first, $second, $third] = $selection->getParticipants();

        expect(tableDecisionIds($selection->getParticipants()))->toBe(['b1', 'a1', 'c2'])
            ->and($selection->getDecisionsFor($first))->toBe([$across])
            ->and($selection->getDecisionsFor($second))->toBe([$across])
            ->and($selection->getDecisionsFor($third))->toBe([$inPool])
            ->and($selection->getDecisions())->toBe([$across, $inPool]);

        // A holder that both decisions placed has both, the pool's first
        $c2 = $this->tables['C'][1]->getParticipant();
        $entry = static fn(string $id, float $value): StandingEntry => new StandingEntry(new Participant($id, strtoupper($id)), 2, 0, 0, 0, $value);
        $poolC = [$entry('c1', 6.0), $entry('c2', 6.0), $entry('c3', 0.0)];
        $pools = $this->outcome->getPools();
        $pools['C'] = new StageOutcome(new Standings($poolC), $pools['C']->getResults());
        $all = [...$this->tables['A'], ...$this->tables['B'], ...$poolC];
        usort($all, static fn(StandingEntry $a, StandingEntry $b): int => $b->getRankingValue() <=> $a->getRankingValue());
        $level = new StageOutcome(new Standings($all), $this->outcome->getResults(), pools: $pools);

        $acrossThree = new Decider(['c2', 'b1', 'a1'], 'across');
        $both = BestOfRankSelector::ofRank(1, 1)->withDecisions([$acrossThree, $inPool])->selection($level);
        expect(tableDecisionIds($both->getParticipants()))->toBe(['c2'])
            ->and($both->getDecisionsFor($c2))->toBe([$inPool, $acrossThree]);

        // Two of the three level holders are only part of them
        $partOfTheGroup = BestOfRankSelector::ofRank(1, 1)->withDecisions([new Decider(['b1', 'a1']), $inPool]);
        $rejected = tableDecisionRejection(fn(): array => $partOfTheGroup->select($level));
        expect($rejected->getReason())->toBe(RejectedDecisionReason::PartOfLevelSet)
            ->and($rejected->getParticipantIds())->toBe(['c2'])
            ->and($rejected->getRankAcrossPools())->toBe(1);
    });

    it('rejects a decision for a pool table that the pool does not fit, naming the pool', function (): void {
        $selector = BestOfRankSelector::ofRank(1, 1)->withDecisions([new Decider(['c1', 'c3'])]);

        $rejected = tableDecisionRejection(fn(): array => $selector->select($this->outcome));
        expect($rejected->getReason())->toBe(RejectedDecisionReason::ParticipantsNotLevel)
            ->and($rejected->getPool())->toBe('C');

        $unknown = BestOfRankSelector::ofRank(1, 1)->withDecisions([new Decider(['c1', 'zz'])]);
        expect(tableDecisionRejection(fn(): array => $unknown->select($this->outcome))->getReason())
            ->toBe(RejectedDecisionReason::UnknownParticipant);
    });
});

describe('a selection across generated pools with decisions', function (): void {
    it('orders the level holders of a rank as decided, marks them, and refuses what is still undecided', function (): void {
        $selected = 0;
        $refusedInAPool = 0;
        $refusedAcross = 0;
        $settledAcross = 0;
        $withBothMarks = 0;
        $rejected = ['part_of_level_set' => 0, 'participants_not_level' => 0];

        for ($seed = 1; $seed <= 1500; ++$seed) {
            $rng = new Randomizer(new Mt19937($seed));
            $pooled = tableDecisionPooled($rng);
            $perEvent = $rng->getInt(0, 1) === 0;
            $rank = $rng->getInt(1, $pooled['smallest']);
            $count = $rng->getInt(1, count($pooled['pools']));
            $policy = $rng->getInt(0, 2);
            $context = "seed {$seed}, rank {$rank}, count {$count}, policy {$policy}, " . ($perEvent ? 'per event' : 'totals');

            // The oracle, on the rows alone. In each pool: the order with the
            // drawn decisions, and whether the rank is held by an undecided set
            $poolDecisions = [];
            $holders = [];
            $expectedRefusal = null;
            foreach ($pooled['pools'] as $label => $rows) {
                $decisions = tableDecisionDrawn($rng, $rows);
                $poolDecisions = [...$poolDecisions, ...$decisions];
                $holders[] = tableDecisionOracleOrder($rows, $decisions)[$rank - 1];

                foreach (tableDecisionLevelSets($rows) as $start => $ids) {
                    $undecided = count($ids) >= 2
                        && array_filter($decisions, static fn(array $decision): bool => array_diff($ids, $decision) === []) === [];
                    if ($expectedRefusal === null && $undecided && $policy !== 2 && $start < $rank && $start + count($ids) >= $rank) {
                        $expectedRefusal = ['ids' => $ids, 'pool' => $label, 'rank' => null];
                    }
                }
            }

            // Across the pools: the holders as groups that the comparison
            // leaves level, with a drawn decision for some of them
            $groups = tableDecisionComparedGroups($pooled['rows'], $holders, $perEvent);
            $across = [];
            $first = 1;
            foreach ($groups as $index => $group) {
                $last = $first + count($group) - 1;
                if (count($group) >= 2 && $rng->getInt(1, 10) <= 6) {
                    $across[] = $groups[$index] = $rng->shuffleArray($group);
                } elseif (count($group) >= 2 && $expectedRefusal === null && $first <= $count && ($policy === 0 || ($policy === 1 && $last > $count))) {
                    $expectedRefusal = ['ids' => $group, 'pool' => null, 'rank' => $rank];
                }
                $first = $last + 1;
            }

            $base = BestOfRankSelector::ofRank($rank, $count)->comparedBy($perEvent ? PoolComparison::PerEvent : PoolComparison::RawTotals);
            $base = match ($policy) {
                0 => $base,
                1 => $base->withTiesInsideSelectionAccepted(),
                default => $base->withTableOrderAccepted(),
            };
            $given = $rng->shuffleArray([...$poolDecisions, ...$across]);
            $selector = $base->withDecisions(tableDecisionDeciders($given));

            try {
                $selection = $selector->selection($pooled['outcome']);
            } catch (UndecidedSelectionException $undecided) {
                expect($expectedRefusal)->not->toBeNull($context)
                    ->and([
                        'ids' => $undecided->getParticipantIds(),
                        'pool' => $undecided->getPool(),
                        'rank' => $undecided->getRankAcrossPools(),
                    ])->toBe($expectedRefusal, $context)
                    ->and($undecided->isPerEvent())->toBeFalse($context);
                $refusedInAPool += $undecided->getPool() === null ? 0 : 1;
                $refusedAcross += $undecided->getPool() === null ? 1 : 0;

                continue;
            }

            expect($expectedRefusal)->toBeNull($context)
                ->and(tableDecisionIds($selection->getParticipants()))->toBe(array_slice(array_merge(...$groups), 0, $count), $context)
                ->and(tableDecisionIds($selector->select($pooled['outcome'])))->toBe(tableDecisionIds($selection->getParticipants()), $context);

            // A selected holder has the decision that made it the holder in
            // its pool, then the one that placed it among the holders
            foreach ($selection->getParticipants() as $participant) {
                $naming = static fn(array $decisions): array => array_values(array_filter(
                    $decisions,
                    static fn(array $decision): bool => in_array($participant->getId(), $decision, true)
                ));
                $marks = [...$naming($poolDecisions), ...$naming($across)];

                expect(array_map(
                    static fn(Decider $decider): array => $decider->getParticipantIds(),
                    $selection->getDecisionsFor($participant)
                ))->toBe($marks, $context);
                $settledAcross += $naming($across) === [] ? 0 : 1;
                $withBothMarks += count($marks) === 2 ? 1 : 0;
            }
            ++$selected;

            // The same selection with one decision that is wrong: part of a
            // group of level holders, or a group with a holder it is not
            // level with. Every order is accepted, so that nothing else stops it
            foreach ($groups as $group) {
                $others = array_values(array_diff($holders, $group));
                $wrong = match (true) {
                    count($group) >= 3 => ['part_of_level_set', array_slice($rng->shuffleArray($group), 1)],
                    count($group) === 2 && $others !== [] => ['participants_not_level', [...$group, $others[0]]],
                    default => null,
                };
                if ($wrong === null) {
                    continue;
                }

                // Without the decisions for the holders it names: decided twice comes first
                $withoutIt = array_values(array_filter($across, static fn(array $decision): bool => array_intersect($wrong[1], $decision) === []));
                $refusing = $base->withTableOrderAccepted()->withDecisions(tableDecisionDeciders([...$poolDecisions, ...$withoutIt, $wrong[1]]));
                $refusal = tableDecisionRejection(fn(): array => $refusing->select($pooled['outcome']));

                expect($refusal->getReason()->value)->toBe($wrong[0], $context)
                    ->and($refusal->getRankAcrossPools())->toBe($rank, $context)
                    ->and($refusal->getPool())->toBeNull($context)
                    ->and($refusal->getDecider()->getParticipantIds())->toBe($wrong[1], $context);
                ++$rejected[$wrong[0]];

                break;
            }
        }

        expect($selected)->toBeGreaterThan(300)
            ->and($refusedInAPool)->toBeGreaterThan(70)
            ->and($refusedAcross)->toBeGreaterThan(40)
            ->and($settledAcross)->toBeGreaterThan(100)
            ->and($withBothMarks)->toBeGreaterThan(10)
            ->and($rejected['part_of_level_set'])->toBeGreaterThan(10)
            ->and($rejected['participants_not_level'])->toBeGreaterThan(40);
    });

    it('orders entries that are level per event played as decided, marks them, and refuses what is still undecided', function (): void {
        $selected = 0;
        $refused = 0;
        $marked = 0;
        $apartInTheTable = 0;
        $rejected = ['part_of_level_set' => 0, 'participants_not_level' => 0];

        for ($seed = 1; $seed <= 900; ++$seed) {
            $rng = new Randomizer(new Mt19937($seed));
            $pooled = tableDecisionPooled($rng);
            $everyone = array_column($pooled['rows'], 'id');
            $from = $rng->getInt(1, count($everyone));
            $to = $rng->getInt($from, min(count($everyone), $from + 3));
            $policy = $rng->getInt(0, 2);
            $context = "seed {$seed}, ranks {$from} to {$to}, policy {$policy}";

            // The oracle: every row compared per event played, as groups, with
            // a drawn decision for some of the groups of two or more
            $groups = tableDecisionComparedGroups($pooled['rows'], $everyone, true);
            $decisions = [];
            $expectedRefusal = null;
            $first = 1;
            foreach ($groups as $index => $group) {
                $last = $first + count($group) - 1;
                if (count($group) >= 2 && $rng->getInt(1, 10) <= 6) {
                    $decisions[] = $groups[$index] = $rng->shuffleArray($group);
                } elseif (
                    count($group) >= 2 && $expectedRefusal === null && $last >= $from && $first <= $to
                    && ($policy === 0 || ($policy === 1 && !($first >= $from && $last <= $to)))
                ) {
                    $expectedRefusal = ['ids' => $group, 'first' => $first, 'last' => $last];
                }
                $first = $last + 1;
            }

            $base = RankRangeSelector::overall($from, $to)->comparedBy(PoolComparison::PerEvent);
            $base = match ($policy) {
                0 => $base,
                1 => $base->withTiesInsideSelectionAccepted(),
                default => $base->withTableOrderAccepted(),
            };
            $selector = $base->withDecisions(tableDecisionDeciders($rng->shuffleArray($decisions)));

            try {
                $selection = $selector->selection($pooled['outcome']);
            } catch (UndecidedSelectionException $undecided) {
                expect($expectedRefusal)->not->toBeNull($context)
                    ->and([
                        'ids' => $undecided->getParticipantIds(),
                        'first' => $undecided->getFirstPlace(),
                        'last' => $undecided->getLastPlace(),
                    ])->toBe($expectedRefusal, $context)
                    ->and($undecided->isPerEvent())->toBeTrue($context)
                    ->and($undecided->getPool())->toBeNull($context)
                    ->and($undecided->getRankAcrossPools())->toBeNull($context);
                ++$refused;

                continue;
            }

            expect($expectedRefusal)->toBeNull($context)
                ->and(tableDecisionIds($selection->getParticipants()))
                ->toBe(array_slice(array_merge(...$groups), $from - 1, $to - $from + 1), $context);
            foreach ($selection->getParticipants() as $participant) {
                $naming = array_values(array_filter(
                    $decisions,
                    static fn(array $decision): bool => in_array($participant->getId(), $decision, true)
                ));
                expect(array_map(
                    static fn(Decider $decider): array => $decider->getParticipantIds(),
                    $selection->getDecisionsFor($participant)
                ))->toBe($naming, $context);
                $marked += $naming === [] ? 0 : 1;
            }
            ++$selected;

            // A decided group whose members the table of totals places apart
            // is one the table itself would not take a decision for
            foreach ($decisions as $decision) {
                try {
                    $pooled['outcome']->getStandings()->withDecisions(tableDecisionDeciders([$decision]));
                } catch (RejectedDecisionException) {
                    ++$apartInTheTable;
                }
            }

            // One wrong decision: a group without one of its members, or two
            // groups as one order
            $big = array_values(array_filter($groups, static fn(array $group): bool => count($group) >= 2));
            $wrong = match (true) {
                $big !== [] && $seed % 2 === 0 => ['part_of_level_set', array_slice($rng->shuffleArray($big[0]), 1)],
                count($groups) >= 2 => ['participants_not_level', [...$groups[0], ...$groups[1]]],
                default => null,
            };
            if ($wrong !== null) {
                $refusing = $base->withTableOrderAccepted()->withDecisions(tableDecisionDeciders([$wrong[1]]));
                $refusal = tableDecisionRejection(fn(): array => $refusing->select($pooled['outcome']));

                expect($refusal->getReason()->value)->toBe($wrong[0], $context)
                    ->and($refusal->getPool())->toBeNull($context)
                    ->and($refusal->getRankAcrossPools())->toBeNull($context);
                ++$rejected[$wrong[0]];
            }
        }

        expect($selected)->toBeGreaterThan(300)
            ->and($refused)->toBeGreaterThan(150)
            ->and($marked)->toBeGreaterThan(300)
            ->and($apartInTheTable)->toBeGreaterThan(50)
            ->and($rejected['part_of_level_set'])->toBeGreaterThan(100)
            ->and($rejected['participants_not_level'])->toBeGreaterThan(100);
    });
});

describe('a decision on the place below which a larger pool is left out', function (): void {
    beforeEach(function (): void {
        // Pool A of three and pool B of four, so the entrant placed fourth in
        // B is left out with its results. In B the third and the fourth are
        // level: b1 beat b3 and drew with b4, b2 beat b4 and drew with b3.
        // Without b4, b1 has two wins; without b3, a win and a draw. a1 has
        // a win by two and a draw, and stands between the two
        $member = static fn(string $id): Participant => new Participant($id, strtoupper($id));
        $this->a = array_map($member, ['a1', 'a2', 'a3']);
        $this->b = array_map($member, ['b1', 'b2', 'b3', 'b4']);
        $played = static fn(Participant $first, Participant $second, int $for, int $against): Result => new Result(
            new Event([$first, $second]),
            $for === $against ? null : $first,
            [$first->getId() => $for, $second->getId() => $against]
        );
        [$a1, $a2, $a3] = $this->a;
        [$b1, $b2, $b3, $b4] = $this->b;
        $resultsA = [$played($a1, $a2, 2, 0), $played($a1, $a3, 0, 0), $played($a2, $a3, 1, 0)];
        $resultsB = [
            $played($b1, $b2, 1, 0), $played($b1, $b3, 1, 0), $played($b1, $b4, 0, 0),
            $played($b2, $b3, 0, 0), $played($b2, $b4, 1, 0), $played($b3, $b4, 0, 0),
        ];

        $calculator = new StandingsCalculator();
        $this->outcome = StageOutcome::combining([
            'A' => new StageOutcome($calculator->calculate($this->a, $resultsA), $resultsA, entrants: $this->a),
            'B' => new StageOutcome($calculator->calculate($this->b, $resultsB), $resultsB, entrants: $this->b),
        ], $calculator);
        $this->selector = BestOfRankSelector::ofRank(1, 1)->comparedBy(PoolComparison::DiscardLowest);
    });

    it('is refused until the level entries on both sides of that place are decided', function (): void {
        try {
            $this->selector->select($this->outcome);
        } catch (UndecidedSelectionException $undecided) {
            expect($undecided->getParticipantIds())->toBe(['b3', 'b4'])
                ->and([$undecided->getFirstPlace(), $undecided->getLastPlace()])->toBe([3, 4])
                ->and($undecided->getPool())->toBe('B')
                ->and($undecided->getRankAcrossPools())->toBeNull()
                ->and($undecided->isPerEvent())->toBeFalse();

            return;
        }

        throw new LogicException('The selection was not refused.');
    });

    it('leaves out the entrant the decision places below it, which decides who is selected', function (): void {
        $fourthIsB4 = new Decider(['b3', 'b4'], 'ruling');
        $fourthIsB3 = new Decider(['b4', 'b3'], 'ruling');

        expect(tableDecisionIds($this->selector->withDecisions([$fourthIsB4])->select($this->outcome)))->toBe(['b1'])
            ->and(tableDecisionIds($this->selector->withDecisions([$fourthIsB3])->select($this->outcome)))->toBe(['a1']);
    });

    it('marks every selected place with that decision: each is compared without the entrant it left out', function (): void {
        $fourthIsB3 = new Decider(['b4', 'b3'], 'ruling', 'Decided by the organisers');

        $selection = BestOfRankSelector::ofRank(1, 2)
            ->comparedBy(PoolComparison::DiscardLowest)
            ->withDecisions([$fourthIsB3])
            ->selection($this->outcome);

        expect(tableDecisionIds($selection->getParticipants()))->toBe(['a1', 'b1'])
            ->and($selection->getDecisionsFor($this->a[0]))->toBe([$fourthIsB3])
            ->and($selection->getDecisionsFor($this->b[0]))->toBe([$fourthIsB3])
            ->and($selection->getDecisions())->toBe([$fourthIsB3]);

        // Under another comparison nobody is left out, and the same decision
        // settles nothing about the first places
        $byTotals = BestOfRankSelector::ofRank(1, 2)
            ->comparedBy(PoolComparison::RawTotals)
            ->withDecisions([$fourthIsB3])
            ->selection($this->outcome);
        expect(tableDecisionIds($byTotals->getParticipants()))->toBe(['b1', 'a1'])
            ->and($byTotals->getDecisions())->toBe([]);
    });

    it('marks a holder once when the decision that made it the holder is the one on that place', function (): void {
        // The third of each pool: in B that is b3 or b4, by the same decision
        // that says who is left out. Without b3, b4 is level with a3, and
        // that tie between the two selected is accepted here
        $decision = new Decider(['b4', 'b3']);
        $selection = BestOfRankSelector::ofRank(3, 2)
            ->comparedBy(PoolComparison::DiscardLowest)
            ->withTiesInsideSelectionAccepted()
            ->withDecisions([$decision])
            ->selection($this->outcome);

        // Level holders are in the order of the outcome's table, which has
        // b4 above a3 on the totals of every result
        expect(tableDecisionIds($selection->getParticipants()))->toBe(['b4', 'a3'])
            ->and($selection->getDecisionsFor($this->b[3]))->toBe([$decision])
            ->and($selection->getDecisionsFor($this->a[2]))->toBe([$decision]);
    });
});

describe('the decisions of the table of a pooled outcome', function (): void {
    it('are not carried by a combined table, which is calculated afresh', function (): void {
        ['pool' => $pool, 'results' => $results] = tableDecisionDrawnPool();
        $decision = new Decider(['p3', 'p1', 'p4', 'p2']);
        $decided = new StageOutcome(new StandingsCalculator()->calculate($pool, $results)->withDecisions([$decision]), $results, entrants: $pool);

        $combined = StageOutcome::combining(['A' => $decided]);

        expect($combined->getStandings()->getDecisions())->toBe([])
            ->and($combined->getPool('A')?->getStandings()->getDecisions())->toBe([$decision])
            // The per-group selection reads the pool's table, decided
            ->and(tableDecisionIds(RankRangeSelector::topPerGroup(2)->select($combined)))->toBe(['p3', 'p1'])
            // The overall selection reads the combined table, which is not
            ->and(fn(): array => RankRangeSelector::overall(1, 2)->select($combined))->toThrow(UndecidedSelectionException::class)
            ->and(tableDecisionIds(RankRangeSelector::overall(1, 2)->withDecisions([$decision])->select($combined)))->toBe(['p3', 'p1']);
    });

    it('are read by an overall selection of totals and not by one per event played', function (): void {
        // Two pools of two, every entry level; the table of the outcome is
        // built by hand and carries a decision for all four
        $entry = static fn(string $id): StandingEntry => new StandingEntry(new Participant($id, strtoupper($id)), 1, 0, 1, 0, 1.0);
        $entries = array_map($entry, ['a1', 'a2', 'b1', 'b2']);
        $result = static fn(StandingEntry $first, StandingEntry $second): array => [new Result(new Event([$first->getParticipant(), $second->getParticipant()]))];
        $decision = new Decider(['b2', 'a2', 'b1', 'a1'], 'on the table');
        $outcome = new StageOutcome(
            new Standings($entries)->withDecisions([$decision]),
            [...$result($entries[0], $entries[1]), ...$result($entries[2], $entries[3])],
            pools: [
                'A' => new StageOutcome(new Standings([$entries[0], $entries[1]]), $result($entries[0], $entries[1])),
                'B' => new StageOutcome(new Standings([$entries[2], $entries[3]]), $result($entries[2], $entries[3])),
            ]
        );

        $byTotals = RankRangeSelector::overall(1, 2)->selection($outcome);
        expect(tableDecisionIds($byTotals->getParticipants()))->toBe(['b2', 'a2'])
            ->and($byTotals->getDecisions())->toBe([$decision]);

        // Per event played the selector orders the entries itself: the tie is
        // reported, and the decision is given to the selector
        $perEvent = RankRangeSelector::overall(1, 2)->comparedBy(PoolComparison::PerEvent);
        expect(fn(): array => $perEvent->select($outcome))->toThrow(UndecidedSelectionException::class, 'the standings per event played')
            ->and(tableDecisionIds($perEvent->withDecisions([$decision])->select($outcome)))->toBe(['b2', 'a2']);
    });
});

describe('decisions as plain data', function (): void {
    it('round-trip by themselves, as an array and as JSON', function (): void {
        $decision = new Decider(['p3', 'p1', 'p4', 'p2'], 'drawn', 'Drawn by the committee');

        $stored = json_encode($decision->toArray(), JSON_THROW_ON_ERROR);
        $read = json_decode($stored, true, 512, JSON_THROW_ON_ERROR);
        assert(is_array($read));

        expect($decision->toArray())->toBe([
            'format_version' => 1,
            'participants' => ['p3', 'p1', 'p4', 'p2'],
            'label' => 'drawn',
            'reason' => 'Drawn by the committee',
        ])->and(Decider::fromArray($read))->toEqual($decision);

        // What was read back decides the table as what was written did
        ['pool' => $pool, 'results' => $results] = tableDecisionDrawnPool();
        expect(tableDecisionOrder(new StandingsCalculator()->calculate($pool, $results)->withDecisions([Decider::fromArray($read)])))
            ->toBe(['p3', 'p1', 'p4', 'p2']);
    });

    it('round-trip in a selector, under the key decisions, written only when there are any', function (): void {
        ['outcome' => $outcome] = tableDecisionDrawnPool();
        $decision = new Decider(['p3', 'p1', 'p4', 'p2'], 'drawn', null);
        $rankRange = RankRangeSelector::topPerGroup(2)->withDecisions([$decision]);
        $bestOfRank = BestOfRankSelector::ofRank(3, 2)->withDecisions([$decision, new Decider(['x', 'y'])]);

        expect($rankRange->toArray())->toBe([
            'format_version' => 1,
            'mode' => 'per-group',
            'from' => 1,
            'to' => 2,
            'decisions' => [['participants' => ['p3', 'p1', 'p4', 'p2'], 'label' => 'drawn', 'reason' => null]],
        ])->and($bestOfRank->toArray())->toBe([
            'format_version' => 1,
            'rank' => 3,
            'count' => 2,
            'decisions' => [
                ['participants' => ['p3', 'p1', 'p4', 'p2'], 'label' => 'drawn', 'reason' => null],
                ['participants' => ['x', 'y'], 'label' => null, 'reason' => null],
            ],
        ]);

        $stored = json_decode(json_encode($rankRange->toArray(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        assert(is_array($stored));
        $read = RankRangeSelector::fromArray($stored);

        expect($read)->toEqual($rankRange)
            ->and(tableDecisionIds($read->select($outcome)))->toBe(['p3', 'p1'])
            ->and(BestOfRankSelector::fromArray($bestOfRank->toArray()))->toEqual($bestOfRank)
            // No decision, no key: the form of a selector without one is as it was
            ->and($rankRange->withDecisions([])->toArray())->toBe(['format_version' => 1, 'mode' => 'per-group', 'from' => 1, 'to' => 2])
            ->and(BestOfRankSelector::ofRank(3, 2)->toArray())->toBe(['format_version' => 1, 'rank' => 3, 'count' => 2])
            // The selector it was called on is not changed
            ->and(RankRangeSelector::topPerGroup(2)->toArray())->toBe(['format_version' => 1, 'mode' => 'per-group', 'from' => 1, 'to' => 2]);
    });

    it('keep the other choices of the selector they are given to', function (): void {
        $decision = new Decider(['a', 'b']);

        expect(RankRangeSelector::overall(1, 2)->comparedBy(PoolComparison::PerEvent)->withTableOrderAccepted()->withDecisions([$decision])->toArray())
            ->toBe([
                'format_version' => 1,
                'mode' => 'overall',
                'from' => 1,
                'to' => 2,
                'ties' => 'accept-table-order',
                'comparison' => 'per-event',
                'decisions' => [['participants' => ['a', 'b'], 'label' => null, 'reason' => null]],
            ])
            // And the choices made after them keep the decisions
            ->and(RankRangeSelector::overall(1, 2)->withDecisions([$decision])->comparedBy(PoolComparison::RawTotals)->withTiesInsideSelectionAccepted()->toArray()['decisions'] ?? null)
            ->toBe([['participants' => ['a', 'b'], 'label' => null, 'reason' => null]])
            ->and(BestOfRankSelector::ofRank(1, 1)->withDecisions([$decision])->comparedBy(PoolComparison::DiscardLowest)->withTableOrderAccepted()->toArray())
            ->toBe([
                'format_version' => 1,
                'rank' => 1,
                'count' => 1,
                'comparison' => 'discard-lowest',
                'ties' => 'accept-table-order',
                'decisions' => [['participants' => ['a', 'b'], 'label' => null, 'reason' => null]],
            ]);
    });

    it('are refused where they are not a list of deciders', function (mixed $decisions, string $fault): void {
        foreach ([
            fn(): object => RankRangeSelector::fromArray(['mode' => 'overall', 'to' => 2, 'decisions' => $decisions]),
            fn(): object => BestOfRankSelector::fromArray(['rank' => 1, 'count' => 1, 'decisions' => $decisions]),
        ] as $read) {
            try {
                $read();
            } catch (InvalidConfigurationException $refused) {
                expect($refused->getReason())->toBe(InvalidConfigurationReason::WrongValueType)
                    ->and($refused->getMessage())->toContain($fault);

                continue;
            }

            throw new LogicException('The decisions were read.');
        }
    })->with([
        'a string' => ['p1, p2', 'must be a list'],
        'a map' => [['first' => ['participants' => ['a', 'b']]], 'must be a list'],
        'a list of strings' => [['p1'], 'A decision must be an array, string given'],
        'a decider without participants' => [[['label' => 'drawn']], 'Decider data requires a list of participant IDs'],
        'a decider with a key it does not have' => [[['participants' => ['a', 'b'], 'method' => 'lots']], "Unknown key in decider data: 'method'"],
        'a decider that lists a participant twice' => [[['participants' => ['a', 'a']]], 'A decider lists participant a twice'],
    ]);

    it('are refused by withDecisions() when an entry is not a decider', function (): void {
        // What a caller holds who forgot to read the stored arrays
        $notDeciders = json_decode((string) json_encode([new Decider(['a', 'b'])->toArray()]), true);
        assert(is_array($notDeciders));

        expect(fn(): object => RankRangeSelector::topPerGroup(1)->withDecisions($notDeciders))
            ->toThrow(InvalidConfigurationException::class, 'Each decision of a rank range selector must be a Decider')
            ->and(fn(): object => BestOfRankSelector::ofRank(1, 1)->withDecisions($notDeciders))
            ->toThrow(InvalidConfigurationException::class, 'Each decision of a best-of-rank selector must be a Decider');
    });
});
