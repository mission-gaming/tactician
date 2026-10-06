<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Exceptions\InvalidConfigurationException;
use MissionGaming\Tactician\Exceptions\JsonConversionException;
use MissionGaming\Tactician\Exceptions\NoValidPairingException;
use MissionGaming\Tactician\Scheduling\DoubleEliminationEngine;
use MissionGaming\Tactician\Scheduling\EliminationOptions;
use MissionGaming\Tactician\Scheduling\SingleEliminationEngine;
use MissionGaming\Tactician\Stage\MatchOutcomeSelector;
use MissionGaming\Tactician\Stage\StageEngineInterface;
use MissionGaming\Tactician\Stage\StageOutcome;
use MissionGaming\Tactician\Stage\StageState;
use MissionGaming\Tactician\Stage\TieDecision;
use MissionGaming\Tactician\Tests\Support\AwkwardIds;
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

// Level-event sweeps. A single-leg event may finish level; the application
// then records who advances as 'tie_winner' metadata on the event's result.
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
                ? new Result($event, null, [], [TieDecision::TIE_WINNER_KEY => $advancer->getId()])
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
        expect($result->getMetadataValue(TieDecision::TIE_WINNER_KEY))->toBe($level[$index] ? $played[$index][3] : null);
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
