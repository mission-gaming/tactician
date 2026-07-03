<?php

declare(strict_types=1);

use MissionGaming\Tactician\DTO\Participant;
use MissionGaming\Tactician\DTO\Result;
use MissionGaming\Tactician\Scheduling\DoubleEliminationEngine;
use MissionGaming\Tactician\Scheduling\SingleEliminationEngine;
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
    $participants = invariantField($fieldSize);

    $results = [];
    $champion = $engine->getChampion($participants, $results);
    while ($champion === null) {
        $pairing = $engine->pairNextRound($participants, $results);
        expect($pairing->getEvents())->not->toBeEmpty();

        foreach ($pairing->getEvents() as $event) {
            $eventParticipants = $event->getParticipants();
            $results[] = new Result($event, $eventParticipants[$rng->getInt(0, 1)]);
        }
        $champion = $engine->getChampion($participants, $results);
    }

    expect($results)->toHaveCount($fieldSize - 1);
})
    ->with([[2], [3], [4], [5], [6], [7], [8], [9], [16]])
    ->with([[42], [1337]]);

it('resolves double elimination in 2n-2 or 2n-1 matches with correct loss counts', function (
    int $fieldSize,
    int $seed
): void {
    $rng = new Randomizer(new Mt19937($seed));
    $engine = new DoubleEliminationEngine();
    $participants = invariantField($fieldSize);

    $results = [];
    $losses = array_fill_keys(
        array_map(fn (Participant $p) => $p->getId(), $participants),
        0
    );

    $champion = $engine->getChampion($participants, $results);
    while ($champion === null) {
        $pairing = $engine->pairNextRound($participants, $results);
        expect($pairing->getEvents())->not->toBeEmpty();

        foreach ($pairing->getEvents() as $event) {
            $eventParticipants = $event->getParticipants();
            $winner = $eventParticipants[$rng->getInt(0, 1)];
            $loser = $winner === $eventParticipants[0] ? $eventParticipants[1] : $eventParticipants[0];
            ++$losses[$loser->getId()];
            $results[] = new Result($event, $winner);
        }
        $champion = $engine->getChampion($participants, $results);
    }

    // Total matches: 2n-2, or 2n-1 when the grand final was reset
    expect(count($results))->toBeIn([2 * $fieldSize - 2, 2 * $fieldSize - 1]);

    // Everyone loses exactly twice except the champion (at most once)
    foreach ($losses as $id => $lossCount) {
        if ($id === $champion?->getId()) {
            expect($lossCount)->toBeLessThanOrEqual(1);
        } else {
            expect($lossCount)->toBe(2, "Participant {$id} was eliminated with {$lossCount} losses");
        }
    }
})
    ->with([[2], [3], [4], [5], [6], [7], [8], [9], [16]])
    ->with([[42], [1337]]);
