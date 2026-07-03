<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Scheduling\DoubleEliminationEngine;
use MissionGaming\Tactician\Scheduling\EliminationOptions;
use MissionGaming\Tactician\Scheduling\SingleEliminationEngine;
use MissionGaming\Tactician\Stage\MatchOutcomeSelector;
use MissionGaming\Tactician\Stage\StageState;
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
        array_map(fn (Participant $p) => $p->getId(), $participants),
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
