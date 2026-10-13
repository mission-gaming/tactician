<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Decider;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\NoValidPairingException;
use MissionGaming\Tactician\Scheduling\DoubleEliminationEngine;
use MissionGaming\Tactician\Scheduling\EliminationOptions;
use MissionGaming\Tactician\Scheduling\SingleEliminationEngine;
use MissionGaming\Tactician\Stage\MatchOutcomeSelector;
use MissionGaming\Tactician\Stage\RankRangeSelector;
use MissionGaming\Tactician\Stage\StageEngineInterface;
use MissionGaming\Tactician\Stage\StageOutcome;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Standings\StandingEntry;
use Random\Engine\Mt19937;
use Random\Randomizer;

// The table of an elimination outcome is the bracket placement: rank 1 is
// the entrant who advanced from the last tie, rank 2 the one it beat there,
// and everyone else is placed by the round that put them out, the latest
// first. The suite plays brackets with outcomes drawn from a seeded
// generator and holds every outcome to a placement it works out for itself,
// from the rounds as they were played: it never asks the engine who went
// out when.

/**
 * A field whose labels and seed attributes run against list order, so that a
 * table that read either would not be in the order the tests expect.
 *
 * @return list<Participant>
 */
function placementField(int $count): array
{
    $participants = [];
    for ($i = 1; $i <= $count; ++$i) {
        $participants[] = new Participant("p{$i}", 'Entrant ' . chr(90 - ($i % 26)) . ($count - $i), $count + 1 - $i);
    }

    return $participants;
}

/**
 * Play a bracket to the end and keep the books of a placement.
 *
 * Every tie is decided by the generator. In a two-legged bracket the legs
 * are drawn one by one, so a tie can be won 2-0, won with a drawn leg, or
 * finish level and go to a tie decision that the generator also draws.
 *
 * A tie between two entrants who are both out already is a placement tie
 * (the third-place tie of a single elimination bracket): the books know it
 * by that alone, and never by what the engine says of an event.
 *
 * @param list<Participant> $field
 * @return array{outcome: StageOutcome, tiers: list<list<string>>, lost: array<string, int>, rounds: list<list<string>>, placementTies: list<array{string, string}>}
 *                                                                                                                                                             The tiers are the ids of each
 *                                                                                                                                                             placement tier, best first; lost
 *                                                                                                                                                             is how many ties each entrant did
 *                                                                                                                                                             not advance from; rounds is the
 *                                                                                                                                                             events of each round as `a v b`;
 *                                                                                                                                                             a placement tie is the id of the
 *                                                                                                                                                             entrant who advanced from it and
 *                                                                                                                                                             of the other
 * @throws InvalidConfigurationException When the engine refuses the recorded state
 * @throws NoValidPairingException Never: a bracket engine does not throw it
 */
function playForPlacement(StageEngineInterface $engine, array $field, int $seed, int $legsPerTie, int $lossesToGoOut): array
{
    $randomizer = new Randomizer(new Mt19937($seed));
    $state = StageState::start($field);

    $lost = array_fill_keys(array_map(fn(Participant $participant): string => $participant->getId(), $field), 0);
    /** @var array<string, int> $wentOutIn The round of the tie that put each entrant out */
    $wentOutIn = [];
    $lastAdvancer = null;
    $placementTies = [];
    $rounds = [];

    while (!$engine->isComplete($state)) {
        expect(count($rounds))->toBeLessThan(4 * count($field) + 4, 'The bracket does not come to an end.');
        $pairing = $engine->pairNextRound($state);

        $results = [];
        $legsByTie = [];
        foreach ($pairing->getEvents() as $event) {
            [$first, $second] = $event->getParticipants();
            $ids = [$first->getId(), $second->getId()];
            sort($ids, SORT_STRING);
            $legsByTie[implode(' ', $ids)][] = $event;
        }

        foreach ($legsByTie as $legs) {
            [$first, $second] = $legs[0]->getParticipants();
            $legWins = [$first->getId() => 0, $second->getId() => 0];
            $tieResults = [];
            foreach ($legs as $leg) {
                $draw = $legsPerTie === 2 ? $randomizer->getInt(0, 2) : $randomizer->getInt(0, 1);
                $winner = [$first, $second, null][$draw];
                if ($winner !== null) {
                    ++$legWins[$winner->getId()];
                }
                $tieResults[] = [$leg, $winner];
            }

            $advancer = match ($legWins[$first->getId()] <=> $legWins[$second->getId()]) {
                1 => $first,
                -1 => $second,
                default => $randomizer->getInt(0, 1) === 0 ? $first : $second,
            };
            $level = $legWins[$first->getId()] === $legWins[$second->getId()];

            foreach ($tieResults as $index => [$leg, $winner]) {
                // The decision of a level tie is recorded on its last leg
                $decider = $level && $index === count($tieResults) - 1
                    ? new Decider([$advancer->getId()])
                    : null;
                $results[] = new Result($leg, $winner, [], [], null, $decider);
            }

            $other = $advancer->getId() === $first->getId() ? $second : $first;

            // A tie between two entrants who are both out puts nobody
            // out: it decides which of the two is placed above the other
            if (isset($wentOutIn[$first->getId()], $wentOutIn[$second->getId()])) {
                ++$lost[$other->getId()];
                $placementTies[] = [$advancer->getId(), $other->getId()];

                continue;
            }

            if (++$lost[$other->getId()] >= $lossesToGoOut) {
                $wentOutIn[$other->getId()] = $pairing->getRoundNumber();
            }
            $lastAdvancer = $advancer;
        }

        $rounds[] = array_values(array_map(
            fn(Event $event): string => implode(' v ', array_map(fn(Participant $participant): string => $participant->getId(), $event->getParticipants())),
            $pairing->getEvents()
        ));
        $state = $state->withRoundPlayed($pairing, $results);
    }

    assert($lastAdvancer !== null);
    $outcome = $engine->getOutcome($state);
    assert($outcome !== null);

    // The loser of the last tie is out, whatever its count: a double
    // elimination bracket without a reset ends on its first grand final
    $lastRound = $state->getLastRound();
    assert($lastRound !== null);
    foreach ($lastRound->getEvents() as $event) {
        foreach ($event->getParticipants() as $participant) {
            // The entrants of a placement tie went out in an earlier round
            if ($participant->getId() !== $lastAdvancer->getId() && !isset($wentOutIn[$participant->getId()])) {
                $wentOutIn[$participant->getId()] = $lastRound->getRoundNumber();
            }
        }
    }
    unset($wentOutIn[$lastAdvancer->getId()]);

    $byRound = [];
    foreach ($wentOutIn as $id => $round) {
        $byRound[$round][] = $id;
    }
    krsort($byRound);

    $tiers = [[$lastAdvancer->getId()]];
    foreach ($byRound as $ids) {
        sort($ids, SORT_STRING);

        // A placement tie cuts the tier of its two entrants in two, the
        // one who advanced from it first. It is a tie between the only
        // two entrants of a tier, or the books and the bracket disagree
        foreach ($placementTies as $placementTie) {
            $sorted = $placementTie;
            sort($sorted, SORT_STRING);
            if (array_intersect($sorted, $ids) !== []) {
                expect($ids)->toBe($sorted, 'A placement tie between entrants who do not make up a tier.');
                $tiers[] = [$placementTie[0]];
                $ids = [$placementTie[1]];
            }
        }

        $tiers[] = $ids;
    }

    return ['outcome' => $outcome, 'tiers' => $tiers, 'lost' => $lost, 'rounds' => $rounds, 'placementTies' => $placementTies];
}

/**
 * The ids of the outcome's table cut into its tiers, each sorted.
 *
 * @return list<list<string>>
 */
function placementTiersOf(StageOutcome $outcome): array
{
    $standings = $outcome->getStandings();
    $tiers = [];
    foreach (array_values($standings->getEntries()) as $index => $entry) {
        $tiers[$standings->getTiers()[$index]][] = $entry->getParticipant()->getId();
    }

    return array_values(array_map(function (array $ids): array {
        sort($ids, SORT_STRING);

        return $ids;
    }, $tiers));
}

/**
 * What must hold of every finished bracket's table.
 *
 * @param list<Participant> $field
 * @param list<list<string>> $expectedTiers
 * @throws InvalidConfigurationException When a selector refuses the outcome
 */
function expectPlacementTable(StageOutcome $outcome, array $field, array $expectedTiers, string $context): void
{
    $standings = $outcome->getStandings();
    $entries = array_values($standings->getEntries());
    $tiers = $standings->getTiers();
    $position = array_flip(array_map(fn(Participant $participant): string => $participant->getId(), $field));

    // The table is the placement: the same tiers in the same order
    expect(placementTiersOf($outcome))->toBe($expectedTiers, $context);

    // Rank 1 is the entrant who advanced from the last tie, rank 2 the
    // one it beat there, and both are what the outcome selectors read
    // from the results
    expect($entries[0]->getParticipant()->getId())
        ->toBe(MatchOutcomeSelector::winners()->select($outcome)[0]->getId(), $context);
    expect($entries[1]->getParticipant()->getId())
        ->toBe(MatchOutcomeSelector::losers()->select($outcome)[0]->getId(), $context);

    // Inside a tier the records decide, and position where they do not
    foreach ($entries as $index => $entry) {
        $next = $entries[$index + 1] ?? null;
        if ($next === null || $tiers[$index] !== $tiers[$index + 1]) {
            continue;
        }

        expect($entry->getRankingValue() >= $next->getRankingValue())->toBeTrue($context);
        if ($entry->isLevelWith($next)) {
            expect($position[$entry->getParticipant()->getId()])
                ->toBeLessThan($position[$next->getParticipant()->getId()], $context);
        }
    }

    // No tied set crosses a tier: the bracket decided those places
    foreach ($standings->getTiedSets() as $tiedSet) {
        expect(array_unique(array_slice($tiers, $tiedSet->getFirstPosition() - 1, count($tiedSet))))->toHaveCount(1, $context);
    }

    // So the first two places are always decided, and a selection of them
    // is never refused
    expect(array_map(fn(Participant $participant): string => $participant->getId(), RankRangeSelector::overall(1, 2)->select($outcome)))
        ->toBe([$entries[0]->getParticipant()->getId(), $entries[1]->getParticipant()->getId()], $context);

    // The outcome carries the entrant list it was ordered by
    expect(array_map(fn(Participant $participant): string => $participant->getId(), $outcome->getEntrants()))
        ->toBe(array_keys($position), $context);
}

describe('the table of an elimination outcome', function (): void {
    // 31 field sizes and 40 seeds: 1,240 brackets for each variant. Every
    // size that is not a power of two has byes.
    it('is the bracket placement of a single elimination bracket of any size', function (string $variant): void {
        $options = match ($variant) {
            'fixed path' => new EliminationOptions(),
            're-seeded' => new EliminationOptions(reseedEachRound: true),
            'two legs' => new EliminationOptions(legsPerTie: 2),
            'two legs, re-seeded' => new EliminationOptions(legsPerTie: 2, reseedEachRound: true),
            default => throw new UnexpectedValueException($variant),
        };
        $engine = new SingleEliminationEngine($options);
        $brackets = 0;

        for ($size = 2; $size <= 32; ++$size) {
            $field = placementField($size);
            $bracketRounds = (int) ceil(log($size, 2));

            for ($seed = 1; $seed <= 40; ++$seed) {
                $played = playForPlacement($engine, $field, $seed * 100 + $size, $options->legsPerTie, 1);
                $context = "{$variant}, n={$size}, seed {$seed}";

                expectPlacementTable($played['outcome'], $field, $played['tiers'], $context);

                // The tiers have the sizes a bracket has: one, one, then
                // as many as went out in each earlier round
                $sizes = array_map(count(...), $played['tiers']);
                expect(count($sizes))->toBe($bracketRounds + 1, $context);
                expect(array_sum($sizes))->toBe($size, $context);
                expect(array_slice($sizes, 0, 2))->toBe([1, 1], $context);
                ++$brackets;
            }
        }

        expect($brackets)->toBe(1240);
    })->with([['fixed path'], ['re-seeded'], ['two legs'], ['two legs, re-seeded']]);

    // 18 field sizes and 6 seeds: 108 brackets for each variant, each
    // played a second time without the option. A field of two is refused.
    // The sizes are every one from 3 to 17, which is every pattern of byes
    // in a bracket of 4, 8 and 16 and the first size of a bracket of 32,
    // and 24, 31 and 32 of that bracket.
    //
    // The sweep was first written over every size from 3 to 32 with 40
    // seeds, 1,200 brackets for each variant, and passed; it took half a
    // minute of every run. This one is held to the same four faults put
    // into the engine one at a time, and fails for each in all four
    // variants as the wider one did: no third-place tie paired, the tier
    // of the semifinals left whole, the two entrants of the tie placed the
    // wrong way round, and the last round taken as complete before the tie
    // is decided.
    it('gives places 3 and 4 to the two entrants of the third-place tie, and changes no other place', function (string $variant): void {
        [$legs, $reseed] = match ($variant) {
            'fixed path' => [1, false],
            're-seeded' => [1, true],
            'two legs' => [2, false],
            'two legs, re-seeded' => [2, true],
            default => throw new UnexpectedValueException($variant),
        };
        $engine = new SingleEliminationEngine(new EliminationOptions($legs, $reseed, true, true));
        $engineWithout = new SingleEliminationEngine(new EliminationOptions($legs, $reseed));
        $brackets = 0;
        $decidedAgainstTheRecords = 0;

        foreach ([...range(3, 17), 24, 31, 32] as $size) {
            $field = placementField($size);
            $bracketRounds = (int) ceil(log($size, 2));

            for ($seed = 1; $seed <= 6; ++$seed) {
                $played = playForPlacement($engine, $field, $seed * 100 + $size, $legs, 1);
                $context = "{$variant}, n={$size}, seed {$seed}";

                expectPlacementTable($played['outcome'], $field, $played['tiers'], $context);

                // One tie between two entrants who were out, in every
                // field but that of three, and it is in the last round
                expect($played['placementTies'])->toHaveCount($size === 3 ? 0 : 1, $context)
                    ->and($played['rounds'])->toHaveCount($bracketRounds, $context);

                // The first four places are a tier each (three, in a
                // field of three), and no tied set reaches them
                $sizes = array_map(count(...), $played['tiers']);
                $decided = min(4, $size);
                expect(count($sizes))->toBe($bracketRounds + ($size === 3 ? 1 : 2), $context)
                    ->and(array_sum($sizes))->toBe($size, $context)
                    ->and(array_slice($sizes, 0, $decided))->toBe(array_fill(0, $decided, 1), $context);

                $standings = $played['outcome']->getStandings();
                $entries = array_values($standings->getEntries());
                foreach ($played['placementTies'] as [$advancerId, $otherId]) {
                    expect($entries[2]->getParticipant()->getId())->toBe($advancerId, $context)
                        ->and($entries[3]->getParticipant()->getId())->toBe($otherId, $context);

                    // The tie decides, not the records: the sweep counts
                    // the brackets in which the entrant placed fourth has
                    // the better record of the two
                    $decidedAgainstTheRecords += $entries[3]->getRankingValue() > $entries[2]->getRankingValue() ? 1 : 0;
                }
                expect(array_map(
                    fn(Participant $participant): string => $participant->getId(),
                    RankRangeSelector::overall(1, $decided)->select($played['outcome'])
                ))->toBe(array_map(
                    fn(StandingEntry $entry): string => $entry->getParticipant()->getId(),
                    array_slice($entries, 0, $decided)
                ), $context);

                // The same seed without the option plays the same bracket:
                // every round is the same, the last one lacks the tie, and
                // the placement is the same with places 3 and 4 in one tier
                $without = playForPlacement($engineWithout, $field, $seed * 100 + $size, $legs, 1);
                $lastRound = $played['rounds'][$bracketRounds - 1];
                $lastRoundWithout = $without['rounds'][$bracketRounds - 1];
                $merged = $played['tiers'];
                if ($size > 3) {
                    $thirdAndFourth = [...$merged[2], ...$merged[3]];
                    sort($thirdAndFourth, SORT_STRING);
                    array_splice($merged, 2, 2, [$thirdAndFourth]);
                }

                expect($without['rounds'])->toHaveCount($bracketRounds, $context)
                    ->and(array_slice($played['rounds'], 0, -1))->toBe(array_slice($without['rounds'], 0, -1), $context)
                    ->and(array_slice($lastRound, 0, $legs))->toBe($lastRoundWithout, $context)
                    ->and($lastRound)->toHaveCount($size === 3 ? $legs : 2 * $legs, $context)
                    ->and($without['placementTies'])->toBe([], $context)
                    ->and($merged)->toBe($without['tiers'], $context);
                ++$brackets;
            }
        }

        expect($brackets)->toBe(108);
        if ($legs === 2) {
            // Two legs give records the tie decision goes against
            expect($decidedAgainstTheRecords)->toBeGreaterThan(0);
        }
    })->with([['fixed path'], ['re-seeded'], ['two legs'], ['two legs, re-seeded']]);

    // 32 field sizes and 60 seeds: 1,920 brackets for each variant.
    it('is the bracket placement of a double elimination bracket of any size', function (string $variant): void {
        $options = match ($variant) {
            'with a reset' => new EliminationOptions(),
            'without a reset' => new EliminationOptions(grandFinalReset: false),
            'two legs' => new EliminationOptions(legsPerTie: 2),
            default => throw new UnexpectedValueException($variant),
        };
        $engine = new DoubleEliminationEngine($options);
        $brackets = 0;
        $resets = 0;
        $moreWinsBelow = 0;

        for ($size = 2; $size <= 33; ++$size) {
            $field = placementField($size);

            for ($seed = 1; $seed <= 60; ++$seed) {
                $played = playForPlacement($engine, $field, $seed * 100 + $size, $options->legsPerTie, 2);
                $context = "{$variant}, n={$size}, seed {$seed}";

                expectPlacementTable($played['outcome'], $field, $played['tiers'], $context);

                // Everyone but the entrant in rank 1 failed to advance
                // twice (once, for the last tie's loser of a bracket that
                // has no reset)
                $entries = array_values($played['outcome']->getStandings()->getEntries());
                expect($played['lost'][$entries[0]->getParticipant()->getId()])->toBeLessThanOrEqual(1, $context);

                $resets += $played['outcome']->getFinalRound()?->getLabel() === 'grand final reset' ? 1 : 0;
                $mostWins = 0;
                foreach ($entries as $entry) {
                    $mostWins = max($mostWins, $entry->getWins());
                }
                $moreWinsBelow += $mostWins > $entries[0]->getWins() ? 1 : 0;
                ++$brackets;
            }
        }

        // The sweep saw what the table used to get wrong: brackets in
        // which an entrant below rank 1 has more wins than the entrant who
        // won the last tie (a table of wins had that entrant first)
        expect($brackets)->toBe(1920)
            ->and($moreWinsBelow)->toBeGreaterThan(0);
        if ($variant === 'with a reset') {
            expect($resets)->toBeGreaterThan(300)
                ->and($moreWinsBelow)->toBeGreaterThan(300);
        }
    })->with([['with a reset'], ['without a reset'], ['two legs']]);

    it('places the entrant who won the reset above the one with more wins', function (): void {
        // Eight entrants. p8 loses to p1 in round 1, wins the losers
        // bracket and the first grand final, and loses the reset to p1.
        // In every other event the first-named participant wins.
        $field = placementField(8);
        [$p1, , , , , , , $p8] = $field;
        $engine = new DoubleEliminationEngine();

        $state = StageState::start($field);
        while (!$engine->isComplete($state)) {
            $pairing = $engine->pairNextRound($state);
            $results = [];
            foreach ($pairing->getEvents() as $event) {
                $p8Wins = $event->hasParticipant($p8)
                    && $pairing->getRoundNumber() > 1
                    && $pairing->getLabel() !== 'grand final reset';
                $results[] = new Result($event, $p8Wins ? $p8 : $event->getParticipants()[0]);
            }
            $state = $state->withRoundPlayed($pairing, $results);
        }

        $outcome = $engine->getOutcome($state);
        assert($outcome !== null);
        [$first, $second] = $outcome->getStandings()->getEntries();

        expect([$first->getParticipant()->getId(), $first->getWins(), $first->getLosses()])->toBe(['p1', 4, 1])
            ->and([$second->getParticipant()->getId(), $second->getWins(), $second->getLosses()])->toBe(['p8', 5, 2])
            ->and($outcome->getStandings()->getPosition($p1))->toBe(1)
            ->and(array_slice($outcome->getStandings()->getTiers(), 0, 4))->toBe([0, 1, 2, 3]);
    });

    it('places the entrant who had a bye and won the final above the one it beat', function (): void {
        // Three entrants. p1 has the bye; p2 beats p3 and loses the final.
        // Both finalists have one win: p2 from two events, p1 from one.
        $field = placementField(3);
        $engine = new SingleEliminationEngine();

        $state = StageState::start($field);
        while (!$engine->isComplete($state)) {
            $pairing = $engine->pairNextRound($state);
            $state = $state->withRoundPlayed($pairing, array_map(
                fn($event): Result => new Result($event, $event->getParticipants()[0]),
                $pairing->getEvents()
            ));
        }

        $outcome = $engine->getOutcome($state);
        assert($outcome !== null);
        $table = array_map(
            fn(StandingEntry $entry): array => [$entry->getParticipant()->getId(), $entry->getWins(), $entry->getLosses()],
            $outcome->getStandings()->getEntries()
        );

        expect($table)->toBe([['p1', 1, 0], ['p2', 1, 1], ['p3', 0, 1]])
            ->and($outcome->getStandings()->getTiedSets())->toBe([])
            ->and($outcome->getStandings()->getTiers())->toBe([0, 1, 2]);
    });

    it('places the entrant a tie decision sent on from a two-legged final above the other finalist', function (): void {
        // A leg each, and the decision goes to the entrant listed second,
        // whose label and seed attribute are also the later ones
        [$alice, $heidi] = [new Participant('alice', 'Alice', 1), new Participant('heidi', 'Heidi', 2)];
        $engine = new SingleEliminationEngine(new EliminationOptions(legsPerTie: 2));

        $state = StageState::start([$alice, $heidi]);
        $final = $engine->pairNextRound($state);
        [$firstLeg, $secondLeg] = $final->getEvents();
        $state = $state->withRoundPlayed($final, [
            new Result($firstLeg, $alice),
            new Result($secondLeg, $heidi, [], [], null, new Decider(['heidi'])),
        ]);

        $outcome = $engine->getOutcome($state);
        assert($outcome !== null);

        expect($outcome->getStandings()->getEntries()[0]->getParticipant()->getId())->toBe('heidi')
            ->and($outcome->getStandings()->getTiedSets())->toBe([])
            ->and($outcome->getStandings()->getEntries()[0]->isLevelWith($outcome->getStandings()->getEntries()[1]))->toBeTrue();
    });

    it('orders the entrants who went out in one round by their records, then by list position', function (): void {
        // Eight entrants, the first-named wins every tie. The semifinal
        // losers are p4 and p3; p4 lost 1-3 and p3 lost 0-5, so p4 has the
        // better record and is above p3, against list order. The four who
        // went out in round 1 recorded no score: nothing separates them,
        // and they are in list order (their labels and seed attributes
        // run the other way).
        $field = placementField(8);
        $engine = new SingleEliminationEngine();

        $state = StageState::start($field);
        while (!$engine->isComplete($state)) {
            $pairing = $engine->pairNextRound($state);
            $results = [];
            foreach ($pairing->getEvents() as $event) {
                [$winner, $loser] = $event->getParticipants();
                $scores = match (true) {
                    $pairing->getLabel() !== 'semifinal' => [],
                    $loser->getId() === 'p4' => [$winner->getId() => 3, 'p4' => 1],
                    default => [$winner->getId() => 5, $loser->getId() => 0],
                };
                $results[] = new Result($event, $winner, $scores);
            }
            $state = $state->withRoundPlayed($pairing, $results);
        }

        $outcome = $engine->getOutcome($state);
        assert($outcome !== null);
        $standings = $outcome->getStandings();
        $ids = array_map(fn(StandingEntry $entry): string => $entry->getParticipant()->getId(), $standings->getEntries());

        expect($ids)->toBe(['p1', 'p2', 'p4', 'p3', 'p5', 'p6', 'p7', 'p8'])
            ->and($standings->getTiers())->toBe([0, 1, 2, 2, 3, 3, 3, 3])
            ->and(count($standings->getTiedSets()))->toBe(1)
            ->and($standings->getTiedSets()[0]->getFirstPosition())->toBe(5)
            ->and($standings->getTiedSets()[0]->getLastPosition())->toBe(8);
    });
});
