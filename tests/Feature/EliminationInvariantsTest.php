<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Decider;
use MissionGaming\Tactician\DTO\Event;
use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\JsonConversionException;
use MissionGaming\Tactician\Exceptions\NoValidPairingException;
use MissionGaming\Tactician\Scheduling\DoubleEliminationEngine;
use MissionGaming\Tactician\Scheduling\EliminationOptions;
use MissionGaming\Tactician\Scheduling\SingleEliminationEngine;
use MissionGaming\Tactician\Stage\BracketNode;
use MissionGaming\Tactician\Stage\BracketNodeStatus;
use MissionGaming\Tactician\Stage\BracketRound;
use MissionGaming\Tactician\Stage\BracketTie;
use MissionGaming\Tactician\Stage\MatchOutcomeSelector;
use MissionGaming\Tactician\Stage\RankRangeSelector;
use MissionGaming\Tactician\Stage\RecordedRound;
use MissionGaming\Tactician\Stage\RoundPairing;
use MissionGaming\Tactician\Stage\SlotPlaceholder;
use MissionGaming\Tactician\Stage\SlotSource;
use MissionGaming\Tactician\Stage\StageEngineInterface;
use MissionGaming\Tactician\Stage\StageOutcome;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Standings\Adjustment;
use MissionGaming\Tactician\Tests\Support\AwkwardIds;
use MissionGaming\Tactician\Tests\Support\TieWinnerDeprecation;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * @return array<Participant>
 */
function invariantField(int $count): array
{
    $participants = [];
    for ($i = 1; $i <= $count; ++$i) {
        $participants[] = new Participant("p{$i}", "P{$i}", $i);
    }

    return $participants;
}

// Property tests: whatever the results, the bracket structure must hold.
// Winners are chosen pseudo-randomly (seeded, so failures reproduce).

it('resolves single elimination in exactly n-1 matches for any outcomes', function (
    int $fieldSize,
    int $seed
): void {
    $rng = new Randomizer(new Mt19937($seed));
    $engine = new SingleEliminationEngine();
    $state = StageState::start(invariantField($fieldSize));

    while (!$engine->isComplete($state)) {
        $pairing = $engine->pairNextRound($state);
        expect($pairing->getEvents())->not->toBeEmpty();

        $results = [];
        foreach ($pairing->getEvents() as $event) {
            $eventParticipants = $event->getParticipants();
            $results[] = new Result($event, $eventParticipants[$rng->getInt(0, 1)]);
        }
        $state = $state->withRoundPlayed($pairing, $results);
    }

    $outcome = $engine->getOutcome($state);
    expect($outcome)->not->toBeNull();
    assert($outcome !== null);
    expect($outcome->getResults())->toHaveCount($fieldSize - 1);

    // The title holder never lost
    $titleHolder = MatchOutcomeSelector::winners()->select($outcome)[0];
    expect($outcome->getStandings()->getEntryFor($titleHolder)?->getLosses())->toBe(0);
})
    ->with([[2], [3], [4], [5], [6], [7], [8], [9], [16]])
    ->with([[42], [1337]]);

it('resolves re-seeded single elimination in exactly n-1 matches for any outcomes', function (
    int $fieldSize,
    int $seed
): void {
    $rng = new Randomizer(new Mt19937($seed));
    $engine = new SingleEliminationEngine(new EliminationOptions(reseedEachRound: true));
    $state = StageState::start(invariantField($fieldSize));

    while (!$engine->isComplete($state)) {
        $pairing = $engine->pairNextRound($state);
        expect($pairing->getEvents())->not->toBeEmpty();

        $results = [];
        foreach ($pairing->getEvents() as $event) {
            $eventParticipants = $event->getParticipants();
            $results[] = new Result($event, $eventParticipants[$rng->getInt(0, 1)]);
        }
        $state = $state->withRoundPlayed($pairing, $results);
    }

    $outcome = $engine->getOutcome($state);
    assert($outcome !== null);
    expect($outcome->getResults())->toHaveCount($fieldSize - 1);
})
    ->with([[2], [5], [8], [16]])
    ->with([[42], [1337]]);

it('resolves two-legged single elimination in 2(n-1) events for any outcomes', function (
    int $fieldSize,
    int $seed
): void {
    $rng = new Randomizer(new Mt19937($seed));
    $engine = new SingleEliminationEngine(new EliminationOptions(legsPerTie: 2));
    $state = StageState::start(invariantField($fieldSize));

    while (!$engine->isComplete($state)) {
        $pairing = $engine->pairNextRound($state);
        expect($pairing->getEvents())->not->toBeEmpty();

        // Decide each tie decisively in its first leg; draw the second so
        // the leg wins settle every aggregate 1-0
        $results = [];
        foreach ($pairing->getEvents() as $event) {
            $eventParticipants = $event->getParticipants();
            $results[] = $event->getMetadataValue('tie_leg') === 1
                ? new Result($event, $eventParticipants[$rng->getInt(0, 1)])
                : new Result($event);
        }
        $state = $state->withRoundPlayed($pairing, $results);
    }

    $outcome = $engine->getOutcome($state);
    assert($outcome !== null);
    expect($outcome->getResults())->toHaveCount(2 * ($fieldSize - 1));
})
    ->with([[2], [5], [8]])
    ->with([[42], [1337]]);

it('resolves double elimination in 2n-2 or 2n-1 matches with correct loss counts', function (
    int $fieldSize,
    int $seed
): void {
    $rng = new Randomizer(new Mt19937($seed));
    $engine = new DoubleEliminationEngine();
    $participants = invariantField($fieldSize);
    $state = StageState::start($participants);

    $losses = array_fill_keys(
        array_map(fn(Participant $p) => $p->getId(), $participants),
        0
    );

    while (!$engine->isComplete($state)) {
        $pairing = $engine->pairNextRound($state);
        expect($pairing->getEvents())->not->toBeEmpty();

        $results = [];
        foreach ($pairing->getEvents() as $event) {
            $eventParticipants = $event->getParticipants();
            $winner = $eventParticipants[$rng->getInt(0, 1)];
            $loser = $winner === $eventParticipants[0] ? $eventParticipants[1] : $eventParticipants[0];
            ++$losses[$loser->getId()];
            $results[] = new Result($event, $winner);
        }
        $state = $state->withRoundPlayed($pairing, $results);
    }

    $outcome = $engine->getOutcome($state);
    assert($outcome !== null);

    // Total matches: 2n-2, or 2n-1 when the grand final was reset
    expect(count($outcome->getResults()))->toBeIn([2 * $fieldSize - 2, 2 * $fieldSize - 1]);

    // Everyone loses exactly twice except the title holder (at most once)
    $titleHolder = MatchOutcomeSelector::winners()->select($outcome)[0];
    foreach ($losses as $id => $lossCount) {
        if ($id === $titleHolder->getId()) {
            expect($lossCount)->toBeLessThanOrEqual(1);
        } else {
            expect($lossCount)->toBe(2, "Participant {$id} was eliminated with {$lossCount} losses");
        }
    }
})
    ->with([[2], [3], [4], [5], [6], [7], [8], [9], [16]])
    ->with([[42], [1337]]);

// Awkward-id sweeps. A bracket is resolved by replaying the recorded results,
// and every result is found again by the ids of its two participants: ids
// that are equal as numbers, or that contain the `|` or `:` of the result
// key or the `\` that escapes them, must give the bracket any other ids
// give. The state goes through JSON after every round.

/**
 * Play a stage to the end, choosing every outcome by seat from a seeded
 * randomizer, and return what was played as seats: the round, the leg, and
 * the list positions of the two participants and of the winner.
 *
 * @param array<Participant> $field
 * @return array{list<array<int, int|false|null>>, StageState}
 * @throws InvalidConfigurationException When the engine rejects the recorded state
 * @throws NoValidPairingException When the engine finds no pairing
 * @throws JsonConversionException When the state does not survive JSON
 */
function playBracketBySeat(StageEngineInterface $engine, array $field, int $seed, int $legsPerTie = 1): array
{
    $randomizer = new Randomizer(new Mt19937($seed));
    $ids = array_values(array_map(fn(Participant $participant) => $participant->getId(), $field));
    $seat = fn(?Participant $participant): int|false|null => $participant === null
        ? null
        : array_search($participant->getId(), $ids, true);

    $state = StageState::start($field);
    $played = [];
    $rounds = 0;

    while (!$engine->isComplete($state)) {
        expect(++$rounds)->toBeLessThanOrEqual(4 * count($field), 'The bracket does not come to an end.');

        $pairing = $engine->pairNextRound($state);
        expect($pairing->getEvents())->not->toBeEmpty();

        $results = [];
        foreach ($pairing->getEvents() as $event) {
            // A two-legged tie is decided in its first leg; the second is
            // drawn, so the leg wins settle every aggregate.
            $winner = $legsPerTie === 2 && $event->getMetadataValue('tie_leg') === 2
                ? null
                : $event->getParticipants()[$randomizer->getInt(0, 1)];
            $results[] = new Result($event, $winner);
            $played[] = [
                $pairing->getRoundNumber(),
                $event->getMetadataValue('tie_leg'),
                $seat($event->getParticipants()[0]),
                $seat($event->getParticipants()[1]),
                $seat($winner),
            ];
        }

        $state = StageState::fromJson($state->withRoundPlayed($pairing, $results)->toJson());
    }

    return [$played, $state];
}

it('resolves single elimination the same way whatever the ids are', function (
    array $ids,
    int $fieldSize,
    string $variant,
    int $seed
): void {
    $options = match ($variant) {
        'fixed path' => new EliminationOptions(),
        're-seeded' => new EliminationOptions(reseedEachRound: true),
        'two legs' => new EliminationOptions(legsPerTie: 2),
        default => throw new UnexpectedValueException($variant),
    };
    $engine = new SingleEliminationEngine($options);
    $field = AwkwardIds::participants($ids, $fieldSize);

    [$played, $state] = playBracketBySeat($engine, $field, $seed, $options->legsPerTie);

    $outcome = $engine->getOutcome($state);
    assert($outcome !== null);
    expect($outcome->getResults())->toHaveCount($options->legsPerTie * ($fieldSize - 1));

    // One survivor, who never lost a tie; everyone else lost exactly one.
    $titleHolder = MatchOutcomeSelector::winners()->select($outcome)[0];
    $runnerUp = MatchOutcomeSelector::losers()->select($outcome)[0];
    expect($titleHolder->getId())->not->toBe($runnerUp->getId());
    expect($outcome->getStandings()->getEntryFor($titleHolder)?->getLosses())->toBe(0);
    foreach ($field as $participant) {
        if ($participant->getId() !== $titleHolder->getId()) {
            expect($outcome->getStandings()->getEntryFor($participant)?->getLosses())->toBe(1);
        }
    }

    // No two ties of a round share a pairing.
    foreach ($state->getRoundsPlayed() as $pairing) {
        $counts = AwkwardIds::pairingCounts($pairing->getEvents());
        expect(array_values(array_unique($counts)))->toBe([$options->legsPerTie]);
    }

    $plain = invariantField($fieldSize);
    expect($played)->toBe(playBracketBySeat($engine, $plain, $seed, $options->legsPerTie)[0]);
})
    ->with(AwkwardIds::dataset())
    ->with([[2], [3], [4], [5], [6]])
    ->with([['fixed path'], ['re-seeded'], ['two legs']])
    ->with([[42], [1337]]);

it('resolves double elimination the same way whatever the ids are', function (
    array $ids,
    int $fieldSize,
    int $seed
): void {
    $engine = new DoubleEliminationEngine();
    $field = AwkwardIds::participants($ids, $fieldSize);

    [$played, $state] = playBracketBySeat($engine, $field, $seed);

    $outcome = $engine->getOutcome($state);
    assert($outcome !== null);
    expect(count($outcome->getResults()))->toBeIn([2 * $fieldSize - 2, 2 * $fieldSize - 1]);

    // Everyone loses exactly twice except the title holder (at most once).
    $titleHolder = MatchOutcomeSelector::winners()->select($outcome)[0];
    foreach ($field as $participant) {
        $losses = $outcome->getStandings()->getEntryFor($participant)?->getLosses();
        if ($participant->getId() === $titleHolder->getId()) {
            expect($losses)->toBeLessThanOrEqual(1);
        } else {
            expect($losses)->toBe(2);
        }
    }

    foreach ($state->getRoundsPlayed() as $pairing) {
        $counts = AwkwardIds::pairingCounts($pairing->getEvents());
        expect(array_values(array_unique($counts)))->toBe([1]);
    }

    $plain = invariantField($fieldSize);
    expect($played)->toBe(playBracketBySeat($engine, $plain, $seed)[0]);
})
    ->with(AwkwardIds::dataset())
    ->with([[2], [3], [4], [5], [6]])
    ->with([[42], [1337]]);

// Third-place sweeps. With the option, the last round of a single
// elimination bracket holds a second tie, between the two entrants who went
// out in the round before it. Whatever the results, the bracket is the
// bracket it is without the option, with that one tie more, and the two ties
// of the last round decide places 1 to 4. The books below are kept from the
// rounds as they were played: who is in which tie is never asked of the
// engine, whose marks on the events are compared with them.

/**
 * Play a bracket to the end, tie by tie. Who advances from each tie is
 * drawn from a seeded randomizer, and so is how: a single-leg tie is won,
 * or finishes level and names who advances; the legs of a two-legged tie
 * are each won by either side or drawn, and a tie they leave level names
 * who advances on its last leg.
 *
 * A level tie names who advances as the decider of the result, or, with
 * `$decision` set to 'tie_winner', as the deprecated metadata of that
 * name: the same draws are made either way, so the two brackets of one
 * seed are the same bracket recorded in two ways. A caller that asks for
 * the metadata takes the deprecations the engine raises (see
 * TieWinnerDeprecation).
 *
 * Every round is checked as it is paired: no entrant is in two ties, a tie
 * has as many events as it has legs, and all of them carry the same mark.
 * The state goes through JSON after every round.
 *
 * @param array<Participant> $field
 * @param 'decider'|'tie_winner' $decision How a level tie records who advances
 * @return array{state: StageState, ties: list<array{round: int, first: string, second: string, advancer: string, mark: BracketTie|null}>, level: int}
 *                                                                                                                                             The ties in the order they were paired,
 *                                                                                                                                             and how many of them finished level
 * @throws InvalidConfigurationException When the engine rejects the recorded state
 * @throws JsonConversionException When the state does not survive JSON
 */
function playBracketTieByTie(
    SingleEliminationEngine|DoubleEliminationEngine $engine,
    array $field,
    int $seed,
    int $legsPerTie,
    string $decision = 'decider'
): array {
    $randomizer = new Randomizer(new Mt19937($seed));
    $state = StageState::start($field)->withEngineFingerprint($engine->getFingerprint());
    $ties = [];
    $levelTies = 0;
    $rounds = 0;

    while (!$engine->isComplete($state)) {
        expect(++$rounds)->toBeLessThanOrEqual(4 * count($field), 'The bracket does not come to an end.');
        expect($engine->getOutcome($state))->toBeNull();

        $pairing = $engine->pairNextRound($state);
        expect($pairing->getEvents())->not->toBeEmpty();

        $legsByTie = [];
        foreach ($pairing->getEvents() as $event) {
            $ids = array_map(fn(Participant $participant): string => $participant->getId(), $event->getParticipants());
            sort($ids, SORT_STRING);
            $legsByTie[implode(' ', $ids)][] = $event;
        }

        $tiesOf = [];
        $results = [];
        foreach ($legsByTie as $legs) {
            [$first, $second] = $legs[0]->getParticipants();
            $tiesOf[$first->getId()] = ($tiesOf[$first->getId()] ?? 0) + 1;
            $tiesOf[$second->getId()] = ($tiesOf[$second->getId()] ?? 0) + 1;

            $marks = array_map(BracketTie::of(...), $legs);
            expect($legs)->toHaveCount($legsPerTie);
            foreach ($marks as $mark) {
                expect($mark)->toBe($marks[0]);
            }

            $legWins = [$first->getId() => 0, $second->getId() => 0];
            $legWinners = [];
            foreach ($legs as $leg) {
                $winner = [$first, $second, null][$randomizer->getInt(0, 2)];
                $legWinners[] = $winner;
                if ($winner !== null) {
                    ++$legWins[$winner->getId()];
                }
            }

            $level = $legWins[$first->getId()] === $legWins[$second->getId()];
            $advancer = match (true) {
                $level => $randomizer->getInt(0, 1) === 0 ? $first : $second,
                $legWins[$first->getId()] > $legWins[$second->getId()] => $first,
                default => $second,
            };

            $levelTies += $level ? 1 : 0;
            foreach ($legs as $index => $leg) {
                $decides = $level && $index === count($legs) - 1;
                $results[] = new Result(
                    $leg,
                    $legWinners[$index],
                    [],
                    $decides && $decision === 'tie_winner' ? [TieWinnerDeprecation::KEY => $advancer->getId()] : [],
                    null,
                    $decides && $decision === 'decider' ? new Decider([$advancer->getId()]) : null
                );
            }

            $ties[] = [
                'round' => $pairing->getRoundNumber(),
                'first' => $first->getId(),
                'second' => $second->getId(),
                'advancer' => $advancer->getId(),
                'mark' => $marks[0],
            ];
        }

        // Every entrant is in at most one tie of a round
        expect(array_unique(array_values($tiesOf)))->toBe([1]);
        foreach ($pairing->getByes() as $bye) {
            expect($tiesOf)->not->toHaveKey($bye->getId());
        }

        $state = StageState::fromJson($state->withRoundPlayed($pairing, $results)->toJson());
    }

    return ['state' => $state, 'ties' => $ties, 'level' => $levelTies];
}

/**
 * Run one check for every field size and seed of a sweep, and name the
 * case in the message of a failure. The sweeps below run inside one test
 * for each variant: a test for every case would be several hundred more.
 *
 * @param list<int> $fieldSizes
 * @param list<int> $seeds
 * @param Closure(int, int): void $check Given the field size and the seed
 * @return int The number of cases checked
 * @throws AssertionFailedError When a case fails, or throws anything
 */
function sweepThirdPlaceCases(string $variant, array $fieldSizes, array $seeds, Closure $check): int
{
    $cases = 0;
    foreach ($fieldSizes as $fieldSize) {
        foreach ($seeds as $seed) {
            try {
                $check($fieldSize, $seed);
            } catch (Throwable $failure) {
                Assert::fail("{$variant}, n={$fieldSize}, seed {$seed}: " . $failure::class . ': ' . $failure->getMessage());
            }
            ++$cases;
        }
    }

    return $cases;
}

/**
 * What must hold of one bracket played with the third-place option.
 *
 * @throws InvalidConfigurationException When the engine rejects the recorded state
 * @throws JsonConversionException When the state does not survive JSON
 */
function expectThirdPlaceBracket(EliminationOptions $options, int $fieldSize, int $seed): void
{
    $engine = new SingleEliminationEngine($options);
    $field = invariantField($fieldSize);
    $legs = $options->legsPerTie;

    ['state' => $state, 'ties' => $ties] = playBracketTieByTie($engine, $field, $seed, $legs);

    // The stage completes, in the rounds of the bracket without the tie,
    // with the events its plan says it has: one tie more than n-1 where
    // two entrants went out before the last round
    $lastRound = (int) ceil(log($fieldSize, 2));
    $hasThirdPlaceTie = $fieldSize >= 4;
    $outcome = $engine->getOutcome($state);
    assert($outcome !== null);

    expect($engine->isComplete($state))->toBeTrue()
        ->and($state->getLastRound()?->getRoundNumber())->toBe($lastRound)
        ->and($state->getLastRound()?->getLabel())->toBe('final')
        ->and($ties)->toHaveCount($fieldSize - 1 + ($hasThirdPlaceTie ? 1 : 0))
        ->and($outcome->getResults())->toHaveCount($legs * count($ties))
        ->and($engine->getPlan($state)->getExpectedEventCount())->toBe($legs * count($ties))
        ->and($engine->getPlan($state)->hasThirdPlaceTie())->toBe($hasThirdPlaceTie)
        ->and($engine->getPlan($state)->getTotalRounds())->toBe($lastRound);

    // The books: an entrant is out once it did not advance from a tie, and
    // the one tie between two entrants who are both out is the third-place
    // tie
    $wentOutIn = [];
    $final = null;
    $thirdPlace = null;
    foreach ($ties as $tie) {
        $other = $tie['advancer'] === $tie['first'] ? $tie['second'] : $tie['first'];

        if (isset($wentOutIn[$tie['first']], $wentOutIn[$tie['second']])) {
            expect($thirdPlace)->toBeNull('A second tie between entrants who were out.')
                ->and($tie['round'])->toBe($lastRound)
                ->and($tie['mark'])->toBe(BracketTie::ThirdPlace);
            $thirdPlace = [$tie['advancer'], $other];

            continue;
        }

        // In every other tie both entrants are still in the bracket
        expect($wentOutIn)->not->toHaveKey($tie['first'])
            ->and($wentOutIn)->not->toHaveKey($tie['second']);

        if ($tie['round'] === $lastRound) {
            expect($final)->toBeNull('A second final.')
                ->and($tie['mark'])->toBe(BracketTie::Final);
            $final = [$tie['advancer'], $other];
        } else {
            // No event before the last round says it is one of its ties
            expect($tie['mark'])->toBeNull();
        }

        $wentOutIn[$other] = $tie['round'];
    }

    assert($final !== null);
    expect($thirdPlace !== null)->toBe($hasThirdPlaceTie);

    // The four entrants of the last round: the two who went out in the
    // round before it are in the third-place tie, and nobody else is
    $outBeforeTheLastRound = array_keys(array_filter($wentOutIn, fn(int $round): bool => $round === $lastRound - 1));
    $inThirdPlaceTie = $thirdPlace ?? [];
    sort($outBeforeTheLastRound, SORT_STRING);
    sort($inThirdPlaceTie, SORT_STRING);
    expect($outBeforeTheLastRound)->toHaveCount($hasThirdPlaceTie ? 2 : 1)
        ->and($inThirdPlaceTie)->toBe($hasThirdPlaceTie ? $outBeforeTheLastRound : []);

    // Places 1 to 4 follow the two ties, each alone in its tier; in a field
    // of three the one entrant who went out before the final is third
    $placed = [...$final, ...($thirdPlace ?? $outBeforeTheLastRound)];
    $standings = $outcome->getStandings();
    $table = array_map(fn($entry): string => $entry->getParticipant()->getId(), array_values($standings->getEntries()));

    expect(array_slice($table, 0, count($placed)))->toBe($placed)
        ->and(array_slice($standings->getTiers(), 0, count($placed)))->toBe(array_keys($placed))
        ->and(array_map(
            fn(Participant $participant): string => $participant->getId(),
            RankRangeSelector::overall(1, count($placed))->select($outcome)
        ))->toBe($placed);
    foreach ($standings->getTiedSets() as $tiedSet) {
        expect($tiedSet->getFirstPosition())->toBeGreaterThan(count($placed));
    }

    // The outcome selectors read the final and not the third-place tie,
    // which sends nobody on: one participant, as after every bracket
    $ids = fn(array $participants): array => array_map(fn(Participant $participant): string => $participant->getId(), $participants);
    expect($ids(MatchOutcomeSelector::winners()->select($outcome)))->toBe([$final[0]])
        ->and($ids(MatchOutcomeSelector::losers()->select($outcome)))->toBe([$final[1]]);

    // The marks are stored with the events: the state went through JSON
    // after every round
    $marks = [];
    foreach ($state->getLastRound()?->getEvents() ?? [] as $event) {
        $marks[] = BracketTie::of($event);
    }
    expect($marks)->toBe([
        ...array_fill(0, $legs, BracketTie::Final),
        ...array_fill(0, $hasThirdPlaceTie ? $legs : 0, BracketTie::ThirdPlace),
    ]);

    // A single-leg tie that finished level counts as won by whoever
    // advanced, the third-place tie included: nobody has a draw, and the
    // entrant placed fourth did not advance from two ties
    if ($legs === 1) {
        foreach ($standings->getEntries() as $entry) {
            $id = $entry->getParticipant()->getId();
            expect($entry->getDraws())->toBe(0)
                ->and($entry->getLosses())->toBe(match (true) {
                    $id === $final[0] => 0,
                    $thirdPlace !== null && $id === $thirdPlace[1] => 2,
                    default => 1,
                });
        }
    }
}

/**
 * The options of a variant of the third-place sweeps, with the tie or
 * without it.
 *
 * @throws InvalidConfigurationException Never: every variant is a valid configuration
 * @throws UnexpectedValueException When the variant is not one of the four
 */
function thirdPlaceSweepOptions(string $variant, bool $thirdPlace): EliminationOptions
{
    return match ($variant) {
        'fixed path' => new EliminationOptions(thirdPlace: $thirdPlace),
        're-seeded' => new EliminationOptions(reseedEachRound: true, thirdPlace: $thirdPlace),
        'two legs' => new EliminationOptions(legsPerTie: 2, thirdPlace: $thirdPlace),
        'two legs, re-seeded' => new EliminationOptions(legsPerTie: 2, reseedEachRound: true, thirdPlace: $thirdPlace),
        default => throw new UnexpectedValueException($variant),
    };
}

// 11 field sizes and 3 seeds: 33 brackets for each variant.
it('plays the third-place tie between the two entrants who went out before the last round, whatever the outcomes', function (
    string $variant
): void {
    $options = thirdPlaceSweepOptions($variant, true);

    expect(sweepThirdPlaceCases(
        $variant,
        [3, 4, 5, 6, 7, 8, 9, 12, 16, 17, 32],
        [42, 1337, 20260],
        fn(int $fieldSize, int $seed) => expectThirdPlaceBracket($options, $fieldSize, $seed)
    ))->toBe(33);
})->with([['fixed path'], ['re-seeded'], ['two legs'], ['two legs, re-seeded']]);

// 10 field sizes and 2 seeds: 20 brackets for each variant, each played
// with the option and without it.
it('pairs every round as the bracket without a third-place tie does, and adds one tie to the last', function (
    string $variant
): void {
    $optionsWithout = thirdPlaceSweepOptions($variant, false);
    $optionsWith = thirdPlaceSweepOptions($variant, true);
    $legs = $optionsWith->legsPerTie;

    $check = function (int $fieldSize, int $seed) use ($optionsWithout, $optionsWith, $legs): void {
        $field = invariantField($fieldSize);

        // The draws of the two brackets are the same up to the final,
        // which is paired before the third-place tie: the same seed plays
        // the same bracket
        $without = playBracketTieByTie(new SingleEliminationEngine($optionsWithout), $field, $seed, $legs);
        $with = playBracketTieByTie(new SingleEliminationEngine($optionsWith), $field, $seed, $legs);

        $unmarked = fn(array $tie): array => [$tie['round'], $tie['first'], $tie['second'], $tie['advancer']];
        $tiesWithout = array_map($unmarked, $without['ties']);
        $tiesWith = array_map($unmarked, $with['ties']);

        expect(array_slice($tiesWith, 0, count($tiesWithout)))->toBe($tiesWithout)
            ->and(count($tiesWith) - count($tiesWithout))->toBe($fieldSize >= 4 ? 1 : 0);

        // Without the option no event says which tie it is, in any round
        foreach ($without['ties'] as $tie) {
            expect($tie['mark'])->toBeNull();
        }
        foreach ($without['state']->getResults() as $result) {
            expect($result->getEvent()->hasMetadata(BracketTie::METADATA_KEY))->toBeFalse();
        }

        // The byes are the byes of the bracket without the tie
        expect($with['state']->getByeCounts())->toBe($without['state']->getByeCounts());
    };

    expect(sweepThirdPlaceCases($variant, [3, 4, 5, 6, 7, 8, 9, 12, 16, 17], [42, 1337], $check))->toBe(20);
})->with([['fixed path'], ['re-seeded'], ['two legs'], ['two legs, re-seeded']]);

// 4 field sizes and 2 seeds: 8 brackets for each set of ids and variant.
it('resolves a bracket with a third-place tie the same way whatever the ids are', function (array $ids): void {
    foreach (['fixed path', 're-seeded', 'two legs'] as $variant) {
        $options = thirdPlaceSweepOptions($variant, true);
        $engine = new SingleEliminationEngine($options);

        $check = function (int $fieldSize, int $seed) use ($ids, $options, $engine): void {
            $field = AwkwardIds::participants($ids, $fieldSize);

            [$played, $state] = playBracketBySeat($engine, $field, $seed, $options->legsPerTie);

            $outcome = $engine->getOutcome($state);
            assert($outcome !== null);
            expect($outcome->getResults())->toHaveCount($options->legsPerTie * ($fieldSize - 1 + ($fieldSize >= 4 ? 1 : 0)));

            // No two ties of a round share a pairing.
            foreach ($state->getRoundsPlayed() as $pairing) {
                $counts = AwkwardIds::pairingCounts($pairing->getEvents());
                expect(array_values(array_unique($counts)))->toBe([$options->legsPerTie]);
            }

            expect($played)->toBe(playBracketBySeat($engine, invariantField($fieldSize), $seed, $options->legsPerTie)[0]);
        };

        expect(sweepThirdPlaceCases($variant, [3, 4, 5, 6], [42, 1337], $check))->toBe(8);
    }
})->with(AwkwardIds::dataset());

// Level-event sweeps. A single-leg event may finish level; the application
// then records who advances as the decider of the event's result.
// Whatever share of a bracket's events finish level, the bracket must hold
// the structure it holds when every event has a winner, and the participant
// each level result names must be the one who goes on.

/**
 * Play a single-leg bracket to the end. Who advances from each event comes
 * from a seeded randomizer; so does whether the event finishes level (the
 * result is then a draw naming who advances) or has a winner. With
 * `$recordAsWins` the same draws are made and every event is recorded with
 * the advancing participant as its winner: the bracket a level bracket
 * must be indistinguishable from.
 *
 * The state goes through JSON after every round of a bracket that records
 * level events.
 *
 * @param array<Participant> $field
 * @param bool $everyEventLevel Whether every event finishes level, or a random subset
 * @return array{list<array{int, string, string, string}>, list<bool>, StageState} The events as round, first id,
 *                                                                                second id and advancing id; whether
 *                                                                                each finished level; the final state
 * @throws InvalidConfigurationException When the engine rejects the recorded state
 * @throws NoValidPairingException When the engine finds no pairing
 * @throws JsonConversionException When the state does not survive JSON
 */
function playBracketWithLevelEvents(
    StageEngineInterface $engine,
    array $field,
    int $seed,
    bool $everyEventLevel,
    bool $recordAsWins
): array {
    $randomizer = new Randomizer(new Mt19937($seed));
    $state = StageState::start($field);
    $played = [];
    $level = [];
    $rounds = 0;

    while (!$engine->isComplete($state)) {
        expect(++$rounds)->toBeLessThanOrEqual(4 * count($field), 'The bracket does not come to an end.');
        expect($engine->getOutcome($state))->toBeNull();

        $pairing = $engine->pairNextRound($state);
        expect($pairing->getEvents())->not->toBeEmpty();

        $results = [];
        foreach ($pairing->getEvents() as $event) {
            [$first, $second] = $event->getParticipants();
            $advancer = $randomizer->getInt(0, 1) === 0 ? $first : $second;
            $isLevel = $randomizer->getInt(0, 2) > 0 || $everyEventLevel;

            $results[] = $isLevel && !$recordAsWins
                ? new Result($event, null, [], [], null, new Decider([$advancer->getId()]))
                : new Result($event, $advancer);
            $played[] = [$pairing->getRoundNumber(), $first->getId(), $second->getId(), $advancer->getId()];
            $level[] = $isLevel;
        }

        $state = $state->withRoundPlayed($pairing, $results);

        if (!$recordAsWins) {
            $restored = StageState::fromJson($state->toJson());
            expect($restored->toArray())->toBe($state->toArray());
            $state = $restored;
        }
    }

    return [$played, $level, $state];
}

/**
 * @return array<string, array{int, int, int}> Wins, draws and losses by participant id, in table order
 */
function invariantRecords(StageOutcome $outcome): array
{
    $records = [];
    foreach ($outcome->getStandings()->getEntries() as $entry) {
        $records[$entry->getParticipant()->getId()] = [$entry->getWins(), $entry->getDraws(), $entry->getLosses()];
    }

    return $records;
}

it('holds the bracket structure when single-leg events finish level and name who advances', function (
    string $variant,
    int $fieldSize,
    string $levelShare,
    int $seed
): void {
    $engine = match ($variant) {
        'single, fixed path' => new SingleEliminationEngine(),
        'single, re-seeded' => new SingleEliminationEngine(new EliminationOptions(reseedEachRound: true)),
        'double, with reset' => new DoubleEliminationEngine(),
        'double, no reset' => new DoubleEliminationEngine(new EliminationOptions(grandFinalReset: false)),
        default => throw new UnexpectedValueException($variant),
    };
    $double = $engine instanceof DoubleEliminationEngine;
    $field = invariantField($fieldSize);

    [$played, $level, $state] = playBracketWithLevelEvents($engine, $field, $seed, $levelShare === 'every event', false);

    $outcome = $engine->getOutcome($state);
    assert($outcome !== null);

    // The number of events a bracket of this size has.
    $expectedCounts = match ($variant) {
        'double, with reset' => [2 * $fieldSize - 2, 2 * $fieldSize - 1],
        'double, no reset' => [2 * $fieldSize - 2],
        default => [$fieldSize - 1],
    };
    expect(count($played))->toBeIn($expectedCounts);
    expect($outcome->getResults())->toHaveCount(count($played));

    // The recorded results are the results as recorded: a level event is
    // still a draw that names who advanced, also after JSON.
    foreach (array_values($outcome->getResults()) as $index => $result) {
        expect($result->isDraw())->toBe($level[$index]);
        expect($result->getDecider()?->getParticipantIds())->toBe($level[$index] ? [$played[$index][3]] : null);
    }

    // The participant each event names advances: they are paired (or sit
    // out with a bye) in a later round. The other one is out in single
    // elimination; in double elimination they play on only if it was the
    // first event they did not advance from.
    $lastSeenIn = [];
    foreach ($state->getRoundsPlayed() as $pairing) {
        $seen = $pairing->getByes();
        foreach ($pairing->getEvents() as $event) {
            $seen = [...$seen, ...$event->getParticipants()];
        }
        foreach ($seen as $participant) {
            $lastSeenIn[$participant->getId()] = max($lastSeenIn[$participant->getId()] ?? 0, $pairing->getRoundNumber());
        }
    }

    $failedToAdvance = array_fill_keys(array_map(fn(Participant $p) => $p->getId(), $field), 0);
    $lastIndex = count($played) - 1;
    foreach ($played as $index => [$round, $firstId, $secondId, $advancerId]) {
        $otherId = $advancerId === $firstId ? $secondId : $firstId;
        ++$failedToAdvance[$otherId];

        if ($index === $lastIndex) {
            continue;
        }

        expect($lastSeenIn[$advancerId] > $round)
            ->toBeTrue("{$advancerId} advanced from round {$round} and was not seen again.");
        expect($lastSeenIn[$otherId] > $round)
            ->toBe($double && $failedToAdvance[$otherId] === 1, "{$otherId} did not advance from round {$round}.");
    }

    // The title holder is whoever advanced from the last event, and the
    // other finalist is the loser of the final round.
    [, $lastFirstId, $lastSecondId, $titleHolderId] = $played[$lastIndex];
    $runnerUpId = $titleHolderId === $lastFirstId ? $lastSecondId : $lastFirstId;
    expect(MatchOutcomeSelector::winners()->select($outcome)[0]->getId())->toBe($titleHolderId);
    expect(MatchOutcomeSelector::losers()->select($outcome)[0]->getId())->toBe($runnerUpId);

    // The standings count a decided level event as a win for whoever
    // advanced: nobody has a draw, and the losses are the loss counts a
    // bracket has. Single: none for the title holder, one for the rest,
    // and the title holder is rank 1. Double: at most one for the title
    // holder, two for the rest (without the reset the last event's loser
    // may have only one).
    $records = invariantRecords($outcome);
    foreach ($field as $participant) {
        $id = $participant->getId();
        [, $draws, $losses] = $records[$id];

        expect($draws)->toBe(0);
        expect($losses)->toBe($failedToAdvance[$id]);

        if ($id === $titleHolderId) {
            expect($losses)->toBeLessThanOrEqual($double ? 1 : 0);
        } elseif (!$double) {
            expect($losses)->toBe(1);
        } elseif ($variant === 'double, no reset' && $id === $runnerUpId) {
            expect($losses)->toBeIn([1, 2]);
        } else {
            expect($losses)->toBe(2);
        }
    }
    if (!$double) {
        expect(array_key_first($records))->toBe($titleHolderId);
    }

    // No two events of a round share a pairing.
    foreach ($state->getRoundsPlayed() as $pairing) {
        $counts = AwkwardIds::pairingCounts($pairing->getEvents());
        expect(array_values(array_unique($counts)))->toBe([1]);
    }

    // A level event that names who advances is, to the bracket, a win for
    // that participant: the same draws recorded as wins give the same
    // events in the same order and the same table.
    [$playedAsWins, , $stateAsWins] = playBracketWithLevelEvents($engine, $field, $seed, $levelShare === 'every event', true);
    $outcomeAsWins = $engine->getOutcome($stateAsWins);
    assert($outcomeAsWins !== null);

    expect($played)->toBe($playedAsWins);
    expect($records)->toBe(invariantRecords($outcomeAsWins));
})
    ->with([['single, fixed path'], ['single, re-seeded'], ['double, with reset'], ['double, no reset']])
    ->with([[2], [3], [4], [5], [6], [7], [8], [9], [12], [16], [17], [32]])
    ->with([['every event'], ['a random subset']])
    ->with([[42], [1337], [20260]]);

// Decider sweeps. The decider of a result and the deprecated 'tie_winner'
// metadata are two ways to record one fact: who advances from a tie that
// its results left level. Whatever the bracket, the same draws recorded
// either way must give the same ties in the same order, the same table and
// the same selections; and the metadata must say that it is deprecated once
// for each call of the engine that reads it, however many ties it decided.

/**
 * @throws InvalidConfigurationException Never: every variant is a valid configuration
 * @throws UnexpectedValueException When the variant is not one of the eight
 */
function deciderSweepEngine(string $variant): SingleEliminationEngine|DoubleEliminationEngine
{
    return match ($variant) {
        'single' => new SingleEliminationEngine(),
        'single, re-seeded' => new SingleEliminationEngine(new EliminationOptions(reseedEachRound: true)),
        'single, two legs' => new SingleEliminationEngine(new EliminationOptions(legsPerTie: 2)),
        'single, third place' => new SingleEliminationEngine(new EliminationOptions(thirdPlace: true)),
        'single, two legs, re-seeded, third place' => new SingleEliminationEngine(
            new EliminationOptions(legsPerTie: 2, reseedEachRound: true, thirdPlace: true)
        ),
        'double' => new DoubleEliminationEngine(),
        'double, two legs' => new DoubleEliminationEngine(new EliminationOptions(legsPerTie: 2)),
        'double, no reset' => new DoubleEliminationEngine(new EliminationOptions(grandFinalReset: false)),
        default => throw new UnexpectedValueException($variant),
    };
}

/**
 * The table of an outcome as rows of what it orders and shows by: the
 * participant, the tier, the record, the ranking value and the scores.
 *
 * @return list<array{string, int|null, int, int, int, float, float, float}>
 */
function deciderSweepTable(StageOutcome $outcome): array
{
    $tiers = $outcome->getStandings()->getTiers();
    $rows = [];
    foreach (array_values($outcome->getStandings()->getEntries()) as $index => $entry) {
        $rows[] = [
            $entry->getParticipant()->getId(),
            $tiers[$index] ?? null,
            $entry->getWins(),
            $entry->getDraws(),
            $entry->getLosses(),
            $entry->getRankingValue(),
            $entry->getScoreFor(),
            $entry->getScoreAgainst(),
        ];
    }

    return $rows;
}

// 6 field sizes and 2 seeds: 12 brackets for each variant, each played twice.
it('settles a bracket through the decider as it settles it through the deprecated tie_winner metadata', function (
    string $variant
): void {
    $engine = deciderSweepEngine($variant);
    $legs = str_contains($variant, 'two legs') ? 2 : 1;
    $levelTiesOfTheSweep = 0;
    $ids = fn(array $participants): array => array_map(fn(Participant $participant): string => $participant->getId(), $participants);

    $check = function (int $fieldSize, int $seed) use ($engine, $legs, $ids, &$levelTiesOfTheSweep): void {
        $field = invariantField($fieldSize);

        // Through the decider: nothing is deprecated, so a notice here
        // would fail the test
        $byDecider = playBracketTieByTie($engine, $field, $seed, $legs);
        $state = $byDecider['state'];
        $outcome = $engine->getOutcome($state);
        assert($outcome !== null);
        $levelTiesOfTheSweep += $byDecider['level'];

        // Through the metadata: the same draws, the same bracket
        $byMetadata = null;
        $raisedWhilePlaying = TieWinnerDeprecation::countIn(function () use ($engine, $field, $seed, $legs, &$byMetadata): void {
            $byMetadata = playBracketTieByTie($engine, $field, $seed, $legs, 'tie_winner');
        });
        assert($byMetadata !== null);
        $legacyState = $byMetadata['state'];

        expect($byMetadata['ties'])->toBe($byDecider['ties'])
            ->and($byMetadata['level'])->toBe($byDecider['level'])
            ->and($raisedWhilePlaying > 0)->toBe($byDecider['level'] > 0)
            ->and(array_map(fn($pairing): array => $pairing->toArray(), $legacyState->getRoundsPlayed()))
            ->toBe(array_map(fn($pairing): array => $pairing->toArray(), $state->getRoundsPlayed()));

        // The results are the same results, and differ in how the decision
        // is carried and in nothing else: no score, no winner
        $decided = 0;
        foreach ($state->getResults() as $index => $result) {
            $legacy = $legacyState->getResults()[$index];
            $named = $result->getDecider()?->getParticipantIds();
            $decided += $named === null ? 0 : 1;

            expect($legacy->getDecider())->toBeNull()
                ->and($result->getMetadata())->toBe([])
                ->and($legacy->getMetadata())->toBe($named === null ? [] : [TieWinnerDeprecation::KEY => $named[0]])
                ->and($legacy->getWinner()?->getId())->toBe($result->getWinner()?->getId())
                ->and($legacy->getScores())->toBe($result->getScores())
                ->and($legacy->getEvent()->toArray())->toBe($result->getEvent()->toArray());
        }
        expect($decided)->toBe($byDecider['level']);

        // Each call of the engine that reads the metadata says so once,
        // however many ties the metadata decided in the bracket
        $expected = $byDecider['level'] > 0 ? 1 : 0;
        $legacyOutcome = null;
        expect(TieWinnerDeprecation::countIn(function () use ($engine, $legacyState, &$legacyOutcome): void {
            $legacyOutcome = $engine->getOutcome($legacyState);
        }))->toBe($expected)
            ->and(TieWinnerDeprecation::countIn(fn(): bool => $engine->isComplete($legacyState)))->toBe($expected);
        assert($legacyOutcome instanceof StageOutcome);

        // The same table, tier by tier, and the same selections
        expect(deciderSweepTable($legacyOutcome))->toBe(deciderSweepTable($outcome))
            ->and(count($legacyOutcome->getStandings()->getTiedSets()))->toBe(count($outcome->getStandings()->getTiedSets()));

        foreach ([MatchOutcomeSelector::winners(), MatchOutcomeSelector::losers()] as $selector) {
            $selected = null;
            $raised = TieWinnerDeprecation::countIn(function () use ($selector, $legacyOutcome, &$selected): void {
                $selected = $selector->select($legacyOutcome);
            });

            expect($ids($selected ?? []))->toBe($ids($selector->select($outcome)))
                ->and($raised)->toBeLessThanOrEqual(1);
        }
    };

    expect(sweepThirdPlaceCases($variant, [3, 4, 5, 6, 8, 9], [42, 1337], $check))->toBe(12)
        // The sweep is not of brackets that never needed a decision
        ->and($levelTiesOfTheSweep)->toBeGreaterThan(5);
})->with([
    ['single'],
    ['single, re-seeded'],
    ['single, two legs'],
    ['single, third place'],
    ['single, two legs, re-seeded, third place'],
    ['double'],
    ['double, two legs'],
    ['double, no reset'],
]);

// Sweeps over what was known when a round was paired. A stage state records
// after which round each adjustment was made and each participant withdrew,
// and an engine reads a recorded round from the adjustments and the
// entrants of that moment (ADR 0001, ADR 0007). So nothing that is recorded
// later may change what a recorded round is read as: not its label, not the
// round the engine would have paired in its place, not who went out in it.

/**
 * The pairs and the byes of a round, as sorted lists of IDs: what two
 * rounds are compared by.
 *
 * @return array{list<string>, list<string>}
 */
function knownThenShape(RoundPairing $pairing): array
{
    $pairs = [];
    foreach ($pairing->getEvents() as $event) {
        $ids = array_map(static fn(Participant $participant): string => $participant->getId(), array_values($event->getParticipants()));
        sort($ids);
        $pairs[implode(' v ', $ids)] = true;
    }
    $pairs = array_keys($pairs);
    sort($pairs);

    $byes = array_map(static fn(Participant $participant): string => $participant->getId(), array_values($pairing->getByes()));
    sort($byes);

    return [$pairs, $byes];
}

/**
 * What an engine reads of the rounds a state holds, as plain values: for
 * each, its label as the engine reads it, the round the engine would have
 * paired, whether the two differ, and the events nobody played.
 *
 * @return list<array<string, mixed>>
 *
 * @throws InvalidConfigurationException When the engine refuses the state
 */
function knownThenRounds(SingleEliminationEngine|DoubleEliminationEngine $engine, StageState $state): array
{
    return array_map(
        static fn(RecordedRound $round): array => [
            'round' => $round->getRoundNumber(),
            'read as' => $round->getDerivation()?->getLabel(),
            'derivation' => $round->getDerivation() instanceof RoundPairing ? knownThenShape($round->getDerivation()) : null,
            'manual' => $round->isManual(),
            'uncontested' => count($round->getUncontestedEvents()),
        ],
        $engine->getRecordedRounds($state)
    );
}

/**
 * The tier of every participant of a bracket's table, by ID.
 *
 * @return array<string, int>
 *
 * @throws InvalidConfigurationException When the engine refuses the state
 */
function knownThenTiers(SingleEliminationEngine|DoubleEliminationEngine $engine, StageState $state): array
{
    $table = $engine->getStandings($state);
    $tiers = [];
    foreach (array_values($table->getEntries()) as $index => $entry) {
        $tiers[$entry->getParticipant()->getId()] = $table->getTiers()[$index] ?? -1;
    }
    ksort($tiers);

    return $tiers;
}

/**
 * Play a bracket to its end through the verbs, with withdrawals and
 * adjustments at any moment: before a round is paired, while it is being
 * played, and after the bracket is over. The check is given every state
 * the bracket goes through.
 *
 * Where the legs are recorded one by one ($legByLeg), each leg of a tie
 * is its own step, with a score for each of the two, so that the check
 * also sees a tie with some of its legs recorded. That draws nothing more
 * from the seed: the bracket is played as it is without.
 *
 * @param array<Participant> $field
 * @param Closure(StageState): void $check
 *
 * @return array{StageState, int, int} The final state, and how many withdrawals and adjustments it holds
 *
 * @throws InvalidConfigurationException When the engine or the state refuses a step
 * @throws LogicException When the bracket does not end
 */
function knownThenPlay(
    SingleEliminationEngine|DoubleEliminationEngine $engine,
    array $field,
    int $seed,
    Closure $check,
    bool $legByLeg = false
): array {
    $rng = new Randomizer(new Mt19937($seed));
    $field = array_values($field);
    $state = StageState::start($field);
    $adjustments = 0;

    $disturb = static function (StageState $state) use ($rng, $field, &$adjustments, $check): StageState {
        if ($state->getRoundsPlayed() !== [] && $rng->getInt(0, 3) === 0 && count($state->getParticipants()) > 0) {
            $active = array_values($state->getParticipants());
            $state = $state->withoutParticipant($active[$rng->getInt(0, count($active) - 1)]);
            $check($state);
        }
        if ($rng->getInt(0, 2) === 0) {
            $state = $state->withAdjustment(
                new Adjustment($field[$rng->getInt(0, count($field) - 1)]->getId(), [-4.0, -1.0, 0.5, 1.0, 4.0][$rng->getInt(0, 4)])
            );
            ++$adjustments;
            $check($state);
        }

        return $state;
    };

    for ($guard = 0; !$engine->isComplete($state); ++$guard) {
        if ($guard > 60) {
            throw new LogicException('The bracket did not end.');
        }

        // The round is recorded when it is paired, so that what happens
        // while it is played comes after it
        $pairing = $engine->pairNextRound($state);
        $state = $state->withRoundPaired($pairing);
        $check($state);

        // The results come one tie at a time, with a withdrawal or an
        // adjustment between them now and then. A tie an entrant has left
        // is not played.
        $ties = [];
        foreach ($pairing->getEvents() as $event) {
            $ids = array_map(static fn(Participant $participant): string => $participant->getId(), array_values($event->getParticipants()));
            sort($ids);
            $ties[implode(' ', $ids)][] = $event;
        }
        foreach ($rng->shuffleArray(array_values($ties)) as $legs) {
            $state = $disturb($state);

            $active = array_map(static fn(Participant $participant): string => $participant->getId(), $state->getParticipants());
            $entrants = array_values($legs[0]->getParticipants());
            if (!in_array($entrants[0]->getId(), $active, true) || !in_array($entrants[1]->getId(), $active, true)) {
                continue;
            }

            // One of the two wins every leg
            $winner = $entrants[$rng->getInt(0, 1)];
            if (!$legByLeg) {
                $state = $state->withAdditionalResults(array_map(static fn(Event $leg): Result => new Result($leg, $winner), $legs));
                $check($state);

                continue;
            }
            foreach (array_values($legs) as $index => $leg) {
                $loser = $entrants[0]->getId() === $winner->getId() ? $entrants[1] : $entrants[0];
                $state = $state->withAdditionalResults([new Result($leg, $winner, [$winner->getId() => $index + 2, $loser->getId() => $index])]);
                $check($state);
            }
        }

        $state = $disturb($state);
    }

    // After the end: whoever withdraws now, and whatever is adjusted
    $state = $disturb($disturb($state));

    return [$state, count($state->getWithdrawals()), $adjustments];
}

// Before this was held, a re-seeded bracket ranked every recorded round
// with every adjustment the state held, also those made later, and where
// an entrant had withdrawn before a round, a later adjustment could make
// the engine read that round as another one. A wider run compared the
// engines, after every step, with a model of the bracket that knows when
// everything happened: 3,380 single and 1,500 double elimination stages of
// 2 to 17 entrants, with rounds drawn by hand among them. Before, 89 of the
// 727 rounds that re-seeded brackets with an adjustment had paired
// themselves were reported as paired by hand, and 31 single elimination
// stages were read otherwise than they were played. After, no round an
// engine paired was reported as manual (of 9,326 and 9,519) and no stage
// was read otherwise.
it('reads every round it paired as its own round, whatever is recorded afterwards', function (string $variant): void {
    $engine = deciderSweepEngine($variant);
    $rounds = 0;
    $withdrawals = 0;
    $adjustments = 0;

    foreach ([3, 5, 8, 11, 16] as $fieldSize) {
        foreach ([1, 2, 3, 4] as $seed) {
            $where = "{$variant}, {$fieldSize} entrants, seed {$seed}";
            $before = [];

            $check = static function (StageState $state) use ($engine, $where, &$before): void {
                $read = knownThenRounds($engine, $state);

                foreach ($engine->getRecordedRounds($state) as $index => $round) {
                    $derivation = $round->getDerivation();
                    assert($derivation instanceof RoundPairing);

                    // The engine paired the round: it is the round it would pair
                    expect($round->isManual())->toBeFalse("{$where}, round {$round->getRoundNumber()}")
                        ->and($derivation->getLabel())->toBe($round->getPairing()->getLabel(), $where)
                        ->and(knownThenShape($derivation))->toBe(knownThenShape($round->getPairing()), $where);
                    unset($read[$index]['uncontested']);
                }

                // And it is read the same at every later moment
                expect(array_slice($read, 0, count($before)))->toBe($before, $where);
                $before = $read;
            };

            [$state, $withdrawn, $adjusted] = knownThenPlay($engine, invariantField($fieldSize), 1000 * $fieldSize + $seed, $check);

            // The rows of an application say the same, and so does the stored state
            $fromRows = StageState::fromHistory(
                invariantField($fieldSize),
                $state->getRoundsPlayed(),
                $state->getResults(),
                $state->getWithdrawals(),
                $state->getAdjustments()
            );
            expect(knownThenRounds($engine, $fromRows))->toBe(knownThenRounds($engine, $state), $where)
                ->and(knownThenTiers($engine, $fromRows))->toBe(knownThenTiers($engine, $state), $where)
                ->and(knownThenTiers($engine, StageState::fromJson($state->toJson())))->toBe(knownThenTiers($engine, $state), $where)
                ->and($engine->isComplete($state))->toBeTrue($where);

            $rounds += count($state->getRoundsPlayed());
            $withdrawals += $withdrawn;
            $adjustments += $adjusted;
        }
    }

    // The sweep is of brackets that had rounds, withdrawals and adjustments
    expect($rounds)->toBeGreaterThan(40)
        ->and($withdrawals)->toBeGreaterThan(10)
        ->and($adjustments)->toBeGreaterThan(20);
})->with([
    ['single'],
    ['single, re-seeded'],
    ['single, two legs'],
    ['single, third place'],
    ['single, two legs, re-seeded, third place'],
    ['double'],
    ['double, two legs'],
    ['double, no reset'],
]);

// An adjustment recorded now belongs to the round that is paired next and
// to the tables. It decides nothing about a round that exists: the bracket
// stays where it is, with the same entrants still in it and the same ones
// out, each in the round it went out in. A re-seeded bracket may fold the
// next round otherwise; it does not pair another round than the one due.
it('leaves the bracket where it is when an adjustment is recorded, at every point of it', function (string $variant): void {
    $engine = deciderSweepEngine($variant);
    $points = 0;

    foreach ([4, 7, 9, 16] as $fieldSize) {
        foreach ([1, 2, 3] as $seed) {
            $field = invariantField($fieldSize);
            $rng = new Randomizer(new Mt19937(77 * $fieldSize + $seed));
            $where = "{$variant}, {$fieldSize} entrants, seed {$seed}";

            $check = static function (StageState $state) use ($engine, $field, $rng, $where, &$points): void {
                $adjusted = $state;
                foreach ([1, 2, 3] as $ignored) {
                    $adjusted = $adjusted->withAdjustment(
                        new Adjustment($field[$rng->getInt(0, count($field) - 1)]->getId(), [-9.0, -4.0, 1.0, 4.0, 9.0][$rng->getInt(0, 4)])
                    );
                }

                $next = static function (StageState $state) use ($engine): array {
                    $readiness = $engine->getReadiness($state);
                    if (!$readiness->isReady()) {
                        return [$readiness->isComplete(), $readiness->getRoundNumber(), count($readiness->getUnresolvedEvents())];
                    }

                    $pairing = $engine->pairNextRound($state);
                    $in = [];
                    foreach ($pairing->getEvents() as $event) {
                        foreach ($event->getParticipants() as $participant) {
                            $in[$participant->getId()] = true;
                        }
                    }
                    foreach ($pairing->getByes() as $bye) {
                        $in[$bye->getId()] = true;
                    }
                    ksort($in);

                    return [$pairing->getRoundNumber(), $pairing->getLabel(), array_keys($in)];
                };

                expect(knownThenRounds($engine, $adjusted))->toBe(knownThenRounds($engine, $state), $where)
                    ->and(knownThenTiers($engine, $adjusted))->toBe(knownThenTiers($engine, $state), $where)
                    ->and($next($adjusted))->toBe($next($state), $where);
                ++$points;
            };

            knownThenPlay($engine, $field, 500 * $fieldSize + $seed, $check);
        }
    }

    expect($points)->toBeGreaterThan(100);
})->with([
    ['single'],
    ['single, re-seeded'],
    ['single, two legs, re-seeded, third place'],
    ['double'],
]);

/**
 * Whether the last tie of a finished bracket was decided by its results:
 * the tie between the two who met in it last, unless that meeting was a
 * grand final the losers champion won, which makes a reset due.
 */
function knownThenLastTiePlayed(StageState $state, Participant $winner, Participant $loser, bool $resetDue): bool
{
    $last = null;
    foreach ($state->getResults() as $result) {
        $ids = array_map(static fn(Participant $participant): string => $participant->getId(), array_values($result->getEvent()->getParticipants()));
        if (in_array($winner->getId(), $ids, true) && in_array($loser->getId(), $ids, true)) {
            $last = $result;
        }
    }
    if ($last === null) {
        return false;
    }

    // A grand final the losers champion (named second) won makes the reset the last tie
    $grandFinal = $last->getEvent()->getRound()?->getMetadataValue('label') === 'grand final';
    $first = array_values($last->getEvent()->getParticipants())[$last->getEvent()->getMetadataValue('tie_leg') === 2 ? 1 : 0];

    return !$grandFinal || !$resetDue || $last->getWinner()?->getId() === $first->getId();
}

// A withdrawal gives away the ties the entrant has not finished. Once the
// bracket is over nothing is left to give away: its last tie was decided,
// by a result or by the withdrawal of one of its entrants, and a later
// withdrawal belongs to whatever comes next (ADR 0013). So every entrant
// still in the stage withdraws from a finished bracket, one after another,
// the winner among them, and the bracket reads the same after each: the
// outcome, its table and settled ties, the selector, the recorded rounds.
it('takes back nothing once the bracket is over when entrants withdraw, the winner of the bracket included', function (string $variant): void {
    $engine = deciderSweepEngine($variant);
    $finished = 0;
    $winnersWithdrawn = 0;
    $givenAway = 0;

    foreach ([3, 4, 5, 6, 8, 13] as $fieldSize) {
        foreach ([1, 2, 3] as $seed) {
            $where = "{$variant}, {$fieldSize} entrants, seed {$seed}";
            [$state] = knownThenPlay($engine, invariantField($fieldSize), 300 * $fieldSize + $seed, static function (): void {});

            $outcome = $engine->getOutcome($state);
            assert($outcome instanceof StageOutcome);
            $tiers = knownThenTiers($engine, $state);
            $rounds = $engine->getRecordedRounds($state);
            $winners = MatchOutcomeSelector::winners()->select($outcome);
            $losers = MatchOutcomeSelector::losers()->select($outcome);

            // The selector gives the entrant the table has in its first tier
            // and the one it beat
            expect(array_map(static fn(Participant $participant): string => $participant->getId(), $winners))
                ->toBe(array_keys($tiers, 0, true), $where)
                ->and($winners)->toHaveCount(1, $where);
            foreach ($losers as $loser) {
                expect($tiers[$loser->getId()])->toBe(1, $where);
            }
            if (!knownThenLastTiePlayed($state, $winners[0], $losers[0] ?? $winners[0], !str_contains($variant, 'no reset'))) {
                ++$givenAway;
            }

            foreach ($state->getParticipants() as $leaving) {
                $state = $state->withoutParticipant($leaving);
                $after = $engine->getOutcome($state);
                assert($after instanceof StageOutcome);

                expect($engine->isComplete($state))->toBeTrue($where)
                    ->and(knownThenTiers($engine, $state))->toBe($tiers, "{$where}: {$leaving->getId()} withdrew")
                    ->and($after->getStandings())->toEqual($outcome->getStandings(), $where)
                    ->and($after->getSettledTies())->toEqual($outcome->getSettledTies(), $where)
                    ->and(MatchOutcomeSelector::winners()->select($after))->toEqual($winners, $where)
                    ->and(MatchOutcomeSelector::losers()->select($after))->toEqual($losers, $where)
                    ->and($engine->getRecordedRounds($state))->toEqual($rounds, $where);
                $winnersWithdrawn += $leaving->getId() === $winners[0]->getId() ? 1 : 0;
            }
            ++$finished;
        }
    }

    // Some of the brackets of the sweep end with a last tie that was given away
    expect($finished)->toBe(18)
        ->and($winnersWithdrawn)->toBeGreaterThan(5)
        ->and($givenAway)->toBeGreaterThanOrEqual(1);
})->with([
    ['single'],
    ['single, re-seeded'],
    ['single, two legs'],
    ['single, third place'],
    ['double'],
    ['double, no reset'],
]);

// To a state a round is paired when it is recorded, and an application may
// record a round only once it has been played. An entrant who withdraws in
// between is then recorded as withdrawn before the round. The round names
// it, so it was in the bracket when the round was paired: the bracket is
// read the same as where the round was recorded first and the withdrawals
// after it, round by round to its end.
it('reads a bracket the same whether a round or the withdrawals during it are recorded first', function (string $variant): void {
    $engine = deciderSweepEngine($variant);
    $rounds = 0;
    $between = 0;

    $next = static function (StageState $state) use ($engine): array {
        $readiness = $engine->getReadiness($state);
        if (!$readiness->isReady()) {
            return [$readiness->isComplete(), $readiness->getRoundNumber(), count($readiness->getUnresolvedEvents())];
        }

        return [$engine->pairNextRound($state)->getRoundNumber(), knownThenShape($engine->pairNextRound($state))];
    };

    foreach ([3, 4, 6, 8, 13] as $fieldSize) {
        foreach ([1, 2, 3, 4] as $seed) {
            $where = "{$variant}, {$fieldSize} entrants, seed {$seed}";
            $rng = new Randomizer(new Mt19937(900 * $fieldSize + $seed));

            // The same history twice: a round recorded when it is paired, and when it has been played
            $whenPaired = $whenPlayed = StageState::start(invariantField($fieldSize));

            for ($guard = 0; !$engine->isComplete($whenPaired); ++$guard) {
                if ($guard > 60) {
                    throw new LogicException('The bracket did not end.');
                }

                $pairing = $engine->pairNextRound($whenPaired);
                expect(knownThenShape($engine->pairNextRound($whenPlayed)))->toBe(knownThenShape($pairing), $where);

                // Up to two of those still in the stage leave while the round is played,
                // the first round included: a state that holds no round keeps the
                // withdrawal with round 0, and the first round says the entrant was in
                // the draw
                // In double elimination they are entrants the round names: a
                // round of one bracket does not name an entrant of the other,
                // and the limit that leaves is pinned in StageTimesTest ("a
                // round that is recorded after it was paired")
                $leaving = [];
                $active = array_values($whenPaired->getParticipants());
                if (str_starts_with($variant, 'double')) {
                    $named = [];
                    foreach ($pairing->getEvents() as $event) {
                        foreach ($event->getParticipants() as $participant) {
                            $named[$participant->getId()] = true;
                        }
                    }
                    foreach ($pairing->getByes() as $participant) {
                        $named[$participant->getId()] = true;
                    }
                    $active = array_values(array_filter(
                        $active,
                        static fn(Participant $participant): bool => isset($named[$participant->getId()])
                    ));
                }
                for ($i = $rng->getInt(0, 2); $i > 0 && count($active) > count($leaving); --$i) {
                    $leaving[$active[$rng->getInt(0, count($active) - 1)]->getId()] = true;
                }
                $leaving = array_values(array_filter(
                    $whenPaired->getParticipants(),
                    static fn(Participant $participant): bool => isset($leaving[$participant->getId()])
                ));
                $between += count($leaving);

                $whenPaired = $whenPaired->withRoundPlayed($pairing, []);
                foreach ($leaving as $participant) {
                    $whenPaired = $whenPaired->withoutParticipant($participant);
                    $whenPlayed = $whenPlayed->withoutParticipant($participant);
                }
                $whenPlayed = $whenPlayed->withRoundPlayed($pairing, []);

                // The results of the ties both entrants are still in, one of the two winning every leg
                $gone = array_map(static fn(Participant $participant): string => $participant->getId(), $whenPaired->getWithdrawnEntrants());
                $ties = [];
                foreach ($pairing->getEvents() as $event) {
                    $ids = array_map(static fn(Participant $participant): string => $participant->getId(), array_values($event->getParticipants()));
                    sort($ids);
                    $ties[implode(' ', $ids)][] = $event;
                }
                $results = [];
                foreach ($ties as $legs) {
                    $entrants = array_values($legs[0]->getParticipants());
                    if (in_array($entrants[0]->getId(), $gone, true) || in_array($entrants[1]->getId(), $gone, true)) {
                        continue;
                    }
                    $winner = $entrants[$rng->getInt(0, 1)];
                    foreach ($legs as $leg) {
                        $results[] = new Result($leg, $winner);
                    }
                }
                $whenPaired = $whenPaired->withAdditionalResults($results);
                $whenPlayed = $whenPlayed->withAdditionalResults($results);

                // Every round is listed, each as the round the engine paired
                $read = knownThenRounds($engine, $whenPlayed);
                expect($read)->toHaveCount(count($whenPlayed->getRoundsPlayed()), $where)
                    ->and($read)->toBe(knownThenRounds($engine, $whenPaired), $where)
                    ->and(array_column($read, 'manual'))->toBe(array_fill(0, count($read), false), $where)
                    ->and($read[count($read) - 1]['derivation'])->toBe(knownThenShape($pairing), $where)
                    ->and(knownThenTiers($engine, $whenPlayed))->toBe(knownThenTiers($engine, $whenPaired), $where)
                    ->and($next($whenPlayed))->toBe($next($whenPaired), $where);
                ++$rounds;
            }

            expect($engine->isComplete($whenPlayed))->toBeTrue($where);
        }
    }

    expect($rounds)->toBeGreaterThan(40)
        ->and($between)->toBeGreaterThan(20);
})->with([
    ['single'],
    ['single, re-seeded'],
    ['single, two legs'],
    ['single, third place'],
    ['double'],
    ['double, two legs'],
    ['double, no reset'],
]);

// The structured bracket view (issue #57). Whatever has happened, after every
// step of a bracket played with withdrawals and adjustments at any moment,
// the view reads the state as the engine does: the same recorded rounds with
// the same events and labels, the round to pair next as the engine pairs it,
// each slot filled by the node it names, each node settled as its status
// says, the first round as the entry fold, and a finished bracket won by the
// entrant its outcome names. Reading it never throws. Every node carries the
// result the state holds for each of its events (issue #181), and every
// result the state holds is on exactly one node, at the place of its event.

/**
 * The key of an event that a result names, worked out apart from the
 * library: the round number, the two participants sorted, and the leg.
 */
function bracketViewResultKey(Event $event): string
{
    $ids = array_map(static fn(Participant $participant): string => $participant->getId(), array_values($event->getParticipants()));
    sort($ids);
    $leg = $event->getMetadataValue('tie_leg');

    return ($event->getRound()?->getNumber() ?? 0) . ' ' . implode(' v ', $ids) . ' leg ' . (is_int($leg) ? $leg : 1);
}

/**
 * The events as sorted keys: the two participants in their order, and the leg.
 *
 * @param array<Event> $events
 *
 * @return list<string>
 */
function bracketViewEventKeys(array $events): array
{
    $keys = array_map(
        static fn(Event $event): string => implode(' v ', array_map(static fn(Participant $participant): string => $participant->getId(), array_values($event->getParticipants())))
            . ' leg ' . (is_int($event->getMetadataValue('tie_leg')) ? $event->getMetadataValue('tie_leg') : 1),
        array_values($events)
    );
    sort($keys);

    return $keys;
}

/**
 * The IDs of participants, sorted.
 *
 * @param array<Participant|null> $participants
 *
 * @return list<string>
 */
function bracketViewIds(array $participants): array
{
    $ids = [];
    foreach ($participants as $participant) {
        if ($participant instanceof Participant) {
            $ids[] = $participant->getId();
        }
    }
    sort($ids);

    return $ids;
}

/**
 * Check the view of one state against the engine, and count what it showed.
 *
 * @param array<Participant> $entrants In list order
 * @param array<string, int> $seen
 *
 * @throws InvalidConfigurationException When the engine refuses the state
 */
function bracketViewInvariants(
    SingleEliminationEngine|DoubleEliminationEngine $engine,
    EliminationOptions $options,
    StageState $state,
    array $entrants,
    string $where,
    array &$seen
): void {
    $entrants = array_values($entrants);
    $bracket = $engine->getBracket($state);
    $complete = $engine->isComplete($state);
    expect($bracket->isComplete())->toBe($complete, $where);

    $byId = [];
    foreach ($bracket->getNodes() as $node) {
        expect(isset($byId[$node->getId()]))->toBeFalse("{$where}: {$node->getId()} twice");
        $byId[$node->getId()] = $node;
    }

    // The recorded rounds, as the engine reads them
    $recorded = $engine->getRecordedRounds($state);
    $viewRecorded = array_values(array_filter($bracket->getRounds(), static fn(BracketRound $round): bool => $round->isRecorded()));
    expect(array_map(static fn(BracketRound $round): ?int => $round->getRoundNumber(), $viewRecorded))
        ->toBe(array_map(static fn(RecordedRound $round): int => $round->getRoundNumber(), $recorded), $where);
    foreach ($recorded as $index => $round) {
        $view = $viewRecorded[$index];
        $nodeOf = [];
        $events = [];
        foreach ($view->getNodes() as $node) {
            foreach ($node->getEvents() as $event) {
                $events[] = $event;
                $nodeOf[spl_object_id($event)] = $node;
            }
        }

        expect($view->getLabel())->toBe($round->getDerivation()?->getLabel(), $where)
            ->and($view->getLabel())->toBe($round->getPairing()->getLabel(), $where)
            ->and($view->isManual())->toBe($round->isManual(), $where)
            ->and(bracketViewEventKeys($events))->toBe(bracketViewEventKeys($round->getPairing()->getEvents()), "{$where}, round {$round->getRoundNumber()}");
        foreach ($round->getUnresolvedEvents() as $event) {
            expect($nodeOf[spl_object_id($event)]->getStatus())->toBe(BracketNodeStatus::InPlay, $where);
        }
        foreach ($round->getUndecidedEvents() as $event) {
            expect($nodeOf[spl_object_id($event)]->getStatus())->toBe(BracketNodeStatus::AwaitingDecision, $where);
        }
        foreach ($round->getUncontestedEvents() as $event) {
            expect($nodeOf[spl_object_id($event)]->getStatus())->toBeIn([BracketNodeStatus::Uncontested, BracketNodeStatus::Void], $where);
        }
    }

    // The results of the nodes are the state's: one for each event, at its
    // place, null where the state holds none; every result of the state on
    // exactly one node
    $resultOf = [];
    foreach ($state->getResults() as $result) {
        $resultOf[bracketViewResultKey($result->getEvent())] = $result;
    }
    $shown = [];
    foreach ($bracket->getNodes() as $node) {
        $events = $node->getEvents();
        $results = $node->getResults();
        expect(array_keys($results))->toBe(array_keys($events), "{$where}: {$node->getId()}")
            ->and(array_map(static fn(Event $event): mixed => $event->getMetadataValue('tie_leg') ?? 1, $events))
            ->toBe($events === [] ? [] : range(1, count($events)), "{$where}: {$node->getId()}");
        foreach ($events as $index => $event) {
            $key = bracketViewResultKey($event);
            expect($results[$index])->toBe($resultOf[$key] ?? null, "{$where}: {$node->getId()}, {$key}");
            if ($results[$index] instanceof Result) {
                $shown[$key] = ($shown[$key] ?? 0) + 1;
            }
        }
        $recorded = count(array_filter($results, static fn(?Result $result): bool => $result instanceof Result));
        if ($recorded > 0) {
            ++$seen[$recorded < count($results) ? 'some legs recorded' : 'every leg recorded'];
        }
    }
    ksort($shown);
    ksort($resultOf);
    expect($shown)->toBe(array_fill_keys(array_keys($resultOf), 1), $where);

    // The round to pair next, as the engine pairs it
    $next = $bracket->getNextRound();
    expect($next instanceof BracketRound)->toBe($engine->getReadiness($state)->isReady(), $where);
    if ($next instanceof BracketRound) {
        $pairing = $engine->pairNextRound($state);
        $ties = [];
        $byes = [];
        foreach ($next->getNodes() as $node) {
            if ($node->getStatus() === BracketNodeStatus::ToBePaired) {
                $ties[] = implode(' v ', bracketViewIds($node->getParticipants()));
            }
            if (in_array($node->getStatus(), [BracketNodeStatus::Bye, BracketNodeStatus::Uncontested], true)) {
                $byes[] = $node->getAdvancer();
            }
        }
        sort($ties);
        expect([$ties, bracketViewIds($byes)])->toBe(knownThenShape($pairing), $where)
            ->and($next->getLabel())->toBe($pairing->getLabel(), $where);
    }

    foreach ($bracket->getRounds() as $round) {
        foreach ($round->getNodes() as $node) {
            $participants = bracketViewIds($node->getParticipants());
            $advancer = $node->getAdvancer()?->getId();
            $losers = bracketViewIds($node->getLosers());
            ++$seen[$node->getStatus()->value];

            foreach ($node->getSlots() as $slot) {
                $participant = $slot->getParticipant();
                $sources = array_map(static fn(string $id): ?BracketNode => $byId[$id] ?? null, $slot->getSourceNodes());
                foreach ($sources as $source) {
                    expect($source)->toBeInstanceOf(BracketNode::class, $where);
                }
                if ($participant === null) {
                    $placeholder = $slot->getPlaceholder();
                    assert($placeholder !== null);
                    ++$seen['placeholder ' . $placeholder->value];
                    if ($slot->isPending()) {
                        // Only a round the engine has not reached waits for an entrant
                        expect($round->isRecorded() || $round->isNext())->toBeFalse($where)
                            ->and($node->getStatus())->toBe(BracketNodeStatus::AwaitingEntrants, $where);
                    }
                    if ($slot->getSource() === SlotSource::Draw) {
                        expect($entrants[($slot->getDrawPosition() ?? 0) - 1] ?? null)->toBeNull($where);
                    }
                    if ($slot->isReseeded() && $placeholder !== SlotPlaceholder::Reseeded) {
                        // A re-seeded slot holds nobody only where no node it is filled from has anybody to send
                        $sending = $slot->getSource() === SlotSource::LoserOf
                            ? [BracketNodeStatus::Decided, BracketNodeStatus::Uncontested]
                            : [BracketNodeStatus::Decided, BracketNodeStatus::Uncontested, BracketNodeStatus::Bye];
                        foreach ($sources as $source) {
                            expect($source?->getStatus())->not->toBeIn($sending, "{$where}: {$node->getId()}");
                        }
                    }
                    continue;
                }

                // A slot holds the entrant the node it names sent there
                $source = $sources[0] ?? null;
                match (true) {
                    $slot->getSource() === SlotSource::Draw => expect(($entrants[($slot->getDrawPosition() ?? 0) - 1] ?? null)?->getId())->toBe($participant->getId(), $where),
                    $slot->isReseeded() && $slot->getSource() === SlotSource::AdvancerOf => expect(array_map(
                        static fn(?BracketNode $node): ?string => $node?->getAdvancer()?->getId(),
                        $sources
                    ))->toContain($participant->getId()),
                    $slot->getSource() === SlotSource::AdvancerOf => expect($source?->getAdvancer()?->getId())->toBe($participant->getId(), "{$where}: {$node->getId()}"),
                    $slot->getSource() === SlotSource::LoserOf => expect(bracketViewIds($source?->getLosers() ?? []))->toContain($participant->getId()),
                    default => null,
                };
            }

            // A tie to be paired is one both of whose entrants are in the stage:
            // one an entrant has left is settled as its pairing will settle it
            if ($node->getStatus() === BracketNodeStatus::ToBePaired) {
                foreach ($node->getSlots() as $slot) {
                    expect($slot->isWithdrawn())->toBeFalse("{$where}: {$node->getId()}");
                }
            }

            // A node is settled as its status says
            match ($node->getStatus()) {
                BracketNodeStatus::Decided => expect(bracketViewIds([...$node->getLosers(), $node->getAdvancer()]))->toBe($participants, $where)
                    ->and($losers)->toHaveCount(1, $where),
                BracketNodeStatus::Uncontested => expect($participants)->toContain($advancer)
                    ->and(array_values(array_diff($losers, $participants)))->toBe([], $where)
                    ->and($losers)->toHaveCount(1, $where),
                BracketNodeStatus::Bye => expect($participants)->toBe([$advancer], $where)->and($losers)->toBe([], $where),
                BracketNodeStatus::Void => expect($advancer)->toBeNull($where)
                    ->and($losers)->not->toBe([], $where)
                    ->and(array_values(array_diff($losers, $participants)))->toBe([], $where),
                BracketNodeStatus::Empty => expect($participants)->toBe([], $where)->and($advancer)->toBeNull($where),
                default => expect($advancer)->toBeNull($where)->and($losers)->toBe([], $where),
            };
        }
    }

    // The shape: on a fixed path every round halves the one before it, and a
    // double elimination bracket has every stage of its sequence
    $size = 2;
    while ($size < count($entrants)) {
        $size *= 2;
    }
    if ($engine instanceof SingleEliminationEngine && !$options->reseedEachRound) {
        $counts = array_map(
            static fn(BracketRound $round): int => count(array_filter($round->getNodes(), static fn(BracketNode $node): bool => $node->getKind() !== BracketTie::ThirdPlace)),
            $bracket->getRounds()
        );
        $expected = [];
        for ($nodes = intdiv($size, 2); $nodes >= 1; $nodes = intdiv($nodes, 2)) {
            $expected[] = $nodes;
        }
        expect($counts)->toBe($expected, $where);
    }
    if ($engine instanceof DoubleEliminationEngine) {
        $winnersRounds = (int) log($size, 2);
        expect($bracket->getRounds())->toHaveCount($winnersRounds + 2 * ($winnersRounds - 1) + 1 + ($options->grandFinalReset ? 1 : 0), $where);
    }

    // A finished bracket is won by the entrant its outcome names
    if ($complete) {
        $outcome = $engine->getOutcome($state);
        assert($outcome instanceof StageOutcome);
        $rounds = $bracket->getRounds();
        $last = $rounds[count($rounds) - 1]->getNodes()[0];
        if ($last->getStatus() === BracketNodeStatus::NotPlayed) {
            $last = $rounds[count($rounds) - 2]->getNodes()[0];
        }
        $settled = $outcome->getSettledTies()[0] ?? null;
        $tiers = knownThenTiers($engine, $state);
        expect($last->getAdvancer()?->getId())->toBe($settled?->getAdvancer()?->getId(), $where)
            ->and(array_keys($tiers, 0, true))->toBe($last->getAdvancer() === null ? [] : [$last->getAdvancer()->getId()], $where);
        ++$seen['complete'];
    }
}

it('shows the bracket as the engine reads it after every step, whatever happens in it', function (string $variant): void {
    $engine = deciderSweepEngine($variant);
    $options = match ($variant) {
        'single, re-seeded' => new EliminationOptions(reseedEachRound: true),
        'single, two legs' => new EliminationOptions(legsPerTie: 2),
        'single, third place' => new EliminationOptions(thirdPlace: true),
        'single, two legs, re-seeded, third place' => new EliminationOptions(legsPerTie: 2, reseedEachRound: true, thirdPlace: true),
        'double, two legs' => new EliminationOptions(legsPerTie: 2),
        'double, no reset' => new EliminationOptions(grandFinalReset: false),
        default => new EliminationOptions(),
    };
    $seen = array_fill_keys(
        [
            ...array_map(static fn(BracketNodeStatus $status): string => $status->value, BracketNodeStatus::cases()),
            ...array_map(static fn(SlotPlaceholder $placeholder): string => 'placeholder ' . $placeholder->value, SlotPlaceholder::cases()),
            'complete',
            'some legs recorded',
            'every leg recorded',
        ],
        0
    );
    $states = 0;

    foreach ([2, 3, 4, 5, 6, 7, 8, 9, 12, 16, 17, 33] as $fieldSize) {
        if ($fieldSize < 3 && str_contains($variant, 'third place')) {
            continue;
        }
        foreach ([1, 2] as $seed) {
            $where = "{$variant}, {$fieldSize} entrants, seed {$seed}";
            $entrants = invariantField($fieldSize);

            $check = static function (StageState $state) use ($engine, $options, $entrants, $where, &$seen, &$states): void {
                bracketViewInvariants($engine, $options, $state, $entrants, $where, $seen);
                ++$states;
            };
            $check(StageState::start($entrants));
            [$state] = knownThenPlay($engine, $entrants, 700 * $fieldSize + $seed, $check, legByLeg: true);
            $check($state);

            // The rows of an application show the same bracket
            $fromRows = StageState::fromHistory($entrants, $state->getRoundsPlayed(), $state->getResults(), $state->getWithdrawals(), $state->getAdjustments());
            expect($engine->getBracket($fromRows))->toEqual($engine->getBracket($state), $where);
        }
    }

    // The sweep saw byes, withdrawals, void nodes, placeholders and finished brackets
    expect($states)->toBeGreaterThan(300)
        ->and($seen['bye'])->toBeGreaterThan(0)
        ->and($seen['uncontested'])->toBeGreaterThan(0)
        ->and($seen['in-play'])->toBeGreaterThan(0)
        ->and($seen['awaiting-entrants'])->toBeGreaterThan(0)
        ->and($seen['to-be-paired'])->toBeGreaterThan(0)
        ->and($seen['complete'])->toBeGreaterThan(20)
        ->and($seen['every leg recorded'])->toBeGreaterThan(0);
    if (str_contains($variant, 'two legs')) {
        // A tie with its first leg recorded and not its second
        expect($seen['some legs recorded'])->toBeGreaterThan(0);
    }
    if (str_contains($variant, 're-seeded')) {
        expect($seen['placeholder reseeded'])->toBeGreaterThan(0);
    } else {
        // A re-seeded bracket folds the survivors without the slot of a void tie
        expect($seen['void'] + $seen['placeholder void'])->toBeGreaterThan(0);
    }
    if (str_starts_with($variant, 'double')) {
        expect($seen['placeholder loser-of'])->toBeGreaterThan(0);
    }
    if ($variant === 'double') {
        expect($seen['not-played'])->toBeGreaterThan(0);
    }
})->with([
    ['single'],
    ['single, re-seeded'],
    ['single, two legs'],
    ['single, third place'],
    ['single, two legs, re-seeded, third place'],
    ['double'],
    ['double, two legs'],
    ['double, no reset'],
]);
